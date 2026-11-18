<?php
declare(strict_types=1);
namespace CaliChat\Services;

use CaliChat\Core\Db;
use CaliChat\Core\Log;
use CaliChat\Core\Tenant;

/**
 * Hybrid retrieval: Gemini embeddings (semantic, handles Sinhala/Tamil/paraphrase) fused with keyword matching.
 * The semantic leg scans only this tenant's chunks, so cost and ranking never depend on other tenants' data.
 */
class Knowledge {
    private const VECTOR_SCAN_LIMIT = 1500;

    public static function pack(array $v): string { return pack('g*', ...$v); }
    public static function unpack(string $b): array { return array_values(unpack('g*', $b) ?: []); }

    public static function index(int $tenantId, int $docId, string $content): void {
        Db::q("DELETE FROM knowledge_chunks WHERE doc_id=? AND tenant_id=?", [$docId, $tenantId]);
        $paras = preg_split('/\n\s*\n/u', trim($content)) ?: [];
        $chunks = []; $buf = '';
        $flush = function () use (&$buf, &$chunks) { if (trim($buf) !== '') $chunks[] = trim($buf); $buf = ''; };
        foreach ($paras as $p) {
            if (mb_strlen($buf) + mb_strlen($p) > 700) $flush();
            while (mb_strlen($p) > 900) { $buf = mb_substr($p, 0, 900); $flush(); $p = mb_substr($p, 900); }
            $buf .= ($buf ? "\n\n" : '') . $p;
        }
        $flush();
        $t = Tenant::get($tenantId);
        $vecs = $t ? self::tryEmbed(Tenant::geminiKey($t), $chunks, 'RETRIEVAL_DOCUMENT', $tenantId) : null;
        $model = (string)env('GEMINI_EMBED_MODEL', 'gemini-embedding-001');
        foreach ($chunks as $i => $c) {
            Db::insert('knowledge_chunks', ['tenant_id' => $tenantId, 'doc_id' => $docId, 'content' => $c,
                'embedding' => $vecs ? self::pack($vecs[$i]) : null, 'embed_model' => $vecs ? $model : null]);
        }
    }

    /** Embed any chunks that don't have a vector yet. @return int number of chunks embedded */
    public static function reindex(int $tenantId): int {
        $t = Tenant::get($tenantId); if (!$t) return 0;
        $rows = Db::all("SELECT id, content FROM knowledge_chunks WHERE tenant_id=? AND embedding IS NULL", [$tenantId]);
        $n = 0;
        foreach (array_chunk($rows, 50) as $batch) {
            $vecs = self::tryEmbed(Tenant::geminiKey($t), array_column($batch, 'content'), 'RETRIEVAL_DOCUMENT', $tenantId);
            if (!$vecs) continue;
            foreach ($batch as $i => $r) { Db::update('knowledge_chunks', ['embedding' => self::pack($vecs[$i]), 'embed_model' => (string)env('GEMINI_EMBED_MODEL', 'gemini-embedding-001')], 'id=? AND tenant_id=?', [$r['id'], $tenantId]); $n++; }
        }
        return $n;
    }

    private static function tryEmbed(string $key, array $texts, string $task, int $tid): ?array {
        try { return Gemini::embed($key, $texts, $task, $tid); } catch (\Throwable $e) { Log::exception($e, 'embed_failed'); return null; }
    }

    /** @return array<int,array{title:string,content:string,score:float}> */
    public static function search(int $tenantId, string $query, int $limit = 4, bool $logMiss = true, bool $semantic = true): array {
        $query = trim($query);
        if ($query === '') return [];
        $rank = []; // chunk id => RRF score
        $meta = [];

        // 1) keyword leg (tenant filter in the same query; LIKE fallback for short or non-Latin tokens)
        $lex = [];
        try {
            $lex = Db::all("SELECT c.id, c.content, d.title FROM knowledge_chunks c JOIN knowledge_docs d ON d.id=c.doc_id
                WHERE c.tenant_id=? AND MATCH(c.content) AGAINST (? IN NATURAL LANGUAGE MODE) LIMIT 20", [$tenantId, $query]);
        } catch (\Throwable $e) { $lex = []; }
        if (!$lex) {
            $words = array_slice(array_filter(preg_split('/\s+/u', $query), fn($w) => mb_strlen($w) >= 2), 0, 5);
            if ($words) {
                $like = implode(' OR ', array_fill(0, count($words), 'c.content LIKE ?'));
                $lex = Db::all("SELECT c.id, c.content, d.title FROM knowledge_chunks c JOIN knowledge_docs d ON d.id=c.doc_id WHERE c.tenant_id=? AND ($like) LIMIT 20",
                    array_merge([$tenantId], array_map(fn($w) => '%' . $w . '%', $words)));
            }
        }
        foreach ($lex as $i => $r) { $rank[$r['id']] = ($rank[$r['id']] ?? 0) + 1 / (60 + $i + 1); $meta[$r['id']] = $r; }

        // 2) semantic leg
        $bestSim = 0.0; $t = Tenant::get($tenantId);
        $hasVec = (int)Db::val("SELECT COUNT(*) FROM knowledge_chunks WHERE tenant_id=? AND embedding IS NOT NULL", [$tenantId]) > 0;
        if ($t && $hasVec && $semantic) {
            $qv = self::tryEmbed(Tenant::geminiKey($t), [$query], 'RETRIEVAL_QUERY', $tenantId);
            if ($qv) {
                $q = $qv[0]; $scores = [];
                $rows = Db::all("SELECT c.id, c.content, c.embedding, d.title FROM knowledge_chunks c JOIN knowledge_docs d ON d.id=c.doc_id WHERE c.tenant_id=? AND c.embedding IS NOT NULL ORDER BY c.id DESC LIMIT " . self::VECTOR_SCAN_LIMIT, [$tenantId]);
                foreach ($rows as $r) {
                    $v = self::unpack($r['embedding']); $dot = 0.0;
                    for ($i = 0, $n = min(count($v), count($q)); $i < $n; $i++) $dot += $v[$i] * $q[$i];
                    $scores[$r['id']] = $dot; $meta[$r['id']] = $meta[$r['id']] ?? ['id' => $r['id'], 'content' => $r['content'], 'title' => $r['title']];
                }
                arsort($scores); $i = 0;
                foreach (array_slice($scores, 0, 20, true) as $id => $s) {
                    if ($i === 0) $bestSim = $s;
                    if ($s >= 0.35) $rank[$id] = ($rank[$id] ?? 0) + 1 / (60 + $i + 1);
                    $i++;
                }
            }
        }
        arsort($rank);
        $out = [];
        foreach (array_slice($rank, 0, $limit, true) as $id => $score) $out[] = ['title' => $meta[$id]['title'], 'content' => $meta[$id]['content'], 'score' => round($score, 4)];

        if ($logMiss && (!$out || ($hasVec && $bestSim < 0.5 && !$lex))) self::logMiss($tenantId, $query);
        return $out;
    }

    private static function logMiss(int $tid, string $q): void {
        try {
            $q = mb_substr($q, 0, 290);
            Db::q("INSERT INTO kb_misses (tenant_id, qhash, query, hits, last_seen) VALUES (?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE hits=hits+1, last_seen=NOW()", [$tid, md5(mb_strtolower($q)), $q]);
        } catch (\Throwable $e) {}
    }
}
