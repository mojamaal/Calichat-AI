<?php
declare(strict_types=1);
namespace CaliChat\Services;

use CaliChat\Core\Audit;
use CaliChat\Core\Db;
use CaliChat\Core\Platform;

/**
 * Plans, trials and manual (bank transfer) billing. No payment gateway: the business pays by bank transfer against an invoice, says "I have paid"
 * with the bank reference, and the platform admin confirms it. Confirmation applies the plan, extends the period and keeps the AI running.
 * When a trial or paid period ends there is a grace period; after it the AI stops answering new chats (customers get the holding message).
 */
class Billing {
    public static function trialDays(): int { return max(1, (int)env('BILLING_TRIAL_DAYS', 14)); }
    public static function graceDays(): int { return max(0, (int)env('BILLING_GRACE_DAYS', 3)); }

    public static function plans(bool $publicOnly = true): array { return Db::all("SELECT * FROM plans" . ($publicOnly ? ' WHERE is_public=1' : '') . " ORDER BY sort, price_lkr"); }
    public static function plan(string $code): ?array { return Db::one("SELECT * FROM plans WHERE code=?", [$code]); }
    public static function bankDetails(): string { return (string)Platform::get('billing_bank_details', ''); }
    public static function signupOpen(): bool { return Platform::get('signup_enabled', '1') !== '0'; }

    public static function applyPlan(int $tid, string $code): bool {
        $p = self::plan($code); if (!$p) return false;
        Db::update('tenants', ['plan' => $p['code'], 'monthly_message_limit' => (int)$p['monthly_message_limit'], 'monthly_token_limit' => (int)$p['monthly_token_limit']], 'id=?', [$tid]);
        return true;
    }

    public static function startTrial(int $tid, string $code, ?int $days = null): void {
        self::applyPlan($tid, $code);
        Db::update('tenants', ['billing_status' => 'trial', 'trial_ends_at' => gmdate('Y-m-d H:i:s', time() + ($days ?? self::trialDays()) * 86400), 'current_period_end' => null], 'id=?', [$tid]);
    }

    /** @return array{id:int,number:string,amount:float}|null */
    public static function issue(int $tid, string $planCode, int $months = 1): ?array {
        $p = self::plan($planCode); if (!$p || $months < 1 || $months > 12) return null;
        Db::q("UPDATE invoices SET status='void' WHERE tenant_id=? AND status='issued'", [$tid]); // a new request replaces unpaid ones
        $year = gmdate('Y'); $n = (int)Db::val("SELECT COUNT(*) FROM invoices WHERE number LIKE ?", ["INV-$year-%"]) + 1;
        do { $number = sprintf('INV-%s-%04d', $year, $n++); } while (Db::val("SELECT id FROM invoices WHERE number=?", [$number]));
        $amount = round((float)$p['price_lkr'] * $months, 2);
        $id = Db::insert('invoices', ['tenant_id' => $tid, 'number' => $number, 'plan_code' => $p['code'], 'description' => $p['name'] . ' plan, ' . $months . ' month' . ($months > 1 ? 's' : ''), 'amount' => $amount, 'months' => $months, 'due_date' => gmdate('Y-m-d', time() + 7 * 86400)]);
        return ['id' => $id, 'number' => $number, 'amount' => $amount];
    }

    /** The business says it has paid. @return array{ok:bool,error:?string} */
    public static function claim(int $tid, int $invoiceId, string $ref): array {
        $ref = trim($ref); if (mb_strlen($ref) < 3) return ['ok' => false, 'error' => 'Enter the bank reference or slip number so we can find your payment.'];
        $n = Db::q("UPDATE invoices SET status='paid_claimed', payment_ref=?, claimed_at=NOW() WHERE id=? AND tenant_id=? AND status='issued'", [mb_substr($ref, 0, 100), $invoiceId, $tid])->rowCount();
        if (!$n) return ['ok' => false, 'error' => 'That invoice is not waiting for payment.'];
        $inv = Db::one("SELECT i.number, i.amount, t.name FROM invoices i JOIN tenants t ON t.id=i.tenant_id WHERE i.id=?", [$invoiceId]);
        Notifier::alert(null, 'warn', 'payment_claimed', "{$inv['name']} says it paid {$inv['number']} (Rs. " . number_format((float)$inv['amount']) . "), reference $ref. Check your bank and confirm in Billing.", 'claim:' . $invoiceId, 24);
        return ['ok' => true, 'error' => null];
    }

