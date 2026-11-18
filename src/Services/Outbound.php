<?php
declare(strict_types=1);
namespace CaliChat\Services;

use CaliChat\Core\Db;

/** Send a free-text message from the business to a customer's phone on their WhatsApp channel, within WhatsApp's 24-hour window, and record it in the inbox. */
class Outbound {
    /** @return array{ok:bool,error:?string} */
    public static function toPhone(int $tid, string $phone, string $text, array $meta = []): array {
        $phone = Store::digits($phone);
        $contact = Store::contactByPhone($tid, $phone);
        if (!$contact) return ['ok' => false, 'error' => 'This customer has not messaged you on WhatsApp yet, so there is no chat to reply in.'];
        $last = (string)Db::val("SELECT MAX(last_customer_at) FROM conversations WHERE tenant_id=? AND contact_id=?", [$tid, $contact['id']]);
        if ($last === '' || time() - strtotime($last . ' UTC') >= 86400) return ['ok' => false, 'error' => 'The customer last wrote more than 24 hours ago. WhatsApp only allows an approved template after that (see Follow-ups).'];
        $ch = Db::one("SELECT * FROM channels WHERE id=? AND tenant_id=? AND status='active'", [$contact['channel_id'], $tid]);
        if (!$ch) return ['ok' => false, 'error' => 'The channel for this customer is not active.'];
        $d = Meta::send($ch, (string)($contact['phone'] ?: $contact['external_id']), $text);
        if (!$d['ok']) return ['ok' => false, 'error' => (string)$d['error']];
        $lock = 'conv:' . $tid . ':' . $contact['id'];
        if (Db::lock($lock, 3)) {
            try { $conv = Chat::conversation($tid, $ch, $ch['type'], $contact, $ch['agent_id'] ? (int)$ch['agent_id'] : null); Chat::addMessage($tid, (int)$conv['id'], 'out', 'human', $text, $meta); }
            finally { Db::unlock($lock); }
        }
        return ['ok' => true, 'error' => null];
    }
}
