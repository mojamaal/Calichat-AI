<?php
use CaliChat\Core\Audit;
use CaliChat\Core\Auth;
use CaliChat\Core\Db;
use CaliChat\Services\Brand;
use CaliChat\Services\Catalogue;

$title = 'Catalogue designer';
$canEdit = Auth::can('store_edit');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); Auth::requireCan('store_edit');
    $act = post('act');
    if ($act === 'logo_remove') { Brand::removeLogo((int)$tenantId); Audit::log('brand_logo_remove'); flash('Logo removed.'); redirect('app/catalogue'); }
    if ($act === 'brand_save') {
        $in = []; foreach (array_keys(Brand::defaults()) as $k) if (isset($_POST[$k])) $in[$k] = is_array($_POST[$k]) ? $_POST[$k] : trim((string)$_POST[$k]);
        $in['cat_hide_categories'] = (array)($_POST['cat_hide_categories'] ?? []); $in['_catalogue_form'] = 1;
        foreach (['cat_show_stock', 'cat_show_desc', 'cat_show_sku'] as $k) if (!empty($_POST[$k])) $in[$k] = 1;
        Brand::save((int)$tenantId, $in);
        if (!empty($_FILES['logo']['tmp_name']) && ($_FILES['logo']['error'] ?? 0) === UPLOAD_ERR_OK) {
            if ($err = Brand::saveLogo((int)$tenantId, $_FILES['logo'])) { flash('Saved, but the logo was not changed: ' . $err, 'err'); redirect('app/catalogue'); }
        }
        Audit::log('brand_save'); flash('Brand and catalogue design saved. Customers see it straight away.'); redirect('app/catalogue');
    }
    redirect('app/catalogue');
}

$b = Brand::get((int)$tenantId);
$cats = array_column(Db::all("SELECT DISTINCT COALESCE(NULLIF(category,''),'Other') c FROM store_products WHERE tenant_id=? AND is_active=1 ORDER BY c", [$tenantId]), 'c');
$prods = Db::all("SELECT * FROM store_products WHERE tenant_id=? AND is_active=1 ORDER BY name LIMIT 100", [$tenantId]);
$first = $prods[0] ?? null;
$preview = Catalogue::previewLink((int)$tenantId);
$dis = $canEdit ? '' : 'disabled';
$sel = fn(string $k, array $set) => implode('', array_map(fn($v, $l) => '<option value="' . e($v) . '"' . ($b[$k] === $v ? ' selected' : '') . '>' . e($l) . '</option>', array_keys($set), $set));
?>
<div class="page-head"><div><h1>Catalogue <span class="accent">designer</span></h1><p>This is what customers see when the AI sends a product card or the catalogue on WhatsApp: your logo and business name, your colours, your layout. Change anything on the left and the preview updates.</p></div></div>

