<?php
use CaliChat\Core\Audit;
use CaliChat\Core\Auth;
use CaliChat\Core\Db;
use CaliChat\Services\Appointments;
use CaliChat\Services\Store;
use CaliChat\Services\StoreError;

$title = 'Appointments';
$owner = Auth::can('store_edit'); $canBook = Auth::can('appointments_edit');
$tzName = get_tz();
$today = (new DateTime('now', new DateTimeZone($tzName)))->format('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = post('act'); $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', post('date')) ? post('date') : $today;
    try {
        if (in_array($act, ['svc_save', 'svc_toggle', 'svc_delete'], true)) {
            Auth::requireCan('store_edit');
            if ($act === 'svc_save') {
                $name = mb_substr(post('name'), 0, 100); if ($name === '') throw new StoreError('Service name is required', 422);
                $d = ['name' => $name, 'duration_min' => max(5, min(480, (int)post('duration_min', '30'))), 'price' => max(0, (float)post('price')), 'capacity' => max(1, min(20, (int)post('capacity', '1'))), 'is_active' => 1];
                $id = (int)post('id');
                if ($id) Db::update('appt_services', $d, 'id=? AND tenant_id=?', [$id, $tenantId]);
                else Db::q("INSERT INTO appt_services (tenant_id, name, duration_min, price, capacity) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE duration_min=VALUES(duration_min), price=VALUES(price), capacity=VALUES(capacity), is_active=1", [$tenantId, $d['name'], $d['duration_min'], $d['price'], $d['capacity']]);
                flash('Service saved.');
            } elseif ($act === 'svc_toggle') { Db::q("UPDATE appt_services SET is_active = 1 - is_active WHERE id=? AND tenant_id=?", [(int)post('id'), $tenantId]); }
            else { Db::q("DELETE FROM appt_services WHERE id=? AND tenant_id=?", [(int)post('id'), $tenantId]); flash('Service and its appointments deleted.'); }
            Audit::log('appointment_service', $act); if (Store::connected($tenantId)) Store::connect($tenantId); // the AI's booking tools follow the services you offer
        } elseif ($act === 'appt_status') {
            Auth::requireCan('appointments_edit');
            $a = Appointments::setStatus($tenantId, (int)post('id'), post('status')); Audit::log('appointment_status', 'appt:' . $a['id'], $a['status']); flash('Updated.');
        } elseif ($act === 'appt_book') {
            Auth::requireCan('appointments_edit');
            $a = Appointments::book($tenantId, (int)post('service_id'), post('phone'), post('name'), post('date') . ' ' . post('time'), 'manual', post('notes')); flash('Booked.' . (Db::val("SELECT COUNT(*) FROM automations WHERE tenant_id=? AND trigger_type='appointment_booked' AND is_active=1", [$tenantId]) ? ' A confirmation is scheduled for the customer.' : ''));
        }
    } catch (StoreError $e) { flash($e->getMessage(), 'err'); }
    redirect('app/appointments?date=' . $date);
}

$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date'] ?? '')) ? $_GET['date'] : $today;
$prev = (new DateTime($date))->modify('-1 day')->format('Y-m-d'); $next = (new DateTime($date))->modify('+1 day')->format('Y-m-d');
$services = Appointments::services($tenantId, false); $active = array_values(array_filter($services, fn($s) => $s['is_active']));
[$from, $to] = \CaliChat\Core\Tenant::dayRangeUtc($tzName, $date);
$rows = Db::all("SELECT a.*, s.name service FROM appointments a JOIN appt_services s ON s.id=a.service_id WHERE a.tenant_id=? AND a.start_at >= ? AND a.start_at < ? ORDER BY a.start_at", [$tenantId, $from, $to]);
$upcoming = (int)Db::val("SELECT COUNT(*) FROM appointments WHERE tenant_id=? AND status='booked' AND start_at > UTC_TIMESTAMP()", [$tenantId]);
$bs = (int)($_GET['bs'] ?? ($active[0]['id'] ?? 0)); $bd = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['bd'] ?? '')) ? $_GET['bd'] : $date;
$free = []; $slotErr = '';
if ($bs && isset($_GET['bs'])) { try { $free = Appointments::slots($tenantId, $bs, $bd); } catch (StoreError $e) { $slotErr = $e->getMessage(); } }
$h = Appointments::hours($tenantId); $dn = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$badge = ['booked' => 'warn', 'completed' => 'ok', 'cancelled' => 'gray', 'no_show' => 'err'];
?>
<div class="page-head"><div><h1>Customer <span class="accent">Appointments</span></h1><p>Bookings made by the AI agent or your team. Open hours: <?= e(implode(', ', array_map(fn($d) => $dn[$d], $h['days']))) ?>, <?= e($h['start']) ?> to <?= e($h['end']) ?> (change in Setup guide / Settings). <?= $upcoming ?> upcoming.</p></div></div>

