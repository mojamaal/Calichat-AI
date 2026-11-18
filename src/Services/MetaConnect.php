<?php
declare(strict_types=1);
namespace CaliChat\Services;

use CaliChat\Core\Crypto;
use CaliChat\Core\Db;
use CaliChat\Core\Net;
use CaliChat\Core\Platform;

/**
 * One-click channel connection through the PLATFORM's Meta app ("Tech Provider" model):
 *  - Messenger + Instagram: Facebook Login (OAuth) -> pick Pages / Instagram accounts -> subscribe the Page -> channels created
 *  - WhatsApp: Embedded Signup (JS SDK) -> code + WABA id + phone number id -> subscribe WABA -> channel created
 * All inbound events arrive on ONE webhook (/webhook/meta) signed with the platform app secret and are routed to the right tenant by page / phone number id.
 */
class MetaConnect {
    public const SCOPE = 'pages_show_list,pages_messaging,pages_manage_metadata,instagram_basic,instagram_manage_messages,business_management';

    public static function appId(): string { return (string)Platform::get('meta_app_id', ''); }
    public static function appSecret(): string { return (string)Platform::get('meta_app_secret', ''); }
    public static function configId(): string { return (string)Platform::get('meta_wa_config_id', ''); }
    public static function configured(): bool { return self::appId() !== '' && self::appSecret() !== ''; }
    public static function whatsappReady(): bool { return self::configured() && self::configId() !== ''; }
    public static function redirectUri(): string { return abs_url('meta/callback'); }

    public static function verifyToken(): string {
        $t = Platform::get('meta_verify_token');
        if (!$t) { $t = rand_key(16); Platform::set('meta_verify_token', $t); }
        return $t;
    }

    public static function loginUrl(string $state): string {
        return rtrim((string)env('META_OAUTH_BASE', 'https://www.facebook.com'), '/') . '/' . env('META_GRAPH_VERSION', 'v21.0') . '/dialog/oauth?' .
            http_build_query(['client_id' => self::appId(), 'redirect_uri' => self::redirectUri(), 'state' => $state, 'scope' => self::SCOPE, 'response_type' => 'code']);
    }

    /** CSRF state bound to the signed-in user and tenant. */
    public static function newState(int $tenantId, int $userId): string {
        $nonce = rand_key(16);
        $_SESSION['meta_state'] = ['n' => $nonce, 't' => $tenantId, 'u' => $userId, 'exp' => time() + 900];
        return $nonce . '.' . hash_hmac('sha256', "$nonce|$tenantId|$userId", (string)env('APP_KEY') . (string)env('APP_KEYS'));
    }
    public static function checkState(string $state, int $tenantId, int $userId): bool {
        $s = $_SESSION['meta_state'] ?? null; unset($_SESSION['meta_state']);
        if (!$s || $s['exp'] < time() || (int)$s['t'] !== $tenantId || (int)$s['u'] !== $userId) return false;
        return hash_equals($s['n'] . '.' . hash_hmac('sha256', "{$s['n']}|$tenantId|$userId", (string)env('APP_KEY') . (string)env('APP_KEYS')), $state);
    }

    /** Graph call. @return array{ok:bool,status:int,json:array,error:?string} */
    public static function call(string $method, string $path, array $params = [], ?string $token = null): array {
        $url = Meta::graphBase() . '/' . ltrim($path, '/');
        $headers = $token ? ['Authorization: Bearer ' . $token] : [];
        $opt = ['timeout' => 15, 'allow_private' => Meta::allowPrivate(), 'max_bytes' => 262144];
        if ($method === 'GET') { if ($params) $url .= '?' . http_build_query($params); }
        else { $opt['body'] = http_build_query($params); $headers[] = 'Content-Type: application/x-www-form-urlencoded'; }
        $r = Net::request($method, $url, $headers, $opt);
        $j = json_decode($r['body'], true) ?: [];
        return ['ok' => $r['status'] >= 200 && $r['status'] < 300 && !$r['error'], 'status' => $r['status'], 'json' => $j, 'error' => $r['error'] ?: ($j['error']['message'] ?? ($r['status'] >= 300 ? 'HTTP ' . $r['status'] : null))];
    }

