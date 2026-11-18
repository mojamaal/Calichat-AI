<?php
declare(strict_types=1);

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function base_path(): string {
    if (PHP_SAPI === 'cli-server') return ''; // built-in dev server serves from the document root
    $d =str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    return $d === '/' || $d === '.' ? '' : rtrim($d, '/');
}
function url(string $p = ''): string { return base_path() . '/' . ltrim($p, '/'); }
function abs_url(string $p = ''): string {
    $app = rtrim((string)env('APP_URL', ''), '/');
    // Opened through a public tunnel (ngrok, Cloudflare) while APP_URL still says localhost: show the address that is actually reachable.
    $isLocal = fn(string $h) => in_array(strtolower(explode(':', $h)[0]), ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    $reqHost = $_SERVER['HTTP_HOST'] ?? '';
    if ($app !== '' && $reqHost !== '' && $isLocal((string)parse_url($app, PHP_URL_HOST)) && !$isLocal($reqHost) && preg_match('/^[A-Za-z0-9.\-:]+$/', $reqHost)) {
        $xfp = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $app = (in_array($xfp, ['http', 'https'], true) ? $xfp : (is_https() ? 'https' : 'http')) . '://' . $reqHost . base_path();
    }
    if ($app === '') {
        $s = is_https() ? 'https' : 'http';
        $app = $s . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base_path();
    }
    return $app . '/' . ltrim($p, '/');
}
function redirect(string $p): void { header('Location: ' . (preg_match('#^https?://#', $p) ? $p : url($p))); exit; }
/** Work to do AFTER the browser has its answer (for example saving customer memory with another AI call). Runs from json_out(). */
function after_response(callable $fn): void { $GLOBALS['CC_AFTER'][] = $fn; }
function json_out($data, int $code = 200): void {
    $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $later = $GLOBALS['CC_AFTER'] ?? []; unset($GLOBALS['CC_AFTER']);
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($later) { ignore_user_abort(true); if (session_status() === PHP_SESSION_ACTIVE) session_write_close(); header('Connection: close'); header('Content-Length: ' . strlen((string)$body)); } // let the browser finish now; the session lock is released so the next message is not held up
    echo $body;
    if ($later) {
        while (ob_get_level() > 0) @ob_end_flush();
        flush(); if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        set_time_limit(60);
        foreach ($later as $fn) { try { $fn(); } catch (\Throwable $e) { \CaliChat\Core\Log::exception($e, 'after_response_failed'); } }
    }
    exit;
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void {
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) { http_response_code(419); exit('Session expired. Please reload the page.'); }
}
function flash(?string $msg = null, string $type = 'ok') {
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $type]; return null; }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
}
function post(string $k, $d = '') { return isset($_POST[$k]) && is_string($_POST[$k]) ? trim($_POST[$k]) : $d; }
function now(): string { return gmdate('Y-m-d H:i:s'); }
function client_ip(): string {
    if (env('TRUST_PROXY', '0') === '1' && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    return $_SERVER['REMOTE_ADDR'] ?? 'cli';
}

/** Tenant display timezone, set by the layout/API once the tenant is known. */
function set_tz(string $tz): void { $GLOBALS['CC_TZ'] = in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC'; }
function get_tz(): string { return $GLOBALS['CC_TZ'] ?? 'Asia/Colombo'; }
/** Format a UTC DB datetime in the active tenant timezone. */
function local_dt(?string $utc, string $fmt = 'M j, H:i'): string {
    if (!$utc) return '—';
    try { $d = new DateTime($utc, new DateTimeZone('UTC')); $d->setTimezone(new DateTimeZone(get_tz())); return $d->format($fmt); }
    catch (\Throwable $e) { return (string)$utc; }
}
function ago(?string $dt): string {
    if (!$dt) return '—';
    $s = time() - strtotime($dt . ' UTC');
    if ($s < 60) return 'just now';
    if ($s < 3600) return floor($s / 60) . 'm ago';
    if ($s < 86400) return floor($s / 3600) . 'h ago';
    if ($s < 7 * 86400) return floor($s / 86400) . 'd ago';
    return local_dt($dt, 'M j');
}
function num($n): string { return number_format((float)$n); }
/** A phone number as this signed-in user may see it (partly hidden without the show_phone capability). */
function phone_view(?string $p): string { $p = (string)$p; return $p !== '' && !\CaliChat\Core\Auth::can('show_phone') ? \CaliChat\Core\Perms::mask($p) : $p; }
function money($n): string { return '$' . number_format((float)$n, $n < 1 ? 4 : 2); }
function bytes_h($b): string { $u = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0; $b = (float)$b; while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; } return round($b, $i ? 1 : 0) . ' ' . $u[$i]; }
function rand_key(int $bytes = 16): string { return bin2hex(random_bytes($bytes)); }
function slugify(string $s): string { $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-')); return $s !== '' ? $s : rand_key(3); }
function initials(string $n): string {
    $p = preg_split('/\s+/', trim($n)); $o = '';
    foreach (array_slice($p, 0, 2) as $w) $o .= mb_strtoupper(mb_substr($w, 0, 1));
    return $o ?: '?';
}
function pct($a, $b): string { return $b > 0 ? round(100 * $a / $b) . '%' : '—'; }

/** Inline SVG bar chart: $series = [label => value]. */
function svg_bars(array $series, string $color = 'var(--brand)', int $h = 130): string {
    $n = max(1, count($series)); $max = max(1, max($series ?: [0]));
    $w = 560; $bw = $w / $n; $out = '<svg viewBox="0 0 ' . $w . ' ' . ($h + 22) . '" class="chart" role="img" preserveAspectRatio="none">';
    $i = 0;
    foreach ($series as $label => $v) {
        $bh = max(2, ($v / $max) * ($h - 14)); $x = $i * $bw + $bw * .15;
        $out .= '<rect x="' . round($x, 1) . '" y="' . round($h - $bh, 1) . '" width="' . round($bw * .7, 1) . '" height="' . round($bh, 1) . '" rx="4" fill="' . $color . '"><title>' . e($label) . ': ' . e($v) . '</title></rect>';
        if ($n <= 16 || $i % 2 === 0) $out .= '<text x="' . round($i * $bw + $bw / 2, 1) . '" y="' . ($h + 16) . '" text-anchor="middle" class="axis">' . e($label) . '</text>';
        $i++;
    }
    return $out . '</svg>';
}
/** Inline SVG sparkline. */
function svg_spark(array $vals, string $color = 'var(--brand)'): string {
    $vals = array_values($vals); $n = count($vals); if ($n < 2) return '';
    $max = max(1, max($vals)); $w = 160; $h = 36; $pts = [];
    foreach ($vals as $i => $v) $pts[] = round($i * $w / ($n - 1), 1) . ',' . round($h - 3 - ($v / $max) * ($h - 8), 1);
    return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" class="spark" aria-hidden="true"><polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . $color . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/></svg>';
}
function gauge(float $pct, string $label, string $sub = ''): string {
    $p = max(0, min(100, $pct)); $cls = $p >= 90 ? 'g-bad' : ($p >= 70 ? 'g-warn' : 'g-ok');
    return '<div class="gauge ' . $cls . '"><div class="gh"><span>' . e($label) . '</span><b>' . round($p) . '%</b></div><div class="gbar"><i style="width:' . $p . '%"></i></div>' . ($sub ? '<div class="gs">' . e($sub) . '</div>' : '') . '</div>';
}

function icon(string $name, int $size = 20): string {
    static $m = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'inbox' => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5h13l3.5 7v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6z"/>',
        'agent' => '<rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 8V4"/><circle cx="12" cy="3" r="1"/><circle cx="9" cy="14" r="1"/><circle cx="15" cy="14" r="1"/>',
        'channel' => '<path d="M21 11.5a8.4 8.4 0 0 1-12.4 7.4L3 20.5l1.6-5.3A8.5 8.5 0 1 1 21 11.5z"/>',
        'plug' => '<path d="M9 2v6M15 2v6"/><path d="M6 8h12v4a6 6 0 0 1-12 0z"/><path d="M12 18v4"/>',
        'book' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5z"/><path d="M4 19.5V21h16"/>',
        'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
        'megaphone' => '<path d="M3 11v2a1 1 0 0 0 1 1h3l8 4V6L7 10H4a1 1 0 0 0-1 1z"/><path d="M18 8a5 5 0 0 1 0 8"/>',
        'flask' => '<path d="M9 3h6M10 3v6L4.5 19a2 2 0 0 0 1.7 3h11.6a2 2 0 0 0 1.7-3L14 9V3"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'building' => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4M8 6h.01M12 6h.01M16 6h.01M8 10h.01M12 10h.01M16 10h.01M8 14h.01M12 14h.01M16 14h.01"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
        'send' => '<path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'trash' => '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>',
        'alert' => '<path d="M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
        'zap' => '<path d="M13 2L3 14h9l-1 8 10-12h-9z"/>',
        'check' => '<path d="M20 6L9 17l-5-5"/>',
        'copy' => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'sparkle' => '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9zM19 16l.9 2.1L22 19l-2.1.9L19 22l-.9-2.1L16 19l2.1-.9z"/>',
        'activity' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'server' => '<rect x="2" y="3" width="20" height="8" rx="2"/><rect x="2" y="13" width="20" height="8" rx="2"/><path d="M6 7h.01M6 17h.01"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'box' => '<path d="M21 8l-9-5-9 5v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
        'cart' => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a2 2 0 0 0 2 1.6h8a2 2 0 0 0 2-1.6L21 7H6"/>',
        'funnel' => '<path d="M3 4h18l-7 8.5V19l-4 2v-8.5z"/>',
        'template' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>',
        'chart' => '<path d="M3 3v18h18"/><path d="M7 15l4-5 3 3 5-7"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    ];
    $p = $m[$name] ?? $m['sparkle'];
    return '<svg class="ic" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}
