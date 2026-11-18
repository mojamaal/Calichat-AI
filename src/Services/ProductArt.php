<?php
declare(strict_types=1);
namespace CaliChat\Services;

/**
 * Clean flat-style illustrations of common fashion products (T-shirt, jeans, dress, sneakers, cap, watch...), drawn with GD.
 * Used as the default picture of demo/sample products so a catalogue never looks empty; a real photo link replaces it any time.
 * A product uses it by having image_url = "media/art/<tenant>/<sku>" (served by app/media.php).
 */
class ProductArt {
    private const NAMED = ['black' => '#252a36', 'white' => '#f3f3f6', 'navy' => '#223a6b', 'maroon' => '#7d1f36', 'blue' => '#3470b8', 'brown' => '#8b5a3a', 'green' => '#2f7d5b', 'floral' => '#e8718c', 'red' => '#d23b3b',
        'natural' => '#e7d9b9', 'silver' => '#b9bec9', 'gold' => '#d6b04a', 'khaki' => '#b7a36c', 'grey' => '#8c919c', 'gray' => '#8c919c', 'pink' => '#ee8fb0', 'yellow' => '#f0c43c', 'orange' => '#ee8a3a', 'purple' => '#7b4ab8', 'beige' => '#d8c7a6', 'cream' => '#f0e6cc'];
    private const BACKS = [['#eef0fb', '#dfe3f7'], ['#fdf1ea', '#f8e0d2'], ['#eaf6f1', '#d7ede4'], ['#f6eefb', '#ead9f4'], ['#fff6e3', '#fbe9bd'], ['#eaf4fb', '#d5e8f6']];

    public static function url(int $tid, string $sku): string { return 'media/art/' . $tid . '/' . $sku; }
    public static function isArtUrl(string $u): bool { return (bool)preg_match('#^media/art/\d+/[A-Za-z0-9-]{2,30}$#', $u); }

    public static function kind(array $p): string {
        $n = strtolower((string)($p['name'] ?? '') . ' ' . (string)($p['category'] ?? ''));
        foreach (['polo' => 'polo', 't-shirt' => 'tshirt', 'tshirt' => 'tshirt', 'shirt' => 'tshirt', 'jean' => 'jeans', 'trouser' => 'jeans', 'pant' => 'jeans', 'sarong' => 'sarong', 'dress' => 'dress', 'sneaker' => 'sneaker', 'shoe' => 'sneaker',
            'slipper' => 'slipper', 'flip' => 'slipper', 'sandal' => 'slipper', 'cap' => 'cap', 'hat' => 'cap', 'belt' => 'belt', 'tote' => 'tote', 'bag' => 'tote', 'watch' => 'watch', 'scarf' => 'scarf'] as $k => $v) if (strpos($n, $k) !== false) return $v;
        return 'box';
    }
    public static function color(array $p): string {
        $first = strtolower(trim(explode(',', (string)($p['colors'] ?? ''))[0] ?? ''));
        foreach (self::NAMED as $k => $hex) if ($first !== '' && strpos($first, $k) !== false) return $hex;
        $h = hexdec(substr(md5((string)($p['sku'] ?? 'x')), 0, 4)) % 360; return self::hsl($h, 0.45, 0.45);
    }
    private static function hsl(float $h, float $s, float $l): string {
        $c = (1 - abs(2 * $l - 1)) * $s; $x = $c * (1 - abs(fmod($h / 60, 2) - 1)); $m = $l - $c / 2; [$r, $g, $b] = $h < 60 ? [$c, $x, 0] : ($h < 120 ? [$x, $c, 0] : ($h < 180 ? [0, $c, $x] : ($h < 240 ? [0, $x, $c] : ($h < 300 ? [$x, 0, $c] : [$c, 0, $x]))));
        return sprintf('#%02x%02x%02x', (int)round(($r + $m) * 255), (int)round(($g + $m) * 255), (int)round(($b + $m) * 255));
    }

