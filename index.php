<?php
declare(strict_types=1);

if (PHP_SAPI === 'cli-server') { // dev server: let real static files through
    $f = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($f) && !preg_match('/\.(php|env)$/', $f)) return false;
}
require __DIR__ . '/config/bootstrap.php';

use CaliChat\Core\Auth;
use CaliChat\Core\Db;
use CaliChat\Core\Log;
use CaliChat\Core\Tenant;
use CaliChat\Services\Queue;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = base_path();
if ($base !== '' && strpos($path, $base) === 0) $path = substr($path, strlen($base));
$seg = array_values(array_filter(explode('/', trim($path, '/')), fn($s) => $s !== ''));
$area = $seg[0] ?? '';
$page = preg_replace('/[^a-z_]/', '', $seg[1] ?? 'dashboard');

try {
    switch ($area) {
        case '':
            if (Auth::user() && !empty($_SESSION['first_login']) && Auth::tenantId()) { unset($_SESSION['first_login']); redirect('app/onboarding'); } // first sign-in: guided setup
            redirect(Auth::user() ? (Auth::isSuper() && !Auth::tenantId() ? 'admin/dashboard' : 'app/dashboard') : 'login');
        case 'login':
            require ROOT . '/app/login.php'; break;
        case 'logout':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_check(); \CaliChat\Core\Audit::log('logout'); Auth::logout(); }
            redirect('login');
        case 'signup': // public self sign-up
            require ROOT . '/app/signup.php'; break;
        case 'reset':
            require ROOT . '/app/reset.php'; break;
        case 'account':
            if (!Auth::user()) redirect('login');
            require ROOT . '/app/account.php'; break;
        case 'health': // minimal, unauthenticated: safe for uptime monitors
            header('Cache-Control: no-store');
            try { Db::val("SELECT 1"); $db = true; } catch (\Throwable $e) { $db = false; }
            $lag = $db ? (int)Db::val("SELECT COALESCE(TIMESTAMPDIFF(SECOND, MIN(received_at), NOW()),0) FROM inbound_events WHERE status IN ('received','retry') AND next_attempt_at <= NOW()") : null;
            $ok = $db && ($lag ?? 0) < 300;
            json_out(['status' => $ok ? 'ok' : 'degraded', 'db' => $db, 'queue_lag_s' => $lag, 'time' => gmdate('c')], $ok ? 200 : 503);
        case 'webhook':
            require ROOT . (($seg[1] ?? '') === 'meta' ? '/app/webhook_meta.php' : '/app/webhook.php'); break;
        case 'meta':
            require ROOT . '/app/meta.php'; break;
        case 'mock':
            require ROOT . '/app/mock_erp.php'; break;
        case 'invoice': // customer-facing invoice (signed link)
            require ROOT . '/app/invoice.php'; break;
        case 'shop': // customer-facing branded catalogue + cart + order form (signed, customer-bound link)
            require ROOT . '/app/shop.php'; break;
        case 'media': // public brand logo and branded product cards
            require ROOT . '/app/media.php'; break;
        case 'store': // built-in store API used by the AI's tools (bearer token per tenant)
            require ROOT . '/app/store_api.php'; break;
        case 'api':
            require ROOT . '/app/api.php'; break;
        case 'app':
        case 'admin':
            $u = Auth::require($area === 'admin' ? 'admin' : 'tenant');
            $file = ROOT . "/app/pages/" . ($area === 'admin' ? 'admin' : 'tenant') . "/$page.php";
            if (!is_file($file)) { http_response_code(404); $file = ROOT . '/app/pages/404.php'; }
            $nav = $area; $tenantId = Auth::tenantId();
            if ($area === 'app' && ($need = \CaliChat\Core\Perms::pageCap($page)) && !Auth::can($need)) { flash('Your account does not have access to that page. Ask an owner to change your access on the Team page.', 'err'); redirect('app/dashboard'); }
            if ($tenantId) { Log::with(['tenant' => $tenantId, 'user' => (int)$u['id']]); set_tz(Tenant::tz($tenantId)); }
            header('Cache-Control: no-store');
            require ROOT . '/app/layout.php'; break;
        default:
            http_response_code(404); echo 'Not found';
    }
} catch (\Throwable $e) {
    Log::exception($e, 'unhandled');
    error_log((string)$e);
    http_response_code(500);
    echo APP_DEBUG ? '<pre>' . e((string)$e) . '</pre>' : 'Something went wrong. Reference: ' . e(Log::requestId());
}
