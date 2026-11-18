<?php
declare(strict_types=1);
namespace CaliChat\Services;

use CaliChat\Core\Db;
use CaliChat\Core\Net;
use CaliChat\Core\Tenant;

/**
 * A business's public face: name, logo, colour, and the look of its catalogue. Stored in the tenant settings (no extra tables).
 * Also draws the branded product card image that is sent on WhatsApp (logo + business name + photo + price).
 */
class Brand {
    public const LAYOUTS = ['list' => 'Product list (like the WhatsApp catalog)', 'grid' => 'Two-column tiles'];
    public const THEMES = ['light' => 'Light', 'dark' => 'Dark', 'brand' => 'Brand colour header'];
    public const SORTS = ['name' => 'Name A-Z', 'price_asc' => 'Price, low to high', 'price_desc' => 'Price, high to low', 'newest' => 'Newest first'];
    public const CORNERS = ['round' => 'Rounded', 'soft' => 'Slightly rounded', 'square' => 'Square'];

    public static function defaults(): array {
        return ['brand_name' => '', 'brand_tagline' => '', 'brand_color' => '#5B5BF0', 'brand_phone' => '', 'brand_address' => '', 'brand_logo_v' => 0,
            'cat_layout' => 'list', 'cat_theme' => 'light', 'cat_corner' => 'round', 'cat_columns' => 3, 'cat_title' => '', 'cat_intro' => '', 'cat_button' => 'Add to order', 'cat_footer' => '',
            'cat_show_stock' => 1, 'cat_show_desc' => 1, 'cat_show_sku' => 0, 'cat_sort' => 'name', 'cat_featured' => '', 'cat_hide_categories' => ''];
    }