    /** Platform admin confirms the money arrived. @return array{ok:bool,error:?string} */
    public static function markPaid(int $invoiceId, int $adminId, ?string $ref = null): array {
        $inv = Db::one("SELECT * FROM invoices WHERE id=? AND status IN ('issued','paid_claimed')", [$invoiceId]);
        if (!$inv) return ['ok' => false, 'error' => 'That invoice is already settled or void.'];
        $t = Db::one("SELECT * FROM tenants WHERE id=?", [$inv['tenant_id']]); if (!$t) return ['ok' => false, 'error' => 'Tenant not found'];
        Db::update('invoices', ['status' => 'paid', 'paid_at' => now(), 'decided_by' => $adminId, 'payment_ref' => $ref !== null && $ref !== '' ? mb_substr($ref, 0, 100) : $inv['payment_ref']], 'id=?', [$invoiceId]);
        $base = max(time(), $t['current_period_end'] ? strtotime($t['current_period_end'] . ' UTC') : 0, $t['billing_status'] === 'trial' && $t['trial_ends_at'] ? strtotime($t['trial_ends_at'] . ' UTC') : 0);
        $end = (new \DateTime('@' . $base))->modify('+' . (int)$inv['months'] . ' month')->format('Y-m-d H:i:s');
        self::applyPlan((int)$t['id'], $inv['plan_code']);
        Db::update('tenants', ['billing_status' => 'active', 'current_period_end' => $end, 'trial_ends_at' => null], 'id=?', [$t['id']]);
        Audit::log('invoice_paid', $inv['number'], 'until ' . $end, (int)$t['id'], $adminId);
        Notifier::alert((int)$t['id'], 'info', 'payment_confirmed', "Payment for {$inv['number']} confirmed. Your {$inv['plan_code']} plan is active until " . substr($end, 0, 10) . '. Thank you.', 'paid:' . $invoiceId, 24);
        return ['ok' => true, 'error' => null];
    }

    public static function void(int $invoiceId, int $adminId): bool {
        $n = Db::q("UPDATE invoices SET status='void', decided_by=? WHERE id=? AND status IN ('issued','paid_claimed')", [$adminId, $invoiceId])->rowCount();
        if ($n) Audit::log('invoice_void', (string)$invoiceId, '', null, $adminId);
        return $n > 0;
    }

    /** @return array{state:string,days_left:?int,until:?string,blocking:bool,message:?string} */
    public static function status(array $t): array {
        $st = (string)($t['billing_status'] ?? 'active'); $until = $st === 'trial' ? ($t['trial_ends_at'] ?? null) : ($t['current_period_end'] ?? null);
        $days = $until ? (int)ceil((strtotime($until . ' UTC') - time()) / 86400) : null;
        $msg = null;
        if ($st === 'trial') $msg = $days !== null && $days > 0 ? "Free trial: $days day" . ($days === 1 ? '' : 's') . ' left.' : 'Your free trial has ended.';
        elseif ($st === 'active' && $days !== null && $days <= 7) $msg = $days > 0 ? "Your plan renews in $days day" . ($days === 1 ? '' : 's') . '.' : 'Your plan period has ended.';
        elseif ($st === 'past_due') $msg = 'Payment is overdue. The AI will stop answering new chats soon.';
        elseif ($st === 'lapsed') $msg = 'Your subscription has lapsed: the AI is not answering new chats. Pay the open invoice to restart it.';
        elseif ($st === 'cancelled') $msg = 'This subscription is cancelled.';
        return ['state' => $st, 'days_left' => $days, 'until' => $until, 'blocking' => in_array($st, ['lapsed', 'cancelled'], true), 'message' => $msg];
    }
    public static function blocked(array $t): bool { return in_array($t['billing_status'] ?? 'active', ['lapsed', 'cancelled'], true); }

