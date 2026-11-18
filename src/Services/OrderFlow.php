<?php
declare(strict_types=1);
namespace CaliChat\Services;

use CaliChat\Core\Db;
use CaliChat\Core\Log;

/**
 * The order "form" a customer sees in chat: a server-built summary (items, delivery fee, total, address, payment) with Confirm / Cancel buttons,
 * and the deterministic handling of those taps. The numbers always come from Store::check, never from the model's wording.
 */
class OrderFlow {
    public const TOOLS = ['create_order', 'update_order'];

    public static function lang(string $text): string { return preg_match('/\p{Sinhala}/u', $text) ? 'si' : (preg_match('/\p{Tamil}/u', $text) ? 'ta' : 'en'); }

    private const T = [
        'en' => ['title' => 'Order summary', 'change' => 'Order %s: changes', 'subtotal' => 'Subtotal', 'delivery' => 'Delivery', 'free' => 'FREE', 'total' => 'Total', 'pay' => 'Payment', 'to' => 'Deliver to', 'eta' => 'Delivery time', 'ask' => 'Tap *Confirm order* to place it, or *Cancel order* to discard it.', 'ask_text' => 'Reply *Confirm* to place this order, or *No* to discard it.',
            'confirm' => 'Confirm order', 'cancel' => 'Cancel order', 'placed' => 'Order %s placed', 'updated' => 'Order %s updated', 'thanks' => 'Thank you for shopping with %s!', 'invoice' => 'Invoice', 'discarded' => 'No problem, I have discarded this order and nothing was placed. Tell me what you would like to change, or browse the catalogue.', 'approval' => 'Your order request was submitted and a team member will confirm it shortly.', 'sorry' => 'Sorry, the order could not be placed: %s. Tell me what to change and I will prepare it again.'],
        'si' => ['title' => 'ඇණවුම් සාරාංශය', 'change' => 'ඇණවුම %s: වෙනස්කම්', 'subtotal' => 'උප එකතුව', 'delivery' => 'බෙදාහැරීම', 'free' => 'නොමිලේ', 'total' => 'මුළු මුදල', 'pay' => 'ගෙවීම', 'to' => 'බෙදාහරින ලිපිනය', 'eta' => 'බෙදාහැරීමේ කාලය', 'ask' => 'මෙම ඇණවුම තැබීමට *තහවුරු කරන්න* ඔබන්න, අවලංගු කිරීමට *අවලංගු* ඔබන්න.', 'ask_text' => 'මෙම ඇණවුම තැබීමට *තහවුරු* ලෙස පිළිතුරු දෙන්න, අවලංගු කිරීමට *අවලංගු*.',
            'confirm' => 'තහවුරු කරන්න', 'cancel' => 'අවලංගු කරන්න', 'placed' => 'ඇණවුම %s තබා ඇත', 'updated' => 'ඇණවුම %s යාවත්කාලීන කර ඇත', 'thanks' => '%s සමඟ සාප්පු සවාරි කිරීම ගැන ස්තූතියි!', 'invoice' => 'ඉන්වොයිසිය', 'discarded' => 'කමක් නැත, මෙම ඇණවුම අවලංගු කළා, කිසිවක් තබා නැත. වෙනස් කිරීමට අවශ්‍ය දේ කියන්න.', 'approval' => 'ඔබේ ඇණවුම් ඉල්ලීම ඉදිරිපත් කළා, කණ්ඩායමේ සාමාජිකයෙකු ඉක්මනින් තහවුරු කරයි.', 'sorry' => 'සමාවන්න, ඇණවුම තැබිය නොහැකි විය: %s. වෙනස් කළ යුතු දේ කියන්න.'],
        'ta' => ['title' => 'ஆர்டர் சுருக்கம்', 'change' => 'ஆர்டர் %s: மாற்றங்கள்', 'subtotal' => 'துணைத் தொகை', 'delivery' => 'டெலிவரி', 'free' => 'இலவசம்', 'total' => 'மொத்தம்', 'pay' => 'கட்டணம்', 'to' => 'டெலிவரி முகவரி', 'eta' => 'டெலிவரி நேரம்', 'ask' => 'ஆர்டரை பதிவு செய்ய *உறுதிப்படுத்து* அழுத்தவும், நிராகரிக்க *ரத்து* அழுத்தவும்.', 'ask_text' => 'ஆர்டரை பதிவு செய்ய *உறுதி* என பதிலளிக்கவும், நிராகரிக்க *ரத்து*.',
            'confirm' => 'உறுதிப்படுத்து', 'cancel' => 'ரத்து செய்', 'placed' => 'ஆர்டர் %s பதிவு செய்யப்பட்டது', 'updated' => 'ஆர்டர் %s புதுப்பிக்கப்பட்டது', 'thanks' => '%s இல் வாங்கியதற்கு நன்றி!', 'invoice' => 'விலைப்பட்டியல்', 'discarded' => 'பரவாயில்லை, இந்த ஆர்டரை நிராகரித்தேன், எதுவும் பதிவாகவில்லை. என்ன மாற்ற வேண்டும் என்று சொல்லுங்கள்.', 'approval' => 'உங்கள் ஆர்டர் கோரிக்கை சமர்ப்பிக்கப்பட்டது, குழு உறுப்பினர் விரைவில் உறுதிப்படுத்துவார்.', 'sorry' => 'மன்னிக்கவும், ஆர்டரை பதிவு செய்ய முடியவில்லை: %s. என்ன மாற்ற வேண்டும் என்று சொல்லுங்கள்.'],
    ];
    public static function t(string $lang, string $k, string ...$a): string { $s = self::T[$lang][$k] ?? self::T['en'][$k]; return $a ? vsprintf($s, $a) : $s; }
    private static function money(float $v): string { return 'Rs. ' . number_format($v); }

