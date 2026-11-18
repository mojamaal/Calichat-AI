<?php
declare(strict_types=1);
namespace CaliChat\Services;

use CaliChat\Core\Db;
use CaliChat\Core\Guard;
use CaliChat\Core\Log;
use CaliChat\Core\Tenant;

/**
 * Gemini gateway: per-tenant + global rate limits, monthly token budget, circuit breaker per model,
 * fallback model, deadline-aware timeouts, and a metrics row for every call (ai_calls).
 */
class Gemini {
    public static function model(): string { return (string)env('GEMINI_MODEL', 'gemini-3.5-flash-lite'); }
    public static function fallbackModel(): ?string { $m = (string)env('GEMINI_FALLBACK_MODEL', 'gemini-3.1-flash-lite'); return $m !== '' && $m !== self::model() ? $m : null; }

    /**
     * @param array{tenant_id?:int,conversation_id?:int,kind?:string,is_test?:bool,deadline?:float,model?:string,fallback?:bool} $o
     */
    public static function generate(string $apiKey, array $body, array $o = []): array {
        if ($apiKey === '') throw new LlmException('Gemini API key is not configured.', false, null, 'config');
        $tid = $o['tenant_id'] ?? null;
        if ($tid) {
            $t = Db::one("SELECT monthly_token_limit, llm_rpm FROM tenants WHERE id=?", [$tid]);
            if ($t && Tenant::monthTokens((int)$tid) >= (int)$t['monthly_token_limit']) throw new LlmException('Monthly AI token budget reached', false, null, 'budget');
            if ($t && !Guard::allow('llm:t' . $tid, (int)$t['llm_rpm'])) throw new LlmException('Tenant AI rate limit reached', true, 429, 'throttled', Guard::secondsToWindowEnd());
        }
        if (!Guard::allow('llm:global', (int)env('GEMINI_GLOBAL_RPM', 600))) throw new LlmException('Platform AI rate limit reached', true, 429, 'throttled', Guard::secondsToWindowEnd());

        $models = [$o['model'] ?? self::model()];
        if (($o['fallback'] ?? true) && ($fb = self::fallbackModel())) $models[] = $fb;
        $last = null;
        foreach ($models as $model) {
            if (Guard::circuitOpen('gemini:' . $model)) { $last = new LlmException("Model $model temporarily unavailable (circuit open)", true, 503, 'circuit', 30); continue; }
            try { return self::call($apiKey, $body, $model, $o); }
            catch (LlmException $e) {
                $last = $e;
                if (!$e->retryable && $e->status !== 404) break; // client errors would fail on the fallback too
            }
        }
        throw $last ?? new LlmException('No model available', true);
    }