<div class="grid" style="grid-template-columns:1.5fr 1fr;align-items:start">
<div class="card"><div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px"><a class="btn sm" href="<?= url('app/appointments?date=' . $prev) ?>">&larr;</a><h3 style="margin:0"><?= e((new DateTime($date))->format('l, j F Y')) ?><?= $date === $today ? ' <span class="badge">today</span>' : '' ?></h3><a class="btn sm" href="<?= url('app/appointments?date=' . $next) ?>">&rarr;</a><?php if ($date !== $today): ?><a class="btn sm" href="<?= url('app/appointments') ?>">Today</a><?php endif; ?></div>
  <?php if (!$rows): ?><div class="empty">No appointments on this day.</div><?php else: ?><div class="tablewrap"><table><thead><tr><th>Time</th><th>Customer</th><th>Service</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $a): ?><tr><td><b><?= e(Appointments::toLocal($tenantId, $a['start_at'], 'H:i')) ?></b><div class="muted small"><?= e($a['source'] === 'ai' ? 'by AI agent' : 'by team') ?></div></td><td><?= e($a['customer_name'] ?: '—') ?><div class="muted small mono"><?= e(phone_view($a['phone'])) ?></div><?= $a['notes'] ? '<div class="small">' . e($a['notes']) . '</div>' : '' ?></td><td><?= e($a['service']) ?></td><td><span class="badge <?= $badge[$a['status']] ?>"><?= e(str_replace('_', ' ', $a['status'])) ?></span></td>
    <td style="white-space:nowrap"><?php if ($canBook && $a['status'] === 'booked'): foreach (['completed' => 'Done', 'no_show' => 'No-show', 'cancelled' => 'Cancel'] as $k => $l): if ($k === 'cancelled' && $a['start_at'] <= gmdate('Y-m-d H:i:s')) continue; ?><form method="post" style="display:inline"<?= $k === 'cancelled' ? ' data-confirm="Cancel this appointment?"' : '' ?>><?= csrf_field() ?><input type="hidden" name="act" value="appt_status"><input type="hidden" name="id" value="<?= $a['id'] ?>"><input type="hidden" name="date" value="<?= e($date) ?>"><input type="hidden" name="status" value="<?= $k ?>"><button class="btn sm <?= $k === 'cancelled' ? 'danger' : '' ?>"><?= $l ?></button></form> <?php endforeach; endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>

<div class="stack">
<div class="card stack"><h3>Book an appointment</h3>
  <?php if (!$canBook): ?><div class="muted small">You can view appointments but not book them.</div><?php elseif (!$active): ?><div class="muted small">Add a service first (right below).</div><?php else: ?>
  <form method="get" class="stack"><input type="hidden" name="date" value="<?= e($date) ?>"><div class="row"><label>Service<select name="bs"><?php foreach ($active as $s): ?><option value="<?= $s['id'] ?>" <?= $bs === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?> (<?= (int)$s['duration_min'] ?> min)</option><?php endforeach; ?></select></label><label>Date<input type="date" name="bd" value="<?= e($bd) ?>" min="<?= e($today) ?>"></label></div><button class="btn">Show free times</button></form>
  <?php if ($slotErr): ?><div class="flash err"><?= e($slotErr) ?></div><?php elseif (isset($_GET['bs'])): ?>
    <?php if (!$free): ?><div class="muted small">Nothing free on <?= e($bd) ?> (closed, fully booked or too soon).</div><?php else: ?>
    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="act" value="appt_book"><input type="hidden" name="service_id" value="<?= $bs ?>"><input type="hidden" name="date" value="<?= e($bd) ?>">
      <label>Free times on <?= e($bd) ?><select name="time"><?php foreach ($free as $t): ?><option><?= e($t) ?></option><?php endforeach; ?></select></label>
      <div class="row"><label>Customer name<input name="name" required></label><label>Phone <small>with country code</small><input name="phone" required placeholder="94771234567"></label></div><label>Note<input name="notes"></label><button class="btn primary">Book</button></form><?php endif; ?><?php endif; ?><?php endif; ?></div>

<div class="card"><h3>Services</h3><?php if (!$services): ?><p class="sub">Add what customers can book: a haircut, a check-up, a viewing. The AI agent can then offer free times and book them.</p><?php endif; ?>
  <?php foreach ($services as $s): ?><form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:8px;<?= $s['is_active'] ? '' : 'opacity:.55' ?>"><?= csrf_field() ?><input type="hidden" name="act" value="svc_save"><input type="hidden" name="id" value="<?= $s['id'] ?>"><input type="hidden" name="date" value="<?= e($date) ?>">
    <input name="name" value="<?= e($s['name']) ?>" aria-label="Service name" style="flex:1;min-width:120px" <?= $owner ? '' : 'disabled' ?>><input name="duration_min" type="number" min="5" value="<?= (int)$s['duration_min'] ?>" aria-label="Minutes" style="width:70px" <?= $owner ? '' : 'disabled' ?>><span class="muted small">min</span><input name="price" type="number" min="0" value="<?= e($s['price']) ?>" aria-label="Price" style="width:90px" <?= $owner ? '' : 'disabled' ?>><input name="capacity" type="number" min="1" value="<?= (int)$s['capacity'] ?>" aria-label="At the same time" title="How many at the same time" style="width:55px" <?= $owner ? '' : 'disabled' ?>>
    <?php if ($owner): ?><button class="btn sm">Save</button><?php endif; ?></form>
    <?php if ($owner): ?><div style="display:flex;gap:6px;margin:-4px 0 10px"><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="svc_toggle"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button class="btn sm"><?= $s['is_active'] ? 'Hide' : 'Show' ?></button></form><form method="post" data-confirm="Delete this service and all its appointments?"><?= csrf_field() ?><input type="hidden" name="act" value="svc_delete"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button class="btn sm danger">Delete</button></form></div><?php endif; ?><?php endforeach; ?>
  <?php if ($owner): ?><form method="post" class="stack" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="act" value="svc_save"><input type="hidden" name="date" value="<?= e($date) ?>"><b class="small">Add a service</b>
    <div class="row"><label>Name<input name="name" required placeholder="Haircut"></label><label>Minutes<input name="duration_min" type="number" min="5" value="30"></label></div><div class="row"><label>Price (Rs.)<input name="price" type="number" min="0" value="0"></label><label>At the same time<input name="capacity" type="number" min="1" value="1"></label></div><button class="btn">Add service</button></form><?php endif; ?></div>
</div></div>