    /** Tap-button labels for the summary message. */
    public static function buttons(string $lang): array { return [['id' => 'order_confirm', 'title' => self::t($lang, 'confirm')], ['id' => 'order_cancel', 'title' => self::t($lang, 'cancel')]]; }

    /**
     * The order summary for a create_order / update_order preview. Null when the details are not complete (the model's own text is used then).
     * @return array{text:string,total:float,lang:string}|null
     */
    public static function summary(int $tid, string $tool, array $args, array $contact, string $userText): ?array {
        try {
            $lang = self::lang($userText); $phone = Store::digits((string)($contact['phone'] ?? $contact['external_id'] ?? ''));
            $existing = null;
            if ($tool === 'update_order') { $existing = Store::order($tid, (string)($args['order_no'] ?? '')); if (!$existing || $existing['phone'] !== $phone) return null; }
            $in = array_intersect_key($args, array_flip(['items', 'delivery_city', 'delivery_address', 'payment_method', 'customer_name']));
            if ($tool === 'create_order' && trim((string)($in['customer_name'] ?? '')) === '' && ($pf = Store::profile($tid, $phone)) && !empty($pf['name'])) $in['customer_name'] = $pf['name'];
            $c = Store::check($tid, $in, $existing);
            if (!$c['ready']) return null;
            $lines = $c['lines'];
            if (!$lines && $existing) { // only the address or payment changed: show the order's current items
                foreach (Db::all("SELECT sku, name, size, color, qty, unit_price FROM store_order_items WHERE order_id=? ORDER BY id", [$existing['id']]) as $i) $lines[] = ['name' => $i['name'], 'size' => $i['size'], 'color' => $i['color'], 'qty' => (int)$i['qty'], 'line_total' => (float)$i['unit_price'] * (int)$i['qty']];
            }
            $b = Brand::get($tid); $t = [];
            $t[] = '*' . $b['name'] . '*';
            $t[] = $existing ? '🧾 *' . self::t($lang, 'change', (string)$existing['order_no']) . '*' : '🧾 *' . self::t($lang, 'title') . '*';
            foreach ($lines as $l) { $opt = trim(implode(', ', array_filter([$l['size'] ?? null, $l['color'] ?? null]))); $t[] = '• ' . (int)$l['qty'] . ' × ' . $l['name'] . ($opt !== '' ? ' (' . $opt . ')' : '') . ' — ' . self::money((float)$l['line_total']); }
            $city = trim((string)($in['delivery_city'] ?? ($existing['delivery_city'] ?? ''))); $addr = trim((string)($in['delivery_address'] ?? ($existing['delivery_address'] ?? '')));
            $name = trim((string)($in['customer_name'] ?? ($existing['customer_name'] ?? ''))); $pay = trim((string)($in['payment_method'] ?? ($existing['payment_method'] ?? '')));
            $t[] = '';
            $t[] = self::t($lang, 'subtotal') . ': ' . self::money((float)$c['subtotal']);
            $t[] = self::t($lang, 'delivery') . ($city !== '' ? ' (' . $city . ')' : '') . ': ' . ((float)$c['delivery_fee'] > 0 ? self::money((float)$c['delivery_fee']) : self::t($lang, 'free'));
            $t[] = '*' . self::t($lang, 'total') . ': ' . self::money((float)$c['total']) . '*';
            $t[] = '';
            if ($pay !== '') $t[] = self::t($lang, 'pay') . ': ' . $pay;
            $to = trim(($name !== '' ? $name . ', ' : '') . $addr . ($city !== '' && mb_stripos($addr, $city) === false ? ', ' . $city : '')); if ($to !== '') $t[] = self::t($lang, 'to') . ': ' . $to;
            if (!empty($c['eta'])) $t[] = self::t($lang, 'eta') . ': ' . $c['eta'];
            return ['text' => implode("\n", $t), 'total' => (float)$c['total'], 'lang' => $lang];
        } catch (\Throwable $e) { Log::exception($e, 'order_summary_failed'); return null; }
    }

