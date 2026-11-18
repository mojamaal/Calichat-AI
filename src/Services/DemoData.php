<?php
declare(strict_types=1);
namespace CaliChat\Services;

use CaliChat\Core\Crypto;
use CaliChat\Core\Db;

/** Demo store helpers: wires the demo tenant to the demo ERP and creates sample orders for a tester's phone number. */
class DemoData {
    public static function digits(string $phone): string { return preg_replace('/\D/', '', preg_replace('/^whatsapp:/i', '', $phone)); }

    /** Create/refresh the demo connector and its tools for a tenant (public demo token, re-encrypted with THIS machine's key). */
    public static function ensureTools(int $tid): void {
        self::installTools($tid, 'Demo ERP', abs_url('mock/erp'), 'demo-erp-token', '[A-Za-z]{2,4}-[0-9]{2,4}', 'Cash on delivery,Card,Bank transfer');
    }

    /**
     * Create/refresh a connector and the standard order/lead/product tools that talk to an ERP with the mock-ERP API shape.
     * Used for the demo ERP and for the built-in store (src/Services/Store.php). Existing tools of the same name are re-pointed.
     * @param array<string,array> $extra additional read tools: name => [description, path, params, response_path, response_fields, cache_ttl]
     */
    public static function installTools(int $tid, string $connName, string $baseUrl, string $token, string $skuRe, string $payments, array $extra = [], bool $withColour = false): void {
        $conn = Db::one("SELECT id FROM connectors WHERE tenant_id=? AND name=? LIMIT 1", [$tid, $connName]);
        $refresh = ['base_url' => $baseUrl, 'auth_type' => 'bearer', 'auth_secret' => Crypto::encrypt($token), 'is_active' => 1];
        if ($conn) Db::update('connectors', $refresh, 'id=?', [$conn['id']]);
        $cid = $conn ? (int)$conn['id'] : Db::insert('connectors', ['tenant_id' => $tid, 'name' => $connName] + $refresh);
        $tools = [
            'search_products' => ['Search the product catalogue by name, colour or category (e.g. "jeans", "sarong", "footwear"). Returns price (LKR), stock quantity, sizes and colours.', '/products',
                [['name' => 'search', 'type' => 'string', 'in' => 'query', 'required' => 1, 'source' => 'ai', 'description' => 'Product name, category or colour keyword', 'max_len' => 60]],
                'data', 'sku,name,category,description,price,currency,stock,in_stock,variants,colors', 60],
            'get_product' => ['Get full details of ONE product by its SKU (for example TS-001) once the customer picks a product.', '/products/{sku}',
                [['name' => 'sku', 'type' => 'string', 'in' => 'path', 'required' => 1, 'source' => 'ai', 'description' => 'Product SKU like TS-001', 'pattern' => '^' . $skuRe . '$']],
                'data', 'sku,name,category,description,price,currency,stock,in_stock,variants,colors', 60],
            'get_order' => ['Look up the status, courier, tracking number, ETA, payment method and items of THIS customer\'s order by order number (e.g. ORD-1001).', '/orders/{order_no}',
                [['name' => 'order_no', 'type' => 'string', 'in' => 'path', 'required' => 1, 'source' => 'ai', 'description' => 'Order number like ORD-1001', 'pattern' => '^ORD-[0-9]{3,8}$'],
                 ['name' => 'phone', 'type' => 'string', 'in' => 'query', 'required' => 1, 'source' => 'contact.phone', 'description' => '']],
                'data', 'order_no,status,courier,tracking,eta,payment_method,delivery_city,subtotal,delivery_fee,total,placed_at,items,name,size,qty,unit_price', 0],
            'list_my_orders' => ['List the most recent orders of THIS customer (use when they do not know the order number, or ask "where are my orders").', '/orders',
                [['name' => 'phone', 'type' => 'string', 'in' => 'query', 'required' => 1, 'source' => 'contact.phone', 'description' => '']],
                'data', 'order_no,status,courier,tracking,eta,total,placed_at,items,name,size,qty', 0],
        ];
        // Write tools: the server forces preview -> customer reply -> confirmed call, so these are safe to leave autonomous.
        $one = $skuRe . ':[A-Za-z0-9-]{1,10}:[1-9][0-9]?' . ($withColour ? '(:[A-Za-z][A-Za-z -]{0,19})?' : '');
        $items = ['name' => 'items', 'type' => 'string', 'in' => 'body', 'source' => 'ai', 'max_len' => 240, 'pattern' => '^' . $one . '(,' . $one . '){0,7}$',
            'description' => $withColour
                ? 'Items as SKU:SIZE:QTY:COLOUR separated by commas, e.g. "TS-001:M:1:Black,SH-221:42:2:White". Use - when the product has no size, e.g. "AC-503:-:1:Khaki". SKU, sizes, colours and stock come from search_products. Include the colour the customer asked for.'
                : 'Items as SKU:SIZE:QTY separated by commas, e.g. "TS-001:M:1,SH-221:42:2". Use - when the product has no size, e.g. "AC-503:-:1". SKU, sizes and stock come from search_products.'];
        $phone = ['name' => 'phone', 'type' => 'string', 'in' => 'body', 'required' => 1, 'source' => 'contact.phone', 'description' => ''];
        $orderFields = 'order_no,status,eta,payment_method,delivery_city,delivery_address,subtotal,delivery_fee,total,items,name,size,qty,unit_price,message,idempotent_replay,duplicate';
        $writes = [
            'create_order' => ['POST', 'Place a NEW order for this customer. Before calling: find the products and sizes with search_products, collect delivery city, delivery address and payment method from the customer. The system shows a preview first; read it back and wait for the customer to confirm.', '/orders',
                [$items + ['required' => 1], ['name' => 'delivery_city', 'type' => 'string', 'in' => 'body', 'required' => 1, 'source' => 'ai', 'max_len' => 60, 'description' => 'Delivery city or town'],
                 ['name' => 'delivery_address', 'type' => 'string', 'in' => 'body', 'required' => 1, 'source' => 'ai', 'max_len' => 150, 'description' => 'Full delivery address'],
                 ['name' => 'payment_method', 'type' => 'string', 'in' => 'body', 'required' => 1, 'source' => 'ai', 'enum' => $payments, 'description' => 'How the customer will pay'],
                 ['name' => 'customer_name', 'type' => 'string', 'in' => 'body', 'source' => 'ai', 'max_len' => 80, 'description' => 'Customer name for the delivery'], $phone], $orderFields],
            'cancel_order' => ['POST', 'Cancel THIS customer\'s order (only possible while Processing or Packed). Ask for the order number and the reason; the system shows a preview first.', '/orders/{order_no}/cancel',
                [['name' => 'order_no', 'type' => 'string', 'in' => 'path', 'required' => 1, 'source' => 'ai', 'pattern' => '^ORD-[0-9]{3,8}$', 'description' => 'Order number like ORD-3001'],
                 ['name' => 'reason', 'type' => 'string', 'in' => 'body', 'source' => 'ai', 'max_len' => 150, 'description' => 'Why the customer is cancelling'], $phone], $orderFields],
            'update_order' => ['PATCH', 'Change THIS customer\'s order while it is still Processing: delivery city, delivery address, payment method, or the items (send the COMPLETE new item list). Provide only what changes; the system shows a preview first.', '/orders/{order_no}',
                [['name' => 'order_no', 'type' => 'string', 'in' => 'path', 'required' => 1, 'source' => 'ai', 'pattern' => '^ORD-[0-9]{3,8}$', 'description' => 'Order number like ORD-3001'],
                 ['name' => 'delivery_city', 'type' => 'string', 'in' => 'body', 'source' => 'ai', 'max_len' => 60, 'description' => 'New delivery city'],
                 ['name' => 'delivery_address', 'type' => 'string', 'in' => 'body', 'source' => 'ai', 'max_len' => 150, 'description' => 'New delivery address'],
                 ['name' => 'payment_method', 'type' => 'string', 'in' => 'body', 'source' => 'ai', 'enum' => $payments, 'description' => 'New payment method'],
                 $items + ['description' => 'COMPLETE replacement item list as SKU:SIZE:QTY,... (only when the customer changes items)'], $phone], $orderFields],
            'create_lead' => ['POST', 'Record a sales enquiry (lead) when a customer wants something a person must arrange: bulk or corporate orders, viewings, quotes, restock alerts, callbacks. Get their name and what they are interested in; the system shows a preview first.', '/leads',
                [['name' => 'name', 'type' => 'string', 'in' => 'body', 'required' => 1, 'source' => 'ai', 'max_len' => 80, 'description' => 'Customer name'],
                 ['name' => 'interest', 'type' => 'string', 'in' => 'body', 'required' => 1, 'source' => 'ai', 'max_len' => 150, 'description' => 'What the customer is interested in'],
                 ['name' => 'preferred_time', 'type' => 'string', 'in' => 'body', 'source' => 'ai', 'max_len' => 60, 'description' => 'When to contact them'],
                 ['name' => 'notes', 'type' => 'string', 'in' => 'body', 'source' => 'ai', 'max_len' => 200, 'description' => 'Any extra details'], $phone], 'id,name,interest,preferred_time,status,message,duplicate,idempotent_replay'],
            'confirm_lead' => ['POST', 'Confirm one of THIS customer\'s open enquiries (leads) so the sales team follows up. Use list_my_leads to find the lead id; the system shows a preview first.', '/leads/{lead_id}/confirm',
                [['name' => 'lead_id', 'type' => 'string', 'in' => 'path', 'required' => 1, 'source' => 'ai', 'pattern' => '^[0-9]{1,8}$', 'description' => 'Lead id number'], $phone], 'id,name,interest,preferred_time,status,confirmed_at,message,idempotent_replay'],
        ];
        $tools['list_my_leads'] = ['List THIS customer\'s recent sales enquiries (leads) and their status.', '/leads', [['name' => 'phone', 'type' => 'string', 'in' => 'query', 'required' => 1, 'source' => 'contact.phone', 'description' => '']], 'data', 'id,name,interest,preferred_time,status,created_at,confirmed_at', 0];
        foreach ($writes as $wn => [$wm, $wd, $wp, $wpar, $wf]) $tools[$wn] = [$wd, $wp, $wpar, '', $wf, 0, $wm]; // no unwrapping: the AI needs the system's message and flags too
        foreach ($extra as $en => $ed) $tools[$en] = $ed;

        foreach ($tools as $name => $def) {
            [$desc, $path, $params, $rp, $fields, $ttl] = $def; $meth = $def[6] ?? 'GET';
            $row = ['connector_id' => $cid, 'description' => $desc, 'method' => $meth, 'path' => $path, 'params' => json_encode($params), 'response_path' => $rp, 'response_fields' => $fields, 'cache_ttl' => $ttl, 'allow_write' => $meth === 'GET' ? 0 : 1, 'no_confirm' => (int)($def[7] ?? 0), 'is_active' => 1];
            $ex = Db::one("SELECT id FROM tools WHERE tenant_id=? AND name=?", [$tid, $name]);
            if ($ex) Db::update('tools', $row, 'id=? AND tenant_id=?', [$ex['id'], $tid]);
            else Db::insert('tools', $row + ['tenant_id' => $tid, 'name' => $name]);
        }
    }

