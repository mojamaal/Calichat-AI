<?php
use CaliChat\Core\Audit;
use CaliChat\Core\Auth;
use CaliChat\Core\Db;

$title = 'AI Agents';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); Auth::requireOwner();
    $act = post('act');
    if ($act === 'delete') {
        Db::q("DELETE FROM agents WHERE id=? AND tenant_id=?", [(int)post('id'), $tenantId]); // channels.agent_id → NULL via FK
        Audit::log('agent_delete', 'agent:' . (int)post('id'));
        flash('Agent deleted.'); redirect('app/agents');
    }
    $toolIds = array_values(array_filter(array_map('intval', (array)($_POST['tool_ids'] ?? []))));
    if ($toolIds) { $in = implode(',', array_fill(0, count($toolIds), '?')); $toolIds = array_map('intval', array_column(Db::all("SELECT id FROM tools WHERE tenant_id=? AND id IN ($in)", array_merge([$tenantId], $toolIds)), 'id')); }
    $d = ['name' => mb_substr(post('name', 'Assistant'), 0, 120), 'business_name' => mb_substr(post('business_name'), 0, 150), 'instructions' => mb_substr(post('instructions'), 0, 12000),
          'tone' => in_array(post('tone'), ['friendly', 'professional', 'casual', 'formal', 'playful'], true) ? post('tone') : 'friendly',
          'languages' => mb_substr(post('languages', 'English'), 0, 120), 'greeting' => mb_substr(post('greeting'), 0, 500), 'fallback_message' => mb_substr(post('fallback_message'), 0, 500),
          'temperature' => max(0, min(2, (float)post('temperature', '1'))), 'is_active' => isset($_POST['is_active']) ? 1 : 0,
          'tool_ids' => $toolIds ? implode(',', $toolIds) : null];
    if ($id = (int)post('id')) { Db::update('agents', $d, 'id=? AND tenant_id=?', [$id, $tenantId]); flash('Agent saved.'); }
    else { $id = Db::insert('agents', $d + ['tenant_id' => $tenantId]); flash('Agent created.'); }
    Audit::log('agent_save', 'agent:' . $id);
    redirect('app/agents');
}
$agents = Db::all("SELECT * FROM agents WHERE tenant_id=? ORDER BY id", [$tenantId]);
$edit = isset($_GET['id']) ? Db::one("SELECT * FROM agents WHERE id=? AND tenant_id=?", [(int)$_GET['id'], $tenantId]) : null;
$new = isset($_GET['new']);
$allTools = Db::all("SELECT id, name, method FROM tools WHERE tenant_id=? ORDER BY name", [$tenantId]);
?>
<div class="page-head"><div><h1>AI <span class="accent">Agents</span></h1><p>Define the persona, rules, tone and tools each agent uses when talking to your customers.</p></div>
  <div class="actions"><a class="btn primary" href="<?= url('app/agents?new=1') ?>"><?= icon('plus', 18) ?> New agent</a></div></div>

<?php if ($edit || $new): $a = $edit ?: ['id' => 0, 'name' => '', 'business_name' => '', 'instructions' => '', 'tone' => 'friendly', 'languages' => 'English, Sinhala, Tamil', 'greeting' => '', 'fallback_message' => '', 'temperature' => 1.0, 'is_active' => 1, 'tool_ids' => null];
      $sel = $a['tool_ids'] !== null && $a['tool_ids'] !== '' ? array_map('intval', explode(',', $a['tool_ids'])) : []; ?>
<form method="post" class="card stack"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
  <h3><?= $a['id'] ? 'Edit agent' : 'New agent' ?></h3>
  <div class="row"><label>Agent name<input name="name" required value="<?= e($a['name']) ?>" placeholder="e.g. Kavi"></label><label>Business name shown to customers<input name="business_name" value="<?= e($a['business_name']) ?>"></label></div>
  <label>Business instructions <small>What the agent should know and how to behave: products you sell, delivery areas, return policy, upsell rules, escalation rules…</small><textarea name="instructions" rows="9"><?= e($a['instructions']) ?></textarea></label>
  <div class="row">
    <label>Tone<select name="tone"><?php foreach (['friendly', 'professional', 'casual', 'formal', 'playful'] as $o): ?><option <?= $a['tone'] === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></label>
    <label>Languages<input name="languages" value="<?= e($a['languages']) ?>"></label>
    <label>Temperature (0–2) <small>1.0 is recommended for Gemini 3 models</small><input name="temperature" type="number" step="0.05" min="0" max="2" value="<?= e($a['temperature']) ?>"></label>
  </div>
  <fieldset style="border:1px solid var(--line);border-radius:14px;padding:12px 16px"><legend class="eyebrow">Tools this agent may use</legend>
    <p class="muted small" style="margin:0 0 8px">Leave all unchecked to allow every active tool. Fewer tools means faster, more accurate tool choice.</p>
    <?php foreach ($allTools as $t): ?><label class="check" style="margin-bottom:6px"><input type="checkbox" name="tool_ids[]" value="<?= $t['id'] ?>" <?= in_array((int)$t['id'], $sel, true) ? 'checked' : '' ?>> <span class="mono"><?= e($t['name']) ?></span> <?= $t['method'] !== 'GET' ? '<span class="badge warn">write</span>' : '' ?></label><?php endforeach; ?>
    <?php if (!$allTools): ?><span class="muted small">No tools yet. Add some in Connectors.</span><?php endif; ?></fieldset>
  <label>Fallback message <small>Sent if the AI service is unavailable (the chat is then handed to a human)</small><input name="fallback_message" value="<?= e($a['fallback_message']) ?>" placeholder="Sorry, we're having trouble. A team member will reply shortly."></label>
  <label class="check"><input type="checkbox" name="is_active" <?= $a['is_active'] ? 'checked' : '' ?>> Active</label>
  <div class="row" style="justify-content:flex-start"><button class="btn primary" style="flex:none">Save agent</button><a class="btn" style="flex:none" href="<?= url('app/agents') ?>">Cancel</a></div>
</form>
<?php else: ?>
<div class="grid g2">
<?php foreach ($agents as $a): ?>
  <div class="card"><div class="agent-head"><div class="ico vio"><?= icon('agent', 24) ?></div>
    <div class="info"><h3><?= e($a['name']) ?></h3><div class="muted small"><?= e($a['business_name'] ?: 'No business name') ?></div></div>
    <span class="badge <?= $a['is_active'] ? 'ok' : 'gray' ?>"><?= $a['is_active'] ? 'Active' : 'Paused' ?></span></div>
    <p class="muted small" style="margin:14px 0"><?= e(mb_substr((string)$a['instructions'], 0, 180) ?: 'No instructions yet.') ?></p>
    <span class="badge"><?= e($a['tone']) ?></span> <span class="badge gray"><?= e($a['languages']) ?></span> <span class="badge gray"><?= $a['tool_ids'] ? count(explode(',', $a['tool_ids'])) . ' tools' : 'all tools' ?></span>
    <div style="margin-top:16px;display:flex;gap:8px"><a class="btn sm" href="<?= url('app/agents?id=' . $a['id']) ?>">Edit</a>
      <form method="post" data-confirm="Delete this agent?"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button class="btn sm danger">Delete</button></form></div></div>
<?php endforeach; ?>
<?php if (!$agents): ?><div class="card empty" style="grid-column:1/-1">No agents yet. Create your first one.</div><?php endif; ?>
</div>
<?php endif; ?>