<div class="grid" style="grid-template-columns:minmax(320px,1fr) minmax(320px,1.1fr);align-items:start">
<form method="post" enctype="multipart/form-data" class="stack" id="designer"><?= csrf_field() ?><input type="hidden" name="act" value="brand_save"><input type="hidden" name="_catalogue_form" value="1">
  <div class="card stack"><h3>Your brand</h3>
    <div class="row"><label>Business name shown to customers<input name="brand_name" maxlength="80" value="<?= e($b['brand_name']) ?>" placeholder="<?= e($b['name']) ?>" <?= $dis ?>></label><label>Tagline <small>optional</small><input name="brand_tagline" maxlength="120" value="<?= e($b['brand_tagline']) ?>" placeholder="Fashion for every day" <?= $dis ?>></label></div>
    <div class="row"><label>Brand colour<input type="color" name="brand_color" value="<?= e($b['brand_color']) ?>" style="height:44px;padding:4px" <?= $dis ?>></label>
      <label>Logo <small>PNG, JPG or WebP, up to 2 MB</small><input type="file" name="logo" accept="image/png,image/jpeg,image/webp" <?= $dis ?>></label></div>
    <?php if ($b['has_logo']): ?><div style="display:flex;gap:14px;align-items:center"><img src="<?= e($b['logo_url']) ?>" alt="Current logo" style="height:56px;max-width:180px;object-fit:contain;border:1px solid var(--line);border-radius:10px;padding:6px;background:#fff"><?php if ($canEdit): ?><button class="btn sm danger" name="act" value="logo_remove" formnovalidate>Remove logo</button><?php endif; ?></div>
    <?php else: ?><p class="muted small" style="margin:0">No logo yet: customers see your business initial. Upload one so it appears on every card and on the catalogue.</p><?php endif; ?>
    <div class="row"><label>Phone shown in the catalogue footer<input name="brand_phone" maxlength="40" value="<?= e($b['brand_phone']) ?>" <?= $dis ?>></label><label>Address shown in the footer<input name="brand_address" maxlength="200" value="<?= e($b['brand_address']) ?>" <?= $dis ?>></label></div></div>

  <div class="card stack"><h3>Catalogue look</h3>
    <div class="row"><label>Title<input name="cat_title" maxlength="80" value="<?= e($b['cat_title']) ?>" placeholder="Our catalogue" <?= $dis ?>></label><label>Add button text<input name="cat_button" maxlength="24" value="<?= e($b['cat_button']) ?>" <?= $dis ?>></label></div>
    <label>Welcome text<textarea name="cat_intro" rows="2" maxlength="300" placeholder="Hand-picked styles with island-wide delivery." <?= $dis ?>><?= e($b['cat_intro']) ?></textarea></label>
    <div class="row"><label>Layout<select name="cat_layout" <?= $dis ?>><?= $sel('cat_layout', Brand::LAYOUTS) ?></select></label><label>Theme<select name="cat_theme" <?= $dis ?>><?= $sel('cat_theme', Brand::THEMES) ?></select></label></div>
    <div class="row"><label>Corners<select name="cat_corner" <?= $dis ?>><?= $sel('cat_corner', Brand::CORNERS) ?></select></label><label>Sort products<select name="cat_sort" <?= $dis ?>><?= $sel('cat_sort', Brand::SORTS) ?></select></label></div>
    <label>Featured products first <small>SKUs separated by commas, in the order you want them</small><input name="cat_featured" value="<?= e($b['cat_featured']) ?>" placeholder="TS-001, SH-221" <?= $dis ?>></label>
    <fieldset style="border:0;padding:0;margin:0"><legend class="small muted" style="padding:0 0 6px">Show on each product</legend>
      <?php foreach (['cat_show_stock' => 'Stock level (Only 3 left)', 'cat_show_desc' => 'Description', 'cat_show_sku' => 'Product code (SKU)'] as $k => $l): ?><label style="display:flex;gap:8px;align-items:center;margin-bottom:4px"><input type="checkbox" name="<?= $k ?>" value="1" <?= !empty($b[$k]) ? 'checked' : '' ?> style="width:auto" <?= $dis ?>> <?= e($l) ?></label><?php endforeach; ?></fieldset>
    <?php if ($cats): ?><fieldset style="border:0;padding:0;margin:0"><legend class="small muted" style="padding:0 0 6px">Hide these categories from the catalogue</legend>
      <?php foreach ($cats as $c): ?><label style="display:flex;gap:8px;align-items:center;margin-bottom:4px"><input type="checkbox" name="cat_hide_categories[]" value="<?= e($c) ?>" <?= in_array($c, $b['hidden_categories'], true) ? 'checked' : '' ?> style="width:auto" <?= $dis ?>> <?= e($c) ?></label><?php endforeach; ?></fieldset><?php endif; ?>
    <label>Footer note<input name="cat_footer" maxlength="200" value="<?= e($b['cat_footer']) ?>" placeholder="Prices include VAT. Delivery 1-3 days." <?= $dis ?>></label>
    <?php if ($canEdit): ?><div><button class="btn primary">Save design</button></div><?php else: ?><p class="muted small">Ask an owner to change the design.</p><?php endif; ?></div>
</form>

<div class="stack" style="position:sticky;top:12px">
  <div class="card"><div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px"><h3 style="margin:0">Live preview</h3><span class="muted small">Updates as you type. Not saved until you press Save design.</span></div>
    <div style="border:1px solid var(--line);border-radius:16px;overflow:hidden;background:#fff"><iframe id="pv" title="Catalogue preview" data-src="<?= e($preview) ?>" src="<?= e($preview) ?>" style="width:100%;height:680px;border:0" loading="lazy"></iframe></div></div>
  <div class="card"><h3>WhatsApp product card</h3>
    <?php if (!$first): ?><p class="muted">Add products in the Store to see a card.</p><?php else: ?>
      <label>Product<select id="cardpick"><?php foreach ($prods as $p): ?><option value="<?= e($p['sku']) ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></label>
      <?php if (Brand::canDraw((int)$tenantId, $first)): ?><div style="margin-top:12px;text-align:center"><img id="cardimg" src="<?= e(url('media/card/' . $tenantId . '/' . rawurlencode($first['sku']))) ?>?v=<?= e(Brand::cardVersion((int)$tenantId, $first)) ?>" alt="Product card preview" style="max-width:340px;width:100%;border-radius:14px;border:1px solid var(--line)" data-base="<?= e(url('media/card/' . $tenantId)) ?>"></div>
        <p class="muted small" style="margin:10px 0 0">This image is sent with the Add to order and More products buttons. It redraws after you save the brand.</p>
      <?php else: ?><p class="muted small" style="margin-top:10px">The branded card image needs a Latin product name and a system font. The product photo is sent as it is, with your business name in the message footer.</p><?php endif; ?>
    <?php endif; ?></div>
</div></div>
<script src="<?= url('assets/js/catalogue_designer.js') ?>?v=<?= (int)@filemtime(ROOT . '/assets/js/catalogue_designer.js') ?>"></script>
