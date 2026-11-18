<?php
use CaliChat\Core\Audit;
use CaliChat\Core\Auth;
use CaliChat\Core\Db;
use CaliChat\Services\AgentTests;

$title = 'Quality tests';
$owner = Auth::isOwner();
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); Auth::requireOwner(); $act = post('act'); $id = (int)post('id');
    if ($act === 'save') {
        $name = mb_substr(post('name'), 0, 120); $script = trim((string)($_POST['script'] ?? '')); $p = AgentTests::parse($script);
        if ($name === '') flash('Give the test a name.', 'err');
        elseif ($p['errors']) flash($p['errors'][0], 'err');
        else {
            if ($id && Db::val("SELECT id FROM agent_tests WHERE id=? AND tenant_id=?", [$id, $tenantId])) { Db::update('agent_tests', ['name' => $name, 'script' => $script, 'last_status' => 'never'], 'id=? AND tenant_id=?', [$id, $tenantId]); }
            elseif ((int)Db::val("SELECT COUNT(*) FROM agent_tests WHERE tenant_id=?", [$tenantId]) >= 60) { flash('At most 60 tests.', 'err'); redirect('app/quality'); }
            else { try { $id = Db::insert('agent_tests', ['tenant_id' => $tenantId, 'name' => $name, 'script' => $script]); } catch (\Throwable $e) { flash('A test with that name already exists.', 'err'); redirect('app/quality'); } }
            Audit::log('agent_test_save', 'test:' . $id, $name); flash('Test saved.'); redirect('app/quality?t=' . $id);
        }
        redirect('app/quality' . ($id ? '?t=' . $id : ''));
    }
    if ($act === 'delete') { Db::q("DELETE FROM agent_tests WHERE id=? AND tenant_id=?", [$id, $tenantId]); flash('Test deleted.'); redirect('app/quality'); }
    if ($act === 'starters') { $n = AgentTests::installStarters($tenantId); flash($n ? "$n starter tests added." : 'The starter tests are already there.'); redirect('app/quality'); }
    if ($act === 'run' || $act === 'run_all') {
        set_time_limit(280); session_write_close();
        $ids = $act === 'run' ? [$id] : array_column(Db::all("SELECT id FROM agent_tests WHERE tenant_id=? ORDER BY id LIMIT 8", [$tenantId]), 'id');
        $ok = 0; $bad = 0; $last = null;
        foreach ($ids as $tid1) { $r = AgentTests::run((int)$tenantId, (int)$tid1); $r['passed'] ? $ok++ : $bad++; $last = (int)$tid1; }
        Audit::log('agent_test_run', $act === 'run' ? 'test:' . $id : 'all', "$ok passed, $bad failed");
        @session_start(); flash($bad ? "$bad of " . ($ok + $bad) . ' test(s) failed. Open a test to see why.' : "All $ok test(s) passed.", $bad ? 'err' : 'ok'); redirect('app/quality' . ($act === 'run' && $last ? '?t=' . $last : ''));
    }
}
$tests = Db::all("SELECT * FROM agent_tests WHERE tenant_id=? ORDER BY id", [$tenantId]);
$sel = !empty($_GET['t']) ? Db::one("SELECT * FROM agent_tests WHERE id=? AND tenant_id=?", [(int)$_GET['t'], $tenantId]) : null;
$runs = $sel ? Db::all("SELECT * FROM agent_test_runs WHERE test_id=? AND tenant_id=? ORDER BY id DESC LIMIT 5", [$sel['id'], $tenantId]) : [];
$badge = ['never' => 'gray', 'passed' => 'ok', 'failed' => 'err'];
$f = $sel ?: ['name' => '', 'script' => "CUSTOMER: \nEXPECT tools: \nEXPECT reply_has: "];
?>
<div class="page-head"><div><h1>Agent <span class="accent">Quality tests</span></h1><p>Write the conversations your agent must handle well, run them with the real AI, and run them again after you change the briefing, tools or model. Tests use a made-up customer and never create real orders or messages.</p></div>
  <div class="actions"><?php if ($owner): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="starters"><button class="btn">Add starter tests</button></form><?php if ($tests): ?><form method="post" data-confirm="Run every test now? This uses AI tokens and can take a few minutes."><?= csrf_field() ?><input type="hidden" name="act" value="run_all"><button class="btn primary">Run all</button></form><?php endif; ?><?php endif; ?></div></div>

