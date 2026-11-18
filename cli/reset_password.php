<?php
declare(strict_types=1);
/** Reset a user's password and print a new one:  php cli/reset_password.php user@example.com   (use after restoring a backup, or if locked out) */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require dirname(__DIR__) . '/config/bootstrap.php';

use CaliChat\Core\Db;

$email = strtolower(trim($argv[1] ?? ''));
if ($email === '') exit("Usage: php cli/reset_password.php user@example.com\n");
$u = Db::one("SELECT id, name, role FROM users WHERE email=?", [$email]);
if (!$u) exit("No user with that email. Existing users:\n" . implode("\n", array_map(fn($r) => '  ' . $r['email'] . ' (' . $r['role'] . ')', Db::all("SELECT email, role FROM users ORDER BY id"))) . "\n");
$pw = bin2hex(random_bytes(9)) . 'Aa1!';
Db::update('users', ['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'must_change_password' => 1, 'status' => 'active'], 'id=?', [$u['id']]);
Db::q("DELETE FROM login_attempts WHERE email=?", [$email]);
echo "New password for $email: $pw\n(you will be asked to choose your own at first sign-in)\n";
