<?php
declare(strict_types=1);
namespace CaliChat\Services;

use CaliChat\Core\Crypto;
use CaliChat\Core\Db;
use CaliChat\Core\Log;
use CaliChat\Core\Net;

/**
 * Pull the product catalogue (names, prices, stock, sizes, colours, photos) from a WooCommerce or Shopify store into the built-in Store.
 * One-way and read-only: nothing is ever written back to the other shop. Existing SKUs are updated, new ones added, nothing is deleted.
 */
class Importers {
    private const MAX_PRODUCTS = 600;

    public static function baseUrl(string $type, string $base): ?string {
        $base = trim($base);
        if ($type === 'shopify') { $b = preg_replace('#^https?://#i', '', rtrim($base, '/')); if (Meta::allowPrivate() && preg_match('#^http://[\w.:\-]+$#i', $base)) return rtrim($base, '/'); return preg_match('/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/i', $b) ? 'https://' . strtolower($b) : null; }
        if (Meta::allowPrivate() && preg_match('#^http://[\w.:\-]+(/[\w.\-/]*)?$#i', $base)) return rtrim($base, '/');
        return preg_match('#^https://[\w.\-]+(:\d+)?(/[\w.\-/]*)?$#i', $base) ? rtrim($base, '/') : null;
    }

    /** @return array{ok:bool,added:int,error:?string} */
    public static function sync(int $tid, int $id): array {
        $src = Db::one("SELECT * FROM store_sources WHERE id=? AND tenant_id=?", [$id, $tid]);
        if (!$src) return ['ok' => false, 'added' => 0, 'error' => 'Source not found'];
        $cred = json_decode((string)Crypto::decrypt($src['credentials']), true) ?: [];
        $res = ['ok' => false, 'added' => 0, 'error' => null];
        try {
            $items = $src['type'] === 'woocommerce' ? self::fetchWoo((string)$src['base_url'], $cred) : self::fetchShopify((string)$src['base_url'], $cred);
            $errs = 0;
            foreach ($items as $p) { try { Store::saveProduct($tid, $p); $res['added']++; } catch (StoreError $e) { $errs++; } }
            $res['ok'] = true; $res['error'] = $errs ? "$errs product(s) skipped because of invalid data" : null;
        } catch (\Throwable $e) { $res['error'] = mb_substr($e->getMessage(), 0, 250); if (!($e instanceof \RuntimeException)) Log::exception($e, 'import_failed'); }
        Db::update('store_sources', ['last_sync_at' => now(), 'last_status' => $res['ok'] ? ('Imported ' . $res['added'] . ' product(s)' . ($res['error'] ? '. ' . $res['error'] : '')) : 'Failed: ' . $res['error'], 'last_count' => $res['added']], 'id=?', [$id]);
        return $res;
    }

    /** Every 6 hours for sources with auto-sync switched on. */
    public static function syncAll(): int {
        $n = 0;
        foreach (Db::all("SELECT id, tenant_id FROM store_sources WHERE auto_sync=1 AND (last_sync_at IS NULL OR last_sync_at < NOW() - INTERVAL 6 HOUR)") as $s) { $r = self::sync((int)$s['tenant_id'], (int)$s['id']); if ($r['ok']) $n += $r['added']; }
        return $n;
    }