    // ------------------------------------------------------------------ taps
    /**
     * Handle a tap on the summary's Confirm / Cancel button without asking the model. Returns the Chat::respond-shaped result, or null to fall
     * through to the normal AI path (no open summary, human in charge, billing hold...).
     */
    public static function handleTap(array $tenant, ?array $channel, array $contact, array $conv, string $replyId, callable $deliver): ?array {
        $tid = (int)$tenant['id']; $convId = (int)$conv['id'];
        $conv = Db::one("SELECT * FROM conversations WHERE id=? AND tenant_id=?", [$convId, $tid]) ?: $conv;
        if ($conv['status'] !== 'ai' || Billing::blocked($tenant)) return null;
        $pv = Db::one("SELECT * FROM action_previews WHERE conversation_id=? AND tenant_id=? AND used_at IS NULL AND tool_name IN ('create_order','update_order') AND args IS NOT NULL AND created_at > NOW() - INTERVAL 30 MINUTE ORDER BY id DESC LIMIT 1", [$convId, $tid]);
        if (!$pv) return null;
        $lastIn = (string)Db::val("SELECT content FROM messages WHERE conversation_id=? AND tenant_id=? AND sender='customer' ORDER BY id DESC LIMIT 1", [$convId, $tid]);
        $lang = self::lang($lastIn);
        $res = ['reply' => null, 'handoff' => null, 'tools' => [], 'error' => null, 'skipped' => null, 'retry' => false, 'retry_after' => 0, 'conversation_id' => $convId];
        $say = function (string $text, array $meta) use ($deliver, $tid, $convId, &$res) {
            $d = $deliver($text); if (!$d['ok']) $meta['delivery_error'] = $d['error'] ?? 'send failed';
            Chat::addMessage($tid, $convId, 'out', 'ai', $text, $meta); $res['reply'] = $text;
        };
        if ($replyId === 'order_cancel') {
            Db::update('action_previews', ['used_at' => now()], 'tenant_id=? AND conversation_id=? AND used_at IS NULL', [$tid, $convId]);
            $say(self::t($lang, 'discarded'), ['order_tap' => 'cancel']);
            return $res;
        }
        $tool = Db::one("SELECT * FROM tools WHERE id=? AND tenant_id=? AND is_active=1", [$pv['tool_id'], $tid]);
        if (!$tool) return null;
        $args = json_decode((string)$pv['args'], true); if (!is_array($args)) return null;
        $r = ToolRunner::run($tool, $args + ['customer_confirmed' => true], $contact, $convId, ['deadline' => microtime(true) + 25]);
        $res['tools'] = [['name' => $tool['name'], 'ok' => $r['ok'], 'ms' => $r['ms']]];
        $notes = $tool['name'] . '(' . json_encode($args, JSON_UNESCAPED_UNICODE) . ') -> ' . mb_substr((string)json_encode($r['data'], JSON_UNESCAPED_UNICODE), 0, 350) . "\n";
        $meta = ['order_tap' => 'confirm', 'tools' => [$tool['name']], 'tool_notes' => $notes];
        $b = Brand::get($tid);
        if ($r['ok'] && ($r['gated'] ?? '') === 'pending_team_approval') { $say(self::t($lang, 'approval'), $meta); return $res; }
        $d = is_array($r['data']) ? ($r['data']['data'] ?? []) : [];
        if ($r['ok'] && !empty($d['order_no'])) {
            $say(self::confirmationText($tid, $lang, (string)$d['order_no'], $tool['name'] === 'update_order', $b['name']), $meta);
            return $res;
        }
        $why = trim((string)preg_replace('/^HTTP \d+:?\s*/', '', (string)$r['error'])); if ($why === '') $why = 'a system error';
        $say(self::t($lang, 'sorry', $why), $meta + ['error' => $why]); $res['error'] = $why;
        if (!$r['user_error']) { $res['handoff'] = 'Order confirmation failed: ' . mb_substr($why, 0, 160); Chat::handoff($tenant, $conv, $res['handoff'], $contact); }
        return $res;
    }