    // ------------------------------------------------------------------ drawing helpers (1000 x 1000 design space, drawn at 1600 px then reduced)
    private const K = 1.6;
    private static $im;
    private static function rgb(string $hex, float $shade = 0.0, int $alpha = 0): int {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        if ($shade < 0) { $r = (int)($r * (1 + $shade)); $g = (int)($g * (1 + $shade)); $b = (int)($b * (1 + $shade)); } else { $r = (int)($r + (255 - $r) * $shade); $g = (int)($g + (255 - $g) * $shade); $b = (int)($b + (255 - $b) * $shade); }
        return imagecolorallocatealpha(self::$im, max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)), $alpha);
    }
    private static function poly(array $pts, int $c): void { $f = []; foreach ($pts as $v) $f[] = (int)round($v * self::K); imagefilledpolygon(self::$im, $f, intdiv(count($f), 2), $c); }
    private static function line(float $x1, float $y1, float $x2, float $y2, int $c, float $w = 6): void { imagesetthickness(self::$im, max(1, (int)round($w * self::K))); imageline(self::$im, (int)round($x1 * self::K), (int)round($y1 * self::K), (int)round($x2 * self::K), (int)round($y2 * self::K), $c); imagesetthickness(self::$im, 1); }
    private static function ell(float $cx, float $cy, float $rx, float $ry, int $c): void { imagefilledellipse(self::$im, (int)round($cx * self::K), (int)round($cy * self::K), (int)round($rx * 2 * self::K), (int)round($ry * 2 * self::K), $c); }
    private static function rect(float $x1, float $y1, float $x2, float $y2, int $c): void { imagefilledrectangle(self::$im, (int)round($x1 * self::K), (int)round($y1 * self::K), (int)round($x2 * self::K), (int)round($y2 * self::K), $c); }
    private static function rev(array $p): array { $o = []; for ($i = count($p) - 2; $i >= 0; $i -= 2) { $o[] = $p[$i]; $o[] = $p[$i + 1]; } return $o; }
    private static function ring(float $cx, float $cy, float $rx, float $ry, float $w, float $a0, float $a1, int $c): void { self::poly(array_merge(self::arcPts($cx, $cy, $rx, $ry, $a0, $a1, 30), self::rev(self::arcPts($cx, $cy, $rx - $w, $ry - $w, $a0, $a1, 30))), $c); }
    private static function arcPts(float $cx, float $cy, float $rx, float $ry, float $a0, float $a1, int $n = 28): array { $o = []; for ($i = 0; $i <= $n; $i++) { $a = deg2rad($a0 + ($a1 - $a0) * $i / $n); $o[] = $cx + $rx * cos($a); $o[] = $cy + $ry * sin($a); } return $o; }
    private static function shadow(float $cx, float $cy, float $rx, float $ry): void { for ($i = 5; $i >= 1; $i--) self::ell($cx, $cy, $rx + $i * 14, $ry + $i * 5, self::rgb('#3a3a5a', 0, 122 + $i * 1)); }
    private static function inside(array $pts, float $x, float $y): bool { $in = false; $n = intdiv(count($pts), 2); for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) { [$xi, $yi, $xj, $yj] = [$pts[$i * 2], $pts[$i * 2 + 1], $pts[$j * 2], $pts[$j * 2 + 1]]; if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi)) $in = !$in; } return $in; }

    /** @return string PNG bytes (800 x 800) */
    public static function render(array $p): string {
        $big = imagecreatetruecolor(1600, 1600); self::$im = $big; imagealphablending($big, true); imageantialias($big, true);
        [$b1, $b2] = self::BACKS[hexdec(substr(md5((string)($p['category'] ?? $p['sku'] ?? '')), 0, 2)) % count(self::BACKS)];
        for ($y = 0; $y < 1600; $y += 8) { $t = $y / 1600; [$r1, $g1, $bl1] = sscanf($b1, '#%02x%02x%02x'); [$r2, $g2, $bl2] = sscanf($b2, '#%02x%02x%02x'); imagefilledrectangle($big, 0, $y, 1600, $y + 8, imagecolorallocate($big, (int)($r1 + ($r2 - $r1) * $t), (int)($g1 + ($g2 - $g1) * $t), (int)($bl1 + ($bl2 - $bl1) * $t))); }
        $kind = self::kind($p); $col = self::color($p);
        self::shadow(500, 880, 250, 24);
        $fn = 'draw' . ucfirst($kind); self::$fn($col, $p);
        $out = imagecreatetruecolor(800, 800); imagecopyresampled($out, $big, 0, 0, 0, 0, 800, 800, 1600, 1600);
        ob_start(); imagepng($out, null, 6); $png = (string)ob_get_clean(); imagedestroy($big); imagedestroy($out); self::$im = null; return $png;
    }

    private static function tee(string $col, bool $polo): void {
        $base = self::rgb($col); $dark = self::rgb($col, -0.22); $light = self::rgb($col, 0.18); $neck = $polo ? 175 : 215;
        $body = [305, 232, 395, 200]; $neckPts = self::arcPts(500, 205, 108, $neck - 130 + 40, 180, 0, 22); // neckline, left to right through the bottom
        $pts = array_merge([300, 232, 138, 330, 196, 442, 300, 396, 300, 812, 700, 812, 700, 396, 804, 442, 862, 330, 700, 232, 608, 205], self::rev($neckPts), [392, 205]);
        self::poly($pts, $base);
        self::poly([300, 232, 138, 330, 160, 370, 300, 300], $dark); self::poly([700, 232, 862, 330, 840, 370, 700, 300], $dark); // sleeve shading
        self::poly([300, 396, 300, 812, 340, 812, 340, 396], $dark); self::poly([660, 396, 660, 812, 700, 812, 700, 396], self::rgb($col, -0.12)); // side shading
        self::poly([360, 420, 460, 420, 440, 800, 360, 800], self::rgb($col, 0.06, 100)); // soft highlight
        self::poly(array_merge(self::arcPts(500, 205, 108, $neck - 90, 0, 180, 22), self::rev(self::arcPts(500, 205, 130, $neck - 70, 0, 180, 22))), $dark); // collar rib
        self::line(300, 790, 700, 790, $dark, 5); self::line(160, 360, 215, 425, $dark, 5); self::line(840, 360, 785, 425, $dark, 5);
        if ($polo) { // placket, buttons, folded collar
            self::rect(488, 262, 512, 520, $dark); self::ell(500, 330, 7, 7, $light); self::ell(500, 410, 7, 7, $light); self::ell(500, 490, 7, 7, $light);
            self::poly([392, 205, 470, 262, 488, 262, 430, 190], $light); self::poly([608, 205, 530, 262, 512, 262, 570, 190], $light);
        }
    }
    private static function drawTshirt(string $c, array $p): void { self::tee($c, false); }
    private static function drawPolo(string $c, array $p): void { self::tee($c, true); }

    private static function drawJeans(string $c, array $p): void {
        $base = self::rgb($c); $dark = self::rgb($c, -0.25); $light = self::rgb($c, 0.16); $stitch = self::rgb('#e9b86a');
        self::poly([312, 175, 688, 175, 712, 320, 762, 872, 566, 872, 500, 400, 434, 872, 238, 872, 288, 320], $base);
        self::poly([312, 175, 288, 320, 238, 872, 290, 872, 340, 330], $dark); self::poly([688, 175, 712, 320, 762, 872, 710, 872, 660, 330], self::rgb($c, -0.14));
        self::poly([420, 400, 500, 400, 470, 872, 434, 872], self::rgb($c, 0.1, 90)); self::poly([500, 400, 566, 872, 520, 872, 505, 520], self::rgb($c, -0.1, 90));
        self::rect(312, 175, 688, 232, $dark);
        foreach ([350, 430, 570, 650] as $x) self::rect($x - 9, 168, $x + 9, 246, $base);
        self::ell(500, 214, 15, 15, self::rgb('#c9ccd4')); self::line(500, 232, 500, 400, $stitch, 5); self::line(500, 400, 440, 370, $stitch, 5);
        self::line(330, 260, 420, 330, $stitch, 5); self::line(420, 330, 430, 250, $stitch, 5); self::line(670, 260, 580, 330, $stitch, 5); self::line(580, 330, 570, 250, $stitch, 5);
        self::line(250, 850, 300, 850, $stitch, 5); self::line(700, 850, 750, 850, $stitch, 5);
    }

    private static function drawSarong(string $c, array $p): void {
        $base = self::rgb($c); $dark = self::rgb($c, -0.3); $light = self::rgb($c, 0.35); $alt = self::rgb('#c8963e');
        $L = fn(float $y) => 312 - 34 * ($y - 190) / 640; $R = fn(float $y) => 688 + 34 * ($y - 190) / 640;
        self::poly([312, 190, 688, 190, 722, 830, 278, 830], $base);
        for ($i = 0; $i < 9; $i++) { $f = ($i + 0.5) / 9; $w = 0.035; self::poly([312 + $f * 376 - $w * 376, 190, 312 + $f * 376 + $w * 376, 190, 278 + $f * 444 + $w * 444, 830, 278 + $f * 444 - $w * 444, 830], $i % 3 === 0 ? $light : ($i % 3 === 1 ? $dark : $alt)); }
        for ($y = 260; $y < 830; $y += 95) self::poly([$L($y), $y, $R($y), $y, $R($y + 16), $y + 16, $L($y + 16), $y + 16], $y % 190 === 0 ? self::rgb('#ffffff', 0, 70) : self::rgb('#000000', 0, 95));
        self::poly([312, 190, 688, 190, 692, 250, 308, 250], self::rgb($c, -0.35)); self::line(308, 250, 692, 250, self::rgb($c, 0.25), 5);
        self::poly([300, 830, 700, 830, 704, 858, 296, 858], $dark); for ($x = 304; $x < 700; $x += 22) self::line($x, 858, $x - 2, 884, $dark, 4);
    }

    private static function drawDress(string $c, array $p): void {
        $base = self::rgb($c); $dark = self::rgb($c, -0.22); $light = self::rgb($c, 0.38);
        self::poly([408, 120, 432, 120, 448, 290, 420, 290], $dark); self::poly([568, 120, 592, 120, 580, 290, 552, 290], $dark); // straps
        $sk = array_merge([402, 330, 598, 330], self::arcPts(500, 850, 270, 26, 360, 180, 24)); // hem curve right-to-left
        $skirt = [402, 330, 598, 330, 770, 850]; foreach (self::arcPts(500, 850, 270, 26, 0, 180, 24) as $v) $skirt[] = $v; $skirt[] = 230; $skirt[] = 850;
        self::poly([405, 270, 595, 270, 575, 420, 425, 420], $base); self::poly(array_merge([420, 270], self::arcPts(500, 270, 85, 55, 180, 0, 16), [580, 270]), $dark); // bodice + neckline
        $skirtPts = array_merge([425, 410, 575, 410, 775, 850], self::arcPts(500, 850, 275, 28, 0, 180, 26), [225, 850]); self::poly($skirtPts, $base);
        self::poly([425, 410, 460, 410, 340, 850, 225, 850], self::rgb($c, -0.12, 70)); self::poly([540, 410, 575, 410, 775, 850, 690, 850], self::rgb($c, 0.12, 80));
        self::rect(420, 396, 580, 430, $dark); self::poly([500, 430, 470, 850, 530, 850], self::rgb($c, 0.05, 105));
        mt_srand(crc32((string)($p['sku'] ?? 'd'))); for ($i = 0; $i < 46; $i++) { $x = mt_rand(250, 760); $y = mt_rand(450, 840); if (self::inside($skirtPts, $x, $y)) { self::ell($x, $y, 14, 14, $light); self::ell($x, $y, 5, 5, self::rgb('#f5d36a')); } }
    }

    private static function drawSneaker(string $c, array $p): void {
        $base = self::rgb($c); $dark = self::rgb($c, -0.28); $light = self::rgb($c, 0.14); $sole = self::rgb('#f6f6f9'); $soleD = self::rgb('#c3c7d2'); $white = self::rgb('#f6f6f9'); $lace = self::rgb('#ffffff');
        self::poly([170, 560, 190, 400, 250, 372, 340, 392, 392, 430, 560, 470, 720, 520, 850, 590, 880, 650, 170, 660], $base); // upper
        self::poly([170, 560, 190, 400, 250, 372, 300, 560, 215, 660, 170, 660], $dark); // heel counter
        self::poly([760, 530, 850, 590, 880, 650, 700, 650, 690, 560], $light); // toe cap
        self::poly([330, 372, 392, 392, 392, 430, 340, 440], $dark); // padded collar
        self::poly(array_merge([150, 648, 890, 648], self::arcPts(880, 686, 22, 38, -90, 90, 8), [160, 724], self::arcPts(160, 686, 22, 38, 90, 270, 8)), $sole);
        self::rect(150, 690, 890, 722, $soleD); self::line(190, 640, 870, 640, self::rgb('#d33a3a'), 7);
        for ($i = 0; $i < 4; $i++) { $x = 410 + $i * 80; $y = 440 + $i * 22; self::ell($x, $y, 7, 7, $dark); self::line($x - 22, $y + 34, $x + 38, $y - 16, $lace, 10); }
        self::line(410, 410, 740, 520, $dark, 5); self::ell(300, 590, 34, 34, $white); self::ell(300, 590, 14, 14, $dark);
    }

    private static function drawSlipper(string $c, array $p): void {
        $sole = self::rgb($c, -0.32); $bed = self::rgb($c, 0.06); $strap = self::rgb('#f0b429'); $strapD = self::rgb('#c88a12'); $hi = self::rgb('#ffffff', 0, 95);
        foreach ([[380, 20], [630, 50]] as [$cx, $dy]) {
            $out = [[-95, 330], [-60, 285], [0, 268], [60, 285], [98, 335], [108, 430], [92, 540], [76, 650], [82, 760], [56, 830], [0, 850], [-56, 830], [-82, 760], [-76, 650], [-92, 540], [-108, 430]]; $o = []; $i2 = [];
            foreach ($out as [$x, $y]) { $o[] = $cx + $x; $o[] = $y + $dy; $i2[] = $cx + $x * 0.86; $i2[] = 560 + $dy + ($y - 560) * 0.93; }
            self::poly($o, $sole); self::poly($i2, $bed); self::poly([$cx - 70, 400 + $dy, $cx - 20, 380 + $dy, $cx - 30, 560 + $dy, $cx - 72, 520 + $dy], $hi);
            self::ell($cx, 425 + $dy, 17, 17, $strapD);
            self::poly([$cx - 14, 425 + $dy, $cx + 14, 425 + $dy, $cx + 10, 520 + $dy, $cx - 10, 520 + $dy], $strapD); // toe post
            self::poly([$cx - 5, 425 + $dy, $cx - 84, 560 + $dy, $cx - 96, 540 + $dy, $cx - 20, 410 + $dy], $strap); self::poly([$cx + 5, 425 + $dy, $cx + 84, 548 + $dy, $cx + 96, 528 + $dy, $cx + 20, 410 + $dy], $strap);
            self::ell($cx - 90, 550 + $dy, 15, 15, $strapD); self::ell($cx + 90, 538 + $dy, 15, 15, $strapD); self::ell($cx, 420 + $dy, 24, 24, $strap);
        }
    }

    private static function drawCap(string $c, array $p): void {
        $base = self::rgb($c); $dark = self::rgb($c, -0.25); $light = self::rgb($c, 0.2);
        $dome = self::arcPts(450, 600, 300, 300, 180, 360, 40); self::poly(array_merge($dome, [750, 600, 150, 600]), $base);
        self::poly(array_merge(self::arcPts(450, 600, 300, 300, 270, 360, 18), [750, 600, 450, 600]), self::rgb($c, -0.12));
        foreach ([200, 235, 270, 305, 340] as $a) { $r = deg2rad($a); self::line(450, 300, 450 + 300 * cos($r), 600 + 300 * sin($r), $dark, 4); }
        self::rect(150, 590, 750, 640, $dark); self::line(150, 612, 750, 612, $light, 4);
        self::poly([700, 585, 780, 595, 930, 640, 940, 668, 860, 690, 700, 640], $dark); self::poly([700, 585, 780, 595, 920, 635, 700, 612], self::rgb($c, 0.05)); // brim
        self::ell(450, 302, 22, 22, $dark); self::ell(450, 300, 15, 15, $light);
    }

    private static function drawBelt(string $c, array $p): void {
        $base = self::rgb($c); $dark = self::rgb($c, -0.3); $light = self::rgb($c, 0.2); $metal = self::rgb('#c9ccd4'); $metalD = self::rgb('#8b909c');
        self::poly(array_merge([280, 455, 800, 455], self::arcPts(800, 500, 70, 45, -90, 90, 14), [280, 545]), $base); self::rect(280, 455, 840, 466, $light); self::rect(280, 534, 840, 545, $dark);
        for ($i = 0; $i < 6; $i++) self::ell(470 + $i * 64, 500, 13, 13, self::rgb('#000000', 0, 40)); for ($x = 285; $x < 835; $x += 24) { self::line($x, 462, $x + 12, 462, $light, 3); self::line($x, 538, $x + 12, 538, $dark, 3); }
        self::rect(150, 400, 310, 600, $metalD); self::rect(172, 422, 288, 578, $base); self::rect(190, 440, 290, 560, $base);
        self::rect(150, 400, 310, 424, $metal); self::rect(150, 576, 310, 600, $metal); self::rect(150, 400, 174, 600, $metal); self::rect(286, 400, 310, 600, $metal);
        self::rect(215, 488, 330, 512, $metal); self::ell(335, 500, 12, 12, $metal); self::line(220, 405, 220, 595, $metalD, 3);
    }

    private static function drawTote(string $c, array $p): void {
        $base = self::rgb($c); $dark = self::rgb($c, -0.18); $light = self::rgb($c, 0.2); $ink = self::rgb('#3a3f4d'); $hand = self::rgb($c, -0.32); $handL = self::rgb($c, -0.12);
        self::ring(500, 440, 165, 250, 34, 180, 360, $hand); // back handle
        self::poly([255, 410, 745, 410, 775, 870, 225, 870], $base); self::poly([255, 410, 300, 410, 275, 870, 225, 870], $dark); self::poly([745, 410, 700, 410, 725, 870, 775, 870], $dark);
        self::poly([255, 410, 745, 410, 748, 450, 252, 450], $light); self::line(262, 470, 738, 470, $dark, 4); self::line(240, 850, 760, 850, $dark, 4);
        self::ell(500, 650, 92, 92, $ink); self::ell(500, 650, 76, 76, $base); self::poly([440, 675, 470, 610, 500, 660, 530, 600, 560, 675, 530, 705, 470, 705], $ink);
        self::ring(500, 440, 128, 215, 30, 180, 360, $handL); // front handle
        self::rect(372, 404, 402, 430, $dark); self::rect(598, 404, 628, 430, $dark);
    }

    private static function drawWatch(string $c, array $p): void {
        $metal = self::rgb($c); $metalD = self::rgb($c, -0.3); $metalL = self::rgb($c, 0.4); $strap = self::rgb('#3b2a22'); $strapL = self::rgb('#5a4034');
        self::poly([395, 70, 605, 70, 625, 380, 375, 380], $strap); self::poly([375, 620, 625, 620, 605, 930, 395, 930], $strap);
        foreach ([[410, 92], [410, 340]] as [$x, $y]) { } for ($i = 0; $i < 5; $i++) self::ell(500, 700 + $i * 48, 13, 13, self::rgb('#000000', 0, 60)); self::line(412, 90, 420, 380, $strapL, 4); self::line(588, 90, 580, 380, $strapL, 4);
        self::line(418, 640, 410, 920, $strapL, 4); self::line(582, 640, 590, 920, $strapL, 4);
        self::rect(700, 470, 748, 530, $metalD); self::rect(700, 480, 748, 490, $metalL);
        self::ell(500, 500, 235, 235, $metalD); self::ell(500, 500, 222, 222, $metal); self::ell(500, 500, 190, 190, self::rgb('#20232c')); self::ell(500, 500, 178, 178, self::rgb('#fbfbfd'));
        for ($i = 0; $i < 12; $i++) { $a = deg2rad($i * 30 - 90); $r1 = $i % 3 === 0 ? 140 : 152; self::line(500 + $r1 * cos($a), 500 + $r1 * sin($a), 500 + 166 * cos($a), 500 + 166 * sin($a), self::rgb('#20232c'), $i % 3 === 0 ? 9 : 5); }
        self::line(500, 500, 500 + 85 * cos(deg2rad(-30)), 500 + 85 * sin(deg2rad(-30)), self::rgb('#20232c'), 14); self::line(500, 500, 500 + 130 * cos(deg2rad(70)), 500 + 130 * sin(deg2rad(70)), self::rgb('#20232c'), 9);
        self::line(500, 500, 500 + 140 * cos(deg2rad(190)), 500 + 140 * sin(deg2rad(190)), self::rgb('#d33a3a'), 4); self::ell(500, 500, 14, 14, self::rgb('#d33a3a'));
        self::poly([360, 400, 470, 330, 500, 345, 380, 430], self::rgb('#ffffff', 0, 100));
    }

    private static function drawScarf(string $c, array $p): void {
        $base = self::rgb($c); $dark = self::rgb($c, -0.25); $light = self::rgb($c, 0.3); $stripe = self::rgb('#f3f3f6');
        $center = []; for ($i = 0; $i <= 36; $i++) { $t = $i / 36; $center[] = [150 + 700 * $t, 230 + 520 * $t + 70 * sin($t * 2 * M_PI * 1.2)]; }
        $a = []; $b = []; for ($i = 0; $i <= 36; $i++) { $q = $center[min(36, $i + 1)]; $o = $center[max(0, $i - 1)]; $dx = $q[0] - $o[0]; $dy = $q[1] - $o[1]; $l = sqrt($dx * $dx + $dy * $dy) ?: 1; $nx = -$dy / $l; $ny = $dx / $l; $w = 105; $a[] = [$center[$i][0] + $nx * $w, $center[$i][1] + $ny * $w]; $b[] = [$center[$i][0] - $nx * $w, $center[$i][1] - $ny * $w]; }
        $pts = []; foreach ($a as $v) { $pts[] = $v[0]; $pts[] = $v[1]; } foreach (array_reverse($b) as $v) { $pts[] = $v[0]; $pts[] = $v[1]; } self::poly($pts, $base);
        for ($i = 2; $i < 36; $i += 5) { self::poly([$a[$i][0], $a[$i][1], $a[$i + 1][0], $a[$i + 1][1], $b[$i + 1][0], $b[$i + 1][1], $b[$i][0], $b[$i][1]], $i % 10 === 2 ? $stripe : $dark); }
        for ($i = 0; $i < 36; $i += 2) { self::poly([$a[$i][0], $a[$i][1], $a[$i + 1][0], $a[$i + 1][1], $center[$i + 1][0], $center[$i + 1][1], $center[$i][0], $center[$i][1]], self::rgb($c, 0.12, 100)); }
        foreach ([[0, -1], [36, 1]] as [$idx, $dir]) for ($k = 0; $k <= 12; $k++) { $f = $k / 12; $x = $a[$idx][0] + ($b[$idx][0] - $a[$idx][0]) * $f; $y = $a[$idx][1] + ($b[$idx][1] - $a[$idx][1]) * $f; $cx = $center[$idx]; self::line($x, $y, $x + $dir * 34, $y + $dir * 22, $dark, 5); }
    }

    private static function drawBox(string $c, array $p): void {
        $base = self::rgb($c); $dark = self::rgb($c, -0.22); $light = self::rgb($c, 0.2);
        self::poly([500, 220, 780, 340, 500, 460, 220, 340], $light); self::poly([220, 340, 500, 460, 500, 800, 220, 680], $base); self::poly([780, 340, 500, 460, 500, 800, 780, 680], $dark); self::line(500, 460, 500, 800, self::rgb('#ffffff', 0, 60), 6);
    }
}