    private static function get(string $url, array $headers): array {
        $r = Net::request('GET', $url, $headers + ['Accept' => 'Accept: application/json'], ['timeout' => 25, 'allow_private' => Meta::allowPrivate(), 'max_bytes' => 6 * 1048576]);
        if ($r['status'] === 401 || $r['status'] === 403) throw new \RuntimeException('The shop refused the keys (HTTP ' . $r['status'] . '). Check the key, secret or token and its read permission.');
        if ($r['status'] !== 200) throw new \RuntimeException($r['error'] ?: 'The shop answered HTTP ' . $r['status']);
        $j = json_decode($r['body'], true);
        if (!is_array($j)) throw new \RuntimeException('The shop did not return product data.');
        return $j;
    }
    private static function plain(?string $html, int $max = 500): string { return mb_substr(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$html), ENT_QUOTES, 'UTF-8'))), 0, $max); }
    private static function opts(array $values): string { $o = []; foreach ($values as $v) { $v = trim((string)$v); if ($v !== '' && preg_match('/^[A-Za-z0-9 -]{1,10}$/', $v)) $o[] = $v; } return implode(',', array_slice(array_unique($o), 0, 12)); }

    /** @return array<int,array> products in Store::saveProduct format */
    private static function fetchWoo(string $base, array $c): array {
        if (empty($c['key']) || empty($c['secret'])) throw new \RuntimeException('WooCommerce needs a consumer key and secret.');
        $out = [];
        for ($page = 1; $page <= 6 && count($out) < self::MAX_PRODUCTS; $page++) {
            $rows = self::get($base . '/wp-json/wc/v3/products?per_page=100&status=publish&page=' . $page, ['Authorization' => 'Authorization: Basic ' . base64_encode($c['key'] . ':' . $c['secret'])]);
            foreach ($rows as $p) {
                if (!is_array($p) || empty($p['name'])) continue;
                $size = $colour = [];
                foreach ((array)($p['attributes'] ?? []) as $a) { $n = strtolower((string)($a['name'] ?? '')); if (preg_match('/size/', $n)) $size = (array)($a['options'] ?? []); elseif (preg_match('/colou?r/', $n)) $colour = (array)($a['options'] ?? []); }
                $stock = isset($p['stock_quantity']) && $p['stock_quantity'] !== null ? (int)$p['stock_quantity'] : (($p['stock_status'] ?? 'instock') === 'outofstock' ? 0 : 100);
                $out[] = ['sku' => strtoupper(preg_replace('/[^A-Za-z0-9-]/', '-', (string)($p['sku'] ?? '') ?: 'WC-' . $p['id'])), 'name' => (string)$p['name'], 'category' => (string)($p['categories'][0]['name'] ?? ''), 'description' => self::plain($p['short_description'] ?? $p['description'] ?? ''),
                    'price' => (float)($p['price'] !== '' && isset($p['price']) ? $p['price'] : ($p['regular_price'] ?? 0)), 'stock' => $stock, 'variants' => self::opts($size), 'colors' => self::opts($colour), 'image_url' => (string)($p['images'][0]['src'] ?? ''), 'is_active' => 1];
            }
            if (count($rows) < 100) break;
        }
        return $out;
    }

    private static function fetchShopify(string $base, array $c): array {
        if (empty($c['token'])) throw new \RuntimeException('Shopify needs an Admin API access token.');
        $j = self::get($base . '/admin/api/2024-10/products.json?limit=250&status=active', ['X-Shopify-Access-Token' => 'X-Shopify-Access-Token: ' . $c['token']]);
        $out = [];
        foreach (array_slice($j['products'] ?? [], 0, self::MAX_PRODUCTS) as $p) {
            if (empty($p['title'])) continue;
            $v = (array)($p['variants'] ?? []); $first = $v[0] ?? [];
            $size = $colour = [];
            foreach ((array)($p['options'] ?? []) as $o) { $n = strtolower((string)($o['name'] ?? '')); if (preg_match('/size/', $n)) $size = (array)($o['values'] ?? []); elseif (preg_match('/colou?r/', $n)) $colour = (array)($o['values'] ?? []); }
            $stock = 0; foreach ($v as $x) $stock += max(0, (int)($x['inventory_quantity'] ?? 0));
            $out[] = ['sku' => strtoupper(preg_replace('/[^A-Za-z0-9-]/', '-', (string)($first['sku'] ?? '') ?: 'SH-' . $p['id'])), 'name' => (string)$p['title'], 'category' => (string)($p['product_type'] ?? ''), 'description' => self::plain($p['body_html'] ?? ''),
                'price' => (float)($first['price'] ?? 0), 'stock' => $stock, 'variants' => self::opts($size), 'colors' => self::opts($colour), 'image_url' => (string)($p['image']['src'] ?? ($p['images'][0]['src'] ?? '')), 'is_active' => 1];
        }
        return $out;
    }
}
