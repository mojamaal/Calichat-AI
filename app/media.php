<?php
declare(strict_types=1);
/** Public brand media: /media/logo/{tenant} and /media/card/{tenant}/{sku} (the branded product card WhatsApp fetches). Only active tenants and visible products. */
use CaliChat\Core\Db;
use CaliChat\Core\Guard;
use CaliChat\Services\Brand;

if (session_status() === PHP_SESSION_ACTIVE) session_write_close(); // public images need no session; releasing its lock lets a browser load many pictures in parallel
$kind = (string)($seg[1] ?? ''); $tid = (int)($seg[2] ?? 0);
if (!Guard::allow('media:' . client_ip(), 600)) { http_response_code(429); exit('Too many requests'); }
$active = $tid > 0 && Db::val("SELECT status FROM tenants WHERE id=?", [$tid]) === 'active';
$send = function (string $bytes) { header('Content-Type: image/png'); header('X-Content-Type-Options: nosniff'); header('Cache-Control: public, max-age=86400'); header('Content-Length: ' . strlen($bytes)); echo $bytes; exit; };
if ($active && $kind === 'logo' && is_file(Brand::logoPath($tid))) $send((string)file_get_contents(Brand::logoPath($tid)));
if ($active && $kind === 'card') {
    $sku = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string)($seg[3] ?? '')));
    $p = $sku !== '' ? Db::one("SELECT * FROM store_products WHERE tenant_id=? AND sku=? AND is_active=1", [$tid, $sku]) : null;
    if ($p && ($png = Brand::cardPng($tid, $p))) $send($png);
}
if ($active && $kind === 'art') { // built-in product picture (drawn once, then cached on disk)
    $sku = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string)($seg[3] ?? '')));
    $p = $sku !== '' ? Db::one("SELECT * FROM store_products WHERE tenant_id=? AND sku=? AND is_active=1", [$tid, $sku]) : null;
    if ($p) {
        $dir = Brand::storage() . '/art/' . $tid; $file = $dir . '/' . $sku . '-' . substr(sha1($p['name'] . '|' . $p['colors'] . '|' . $p['category'] . '|v2'), 0, 10) . '.png';
        if (!is_file($file)) { if (!is_dir($dir)) @mkdir($dir, 0775, true); foreach (glob($dir . '/' . $sku . '-*.png') ?: [] as $old) @unlink($old); @file_put_contents($file, \CaliChat\Services\ProductArt::render($p)); }
        if (is_file($file)) $send((string)file_get_contents($file));
    }
}
if ($active && $kind === 'photo') {
    $sku = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string)($seg[3] ?? '')));
    $p = $sku !== '' ? Db::one("SELECT * FROM store_products WHERE tenant_id=? AND sku=? AND is_active=1", [$tid, $sku]) : null;
    if ($p && ($jpg = Brand::photo($tid, $p))) { header('Content-Type: image/jpeg'); header('X-Content-Type-Options: nosniff'); header('Cache-Control: public, max-age=86400'); header('Content-Length: ' . strlen($jpg)); echo $jpg; exit; }
}
http_response_code(404); header('Content-Type: text/plain'); echo 'Not found';
