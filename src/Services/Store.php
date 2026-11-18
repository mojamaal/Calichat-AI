<?php
declare(strict_types=1);
namespace CaliChat\Services;

use CaliChat\Core\Audit;
use CaliChat\Core\Crypto;
use CaliChat\Core\Db;

/** A business rule was broken. The code is the HTTP status the store API (and therefore the AI) sees. */
class StoreError extends \RuntimeException {}

/**
 * Built-in lightweight store + CRM: products, delivery zones, orders, leads.
 * The business rules live here (prices come from the catalogue, stock is checked, an order belongs to one phone number),
 * so the store API used by the AI and the dashboard pages behave identically.
 */
class Store {
    public const STATUSES = ['processing', 'packed', 'shipped', 'delivered', 'cancelled', 'returned'];
    public const STAGES = ['new', 'contacted', 'qualified', 'won', 'lost'];
    private const NEXT = ['processing' => ['packed', 'shipped', 'cancelled'], 'packed' => ['shipped', 'cancelled'], 'shipped' => ['delivered', 'returned'], 'delivered' => ['returned'], 'cancelled' => [], 'returned' => []];
    public const CONNECTOR = 'CaliChat Store';

    // ------------------------------------------------------------------ invoices (public, signed link; the customer needs no login)
    private static function invoiceSig(int $tid, string $orderNo): string { return substr(rtrim(strtr(base64_encode(hash_hmac('sha256', 'inv|' . $tid . '|' . strtoupper($orderNo), (string)env('APP_KEY', 'dev'), true)), '+/', '-_'), '='), 0, 24); }
    public static function invoiceUrl(int $tid, string $orderNo): string { return abs_url('invoice/' . $tid . '/' . strtoupper($orderNo) . '/' . self::invoiceSig($tid, $orderNo)); }
    public static function invoiceValid(int $tid, string $orderNo, string $sig): bool { return hash_equals(self::invoiceSig($tid, $orderNo), $sig); }

    /** The WhatsApp contact for a phone number: when the same number appears on two channels, the one that wrote most recently. */
    public static function contactByPhone(int $tid, string $phone): ?array {
        $p = self::digits($phone); if ($p === '') return null;
        return Db::one("SELECT ct.*, (SELECT MAX(v.last_customer_at) FROM conversations v WHERE v.contact_id=ct.id) lc FROM contacts ct WHERE ct.tenant_id=? AND ct.channel_type IN ('whatsapp','twilio_whatsapp') AND (ct.phone=? OR ct.external_id=?) ORDER BY lc IS NULL, lc DESC, ct.last_seen DESC LIMIT 1", [$tid, $p, $p]);
    }

    /** Statuses an order in this status may move to. @return string[] */
    public static function next(string $status): array { return self::NEXT[$status] ?? []; }

    public static function digits(string $p): string { return preg_replace('/\D/', '', preg_replace('/^whatsapp:/i', '', $p)); }

    /** @return array{default_fee:float,free_over:float,max_total:float,payments:string[],require_colour:bool,invoice_info:string} */
    public static function settings(int $tid): array {
        $cfg = \CaliChat\Core\Tenant::get($tid)['cfg'] ?? [];
        $pay = array_values(array_filter(array_map('trim', explode(',', (string)($cfg['store_payments'] ?? 'Cash on delivery,Bank transfer,Card')))));
        return ['default_fee' => (float)($cfg['store_default_fee'] ?? 350), 'free_over' => (float)($cfg['store_free_over'] ?? 10000), 'max_total' => (float)($cfg['store_max_total'] ?? 100000), 'payments' => $pay ?: ['Cash on delivery'], 'require_colour' => (bool)($cfg['store_require_colour'] ?? true), 'invoice_info' => (string)($cfg['store_invoice_info'] ?? '')];
    }

