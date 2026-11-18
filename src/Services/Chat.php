<?php
declare(strict_types=1);
namespace CaliChat\Services;

use CaliChat\Core\Db;
use CaliChat\Core\Guard;
use CaliChat\Core\Log;
use CaliChat\Core\Tenant;
use CaliChat\Memory\Memory;

/** Conversation logic shared by the queue worker and the Playground. */
class Chat {
    private const STOP = ['stop', 'unsubscribe', 'optout', 'opt out', 'opt-out', 'cancel', 'නවත්වන්න', 'අයින් කරන්න', 'ඉවත් වන්න', 'நிறுத்து', 'நிறுத்துங்கள்', 'விலகு'];
    private const START = ['start', 'subscribe', 'opt in', 'optin', 'ආරම්භ', 'தொடங்கு'];

    public static function contact(int $tid, int $channelId, string $type, string $extId, ?string $name = null, ?string $phone = null): array {
        Db::q("INSERT INTO contacts (tenant_id, channel_id, channel_type, external_id, name, phone, last_seen) VALUES (?,?,?,?,?,?,NOW())
               ON DUPLICATE KEY UPDATE last_seen=NOW(), name=IF(name IS NULL OR name='', VALUES(name), name), phone=IF(phone IS NULL OR phone='', VALUES(phone), phone)",
            [$tid, $channelId, $type, $extId, $name ? mb_substr($name, 0, 150) : null, $phone ? mb_substr($phone, 0, 40) : null]);
        return Db::one("SELECT * FROM contacts WHERE tenant_id=? AND channel_type=? AND channel_id=? AND external_id=?", [$tid, $type, $channelId, $extId]);
    }

    /** Remember which ad brought this customer (WhatsApp click-to-chat ads send a referral with the first message). */
    public static function applyReferral(array $contact, string $json): array {
        $r = json_decode($json, true); if (!is_array($r)) return $contact;
        $detail = mb_substr(trim((string)($r['headline'] ?? '') ?: (string)($r['source_url'] ?? '') ?: (string)($r['source_id'] ?? '')), 0, 300);
        Db::update('contacts', ['acquisition_source' => (($r['source_type'] ?? '') === 'post' ? 'post' : 'ad') === 'ad' ? 'click_to_whatsapp' : 'social_post', 'acquisition_detail' => $detail ?: null], 'id=? AND acquisition_source IS NULL', [$contact['id']]);
        return Db::one("SELECT * FROM contacts WHERE id=?", [$contact['id']]) ?: $contact;
    }

    /** Must be called while holding the per-contact lock. */
    public static function conversation(int $tid, ?array $channel, string $type, array $contact, ?int $agentId, bool $isTest = false): array {
        $cid = $channel['id'] ?? null;
        $c = Db::one("SELECT * FROM conversations WHERE tenant_id=? AND contact_id=? AND channel_type=? AND (channel_id <=> ?) AND status<>'closed' ORDER BY id DESC LIMIT 1", [$tid, $contact['id'], $type, $cid]);
        if ($c) return $c;
        $id = Db::insert('conversations', ['tenant_id' => $tid, 'channel_id' => $cid, 'channel_type' => $type, 'contact_id' => $contact['id'], 'agent_id' => $agentId, 'status' => 'ai', 'is_test' => $isTest ? 1 : 0, 'last_message_at' => now()]);
        return Db::one("SELECT * FROM conversations WHERE id=?", [$id]);
    }