    private static function call(string $apiKey, array $body, string $model, array $o): array {
        $tid = $o['tenant_id'] ?? null;
        $url = rtrim((string)env('GEMINI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta'), '/') . '/models/' . rawurlencode($model) . ':generateContent';
        $think = env('GEMINI_THINKING');
        if ($think && empty($body['generationConfig']['thinkingConfig'])) $body['generationConfig']['thinkingConfig'] = ['thinkingLevel' => $think];
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $maxTry = 2; $last = null;
        for ($try = 1; $try <= $maxTry; $try++) {
            $remaining = isset($o['deadline']) ? $o['deadline'] - microtime(true) : 999;
            if ($remaining < 3) { $last = $last ?: new LlmException('Time budget exhausted', true, 504, 'timeout'); break; }
            $timeout = (int)min((int)env('GEMINI_TIMEOUT', 20), floor($remaining));
            $t0 = microtime(true);
            $ch = self::handle($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey],
            ]);
            if (($pin = self::pinnedAddress($url)) !== null) curl_setopt($ch, CURLOPT_RESOLVE, [$pin]);
            $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
            if ($code === 0) { self::forgetPin($url); self::$ch = null; curl_close($ch); } // connection trouble: next attempt resolves the name afresh on a new connection
            $ms = (int)((microtime(true) - $t0) * 1000);
            $j = ($res !== false && $code === 200) ? json_decode($res, true) : null;
            if (is_array($j)) {
                $finish = $j['candidates'][0]['finishReason'] ?? null;
                self::record($tid, $o, $model, true, $code, $ms, $j['usageMetadata'] ?? [], $finish, null);
                Guard::circuitSuccess('gemini:' . $model);
                return $j;
            }
            $msg = $res ? (json_decode($res, true)['error']['message'] ?? substr((string)$res, 0, 200)) : $err;
            $retryable = in_array($code, [0, 408, 429, 500, 502, 503, 504], true);
            $last = new LlmException("Gemini HTTP $code: $msg", $retryable, $code ?: null, $retryable ? 'transient' : 'error');
            self::record($tid, $o, $model, false, $code, $ms, [], null, $msg);
            if ($retryable || $code === 404) {
                if (Guard::circuitFailure('gemini:' . $model, $last->getMessage())) {
                    Notifier::alert(null, 'crit', 'llm_circuit', "Gemini model $model failing repeatedly; circuit opened for 60s. Last error: " . mb_substr($msg, 0, 160), 'llm_circuit:' . $model, 1);
                }
            }
            if (!$retryable) break;
            if ($code === 503 || $code === 429) break; // the model is overloaded: retrying it only adds seconds, the fallback model answers instead
            usleep(300000);
        }
        throw $last;
    }

    // ------------------------------------------------------------------ connection speed
    /** @var resource|\CurlHandle|null one connection kept open for every call this process makes (the worker keeps it for hours) */
    private static $ch = null;
    private static function handle(string $url) {
        if (self::$ch === null) self::$ch = curl_init(); else curl_reset(self::$ch); // curl_reset keeps the open TLS connection
        curl_setopt(self::$ch, CURLOPT_URL, $url);
        return self::$ch;
    }
    private static function pinFile(string $host): string { return ROOT . '/storage/cache/dns_' . preg_replace('/[^a-z0-9.-]/i', '', $host) . '.json'; }
    /**
     * "host:443:ip" for CURLOPT_RESOLVE. On some networks name lookups stall for seconds at random (measured: 3-5 s per call on the dev machine, 0.06 s by address),
     * so the address is remembered for 30 minutes and used directly; a failed connection throws the remembered address away.
     */
    private static function pinnedAddress(string $url): ?string {
        $host = (string)parse_url($url, PHP_URL_HOST); if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) return null;
        $f = self::pinFile($host); $c = @json_decode((string)@file_get_contents($f), true);
        if (!is_array($c) || empty($c['ip']) || ($c['at'] ?? 0) < time() - 1800) {
            $ips = array_values(array_filter((array)@gethostbynamel($host), fn($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));
            if (!$ips) return null;
            $c = ['ip' => $ips[array_rand($ips)], 'at' => time()];
            if (!is_dir(dirname($f))) @mkdir(dirname($f), 0775, true);
            @file_put_contents($f, json_encode($c));
        }
        return $host . ':443:' . $c['ip'];
    }
    private static function forgetPin(string $url): void { $host = (string)parse_url($url, PHP_URL_HOST); if ($host !== '') @unlink(self::pinFile($host)); }

    private static function record(?int $tid, array $o, string $model, bool $ok, int $code, int $ms, array $usage, ?string $finish, ?string $error): void {
        if (!$tid) return;
        try {
            $in = (int)($usage['promptTokenCount'] ?? 0); $out = (int)($usage['candidatesTokenCount'] ?? 0) + (int)($usage['thoughtsTokenCount'] ?? 0);
            $test = !empty($o['is_test']);
            Db::insert('ai_calls', ['tenant_id' => $tid, 'conversation_id' => $o['conversation_id'] ?? null, 'kind' => $o['kind'] ?? 'reply', 'model' => mb_substr($model, 0, 80),
                'ok' => $ok ? 1 : 0, 'http_status' => $code ?: null, 'latency_ms' => $ms, 'tokens_in' => $in, 'tokens_out' => $out, 'finish_reason' => $finish ? mb_substr($finish, 0, 30) : null,
                'error' => $error ? mb_substr($error, 0, 290) : null, 'is_test' => $test ? 1 : 0]);
            Tenant::usage($tid, 'ai_calls'); Tenant::usage($tid, 'latency_ms_total', $ms);
            if (!$ok) Tenant::usage($tid, 'ai_errors');
            if ($test) Tenant::usage($tid, 'test_tokens', $in + $out); else { Tenant::usage($tid, 'tokens_in', $in); Tenant::usage($tid, 'tokens_out', $out); }
        } catch (\Throwable $e) { Log::exception($e, 'ai_record_failed'); }
    }

    public static function text(array $resp): string {
        $out = '';
        foreach ($resp['candidates'][0]['content']['parts'] ?? [] as $p) {
            if (isset($p['text']) && empty($p['thought'])) $out .= $p['text'];
        }
        return trim($out);
    }
    public static function finishReason(array $resp): ?string { return $resp['candidates'][0]['finishReason'] ?? null; }

    /** One-shot JSON-returning helper (memory extraction, summaries). */
    public static function json(string $apiKey, string $system, string $prompt, array $o = []): ?array {
        $r = self::generate($apiKey, [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.1, 'responseMimeType' => 'application/json', 'maxOutputTokens' => 1500],
        ], $o + ['kind' => 'extract']);
        $j = json_decode(self::text($r), true);
        return is_array($j) ? $j : null;
    }

    /** Plain text helper. */
    public static function plain(string $apiKey, string $system, array $parts, array $o = []): string {
        $r = self::generate($apiKey, [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 1500],
        ], $o);
        return Gemini::text($r);
    }

    /**
     * Embeddings (gemini-embedding-001). Returns list of normalised float vectors, or null on failure.
     * @param string[] $texts
     */
    public static function embed(string $apiKey, array $texts, string $task = 'RETRIEVAL_DOCUMENT', ?int $tid = null): ?array {
        if ($apiKey === '' || !$texts) return null;
        $model = (string)env('GEMINI_EMBED_MODEL', 'gemini-embedding-001');
        $dim = (int)env('EMBED_DIM', 256);
        $vectors = [];
        foreach (array_chunk($texts, 50) as $chunk) {
            $reqs = array_map(fn($t) => ['model' => 'models/' . $model, 'content' => ['parts' => [['text' => mb_substr($t, 0, 6000)]]], 'taskType' => $task, 'outputDimensionality' => $dim], $chunk);
            $t0 = microtime(true);
            $ch = curl_init(rtrim((string)env('GEMINI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta'), '/') . '/models/' . rawurlencode($model) . ':batchEmbedContents');
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['requests' => $reqs], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey]]);
            $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            $ms = (int)((microtime(true) - $t0) * 1000);
            $j = $res ? json_decode($res, true) : null;
            $est = (int)(array_sum(array_map('mb_strlen', $chunk)) / 4);
            self::record($tid, ['kind' => 'embed'], $model, $code === 200 && isset($j['embeddings']), $code, $ms, ['promptTokenCount' => $est], null, $code === 200 ? null : substr((string)$res, 0, 200));
            if ($code !== 200 || empty($j['embeddings'])) return null;
            foreach ($j['embeddings'] as $e) {
                $v = array_map('floatval', $e['values'] ?? []);
                $norm = sqrt(array_sum(array_map(fn($x) => $x * $x, $v))) ?: 1.0;
                $vectors[] = array_map(fn($x) => $x / $norm, $v);
            }
        }
        return count($vectors) === count($texts) ? $vectors : null;
    }
}
