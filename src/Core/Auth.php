<?php
declare(strict_types=1);
namespace CaliChat\Core;

class Auth {
    private const IDLE_SECONDS = 1800;      // 30 min
    private const ABSOLUTE_SECONDS = 43200; // 12 h
    private const COMMON = ['password', 'password1', '123456789012', 'qwertyuiopas', 'letmein12345', 'welcome12345', 'admin1234567', 'calichat1234', 'changeme1234'];

    /** @return string ok | invalid | locked */
    public static function attempt(string $email, string $pass): string {
        $email = strtolower(trim($email)); $ip = client_ip();
        if (self::locked($email, $ip)) { Audit::log('login_locked', $email, '', null, 0); return 'locked'; }
        $u = Db::one("SELECT u.*, t.status AS tstatus FROM users u LEFT JOIN tenants t ON t.id=u.tenant_id WHERE u.email=? LIMIT 1", [$email]);
        // Always run a hash verification so response time doesn't reveal whether the account exists.
        $hash = $u['password_hash'] ?? '$2y$10$usesomesillystringforsaltandnotarealhashvalueabcdefghijklmn';
        $okPass = password_verify($pass, $hash);
        $ok = $u && $okPass && $u['status'] === 'active' && ($u['role'] === 'super_admin' || $u['tstatus'] === 'active');
        Db::insert('login_attempts', ['email' => mb_substr($email, 0, 190), 'ip' => $ip, 'ok' => $ok ? 1 : 0]);
        if (!$ok) { Audit::log('login_failed', $email, '', null, $u['id'] ?? 0); return 'invalid'; }
        session_regenerate_id(true);
        $_SESSION['first_login'] = $u['last_login'] === null && $u['role'] !== 'super_admin'; // first sign-in goes to the setup guide
        $_SESSION['uid'] = (int)$u['id']; $_SESSION['born'] = time(); $_SESSION['last'] = time();
        Db::update('users', ['last_login' => now()], 'id=?', [$u['id']]);
        Audit::log('login', $email, '', $u['tenant_id'] ? (int)$u['tenant_id'] : null, (int)$u['id']);
        return 'ok';
    }
    /** Lock an account after 5 failures in 15 min, or an IP after 20. */
    public static function locked(string $email, string $ip): bool {
        $e = (int)Db::val("SELECT COUNT(*) FROM login_attempts WHERE email=? AND ok=0 AND created_at > NOW() - INTERVAL 15 MINUTE", [$email]);
        $i = (int)Db::val("SELECT COUNT(*) FROM login_attempts WHERE ip=? AND ok=0 AND created_at > NOW() - INTERVAL 15 MINUTE", [$ip]);
        return $e >= 5 || $i >= 20;
    }
    public static function logout(): void { $_SESSION = []; if (session_status() === PHP_SESSION_ACTIVE) session_destroy(); }

