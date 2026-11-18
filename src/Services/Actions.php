<?php
declare(strict_types=1);
namespace CaliChat\Services;

use CaliChat\Core\Audit;
use CaliChat\Core\Db;

/** Team-approval queue for AI actions on tools marked "requires approval". */
class Actions {
    public static function summarize(array $tool, array $args): string {
        $parts = [];
        foreach (array_diff_key($args, ['customer_confirmed' => 1]) as $k => $v) $parts[] = $k . '=' . (is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE));
        return mb_substr($tool['name'] . ': ' . implode(', ', $parts), 0, 590);
    }

    public static function create(array $tool, array $args, array $contact, int $convId): int {
        $id = Db::insert('pending_actions', ['tenant_id' => $tool['tenant_id'], 'conversation_id' => $convId, 'contact_id' => $contact['id'], 'tool_id' => $tool['id'], 'tool_name' => $tool['name'],
            'args' => json_encode(array_diff_key($args, ['customer_confirmed' => 1]), JSON_UNESCAPED_UNICODE), 'summary' => self::summarize($tool, $args)]);
        Notifier::alert((int)$tool['tenant_id'], 'warn', 'approval', 'The AI needs your approval: ' . self::summarize($tool, $args), 'approval:' . $id, 1);
        return $id;
    }

    /** @return array{ok:bool,message:string} */
    public static function decide(int $tenantId, int $id, bool $approve, array $user): array {
        $a = Db::one("SELECT * FROM pending_actions WHERE id=? AND tenant_id=? AND status='pending'", [$id, $tenantId]);
        if (!$a) return ['ok' => false, 'message' => 'This request was already handled.'];
        $claimed = Db::q("UPDATE pending_actions SET status=?, decided_by=?, decided_at=NOW() WHERE id=? AND status='pending'", [$approve ? 'done' : 'rejected', $user['id'], $id])->rowCount();
        if (!$claimed) return ['ok' => false, 'message' => 'This request was already handled.'];
        $conv = Db::one("SELECT c.*, ct.external_id, ct.phone, ct.name, ct.id contact_id_x FROM conversations c JOIN contacts ct ON ct.id=c.contact_id WHERE c.id=? AND c.tenant_id=?", [$a['conversation_id'], $tenantId]);
        $text = 'We were unable to complete this request. A team member will follow up with you shortly.';
        $ok = false;
        if ($approve) {
            $tool = Db::one("SELECT * FROM tools WHERE id=? AND tenant_id=?", [$a['tool_id'], $tenantId]);
            $contact = $conv ? ['id' => $conv['contact_id'], 'phone' => $conv['phone'], 'external_id' => $conv['external_id'], 'name' => $conv['name']] : [];
            if ($tool && $conv) {
                $r = ToolRunner::run($tool, (array)json_decode($a['args'], true) + ['customer_confirmed' => true], $contact, (int)$a['conversation_id'], ['approved' => true]);
                $ok = $r['ok'];
                Db::update('pending_actions', ['status' => $ok ? 'done' : 'failed', 'result' => json_encode($r['ok'] ? $r['data'] : ['error' => $r['error']], JSON_UNESCAPED_UNICODE)], 'id=?', [$id]);
                if ($ok) {
                    $d = $r['data'];
                    $msg = is_array($d) ? ($d['message'] ?? null) : null;
                    $ref = is_array($d) ? ($d['order_no'] ?? $d['id'] ?? null) : null;
                    $text = '✅ Approved and completed' . ($ref ? " (ref {$ref})" : '') . '. ' . ($msg ?: $a['summary']);
                } else $text = 'We approved your request but hit a problem completing it (' . mb_substr((string)$r['error'], 0, 120) . '). A team member will follow up.';
            }
        }
        if ($conv) {
            $meta = ['user' => $user['name'], 'approval' => $approve ? 'approved' : 'rejected'];
            if ($conv['channel_id']) {
                $ch = Db::one("SELECT * FROM channels WHERE id=? AND tenant_id=?", [$conv['channel_id'], $tenantId]);
                if ($ch) { $d = Meta::send($ch, $conv['external_id'], $text); if (!$d['ok']) $meta['delivery_error'] = $d['error']; }
            }
            Chat::addMessage($tenantId, (int)$conv['id'], 'out', 'human', $text, $meta);
        }
        Audit::log($approve ? 'ai_action_approved' : 'ai_action_rejected', 'pending:' . $id, $a['summary'], $tenantId, (int)$user['id']);
        return ['ok' => $approve ? $ok : true, 'message' => $approve ? ($ok ? 'Approved and completed. The customer was notified.' : 'Approved, but the action failed. See the conversation.') : 'Rejected. The customer was notified.'];
    }

    public static function pendingCount(int $tenantId): int {
        return (int)Db::val("SELECT COUNT(*) FROM pending_actions WHERE tenant_id=? AND status='pending'", [$tenantId]);
    }
}