    /** Three sample orders (shipped, delivered, processing) for a phone number; safe to run again. @return string[] order numbers */
    public static function seedPhone(string $phone, string $name = 'Test Customer'): array {
        $phone = self::digits($phone);
        if (strlen($phone) < 9) throw new \InvalidArgumentException('Enter the phone number with country code, e.g. 94771234567');
        $existing = Db::all("SELECT order_no FROM demo_orders WHERE phone=? AND internal_cost_note=? ORDER BY order_no", [$phone, 'seed:' . $phone]);
        if ($existing) return array_column($existing, 'order_no');
        $next = (int)Db::val("SELECT COALESCE(MAX(CAST(SUBSTRING(order_no, 5) AS UNSIGNED)), 2000) FROM demo_orders WHERE order_no REGEXP '^ORD-[0-9]+$' AND CAST(SUBSTRING(order_no, 5) AS UNSIGNED) >= 2000");
        $plans = [
            ['Shipped', 'Domex', 'DX', 'Arrives in 2 days', [['TS-002', 'M', 1], ['AC-503', null, 1]], 2],
            ['Delivered', 'Pronto', 'PR', 'Delivered 5 days ago', [['JN-015', '32', 1], ['SH-222', '42', 1]], 7],
            ['Processing', null, null, 'Dispatch in 1-2 days', [['DR-301', 'M', 1]], 0],
        ];
        $made = [];
        foreach ($plans as [$status, $courier, $pfx, $eta, $items, $daysAgo]) {
            $no = 'ORD-' . (++$next); $sub = 0.0; $lines = [];
            foreach ($items as [$sku, $size, $qty]) {
                $p = Db::one("SELECT sku, name, price FROM demo_products WHERE sku=?", [$sku]); if (!$p) continue;
                $sub += (float)$p['price'] * $qty; $lines[] = [$p['sku'], $p['name'], $size, $qty, $p['price']];
            }
            $fee = $sub >= 10000 ? 0 : 350;
            Db::insert('demo_orders', ['order_no' => $no, 'phone' => $phone, 'customer_name' => mb_substr($name, 0, 100), 'status' => $status, 'courier' => $courier, 'tracking' => $pfx ? $pfx . random_int(100000, 999999) : null,
                'eta' => $eta, 'payment_method' => 'Cash on delivery', 'delivery_city' => 'Colombo', 'subtotal' => $sub, 'delivery_fee' => $fee, 'total' => $sub + $fee, 'internal_cost_note' => 'seed:' . $phone, 'created_at' => gmdate('Y-m-d H:i:s', time() - $daysAgo * 86400 - 600)]);
            foreach ($lines as [$sku, $nm, $size, $qty, $price]) Db::insert('demo_order_items', ['order_no' => $no, 'sku' => $sku, 'name' => $nm, 'size' => $size, 'qty' => $qty, 'unit_price' => $price]);
            $made[] = $no;
        }
        return $made;
    }
}
