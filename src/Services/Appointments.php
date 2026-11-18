<?php
declare(strict_types=1);
namespace CaliChat\Services;

use CaliChat\Core\Audit;
use CaliChat\Core\Db;
use CaliChat\Core\Tenant;

/**
 * Appointments: services (a haircut, a dental check-up), free slots inside the tenant's business hours, booking without double-booking.
 * Used by the dashboard page and by the AI's tools through the store API.
 */
class Appointments {
    public const STATUSES = ['booked', 'completed', 'cancelled', 'no_show'];

    /** @return array{days:int[],start:string,end:string} opening days (1=Mon..7=Sun) and hours from the tenant profile; Mon-Sat 09:00-17:00 when unset */
    public static function hours(int $tid): array {
        $h = Tenant::get($tid)['cfg']['hours'] ?? [];
        $days = array_values(array_filter(array_map('intval', (array)($h['days'] ?? [])), fn($d) => $d >= 1 && $d <= 7));
        return ['days' => $days ?: [1, 2, 3, 4, 5, 6], 'start' => preg_match('/^\d{2}:\d{2}$/', (string)($h['start'] ?? '')) ? $h['start'] : '09:00', 'end' => preg_match('/^\d{2}:\d{2}$/', (string)($h['end'] ?? '')) ? $h['end'] : '17:00'];
    }
    private static function cfgInt(int $tid, string $key, int $default): int { return (int)(Tenant::get($tid)['cfg'][$key] ?? $default); }

    public static function services(int $tid, bool $onlyActive = true): array {
        return Db::all("SELECT id, name, duration_min, price, capacity, is_active FROM appt_services WHERE tenant_id=?" . ($onlyActive ? ' AND is_active=1' : '') . " ORDER BY name", [$tid]);
    }

