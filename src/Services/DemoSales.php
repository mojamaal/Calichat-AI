<?php
declare(strict_types=1);
namespace CaliChat\Services;

use CaliChat\Core\Db;
use CaliChat\Core\Tenant;

/**
 * Realistic demo history for one business: customers, chats, orders, revenue, leads, hand-offs and daily usage over the last weeks, so the
 * Dashboard, Orders, Leads, Inbox and Reports pages have something to show. Everything it adds is tagged ("demo data") and clear() removes only that.
 * It never touches stock and never sends anything.
 */
class DemoSales {
    public const TAG = 'demo data';

    private const CUSTOMERS = ['Kasun Perera', 'Nimali Fernando', 'Sanduni Jayawardena', 'Ruwan Silva', 'Dilani Rathnayake', 'Tharindu Bandara', 'Ishara Wickramasinghe', 'Chamari Gunasekara', 'Nuwan Dissanayake', 'Piumi Senanayake',
        'Arjun Selvarajah', 'Thushari Kumar', 'Mohamed Rizwan', 'Fathima Nazeer', 'Sajith Premadasa', 'Hiruni Abeysekara', 'Lakmal Herath', 'Kavindu Madushan', 'Anushka Weerasinghe', 'Dinesh Karunaratne', 'Priya Sivakumar',
        'Mohamed Faris', 'Yasodha Amarasinghe', 'Chathura Ekanayake', 'Sachini Ranasinghe', 'Roshan De Silva', 'Malsha Gamage', 'Vimal Rajapaksha', 'Harini Thevarajah', 'Imran Cassim', 'Gayathri Pillai', 'Oshadi Liyanage',
        'Thilina Samarasinghe', 'Nethmi Pathirana', 'Janaka Wijesinghe', 'Shanika Jayasuriya'];
    private const STREETS = ['Galle Road', 'Temple Road', 'Lake Drive', 'Hill Street', 'Station Road', 'Main Street', 'Church Lane', 'Park Avenue', 'Mill Road', 'Flower Road'];
    private const CITIES = ['Colombo' => 38, 'Gampaha' => 16, 'Kandy' => 20, 'Galle' => 14, 'Jaffna' => 6];
    private const COURIERS = [['Domex', 'DX'], ['Pronto', 'PR'], ['Koombiyo', 'KB']];
    private const LEADS = [
        ['Bulk order: 40 polo shirts with our logo for office uniforms', 148000], ['Wedding party outfits: 8 sarongs and 6 dresses', 76500], ['Corporate gift: 25 leather belts in gift boxes', 60000], ['Restock alert: Canvas Sneakers size 43', 7500],
        ['School cricket team caps x 60', 90000], ['Wholesale pricing for cotton scarves', 54000], ['Custom printed t-shirts for a charity event (120 pcs)', 222000], ['Boutique reseller enquiry: monthly supply of tote bags', 118000],
        ['Staff uniform: 30 T-shirts and 30 caps', 105000], ['Birthday gift set: watch and belt', 15300], ['Hotel gift shop: 50 handloom sarongs', 160000], ['Sports club jerseys: 45 t-shirts', 83250], ['Retirement gift: analog watch engraved', 12900],
        ['Bridal shower: 12 summer dresses in matching colour', 82800], ['University society hoodies and caps x 80', 196000], ['Rubber slippers for a resort (100 pairs)', 125000], ['Export sample: 6 handloom sarongs to Australia', 19200],
        ['Pop-up stall partnership: 200 mixed accessories', 240000], ['Team building event tote bags x 70', 206500], ['Gift hampers for staff: scarf and belt x 35', 142000], ['Photo shoot styling: 20 outfits on loan', 30000], ['Back-to-school: 25 pairs of sneakers', 187500],
        ['Corporate event: 60 polo shirts in two colours', 159000], ['Monthly delivery of tote bags for a book shop', 88500]];

    private static function pick(array $a) { return $a[mt_rand(0, count($a) - 1)]; }
    private static function weighted(array $w): string { $t = array_sum($w); $r = mt_rand(1, (int)$t); foreach ($w as $k => $v) { $r -= $v; if ($r <= 0) return (string)$k; } return (string)array_key_first($w); }
    private static function utc(int $ts): string { return gmdate('Y-m-d H:i:s', $ts); }
    private static function rs(float $v): string { return 'Rs. ' . number_format($v); }

