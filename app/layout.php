<?php
declare(strict_types=1);
/** Shell: sidebar + topbar. Expects $u, $nav ('app'|'admin'), $page, $file, $tenantId */

use CaliChat\Core\Auth;
use CaliChat\Core\Db;
use CaliChat\Core\Tenant;
use CaliChat\Services\Notifier;

$isAdmin = $nav === 'admin';
$tenantRow = $tenantId ? Tenant::get((int)$tenantId) : null;
$isOwner = Auth::isOwner();
$items = [['dashboard', 'dashboard', 'Dashboard'], ['tenants', 'building', 'Tenants'], ['billing', 'chart', 'Billing'], ['operations', 'activity', 'Operations'], ['audit', 'shield', 'Audit log'], ['platform', 'settings', 'Platform']]; // platform console: a short flat list
// Tenant workspace: grouped, collapsible sections. CRM & order management first, then the Playground, then the rest in the order a business sets up and runs things.
$navGroups = [];
if (!$isAdmin) {
    $setup = $isOwner && empty($tenantRow['cfg']['onboarding_dismissed']);
    $defs = [
        ['crm', 'CRM & Orders', 'cart', [['inbox', 'inbox', 'Inbox'], ['orders', 'cart', 'Orders'], ['leads', 'funnel', 'Leads'], ['contacts', 'users', 'Contacts'], ['appointments', 'calendar', 'Appointments'], ['approvals', 'check', 'Approvals']]],
        ['playground', null, null, $isOwner ? [['playground', 'flask', 'Playground']] : []],
        ['store', 'Store', 'box', [['store', 'box', 'Store'], ['catalogue', 'template', 'Catalogue designer']]],
        ['ai', 'AI Assistant', 'agent', [['agents', 'agent', 'AI Agents'], ['knowledge', 'book', 'Knowledge'], ['quality', 'flask', 'Quality tests']]],
        ['connect', 'Integrations', 'plug', [['channels', 'channel', 'Channels'], ['connectors', 'plug', 'Connectors']]],
        ['marketing', 'Marketing', 'megaphone', [['campaigns', 'megaphone', 'Campaigns'], ['templates', 'template', 'WA Templates'], ['automations', 'clock', 'Follow-ups']]],
        ['insights', 'Insights', 'chart', [['dashboard', 'dashboard', 'Dashboard'], ['reports', 'chart', 'Reports'], ['monitoring', 'activity', 'Usage & Monitoring']]],
        ['workspace', 'Workspace', 'settings', array_values(array_filter([$setup ? ['onboarding', 'sparkle', 'Setup guide'] : null, ['team', 'users', 'Team'], $isOwner ? ['billing', 'chart', 'Plan & billing'] : null, $isOwner ? ['audit', 'shield', 'Audit log'] : null, ['settings', 'settings', 'Settings']]))],
    ];
    foreach ($defs as [$gk, $gl, $gi, $gitems]) { // each person sees only what their access allows; an empty section disappears
        $gitems = array_values(array_filter($gitems, fn($i) => !($cap = \CaliChat\Core\Perms::pageCap($i[0])) || Auth::can($cap)));
        if ($gitems) $navGroups[] = ['key' => $gk, 'label' => $gl, 'icon' => $gi, 'items' => $gitems];
    }
}
$handoffs = $tenantId ? (int)Db::val("SELECT COUNT(*) FROM conversations WHERE tenant_id=? AND status='human' AND is_test=0", [$tenantId]) : 0;
$pendingApprovals = $tenantId ? \CaliChat\Services\Actions::pendingCount((int)$tenantId) : 0;
$alertCount = $tenantId ? Notifier::unseenCount((int)$tenantId) : (int)Db::val("SELECT COUNT(*) FROM alerts WHERE resolved_at IS NULL AND severity='crit'");

