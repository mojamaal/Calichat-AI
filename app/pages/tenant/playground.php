<?php
use CaliChat\Core\Auth;
use CaliChat\Core\Db;
use CaliChat\Services\Brand;

Auth::requireOwner();
$title = 'Playground';
$tools = Db::all("SELECT name, method FROM tools WHERE tenant_id=? AND is_active=1", [$tenantId]);
$brand = Brand::get((int)$tenantId); $logo = Brand::logoPathRel((int)$tenantId);
$agent = Db::one("SELECT name FROM agents WHERE tenant_id=? AND is_active=1 ORDER BY id LIMIT 1", [$tenantId]);
$tries = ['Do you have jeans?', 'What menswear do you have?', 'Where is order ORD-1001?', 'ජීන්ස් තියෙනවද?', 'I want 2 black baseball caps, deliver to Kandy, cash on delivery', 'I want to talk to a person'];
$ico = fn(string $d, int $s = 24) => '<svg viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="currentColor" aria-hidden="true"><path d="' . $d . '"/></svg>';
?>
<link rel="stylesheet" href="<?= url('assets/css/playground.css') ?>?v=<?= (int)@filemtime(ROOT . '/assets/css/playground.css') ?>">
<div class="page-head"><div><h1>Agent <span class="accent">Playground</span></h1><p>Chat with your agent exactly as a customer would on WhatsApp. Tool calls to your systems are shown under each reply. Test chats never count toward quotas or dashboards.</p></div></div>

<div class="pgwrap" id="pg">
  <section class="wa" aria-label="Simulated WhatsApp chat">
    <header class="wa-h">
      <span class="wa-back"><?= $ico('M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20z', 22) ?></span>
      <span class="wa-av"><?php if ($logo): ?><img src="<?= e(url($logo)) ?>" alt=""><?php else: ?><?= e(mb_strtoupper(mb_substr($brand['name'], 0, 1))) ?><?php endif; ?></span>
      <span class="wa-who"><b><?= e($brand['name']) ?> <svg class="wa-ver" viewBox="0 0 24 24" width="15" height="15" aria-label="Business account"><circle cx="12" cy="12" r="11" fill="#25D366"/><path d="M7 12.5l3.2 3.2L17 9" stroke="#fff" stroke-width="2.2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></b><small id="pg-status">business account</small></span>
      <span class="wa-ico" aria-hidden="true"><?= $ico('M17 10.5V7a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-3.5l4 4v-11z') ?><?= $ico('M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25 11.4 11.4 0 0 0 3.6.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.57a1 1 0 0 1-.25 1z', 21) ?></span>
      <span class="wa-menuwrap"><button type="button" class="wa-ico wa-dots" id="pg-menu" aria-label="Menu" aria-haspopup="true" aria-expanded="false"><?= $ico('M12 7a2 2 0 1 0 0-4 2 2 0 0 0 0 4zm0 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm0 6a2 2 0 1 0 0 4 2 2 0 0 0 0-4z') ?></button>
        <div class="wa-menu" id="pg-menubox" hidden><button type="button" id="pg-reset2">Clear chat &amp; memory</button></div></span>
    </header>
    <div class="wa-chat" id="pg-msgs" aria-live="polite"></div>
    <form class="wa-bar" id="pg-form" autocomplete="off">
      <span class="wa-field"><span class="wa-emoji" aria-hidden="true"><?= $ico('M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16zm-3.5-9a1.25 1.25 0 1 0 0-2.5 1.25 1.25 0 0 0 0 2.5zm7 0a1.25 1.25 0 1 0 0-2.5 1.25 1.25 0 0 0 0 2.5zM12 17.5c2.33 0 4.31-1.46 5.11-3.5H6.89c.8 2.04 2.78 3.5 5.11 3.5z', 24) ?></span>
        <input name="text" placeholder="Message" aria-label="Message" autofocus>
        <span class="wa-clip" aria-hidden="true"><?= $ico('M16.5 6v11.5a4 4 0 0 1-8 0V5a2.5 2.5 0 0 1 5 0v10.5a1 1 0 0 1-2 0V6H10v9.5a2.5 2.5 0 0 0 5 0V5a4 4 0 0 0-8 0v12.5a5.5 5.5 0 0 0 11 0V6z', 22) ?></span></span>
      <button class="wa-send" id="pg-send" type="submit" aria-label="Send"><span class="i-mic"><?= $ico('M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 6 6.92V21h2v-3.08A7 7 0 0 0 19 11z', 22) ?></span><span class="i-send"><?= $ico('M2.01 21 23 12 2.01 3 2 10l15 2-15 2z', 22) ?></span></button>
    </form>
  </section>

  <div class="stack pgside">
    <div class="card"><h3>Simulated customer</h3><p class="sub">Used to bind customer-scoped tools (for example order lookup by phone). Every use is recorded in the audit log.</p>
      <label>Customer phone<input id="pg-phone" value="94771234567"></label>
      <label class="check" style="margin-top:14px"><input type="checkbox" id="pg-writes" checked> Run write actions (create, update, cancel)</label>
      <p class="muted small" style="margin:8px 0 0"><b>Actions run for real</b> when ticked: orders and leads are created in your connected systems (the AI still previews and waits for your Confirm). Untick to keep the Playground read-only.</p>
      <div style="margin-top:14px"><button class="btn danger" id="pg-reset" type="button">Reset chat &amp; memory</button></div></div>
    <div class="card"><h3>Try asking</h3><p class="sub">Tap to put it in the message box.</p>
      <div class="pg-tries"><?php foreach ($tries as $t): ?><button type="button" class="pg-try" data-try="<?= e($t) ?>"><?= e($t) ?></button><?php endforeach; ?></div></div>
    <div class="card"><h3>Live tools</h3><p class="sub">Available to the agent<?= $agent ? ' (' . e($agent['name']) . ')' : '' ?></p>
      <?php if (!$tools): ?><span class="muted">None yet. <a href="<?= url('app/connectors') ?>">Add a connector</a>.</span><?php endif; ?>
      <?php foreach ($tools as $t): ?><span class="badge" style="margin:0 6px 6px 0">⚙ <?= e($t['name']) ?><?= $t['method'] !== 'GET' ? ' (write)' : '' ?></span><?php endforeach; ?>
      <span class="badge gray">search_knowledge</span> <span class="badge gray">handoff_to_human</span></div>
  </div>
</div>
<script src="<?= url('assets/js/playground.js') ?>?v=<?= (int)@filemtime(ROOT . '/assets/js/playground.js') ?>"></script>
