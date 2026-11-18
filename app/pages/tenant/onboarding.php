<?php
use CaliChat\Core\Audit;
use CaliChat\Core\Auth;
use CaliChat\Core\Db;
use CaliChat\Core\Log;
use CaliChat\Core\Tenant;
use CaliChat\Services\Brand;
use CaliChat\Services\Gemini;
use CaliChat\Services\Onboarding;

$title = 'Setup guide';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); Auth::requireOwner();
    $act = post('act');
    if ($act === 'profile') {
        if (post('name') !== '') Db::update('tenants', ['name' => mb_substr(post('name'), 0, 150)], 'id=?', [$tenantId]);
        $tz = post('timezone', 'Asia/Colombo'); if (in_array($tz, timezone_identifiers_list(), true)) Db::update('tenants', ['timezone' => $tz], 'id=?', [$tenantId]);
        $days = array_values(array_filter(array_map('intval', (array)($_POST['hours_days'] ?? [])), fn($d) => $d >= 1 && $d <= 7));
        $patch = ['profile_done' => 1];
        if ($days && preg_match('/^\d\d:\d\d$/', post('hours_start')) && preg_match('/^\d\d:\d\d$/', post('hours_end'))) $patch['hours'] = ['days' => $days, 'start' => post('hours_start'), 'end' => post('hours_end')];
        Tenant::saveSettings((int)$tenantId, $patch); Audit::log('onboarding_profile'); flash('Business profile saved.');
    } elseif ($act === 'store_details') {
        $name = mb_substr(post('name'), 0, 150); if ($name !== '') { Db::update('tenants', ['name' => $name], 'id=?', [$tenantId]); Db::q("UPDATE agents SET business_name=? WHERE tenant_id=? AND (business_name IS NULL OR business_name='' OR business_name=?)", [$name, $tenantId, $tenantRow['name']]); }
        Brand::save((int)$tenantId, ['brand_name' => post('brand_name'), 'brand_tagline' => post('brand_tagline'), 'brand_color' => post('brand_color', '#5B5BF0'), 'brand_phone' => post('brand_phone'), 'brand_address' => post('brand_address')]);
        $pay = array_values(array_intersect(['Cash on delivery', 'Bank transfer', 'Card', 'Online payment'], (array)($_POST['payments'] ?? [])));
        $patch = ['store_default_fee' => max(0, (float)post('default_fee')), 'store_free_over' => max(0, (float)post('free_over')), 'store_invoice_info' => mb_substr(trim((string)($_POST['invoice_info'] ?? '')), 0, 600), 'brand_done' => 1];
        if ($pay) $patch['store_payments'] = implode(',', $pay);
        Tenant::saveSettings((int)$tenantId, $patch);
        if (\CaliChat\Services\Store::connected((int)$tenantId)) \CaliChat\Services\Store::connect((int)$tenantId); // payment choices are part of the AI's tool definition
        $msg = 'Store details saved.';
        if (!empty($_FILES['logo']['tmp_name']) && ($_FILES['logo']['error'] ?? 0) === UPLOAD_ERR_OK) { $err = Brand::saveLogo((int)$tenantId, $_FILES['logo']); $msg .= $err ? ' The logo was not changed: ' . $err : ' Logo saved.'; }
        if (!$pay) $msg .= ' Choose at least one payment method to change that setting.';
        Audit::log('onboarding_store_details'); flash($msg);
    } elseif ($act === 'agent') {
        $desc = mb_substr(post('description'), 0, 3000); $instr = mb_substr(post('instructions'), 0, 12000);
        $draft = post('mode') === 'draft';
        Tenant::saveSettings((int)$tenantId, ['agent_description' => $desc]); // never lose what the owner typed, even if drafting fails
        $_SESSION['onb_agent'] = ['description' => $desc, 'instructions' => $instr]; // shown again if something below stops the save
        if ($draft) $instr = ''; // "Draft with AI" always writes a new briefing from the description
        if ($instr === '' && mb_strlen($desc) >= 20) { // draft the briefing with the AI from a plain description
            try {
                $tn = Tenant::get((int)$tenantId);
                $instr = trim(Gemini::plain(Tenant::geminiKey($tn),
                    'You write the BRIEFING for a customer-service AI agent that answers WhatsApp, Messenger and Instagram chats for a business. Write in second person ("You are..."), plain text, 120-250 words, no markdown headings. Cover: what the business sells, delivery/returns/payment facts ONLY if the owner stated them, tone, languages (English, Sinhala, Tamil), when to hand over to a human (complaints, refunds, anything it cannot verify), and that it must never invent prices or order details. Do not invent facts the owner did not give.',
                    [['text' => "Business description from the owner:\n$desc"]], ['tenant_id' => (int)$tenantId, 'kind' => 'onboarding']));
            } catch (\Throwable $e) { Log::exception($e, 'onboarding_draft_failed'); flash('The AI could not draft the briefing right now (' . (preg_match('/key/i', $e->getMessage()) ? 'no Gemini API key is set' : 'try again in a moment') . '). Your description was saved; you can also write the instructions yourself below.', 'err'); redirect('app/onboarding#agent'); }
        }
        if (mb_strlen($instr) < 20) { flash('Describe your business in a few sentences, or write the instructions yourself. What you typed was kept.', 'err'); redirect('app/onboarding#agent'); }
        $a = Db::one("SELECT id FROM agents WHERE tenant_id=? ORDER BY id LIMIT 1", [$tenantId]);
        if ($a) Db::update('agents', ['instructions' => $instr, 'is_active' => 1], 'id=? AND tenant_id=?', [$a['id'], $tenantId]);
        else Db::insert('agents', ['tenant_id' => $tenantId, 'name' => 'Assistant', 'business_name' => $tenantRow['name'], 'instructions' => $instr]);
        unset($_SESSION['onb_agent']);
        Audit::log('onboarding_agent'); flash('Agent instructions saved. Try them in the Playground.');
    } elseif ($act === 'skip_systems') { Tenant::saveSettings((int)$tenantId, ['skip_systems' => 1]); flash('Skipped. You can connect systems any time under Connectors.');
    } elseif ($act === 'dismiss') { Tenant::saveSettings((int)$tenantId, ['onboarding_dismissed' => 1]); redirect('app/dashboard'); }
    redirect('app/onboarding');
}
$steps = Onboarding::steps((int)$tenantId); $prog = Onboarding::progress((int)$tenantId);
$next = null; foreach ($steps as $s) if (!$s['done']) { $next = $s['key']; break; }
$t = Tenant::get((int)$tenantId); $cfg = $t['cfg'];
$h = $cfg['hours'] ?? ['days' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '18:00'];
$agent = Db::one("SELECT * FROM agents WHERE tenant_id=? ORDER BY id LIMIT 1", [$tenantId]);
$kept = $_SESSION['onb_agent'] ?? null; unset($_SESSION['onb_agent']); // what the owner typed if the last save was stopped
$agentDesc = $kept['description'] ?? ($cfg['agent_description'] ?? ''); $agentText = $kept['instructions'] ?? ($agent['instructions'] ?? '');
$brand = Brand::get((int)$tenantId); $sset = \CaliChat\Services\Store::settings((int)$tenantId); $allPay = ['Cash on delivery', 'Bank transfer', 'Card', 'Online payment'];
$dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
$owner = Auth::isOwner(); $pct = (int)round(100 * $prog['done'] / $prog['total']);
?>
<div class="page-head"><div><h1>Welcome to <span class="accent">CaliChat</span></h1><p><?= e(['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten'][$prog['total']] ?? $prog['total']) ?> short steps take you from an empty workspace to answering real customers. Each step turns green when it is done, so you can leave and come back.</p></div>
  <div class="actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="dismiss"><button class="btn">Hide this guide</button></form></div></div>

