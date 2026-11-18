<?php
declare(strict_types=1);
namespace CaliChat\Services;

use CaliChat\Core\Crypto;
use CaliChat\Core\Net;

/**
 * Twilio WhatsApp (Sandbox or a production sender) as a CaliChat channel (type "twilio_whatsapp").
 *  - inbound: Twilio POSTs form fields to /webhook/{channel_key}, signed with X-Twilio-Signature
 *  - outbound: Twilio REST API  POST /2010-04-01/Accounts/{SID}/Messages.json  (Basic auth SID:AuthToken)
 * Channel fields: account_sid = Account SID, access_token = Auth Token (encrypted), external_id = sender number (+14155238886).
 */
class Twilio {
    private const API = 'https://api.twilio.com/2010-04-01';

    /** whatsapp:+94771234567 or +94 77 123 4567  ->  94771234567 */
    public static function digits(string $s): string { return preg_replace('/\D/', '', preg_replace('/^whatsapp:/i', '', $s)); }
    public static function senderNumber(array $channel): string { return '+' . self::digits((string)$channel['external_id']); }

    /** Twilio's request signature: base64(HMAC-SHA1(authToken, url . sorted(name . value)...)) */
    public static function signature(string $url, array $params, string $authToken): string {
        ksort($params);
        $s = $url;
        foreach ($params as $k => $v) $s .= $k . (is_array($v) ? implode('', $v) : $v);
        return base64_encode(hash_hmac('sha1', $s, $authToken, true));
    }