    // ------------------------------------------------------------------ connection to the AI
    /** Create/refresh the store connector and tools. Returns the number of tools that were re-pointed from another connector. */
    public static function connect(int $tid): int {
        $conn = Db::one("SELECT id, auth_secret FROM connectors WHERE tenant_id=? AND name=?", [$tid, self::CONNECTOR]);
        $token = $conn ? Crypto::decrypt($conn['auth_secret']) : null;
        if (!$token) $token = 'st_' . bin2hex(random_bytes(24));
        Db::q("REPLACE INTO store_tokens (tenant_id, token_hash) VALUES (?,?)", [$tid, hash('sha256', $token)]);
        $before = (int)Db::val("SELECT COUNT(*) FROM tools t JOIN connectors c ON c.id=t.connector_id WHERE t.tenant_id=? AND c.name<>?", [$tid, self::CONNECTOR]);
        $s = self::settings($tid);
        $zones = ['delivery_info' => ['Quote the delivery fee and delivery time for a town or city, given the order subtotal. Use before the customer chooses payment.', '/delivery',
            [['name' => 'city', 'type' => 'string', 'in' => 'query', 'required' => 1, 'source' => 'ai', 'max_len' => 60, 'description' => 'Delivery town or city'],
             ['name' => 'subtotal', 'type' => 'number', 'in' => 'query', 'source' => 'ai', 'min' => 0, 'description' => 'Order subtotal in LKR (optional)']],
            'data', 'city,fee,free_over,eta,cod_allowed,known', 60]];
        $ph = ['name' => 'phone', 'type' => 'string', 'in' => 'query', 'required' => 1, 'source' => 'contact.phone', 'description' => ''];
        $one = '[A-Za-z0-9-]{2,30}:[A-Za-z0-9-]{1,10}:[1-9][0-9]?(:[A-Za-z][A-Za-z -]{0,19})?';
        $pay = implode(',', $s['payments']);
        $extra = $zones + [
            'check_order' => ['Dry-run check of the order details you extracted from the customer messages. Returns the real prices, delivery fee, total, every PROBLEM (size or colour needed, out of stock, cash on delivery unavailable, above the assistant limit) and every MISSING detail. Call it before showing the customer a summary; pass order_no to check a change to an existing order. Changes nothing.', '/orders/check',
                [['name' => 'items', 'type' => 'string', 'in' => 'query', 'source' => 'ai', 'max_len' => 240, 'pattern' => '^' . $one . '(,' . $one . '){0,7}$', 'description' => 'Items as SKU:SIZE:QTY:COLOUR separated by commas (use - when no size). For a change to an existing order send the COMPLETE new list.'],
                 ['name' => 'delivery_city', 'type' => 'string', 'in' => 'query', 'source' => 'ai', 'max_len' => 60, 'description' => 'Delivery town or city'],
                 ['name' => 'delivery_address', 'type' => 'string', 'in' => 'query', 'source' => 'ai', 'max_len' => 150, 'description' => 'Full delivery address'],
                 ['name' => 'payment_method', 'type' => 'string', 'in' => 'query', 'source' => 'ai', 'enum' => $pay, 'description' => 'Payment method'],
                 ['name' => 'customer_name', 'type' => 'string', 'in' => 'query', 'source' => 'ai', 'max_len' => 80, 'description' => 'Customer name'],
                 ['name' => 'order_no', 'type' => 'string', 'in' => 'query', 'source' => 'ai', 'pattern' => '^ORD-[0-9]{3,8}$', 'description' => 'Only when checking a change to an existing order'], $ph],
                'data', 'ready,lines,sku,name,size,color,qty,unit_price,line_total,subtotal,delivery_fee,total,eta,delivery_known,problems,missing', 0],
            'get_invoice_link' => ['Get the link to THIS customer\'s invoice for an order, to share in the chat when they ask for an invoice or receipt.', '/orders/{order_no}/invoice',
                [['name' => 'order_no', 'type' => 'string', 'in' => 'path', 'required' => 1, 'source' => 'ai', 'pattern' => '^ORD-[0-9]{3,8}$', 'description' => 'Order number like ORD-1001'], $ph], 'data', 'invoice_url,order_no', 0],
            'get_customer_profile' => ['What we already know about THIS customer from earlier orders and chats: name, delivery address, town, usual payment method. Use it to avoid asking again: offer the saved details and let the customer confirm or correct them.', '/customer', [$ph], 'data', 'name,address,city,payment_method,orders_count,last_order_at', 0],
            'save_customer_details' => ['Remember details the customer just gave you (their name, delivery address, town, usual payment method) so next time you can offer them instead of asking. Save only what the customer actually said.', '/customer',
                [['name' => 'name', 'type' => 'string', 'in' => 'body', 'source' => 'ai', 'max_len' => 100, 'description' => 'Customer name'], ['name' => 'address', 'type' => 'string', 'in' => 'body', 'source' => 'ai', 'max_len' => 200, 'description' => 'Delivery address'],
                 ['name' => 'city', 'type' => 'string', 'in' => 'body', 'source' => 'ai', 'max_len' => 60, 'description' => 'Delivery town or city'], ['name' => 'payment_method', 'type' => 'string', 'in' => 'body', 'source' => 'ai', 'enum' => $pay, 'description' => 'Usual payment method'],
                 ['name' => 'phone', 'type' => 'string', 'in' => 'body', 'required' => 1, 'source' => 'contact.phone', 'description' => '']], '', 'message,data,name,address,city,payment_method', 0, 'POST', 1],
        ];
        if (Appointments::services($tid)) {
            $sv = ['name' => 'service_id', 'type' => 'integer', 'in' => 'query', 'required' => 1, 'source' => 'ai', 'min' => 1, 'description' => 'Service id from list_services'];
            $extra += [
                'list_services' => ['List the services that can be booked as an appointment, with duration and price.', '/services', [], 'data', 'id,name,duration_min,price', 60],
                'get_free_slots' => ['Free appointment times for one service on one date. NEVER invent times: only offer times returned here.', '/slots', [$sv, ['name' => 'date', 'type' => 'string', 'in' => 'query', 'required' => 1, 'source' => 'ai', 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$', 'description' => 'Date as YYYY-MM-DD']], 'data', 'date,service_id,free_times,note', 0],
                'my_appointments' => ['This customer\'s recent and upcoming appointments.', '/appointments', [$ph], 'data', 'id,service,customer_name,date,time,day,status', 0],
                'book_appointment' => ['Book an appointment for THIS customer at one of the free times. Get the service, the date and time (from get_free_slots) and the customer\'s name first; the system shows a preview first.', '/appointments',
                    [['name' => 'service_id', 'type' => 'integer', 'in' => 'body', 'required' => 1, 'source' => 'ai', 'min' => 1, 'description' => 'Service id from list_services'], ['name' => 'start', 'type' => 'string', 'in' => 'body', 'required' => 1, 'source' => 'ai', 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}$', 'description' => 'Local start as YYYY-MM-DD HH:MM, exactly one of the free times'],
                     ['name' => 'customer_name', 'type' => 'string', 'in' => 'body', 'source' => 'ai', 'max_len' => 80, 'description' => 'Customer name'], ['name' => 'phone', 'type' => 'string', 'in' => 'body', 'required' => 1, 'source' => 'contact.phone', 'description' => '']], '', 'data,id,service,date,time,day,status,message,idempotent_replay', 0, 'POST'],
                'cancel_appointment' => ['Cancel one of THIS customer\'s upcoming appointments (get the id from my_appointments); the system shows a preview first.', '/appointments/{id}/cancel',
                    [['name' => 'id', 'type' => 'string', 'in' => 'path', 'required' => 1, 'source' => 'ai', 'pattern' => '^[0-9]{1,9}$', 'description' => 'Appointment id'], ['name' => 'phone', 'type' => 'string', 'in' => 'body', 'required' => 1, 'source' => 'contact.phone', 'description' => '']], '', 'data,id,service,date,time,day,status,message,idempotent_replay', 0, 'POST'],
            ];
        }
        DemoData::installTools($tid, self::CONNECTOR, abs_url('store/v1'), $token, '[A-Za-z0-9-]{2,30}', $pay, $extra, true);
        // Tools of the same name that previously pointed to another system now point here.
        return $before;
    }

    public static function connected(int $tid): bool {
        return (bool)Db::val("SELECT COUNT(*) FROM tools t JOIN connectors c ON c.id=t.connector_id WHERE t.tenant_id=? AND c.name=? AND t.is_active=1", [$tid, self::CONNECTOR]);
    }

    /** Tenant id for a bearer token, or null. */
    public static function tenantForToken(string $bearer): ?int {
        if (strpos($bearer, 'Bearer st_') !== 0) return null;
        $tid = Db::val("SELECT s.tenant_id FROM store_tokens s JOIN tenants t ON t.id=s.tenant_id WHERE s.token_hash=? AND t.status='active'", [hash('sha256', substr($bearer, 7))]);
        return $tid ? (int)$tid : null;
    }

    // ------------------------------------------------------------------ products
    public static function productView(array $r): array {
        return ['sku' => $r['sku'], 'name' => $r['name'], 'category' => $r['category'], 'description' => $r['description'], 'price' => (float)$r['price'], 'currency' => $r['currency'],
            'stock' => (int)$r['stock'], 'in_stock' => (int)$r['stock'] > 0, 'variants' => $r['variants'] ? array_map('trim', explode(',', $r['variants'])) : [], 'colors' => $r['colors'] ? array_map('trim', explode(',', $r['colors'])) : [],
            'image_url' => $r['image_url'], 'cost_price' => (float)$r['cost_price']]; // cost_price is internal on purpose: the tool's allow-list keeps it away from the AI
    }

    /** @throws StoreError */
    public static function saveProduct(int $tid, array $in, ?int $id = null): int {
        $sku = strtoupper(trim((string)($in['sku'] ?? '')));
        if (!preg_match('/^[A-Z0-9-]{2,30}$/', $sku)) throw new StoreError('SKU must be 2-30 letters, digits or dashes (example: TS-001)', 422);
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') throw new StoreError('Product name is required', 422);
        $price = (float)($in['price'] ?? -1); if ($price < 0 || $price > 10000000) throw new StoreError("Price for $sku must be between 0 and 10,000,000", 422);
        $csv = function ($v) { $p = array_values(array_unique(array_filter(array_map('trim', explode(',', (string)$v))))); foreach ($p as $x) if (!preg_match('/^[A-Za-z0-9 -]{1,10}$/', $x)) throw new StoreError("Sizes must be short, like S,M,L or 32,34: '$x'", 422); return $p ? implode(',', $p) : null; };
        $img = trim((string)($in['image_url'] ?? '')); if ($img !== '' && !preg_match('#^https://#i', $img) && !ProductArt::isArtUrl($img)) throw new StoreError('Image link must start with https://', 422);
        if (ProductArt::isArtUrl($img) && explode('/', $img)[2] !== (string)$tid) $img = ProductArt::url($tid, $sku); // a built-in picture is always this business's own
        $row = ['sku' => $sku, 'name' => mb_substr($name, 0, 150), 'category' => mb_substr(trim((string)($in['category'] ?? '')), 0, 80) ?: null, 'description' => mb_substr(trim((string)($in['description'] ?? '')), 0, 1000) ?: null,
            'price' => $price, 'cost_price' => max(0, (float)($in['cost_price'] ?? 0)), 'stock' => max(0, (int)($in['stock'] ?? 0)), 'variants' => $csv($in['variants'] ?? ''), 'colors' => $csv($in['colors'] ?? ''),
            'image_url' => $img !== '' ? mb_substr($img, 0, 500) : null, 'is_active' => isset($in['is_active']) ? (int)(bool)$in['is_active'] : 1, 'updated_at' => now()];
        $dup = Db::val("SELECT id FROM store_products WHERE tenant_id=? AND sku=?", [$tid, $sku]);
        if ($id) {
            if ($dup && (int)$dup !== $id) throw new StoreError("SKU $sku already exists", 409);
            Db::update('store_products', $row, 'id=? AND tenant_id=?', [$id, $tid]); return $id;
        }
        if ($dup) { Db::update('store_products', $row, 'id=? AND tenant_id=?', [$dup, $tid]); return (int)$dup; } // upsert by SKU (CSV import)
        return Db::insert('store_products', $row + ['tenant_id' => $tid]);
    }

    /** CSV with a header row: sku,name,price,stock,category,variants,colors,description,cost_price,image_url. @return array{added:int,errors:string[]} */
    public static function importCsv(int $tid, string $csv): array {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)); $head = array_map(fn($h) => strtolower(trim($h)), str_getcsv((string)array_shift($lines)));
        if (!in_array('sku', $head, true) || !in_array('name', $head, true) || !in_array('price', $head, true)) return ['added' => 0, 'errors' => ['The first row must contain at least the columns sku, name and price.']];
        $n = 0; $errs = [];
        foreach (array_slice($lines, 0, 2000) as $i => $line) {
            if (trim($line) === '') continue;
            $cells = str_getcsv($line); $row = [];
            foreach ($head as $k => $h) $row[$h] = $cells[$k] ?? '';
            try { self::saveProduct($tid, $row); $n++; } catch (StoreError $e) { $errs[] = 'Row ' . ($i + 2) . ': ' . $e->getMessage(); if (count($errs) >= 10) break; }
        }
        return ['added' => $n, 'errors' => $errs];
    }

    /** 12 sample products and Sri Lankan delivery zones so a new store can be tried immediately. */
    public static function seedSample(int $tid): void {
        $items = [['TS-001', 'Cotton T-Shirt', 'Tops', 1850, 40, 'S,M,L,XL', 'Black,White,Navy'], ['TS-002', 'Polo Shirt', 'Tops', 2650, 25, 'S,M,L,XL', 'Navy,Maroon'], ['JN-015', 'Slim Fit Jeans', 'Bottoms', 5490, 18, '30,32,34,36', 'Blue,Black'],
            ['SR-110', 'Handloom Sarong', 'Traditional', 3200, 30, '', 'Brown,Green'], ['DR-301', 'Summer Dress', 'Dresses', 6900, 12, 'S,M,L', 'Floral,Red'], ['SH-221', 'Canvas Sneakers', 'Footwear', 7500, 20, '40,41,42,43,44', 'White,Black'],
            ['SL-045', 'Rubber Slippers', 'Footwear', 1250, 60, '39,40,41,42,43', 'Black,Blue'], ['AC-503', 'Baseball Cap', 'Accessories', 1500, 50, '', 'Black,Khaki'], ['AC-510', 'Leather Belt', 'Accessories', 2400, 15, '32,34,36', 'Brown,Black'],
            ['BG-601', 'Canvas Tote Bag', 'Bags', 2950, 22, '', 'Natural'], ['WT-700', 'Analog Wrist Watch', 'Accessories', 12900, 6, '', 'Silver,Gold'], ['SC-050', 'Cotton Scarf', 'Accessories', 1650, 0, '', 'Red,Grey']];
        foreach ($items as [$sku, $name, $cat, $price, $stock, $var, $col]) self::saveProduct($tid, ['sku' => $sku, 'name' => $name, 'category' => $cat, 'price' => $price, 'stock' => $stock, 'variants' => $var, 'colors' => $col, 'cost_price' => round($price * 0.55), 'description' => "$name in Sri Lanka's favourite colours.", 'image_url' => ProductArt::url($tid, $sku)]);
        foreach ([['Colombo', 350, null, '1-2 working days'], ['Gampaha', 350, null, '1-2 working days'], ['Kandy', 450, null, '2-3 working days'], ['Galle', 450, null, '2-3 working days'], ['Jaffna', 650, 15000, '3-5 working days']] as [$t, $fee, $free, $eta]) {
            Db::q("INSERT IGNORE INTO store_zones (tenant_id, town, fee, free_over, eta, cod_allowed) VALUES (?,?,?,?,?,1)", [$tid, $t, $fee, $free, $eta]);
        }
    }

    /** Give every product that has no picture a built-in illustration (never replaces a photo link). @return int products updated */
    public static function addPictures(int $tid): int {
        $n = 0;
        foreach (Db::all("SELECT id, sku FROM store_products WHERE tenant_id=? AND (image_url IS NULL OR image_url='')", [$tid]) as $r) { Db::update('store_products', ['image_url' => ProductArt::url($tid, $r['sku']), 'updated_at' => now()], 'id=? AND tenant_id=?', [$r['id'], $tid]); $n++; }
        return $n;
    }

    // ------------------------------------------------------------------ delivery
    /** @return array{city:string,fee:float,free_over:float,eta:?string,cod_allowed:bool,known:bool} */
    public static function delivery(int $tid, string $city, float $subtotal = 0): array {
        $s = self::settings($tid); $z = $city === '' ? null : Db::one("SELECT * FROM store_zones WHERE tenant_id=? AND LOWER(town)=LOWER(?)", [$tid, $city]);
        $free = $z && $z['free_over'] !== null ? (float)$z['free_over'] : $s['free_over'];
        $fee = $z ? (float)$z['fee'] : $s['default_fee'];
        if ($free > 0 && $subtotal >= $free) $fee = 0.0;
        return ['city' => $city, 'fee' => $fee, 'free_over' => $free, 'eta' => $z['eta'] ?? null, 'cod_allowed' => $z ? (bool)$z['cod_allowed'] : true, 'known' => (bool)$z];
    }

    // ------------------------------------------------------------------ orders
    public static function orderView(array $o, bool $internal = false): array {
        $items = Db::all("SELECT sku, name, size, color, qty, unit_price FROM store_order_items WHERE order_id=? ORDER BY id", [$o['id']]);
        foreach ($items as &$i) { $i['qty'] = (int)$i['qty']; $i['unit_price'] = (float)$i['unit_price']; }
        $v = ['order_no' => $o['order_no'], 'phone' => $o['phone'], 'customer_name' => $o['customer_name'], 'status' => ucfirst($o['status']), 'payment_method' => $o['payment_method'], 'payment_status' => $o['payment_status'],
            'courier' => $o['courier'], 'tracking' => $o['tracking'], 'eta' => $o['eta'], 'delivery_city' => $o['delivery_city'], 'delivery_address' => $o['delivery_address'],
            'subtotal' => (float)$o['subtotal'], 'delivery_fee' => (float)$o['delivery_fee'], 'total' => (float)$o['total'], 'items' => $items, 'placed_at' => $o['created_at']];
        return $internal ? $v + ['notes' => $o['notes'], 'source' => $o['source']] : $v;
    }

    public static function order(int $tid, string $orderNo): ?array { return Db::one("SELECT * FROM store_orders WHERE tenant_id=? AND order_no=?", [$tid, strtoupper($orderNo)]); }

    /** One item entry "SKU:SIZE:QTY[:COLOUR]" -> validated line. Size "-" means no size. @throws StoreError */
    private static function parseLine(int $tid, string $p): array {
        if (!preg_match('/^([A-Za-z0-9-]{2,30}):([A-Za-z0-9-]{1,10}):([1-9][0-9]?)(?::([A-Za-z][A-Za-z -]{0,19}))?$/', $p, $m)) throw new StoreError("Bad item '$p'. Use SKU:SIZE:QTY or SKU:SIZE:QTY:COLOUR, with - when there is no size.", 422);
        $sku = strtoupper($m[1]); $size = $m[2] === '-' ? null : $m[2]; $qty = (int)$m[3]; $colour = isset($m[4]) ? trim($m[4]) : '';
        $pr = Db::one("SELECT * FROM store_products WHERE tenant_id=? AND sku=? AND is_active=1", [$tid, $sku]);
        if (!$pr) throw new StoreError("Unknown product $sku", 404);
        $variants = $pr['variants'] ? array_map('trim', explode(',', $pr['variants'])) : [];
        if ($variants) { $hit = array_values(array_filter($variants, fn($v) => strcasecmp($v, (string)$size) === 0)); if (!$hit) throw new StoreError("{$pr['name']} needs a size: " . implode(', ', $variants), 422); $size = $hit[0]; } else $size = null;
        $colours = $pr['colors'] ? array_map('trim', explode(',', $pr['colors'])) : [];
        if ($colours) {
            if ($colour === '' && count($colours) === 1) $colour = $colours[0];
            if ($colour === '') { if (self::settings($tid)['require_colour']) throw new StoreError("{$pr['name']} needs a colour: " . implode(', ', $colours), 422); }
            else { $hit = array_values(array_filter($colours, fn($c) => strcasecmp($c, $colour) === 0)); if (!$hit) throw new StoreError("{$pr['name']} does not come in $colour. Colours: " . implode(', ', $colours), 422); $colour = $hit[0]; }
        } else $colour = '';
        return ['sku' => $sku, 'name' => $pr['name'], 'size' => $size, 'color' => $colour !== '' ? $colour : null, 'qty' => $qty, 'price' => (float)$pr['price']];
    }
    /** @return array<int,string> the item entries, trimmed */
    private static function splitItems(string $s): array { return array_values(array_filter(array_map('trim', explode(',', $s)), 'strlen')); }
    /** "TS-001:M:1:Black,SH-221:42:2" -> validated lines (same product, size and colour are merged). @throws StoreError */
    private static function parseItems(int $tid, string $s): array {
        $parts = self::splitItems($s);
        if (!$parts || count($parts) > 8) throw new StoreError('items must contain 1 to 8 entries like SKU:SIZE:QTY:COLOUR', 422);
        $lines = [];
        foreach ($parts as $p) {
            $l = self::parseLine($tid, $p); $k = $l['sku'] . '|' . $l['size'] . '|' . $l['color'];
            if (isset($lines[$k])) $lines[$k]['qty'] += $l['qty']; else $lines[$k] = $l;
        }
        return array_values($lines);
    }

    /**
     * Dry run for the AI: validate what it extracted from the customer's messages and report every problem and every missing detail at once,
     * with the real prices, delivery fee and total. Changes nothing.
     * @param array{items?:string,delivery_city?:string,delivery_address?:string,payment_method?:string,customer_name?:string} $in
     * @return array{ready:bool,lines:array,subtotal:float,delivery_fee:float,total:float,eta:?string,problems:string[],missing:string[]}
     */
    public static function check(int $tid, array $in, ?array $existing = null): array {
        $problems = []; $missing = []; $lines = []; $sub = 0.0;
        $items = trim((string)($in['items'] ?? ''));
        if ($items === '') { if (!$existing) $missing[] = 'items (which products, size, colour and how many)'; }
        else {
            $parts = self::splitItems($items); if (count($parts) > 8) $problems[] = 'At most 8 different items per order.';
            foreach (array_slice($parts, 0, 8) as $p) {
                try { $l = self::parseLine($tid, $p); $k = $l['sku'] . '|' . $l['size'] . '|' . $l['color']; if (isset($lines[$k])) $lines[$k]['qty'] += $l['qty']; else $lines[$k] = $l; }
                catch (StoreError $e) { $problems[] = $e->getMessage(); }
            }
            $lines = array_values($lines);
            foreach ($lines as &$l) { $have = (int)Db::val("SELECT stock FROM store_products WHERE tenant_id=? AND sku=?", [$tid, $l['sku']]); if ($have < $l['qty']) $problems[] = "Not enough stock for {$l['name']} (available: $have)."; $l['line_total'] = $l['price'] * $l['qty']; $sub += $l['line_total']; } unset($l);
        }
        $city = trim((string)($in['delivery_city'] ?? ($existing['delivery_city'] ?? ''))); $addr = trim((string)($in['delivery_address'] ?? ($existing['delivery_address'] ?? '')));
        if (!$existing) { if ($city === '') $missing[] = 'delivery town or city'; if ($addr === '') $missing[] = 'delivery address'; if (trim((string)($in['customer_name'] ?? '')) === '') $missing[] = 'customer name'; }
        $s = self::settings($tid); $pay = trim((string)($in['payment_method'] ?? ($existing['payment_method'] ?? '')));
        if ($pay === '') { if (!$existing) $missing[] = 'payment method (' . implode(', ', $s['payments']) . ')'; }
        elseif (!in_array($pay, $s['payments'], true)) $problems[] = 'Payment method must be one of: ' . implode(', ', $s['payments']) . '.';
        if ($existing && $items === '') $sub = (float)$existing['subtotal'];
        $d = self::delivery($tid, $city, $sub); if ($pay === 'Cash on delivery' && $city !== '' && !$d['cod_allowed']) $problems[] = "Cash on delivery is not available for $city.";
        $total = $sub + ($city !== '' || $existing ? $d['fee'] : 0.0);
        if ($s['max_total'] > 0 && $total > $s['max_total']) $problems[] = 'This order is above the limit the assistant may place (Rs. ' . number_format($s['max_total']) . '). A team member must place it: hand off.';
        return ['ready' => !$problems && !$missing, 'lines' => $lines, 'subtotal' => $sub, 'delivery_fee' => $city !== '' || $existing ? $d['fee'] : 0.0, 'total' => $total, 'eta' => $d['eta'], 'delivery_known' => $d['known'], 'problems' => $problems, 'missing' => $missing];
    }

    // ------------------------------------------------------------------ customer profile (what the AI remembers for faster repeat orders)
    private static function clean(string $v, int $max): ?string { $v = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v)); return $v === '' ? null : mb_substr($v, 0, $max); }
    public static function profile(int $tid, string $phone): ?array { return Db::one("SELECT name, address, city, payment_method, orders_count, last_order_at FROM customer_profiles WHERE tenant_id=? AND phone=?", [$tid, self::digits($phone)]); }
    /** Save details the customer gave. Only non-empty fields overwrite. */
    public static function saveProfile(int $tid, string $phone, array $in, bool $countOrder = false): void {
        $phone = self::digits($phone); if (strlen($phone) < 9) return;
        $v = ['name' => self::clean((string)($in['name'] ?? $in['customer_name'] ?? ''), 100), 'address' => self::clean((string)($in['address'] ?? $in['delivery_address'] ?? ''), 200), 'city' => self::clean((string)($in['city'] ?? $in['delivery_city'] ?? ''), 60), 'payment_method' => self::clean((string)($in['payment_method'] ?? ''), 30)];
        if ($v['payment_method'] !== null && !in_array($v['payment_method'], self::settings($tid)['payments'], true)) $v['payment_method'] = null;
        Db::q("INSERT INTO customer_profiles (tenant_id, phone, name, address, city, payment_method, orders_count, last_order_at) VALUES (?,?,?,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE name=COALESCE(VALUES(name), name), address=COALESCE(VALUES(address), address), city=COALESCE(VALUES(city), city), payment_method=COALESCE(VALUES(payment_method), payment_method),
               orders_count = orders_count + VALUES(orders_count), last_order_at = IF(VALUES(orders_count) > 0, VALUES(last_order_at), last_order_at), updated_at=NOW()",
            [$tid, $phone, $v['name'], $v['address'], $v['city'], $v['payment_method'], $countOrder ? 1 : 0, $countOrder ? now() : null]);
    }
    /** A short, data-only block for the AI's prompt (empty when nothing is saved). */
    public static function profileText(int $tid, string $phone): string {
        $p = self::profile($tid, $phone); if (!$p || !(($p['name'] ?? '') . ($p['address'] ?? '') . ($p['city'] ?? ''))) return '';
        $bits = []; foreach (['name' => 'Name', 'address' => 'Address', 'city' => 'Town', 'payment_method' => 'Usual payment'] as $k => $l) if (!empty($p[$k])) $bits[] = "$l: " . str_replace(["\n", '<', '>'], ' ', (string)$p[$k]);
        return implode('; ', $bits) . ($p['orders_count'] ? "; previous orders: {$p['orders_count']}" : '');
    }
    private static function takeStock(int $tid, array $lines): void {
        foreach ($lines as $l) {
            if (!Db::q("UPDATE store_products SET stock = stock - ? WHERE tenant_id=? AND sku=? AND stock >= ? AND is_active=1", [$l['qty'], $tid, $l['sku'], $l['qty']])->rowCount()) {
                $have = (int)Db::val("SELECT stock FROM store_products WHERE tenant_id=? AND sku=?", [$tid, $l['sku']]);
                throw new StoreError("Not enough stock for {$l['name']} (available: $have)", 409);
            }
        }
    }
    private static function returnStock(int $tid, int $orderId): void {
        foreach (Db::all("SELECT sku, qty FROM store_order_items WHERE order_id=?", [$orderId]) as $i) Db::q("UPDATE store_products SET stock = stock + ? WHERE tenant_id=? AND sku=?", [$i['qty'], $tid, $i['sku']]);
    }
    private static function totals(int $tid, array $lines, string $city): array {
        $sub = 0.0; foreach ($lines as $l) $sub += $l['price'] * $l['qty'];
        $d = self::delivery($tid, $city, $sub); return [$sub, $d['fee'], $sub + $d['fee'], $d];
    }
    private static function tx(callable $fn) {
        $pdo = Db::pdo(); $pdo->beginTransaction();
        try { $r = $fn(); $pdo->commit(); return $r; } catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    /**
     * @param array{phone:string,items:string,delivery_city:string,delivery_address:string,payment_method?:string,customer_name?:string,notes?:string} $in
     * @throws StoreError
     */
    public static function createOrder(int $tid, array $in, string $source = 'manual'): array {
        $phone = self::digits((string)($in['phone'] ?? '')); if (strlen($phone) < 9) throw new StoreError('A valid phone number with country code is required', 422);
        $city = trim((string)($in['delivery_city'] ?? '')); $addr = trim((string)($in['delivery_address'] ?? ''));
        if ($city === '' || $addr === '') throw new StoreError('delivery_city and delivery_address are required', 422);
        $s = self::settings($tid); $pay = trim((string)($in['payment_method'] ?? '')) ?: $s['payments'][0];
        if (!in_array($pay, $s['payments'], true)) throw new StoreError('payment_method must be one of: ' . implode(', ', $s['payments']), 422);
        $lines = self::parseItems($tid, (string)($in['items'] ?? ''));
        [$sub, $fee, $total, $d] = self::totals($tid, $lines, $city);
        if ($pay === 'Cash on delivery' && !$d['cod_allowed']) throw new StoreError("Cash on delivery is not available for $city. Choose another payment method.", 422);
        if (in_array($source, ['ai', 'catalogue'], true) && $s['max_total'] > 0 && $total > $s['max_total']) throw new StoreError('This order exceeds the online limit of Rs. ' . number_format($s['max_total']) . '. A team member must place it.', 422);
        $order = self::tx(function () use ($tid, $in, $phone, $city, $addr, $pay, $lines, $sub, $fee, $total, $d, $source) {
            self::takeStock($tid, $lines);
            $next = (int)Db::val("SELECT COALESCE(MAX(CAST(SUBSTRING(order_no, 5) AS UNSIGNED)), 1000) + 1 FROM store_orders WHERE tenant_id=? AND order_no REGEXP '^ORD-[0-9]+$' FOR UPDATE", [$tid]);
            $no = 'ORD-' . max(1001, $next);
            $id = Db::insert('store_orders', ['tenant_id' => $tid, 'order_no' => $no, 'phone' => $phone, 'customer_name' => mb_substr(trim((string)($in['customer_name'] ?? '')) ?: 'Customer', 0, 100), 'status' => 'processing',
                'payment_method' => $pay, 'delivery_city' => mb_substr($city, 0, 60), 'delivery_address' => mb_substr($addr, 0, 200), 'subtotal' => $sub, 'delivery_fee' => $fee, 'total' => $total,
                'eta' => $d['eta'] ?: 'Dispatch in 1-2 days', 'notes' => mb_substr(trim((string)($in['notes'] ?? '')), 0, 300) ?: null, 'source' => $source]);
            foreach ($lines as $l) Db::insert('store_order_items', ['tenant_id' => $tid, 'order_id' => $id, 'sku' => $l['sku'], 'name' => $l['name'], 'size' => $l['size'], 'color' => $l['color'], 'qty' => $l['qty'], 'unit_price' => $l['price']]);
            return Db::one("SELECT * FROM store_orders WHERE id=?", [$id]);
        });
        self::saveProfile($tid, $phone, ['name' => $in['customer_name'] ?? '', 'address' => $addr, 'city' => $city, 'payment_method' => $pay], true);
        Audit::log('store_order_create', 'order:' . $order['order_no'], $source, $tid);
        Automations::emit($tid, 'order_placed', 'order', (int)$order['id']);
        return $order;
    }

    /** Change a processing order. Only provided fields change. @throws StoreError */
    public static function updateOrder(int $tid, array $o, array $in): array {
        if ($o['status'] !== 'processing') throw new StoreError("Order {$o['order_no']} is " . ucfirst($o['status']) . ' and can no longer be changed online. A team member can help.', 409);
        $set = [];
        foreach (['delivery_city' => 60, 'delivery_address' => 200] as $f => $max) if (isset($in[$f]) && trim((string)$in[$f]) !== '') $set[$f] = mb_substr(trim((string)$in[$f]), 0, $max);
        $s = self::settings($tid);
        if (isset($in['payment_method']) && $in['payment_method'] !== '') { if (!in_array($in['payment_method'], $s['payments'], true)) throw new StoreError('payment_method must be one of: ' . implode(', ', $s['payments']), 422); $set['payment_method'] = $in['payment_method']; }
        $lines = isset($in['items']) && trim((string)$in['items']) !== '' ? self::parseItems($tid, (string)$in['items']) : null;
        if (!$set && $lines === null) throw new StoreError('Nothing to update. Provide delivery_city, delivery_address, payment_method or items.', 422);
        self::tx(function () use ($tid, $o, $set, $lines, $s) {
            $cityNow = $set['delivery_city'] ?? $o['delivery_city'];
            if ($lines === null && isset($set['delivery_city'])) { // city changed: recompute delivery from the existing lines
                $sub = (float)$o['subtotal']; $d = self::delivery($tid, $cityNow, $sub); $set += ['delivery_fee' => $d['fee'], 'total' => $sub + $d['fee'], 'eta' => $d['eta'] ?: $o['eta']];
            }
            if ($lines !== null) {
                [$sub, $fee, $total, $d] = self::totals($tid, $lines, $cityNow);
                if (in_array($o['source'] ?? '', ['ai', 'catalogue'], true) && $s['max_total'] > 0 && $total > $s['max_total']) throw new StoreError('This order would exceed the online limit. A team member must change it.', 422);
                self::returnStock($tid, (int)$o['id']); self::takeStock($tid, $lines);
                Db::q("DELETE FROM store_order_items WHERE order_id=?", [$o['id']]);
                foreach ($lines as $l) Db::insert('store_order_items', ['tenant_id' => $tid, 'order_id' => $o['id'], 'sku' => $l['sku'], 'name' => $l['name'], 'size' => $l['size'], 'color' => $l['color'], 'qty' => $l['qty'], 'unit_price' => $l['price']]);
                $set += ['subtotal' => $sub, 'delivery_fee' => $fee, 'total' => $total];
            }
            if (($set['payment_method'] ?? $o['payment_method']) === 'Cash on delivery' && !self::delivery($tid, $set['delivery_city'] ?? $o['delivery_city'])['cod_allowed']) throw new StoreError('Cash on delivery is not available for that town.', 422);
            Db::update('store_orders', $set + ['updated_at' => now()], 'id=? AND tenant_id=?', [$o['id'], $tid]);
        });
        return Db::one("SELECT * FROM store_orders WHERE id=?", [$o['id']]);
    }

    /** Move an order along its life cycle. @throws StoreError */
    public static function setStatus(int $tid, array $o, string $status, array $extra = []): array {
        if (!in_array($status, self::STATUSES, true)) throw new StoreError('Unknown status', 422);
        if ($status !== $o['status'] && !in_array($status, self::NEXT[$o['status']], true)) throw new StoreError("An order that is " . ucfirst($o['status']) . " cannot become " . ucfirst($status) . '.', 409);
        $set = ['status' => $status, 'updated_at' => now()];
        foreach (['courier' => 60, 'tracking' => 60, 'eta' => 80] as $f => $max) if (isset($extra[$f]) && trim((string)$extra[$f]) !== '') $set[$f] = mb_substr(trim((string)$extra[$f]), 0, $max);
        if ($status === 'shipped') $set['shipped_at'] = now();
        if ($status === 'delivered') { $set['delivered_at'] = now(); if (($o['payment_method'] ?? '') === 'Cash on delivery') $set['payment_status'] = 'paid'; }
        if ($status === 'cancelled' && !empty($extra['reason'])) $set['notes'] = mb_substr(trim(($o['notes'] ? $o['notes'] . '; ' : '') . 'cancel reason: ' . $extra['reason']), 0, 300);
        $changed = $status !== $o['status'];
        self::tx(function () use ($tid, $o, $set, $status, $changed) {
            if ($changed && in_array($status, ['cancelled'], true)) self::returnStock($tid, (int)$o['id']);
            Db::update('store_orders', $set, 'id=? AND tenant_id=?', [$o['id'], $tid]);
        });
        if ($changed) Automations::emit($tid, 'order_' . $status, 'order', (int)$o['id']);
        return Db::one("SELECT * FROM store_orders WHERE id=?", [$o['id']]);
    }

    public static function markPaid(int $tid, array $o, string $state = 'paid'): array {
        if (!in_array($state, ['unpaid', 'review', 'paid', 'refunded'], true)) throw new StoreError('Unknown payment status', 422);
        Db::update('store_orders', ['payment_status' => $state, 'updated_at' => now()], 'id=? AND tenant_id=?', [$o['id'], $tid]);
        return Db::one("SELECT * FROM store_orders WHERE id=?", [$o['id']]);
    }

    // ------------------------------------------------------------------ leads (CRM)
    public static function leadView(array $l): array {
        return ['id' => (int)$l['id'], 'name' => $l['name'], 'interest' => $l['interest'], 'preferred_time' => $l['preferred_time'], 'status' => $l['confirmed_at'] ? 'confirmed' : ($l['stage'] === 'lost' ? 'cancelled' : 'new'),
            'stage' => $l['stage'], 'created_at' => $l['created_at'], 'confirmed_at' => $l['confirmed_at']];
    }
    /** @return array{lead:array,duplicate:bool} @throws StoreError */
    public static function createLead(int $tid, array $in, string $source = 'manual'): array {
        $phone = self::digits((string)($in['phone'] ?? '')); if (strlen($phone) < 9) throw new StoreError('A valid phone number with country code is required', 422);
        $name = trim((string)($in['name'] ?? '')); $interest = trim((string)($in['interest'] ?? ''));
        if ($name === '' || $interest === '') throw new StoreError('name and interest are required', 422);
        $dup = Db::one("SELECT * FROM crm_leads WHERE tenant_id=? AND phone=? AND interest=? AND created_at > NOW() - INTERVAL 1 DAY AND stage<>'lost' LIMIT 1", [$tid, $phone, mb_substr($interest, 0, 200)]);
        if ($dup) return ['lead' => $dup, 'duplicate' => true];
        $id = Db::insert('crm_leads', ['tenant_id' => $tid, 'phone' => $phone, 'name' => mb_substr($name, 0, 100), 'interest' => mb_substr($interest, 0, 200), 'preferred_time' => mb_substr(trim((string)($in['preferred_time'] ?? '')), 0, 80) ?: null,
            'notes' => mb_substr(trim((string)($in['notes'] ?? '')), 0, 500) ?: null, 'source' => $source, 'value' => isset($in['value']) && $in['value'] !== '' ? max(0, (float)$in['value']) : null]);
        Audit::log('crm_lead_create', 'lead:' . $id, $source, $tid);
        return ['lead' => Db::one("SELECT * FROM crm_leads WHERE id=?", [$id]), 'duplicate' => false];
    }
    /** The customer confirms their own enquiry. @throws StoreError */
    public static function confirmLead(int $tid, int $id, string $phone): array {
        $l = Db::one("SELECT * FROM crm_leads WHERE id=? AND tenant_id=? AND phone=?", [$id, $tid, self::digits($phone)]);
        if (!$l) throw new StoreError('lead not found', 404);
        if ($l['confirmed_at']) throw new StoreError("Enquiry #{$l['id']} is already confirmed.", 409);
        if ($l['stage'] === 'lost') throw new StoreError("Enquiry #{$l['id']} was closed.", 409);
        Db::update('crm_leads', ['confirmed_at' => now(), 'updated_at' => now()], 'id=?', [$l['id']]);
        return Db::one("SELECT * FROM crm_leads WHERE id=?", [$l['id']]);
    }
    /** @throws StoreError */
    public static function setStage(int $tid, int $id, string $stage, ?string $lostReason = null): void {
        if (!in_array($stage, self::STAGES, true)) throw new StoreError('Unknown stage', 422);
        if (!Db::q("UPDATE crm_leads SET stage=?, lost_reason=?, updated_at=NOW() WHERE id=? AND tenant_id=?", [$stage, $stage === 'lost' ? mb_substr((string)$lostReason, 0, 200) : null, $id, $tid])->rowCount()
            && !Db::val("SELECT id FROM crm_leads WHERE id=? AND tenant_id=?", [$id, $tid])) throw new StoreError('lead not found', 404);
    }
}