    public static function addMessage(int $tid, int $convId, string $dir, string $sender, string $text, array $meta = [], ?string $mediaType = null, ?string $providerId = null): int {
        $id = Db::insert('messages', ['tenant_id' => $tid, 'conversation_id' => $convId, 'direction' => $dir, 'sender' => $sender, 'content' => $text,
            'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null, 'media_type' => $mediaType, 'provider_id' => $providerId]);
        if ($sender === 'note') return $id; // internal notes don't touch the conversation clock
        Db::q("UPDATE conversations SET last_message_at=NOW(), last_preview=?, unread = unread + ?,
               last_customer_at = IF(?='customer', NOW(), last_customer_at), last_human_at = IF(?='human', NOW(), last_human_at) WHERE id=? AND tenant_id=?",
            [mb_substr($text, 0, 150), $sender === 'customer' ? 1 : 0, $sender, $sender, $convId, $tid]);
        if ($sender !== 'system' && !(int)Db::val("SELECT is_test FROM conversations WHERE id=?", [$convId])) Tenant::usage($tid, $dir === 'in' ? 'messages_in' : 'messages_out');
        return $id;
    }

    public static function agentFor(int $tid, ?int $agentId): ?array {
        if ($agentId) { $a = Db::one("SELECT * FROM agents WHERE id=? AND tenant_id=? AND is_active=1", [$agentId, $tid]); if ($a) return $a; }
        return Db::one("SELECT * FROM agents WHERE tenant_id=? AND is_active=1 ORDER BY id LIMIT 1", [$tid]);
    }

    /** Opt-out / opt-in keywords handled before any AI. @return string|null reply text when the message was a keyword */
    public static function consentKeyword(int $tid, array $contact, string $text): ?string {
        $t = mb_strtolower(trim(preg_replace('/[\p{P}\s]+/u', ' ', $text)));
        if (in_array($t, self::STOP, true)) {
            Db::update('contacts', ['consent_status' => 'opted_out', 'consent_source' => 'customer_keyword', 'consent_at' => now()], 'id=? AND tenant_id=?', [$contact['id'], $tid]);
            return 'You have been unsubscribed from promotional messages. You can still message us any time. Reply START to subscribe again.';
        }
        if (in_array($t, self::START, true)) {
            Db::update('contacts', ['consent_status' => 'opted_in', 'consent_source' => 'customer_keyword', 'consent_at' => now()], 'id=? AND tenant_id=?', [$contact['id'], $tid]);
            return 'Thanks! You are subscribed to updates and offers. Reply STOP at any time to unsubscribe.';
        }
        return null;
    }

    public static function handoff(array $tenant, array $conv, string $reason, ?array $contact = null): void {
        $tid = (int)$tenant['id'];
        Db::update('conversations', ['status' => 'human', 'handoff_reason' => mb_substr($reason, 0, 290)], 'id=? AND tenant_id=?', [$conv['id'], $tid]);
        Db::insert('handoffs', ['tenant_id' => $tid, 'conversation_id' => $conv['id'], 'reason' => mb_substr($reason, 0, 290)]);
        self::addMessage($tid, (int)$conv['id'], 'out', 'system', 'Handed off to human: ' . $reason);
        if (!$conv['is_test']) {
            Tenant::usage($tid, 'handoffs');
            $who = $contact ? (Agent::safeName($contact['name'] ?? '') ?: ($contact['phone'] ?: $contact['external_id'])) : 'a customer';
            Notifier::alert($tid, 'warn', 'handoff', "Needs a human: $who. $reason", 'handoff:' . $conv['id'], 1);
        }
    }

    /**
     * Everything after the inbound message has been stored.
     * @param array{final?:bool,is_test?:bool} $o
     * @return array{reply:?string,handoff:?string,tools:array,error:?string,skipped:?string,retry:bool,retry_after:int,conversation_id:int}
     */
    public static function respond(array $tenant, ?array $channel, array $contact, array $conv, string $text, ?callable $deliver, array $o = []): array {
        $tid = (int)$tenant['id']; $isTest = !empty($o['is_test']) || (int)$conv['is_test'] === 1; $final = $o['final'] ?? true;
        $res = ['reply' => null, 'handoff' => null, 'tools' => [], 'error' => null, 'skipped' => null, 'retry' => false, 'retry_after' => 0, 'conversation_id' => (int)$conv['id'], 'buttons' => []];
        $conv = Db::one("SELECT * FROM conversations WHERE id=?", [$conv['id']]) ?: $conv;

        if ($conv['status'] === 'human') { $res['skipped'] = 'human_takeover'; return $res; }
        $agent = self::agentFor($tid, $channel['agent_id'] ?? null);
        if (!$agent) {
            $res['skipped'] = 'no_agent';
            Notifier::alert($tid, 'crit', 'no_agent', 'A customer wrote in but there is no active AI agent. Create or activate an agent.', 'no_agent', 6);
            return $res;
        }
        if (!Guard::allow('in:t' . $tid, (int)env('INBOUND_PER_MIN', 600))) { $res['skipped'] = 'rate_limited'; Notifier::alert($tid, 'warn', 'flood', 'Unusually high inbound message volume; some messages were not answered.', 'flood', 1); return $res; }
        $recent = (int)Db::val("SELECT COUNT(*) FROM messages WHERE conversation_id=? AND sender='customer' AND created_at > (NOW() - INTERVAL 60 SECOND)", [$conv['id']]);
        if ($recent > 12) { $res['skipped'] = 'rate_limited'; return $res; }
        if (!$isTest && Billing::blocked($tenant)) return self::holdAndHandoff($tenant, $conv, $contact, $deliver, 'Subscription is not active', $res);
        if (!$isTest && Tenant::monthMessages($tid) >= (int)$tenant['monthly_message_limit']) return self::holdAndHandoff($tenant, $conv, $contact, $deliver, 'Monthly AI reply limit reached', $res);

        $r = Agent::reply($tenant, $agent, $contact + ['is_test' => $isTest], (int)$conv['id'], $text, ['is_test' => $isTest, 'allow_writes' => !empty($o['allow_writes'])]);
        $res['tools'] = $r['tools']; $res['error'] = $r['error']; $res['handoff'] = $r['handoff']; $res['rich'] = $r['rich'] ?? [];

        if ($r['error_kind'] === 'budget') return self::holdAndHandoff($tenant, $conv, $contact, $deliver, 'Monthly AI token budget reached', $res);
        if ($r['text'] === '' && $r['retryable']) {
            if (!$final) { $res['retry'] = true; $res['retry_after'] = 20; return $res; }
            $r['text'] = (string)($agent['fallback_message'] ?: 'Sorry, I could not process that just now. A team member will follow up shortly.');
            $r['handoff'] = 'AI unavailable after retries: ' . mb_substr((string)$r['error'], 0, 160);
            $res['handoff'] = $r['handoff'];
        }

        if ($r['text'] !== '') {
            $r['text'] = preg_replace('/\*\*(.+?)\*\*/s', '*$1*', $r['text']); // WhatsApp bold is *x*, not **x**
            $rich = [];
            if (!empty($r['order_summary'])) { // the order form: server-built summary with the delivery fee, plus Confirm / Cancel
                $os = $r['order_summary']; $r['text'] = $os['text'] . "\n\n" . OrderFlow::t($os['lang'], 'ask');
                $rich = $channel ? Interactive::forReply($tenant, $channel, $r, $r['text']) : [];
                if (!$channel && $isTest) $res['buttons'] = OrderFlow::buttons($os['lang']); // the Playground draws the same Confirm / Cancel buttons WhatsApp would
                elseif (!$rich) $r['text'] = $os['text'] . "\n\n" . OrderFlow::t($os['lang'], 'ask_text'); // channels without buttons: the customer replies with a word
            } else $rich = Interactive::forReply($tenant, $channel, $r, $r['text']);
            $meta = ['tools' => array_column($r['tools'], 'name'), 'tool_notes' => $r['tool_notes']];
            if (!empty($r['order_summary'])) $meta['order_summary'] = true;
            if ($deliver) {
                $d = $deliver($r['text'], $rich);
                if (!$d['ok']) { $meta['delivery_error'] = $d['error'] ?? 'send failed'; $res['error'] = $res['error'] ?: $meta['delivery_error']; }
                elseif (!empty($d['voice'])) $meta['voice'] = true; // sent as a voice note; the text is kept for the inbox
            }
            self::addMessage($tid, (int)$conv['id'], 'out', 'ai', $r['text'], $meta);
            if (!$isTest) Tenant::usage($tid, 'ai_replies');
            $res['reply'] = $r['text'];
            if (!$r['error'] && !empty($conv['handback_note'])) Db::update('conversations', ['handback_note' => null], 'id=? AND tenant_id=?', [$conv['id'], $tid]); // the AI has used the team's note once
            if (!$r['error']) {
                $tidy = function () use ($tenant, $contact, $isTest, $conv, $text, $r) { // remembering the customer and summarising long chats each need another AI call
                    try { Memory::for($tenant)->capture($tenant, $contact + ['is_test' => $isTest], (int)$conv['id'], $text, $r['text']); } catch (\Throwable $e) { Log::exception($e, 'memory_capture_failed'); }
                    Agent::summarizeIfNeeded($tenant, (int)$conv['id'], $isTest);
                };
                if (!$deliver && PHP_SAPI !== 'cli') after_response($tidy); // Playground: the page gets the reply first
                else $tidy();                                                // worker: the customer already has the reply
            }
        }
        if ($res['handoff']) self::handoff($tenant, $conv, $res['handoff'], $contact);
        return $res;
    }

    private static function holdAndHandoff(array $tenant, array $conv, array $contact, ?callable $deliver, string $reason, array $res): array {
        $tid = (int)$tenant['id'];
        $msg = (string)($tenant['cfg']['quota_message'] ?? 'Thanks for your message! Our team will get back to you shortly.');
        if ($deliver) { $d = $deliver($msg); $meta = ['holding' => true] + ($d['ok'] ? [] : ['delivery_error' => $d['error'] ?? 'send failed']); } else $meta = ['holding' => true];
        self::addMessage($tid, (int)$conv['id'], 'out', 'ai', $msg, $meta);
        self::handoff($tenant, $conv, $reason, $contact);
        Notifier::alert($tid, 'crit', 'quota', "$reason. New customer chats are being handed to your team until the limit resets or is raised.", 'quota', 24);
        $res['skipped'] = 'quota'; $res['reply'] = $msg; $res['handoff'] = $reason;
        return $res;
    }

    /** Dashboard "Playground": a simulated customer, never counts toward quotas or dashboards, never runs write tools. */
    public static function playground(array $tenant, array $user, string $text, string $phone, bool $allowWrites = false, ?string $replyId = null): array {
        $tid = (int)$tenant['id'];
        $contact = self::contact($tid, 0, 'playground', 'pg-' . $user['id'] . '-' . $phone, 'Playground tester', $phone);
        $lock = 'conv:' . $tid . ':' . $contact['id'];
        if (!Db::lock($lock, 5)) return ['reply' => null, 'handoff' => null, 'tools' => [], 'error' => 'Still working on your previous message', 'skipped' => 'busy', 'retry' => false, 'retry_after' => 0, 'conversation_id' => 0];
        try {
            $agent = self::agentFor($tid, null);
            $conv = self::conversation($tid, null, 'playground', $contact, $agent['id'] ?? null, true);
            if ($conv['status'] === 'human') { // nobody staffs a test chat: an earlier hand-off (for example after a rate-limited AI reply) must not silence the AI for good
                Db::update('conversations', ['status' => 'ai', 'handoff_reason' => null], 'id=? AND tenant_id=?', [$conv['id'], $tid]);
                Db::q("UPDATE handoffs SET resolved_at=COALESCE(resolved_at, NOW()), resolved_by=COALESCE(resolved_by,'playground') WHERE conversation_id=? AND tenant_id=? AND resolved_at IS NULL", [$conv['id'], $tid]);
                $conv['status'] = 'ai';
            }
            self::addMessage($tid, (int)$conv['id'], 'in', 'customer', $text);
            if (in_array($replyId, ['order_confirm', 'order_cancel'], true)) { // a tap on the order form's buttons, handled exactly as on WhatsApp (not by the model)
                $res = ['reply' => null, 'handoff' => null, 'tools' => [], 'error' => null, 'skipped' => null, 'retry' => false, 'retry_after' => 0, 'conversation_id' => (int)$conv['id'], 'buttons' => [], 'rich' => []];
                if ($replyId === 'order_confirm' && !$allowWrites && Db::val("SELECT id FROM action_previews WHERE conversation_id=? AND tenant_id=? AND used_at IS NULL AND tool_name IN ('create_order','update_order') AND created_at > NOW() - INTERVAL 30 MINUTE LIMIT 1", [$conv['id'], $tid])) {
                    $msg = 'Write actions are switched off in the Playground, so nothing was placed. Tick "Run write actions" and tap Confirm order again.';
                    self::addMessage($tid, (int)$conv['id'], 'out', 'ai', $msg, ['order_tap' => 'blocked']); $res['reply'] = $msg; return $res;
                }
                $t = OrderFlow::handleTap($tenant, null, $contact, $conv, $replyId, fn($x, $rich = []) => ['ok' => true]);
                if ($t) return $t + ['buttons' => [], 'rich' => []];
            }
            return self::respond($tenant, null, $contact, $conv, $text, null, ['final' => true, 'is_test' => true, 'allow_writes' => $allowWrites]);
        } finally { Db::unlock($lock); }
    }
}
