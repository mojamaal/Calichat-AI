<?php
declare(strict_types=1);
namespace CaliChat\Services;

use CaliChat\Core\Crypto;
use CaliChat\Core\Net;
use CaliChat\Core\Tenant;

/**
 * SMS through the business's own SMS provider (Dialog, Mobitel, Text.lk, Notify.lk and similar all offer an HTTP API).
 * The owner enters the provider's URL, how to call it, and a body template with {to} and {message}; a secret header can carry the API key.
 * Used as a fallback when WhatsApp cannot reach a customer (24-hour window closed and no approved template).
 */
class Sms {
    public static function config(int $tid): array {
        $c = Tenant::get($tid)['cfg'] ?? [];
        return ['url' => (string)($c['sms_url'] ?? ''), 'method' => ($c['sms_method'] ?? 'POST') === 'GET' ? 'GET' : 'POST', 'body' => (string)($c['sms_body'] ?? ''), 'header' => (string)($c['sms_header'] ?? ''), 'secret' => (string)Crypto::decrypt($c['sms_secret'] ?? null)];
    }
    public static function enabled(int $tid): bool { return self::config($tid)['url'] !== ''; }
    public static function urlOk(string $u): bool { return (bool)preg_match(Meta::allowPrivate() ? '#^https?://[\w.\-:]+(/\S*)?$#i' : '#^https://[\w.\-]+(:\d+)?(/\S*)?$#i', $u); }

    /** @return array{ok:bool,error:?string} */
    public static function send(int $tid, string $to, string $text): array {
        $c = self::config($tid); if ($c['url'] === '' || !self::urlOk($c['url'])) return ['ok' => false, 'error' => 'SMS is not set up'];
        $to = Store::digits($to); if (strlen($to) < 9) return ['ok' => false, 'error' => 'No phone number'];
        $text = mb_substr(trim($text), 0, 450); $headers = [];
        if ($c['header'] !== '' && $c['secret'] !== '') $headers[] = $c['header'] . ': ' . $c['secret'];
        $url = str_replace(['{to}', '{message}'], [rawurlencode($to), rawurlencode($text)], $c['url']); $body = null;
        if ($c['method'] === 'POST') {
            $tpl = $c['body'] !== '' ? $c['body'] : 'to={to}&message={message}';
            if (ltrim($tpl)[0] === '{') { $body = str_replace(['{to}', '{message}'], [trim(json_encode($to), '"'), trim(json_encode($text, JSON_UNESCAPED_UNICODE), '"')], $tpl); $headers[] = 'Content-Type: application/json'; }
            else { $body = str_replace(['{to}', '{message}'], [rawurlencode($to), rawurlencode($text)], $tpl); $headers[] = 'Content-Type: application/x-www-form-urlencoded'; }
        }
        $r = Net::request($c['method'], $url, $headers, ['timeout' => 15, 'allow_private' => Meta::allowPrivate(), 'max_bytes' => 65536, 'body' => $body]);
        if ($r['status'] >= 200 && $r['status'] < 300) return ['ok' => true, 'error' => null];
        return ['ok' => false, 'error' => 'SMS provider: ' . ($r['error'] ?: 'HTTP ' . $r['status'] . ' ' . mb_substr(trim((string)$r['body']), 0, 120))];
    }
}
