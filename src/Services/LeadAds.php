<?php
declare(strict_types=1);
namespace CaliChat\Services;

use CaliChat\Core\Crypto;
use CaliChat\Core\Db;
use CaliChat\Core\Log;

/**
 * Facebook / Instagram Lead Ads. When someone fills a lead form, Meta sends a `leadgen` webhook with an id; we fetch the answers with the
 * connected Page's token and create a lead (source "ad form") for the right business. Needs the `leadgen` field subscribed in the Meta app
 * and the leads_retrieval permission. A welcome follow-up ("lead_created") can then message the person with an approved template.
 */
class LeadAds {
    /** @return int leads created */
    public static function handle(array $payload): int {
        if (($payload['object'] ?? '') !== 'page') return 0;
        $n = 0;
        foreach ($payload['entry'] ?? [] as $entry) foreach ($entry['changes'] ?? [] as $chg) {
            if (($chg['field'] ?? '') !== 'leadgen') continue;
            $v = $chg['value'] ?? []; $lg = preg_replace('/\D/', '', (string)($v['leadgen_id'] ?? '')); $page = (string)($v['page_id'] ?? $entry['id'] ?? '');
            if ($lg === '' || $page === '') continue;
            $ch = Db::one("SELECT c.* FROM channels c JOIN tenants t ON t.id=c.tenant_id WHERE c.type='messenger' AND c.external_id=? AND c.managed=1 AND c.status='active' AND t.status='active'", [$page]);
            $token = $ch ? Crypto::decrypt($ch['access_token']) : null;
            if (!$ch || !$token) continue;
            try { $n += self::create((int)$ch['tenant_id'], $lg, $token) ? 1 : 0; } catch (\Throwable $e) { Log::exception($e, 'leadgen_failed', ['leadgen' => $lg]); }
        }
        return $n;
    }

    /** "0771234567", "+94 77 123 4567", "94771234567" -> 94771234567 */
    public static function phone(string $p): string {
        $d = preg_replace('/\D/', '', $p);
        if (strlen($d) === 10 && $d[0] === '0') return '94' . substr($d, 1);
        if (strlen($d) === 9) return '94' . $d;
        return $d;
    }

    private static function create(int $tid, string $leadgenId, string $token): bool {
        $r = Meta::api('GET', rawurlencode($leadgenId) . '?fields=field_data,created_time,form_id,ad_name', null, $token);
        if (!$r['ok']) throw new \RuntimeException('Could not read the lead from Meta: ' . $r['error']);
        $f = []; foreach ($r['json']['field_data'] ?? [] as $x) $f[strtolower((string)($x['name'] ?? ''))] = trim((string)($x['values'][0] ?? ''));
        $name = $f['full_name'] ?? trim(($f['first_name'] ?? '') . ' ' . ($f['last_name'] ?? '')); $phone = self::phone((string)($f['phone_number'] ?? $f['phone'] ?? ''));
        $std = ['full_name', 'first_name', 'last_name', 'phone_number', 'phone', 'email', 'city', 'street_address']; $extra = [];
        foreach ($f as $k => $val) if (!in_array($k, $std, true) && $val !== '') $extra[] = str_replace('_', ' ', $k) . ': ' . $val;
        $interest = $extra ? mb_substr($extra[0], 0, 200) : (string)($r['json']['ad_name'] ?? 'Facebook lead form');
        if ($name === '' && $phone === '' && empty($f['email'])) return false;
        $ins = Db::q("INSERT IGNORE INTO crm_leads (tenant_id, phone, name, interest, notes, source, email, external_ref) VALUES (?,?,?,?,?, 'ad_form', ?, ?)",
            [$tid, $phone, mb_substr($name ?: 'Lead form contact', 0, 100), mb_substr($interest, 0, 200), $extra ? mb_substr(implode('; ', $extra), 0, 500) : null, !empty($f['email']) ? mb_substr($f['email'], 0, 150) : null, 'fb:' . $leadgenId]);
        if (!$ins->rowCount()) return false; // Meta retries webhooks: already have it
        $id = (int)Db::pdo()->lastInsertId();
        Notifier::alert($tid, 'info', 'ad_lead', 'New lead from a Facebook/Instagram lead form: ' . ($name ?: 'unnamed') . ($phone ? " ($phone)" : '') . '. See Leads.', 'adlead:' . $id, 1);
        Automations::emit($tid, 'lead_created', 'lead', $id);
        return true;
    }
}