<div class="grid" style="grid-template-columns:1fr 1.2fr;align-items:start">
<div class="card"><h3>Your tests</h3><?php if (!$tests): ?><div class="empty">No tests yet. “Add starter tests” gives you six for a shop.</div><?php else: ?><div class="tablewrap"><table><thead><tr><th>Test</th><th>Last run</th><th></th></tr></thead><tbody><?php foreach ($tests as $t): ?><tr style="<?= $sel && $sel['id'] === $t['id'] ? 'background:var(--brand-50)' : '' ?>"><td><a href="<?= url('app/quality?t=' . $t['id']) ?>"><b><?= e($t['name']) ?></b></a></td><td><span class="badge <?= $badge[$t['last_status']] ?>"><?= e($t['last_status']) ?></span><?= $t['last_run_at'] ? '<div class="muted small">' . ago($t['last_run_at']) . '</div>' : '' ?></td>
  <td><?php if ($owner): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="run"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn sm">Run</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>

<div class="stack">
<?php if ($sel && $runs): $d = json_decode((string)$runs[0]['details'], true) ?: []; ?>
  <div class="card"><div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><h3 style="margin:0">Last result</h3><span class="badge <?= $runs[0]['passed'] ? 'ok' : 'err' ?>"><?= $runs[0]['passed'] ? 'passed' : 'failed' ?></span><span class="muted small"><?= ago($runs[0]['created_at']) ?> · <?= round($runs[0]['duration_ms'] / 1000, 1) ?> s · <?= e($runs[0]['model']) ?></span></div>
  <?php foreach ($d as $i => $turn): ?><div style="border-top:1px solid var(--line);margin-top:12px;padding-top:10px"><div class="small muted">Customer</div><div><?= e($turn['customer']) ?></div><div class="small muted" style="margin-top:6px">AI replied<?= $turn['tools'] ? ' (tools: ' . e(implode(', ', $turn['tools'])) . ')' : '' ?></div><div style="white-space:pre-wrap"><?= e($turn['reply'] ?: '(no reply)') ?></div>
    <?php foreach ($turn['checks'] as $c): ?><div class="small" style="margin-top:3px;color:var(--<?= $c['ok'] ? 'ok' : 'err' ?>)"><?= $c['ok'] ? '✓' : '✕' ?> <?= e($c['name']) ?><?= !$c['ok'] && $c['detail'] ? ' <span class="muted">(' . e($c['detail']) . ')</span>' : '' ?></div><?php endforeach; ?></div><?php endforeach; ?>
  <?php if (count($runs) > 1): ?><div class="small muted" style="margin-top:12px">Earlier runs: <?= e(implode(' · ', array_map(fn($r) => ($r['passed'] ? 'passed' : 'failed') . ' ' . ago($r['created_at']), array_slice($runs, 1)))) ?></div><?php endif; ?></div>
<?php endif; ?>
<?php if ($owner): ?><form method="post" class="card stack"><?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= (int)($sel['id'] ?? 0) ?>"><h3><?= $sel ? 'Edit test' : 'New test' ?></h3>
  <label>Name<input name="name" required value="<?= e($f['name']) ?>"></label>
  <label>Conversation <small>one CUSTOMER: line per message, then what you expect</small><textarea name="script" rows="9" class="mono" required><?= e($f['script']) ?></textarea></label>
  <details><summary class="small muted" style="cursor:pointer">What can I check?</summary><div class="small" style="margin-top:6px;line-height:1.7"><span class="mono">EXPECT tools:</span> the AI must use these tools (comma separated)<br><span class="mono">EXPECT not_tools:</span> it must not use these<br><span class="mono">EXPECT reply_has:</span> the reply mentions at least one of these words<br><span class="mono">EXPECT reply_lacks:</span> the reply mentions none of these<br><span class="mono">EXPECT handoff:</span> yes or no (passed to a person)<br><span class="mono">EXPECT language:</span> sinhala, tamil or english<br><span class="mono">EXPECT max_chars:</span> longest allowed reply<br>Lines starting with # are notes. Several CUSTOMER: lines make a longer chat; checks go under the message they belong to.</div></details>
  <div style="display:flex;gap:10px"><button class="btn primary">Save test</button><?php if ($sel): ?><a class="btn" href="<?= url('app/quality') ?>">New</a><button class="btn danger" name="act" value="delete" formnovalidate data-x="1">Delete</button><?php endif; ?></div></form><?php endif; ?>
</div></div>
