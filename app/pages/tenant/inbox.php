<?php
$title = 'Inbox';
$f = in_array($_GET['status'] ?? '', ['ai', 'human', 'closed', 'unread', 'pinned'], true) ? $_GET['status'] : 'all';
$labels = \CaliChat\Core\Db::all("SELECT id, name, color FROM labels WHERE tenant_id=? ORDER BY name", [$tenantId]);
$ico = fn(string $d, int $s = 24) => '<svg viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="currentColor" aria-hidden="true"><path d="' . $d . '"/></svg>';
$me = mb_strtoupper(mb_substr((string)($u['name'] ?? '?'), 0, 1));
?>
<link rel="stylesheet" href="<?= url('assets/css/inbox.css') ?>?v=<?= (int)@filemtime(ROOT . '/assets/css/inbox.css') ?>">
<div class="wi" id="inbox" data-filter="<?= e($f) ?>">
  <aside class="wi-side" aria-label="Chats">
    <header class="wi-sh">
      <span class="wi-me" title="<?= e($u['name'] ?? '') ?>"><?= e($me) ?></span><b>Chats</b><span class="wi-grow"></span>
      <span class="wi-menuwrap"><button type="button" class="wi-ib" id="wi-sm" aria-label="Menu" aria-haspopup="true" aria-expanded="false"><?= $ico('M12 7a2 2 0 1 0 0-4 2 2 0 0 0 0 4zm0 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm0 6a2 2 0 1 0 0 4 2 2 0 0 0 0-4z') ?></button>
        <div class="wi-menu" id="wi-smbox" hidden><button type="button" id="notif">Turn on desktop alerts</button><label class="wi-mi"><input type="checkbox" id="mine"> Only chats assigned to me</label></div></span>
    </header>
    <div class="wi-search"><span class="wi-sico"><?= $ico('M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z', 20) ?></span><input id="q" type="search" placeholder="Search or start a new chat" aria-label="Search conversations" autocomplete="off"></div>
    <div class="wi-pills" role="tablist" aria-label="Filter chats">
      <?php foreach (['all' => 'All', 'unread' => 'Unread', 'human' => 'Needs human', 'pinned' => 'Pinned', 'ai' => 'AI', 'closed' => 'Closed'] as $k => $l): ?>
        <button type="button" role="tab" data-f="<?= $k ?>" class="<?= $f === $k ? 'on' : '' ?>"><?= $l ?></button>
      <?php endforeach; ?>
      <select id="labelf" aria-label="Filter by label"><option value="0">All labels</option><?php foreach ($labels as $lb): ?><option value="<?= $lb['id'] ?>"><?= e($lb['name']) ?></option><?php endforeach; ?></select>
    </div>
    <div class="wi-list" id="convs" aria-live="polite"><div class="wi-none">Loading…</div></div>
  </aside>

  <section class="wi-main">
    <div class="wi-splash" id="wi-splash"><div class="wi-sp-ico"><?= $ico('M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2zM6 9h12v2H6V9zm8 5H6v-2h8v2zm4-6H6V6h12v2z', 76) ?></div>
      <h2>CaliChat Inbox</h2><p>Every WhatsApp, Messenger and Instagram chat in one place. Pick a chat on the left to read it live, take over from the AI, or hand it back.</p>
      <small>Internal notes are never sent to the customer.</small></div>
    <div class="wi-chat" id="wi-chat" hidden>
      <header class="wi-ch" id="thread-head"></header>
      <div class="wi-labels" id="thread-labels"></div>
      <div class="wi-summary" id="summary" hidden></div>
      <div class="wi-msgs" id="msgs" aria-live="polite"></div>
      <form class="wi-bar" id="composer" autocomplete="off">
        <div class="wi-qpop" id="qpop" hidden></div>
        <span class="wi-mode" role="group" aria-label="Reply type"><button type="button" data-mode="reply" class="on">Reply</button><button type="button" data-mode="note">Note</button></span>
        <span class="wi-field" id="wi-field"><button type="button" class="wi-qr" id="qr" title="Quick replies (or type /)" aria-label="Quick replies"><?= $ico('M7 2v11h3v9l7-12h-4l4-8z', 20) ?></button>
          <input name="text" placeholder="Type a message (the AI pauses while you reply)" aria-label="Message"></span>
        <button class="wi-send" type="submit" aria-label="Send"><span class="i-mic"><?= $ico('M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 6 6.92V21h2v-3.08A7 7 0 0 0 19 11z', 22) ?></span><span class="i-send"><?= $ico('M2.01 21 23 12 2.01 3 2 10l15 2-15 2z', 22) ?></span></button>
      </form>
    </div>
  </section>
</div>
<script src="<?= url('assets/js/inbox.js') ?>?v=<?= (int)@filemtime(ROOT . '/assets/js/inbox.js') ?>"></script>
