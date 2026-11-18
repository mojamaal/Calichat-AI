<?php
use CaliChat\Core\Db;
use CaliChat\Services\Actions;

$title = 'Approvals';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $r = Actions::decide((int)$tenantId, (int)post('id'), post('decision') === 'approve', $u);
    flash($r['message'], $r['ok'] ? 'ok' : 'err'); redirect('app/approvals');
}
$pending = Db::all("SELECT a.*, ct.name cname, ct.phone, c.channel_type FROM pending_actions a JOIN conversations c ON c.id=a.conversation_id JOIN contacts ct ON ct.id=a.contact_id WHERE a.tenant_id=? AND a.status='pending' ORDER BY a.id", [$tenantId]);
$done = Db::all("SELECT a.*, ct.name cname, u.name by_name FROM pending_actions a JOIN contacts ct ON ct.id=a.contact_id LEFT JOIN users u ON u.id=a.decided_by WHERE a.tenant_id=? AND a.status<>'pending' ORDER BY a.id DESC LIMIT 15", [$tenantId]);
$aiDone = Db::all("SELECT created_at, tool, request_summary, ok FROM tool_logs WHERE tenant_id=? AND is_write=1 ORDER BY id DESC LIMIT 15", [$tenantId]);
?>
<div class="page-head"><div><h1>AI <span class="accent">Approvals</span></h1><p>Actions the AI wants to take on tools marked “requires team approval”. The customer has already confirmed each one. Approving runs it and tells the customer.</p></div></div>
<div class="card"><h3>Waiting for you</h3><p class="sub"><?= count($pending) ?> request<?= count($pending) === 1 ? '' : 's' ?></p>
<?php if (!$pending): ?><div class="empty">Nothing waiting. 🎉</div><?php endif; ?>
<?php foreach ($pending as $a): ?><div class="alert-item"><span class="sev warn"></span><div style="flex:1"><b><?= e($a['cname'] ?: $a['phone'] ?: 'Customer') ?></b> <span class="badge gray"><?= e($a['channel_type']) ?></span> <span class="muted small"><?= ago($a['created_at']) ?></span>
  <div class="mono" style="margin:6px 0;word-break:break-word"><?= e($a['summary']) ?></div><a class="small" href="<?= url('app/inbox?c=' . (int)$a['conversation_id']) ?>">Open conversation</a></div>
  <form method="post" style="display:flex;gap:6px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $a['id'] ?>"><button class="btn sm primary" name="decision" value="approve">Approve</button><button class="btn sm danger" name="decision" value="reject" data-confirm-x>Reject</button></form></div><?php endforeach; ?></div>

<div class="grid g2" style="margin-top:22px">
  <div class="card"><h3>Recent decisions</h3><p class="sub">&nbsp;</p><?php if (!$done): ?><div class="empty">None yet.</div><?php else: ?><table><tbody><?php foreach ($done as $a): ?><tr><td><b><?= e($a['cname'] ?: 'Customer') ?></b><div class="mono small"><?= e(mb_substr($a['summary'], 0, 90)) ?></div></td><td><span class="badge <?= $a['status'] === 'done' ? 'ok' : ($a['status'] === 'failed' ? 'err' : 'gray') ?>"><?= e($a['status']) ?></span></td><td class="small muted"><?= e($a['by_name'] ?: '') ?> <?= ago($a['decided_at']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
  <div class="card"><h3>Changes the AI made itself</h3><p class="sub">Every create, update or cancel, after the customer confirmed</p><?php if (!$aiDone): ?><div class="empty">None yet.</div><?php else: ?><table><tbody><?php foreach ($aiDone as $a): ?><tr><td class="mono small"><?= e($a['tool']) ?></td><td class="small"><?= e(mb_substr($a['request_summary'], 0, 100)) ?></td><td><span class="badge <?= $a['ok'] ? 'ok' : 'err' ?>"><?= $a['ok'] ? 'done' : 'refused' ?></span></td><td class="small muted"><?= ago($a['created_at']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
</div>