    /** URLs Twilio may have signed: the configured public URL and the one this request actually arrived on (tunnels/proxies). */
    public static function candidateUrls(array $channel): array {
        $urls = [abs_url('webhook/' . $channel['channel_key'])];
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host !== '') {
            $uri = $_SERVER['REQUEST_URI'] ?? ('/webhook/' . $channel['channel_key']);
            $xfp = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
            foreach (array_unique(array_filter([in_array($xfp, ['http', 'https'], true) ? $xfp : (is_https() ? 'https' : 'http'), 'https', 'http'])) as $scheme) $urls[] = $scheme . '://' . $host . $uri;
        }
        return array_values(array_unique($urls));
    }

    public static function verifySignature(array $channel, array $params, ?string $header, ?array $urls = null): bool {
        $token = Crypto::decrypt($channel['access_token']);
        if (!$token || !$header) return false;
        if (!empty($channel['account_sid']) && isset($params['AccountSid']) && !hash_equals((string)$channel['account_sid'], (string)$params['AccountSid'])) return false;
        foreach ($urls ?? self::candidateUrls($channel) as $u) if (hash_equals(self::signature($u, $params, $token), $header)) return true;
        return false;
    }

    /** Normalise a Twilio inbound webhook into the same event shape the Meta parser produces. */
    public static function parse(array $channel, array $p): array {
        if (isset($p['MessageStatus']) && !isset($p['Body']) && !isset($p['NumMedia'])) return []; // delivery receipts
        $from = (string)($p['From'] ?? ''); $id = (string)($p['MessageSid'] ?? $p['SmsMessageSid'] ?? '');
        if ($from === '' || $id === '' || stripos($from, 'whatsapp:') !== 0) return [];
        $digits = self::digits($from);
        $text = trim((string)($p['Body'] ?? '')); $type = 'text'; $ref = null; $mime = null;
        if ((int)($p['NumMedia'] ?? 0) > 0 && !empty($p['MediaUrl0'])) {
            $mt = strtolower((string)($p['MediaContentType0'] ?? ''));
            if (strpos($mt, 'audio/') === 0) { $type = 'audio'; $ref = (string)$p['MediaUrl0']; $mime = $mt; }
            elseif (strpos($mt, 'image/') === 0) { $type = 'image'; $ref = (string)$p['MediaUrl0']; $mime = $mt; }
            elseif ($text === '') $text = '[customer sent an attachment]';
        }
        if ($text === '' && !$ref) return [];
        return [['id' => $id, 'sender' => $digits, 'name' => !empty($p['ProfileName']) ? (string)$p['ProfileName'] : null, 'phone' => $digits, 'type' => $type, 'text' => $text, 'media_ref' => $ref, 'mime' => $mime]];
    }

    private static function auth(array $channel): ?string {
        $token = Crypto::decrypt($channel['access_token']);
        return ($token && !empty($channel['account_sid'])) ? 'Authorization: Basic ' . base64_encode($channel['account_sid'] . ':' . $token) : null;
    }

    public static function send(array $channel, string $to, string $text): array {
        $auth = self::auth($channel);
        if (!$auth) return ['ok' => false, 'error' => 'Account SID or Auth Token not set'];
        $last = ['ok' => true];
        foreach (self::chunk($text, 1500) as $c) {
            $r = Net::request('POST', self::API . '/Accounts/' . rawurlencode($channel['account_sid']) . '/Messages.json', [$auth, 'Content-Type: application/x-www-form-urlencoded'], [
                'timeout' => 20, 'allow_private' => false, 'max_bytes' => 65536,
                'body' => http_build_query(['From' => 'whatsapp:' . self::senderNumber($channel), 'To' => 'whatsapp:+' . self::digits($to), 'Body' => $c]),
            ]);
            if ($r['status'] >= 200 && $r['status'] < 300) continue;
            $j = json_decode($r['body'], true);
            $msg = $r['error'] ?: (($j['message'] ?? substr($r['body'], 0, 160)) . (isset($j['code']) ? " (Twilio code {$j['code']})" : ''));
            $last = ['ok' => false, 'error' => "Twilio HTTP {$r['status']}: $msg", 'auth' => $r['status'] === 401];
            break;
        }
        Meta::noteResult($channel, $last);
        return $last;
    }

    /** Check Account SID + Auth Token against Twilio. @return array{ok:bool,name:?string,error:?string} */
    public static function verifyConnection(array $channel): array {
        $auth = self::auth($channel);
        if (!$auth || !$channel['external_id']) return ['ok' => false, 'name' => null, 'error' => 'Add the Account SID, Auth Token and WhatsApp sender number first'];
        $r = Net::request('GET', self::API . '/Accounts/' . rawurlencode($channel['account_sid']) . '.json', [$auth], ['timeout' => 10, 'allow_private' => false, 'max_bytes' => 65536]);
        $j = json_decode($r['body'], true) ?: [];
        if ($r['status'] === 200) return ['ok' => true, 'name' => ($j['friendly_name'] ?? 'Twilio account') . ' (' . ($j['type'] ?? 'account') . ', ' . ($j['status'] ?? '?') . ')', 'error' => null];
        return ['ok' => false, 'name' => null, 'error' => $r['error'] ?: ($j['message'] ?? 'HTTP ' . $r['status'])];
    }

    /** Download a customer's media; Twilio redirects the first request to a CDN. @return array{bytes:string,mime:string}|null */
    public static function fetchMedia(array $channel, string $url, ?string $mime): ?array {
        $auth = self::auth($channel);
        $ok = fn(string $u) => parse_url($u, PHP_URL_SCHEME) === 'https' && preg_match('/(^|\.)(twilio\.com|twiliocdn\.com|amazonaws\.com)$/i', (string)parse_url($u, PHP_URL_HOST));
        if (!$auth || !$ok($url)) return null;
        $r = Net::request('GET', $url, [$auth], ['timeout' => 20, 'allow_private' => false, 'max_bytes' => 8 * 1048576]);
        if (in_array($r['status'], [301, 302, 303, 307, 308], true) && !empty($r['location']) && $ok($r['location'])) {
            $r = Net::request('GET', $r['location'], [], ['timeout' => 20, 'allow_private' => false, 'max_bytes' => 8 * 1048576]);
        }
        return ($r['status'] === 200 && $r['body'] !== '') ? ['bytes' => $r['body'], 'mime' => $mime ?: 'application/octet-stream'] : null;
    }

    private static function chunk(string $t, int $max): array {
        if (mb_strlen($t) <= $max) return [$t];
        $out = []; $cur = '';
        foreach (preg_split('/\n/u', $t) as $line) {
            if (mb_strlen($cur) + mb_strlen($line) + 1 > $max && $cur !== '') { $out[] = trim($cur); $cur = ''; }
            $cur .= $line . "\n";
        }
        if (trim($cur) !== '') $out[] = trim($cur);
        return $out;
    }
}
