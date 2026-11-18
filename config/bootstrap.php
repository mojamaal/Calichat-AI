<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
define('APP_START', microtime(true));

function env_load(string $file): void {
    if (!is_file($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        if (getenv($k) !== false) continue; // real process env wins (used by tests / containers)
        $_ENV[$k] = trim($v, " \t\"'");
    }
}
function env(string $k, $d = null) {
    if (isset($_ENV[$k]) && $_ENV[$k] !== '') return $_ENV[$k];
    $g = getenv($k);
    return ($g !== false && $g !== '') ? $g : $d;
}

// A secrets file outside the web root takes priority when present.
$outside = dirname(ROOT) . DIRECTORY_SEPARATOR . 'calichat.env';
env_load(is_file($outside) ? $outside : ROOT . '/.env');
date_default_timezone_set('UTC'); // all stored times are UTC; shown in each tenant's timezone

spl_autoload_register(function (string $c) {
    if (strpos($c, 'CaliChat\\') !== 0) return;
    $p = ROOT . '/src/' . str_replace('\\', '/', substr($c, 9)) . '.php';
    if (is_file($p)) require $p;
});

require __DIR__ . '/helpers.php';

define('APP_ENV', (string)env('APP_ENV', 'local')); // local | production
define('IS_PROD', APP_ENV === 'production');
// Production forces safe values regardless of .env mistakes.
define('APP_DEBUG', !IS_PROD && env('APP_DEBUG', '0') === '1');
define('ALLOW_PRIVATE_HOSTS', !IS_PROD && env('ALLOW_PRIVATE_HOSTS', '0') === '1');

ini_set('display_errors', APP_DEBUG ? '1' : '0');
error_reporting(APP_DEBUG ? E_ALL : (E_ALL & ~E_DEPRECATED & ~E_NOTICE));
ini_set('log_errors', '1');
ini_set('error_log', ROOT . '/storage/logs/php.log');

CaliChat\Core\Log::init();

function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (env('TRUST_PROXY', '0') === '1' && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

if (PHP_SAPI !== 'cli') {
    session_name('calichat');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => is_https(), 'path' => '/']);
    session_start();
    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        if (is_https()) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        header('X-Request-Id: ' . CaliChat\Core\Log::requestId());
    }
}
