<?php
declare(strict_types=1);
namespace CaliChat\Services;

use CaliChat\Core\Crypto;
use CaliChat\Core\Db;

/**
 * WhatsApp message templates: write them with readable {placeholders}, check Meta's rules before submitting,
 * submit and sync their approval status, and fill them from real data when sending.
 * Meta receives {{1}}, {{2}} in order of first appearance; we keep the readable names so a person can read the template.
 */
class Templates {
    /** Placeholders a template may use, with the sample value Meta wants at submission. */
    public const VARS = ['name' => 'Nimal', 'order_no' => 'ORD-1001', 'total' => 'Rs. 3,350', 'status' => 'Shipped', 'tracking' => 'DX123456', 'courier' => 'Domex',
        'eta' => 'Arrives in 2 days', 'items' => '2 x Baseball Cap', 'shop' => 'Demo Store', 'city' => 'Colombo', 'payment_method' => 'Cash on delivery', 'interest' => 'bulk polo shirts', 'service' => 'Haircut', 'date' => 'Mon 3 March', 'time' => '10:30'];
    private const STATUS_MAP = ['APPROVED' => 'approved', 'PENDING' => 'pending', 'IN_APPEAL' => 'pending', 'REJECTED' => 'rejected', 'PAUSED' => 'paused', 'DISABLED' => 'disabled', 'PENDING_DELETION' => 'disabled', 'LIMIT_EXCEEDED' => 'paused'];

    /** Placeholder names in order of first appearance. @return string[] */
    public static function variables(string $text): array {
        preg_match_all('/\{([a-z_]{1,20})\}/', $text, $m);
        return array_values(array_unique($m[1]));
    }
    /** "Hi {name}, order {order_no}" -> "Hi {{1}}, order {{2}}" */
    public static function toMeta(string $text): string {
        $names = self::variables($text);
        return preg_replace_callback('/\{([a-z_]{1,20})\}/', fn($m) => '{{' . (array_search($m[1], $names, true) + 1) . '}}', $text);
    }
    /** Replace {placeholders} with real values (unknown ones become empty). */
    public static function render(string $text, array $ctx): string {
        return preg_replace_callback('/\{([a-z_]{1,20})\}/', fn($m) => (string)($ctx[$m[1]] ?? ''), $text);
    }
    /** @return string[] values in the order Meta expects */
    public static function values(array $tpl, array $ctx): array {
        return array_map(fn($n) => (string)($ctx[$n] ?? '') !== '' ? (string)$ctx[$n] : '-', self::variables((string)$tpl['body']));
    }
    /** @return string[] button labels (one per line in the editor) */
    public static function buttonList(?string $raw): array {
        $j = json_decode((string)$raw, true);
        return is_array($j) ? array_values(array_filter(array_map('strval', $j), 'strlen')) : [];
    }

    /** @return array{errors:string[],warnings:string[]} */
    public static function problems(array $t): array {
        $e = []; $w = [];
        $name = (string)($t['name'] ?? ''); $lang = (string)($t['language'] ?? ''); $body = (string)($t['body'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_]{1,79}$/', $name)) $e[] = 'Name: use lowercase letters, digits and underscores, starting with a letter (example: order_shipped).';
        if (!preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $lang)) $e[] = 'Language code looks wrong. Use en, si or ta (or en_US).';
        if (trim($body) === '') $e[] = 'The message text is empty.';
        if (mb_strlen($body) > 1024) $e[] = 'Message text is over 1,024 characters (Meta limit).';
        if (strpos($body, '{{') !== false || strpos($body, '}}') !== false) $e[] = 'Write placeholders like {name}, not {{1}}. We convert them for Meta.';
        $vars = self::variables($body);
        foreach ($vars as $v) if (!isset(self::VARS[$v])) $e[] = "Unknown placeholder {{$v}}. Allowed: " . implode(', ', array_map(fn($k) => "{{$k}}", array_keys(self::VARS))) . '.';
        $trim = trim($body);
        if ($vars && (preg_match('/^\{[a-z_]+\}/', $trim) || preg_match('/\{[a-z_]+\}[.!?]?$/', $trim))) $e[] = 'Meta rejects templates that start or end with a placeholder. Add a few words before the first and after the last.';
        if (preg_match('/\n{3,}/', $body) || preg_match('/ {5,}/', $body) || strpos($body, "\t") !== false) $e[] = 'Meta rejects tabs, more than 4 spaces in a row, and more than 2 blank lines in a row.';
        if ($vars) { $words = preg_split('/\s+/u', trim(preg_replace('/\{[a-z_]+\}/', '', $body)), -1, PREG_SPLIT_NO_EMPTY); if (count($words) < 3 * count($vars)) $w[] = 'There are many placeholders for the amount of text. Meta may reject it; aim for at least 3 words of text per placeholder.'; }
        $h = (string)($t['header_text'] ?? ''); if (mb_strlen($h) > 60) $e[] = 'Header is over 60 characters.'; if (preg_match('/\{[a-z_]+\}/', $h)) $e[] = 'Placeholders are not supported in the header here. Put them in the message text.';
        $f = (string)($t['footer'] ?? ''); if (mb_strlen($f) > 60) $e[] = 'Footer is over 60 characters.'; if (preg_match('/\{[a-z_]+\}/', $f)) $e[] = 'Footers cannot contain placeholders.';
        $b = self::buttonList($t['buttons'] ?? null); if (count($b) > 3) $e[] = 'At most 3 quick-reply buttons.'; foreach ($b as $x) if (mb_strlen($x) > 25) $e[] = "Button '$x' is over 25 characters.";
        if (($t['category'] ?? 'utility') === 'marketing' && !preg_match('/stop|unsubscribe|opt.?out|නවත්වන්න|நிறுத்து/iu', $body . ' ' . $f)) $w[] = 'Marketing templates should tell people how to opt out, for example the footer "Reply STOP to unsubscribe".';
        if (($t['category'] ?? 'utility') === 'utility' && preg_match('/\b(offer|discount|sale|% ?off|buy now|limited time)\b/i', $body)) $w[] = 'This reads like promotion. Meta may re-classify it as Marketing, which costs more and needs opt-in.';
        return ['errors' => $e, 'warnings' => $w];
    }