ob_start();
$title = 'CaliChat';
require $file; // page may set $title, handle POST+redirect, and echo its body
$body = ob_get_clean();
$flash = flash();
$prefix = $isAdmin ? 'admin' : 'app';
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> · <?= e(env('APP_NAME', 'CaliChat')) ?></title>
<link rel="stylesheet" href="<?= url('assets/css/app.css') ?>?v=<?= (int)@filemtime(ROOT . '/assets/css/app.css') ?>">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<meta name="base" content="<?= e(base_path()) ?>">
<meta name="uid" content="<?= (int)$u['id'] ?>">
</head><body>
<a class="skip" href="#main">Skip to content</a>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <div><a class="brand-block" href="<?= url($isAdmin ? 'admin/dashboard' : 'app/dashboard') ?>" aria-label="Home"><span class="brand-mark">CC</span><span class="brand-name"><?= e(strtoupper(env('APP_NAME', 'CaliChat'))) ?></span></a></div>
    <div class="brand-tag"><?= $isAdmin ? 'PLATFORM CONSOLE' : 'AGENTIC CONVERSATIONS' ?></div>
    <nav class="nav" aria-label="Main">
      <?php $badge = fn(string $slug): int => $slug === 'inbox' ? $handoffs : ($slug === 'approvals' ? $pendingApprovals : 0); ?>
      <?php if ($isAdmin): foreach ($items as [$slug, $ic, $label]): ?>
        <a href="<?= url("$prefix/$slug") ?>" class="<?= $page === $slug ? 'on' : '' ?>" <?= $page === $slug ? 'aria-current="page"' : '' ?>><?= icon($ic) ?><?= e($label) ?></a>
      <?php endforeach; else: foreach ($navGroups as $g):
          $slugs = array_column($g['items'], 0); $active = in_array($page, $slugs, true); $sum = array_sum(array_map($badge, $slugs));
          if ($g['label'] === null): foreach ($g['items'] as [$slug, $ic, $label]): // a single link that stands on its own (Playground) ?>
        <a href="<?= url("$prefix/$slug") ?>" class="nav-solo <?= $page === $slug ? 'on' : '' ?>" <?= $page === $slug ? 'aria-current="page"' : '' ?>><?= icon($ic) ?><?= e($label) ?></a>
      <?php endforeach; else: ?>
        <details class="nav-group" data-key="<?= e($g['key']) ?>" data-active="<?= $active ? '1' : '0' ?>" <?= $active || $g['key'] === 'crm' ? 'open' : '' ?>>
          <summary><?= icon($g['icon'], 19) ?><span class="gl"><?= e($g['label']) ?></span><?php if ($sum): ?><span class="pill"><?= $sum ?></span><?php endif; ?><svg class="chev" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M8 10l4 4 4-4" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></summary>
          <div class="nav-items"><?php foreach ($g['items'] as [$slug, $ic, $label]): ?>
            <a href="<?= url("$prefix/$slug") ?>" class="<?= $page === $slug ? 'on' : '' ?>" <?= $page === $slug ? 'aria-current="page"' : '' ?>><?= icon($ic, 18) ?><?= e($label) ?><?php if ($badge($slug)): ?><span class="pill"><?= $badge($slug) ?></span><?php endif; ?></a>
          <?php endforeach; ?></div>
        </details>
      <?php endif; endforeach; endif; ?>
      <?php if ($u['role'] === 'super_admin' && !$isAdmin): ?><div class="nav-label">PLATFORM</div><a href="<?= url('admin/tenants') ?>"><?= icon('building') ?>All tenants</a><?php endif; ?>
    </nav>
    <div class="who"><b>SIGNED IN</b><span><?= e($u['name']) ?> · <?= e(ucfirst(str_replace('_', ' ', $u['role']))) ?><?php if ($tenantRow && !$isAdmin): ?><br><?= e($tenantRow['name']) ?><?php endif; ?></span>
      <a class="small" href="<?= url('account/password') ?>">Change password</a></div>
  </aside>
  <div class="main">
    <?php if (!empty($_SESSION['impersonate']) && !$isAdmin): ?>
      <div class="impersonate">Viewing as tenant <b><?= e($tenantRow['name'] ?? '') ?></b> (audited)
        <form method="post" action="<?= url('admin/tenants') ?>" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="stop_view"><button class="linkbtn">Exit tenant view →</button></form></div>
    <?php endif; ?>
    <header class="topbar">
      <button class="iconbtn menu-btn" data-toggle="sidebar" aria-label="Open menu"><?= icon('menu') ?></button>
      <form class="search" action="<?= url('app/contacts') ?>" method="get" role="search"><?= icon('search', 18) ?><input name="q" placeholder="Search contacts…" aria-label="Search contacts" value="<?= e($_GET['q'] ?? '') ?>"></form>
      <div class="tb-right">
        <a class="iconbtn" href="<?= $isAdmin ? url('admin/operations') : url('app/alerts') ?>" aria-label="Alerts<?= $alertCount ? ' (' . $alertCount . ' new)' : '' ?>"><?= icon('bell', 19) ?><?php if ($alertCount): ?><span class="dot"></span><?php endif; ?></a>
        <?php if (!$isAdmin): ?><a class="iconbtn" href="<?= url('app/settings') ?>" aria-label="Settings"><?= icon('settings', 19) ?></a><?php endif; ?>
        <span class="avatar" title="<?= e($u['email']) ?>"><?= e(initials($u['name'])) ?></span>
        <form method="post" action="<?= url('logout') ?>"><?= csrf_field() ?><button class="pillbtn" type="submit">Log out</button></form>
      </div>
    </header>
    <main class="content" id="main">
      <?php if (!$isAdmin && $isOwner && $tenantRow && ($bs = \CaliChat\Services\Billing::status($tenantRow)) && $bs['message'] && ($bs['state'] !== 'active' || ($bs['days_left'] !== null && $bs['days_left'] <= 7))): ?><div class="flash <?= $bs['blocking'] || $bs['state'] === 'past_due' ? 'err' : 'ok' ?>" role="status"><?= e($bs['message']) ?> <a href="<?= url('app/billing') ?>">Plan &amp; billing</a></div><?php endif; ?>
      <?php if ($flash): ?><div class="flash <?= e($flash[1]) ?>" role="status"><?= e($flash[0]) ?></div><?php endif; ?>
      <?= $body ?>
    </main>
  </div>
</div>
<script src="<?= url('assets/js/app.js') ?>?v=<?= (int)@filemtime(ROOT . '/assets/js/app.js') ?>"></script>
</body></html>
