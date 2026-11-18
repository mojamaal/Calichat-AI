<?php
declare(strict_types=1);
namespace CaliChat\Core;

/** Outbound HTTP with SSRF protection: resolve once, vet every address (v4 + v6), pin the connection to it. */
class Net {
    public static function isPublicIp(string $ip): bool {
        return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
    /** @return string[] */
    public static function resolve(string $host): array {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) return [$host];
        $ips = [];
        foreach (@dns_get_record($host, DNS_A) ?: [] as $r) if (!empty($r['ip'])) $ips[] = $r['ip'];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
        if (!$ips) foreach (gethostbynamel($host) ?: [] as $ip) $ips[] = $ip;
        return array_values(array_unique($ips));
    }

    /**
     * @param array{timeout?:int,body?:?string,max_bytes?:int,allow_private?:bool,allowed_hosts?:string[]} $o
     * @return array{status:int,body:string,error:?string,ms:int}
     */
    public static function request(string $method, string $url, array $headers = [], array $o = []): array {
        $t0 = microtime(true);
        $res = ['status' => 0, 'body' => '', 'error' => null, 'ms' => 0, 'location' => null];
        try {
            $parts = parse_url($url);
            if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) throw new \RuntimeException('Invalid URL');
            $host = $parts['host'];
            $port = (int)($parts['port'] ?? (($parts['scheme'] === 'https') ? 443 : 80));
            if (!empty($o['allowed_hosts']) && !in_array(strtolower($host), array_map('strtolower', $o['allowed_hosts']), true)) throw new \RuntimeException('Host not on allow-list');
            $ips = self::resolve($host);
            if (!$ips) throw new \RuntimeException('Host did not resolve');
            $allowPrivate = $o['allow_private'] ?? ALLOW_PRIVATE_HOSTS;
            if (!$allowPrivate) foreach ($ips as $ip) if (!self::isPublicIp($ip)) throw new \RuntimeException('Host is not allowed');
            $pin = $ips[0];
            $max = (int)($o['max_bytes'] ?? 1048576);
            $buf = '';
            $ch = curl_init($url);
            $opts = [
                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => max(1, (int)($o['timeout'] ?? 12)), CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (strpos($pin, ':') !== false ? '[' . $pin . ']' : $pin)],
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_WRITEFUNCTION => function ($c, $d) use (&$buf, $max) { $buf .= $d; return strlen($buf) > $max ? 0 : strlen($d); },
                CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$res) { if (stripos($h, 'location:') === 0) $res['location'] = trim(substr($h, 9)); return strlen($h); },
            ];
            if (defined('CURLOPT_PATH_AS_IS')) $opts[CURLOPT_PATH_AS_IS] = true;
            if (isset($o['body']) && $o['body'] !== null) $opts[CURLOPT_POSTFIELDS] = $o['body'];
            curl_setopt_array($ch, $opts);
            curl_exec($ch);
            $res['status'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr = curl_error($ch);
            curl_close($ch);
            $res['body'] = $buf;
            if ($res['status'] === 0) throw new \RuntimeException('Connection failed: ' . ($cerr ?: 'unknown'));
        } catch (\Throwable $e) { $res['error'] = $e->getMessage(); }
        $res['ms'] = (int)((microtime(true) - $t0) * 1000);
        return $res;
    }
}