    // ------------------------------------------------------------------ Meta
    /** WhatsApp channel (with token and business account id) a template belongs to. */
    public static function channel(int $tid, ?int $channelId = null): ?array {
        if ($channelId) return Db::one("SELECT * FROM channels WHERE id=? AND tenant_id=? AND type='whatsapp'", [$channelId, $tid]);
        return Db::one("SELECT * FROM channels WHERE tenant_id=? AND type='whatsapp' AND status='active' ORDER BY (waba_id IS NULL), id LIMIT 1", [$tid]);
    }

    /** Submit a draft/rejected template to Meta for approval. @return array{ok:bool,error:?string} */
    public static function submit(int $tid, int $id): array {
        $t = Db::one("SELECT * FROM wa_templates WHERE id=? AND tenant_id=?", [$id, $tid]);
        if (!$t) return ['ok' => false, 'error' => 'Template not found'];
        if (in_array($t['status'], ['pending', 'approved'], true)) return ['ok' => false, 'error' => 'This template was already submitted. Meta does not allow editing it; create a new version with a new name.'];
        $p = self::problems($t); if ($p['errors']) return ['ok' => false, 'error' => $p['errors'][0]];
        $ch = self::channel($tid, $t['channel_id'] ? (int)$t['channel_id'] : null);
        if (!$ch) return ['ok' => false, 'error' => 'Connect a WhatsApp channel (Meta Cloud API) first.'];
        if ($ch['type'] !== 'whatsapp') return ['ok' => false, 'error' => 'Templates need a Meta WhatsApp channel; Twilio manages its own templates.'];
        if (empty($ch['waba_id'])) return ['ok' => false, 'error' => 'Add the WhatsApp Business Account ID of this channel (Templates page > Business account) so Meta knows where to submit.'];
        $token = Crypto::decrypt($ch['access_token']); if (!$token) return ['ok' => false, 'error' => 'The channel has no access token.'];
        $names = self::variables($t['body']);
        $body = ['type' => 'BODY', 'text' => self::toMeta($t['body'])];
        if ($names) $body['example'] = ['body_text' => [array_map(fn($n) => self::VARS[$n], $names)]];
        $comps = [];
        if ($t['header_text']) $comps[] = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $t['header_text']];
        $comps[] = $body;
        if ($t['footer']) $comps[] = ['type' => 'FOOTER', 'text' => $t['footer']];
        $btn = self::buttonList($t['buttons']); if ($btn) $comps[] = ['type' => 'BUTTONS', 'buttons' => array_map(fn($x) => ['type' => 'QUICK_REPLY', 'text' => $x], $btn)];
        $r = Meta::api('POST', rawurlencode($ch['waba_id']) . '/message_templates', ['name' => $t['name'], 'language' => $t['language'], 'category' => strtoupper($t['category']), 'allow_category_change' => true, 'components' => $comps], $token);
        if (!$r['ok']) { Meta::noteResult($ch, ['ok' => false, 'error' => (string)$r['error'], 'auth' => $r['auth']]); return ['ok' => false, 'error' => 'Meta: ' . $r['error']]; }
        $st = self::STATUS_MAP[strtoupper((string)($r['json']['status'] ?? 'PENDING'))] ?? 'pending';
        Db::update('wa_templates', ['channel_id' => $ch['id'], 'status' => $st, 'meta_id' => (string)($r['json']['id'] ?? '') ?: null, 'rejection_reason' => $st === 'rejected' ? 'Rejected by Meta right away. Press Check approval status for the reason.' : null, 'submitted_at' => now(), 'updated_at' => now()], 'id=?', [$t['id']]);
        if ($st === 'rejected') Notifier::alert($tid, 'warn', 'template_rejected', "WhatsApp template '{$t['name']}' was rejected by Meta. Open Templates for the reason.", 'tplrej:' . $t['name'], 72);
        return ['ok' => true, 'error' => null];
    }

    /** Pull approval status from Meta for every submitted template of the tenant. @return array{ok:bool,updated:int,error:?string} */
    public static function sync(int $tid): array {
        $updated = 0; $err = null;
        $groups = Db::all("SELECT DISTINCT channel_id FROM wa_templates WHERE tenant_id=? AND channel_id IS NOT NULL AND status IN ('pending','approved','paused')", [$tid]);
        foreach ($groups as $g) {
            $ch = Db::one("SELECT * FROM channels WHERE id=? AND tenant_id=?", [$g['channel_id'], $tid]);
            $token = $ch ? Crypto::decrypt($ch['access_token']) : null;
            if (!$ch || !$token || empty($ch['waba_id'])) continue;
            $r = Meta::api('GET', rawurlencode($ch['waba_id']) . '/message_templates?limit=200&fields=id,name,language,status,category,rejected_reason', null, $token);
            if (!$r['ok']) { $err = (string)$r['error']; Meta::noteResult($ch, ['ok' => false, 'error' => $err, 'auth' => $r['auth']]); continue; }
            foreach ($r['json']['data'] ?? [] as $m) {
                $st = self::STATUS_MAP[strtoupper((string)($m['status'] ?? ''))] ?? null; if (!$st) continue;
                $reason = $st === 'rejected' ? mb_substr((string)($m['rejected_reason'] ?? 'Rejected by Meta'), 0, 290) : null;
                $row = Db::one("SELECT id, status FROM wa_templates WHERE tenant_id=? AND channel_id=? AND name=? AND language=?", [$tid, $ch['id'], (string)($m['name'] ?? ''), (string)($m['language'] ?? '')]);
                if (!$row) continue;
                Db::update('wa_templates', ['status' => $st, 'rejection_reason' => $reason, 'meta_id' => (string)($m['id'] ?? '') ?: null, 'synced_at' => now(), 'updated_at' => now()], 'id=?', [$row['id']]);
                if ($row['status'] === $st) continue;
                $updated++;
                if ($st === 'rejected') Notifier::alert($tid, 'warn', 'template_rejected', "WhatsApp template '{$m['name']}' was rejected: " . ($reason ?: 'no reason given'), 'tplrej:' . $m['name'], 72);
                if ($st === 'approved') Notifier::alert($tid, 'info', 'template_approved', "WhatsApp template '{$m['name']}' was approved.", 'tplok:' . $m['name'], 72);
            }
        }
        return ['ok' => $err === null, 'updated' => $updated, 'error' => $err];
    }

    /** Periodic job: sync every tenant that has submitted templates waiting for a decision. */
    public static function syncAll(): int {
        $n = 0;
        foreach (Db::all("SELECT DISTINCT tenant_id FROM wa_templates WHERE status='pending'") as $r) { try { $n += self::sync((int)$r['tenant_id'])['updated']; } catch (\Throwable $e) { \CaliChat\Core\Log::exception($e, 'template_sync_failed'); } }
        return $n;
    }

    public static function approved(int $tid, string $name, string $lang): ?array {
        return Db::one("SELECT * FROM wa_templates WHERE tenant_id=? AND name=? AND language=? AND status='approved'", [$tid, $name, $lang]);
    }

    public static function send(array $channel, string $to, array $tpl, array $ctx): array {
        return Meta::sendTemplate($channel, $to, $tpl['name'], $tpl['language'], self::values($tpl, $ctx));
    }

    /** Starter templates for the common Sri Lankan shop flows. */
    public static function starters(): array {
        return [
            ['order_confirmed', 'utility', "Hi {name}, thank you for your order {order_no} from {shop}. Total {total}. We will update you when it ships.", null, null],
            ['order_shipped', 'utility', "Hi {name}, your order {order_no} has been shipped with {courier}. Tracking number: {tracking}. Expected delivery: {eta}. Thank you for shopping with us.", null, null],
            ['order_delivered', 'utility', "Hi {name}, your order {order_no} has been delivered. We hope you enjoy it. If anything is wrong, just reply to this message.", null, null],
            ['payment_reminder', 'utility', "Hi {name}, we are still waiting for the payment of {total} for order {order_no}. Reply here if you need help paying.", null, null],
            ['review_request', 'marketing', "Hi {name}, thank you for shopping with {shop}. How was your order {order_no}? Reply with a rating from 1 to 5.", 'Reply STOP to unsubscribe', null],
            ['lead_welcome', 'utility', "Hi {name}, thanks for your interest in {interest}. {shop} will contact you shortly. Reply here if you have any questions.", null, null],
            ['appointment_confirmed', 'utility', "Hi {name}, your {service} appointment at {shop} is booked for {date} at {time}. Reply here if you need to change it.", null, null],
            ['appointment_reminder', 'utility', "Hi {name}, a reminder of your {service} appointment at {shop} on {date} at {time}. Reply here if you cannot make it.", null, null],
            ['still_interested', 'marketing', "Hi {name}, you asked about {interest} earlier. Would you like us to help you place the order? Just reply here.", 'Reply STOP to unsubscribe', json_encode(['Yes, please', 'Not now'])],
        ];
    }
}
