<?php
declare(strict_types=1);
namespace CaliChat\Services;

use CaliChat\Core\Db;
use CaliChat\Core\Log;

/**
 * Product cards and the branded web catalogue ("shop") a customer opens from WhatsApp:
 *  - signed, customer-bound links (no login; expires)
 *  - the product list shaped by the business's design settings
 *  - quote and place an order from a cart (all rules stay in Store)
 *  - sending a product card / the catalogue link on the customer's channel
 */
class Catalogue {
    public const LINK_DAYS = 7;

    // ------------------------------------------------------------------ signed links
    private static function sig(int $tid, string $phone, int $exp): string { return substr(rtrim(strtr(base64_encode(hash_hmac('sha256', 'shop|' . $tid . '|' . $phone . '|' . $exp, (string)env('APP_KEY', 'dev'), true)), '+/', '-_'), '='), 0, 24); }
    private static function query(?string $category, ?string $sku): string { $q = array_filter(['c' => $category, 'p' => $sku ? strtoupper($sku) : null], fn($v) => $v !== null && $v !== ''); return $q ? '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) : ''; }
    public static function link(int $tid, string $phone, ?string $category = null, int $ttl = 0, ?string $sku = null): string {
        $phone = Store::digits($phone); $exp = time() + ($ttl ?: self::LINK_DAYS * 86400);
        return abs_url('shop/' . $tid . '/' . $phone . '/' . $exp . '/' . self::sig($tid, $phone, $exp)) . self::query($category, $sku);
    }
    /** Owners' preview link (phone segment "preview": browsing works, ordering is switched off). */
    public static function previewLink(int $tid, ?string $category = null, ?string $sku = null): string { $exp = time() + 3600; return abs_url('shop/' . $tid . '/preview/' . $exp . '/' . self::sig($tid, 'preview', $exp)) . self::query($category, $sku); }

    // ------------------------------------------------------------------ in-chat cards (what the dashboard chats draw)
    /** The data a chat draws for one product card. Paths are relative to the app so they work on whatever host the dashboard is opened on. */
    public static function richProduct(int $tid, array $r): array {
        $p = self::view($r); $b = Brand::get($tid);
        return ['type' => 'product', 'sku' => $p['sku'], 'name' => $p['name'], 'price' => $p['price'], 'stock' => $p['stock'], 'category' => $p['category'], 'sizes' => $p['sizes'], 'colors' => $p['colors'], 'brand' => $b['name'], 'color' => $b['brand_color'], 'logo' => Brand::logoPathRel($tid), 'photo' => Brand::photoPathRel($tid, $r)];
    }
    public static function richCatalogue(int $tid, ?string $category): array {
        $b = Brand::get($tid); $rows = Db::all("SELECT * FROM store_products WHERE tenant_id=? AND is_active=1" . ($category ? " AND category=?" : '') . " ORDER BY id DESC LIMIT 200", $category ? [$tid, $category] : [$tid]);
        $rows = array_values(array_filter($rows, fn($r) => !in_array($r['category'] ?: 'Other', $b['hidden_categories'], true)));
        $items = array_map(fn($r) => ['name' => $r['name'], 'price' => (float)$r['price'], 'photo' => Brand::photoPathRel($tid, $r)], array_slice($rows, 0, 4));
        return ['type' => 'catalogue', 'title' => $b['cat_title'] !== '' ? $b['cat_title'] : 'Our catalogue', 'intro' => $b['cat_intro'], 'category' => $category ?: '', 'count' => count($rows), 'items' => $items, 'brand' => $b['name'], 'tagline' => $b['brand_tagline'], 'color' => $b['brand_color'], 'logo' => Brand::logoPathRel($tid)];
    }
    public static function valid(int $tid, string $phone, int $exp, string $sig): bool { return $exp > time() && $exp < time() + 400 * 86400 && hash_equals(self::sig($tid, $phone, $exp), $sig); }

    // ------------------------------------------------------------------ data
    /** The product as a customer may see it (never the cost price). */
    public static function view(array $r): array {
        return ['sku' => $r['sku'], 'name' => $r['name'], 'category' => $r['category'] ?: 'Other', 'description' => (string)$r['description'], 'price' => (float)$r['price'], 'stock' => (int)$r['stock'],
            'sizes' => $r['variants'] ? array_values(array_filter(array_map('trim', explode(',', $r['variants'])), 'strlen')) : [], 'colors' => $r['colors'] ? array_values(array_filter(array_map('trim', explode(',', $r['colors'])), 'strlen')) : [], 'image' => self::imageUrl((string)($r['image_url'] ?? ''))];
    }
    /** A photo link as the customer's browser / WhatsApp must load it: built-in pictures become full URLs on this site. */
    public static function imageUrl(string $u): string { return ProductArt::isArtUrl($u) ? abs_url($u) . '?v=2' : $u; }
    /** @return array<int,array> products in the order the business designed (featured first), hidden categories removed */
    public static function products(int $tid, array $b, ?string $q = null): array {
        $rows = Db::all("SELECT * FROM store_products WHERE tenant_id=? AND is_active=1 ORDER BY id DESC LIMIT 500", [$tid]);
        $out = [];
        foreach ($rows as $r) {
            $cat = $r['category'] ?: 'Other'; if (in_array($cat, $b['hidden_categories'], true)) continue;
            if ($q !== null && $q !== '') { $hay = mb_strtolower($r['name'] . ' ' . $cat . ' ' . $r['sku'] . ' ' . $r['description'] . ' ' . $r['colors']); $ok = true; foreach (preg_split('/\s+/u', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) as $w) if (mb_strpos($hay, $w) === false) $ok = false; if (!$ok) continue; }
            $out[] = self::view($r) + ['_id' => (int)$r['id']];
        }
        $sort = $b['cat_sort'];
        usort($out, function ($a, $c) use ($sort, $b) {
            $fa = array_search($a['sku'], $b['featured'], true); $fc = array_search($c['sku'], $b['featured'], true);
            if ($fa !== false || $fc !== false) { if ($fa === false) return 1; if ($fc === false) return -1; return $fa <=> $fc; }
            return $sort === 'price_asc' ? $a['price'] <=> $c['price'] : ($sort === 'price_desc' ? $c['price'] <=> $a['price'] : ($sort === 'newest' ? $c['_id'] <=> $a['_id'] : strcasecmp($a['name'], $c['name'])));
        });
        return array_map(function ($p) { unset($p['_id']); return $p; }, $out);
    }
    public static function categories(array $products): array { return array_values(array_unique(array_column($products, 'category'))); }

    // ------------------------------------------------------------------ cart -> quote / order
    /** Cart lines [{sku,size,color,qty}] -> the items string Store understands. @throws StoreError */
    public static function itemsString(array $cart): string {
        $parts = [];
        foreach (array_slice($cart, 0, 12) as $l) {
            $sku = strtoupper(trim((string)($l['sku'] ?? ''))); $size = trim((string)($l['size'] ?? '')); $col = trim((string)($l['color'] ?? '')); $qty = (int)($l['qty'] ?? 0);
            if ($qty < 1) continue; if ($qty > 99) throw new StoreError('Quantity must be between 1 and 99.', 422);
            if (!preg_match('/^[A-Z0-9-]{2,30}$/', $sku)) throw new StoreError('Unknown product in the cart.', 422);
            if ($size !== '' && !preg_match('/^[A-Za-z0-9-]{1,10}$/', $size)) throw new StoreError('Invalid size.', 422);
            if ($col !== '' && !preg_match('/^[A-Za-z][A-Za-z -]{0,19}$/', $col)) throw new StoreError('Invalid colour.', 422);
            $parts[] = $sku . ':' . ($size !== '' ? $size : '-') . ':' . $qty . ($col !== '' ? ':' . $col : '');
        }
        return implode(',', $parts);
    }

    /** Live totals for the checkout form (delivery fee included). @return array{lines:array,subtotal:float,delivery_fee:float,total:float,eta:?string,problems:string[],cod_ok:bool,free_over:float} */
    public static function quote(int $tid, array $cart, string $city, string $payment = ''): array {
        $items = self::itemsString($cart);
        $c = Store::check($tid, ['items' => $items, 'delivery_city' => $city, 'delivery_address' => 'x', 'customer_name' => 'x', 'payment_method' => $payment]);
        $d = Store::delivery($tid, $city, (float)$c['subtotal']);
        $img = []; foreach ($c['lines'] as $l) { $img[$l['sku']] = (string)Db::val("SELECT image_url FROM store_products WHERE tenant_id=? AND sku=?", [$tid, $l['sku']]); }
        foreach ($c['lines'] as &$l) $l['image'] = $img[$l['sku']] ?? ''; unset($l);
        $problems = array_values(array_filter($c['problems'], fn($p) => stripos($p, 'payment method must') === false || $payment !== ''));
        return ['lines' => $c['lines'], 'subtotal' => $c['subtotal'], 'delivery_fee' => $c['delivery_fee'], 'total' => $c['total'], 'eta' => $c['eta'], 'problems' => $problems, 'cod_ok' => $d['cod_allowed'], 'free_over' => $d['free_over'], 'city_known' => $d['known']];
    }

    /**
     * Place the order the customer confirmed on the catalogue page. The click on "Confirm order" is the customer's own confirmation.
     * @return array the order row @throws StoreError
     */
    public static function place(int $tid, string $phone, array $in): array {
        $cart = (array)($in['cart'] ?? []); $name = trim((string)($in['name'] ?? '')); $city = trim((string)($in['city'] ?? '')); $addr = trim((string)($in['address'] ?? '')); $pay = trim((string)($in['payment'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) throw new StoreError('Please enter your name.', 422);
        if ($city === '') throw new StoreError('Please enter your delivery town or city.', 422);
        if (mb_strlen($addr) < 5) throw new StoreError('Please enter your full delivery address.', 422);
        if ($pay === '') throw new StoreError('Please choose how you will pay.', 422);
        $items = self::itemsString($cart); if ($items === '') throw new StoreError('Your cart is empty.', 422);
        $nonce = preg_replace('/[^A-Za-z0-9]/', '', (string)($in['nonce'] ?? '')); $key = $nonce !== '' ? hash('sha256', 'shop|' . $phone . '|' . $nonce) : null;
        if ($key) { $prev = Db::val("SELECT response FROM store_idempotency WHERE tenant_id=? AND idem_key=?", [$tid, $key]); if ($prev !== null) { $o = Store::order($tid, (string)json_decode($prev, true)); if ($o) return $o + ['_replay' => true]; } } // a double tap must not place two orders
        $o = Store::createOrder($tid, ['phone' => $phone, 'items' => $items, 'delivery_city' => $city, 'delivery_address' => $addr, 'payment_method' => $pay, 'customer_name' => $name, 'notes' => 'Placed from the catalogue page'], 'catalogue');
        if ($key) Db::q("INSERT IGNORE INTO store_idempotency (tenant_id, idem_key, response) VALUES (?,?,?)", [$tid, $key, json_encode($o['order_no'])]);
        try { OrderFlow::tellCustomer($tid, $o); } catch (\Throwable $e) { Log::exception($e, 'catalogue_order_notify_failed'); }
        return $o;
    }

    /** What a tap on a card button or list row means, in words the AI understands. */
    public static function tapText(int $tid, string $replyId, string $text): string {
        if (strpos($replyId, 'add:') === 0) {
            $p = Db::one("SELECT sku, name FROM store_products WHERE tenant_id=? AND sku=?", [$tid, strtoupper(substr($replyId, 4))]);
            return $p ? 'Add to order: ' . $p['name'] . ' (' . $p['sku'] . ')' : $text;
        }
        if ($replyId === 'more_products') return 'Show me more products';
        if (preg_match('/^[A-Za-z0-9-]{2,30}$/', $replyId) && ($p = Db::one("SELECT sku, name FROM store_products WHERE tenant_id=? AND sku=? AND is_active=1", [$tid, strtoupper($replyId)]))) return 'I am interested in: ' . $p['name'] . ' (' . $p['sku'] . ')'; // a row picked from the product list
        return $text;
    }

    // ------------------------------------------------------------------ sending on the customer's channel
    private static function channelFor(int $tid, array $contact): ?array {
        return !empty($contact['channel_id']) ? Db::one("SELECT * FROM channels WHERE id=? AND tenant_id=?", [$contact['channel_id'], $tid]) : null;
    }
    private static function clip(string $s, int $n): string { $s = trim(preg_replace('/\s+/u', ' ', $s)); return mb_strlen($s) > $n ? rtrim(mb_substr($s, 0, $n - 1)) . '…' : $s; }

    /** Caption/body text of a product card (also used as the text fallback). */
    public static function cardText(array $p, array $b, bool $withOptions = true): string {
        $t = '*' . $p['name'] . "*\nRs. " . number_format($p['price']);
        if ($withOptions && $p['sizes']) $t .= "\nSizes: " . implode(', ', $p['sizes']);
        if ($withOptions && $p['colors']) $t .= "\nColours: " . implode(', ', $p['colors']);
        $t .= "\n" . ($p['stock'] <= 0 ? 'Sold out' : ($p['stock'] <= 5 ? 'Only ' . $p['stock'] . ' left' : 'In stock'));
        if ($p['description'] !== '') $t .= "\n\n" . self::clip($p['description'], 220);
        return mb_substr($t, 0, 1000);
    }

    /**
     * Send one product as a branded card on a Meta WhatsApp chat: image (logo + business name + photo + price) with Add to order / More products buttons.
     * @return array{status:string,note:string,product?:array,catalogue_url?:string}
     */
    public static function sendProductCard(int $tid, array $contact, string $sku, bool $isTest, int $convId): array {
        $r = Db::one("SELECT * FROM store_products WHERE tenant_id=? AND sku=? AND is_active=1", [$tid, strtoupper($sku)]);
        if (!$r) return ['status' => 'not_found', 'note' => 'No such product. Use search_products first and pass the SKU it returns.'];
        $p = self::view($r); $b = Brand::get($tid); $ch = self::channelFor($tid, $contact); $phone = (string)($contact['phone'] ?? $contact['external_id'] ?? '');
        $link = self::link($tid, $phone ?: 'preview', $p['category']);
        $rich = self::richProduct($tid, $r);
        if ($isTest) { // the dashboard Playground draws the card itself, inside the chat
            Chat::addMessage($tid, $convId, 'out', 'system', '[Product card] ' . $p['name'] . ' - Rs. ' . number_format($p['price']), ['card' => $p['sku'], 'rich' => $rich]);
            return ['status' => 'sent', 'rich' => $rich, 'note' => 'The product card is shown to the customer in the chat with an Add to order button. Do NOT repeat its details; add at most one short friendly line.'];
        }
        if (!$ch || $ch['type'] !== 'whatsapp') return ['status' => 'not_sent', 'note' => 'Cards can only be shown on Meta WhatsApp chats. Describe the product in your own words using this data and offer the catalogue link.', 'product' => array_diff_key($p, ['image' => 1]), 'catalogue_url' => $link];
        $img = $p['image'] !== '' || Brand::canDraw($tid, $r) ? (Brand::canDraw($tid, $r) ? Brand::cardUrl($tid, $p['sku'], $r) : $p['image']) : null;
        $buttons = $p['stock'] > 0 ? [['id' => 'add:' . $p['sku'], 'title' => 'Add to order'], ['id' => 'more_products', 'title' => 'More products']] : [['id' => 'more_products', 'title' => 'More products']];
        $d = Meta::sendButtons($ch, (string)$contact['external_id'], self::cardText($p, $b), $buttons, $img, $b['name']);
        if (!$d['ok'] && $img) $d = Meta::sendButtons($ch, (string)$contact['external_id'], self::cardText($p, $b), $buttons, null, $b['name']); // Meta could not fetch the image: still send the card text
        if (!$d['ok']) return ['status' => 'not_sent', 'note' => 'The card could not be sent. Describe the product in words instead.', 'product' => array_diff_key($p, ['image' => 1]), 'catalogue_url' => $link];
        Chat::addMessage($tid, $convId, 'out', 'system', '[Product card] ' . $p['name'] . ' - Rs. ' . number_format($p['price']), ['card' => $p['sku'], 'rich' => $rich], $img ? 'image' : null);
        return ['status' => 'sent', 'rich' => $rich, 'note' => 'The product card was delivered to the customer with Add to order / More products buttons. Do NOT repeat its details; add at most one short friendly line.'];
    }

    /** Send the catalogue link (a branded "View catalogue" button on WhatsApp, a plain link elsewhere). */
    public static function sendCatalogue(int $tid, array $contact, ?string $category, bool $isTest, int $convId): array {
        $b = Brand::get($tid); $phone = (string)($contact['phone'] ?? $contact['external_id'] ?? ''); $ch = self::channelFor($tid, $contact);
        $n = (int)Db::val("SELECT COUNT(*) FROM store_products WHERE tenant_id=? AND is_active=1", [$tid]);
        if ($n === 0) return ['status' => 'empty', 'note' => 'The store has no products yet. Offer a human instead.'];
        if ($category !== null && $category !== '' && !Db::val("SELECT id FROM store_products WHERE tenant_id=? AND is_active=1 AND category=? LIMIT 1", [$tid, $category])) $category = null;
        $url = self::link($tid, $phone ?: 'preview', $category ?: null);
        $title = $b['cat_title'] !== '' ? $b['cat_title'] : 'Our catalogue';
        $body = $title . ($category ? ' - ' . $category : '') . "\n" . ($b['cat_intro'] !== '' ? $b['cat_intro'] . "\n\n" : "\n") . "Browse everything, pick sizes and quantities, change or remove items, and place your order. This link is private to you and valid for " . self::LINK_DAYS . ' days.';
        $rich = self::richCatalogue($tid, $category ?: null);
        if ($isTest) { // the dashboard Playground draws the catalogue card itself; clicking it opens the catalogue in a modal
            Chat::addMessage($tid, $convId, 'out', 'system', '[Catalogue sent] ' . $title . ($category ? ' - ' . $category : ''), ['catalogue' => $category ?: 'all', 'rich' => $rich]);
            return ['status' => 'sent', 'rich' => $rich, 'note' => 'The catalogue card is shown in the chat; the customer opens it to browse, choose sizes and quantities, remove items and order. Do NOT paste a link; add one short friendly line.'];
        }
        if ($ch && $ch['type'] === 'whatsapp') {
            $d = Meta::sendCtaUrl($ch, (string)$contact['external_id'], $body, 'View catalogue', $url, $b['has_logo'] ? $b['logo_url'] : null, $b['name']);
            if (!$d['ok'] && $b['has_logo']) $d = Meta::sendCtaUrl($ch, (string)$contact['external_id'], $body, 'View catalogue', $url, null, $b['name']);
            if ($d['ok']) { Chat::addMessage($tid, $convId, 'out', 'system', '[Catalogue sent] ' . $title . ($category ? ' - ' . $category : ''), ['catalogue' => $category ?: 'all', 'rich' => $rich]); return ['status' => 'sent', 'rich' => $rich, 'note' => 'The branded catalogue button was delivered. Do NOT paste the link again; add one short friendly line (the customer can add items and quantities there).']; }
        }
        return ['status' => 'link', 'url' => $url, 'note' => 'Give the customer this private catalogue link in your reply (plain text, on its own line) and say they can browse, add items with quantities, remove items and place the order there.'];
    }
}