    /** @return array<string,int> what was added */
    public static function seed(int $tid, int $days = 60, int $seed = 11): array {
        if (self::exists($tid)) throw new \RuntimeException('Demo data is already there. Remove it first to create a fresh set.');
        mt_srand($seed);
        $products = Db::all("SELECT * FROM store_products WHERE tenant_id=? AND is_active=1 ORDER BY id", [$tid]);
        if (count($products) < 3) throw new \RuntimeException('Add products to the Store first (the sample products are fine); demo orders need something to sell.');
        $tenant = Tenant::get($tid); $tz = new \DateTimeZone((string)$tenant['timezone']); $s = Store::settings($tid); $pays = $s['payments'];
        $owner = Db::one("SELECT id, name FROM users WHERE tenant_id=? AND role='owner' AND status='active' ORDER BY id LIMIT 1", [$tid]) ?: ['id' => null, 'name' => 'Team'];
        $agentId = (int)Db::val("SELECT id FROM agents WHERE tenant_id=? AND is_active=1 ORDER BY id LIMIT 1", [$tid]) ?: null; $now = time(); $count = ['customers' => 0, 'orders' => 0, 'leads' => 0, 'conversations' => 0, 'messages' => 0];

        // ---- customers
        $customers = [];
        foreach (self::CUSTOMERS as $i => $name) {
            $phone = '9477' . str_pad((string)(1000000 + ($i * 7919 + 123457) % 8999999), 7, '0', STR_PAD_LEFT); $city = (string)self::weighted(self::CITIES);
            $addr = 'No. ' . mt_rand(3, 180) . ', ' . self::pick(self::STREETS) . ($city === 'Colombo' ? ', Colombo ' . str_pad((string)mt_rand(1, 15), 2, '0', STR_PAD_LEFT) : '') ;
            $c = Chat::contact($tid, 0, 'whatsapp', $phone, $name, $phone);
            Db::update('contacts', ['tags' => self::TAG, 'consent_status' => mt_rand(1, 100) <= 62 ? 'opted_in' : 'unknown', 'created_at' => self::utc($now - mt_rand(2, $days) * 86400), 'last_seen' => self::utc($now - mt_rand(0, 20) * 86400)], 'id=?', [$c['id']]);
            $customers[] = ['id' => (int)$c['id'], 'name' => $name, 'first' => explode(' ', $name)[0], 'phone' => $phone, 'city' => $city, 'address' => $addr, 'pay' => (string)self::weighted(['Cash on delivery' => 60, 'Bank transfer' => 25, 'Card' => 15]), 'orders' => 0]; $count['customers']++;
        }

        // ---- orders: more in recent weeks, a bump at weekends
        $plan = [];
        for ($i = 0; $i < 74; $i++) {
            do { $ageD = (int)floor(pow(mt_rand(0, 1000) / 1000, 1.35) * $days); $dow = (int)gmdate('N', $now - $ageD * 86400); } while ($dow < 6 ? false : mt_rand(1, 100) > 78);
            $ts = $now - $ageD * 86400 - mt_rand(0, 11 * 3600) - ($ageD === 0 ? mt_rand(1800, 6 * 3600) : 0); $plan[] = ['ts' => min($ts, $now - 600), 'age' => $ageD];
        }
        usort($plan, fn($a, $b) => $a['ts'] <=> $b['ts']);
        $next = max(1001, (int)Db::val("SELECT COALESCE(MAX(CAST(SUBSTRING(order_no, 5) AS UNSIGNED)), 1000) + 1 FROM store_orders WHERE tenant_id=? AND order_no REGEXP '^ORD-[0-9]+$'", [$tid]));
        $orders = [];
        foreach ($plan as $o) {
            $ci = mt_rand(1, 100) <= 38 ? mt_rand(0, 11) : mt_rand(0, count($customers) - 1); $cu = &$customers[$ci];
            $lines = []; $sub = 0.0; $n = (int)self::weighted([1 => 52, 2 => 33, 3 => 15]); $used = [];
            for ($k = 0; $k < $n; $k++) {
                $p = $products[min(count($products) - 1, (int)floor(pow(mt_rand(0, 999) / 1000, 1.5) * count($products)))]; if (isset($used[$p['sku']])) continue; $used[$p['sku']] = 1;
                $sizes = $p['variants'] ? array_map('trim', explode(',', $p['variants'])) : []; $cols = $p['colors'] ? array_map('trim', explode(',', $p['colors'])) : []; $qty = (int)self::weighted([1 => 70, 2 => 25, 3 => 5]);
                $lines[] = ['sku' => $p['sku'], 'name' => $p['name'], 'size' => $sizes ? self::pick($sizes) : null, 'color' => $cols ? self::pick($cols) : null, 'qty' => $qty, 'price' => (float)$p['price']]; $sub += $qty * (float)$p['price'];
            }
            $d = Store::delivery($tid, $cu['city'], $sub); $age = $o['age'];
            $status = $age > 10 ? self::weighted(['delivered' => 80, 'returned' => 4, 'cancelled' => 8, 'shipped' => 8]) : ($age > 4 ? self::weighted(['delivered' => 45, 'shipped' => 35, 'packed' => 10, 'cancelled' => 10]) : ($age >= 1 ? self::weighted(['shipped' => 25, 'packed' => 35, 'processing' => 35, 'cancelled' => 5]) : self::weighted(['processing' => 70, 'packed' => 30])));
            $pay = in_array($cu['pay'], $pays, true) ? $cu['pay'] : $pays[0]; if (mt_rand(1, 100) <= 18) $pay = self::pick($pays);
            $pstat = in_array($status, ['delivered', 'shipped'], true) ? 'paid' : ($status === 'returned' ? 'refunded' : ($status === 'cancelled' ? ($pay === 'Cash on delivery' ? 'unpaid' : 'refunded') : ($pay === 'Cash on delivery' ? 'unpaid' : self::weighted(['paid' => 55, 'review' => 25, 'unpaid' => 20]))));
            if ($status === 'shipped' && $pay === 'Cash on delivery') $pstat = 'unpaid';
            $src = self::weighted(['ai' => 55, 'catalogue' => 15, 'manual' => 30]);
            $orders[] = ['no' => 'ORD-' . $next++, 'ts' => $o['ts'], 'cu' => &$customers[$ci], 'lines' => $lines, 'sub' => $sub, 'fee' => (float)$d['fee'], 'eta' => $d['eta'] ?: '2-3 working days', 'status' => $status, 'pay' => $pay, 'pstat' => $pstat, 'src' => $src, 'age' => $age];
            unset($cu);
        }
        foreach ($orders as &$o) {
            $cu = &$o['cu']; $cu['orders']++; $c = self::pick(self::COURIERS); $ship = in_array($o['status'], ['shipped', 'delivered', 'returned'], true);
            $shippedAt = $ship ? $o['ts'] + mt_rand(20, 40) * 3600 : null; $deliveredAt = in_array($o['status'], ['delivered', 'returned'], true) ? $shippedAt + mt_rand(30, 70) * 3600 : null;
            $id = Db::insert('store_orders', ['tenant_id' => $tid, 'order_no' => $o['no'], 'phone' => $cu['phone'], 'customer_name' => $cu['name'], 'status' => $o['status'], 'payment_method' => $o['pay'], 'payment_status' => $o['pstat'], 'delivery_city' => $cu['city'], 'delivery_address' => $cu['address'],
                'subtotal' => $o['sub'], 'delivery_fee' => $o['fee'], 'total' => $o['sub'] + $o['fee'], 'courier' => $ship ? $c[0] : null, 'tracking' => $ship ? $c[1] . mt_rand(100000, 999999) : null, 'eta' => $o['eta'], 'notes' => self::TAG, 'source' => $o['src'],
                'created_at' => self::utc($o['ts']), 'updated_at' => self::utc($deliveredAt ?: ($shippedAt ?: $o['ts'] + 3600)), 'shipped_at' => $shippedAt ? self::utc($shippedAt) : null, 'delivered_at' => $deliveredAt && $o['status'] === 'delivered' ? self::utc($deliveredAt) : null]);
            foreach ($o['lines'] as $l) Db::insert('store_order_items', ['tenant_id' => $tid, 'order_id' => $id, 'sku' => $l['sku'], 'name' => $l['name'], 'size' => $l['size'], 'color' => $l['color'], 'qty' => $l['qty'], 'unit_price' => $l['price']]);
            $o['id'] = $id; $count['orders']++; unset($cu);
        }
        unset($o);
        foreach ($customers as $cu) if ($cu['orders']) Db::q("INSERT INTO customer_profiles (tenant_id, phone, name, address, city, payment_method, orders_count, last_order_at) VALUES (?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE orders_count=VALUES(orders_count)", [$tid, $cu['phone'], $cu['name'], $cu['address'], $cu['city'], $cu['pay'], $cu['orders']]);

        // ---- chats behind the orders (AI and catalogue orders), a few team-placed ones, browsing chats and hand-offs
        $log = [];
        $chat = function (array $cu, array $msgs, string $status, array $o = []) use ($tid, $agentId, &$count, &$log, $owner) {
            $t0 = (int)$msgs[0][2]; $last = end($msgs); $cid = Db::insert('conversations', ['tenant_id' => $tid, 'channel_id' => null, 'channel_type' => 'whatsapp', 'contact_id' => $cu['id'], 'agent_id' => $agentId, 'status' => $status, 'handoff_reason' => $o['handoff'] ?? null,
                'unread' => $o['unread'] ?? 0, 'last_message_at' => self::utc((int)$last[2]), 'last_preview' => mb_substr((string)$last[1], 0, 150), 'created_at' => self::utc($t0), 'is_test' => 0, 'last_customer_at' => self::utc($t0), 'assigned_to' => ($o['handoff'] ?? null) ? $owner['id'] : null]);
            foreach ($msgs as [$who, $text, $ts, $meta]) {
                $in = $who === 'customer'; Db::insert('messages', ['tenant_id' => $tid, 'conversation_id' => $cid, 'direction' => $in ? 'in' : 'out', 'sender' => $who, 'content' => $text, 'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null, 'created_at' => self::utc((int)$ts)]); $count['messages']++;
                if ($who === 'ai') { $log[] = ['conv' => $cid, 'ts' => (int)$ts, 'tools' => $meta['tools'] ?? []]; }
                if ($who === 'human') Db::update('conversations', ['last_human_at' => self::utc((int)$ts)], 'id=?', [$cid]);
            }
            $count['conversations']++; return $cid;
        };
        $handoffs = 0;
        foreach ($orders as $o) {
            $cu = $o['cu']; $t = $o['ts'] - mt_rand(240, 1100); $first = $o['lines'][0]; $opt = trim(($first['color'] ?? '') . ' ' . ($first['size'] ? 'size ' . $first['size'] : '')); $what = $first['qty'] . ' ' . $first['name'] . ($opt !== '' ? ' (' . $opt . ')' : '') . (count($o['lines']) > 1 ? ' and ' . (count($o['lines']) - 1) . ' more' : '');
            $sum = "Demo Store\n🧾 Order summary\n" . implode("\n", array_map(fn($l) => '• ' . $l['qty'] . ' × ' . $l['name'] . ' — ' . self::rs($l['qty'] * $l['price']), $o['lines'])) . "\n\nSubtotal: " . self::rs($o['sub']) . "\nDelivery (" . $cu['city'] . '): ' . ($o['fee'] > 0 ? self::rs($o['fee']) : 'FREE') . "\nTotal: " . self::rs($o['sub'] + $o['fee']) . "\n\nPayment: " . $o['pay'] . "\nDeliver to: " . $cu['name'] . ', ' . $cu['address'] . "\nTap Confirm order to place it, or Cancel order to discard it.";
            $done = "✅ Order " . $o['no'] . " placed\nTotal: " . self::rs($o['sub'] + $o['fee']) . "\nPayment: " . $o['pay'] . "\nDelivery time: " . $o['eta'] . "\nThank you for shopping with Demo Store!";
            $st = $o['age'] > 3 ? 'closed' : 'ai'; $conv = null;
            if ($o['src'] === 'ai') {
                $conv = $chat($cu, [['customer', 'Hi, I want ' . $what, $t, null], ['ai', 'Hi ' . $cu['first'] . '! Yes, that is available. I just need your delivery address and how you would like to pay.', $t + 6, ['tools' => ['search_products']]],
                    ['customer', $cu['address'] . ', ' . $cu['city'] . '. ' . $o['pay'] . ' please', $t + 70, null], ['ai', $sum, $t + 79, ['tools' => ['check_order', 'create_order'], 'order_summary' => true]], ['customer', 'Confirm order', $t + 120, null], ['ai', $done, $t + 124, ['tools' => ['create_order'], 'order_tap' => 'confirm']]], $st, []);
                foreach ([['search_products', 0, $t + 6], ['check_order', 0, $t + 78], ['create_order', 1, $t + 79], ['create_order', 1, $t + 124]] as [$tool, $w, $at]) $log[] = ['tool' => $tool, 'conv' => $conv, 'ts' => $at, 'write' => $w];
            } elseif ($o['src'] === 'catalogue') {
                $conv = $chat($cu, [['customer', 'Can you show me your catalogue?', $t, null], ['system', '[Catalogue sent] Our catalogue', $t + 5, ['catalogue' => 'all']], ['ai', 'Here is our catalogue. Pick what you like and place the order right there. 😊', $t + 6, ['tools' => ['send_catalogue']]],
                    ['ai', $done, $o['ts'] + 5, ['catalogue_order' => $o['no']]]], $st, []);
                $log[] = ['tool' => 'send_catalogue', 'conv' => $conv, 'ts' => $t + 5, 'write' => 0];
            } elseif (mt_rand(1, 100) <= 45) { // team-placed after a chat
                $conv = $chat($cu, [['customer', 'Hello, can I order ' . $what . '?', $t, null], ['human', 'Hello ' . $cu['first'] . '! Of course, I have noted the order and will confirm the total shortly.', $t + 240, ['user' => $owner['name']]], ['human', 'Your order ' . $o['no'] . ' is placed: ' . self::rs($o['sub'] + $o['fee']) . ' including delivery. Thank you!', $t + 600, ['user' => $owner['name']]]], 'closed', []);
            }
            if ($conv && $o['status'] === 'cancelled') Db::q("UPDATE conversations SET last_preview=? WHERE id=?", ['Customer cancelled the order', $conv]);
        }
        $browse = [['Do you have jeans in size 32?', 'Yes! Slim Fit Jeans (Rs. 5,490) come in 30, 32, 34 and 36 in Blue and Black. Would you like to order a pair?', ['search_products']], ["What's the delivery fee to Kandy?", 'Delivery to Kandy is Rs. 450 and takes 2-3 working days. Orders over Rs. 10,000 ship free.', ['delivery_info']],
            ['Can I pay by bank transfer?', 'Yes, bank transfer is available. Send the slip here after you place the order and our team will verify it.', ['search_knowledge']], ['Do you accept returns?', 'Returns are accepted within 7 days if the item is unused. Would you like me to connect you with our team?', ['search_knowledge']],
            ['Is the Handloom Sarong in stock?', 'Yes, the Handloom Sarong is Rs. 3,200, in Brown and Green.', ['search_products']], ['ජීන්ස් තියෙනවද?', 'ඔව්, Slim Fit Jeans තියෙනවා. මිල රු. 5,490. ප්‍රමාණ 30, 32, 34, 36.', ['search_products']],
            ['ஜீன்ஸ் இருக்கிறதா?', 'ஆம், Slim Fit Jeans கிடைக்கும். விலை ரூ. 5,490. அளவுகள் 30, 32, 34, 36.', ['search_products']], ['Do you deliver to Jaffna?', 'Yes, we deliver to Jaffna in 3-5 working days for Rs. 650 (free over Rs. 15,000).', ['delivery_info']],
            ['mage order eka koheda?', 'Oyaage order eka dan pack karala thiyenne. Heta courier ekata denawa.', ['list_my_orders']], ['Any discount for 3 t-shirts?', 'I cannot change prices, but I can pass your request to our team if you would like a quote for a bigger order.', ['search_products']]];
        for ($i = 0; $i < 26; $i++) {
            $cu = $customers[mt_rand(0, count($customers) - 1)]; $b = $browse[$i % count($browse)]; $age = (int)floor(pow(mt_rand(0, 1000) / 1000, 1.4) * 28); $t = $now - $age * 86400 - mt_rand(600, 9 * 3600); $st = $age > 2 ? 'closed' : 'ai';
            $cv = $chat($cu, [['customer', $b[0], $t, null], ['ai', $b[1], $t + mt_rand(3, 9), ['tools' => $b[2]]]], $st, ['unread' => $age === 0 ? 1 : 0]); foreach ($b[2] as $tl) $log[] = ['tool' => $tl, 'conv' => $cv, 'ts' => $t + 5, 'write' => 0];
        }
        $hand = [['My order arrived damaged and I want a refund', 'Customer reports a damaged item and wants a refund', 'I am sorry about that. I have asked a team member to help you right away.', 'Hi, this is ' . $owner['name'] . ' from Demo Store. So sorry! Could you send a photo of the damage?'],
            ['I was charged twice for my card payment', 'Possible double card charge, needs checking', 'I understand. A team member will check this with the payment records and reply shortly.', 'Thanks for flagging this. We are checking the payment records now.'],
            ['The courier has not delivered, it has been 6 days', 'Delivery delayed, customer upset', 'I am sorry for the delay. I am passing this to our team so they can chase the courier.', 'We have contacted the courier and will update you today.'],
            ['I want to change the whole order to a different size, it is already shipped', 'Change request on a shipped order', 'That order has already shipped, so I cannot change it online. I am connecting you with our team.', 'We can arrange an exchange once it arrives. Please keep the tags on.'],
            ['Can I speak to a person please', 'Customer asked for a human', 'Of course. A team member will join this chat shortly.', 'Hi! How can I help you today?']];
        foreach ($hand as $i => [$ask, $why, $ai, $hu]) {
            $cu = $customers[mt_rand(0, count($customers) - 1)]; $age = $i < 2 ? 0 : mt_rand(2, 25); $t = $now - $age * 86400 - mt_rand(3600, 7 * 3600); $open = $i < 2;
            $cv = $chat($cu, [['customer', $ask, $t, null], ['ai', $ai, $t + 7, ['tools' => ['handoff_to_human']]], ['system', 'Handed off to human: ' . $why, $t + 8, null], ['human', $hu, $t + 540, ['user' => $owner['name']]]], $open ? 'human' : 'closed', ['handoff' => $open ? $why : null, 'unread' => $open ? 1 : 0]);
            Db::insert('handoffs', ['tenant_id' => $tid, 'conversation_id' => $cv, 'reason' => $why, 'created_at' => self::utc($t + 8), 'resolved_at' => $open ? null : self::utc($t + 3600), 'resolved_by' => $open ? null : 'human']); $handoffs++; $log[] = ['tool' => 'handoff_to_human', 'conv' => $cv, 'ts' => $t + 7, 'write' => 0];
        }

        // ---- leads (AI recorded and team added) over the last 45 days
        $stages = ['new' => 6, 'contacted' => 5, 'qualified' => 5, 'won' => 5, 'lost' => 3]; $stageList = []; foreach ($stages as $k => $n) for ($i = 0; $i < $n; $i++) $stageList[] = $k; mt_srand($seed + 1); shuffle($stageList);
        foreach (array_slice(self::LEADS, 0, count($stageList)) as $i => [$interest, $value]) {
            $cu = $customers[mt_rand(0, count($customers) - 1)]; $stage = $stageList[$i]; $age = (int)floor(pow(mt_rand(0, 1000) / 1000, 1.2) * 45); $t = $now - $age * 86400 - mt_rand(3600, 9 * 3600); $src = mt_rand(1, 100) <= 58 ? 'ai' : 'manual';
            $upd = $stage === 'new' ? $t : min($now - 600, $t + mt_rand(1, max(2, $age)) * 86400 / 2);
            Db::insert('crm_leads', ['tenant_id' => $tid, 'phone' => $cu['phone'], 'name' => $cu['name'], 'interest' => mb_substr($interest, 0, 200), 'preferred_time' => self::pick(['Weekday mornings', 'After 5 pm', 'Any time', 'This week', 'Saturday']), 'notes' => self::TAG . ($stage === 'won' ? '. Quote accepted, order placed.' : ($stage === 'qualified' ? '. Waiting for the customer to confirm quantities.' : '')),
                'source' => $src, 'stage' => $stage, 'value' => $value, 'assigned_to' => $stage === 'new' ? null : $owner['id'], 'lost_reason' => $stage === 'lost' ? self::pick(['Went with a cheaper supplier', 'No reply after two follow-ups', 'Budget cut']) : null,
                'confirmed_at' => $src === 'ai' && $stage !== 'new' ? self::utc($t + 600) : null, 'created_at' => self::utc($t), 'updated_at' => self::utc((int)$upd)]); $count['leads']++;
            if ($src === 'ai') { $cv = $chat($cu, [['customer', 'Hi, ' . lcfirst($interest) . '. Can you help?', $t, null], ['ai', 'Great, ' . $cu['first'] . '! I have recorded your enquiry so our sales team can send you a quote. What is the best time to call?', $t + 8, ['tools' => ['create_lead']]]], $age > 3 ? 'closed' : 'ai', []); $log[] = ['tool' => 'create_lead', 'conv' => $cv, 'ts' => $t + 8, 'write' => 1]; }
        }

        // ---- tool logs, AI call metrics and daily usage built from the chats above
        $usage = []; $dayOf = fn(int $ts) => (new \DateTime('@' . $ts))->setTimezone($tz)->format('Y-m-d');
        foreach ($log as $l) {
            if (isset($l['tool'])) { Db::insert('tool_logs', ['tenant_id' => $tid, 'conversation_id' => $l['conv'], 'tool' => $l['tool'], 'request_summary' => $l['tool'], 'http_status' => 200, 'ok' => 1, 'latency_ms' => mt_rand(40, 420), 'cache_hit' => 0, 'is_write' => $l['write'] ?? 0, 'created_at' => self::utc($l['ts'])]); $d = $dayOf($l['ts']); $usage[$d]['tool_calls'] = ($usage[$d]['tool_calls'] ?? 0) + 1; }
            else { $ms = mt_rand(1000, 3800); $tin = mt_rand(4500, 5300); $tout = mt_rand(20, 90); Db::insert('ai_calls', ['tenant_id' => $tid, 'conversation_id' => $l['conv'], 'kind' => 'reply', 'model' => 'gemini-3.5-flash-lite', 'ok' => 1, 'http_status' => 200, 'latency_ms' => $ms, 'tokens_in' => $tin, 'tokens_out' => $tout, 'finish_reason' => 'STOP', 'is_test' => 0, 'created_at' => self::utc($l['ts'])]);
                $d = $dayOf($l['ts']); foreach (['ai_replies' => 1, 'messages_out' => 1, 'ai_calls' => 1, 'tokens_in' => $tin, 'tokens_out' => $tout, 'latency_ms_total' => $ms] as $k => $v) $usage[$d][$k] = ($usage[$d][$k] ?? 0) + $v; }
        }
        foreach (Db::all("SELECT m.created_at FROM messages m JOIN conversations c ON c.id=m.conversation_id JOIN contacts ct ON ct.id=c.contact_id WHERE m.tenant_id=? AND ct.tags=? AND m.sender='customer'", [$tid, self::TAG]) as $m) { $d = $dayOf(strtotime($m['created_at'] . ' UTC')); $usage[$d]['messages_in'] = ($usage[$d]['messages_in'] ?? 0) + 1; }
        foreach (Db::all("SELECT h.created_at FROM handoffs h JOIN conversations c ON c.id=h.conversation_id JOIN contacts ct ON ct.id=c.contact_id WHERE h.tenant_id=? AND ct.tags=?", [$tid, self::TAG]) as $h) { $d = $dayOf(strtotime($h['created_at'] . ' UTC')); $usage[$d]['handoffs'] = ($usage[$d]['handoffs'] ?? 0) + 1; }
        $cols = ['messages_in', 'messages_out', 'ai_replies', 'handoffs', 'tool_calls', 'tokens_in', 'tokens_out', 'ai_calls', 'latency_ms_total'];
        foreach ($usage as $day => $u) { $vals = array_map(fn($c) => (int)($u[$c] ?? 0), $cols); Db::q("INSERT INTO usage_daily (tenant_id, day, " . implode(',', $cols) . ") VALUES (?,?," . implode(',', array_fill(0, count($cols), '?')) . ") ON DUPLICATE KEY UPDATE " . implode(',', array_map(fn($c) => "$c = $c + VALUES($c)", $cols)), array_merge([$tid, $day], $vals)); }
        Tenant::saveSettings($tid, ['demo_usage' => $usage]);
        $count['revenue'] = (int)Db::val("SELECT COALESCE(SUM(total),0) FROM store_orders WHERE tenant_id=? AND notes=? AND status<>'cancelled'", [$tid, self::TAG]); $count['handoffs'] = $handoffs;
        return $count;
    }

    public static function exists(int $tid): bool { return (int)Db::val("SELECT COUNT(*) FROM store_orders WHERE tenant_id=? AND notes=?", [$tid, self::TAG]) > 0 || (int)Db::val("SELECT COUNT(*) FROM contacts WHERE tenant_id=? AND tags=?", [$tid, self::TAG]) > 0; }

    /** Remove only what seed() added. @return array<string,int> */
    public static function clear(int $tid): array {
        $r = [];
        $contacts = array_column(Db::all("SELECT id, phone FROM contacts WHERE tenant_id=? AND tags=?", [$tid, self::TAG]), null, 'id'); $cids = array_keys($contacts);
        $convs = $cids ? array_column(Db::all("SELECT id FROM conversations WHERE tenant_id=? AND contact_id IN (" . implode(',', array_map('intval', $cids)) . ")", [$tid]), 'id') : []; $in = $convs ? implode(',', array_map('intval', $convs)) : '0';
        $r['ai_calls'] = Db::q("DELETE FROM ai_calls WHERE tenant_id=? AND conversation_id IN ($in)", [$tid])->rowCount(); $r['tool_logs'] = Db::q("DELETE FROM tool_logs WHERE tenant_id=? AND conversation_id IN ($in)", [$tid])->rowCount();
        Db::q("DELETE FROM handoffs WHERE tenant_id=? AND conversation_id IN ($in)", [$tid]); Db::q("DELETE FROM messages WHERE tenant_id=? AND conversation_id IN ($in)", [$tid]); $r['conversations'] = Db::q("DELETE FROM conversations WHERE tenant_id=? AND id IN ($in)", [$tid])->rowCount();
        $phones = array_filter(array_column($contacts, 'phone')); if ($phones) Db::q("DELETE FROM customer_profiles WHERE tenant_id=? AND phone IN (" . implode(',', array_fill(0, count($phones), '?')) . ")", array_merge([$tid], array_values($phones)));
        $r['contacts'] = $cids ? Db::q("DELETE FROM contacts WHERE tenant_id=? AND tags=?", [$tid, self::TAG])->rowCount() : 0;
        $r['orders'] = Db::q("DELETE FROM store_orders WHERE tenant_id=? AND notes=?", [$tid, self::TAG])->rowCount(); $r['leads'] = Db::q("DELETE FROM crm_leads WHERE tenant_id=? AND notes LIKE ?", [$tid, self::TAG . '%'])->rowCount();
        $usage = (Tenant::get($tid)['cfg']['demo_usage'] ?? []); $cols = ['messages_in', 'messages_out', 'ai_replies', 'handoffs', 'tool_calls', 'tokens_in', 'tokens_out', 'ai_calls', 'latency_ms_total'];
        foreach ($usage as $day => $u) { Db::q("UPDATE usage_daily SET " . implode(',', array_map(fn($c) => "$c = GREATEST(0, $c - ?)", $cols)) . " WHERE tenant_id=? AND day=?", array_merge(array_map(fn($c) => (int)($u[$c] ?? 0), $cols), [$tid, $day])); }
        Db::q("DELETE FROM usage_daily WHERE tenant_id=? AND messages_in=0 AND messages_out=0 AND ai_replies=0 AND tool_calls=0 AND tokens_in=0 AND tokens_out=0", [$tid]); Tenant::saveSettings($tid, ['demo_usage' => []]);
        return $r;
    }
}
