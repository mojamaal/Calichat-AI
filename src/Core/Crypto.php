<?php
declare(strict_types=1);
namespace CaliChat\Core;

/**
 * AES-256-GCM with key ids so keys can be rotated.
 *  v2:<kid>:<b64(iv|tag|ct)>  keys from APP_KEYS="kid2:secret2,kid1:secret1" (first = current)
 *  v1:<b64>                   legacy single APP_KEY (still readable)
 */
class Crypto {
    /** @return array<string,string> kid => raw secret, current first */
    private static function keys(): array {
        $out = [];
        foreach (explode(',', (string)env('APP_KEYS', '')) as $pair) {
            if (strpos($pair, ':') === false) continue;
            [$kid, $sec] = explode(':', trim($pair), 2);
            if (strlen($sec) >= 16 && preg_match('/^[A-Za-z0-9_\-]{1,16}$/', $kid)) $out[$kid] = $sec;
        }
        $legacy = (string)env('APP_KEY', '');
        if (strlen($legacy) >= 16 && !isset($out['k1'])) $out['k1'] = $legacy;
        if (!$out) throw new \RuntimeException('No encryption key configured (APP_KEY / APP_KEYS)');
        return $out;
    }
    private static function derive(string $secret): string { return hash_hkdf('sha256', $secret, 32, 'calichat-secrets-v2'); }
    public static function currentKid(): string { return (string)array_key_first(self::keys()); }

    public static function encrypt(?string $plain): ?string {
        if ($plain === null || $plain === '') return null;
        $keys = self::keys(); $kid = array_key_first($keys);
        $iv = random_bytes(12);
        $c = openssl_encrypt($plain, 'aes-256-gcm', self::derive($keys[$kid]), OPENSSL_RAW_DATA, $iv, $tag);
        return 'v2:' . $kid . ':' . base64_encode($iv . $tag . $c);
    }
    public static function decrypt(?string $blob): ?string {
        if (!$blob) return null;
        $keys = self::keys();
        if (strpos($blob, 'v2:') === 0) {
            $parts = explode(':', $blob, 3);
            if (count($parts) !== 3 || !isset($keys[$parts[1]])) return null;
            $k = self::derive($keys[$parts[1]]); $b64 = $parts[2];
        } elseif (strpos($blob, 'v1:') === 0) {
            $legacy = (string)env('APP_KEY', '');
            if (strlen($legacy) < 16) return null;
            $k = hash('sha256', $legacy, true); $b64 = substr($blob, 3);
        } else return null;
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) < 29) return null;
        $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $k, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $p === false ? null : $p;
    }
    public static function needsRotation(?string $blob): bool {
        return $blob !== null && $blob !== '' && strpos($blob, 'v2:' . self::currentKid() . ':') !== 0;
    }
}
