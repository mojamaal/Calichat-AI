<?php
declare(strict_types=1);
namespace CaliChat\Memory;

use CaliChat\Core\Guard;
use CaliChat\Core\Log;
use CaliChat\Core\Net;

/**
 * Adapter for the TencentDB Agent Memory sidecar (https://github.com/TencentCloud/TencentDB-Agent-Memory).
 * POST {base}/v3/tools/call  {"name": <tool>, "arguments": {...}}.
 * Tool names/argument keys are configurable (.env) because the public README doesn't document them:
 * run `php cli/verify_tencent.php` against your deployment to list the real tools, then adjust.
 * A circuit breaker skips the sidecar for 60 s after 3 failures, and any failure falls back to MySqlMemory.
 * Isolation: every call is namespaced as user_id "t{tenant}_c{contact}".
 */
class TencentMemory implements MemoryProvider {
    private MySqlMemory $fallback;
    private const BREAKER = 'tencent_memory';
    public function __construct() { $this->fallback = new MySqlMemory(); }

    private function call(string $tool, array $args): ?array {
        $base = rtrim((string)env('TENCENT_MEMORY_URL', ''), '/');
        if ($base === '' || Guard::circuitOpen(self::BREAKER)) return null;
        $h = ['Content-Type: application/json'];
        if ($tok = env('TENCENT_MEMORY_TOKEN')) $h[] = 'Authorization: Bearer ' . $tok;
        $r = Net::request('POST', $base . '/v3/tools/call', $h, ['timeout' => 4, 'allow_private' => true, 'max_bytes' => 262144, 'body' => json_encode(['name' => $tool, 'arguments' => $args], JSON_UNESCAPED_UNICODE)]);
        if ($r['error'] || $r['status'] < 200 || $r['status'] >= 300) {
            if (Guard::circuitFailure(self::BREAKER, $r['error'] ?: 'HTTP ' . $r['status'], 3, 60)) Log::warn('tencent_memory_circuit_open');
            return null;
        }
        Guard::circuitSuccess(self::BREAKER);
        $j = json_decode($r['body'], true);
        return is_array($j) ? $j : null;
    }
    private static function uid(array $tenant, array $contact): string { return 't' . $tenant['id'] . '_c' . $contact['id']; }

    public function recall(array $tenant, array $contact, string $query): string {
        $r = $this->call((string)env('TENCENT_TOOL_RECALL', 'memory_recall'), ['user_id' => self::uid($tenant, $contact), 'query' => $query, 'limit' => 8]);
        if ($r === null) return $this->fallback->recall($tenant, $contact, $query);
        $txt = $r['result']['text'] ?? $r['text'] ?? (is_string($r['result'] ?? null) ? $r['result'] : '');
        if ($txt === '' && isset($r['result']) && is_array($r['result'])) $txt = json_encode($r['result'], JSON_UNESCAPED_UNICODE);
        return trim((string)$txt) ?: $this->fallback->recall($tenant, $contact, $query);
    }

    public function capture(array $tenant, array $contact, int $conversationId, string $userText, string $aiText): void {
        $r = $this->call((string)env('TENCENT_TOOL_CAPTURE', 'memory_capture'), [
            'user_id' => self::uid($tenant, $contact), 'session_id' => 'conv_' . $conversationId,
            'messages' => [['role' => 'user', 'content' => $userText], ['role' => 'assistant', 'content' => $aiText]],
        ]);
        if ($r === null) $this->fallback->capture($tenant, $contact, $conversationId, $userText, $aiText);
    }

    /** For cli/verify_tencent.php */
    public static function listTools(): array {
        $base = rtrim((string)env('TENCENT_MEMORY_URL', ''), '/');
        $h = []; if ($tok = env('TENCENT_MEMORY_TOKEN')) $h[] = 'Authorization: Bearer ' . $tok;
        return Net::request('GET', $base . '/v3/tools/list', $h, ['timeout' => 5, 'allow_private' => true]);
    }
}