    public static function user(): ?array {
        static $cache = null;
        if (empty($_SESSION['uid'])) return null;
        if ($cache === null || (int)$cache['id'] !== $_SESSION['uid']) {
            if (time() - ($_SESSION['born'] ?? 0) > self::ABSOLUTE_SECONDS || time() - ($_SESSION['last'] ?? 0) > self::IDLE_SECONDS) { self::logout(); return null; }
            $_SESSION['last'] = time();
            $cache = Db::one("SELECT u.id,u.tenant_id,u.name,u.email,u.role,u.status,u.must_change_password,u.permissions, t.status AS tstatus
                              FROM users u LEFT JOIN tenants t ON t.id=u.tenant_id WHERE u.id=?", [$_SESSION['uid']]);
        }
        // Disabled user or suspended tenant ends the session immediately.
        if (!$cache || $cache['status'] !== 'active' || ($cache['role'] !== 'super_admin' && $cache['tstatus'] !== 'active')) {
            $cache = null; unset($_SESSION['uid']); return null;
        }
        return $cache;
    }
    public static function isSuper(): bool { $u = self::user(); return $u && $u['role'] === 'super_admin'; }

    /** Active tenant id: own tenant, or the impersonated tenant for super admins. */
    public static function tenantId(): ?int {
        $u = self::user();
        if (!$u) return null;
        if ($u['role'] === 'super_admin') return !empty($_SESSION['impersonate']) ? (int)$_SESSION['impersonate'] : null;
        return $u['tenant_id'] ? (int)$u['tenant_id'] : null;
    }
    public static function require(string $area): array {
        $u = self::user();
        if (!$u) redirect('login');
        if ($u['must_change_password'] && ($_SERVER['REQUEST_URI'] ?? '') !== '' && strpos($_SERVER['REQUEST_URI'], '/account/') === false) redirect('account/password');
        if ($area === 'admin' && $u['role'] !== 'super_admin') redirect('app/dashboard');
        if ($area === 'tenant' && !self::tenantId()) redirect($u['role'] === 'super_admin' ? 'admin/tenants' : 'login');
        return $u;
    }
    public static function can(string $cap): bool { $u = self::user(); return $u && Perms::can($u, $cap); }
    public static function requireCan(string $cap): void {
        if (!self::can($cap)) { flash('Your account does not have permission to do that. Ask an owner to change your access on the Team page.', 'err'); redirect('app/dashboard'); }
    }
    public static function isOwner(): bool { $u = self::user(); return $u && in_array($u['role'], ['owner', 'super_admin'], true); }
    public static function requireOwner(): void {
        if (!self::isOwner()) { flash('Only owners can do that.', 'err'); redirect('app/dashboard'); }
    }

    public static function startImpersonation(int $tenantId, string $reason): void {
        $_SESSION['impersonate'] = $tenantId;
        Audit::log('impersonate_start', 'tenant:' . $tenantId, $reason, $tenantId);
    }
    public static function stopImpersonation(): void {
        if (!empty($_SESSION['impersonate'])) Audit::log('impersonate_stop', 'tenant:' . $_SESSION['impersonate'], '', (int)$_SESSION['impersonate']);
        unset($_SESSION['impersonate']);
    }

    /** @return string|null error message, or null when acceptable */
    public static function passwordProblem(string $pw, string $email = ''): ?string {
        if (strlen($pw) < 12) return 'Password must be at least 12 characters.';
        if (in_array(strtolower($pw), self::COMMON, true)) return 'That password is too common.';
        $classes = (preg_match('/[a-z]/', $pw) ? 1 : 0) + (preg_match('/[A-Z]/', $pw) ? 1 : 0) + (preg_match('/\d/', $pw) ? 1 : 0) + (preg_match('/[^A-Za-z0-9]/', $pw) ? 1 : 0);
        if ($classes < 2 && strlen($pw) < 16) return 'Use a mix of letters, numbers or symbols (or a longer passphrase).';
        $local = strtolower(explode('@', $email)[0] ?? '');
        if ($local !== '' && strlen($local) >= 4 && stripos($pw, $local) !== false) return 'Password must not contain your email name.';
        return null;
    }

    /** One-time link token. Returns the raw token (only its hash is stored). */
    public static function createToken(int $userId, string $purpose, int $hours): string {
        $raw = bin2hex(random_bytes(24));
        Db::q("UPDATE password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL", [$userId]);
        Db::insert('password_resets', ['user_id' => $userId, 'token_hash' => hash('sha256', $raw), 'purpose' => $purpose, 'expires_at' => gmdate('Y-m-d H:i:s', time() + $hours * 3600)]);
        return $raw;
    }
    public static function findToken(string $raw): ?array {
        if (!preg_match('/^[a-f0-9]{48}$/', $raw)) return null;
        return Db::one("SELECT r.*, u.email, u.name FROM password_resets r JOIN users u ON u.id=r.user_id WHERE r.token_hash=? AND r.used_at IS NULL AND r.expires_at > NOW()", [hash('sha256', $raw)]);
    }
    public static function setPassword(int $userId, string $pw): void {
        Db::update('users', ['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'must_change_password' => 0, 'password_changed_at' => now()], 'id=?', [$userId]);
    }
}
