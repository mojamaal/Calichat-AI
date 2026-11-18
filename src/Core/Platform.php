<?php
declare(strict_types=1);
namespace CaliChat\Core;

/** Platform-wide settings editable by the super admin (e.g. the Meta app used for one-click channel connect). */
class Platform {
    private const SECRET = ['meta_app_secret'];

    public static function get(string $key, ?string $default = null): ?string {
        $v = Db::val("SELECT svalue FROM platform_settings WHERE skey=?", [$key]);
        if ($v === null || $v === '') return $default;
        return in_array($key, self::SECRET, true) ? (Crypto::decrypt($v) ?? $default) : $v;
    }
    public static function set(string $key, ?string $value): void {
        $store = ($value !== null && $value !== '' && in_array($key, self::SECRET, true)) ? Crypto::encrypt($value) : $value;
        Db::q("INSERT INTO platform_settings (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue), updated_at=NOW()", [$key, $store]);
    }
}