    /** Authorization code -> long-lived user token. @throws \RuntimeException */
    public static function tokenFromCode(string $code, bool $withRedirect = true): string {
        $p = ['client_id' => self::appId(), 'client_secret' => self::appSecret(), 'code' => $code];
        if ($withRedirect) $p['redirect_uri'] = self::redirectUri();
        $r = self::call('GET', 'oauth/access_token', $p);
        if (!$r['ok'] || empty($r['json']['access_token'])) throw new \RuntimeException('Meta did not accept the login: ' . ($r['error'] ?: 'unknown error'));
        $short = (string)$r['json']['access_token'];
        $l = self::call('GET', 'oauth/access_token', ['grant_type' => 'fb_exchange_token', 'client_id' => self::appId(), 'client_secret' => self::appSecret(), 'fb_exchange_token' => $short]);
        return ($l['ok'] && !empty($l['json']['access_token'])) ? (string)$l['json']['access_token'] : $short;
    }

    /** @return array<int,array{id:string,name:string,token:string,ig_id:?string,ig_name:?string}> */
    public static function pages(string $userToken): array {
        $r = self::call('GET', 'me/accounts', ['fields' => 'id,name,access_token,instagram_business_account{id,username}', 'limit' => 100], $userToken);
        if (!$r['ok']) throw new \RuntimeException('Could not list your Facebook Pages: ' . $r['error']);
        $out = [];
        foreach ($r['json']['data'] ?? [] as $p) {
            if (empty($p['id']) || empty($p['access_token'])) continue;
            $out[] = ['id' => (string)$p['id'], 'name' => (string)($p['name'] ?? $p['id']), 'token' => (string)$p['access_token'],
                'ig_id' => isset($p['instagram_business_account']['id']) ? (string)$p['instagram_business_account']['id'] : null, 'ig_name' => $p['instagram_business_account']['username'] ?? null];
        }
        return $out;
    }

    public static function subscribePage(string $pageId, string $pageToken): array {
        return self::call('POST', rawurlencode($pageId) . '/subscribed_apps', ['subscribed_fields' => 'messages,messaging_postbacks,message_reads'], $pageToken);
    }
    public static function subscribeWaba(string $wabaId, string $token): array { return self::call('POST', rawurlencode($wabaId) . '/subscribed_apps', [], $token); }
    public static function registerNumber(string $phoneId, string $token): array {
        return self::call('POST', rawurlencode($phoneId) . '/register', ['messaging_product' => 'whatsapp', 'pin' => (string)random_int(100000, 999999)], $token);
    }
    public static function phoneInfo(string $phoneId, string $token): array {
        return self::call('GET', rawurlencode($phoneId), ['fields' => 'display_phone_number,verified_name'], $token);
    }

    /** Create or refresh a platform-managed channel for a tenant. @return int channel id */
    public static function saveChannel(int $tenantId, string $type, string $externalId, string $name, string $token, ?string $wabaId = null): int {
        // One Page / account / number can belong to only one workspace, otherwise incoming messages could not be routed safely.
        if (Db::val("SELECT id FROM channels WHERE type=? AND external_id=? AND managed=1 AND tenant_id<>? LIMIT 1", [$type, $externalId, $tenantId]))
            throw new \RuntimeException('This account is already connected to another workspace. Disconnect it there first.');
        $ex =Db::one("SELECT id FROM channels WHERE tenant_id=? AND type=? AND external_id=?", [$tenantId, $type, $externalId]);
        $d = ['name' => mb_substr($name, 0, 120), 'access_token' => Crypto::encrypt($token), 'managed' => 1, 'waba_id' => $wabaId, 'verified_at' => now(), 'verified_name' => mb_substr($name, 0, 150), 'verify_error' => null, 'last_error' => null, 'status' => 'active'];
        if ($ex) { Db::update('channels', $d, 'id=? AND tenant_id=?', [$ex['id'], $tenantId]); return (int)$ex['id']; }
        return Db::insert('channels', $d + ['tenant_id' => $tenantId, 'type' => $type, 'external_id' => $externalId, 'verify_token' => rand_key(12), 'channel_key' => rand_key(10)]);
    }
}