    private static function tz(int $tid): \DateTimeZone { return new \DateTimeZone(Tenant::tz($tid)); }
    private static function toUtc(int $tid, string $local): string { $d = new \DateTime($local, self::tz($tid)); return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
    public static function toLocal(int $tid, string $utc, string $fmt = 'Y-m-d H:i'): string { $d = new \DateTime($utc, new \DateTimeZone('UTC')); return $d->setTimezone(self::tz($tid))->format($fmt); }

    /**
     * Free start times (local "HH:MM") on a local date for a service.
     * @return string[]
     * @throws StoreError
     */
    public static function slots(int $tid, int $serviceId, string $date, ?int $nowTs = null): array {
        $svc = Db::one("SELECT * FROM appt_services WHERE id=? AND tenant_id=? AND is_active=1", [$serviceId, $tid]);
        if (!$svc) throw new StoreError('Unknown service', 404);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) throw new StoreError('date must look like 2026-12-31', 422);
        $now = $nowTs ?? time(); $tz = self::tz($tid);
        $day = new \DateTime($date . ' 00:00:00', $tz); $today = (new \DateTime('@' . $now))->setTimezone($tz)->setTime(0, 0);
        $horizon = self::cfgInt($tid, 'appt_horizon_days', 30);
        if ($day < $today) return []; if ($day->diff($today)->days > $horizon) throw new StoreError("Appointments can be booked up to $horizon days ahead.", 422);
        $h = self::hours($tid); if (!in_array((int)$day->format('N'), $h['days'], true)) return [];
        $dur = max(5, (int)$svc['duration_min']); $notice = self::cfgInt($tid, 'appt_notice_min', 60) * 60;
        $open = new \DateTime("$date {$h['start']}:00", $tz); $close = new \DateTime("$date {$h['end']}:00", $tz);
        $booked = Db::all("SELECT start_at, end_at FROM appointments WHERE tenant_id=? AND service_id=? AND status IN ('booked','completed') AND start_at < ? AND end_at > ?", [$tid, $serviceId, self::toUtc($tid, "$date 23:59:59"), self::toUtc($tid, "$date 00:00:00")]);
        $out = [];
        for ($t = clone $open; ; $t->modify("+$dur minutes")) {
            $end = (clone $t)->modify("+$dur minutes"); if ($end > $close) break;
            if ($t->getTimestamp() < $now + $notice) continue;
            $s = $t->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'); $e = $end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'); $t->setTimezone($tz);
            $n = 0; foreach ($booked as $b) if ($b['start_at'] < $e && $b['end_at'] > $s) $n++;
            if ($n < (int)$svc['capacity']) $out[] = $t->format('H:i');
        }
        return $out;
    }

    public static function view(int $tid, array $a): array {
        $svc = Db::one("SELECT name, price, duration_min FROM appt_services WHERE id=?", [$a['service_id']]) ?: ['name' => '?', 'price' => 0, 'duration_min' => 0];
        return ['id' => (int)$a['id'], 'service' => $svc['name'], 'price' => (float)$svc['price'], 'customer_name' => $a['customer_name'], 'date' => self::toLocal($tid, $a['start_at'], 'Y-m-d'), 'time' => self::toLocal($tid, $a['start_at'], 'H:i'),
            'day' => self::toLocal($tid, $a['start_at'], 'l j F'), 'duration_min' => (int)$svc['duration_min'], 'status' => $a['status']];
    }

    /** @throws StoreError */
    public static function book(int $tid, int $serviceId, string $phone, string $name, string $startLocal, string $source = 'manual', string $notes = '', ?int $nowTs = null): array {
        $phone = Store::digits($phone); if (strlen($phone) < 9) throw new StoreError('A valid phone number with country code is required', 422);
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})$/', trim($startLocal), $m)) throw new StoreError('start must look like 2026-12-31 14:30', 422);
        $svc = Db::one("SELECT * FROM appt_services WHERE id=? AND tenant_id=? AND is_active=1", [$serviceId, $tid]); if (!$svc) throw new StoreError('Unknown service', 404);
        if (!Db::lock("appt:$tid:$serviceId", 5)) throw new StoreError('Busy, please try again', 409);
        try {
            $startUtc = self::toUtc($tid, "{$m[1]} {$m[2]}:00"); $endUtc = (new \DateTime($startUtc, new \DateTimeZone('UTC')))->modify('+' . (int)$svc['duration_min'] . ' minutes')->format('Y-m-d H:i:s');
            $same = Db::one("SELECT * FROM appointments WHERE tenant_id=? AND phone=? AND service_id=? AND start_at=? AND status='booked'", [$tid, $phone, $serviceId, $startUtc]);
            if ($same) return $same; // idempotent: the same customer asking again for the booking they already hold
            if (!in_array($m[2], self::slots($tid, $serviceId, $m[1], $nowTs), true)) throw new StoreError("That time is not available. Ask for the free times again.", 409);
            if ((int)Db::val("SELECT COUNT(*) FROM appointments WHERE tenant_id=? AND phone=? AND status='booked' AND start_at > UTC_TIMESTAMP()", [$tid, $phone]) >= 3) throw new StoreError('This customer already has 3 upcoming appointments. A team member can add more.', 409);
            $id = Db::insert('appointments', ['tenant_id' => $tid, 'service_id' => $serviceId, 'phone' => $phone, 'customer_name' => mb_substr(trim($name), 0, 100) ?: null, 'start_at' => $startUtc, 'end_at' => $endUtc, 'status' => 'booked', 'notes' => mb_substr(trim($notes), 0, 300) ?: null, 'source' => $source]);
        } finally { Db::unlock("appt:$tid:$serviceId"); }
        Audit::log('appointment_book', 'appt:' . $id, $source, $tid);
        if (trim($name) !== '') Store::saveProfile($tid, $phone, ['name' => $name]);
        Automations::emit($tid, 'appointment_booked', 'appt', $id);
        return Db::one("SELECT * FROM appointments WHERE id=?", [$id]);
    }

    /** @throws StoreError */
    public static function setStatus(int $tid, int $id, string $status, ?string $phone = null): array {
        if (!in_array($status, self::STATUSES, true)) throw new StoreError('Unknown status', 422);
        $a = Db::one("SELECT * FROM appointments WHERE id=? AND tenant_id=?" . ($phone !== null ? ' AND phone=?' : ''), $phone !== null ? [$id, $tid, Store::digits($phone)] : [$id, $tid]);
        if (!$a) throw new StoreError('appointment not found', 404);
        if ($a['status'] === 'cancelled' && $status === 'cancelled') throw new StoreError('This appointment is already cancelled.', 409);
        if ($status === 'cancelled' && $a['status'] !== 'booked') throw new StoreError('Only a booked appointment can be cancelled (it is ' . $a['status'] . ').', 409);
        if ($status === 'cancelled' && $a['start_at'] <= gmdate('Y-m-d H:i:s')) throw new StoreError('That appointment time has already passed.', 409);
        Db::update('appointments', ['status' => $status, 'updated_at' => now()], 'id=?', [$id]);
        return Db::one("SELECT * FROM appointments WHERE id=?", [$id]);
    }
}