    /** Daily housekeeping: reminders, renewal invoices, grace, lapse. @return array<string,int> */
    public static function tick(): array {
        $out = ['reminded' => 0, 'invoiced' => 0, 'past_due' => 0, 'lapsed' => 0];
        $open = fn(int $tid) => (int)Db::val("SELECT COUNT(*) FROM invoices WHERE tenant_id=? AND status IN ('issued','paid_claimed')", [$tid]) > 0;
        foreach (Db::all("SELECT * FROM tenants WHERE status='active' AND billing_status IN ('trial','active','past_due')") as $t) {
            $tid = (int)$t['id']; $s = self::status($t); $end = $s['until'] ? strtotime($s['until'] . ' UTC') : null;
            if ($t['billing_status'] === 'trial' && $end) {
                if ($s['days_left'] !== null && $s['days_left'] <= 7 && $s['days_left'] > 0 && !$open($tid) && self::plan((string)$t['plan'])) { self::issue($tid, (string)$t['plan'], 1); $out['invoiced']++; }
                if ($s['days_left'] !== null && $s['days_left'] <= 3 && $s['days_left'] > 0) { Notifier::alert($tid, 'warn', 'trial_ending', "Your free trial ends in {$s['days_left']} day(s). Pay your invoice under Billing to keep the AI answering your customers.", 'trial:' . gmdate('Ymd'), 24 * 2); $out['reminded']++; }
                if ($end < time()) { Db::update('tenants', ['billing_status' => 'past_due'], 'id=?', [$tid]); $t['billing_status'] = 'past_due'; $out['past_due']++; Notifier::alert($tid, 'crit', 'trial_ended', 'Your free trial has ended. Pay your invoice under Billing within ' . self::graceDays() . ' days to keep the AI running.', 'trialend', 24 * 3); }
            } elseif ($t['billing_status'] === 'active' && $end) {
                if ($end - time() <= 7 * 86400 && $end > time() && !$open($tid) && self::plan((string)$t['plan'])) { self::issue($tid, (string)$t['plan'], 1); $out['invoiced']++; Notifier::alert($tid, 'info', 'renewal_invoice', 'Your renewal invoice is ready under Billing.', 'renew:' . gmdate('Ym'), 24 * 5); }
                if ($end < time()) { Db::update('tenants', ['billing_status' => 'past_due'], 'id=?', [$tid]); $t['billing_status'] = 'past_due'; $out['past_due']++; Notifier::alert($tid, 'crit', 'plan_overdue', 'Your plan period has ended. Pay the open invoice under Billing to avoid interruption.', 'overdue', 24 * 3); }
            }
            if ($t['billing_status'] === 'past_due') {
                $until = $t['current_period_end'] ?: $t['trial_ends_at']; $endTs = $until ? strtotime($until . ' UTC') : time();
                if (time() > $endTs + self::graceDays() * 86400) { Db::update('tenants', ['billing_status' => 'lapsed'], 'id=?', [$tid]); $out['lapsed']++; Notifier::alert($tid, 'crit', 'subscription_lapsed', 'Your subscription has lapsed, so the AI no longer answers new chats. Pay the open invoice under Billing to restart it.', 'lapsed', 24 * 3); Notifier::alert(null, 'warn', 'tenant_lapsed', "{$t['name']} lapsed (unpaid).", 'lapsed:' . $tid, 24 * 7); }
            }
        }
        return $out;
    }
}