    private static function confirmationText(int $tid, string $lang, string $orderNo, bool $updated, string $brand): string {
        $o = Store::order($tid, $orderNo); if (!$o) return self::t($lang, $updated ? 'updated' : 'placed', $orderNo);
        $t = ['✅ *' . self::t($lang, $updated ? 'updated' : 'placed', $orderNo) . '*'];
        foreach (Db::all("SELECT name, size, color, qty FROM store_order_items WHERE order_id=? ORDER BY id", [$o['id']]) as $i) { $opt = trim(implode(', ', array_filter([$i['size'], $i['color']]))); $t[] = '• ' . (int)$i['qty'] . ' × ' . $i['name'] . ($opt !== '' ? ' (' . $opt . ')' : ''); }
        $t[] = self::t($lang, 'total') . ': ' . self::money((float)$o['total']) . ((float)$o['delivery_fee'] > 0 ? ' (' . self::t($lang, 'delivery') . ' ' . self::money((float)$o['delivery_fee']) . ')' : ' (' . self::t($lang, 'delivery') . ': ' . self::t($lang, 'free') . ')');
        $t[] = self::t($lang, 'pay') . ': ' . $o['payment_method'];
        if (!empty($o['eta'])) $t[] = self::t($lang, 'eta') . ': ' . $o['eta'];
        $t[] = self::t($lang, 'invoice') . ': ' . Store::invoiceUrl($tid, $orderNo);
        $t[] = ''; $t[] = self::t($lang, 'thanks', $brand);
        return implode("\n", $t);
    }

    /** An order placed on the catalogue page: confirm it in the customer's chat and leave a note the AI can see. */
    public static function tellCustomer(int $tid, array $o): void {
        $ct = Store::contactByPhone($tid, (string)$o['phone']); if (!$ct) return;
        $conv = Db::one("SELECT * FROM conversations WHERE tenant_id=? AND contact_id=? AND status<>'closed' ORDER BY id DESC LIMIT 1", [$tid, $ct['id']]); if (!$conv) return;
        $b = Brand::get($tid); $text = self::confirmationText($tid, 'en', (string)$o['order_no'], false, $b['name']);
        $ch = !empty($ct['channel_id']) ? Db::one("SELECT * FROM channels WHERE id=? AND tenant_id=?", [$ct['channel_id'], $tid]) : null;
        $meta = ['catalogue_order' => $o['order_no'], 'tool_notes' => 'catalogue order placed by the customer -> ' . $o['order_no'] . ' total Rs. ' . number_format((float)$o['total']) . "\n"];
        if ($ch) { $d = Meta::send($ch, (string)$ct['external_id'], $text); if (!$d['ok']) $meta['delivery_error'] = $d['error'] ?? 'send failed'; }
        Chat::addMessage($tid, (int)$conv['id'], 'out', 'ai', $text, $meta);
    }
}