    /** @return array<string,mixed> all brand + catalogue settings with defaults, plus name (display), logo_url and hide list */
    public static function get(int $tid, array $override = []): array {
        $t = Tenant::get($tid) ?: ['name' => 'Our store', 'cfg' => []]; $cfg = array_merge($t['cfg'] ?? [], $override); // $override: unsaved design values (live preview)
        $b = array_merge(self::defaults(), array_intersect_key($cfg, self::defaults()));
        $b['name'] = trim((string)$b['brand_name']) !== '' ? trim((string)$b['brand_name']) : (string)$t['name'];
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$b['brand_color'])) $b['brand_color'] = '#5B5BF0';
        $b['has_logo'] = is_file(self::logoPath($tid));
        $b['logo_url'] = $b['has_logo'] ? abs_url('media/logo/' . $tid) . '?v=' . (int)$b['brand_logo_v'] : '';
        $b['cat_columns'] = max(2, min(4, (int)$b['cat_columns']));
        foreach (['cat_layout' => self::LAYOUTS, 'cat_theme' => self::THEMES, 'cat_sort' => self::SORTS, 'cat_corner' => self::CORNERS] as $k => $set) if (!isset($set[$b[$k]])) $b[$k] = self::defaults()[$k];
        $b['hidden_categories'] = array_values(array_filter(array_map('trim', explode(',', (string)$b['cat_hide_categories'])), 'strlen'));
        $b['featured'] = array_values(array_filter(array_map(fn($s) => strtoupper(trim($s)), explode(',', (string)$b['cat_featured'])), 'strlen'));
        return $b;
    }

    /** Validate and save the brand + catalogue design from a form. Unknown or invalid values fall back to the defaults. */
    public static function save(int $tid, array $in): void {
        $p = self::sanitize($in);
        if ($p) Tenant::saveSettings($tid, $p);
    }

    /** @return array<string,mixed> only the valid settings found in $in */
    public static function sanitize(array $in): array {
        foreach ($in as $k => $v) if (is_array($v) && $k !== 'cat_hide_categories') unset($in[$k]); // junk such as d[cat_layout][]=x is ignored, never an error
        $d = self::defaults(); $clean = fn($v, int $max) => mb_substr(trim(preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/u', ' ', (string)$v)), 0, $max);
        $p = [];
        foreach (['brand_name' => 80, 'brand_tagline' => 120, 'brand_phone' => 40, 'brand_address' => 200, 'cat_title' => 80, 'cat_intro' => 300, 'cat_button' => 24, 'cat_footer' => 200] as $k => $max) if (array_key_exists($k, $in)) $p[$k] = $clean($in[$k], $max);
        if (isset($p['cat_button']) && $p['cat_button'] === '') $p['cat_button'] = $d['cat_button'];
        if (isset($in['brand_color'])) $p['brand_color'] = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$in['brand_color']) ? strtoupper((string)$in['brand_color']) : $d['brand_color'];
        foreach (['cat_layout' => self::LAYOUTS, 'cat_theme' => self::THEMES, 'cat_sort' => self::SORTS, 'cat_corner' => self::CORNERS] as $k => $set) if (isset($in[$k])) $p[$k] = isset($set[$in[$k]]) ? $in[$k] : $d[$k];
        if (isset($in['cat_columns'])) $p['cat_columns'] = max(2, min(4, (int)$in['cat_columns']));
        if (array_key_exists('_catalogue_form', $in)) foreach (['cat_show_stock', 'cat_show_desc', 'cat_show_sku'] as $k) $p[$k] = empty($in[$k]) ? 0 : 1; // unticked boxes are absent from a form post
        if (isset($in['cat_featured'])) $p['cat_featured'] = mb_substr(implode(',', array_slice(array_values(array_unique(array_filter(array_map(fn($s) => strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', $s)), explode(',', (string)$in['cat_featured'])), 'strlen'))), 0, 30)), 0, 400);
        if (isset($in['cat_hide_categories']) || array_key_exists('_catalogue_form', $in)) $p['cat_hide_categories'] = $clean(is_array($in['cat_hide_categories'] ?? '') ? implode(',', $in['cat_hide_categories']) : (string)($in['cat_hide_categories'] ?? ''), 400);
        return $p;
    }

    // ------------------------------------------------------------------ logo
    /** Where logos and drawn cards live (STORAGE_PATH lets tests use a throw-away folder). */
    public static function storage(): string { return rtrim((string)env('STORAGE_PATH', ROOT . '/storage'), '/\\'); }
    public static function dir(int $tid): string { return self::storage() . '/brand/' . $tid; }
    public static function logoPath(int $tid): string { return self::dir($tid) . '/logo.png'; }

    /** Accept a PNG/JPEG/WebP upload, re-encode it as a PNG (max 600 px wide, removes any hidden payload). @return string|null error message */
    public static function saveLogo(int $tid, array $file): ?string {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return 'Choose an image file first.';
        if (($file['size'] ?? 0) > 2 * 1048576) return 'The logo must be smaller than 2 MB.';
        return self::saveLogoFile($tid, $file['tmp_name']);
    }
    public static function saveLogoFile(int $tid, string $path): ?string {
        $info = @getimagesize($path);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) return 'Use a PNG, JPG or WebP image (SVG is not accepted).';
        if ($info[0] > 6000 || $info[1] > 6000 || $info[0] < 16 || $info[1] < 16) return 'The logo image size is not supported.';
        $img = self::load((string)file_get_contents($path)); if (!$img) return 'The image could not be read.';
        $w = imagesx($img); $h = imagesy($img); $nw = min(600, $w); $nh = (int)round($h * $nw / $w);
        $out = imagecreatetruecolor($nw, $nh); imagealphablending($out, false); imagesavealpha($out, true); imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        if (!is_dir(self::dir($tid))) @mkdir(self::dir($tid), 0775, true);
        $ok = imagepng($out, self::logoPath($tid), 6);
        if (!$ok) return 'The logo could not be saved.';
        Tenant::saveSettings($tid, ['brand_logo_v' => time()]);
        return null;
    }
    public static function removeLogo(int $tid): void { @unlink(self::logoPath($tid)); Tenant::saveSettings($tid, ['brand_logo_v' => time()]); }

    /** @return \GdImage|resource|null */
    private static function load(string $bytes) { $i = @imagecreatefromstring($bytes); return $i ?: null; }

    // ------------------------------------------------------------------ branded product card (PNG)
    /** A TrueType font that can draw Latin text, or null when the server has none (the plain product photo is used instead). */
    private static function font(bool $bold): ?string {
        $c = $bold
            ? [ROOT . '/assets/fonts/bold.ttf', 'C:/Windows/Fonts/segoeuib.ttf', 'C:/Windows/Fonts/arialbd.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf', '/Library/Fonts/Arial Bold.ttf']
            : [ROOT . '/assets/fonts/regular.ttf', 'C:/Windows/Fonts/segoeui.ttf', 'C:/Windows/Fonts/arial.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/usr/share/fonts/dejavu/DejaVuSans.ttf', '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf', '/Library/Fonts/Arial.ttf'];
        foreach ($c as $f) if (is_file($f)) return $f;
        return null;
    }
    /** GD cannot shape Sinhala or Tamil, so those names are not drawn into the image (they travel in the caption instead). */
    public static function latinOnly(string $s): bool { return !preg_match('/[\x{0D80}-\x{0DFF}\x{0B80}-\x{0BFF}\x{4E00}-\x{9FFF}\x{0600}-\x{06FF}\x{0900}-\x{097F}]/u', $s); }

    private static function hex($img, string $hex, int $alpha = 0) { [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x'); return imagecolorallocatealpha($img, (int)$r, (int)$g, (int)$b, $alpha); }
    private static function textW(string $font, float $size, string $t): int { $b = imagettfbbox($size, 0, $font, $t); return (int)abs($b[2] - $b[0]); }
    private static function fit(string $font, float $size, string $t, int $max): string {
        if (self::textW($font, $size, $t) <= $max) return $t;
        while (mb_strlen($t) > 1 && self::textW($font, $size, $t . '…') > $max) $t = mb_substr($t, 0, -1);
        return rtrim($t) . '…';
    }

    /** Where the photo of a product comes from, for the card. @return string|null raw image bytes */
    private static function fetchPhoto(string $url): ?string {
        if (ProductArt::isArtUrl($url)) { // built-in picture: drawn here, no download
            $x = explode('/', $url); $row = Db::one("SELECT * FROM store_products WHERE tenant_id=? AND sku=?", [(int)$x[2], $x[3]]);
            return $row ? ProductArt::render($row) : null;
        }
        if (!preg_match('#^https://#i', $url)) return null;
        $r = Net::request('GET', $url, ['Accept: image/*'], ['timeout' => 8, 'max_bytes' => 6 * 1048576]);
        if ($r['status'] === 301 || $r['status'] === 302) { if (!empty($r['location']) && preg_match('#^https://#i', $r['location'])) $r = Net::request('GET', $r['location'], ['Accept: image/*'], ['timeout' => 8, 'max_bytes' => 6 * 1048576]); }
        return $r['status'] === 200 && $r['body'] !== '' ? $r['body'] : null;
    }

    /** Can a branded card image be drawn on this server for this product? */
    public static function canDraw(int $tid, array $p): bool {
        return self::font(true) !== null && self::font(false) !== null && function_exists('imagettftext') && self::latinOnly($p['name'] . self::get($tid)['name'] . ($p['variants'] ?? '') . ($p['colors'] ?? ''));
    }

    /** The product photo re-encoded as a small JPEG (cached), so dashboard pages can show it from our own origin. @return string|null JPEG bytes */
    public static function photo(int $tid, array $p): ?string {
        $url = (string)($p['image_url'] ?? ''); if ($url === '') return null;
        $sku = preg_replace('/[^A-Za-z0-9-]/', '', (string)$p['sku']); $dir = self::storage() . '/photos/' . $tid; $file = $dir . '/' . $sku . '-' . substr(sha1($url . (ProductArt::isArtUrl($url) ? '|' . $p['name'] . '|' . $p['colors'] . '|' . $p['category'] . '|v2' : '')), 0, 10) . '.jpg';
        if (is_file($file)) return (string)file_get_contents($file);
        $raw = self::fetchPhoto($url); $src = $raw ? self::load($raw) : null; if (!$src) return null;
        $w = imagesx($src); $h = imagesy($src); $nw = min(640, $w); $nh = (int)round($h * $nw / $w);
        $out = imagecreatetruecolor($nw, $nh); imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255)); imagecopyresampled($out, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        foreach (glob($dir . '/' . $sku . '-*.jpg') ?: [] as $old) @unlink($old);
        ob_start(); imagejpeg($out, null, 82); $jpg = (string)ob_get_clean();
        if ($jpg !== '') @file_put_contents($file, $jpg);
        return $jpg !== '' ? $jpg : null;
    }
    /** Same-origin relative paths used by the chat cards in the dashboard. */
    public static function logoPathRel(int $tid): string { return is_file(self::logoPath($tid)) ? 'media/logo/' . $tid . '?v=' . (int)self::get($tid)['brand_logo_v'] : ''; }
    public static function photoPathRel(int $tid, array $p): string { return ($p['image_url'] ?? '') !== '' ? 'media/photo/' . $tid . '/' . rawurlencode((string)$p['sku']) . '?v=' . substr(sha1((string)$p['image_url']), 0, 8) : ''; }

    public static function cardVersion(int $tid, array $p): string {
        $b = self::get($tid);
        return substr(sha1(implode('|', [$p['name'], $p['price'], (int)$p['stock'] > 5 ? 'in' : (int)$p['stock'], $p['variants'], $p['colors'], $p['image_url'], $b['name'], $b['brand_color'], $b['brand_logo_v'], 'v2'])), 0, 12);
    }
    public static function cardUrl(int $tid, string $sku, array $p): string { return abs_url('media/card/' . $tid . '/' . rawurlencode($sku)) . '?v=' . self::cardVersion($tid, $p); }

    /** The product's branded card as PNG bytes, or null if a card cannot be drawn here. Cached on disk. */
    public static function cardPng(int $tid, array $p): ?string {
        $dir = self::storage() . '/cards/' . $tid; $file = $dir . '/' . preg_replace('/[^A-Za-z0-9-]/', '', (string)$p['sku']) . '-' . self::cardVersion($tid, $p) . '.png';
        if (is_file($file)) return (string)file_get_contents($file);
        $bold = self::font(true); $reg = self::font(false); $b = self::get($tid);
        if (!$bold || !$reg || !function_exists('imagettftext') || !self::latinOnly($p['name'] . $b['name'] . ($p['variants'] ?? '') . ($p['colors'] ?? ''))) return null;
        $W = 800; $H = 920; $head = 112; $photoH = 560; $line = 8;
        $img = imagecreatetruecolor($W, $H); imagesavealpha($img, true);
        $white = self::hex($img, '#FFFFFF'); $ink = self::hex($img, '#15152B'); $muted = self::hex($img, '#6A6A84'); $brand = self::hex($img, $b['brand_color']);
        imagefilledrectangle($img, 0, 0, $W, $H, $white);
        // header bar: logo + business name
        imagefilledrectangle($img, 0, 0, $W, $head, $brand);
        $x = 32;
        if ($b['has_logo'] && ($logo = self::load((string)file_get_contents(self::logoPath($tid))))) {
            $lh = 72; $lw = (int)round(imagesx($logo) * $lh / imagesy($logo)); if ($lw > 220) { $lw = 220; $lh = (int)round(imagesy($logo) * $lw / imagesx($logo)); }
            $pad = 10; imagefilledrectangle($img, $x - $pad, (int)(($head - $lh) / 2) - $pad + 2, $x + $lw + $pad, (int)(($head - $lh) / 2) + $lh + $pad - 2, $white);
            imagecopyresampled($img, $logo, $x, (int)(($head - $lh) / 2), 0, 0, $lw, $lh, imagesx($logo), imagesy($logo)); $x += $lw + 34;
        }
        $nameTxt = self::fit($bold, 30, $b['name'], $W - $x - 30); $bb = imagettfbbox(30, 0, $bold, $nameTxt);
        imagettftext($img, 30, 0, $x, (int)(($head + abs($bb[7] - $bb[1])) / 2), $white, $bold, $nameTxt);
        // photo area (cover crop), or a soft placeholder
        $photo = ($p['image_url'] ?? '') !== '' ? self::fetchPhoto((string)$p['image_url']) : null; $src = $photo ? self::load($photo) : null;
        if ($src) {
            $sw = imagesx($src); $sh = imagesy($src); $scale = max($W / $sw, $photoH / $sh); $cw = (int)round($W / $scale); $ch = (int)round($photoH / $scale);
            imagecopyresampled($img, $src, 0, $head, (int)(($sw - $cw) / 2), (int)(($sh - $ch) / 2), $W, $photoH, $cw, $ch);
        } else {
            imagefilledrectangle($img, 0, $head, $W, $head + $photoH, self::hex($img, '#EEEDF9'));
            $ini = strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', (string)$p['name']), 0, 1)) ?: '?'; $bb = imagettfbbox(150, 0, $bold, $ini);
            imagettftext($img, 150, 0, (int)(($W - abs($bb[2] - $bb[0])) / 2), $head + (int)(($photoH + abs($bb[7] - $bb[1])) / 2), self::hex($img, '#C9C8E6'), $bold, $ini);
        }
        // info panel
        $y = $head + $photoH + 62; $stock = (int)$p['stock'];
        imagettftext($img, 32, 0, 32, $y, $ink, $bold, self::fit($bold, 32, (string)$p['name'], $W - 64));
        $price = 'Rs. ' . number_format((float)$p['price']); imagettftext($img, 38, 0, 32, $y + 66, $brand, $bold, $price);
        [$badge, $bc] = $stock <= 0 ? ['Sold out', '#C62828'] : ($stock <= 5 ? ['Only ' . $stock . ' left', '#B26A00'] : ['In stock', '#168A4F']); $bw = self::textW($reg, 22, $badge) + 36;
        imagefilledrectangle($img, $W - 32 - $bw, $y + 28, $W - 32, $y + 72, self::hex($img, $bc)); imagettftext($img, 22, 0, $W - 32 - $bw + 18, $y + 61, $white, $reg, $badge);
        $opts = []; if (!empty($p['variants'])) $opts[] = 'Sizes: ' . str_replace(',', ' ', (string)$p['variants']); if (!empty($p['colors'])) $opts[] = 'Colours: ' . str_replace(',', ', ', (string)$p['colors']);
        if ($opts) imagettftext($img, 22, 0, 32, $y + 116, $muted, $reg, self::fit($reg, 22, implode('   ·   ', $opts), $W - 64));
        imagefilledrectangle($img, 0, $H - $line, $W, $H, $brand);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        foreach (glob($dir . '/' . preg_replace('/[^A-Za-z0-9-]/', '', (string)$p['sku']) . '-*.png') ?: [] as $old) @unlink($old); // keep one version per product
        ob_start(); imagepng($img, null, 6); $png = (string)ob_get_clean();
        if ($png !== '') @file_put_contents($file, $png);
        return $png !== '' ? $png : null;
    }
}