<div class="card" style="margin-bottom:22px"><div class="gh" style="display:flex;justify-content:space-between;font-weight:700"><span><?= $prog['complete'] ? 'You are live 🎉' : $prog['done'] . ' of ' . $prog['total'] . ' steps done' ?></span><span><?= $pct ?>%</span></div>
  <div class="gbar" style="margin-top:8px"><i style="width:<?= $pct ?>%"></i></div></div>

<div class="stack">
<?php foreach ($steps as $i => $s): $isNext = $s['key'] === $next; ?>
  <div class="card" id="<?= e($s['key']) ?>" style="<?= $isNext ? 'border-color:var(--brand);box-shadow:0 0 0 3px var(--brand-100)' : '' ?>">
    <div class="row" style="align-items:flex-start;flex-wrap:nowrap;gap:14px">
      <div class="ico <?= $s['done'] ? 'ok' : ($isNext ? 'vio' : '') ?>" style="flex:none;width:44px;height:44px;margin:0"><?= $s['done'] ? icon('check') : '<b>' . ($i + 1) . '</b>' ?></div>
      <div style="flex:1"><h3 style="margin-bottom:2px"><?= e($s['title']) ?> <?= $s['optional'] ? '<span class="badge gray">optional</span>' : '' ?> <?= $s['done'] ? '<span class="badge ok">done</span>' : '' ?></h3><p class="muted" style="margin:0 0 10px"><?= e($s['why']) ?></p>

      <?php if ($s['key'] === 'profile' && $owner): ?>
        <details class="fold" <?= !$s['done'] ? 'open' : '' ?>><summary><?= $s['done'] ? 'Edit' : 'Fill in' ?> profile</summary>
          <form method="post" class="stack" style="padding-bottom:12px"><?= csrf_field() ?><input type="hidden" name="act" value="profile">
            <div class="row"><label>Business name<input name="name" value="<?= e($t['name']) ?>"></label><label>Timezone<select name="timezone"><?php foreach (timezone_identifiers_list() as $z): ?><option <?= $t['timezone'] === $z ? 'selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?></select></label></div>
            <div><div class="eyebrow" style="margin-bottom:6px">When is your team available to take over chats?</div><div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:8px"><?php foreach ($dayNames as $n => $l): ?><label class="check"><input type="checkbox" name="hours_days[]" value="<?= $n ?>" <?= in_array($n, (array)$h['days'], true) ? 'checked' : '' ?>> <?= $l ?></label><?php endforeach; ?></div>
              <div class="row"><label>From<input type="time" name="hours_start" value="<?= e($h['start']) ?>"></label><label>To<input type="time" name="hours_end" value="<?= e($h['end']) ?>"></label></div></div>
            <div><button class="btn primary">Save profile</button></div></form></details>

      <?php elseif ($s['key'] === 'agent' && $owner): ?>
        <details class="fold" <?= !$s['done'] ? 'open' : '' ?>><summary><?= $s['done'] ? 'Edit' : 'Write' ?> instructions</summary>
          <form method="post" class="stack" style="padding-bottom:12px"><?= csrf_field() ?><input type="hidden" name="act" value="agent">
            <label>Describe your business in a few sentences <small>The AI will draft the briefing for you. Mention what you sell, delivery and return rules, and tone. This is saved.</small><textarea name="description" rows="4" placeholder="We are an online fashion store in Colombo. We deliver island-wide in 2-4 days, delivery Rs. 350, free over Rs. 10,000. Returns within 7 days if unused. Friendly tone."><?= e($agentDesc) ?></textarea></label>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><button class="btn primary" name="mode" value="draft"><?= icon('sparkle', 16) ?> Draft with AI</button><span class="muted small">writes new instructions from the description above, replacing the text below.</span></div>
            <label>Instructions <small>What the agent follows. Edit them freely, then press “Save instructions”.</small><textarea name="instructions" rows="8"><?= e($agentText) ?></textarea></label>
            <div><button class="btn" name="mode" value="own">Save instructions</button></div></form></details>

      <?php elseif ($s['key'] === 'store_details' && $owner): ?>
        <details class="fold" <?= !$s['done'] ? 'open' : '' ?>><summary><?= $s['done'] ? 'Edit' : 'Fill in' ?> store details</summary>
          <form method="post" enctype="multipart/form-data" class="stack" style="padding-bottom:12px"><?= csrf_field() ?><input type="hidden" name="act" value="store_details">
            <div class="row"><label>Business name<input name="name" required maxlength="150" value="<?= e($t['name']) ?>"></label><label>Name shown to customers <small>if different</small><input name="brand_name" maxlength="80" value="<?= e($brand['brand_name']) ?>" placeholder="<?= e($t['name']) ?>"></label></div>
            <div class="row"><label>Logo <small>PNG, JPG or WebP. Shown on product cards, the catalogue and order forms.</small><input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label><label>Brand colour<input type="color" name="brand_color" value="<?= e($brand['brand_color']) ?>" style="height:44px;padding:4px"></label></div>
            <?php if ($brand['has_logo']): ?><img src="<?= e($brand['logo_url']) ?>" alt="Current logo" style="height:48px;max-width:160px;object-fit:contain;border:1px solid var(--line);border-radius:10px;padding:5px;background:#fff"><?php endif; ?>
            <div class="row"><label>Tagline <small>optional</small><input name="brand_tagline" maxlength="120" value="<?= e($brand['brand_tagline']) ?>"></label><label>Phone<input name="brand_phone" maxlength="40" value="<?= e($brand['brand_phone']) ?>"></label></div>
            <label>Address<input name="brand_address" maxlength="200" value="<?= e($brand['brand_address']) ?>"></label>
            <div class="row"><label>Standard delivery fee (Rs.)<input name="default_fee" type="number" min="0" value="<?= e($sset['default_fee']) ?>"></label><label>Free delivery over (Rs.) <small>0 = never</small><input name="free_over" type="number" min="0" value="<?= e($sset['free_over']) ?>"></label></div>
            <fieldset style="border:0;padding:0;margin:0"><legend class="small muted" style="padding:0 0 6px">Payment methods you accept</legend><?php foreach ($allPay as $m): ?><label style="display:flex;gap:8px;align-items:center;margin-bottom:4px"><input type="checkbox" name="payments[]" value="<?= e($m) ?>" <?= in_array($m, $sset['payments'], true) ? 'checked' : '' ?> style="width:auto"> <?= e($m) ?></label><?php endforeach; ?></fieldset>
            <label>Shown on invoices <small>address, phone, bank account for transfers</small><textarea name="invoice_info" rows="3"><?= e($sset['invoice_info']) ?></textarea></label>
            <div><button class="btn primary">Save store details</button> <a class="small" href="<?= url('app/catalogue') ?>">Design your catalogue →</a></div></form></details>

      <?php elseif ($s['key'] === 'systems' && !$s['done'] && $owner): ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn primary" href="<?= url($s['link']) ?>"><?= e($s['cta']) ?></a><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="skip_systems"><button class="btn">Skip for now</button></form></div>

      <?php elseif (!$s['done']): ?>
        <a class="btn <?= $isNext ? 'primary' : '' ?>" href="<?= url($s['link']) ?>"><?= e($s['cta']) ?></a>
      <?php else: ?><a class="small" href="<?= url($s['link']) ?>">Open →</a><?php endif; ?>
      </div></div></div>
<?php endforeach; ?>
</div>
