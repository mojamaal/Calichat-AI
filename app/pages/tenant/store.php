<?php
use CaliChat\Core\Audit;
use CaliChat\Core\Auth;
use CaliChat\Core\Crypto;
use CaliChat\Services\Importers;
use CaliChat\Core\Db;
use CaliChat\Core\Tenant;
use CaliChat\Services\Store;
use CaliChat\Services\StoreError;

$title = 'Store';
$owner = Auth::can('store_edit');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); Auth::requireCan('store_edit');
    $act = post('act');
    try {
        if ($act === 'product_save') {
            $id = (int)post('id') ?: null;
            Store::saveProduct($tenantId, ['sku' => post('sku'), 'name' => post('name'), 'category' => post('category'), 'price' => post('price'), 'cost_price' => post('cost_price', '0'), 'stock' => post('stock', '0'),
                'variants' => post('variants'), 'colors' => post('colors'), 'description' => post('description'), 'image_url' => post('image_url'), 'is_active' => isset($_POST['is_active']) ? 1 : 0], $id);
            Audit::log('store_product_save', 'sku:' . strtoupper(post('sku'))); flash('Product saved.'); redirect('app/store');
        }
        if ($act === 'product_toggle') { Db::q("UPDATE store_products SET is_active = 1 - is_active, updated_at=NOW() WHERE id=? AND tenant_id=?", [(int)post('id'), $tenantId]); redirect('app/store'); }
        if ($act === 'stock_set') { Db::q("UPDATE store_products SET stock=?, updated_at=NOW() WHERE id=? AND tenant_id=?", [max(0, (int)post('stock')), (int)post('id'), $tenantId]); flash('Stock updated.'); redirect('app/store'); }
        if ($act === 'product_delete') { Db::q("DELETE FROM store_products WHERE id=? AND tenant_id=?", [(int)post('id'), $tenantId]); Audit::log('store_product_delete', 'product:' . (int)post('id')); flash('Product deleted. Past orders keep their item names and prices.'); redirect('app/store'); }
        if ($act === 'import_csv') {
            $csv = post('csv_text');
            if (!empty($_FILES['csv']['tmp_name']) && is_uploaded_file($_FILES['csv']['tmp_name']) && $_FILES['csv']['size'] < 1048576) $csv = (string)file_get_contents($_FILES['csv']['tmp_name']);
            $r = Store::importCsv($tenantId, $csv); Audit::log('store_import', '', $r['added'] . ' products');
            flash($r['added'] . ' product(s) imported or updated.' . ($r['errors'] ? ' Problems: ' . implode(' | ', array_slice($r['errors'], 0, 3)) : ''), $r['errors'] && !$r['added'] ? 'err' : 'ok'); redirect('app/store');
        }
        if ($act === 'zone_save') {
            $town = mb_substr(post('town'), 0, 80); if ($town === '') throw new StoreError('Town is required', 422);
            $free = post('free_over') === '' ? null : max(0, (float)post('free_over'));
            Db::q("INSERT INTO store_zones (tenant_id, town, fee, free_over, eta, cod_allowed) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE fee=VALUES(fee), free_over=VALUES(free_over), eta=VALUES(eta), cod_allowed=VALUES(cod_allowed)",
                [$tenantId, $town, max(0, (float)post('fee')), $free, mb_substr(post('eta'), 0, 60) ?: null, isset($_POST['cod_allowed']) ? 1 : 0]);
            flash('Delivery area saved.'); redirect('app/store#delivery');
        }
        if ($act === 'zone_delete') { Db::q("DELETE FROM store_zones WHERE id=? AND tenant_id=?", [(int)post('id'), $tenantId]); flash('Delivery area removed.'); redirect('app/store#delivery'); }
        if ($act === 'settings_save') {
            $pay = array_values(array_intersect(['Cash on delivery', 'Bank transfer', 'Card', 'Online payment'], (array)($_POST['payments'] ?? [])));
            if (!$pay) throw new StoreError('Choose at least one payment method', 422);
            Tenant::saveSettings($tenantId, ['store_default_fee' => max(0, (float)post('default_fee')), 'store_free_over' => max(0, (float)post('free_over')), 'store_max_total' => max(0, (float)post('max_total')), 'store_payments' => implode(',', $pay), 'store_require_colour' => isset($_POST['require_colour']) ? 1 : 0, 'store_invoice_info' => mb_substr(trim((string)($_POST['invoice_info'] ?? '')), 0, 600)]);
            if (Store::connected($tenantId)) Store::connect($tenantId); // payment choices are part of the AI's tool definition
            Audit::log('store_settings'); flash('Store settings saved.'); redirect('app/store#settings');
        }
        if ($act === 'connect') {
            $moved = Store::connect($tenantId); Audit::log('store_connect', '', "$moved tools re-pointed");
            flash('The AI agent now uses this store for products, orders and leads.' . ($moved ? " $moved tool(s) that pointed at another system were switched to the built-in store." : '')); redirect('app/store');
        }
        if ($act === 'src_save') {
            $type = post('type') === 'shopify' ? 'shopify' : 'woocommerce'; $base = Importers::baseUrl($type, post('base_url'));
            if (!$base) throw new StoreError($type === 'shopify' ? 'Use your shop address like myshop.myshopify.com' : 'Use your shop address starting with https://', 422);
            $cred = $type === 'shopify' ? ['token' => post('token')] : ['key' => post('key'), 'secret' => post('secret')];
            $old = Db::one("SELECT credentials FROM store_sources WHERE tenant_id=? AND type=?", [$tenantId, $type]); $oc = $old ? (json_decode((string)Crypto::decrypt($old['credentials']), true) ?: []) : [];
            foreach ($cred as $k => $v) if ($v === '') $cred[$k] = $oc[$k] ?? ''; // blank = keep the saved one
            Db::q("INSERT INTO store_sources (tenant_id, type, base_url, credentials, auto_sync) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE base_url=VALUES(base_url), credentials=VALUES(credentials), auto_sync=VALUES(auto_sync)", [$tenantId, $type, $base, Crypto::encrypt(json_encode($cred)), isset($_POST['auto_sync']) ? 1 : 0]);
            Audit::log('store_source_save', $type, $base); flash('Saved. Press Import now to pull the products.'); redirect('app/store#sources');
        }
        if ($act === 'src_sync') { $r = Importers::sync($tenantId, (int)post('id')); Audit::log('store_source_sync', 'source:' . (int)post('id'), $r['ok'] ? (string)$r['added'] : (string)$r['error']); flash($r['ok'] ? $r['added'] . ' product(s) imported or updated.' . ($r['error'] ? ' ' . $r['error'] : '') : (string)$r['error'], $r['ok'] ? 'ok' : 'err'); redirect('app/store#sources'); }
        if ($act === 'src_delete') { Db::q("DELETE FROM store_sources WHERE id=? AND tenant_id=?", [(int)post('id'), $tenantId]); flash('Source removed. Imported products stay.'); redirect('app/store#sources'); }
        if ($act === 'sample') { Store::seedSample($tenantId); flash('Sample products and delivery areas added.'); redirect('app/store'); }
        if ($act === 'pictures') { $n = Store::addPictures($tenantId); Audit::log('store_pictures', '', "$n products"); flash($n ? "$n product(s) now have a picture. Replace any of them with your own photo link whenever you like." : 'Every product already has a picture.'); redirect('app/store'); }
    } catch (StoreError $e) { flash($e->getMessage(), 'err'); redirect('app/store'); }
}

$cfg = Store::settings($tenantId);
$q = trim($_GET['q'] ?? '');
$w = 'tenant_id=?'; $p = [$tenantId];
if ($q !== '') { $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%'; $w .= ' AND (name LIKE ? OR sku LIKE ? OR category LIKE ?)'; array_push($p, $like, $like, $like); }
$products = Db::all("SELECT * FROM store_products WHERE $w ORDER BY is_active DESC, name LIMIT 300", $p);
$total = (int)Db::val("SELECT COUNT(*) FROM store_products WHERE tenant_id=?", [$tenantId]);
$low = (int)Db::val("SELECT COUNT(*) FROM store_products WHERE tenant_id=? AND is_active=1 AND stock<=5", [$tenantId]);
$noPic = (int)Db::val("SELECT COUNT(*) FROM store_products WHERE tenant_id=? AND (image_url IS NULL OR image_url='')", [$tenantId]);
$zones = Db::all("SELECT * FROM store_zones WHERE tenant_id=? ORDER BY town", [$tenantId]);
$connected = Store::connected($tenantId);
$sources = Db::all("SELECT * FROM store_sources WHERE tenant_id=?", [$tenantId]); $srcBy = array_column($sources, null, 'type');
$otherTools = (int)Db::val("SELECT COUNT(*) FROM tools t JOIN connectors c ON c.id=t.connector_id WHERE t.tenant_id=? AND c.name<>? AND t.is_active=1", [$tenantId, Store::CONNECTOR]);
$edit = !empty($_GET['edit']) ? Db::one("SELECT * FROM store_products WHERE id=? AND tenant_id=?", [(int)$_GET['edit'], $tenantId]) : null;
$allPay = ['Cash on delivery', 'Bank transfer', 'Card', 'Online payment'];
?>
<div class="page-head"><div><h1>Your <span class="accent">Store</span></h1><p>Products, stock and delivery areas. The AI agent reads and updates this when it answers questions and takes orders, so you do not need a separate ERP.</p></div></div>

<div class="card" style="margin-bottom:22px;<?= $connected ? '' : 'background:var(--warn-bg);border-color:var(--warn)' ?>">
  <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
    <div style="flex:1;min-width:260px"><h3><?= $connected ? 'The AI agent is using this store' : 'Connect this store to your AI agent' ?></h3>
      <p class="sub" style="margin:0"><?= $connected ? 'It can search products, quote delivery, place, change and cancel orders, and record leads here. Every change still needs the customer to confirm first.' : 'One click gives the agent tools to search products, quote delivery, take orders and record leads in this store.' ?>
      <?php if ($otherTools && !$connected): ?> <b>Note:</b> you also have <?= $otherTools ?> tool(s) connected to another system; tools with the same names will be switched to this store.<?php endif; ?></p></div>
    <?php if ($owner): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="connect"><button class="btn primary"><?= $connected ? 'Refresh connection' : 'Use this store with the AI' ?></button></form><?php endif; ?>
  </div>
</div>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin-bottom:22px">
  <div class="card stat"><div class="muted small">Products</div><div class="big"><?= $total ?></div><div class="foot"><?= $low ?> low or out of stock</div></div>
  <div class="card stat"><div class="muted small">Delivery areas</div><div class="big"><?= count($zones) ?></div><div class="foot">Fee Rs. <?= num($cfg['default_fee']) ?> elsewhere</div></div>
  <div class="card stat"><div class="muted small">AI order limit</div><div class="big">Rs. <?= num($cfg['max_total']) ?></div><div class="foot">Bigger orders need your team</div></div>
</div>

<?php if (!$total && $owner): ?><div class="card" style="margin-bottom:22px"><h3>Start quickly</h3><p class="sub">Add 12 sample fashion products and Sri Lankan delivery areas to try the whole flow, or add your own below.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="sample"><button class="btn">Add sample products</button></form></div><?php endif; ?>

<div class="grid" style="grid-template-columns:1.6fr 1fr;align-items:start">
  <div class="card"><div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:12px"><h3 style="margin:0">Products</h3>
    <?php if ($owner && $noPic): ?><form method="post" title="Draws a clean picture for each product that has none; your own photo links are never replaced"><?= csrf_field() ?><input type="hidden" name="act" value="pictures"><button class="btn sm">Add pictures to <?= $noPic ?> product<?= $noPic === 1 ? '' : 's' ?></button></form><?php endif; ?>
    <form method="get" style="margin-left:auto"><input name="q" value="<?= e($q) ?>" placeholder="Search name, SKU, category" aria-label="Search products" style="padding:7px 12px"></form></div>
    <?php if (!$products): ?><div class="empty">No products<?= $q ? ' match your search' : ' yet' ?>.</div><?php else: ?>
    <div class="tablewrap"><table><thead><tr><th>Product</th><th class="num">Price</th><th class="num">Stock</th><th>Sizes</th><th></th></tr></thead><tbody>
    <?php foreach ($products as $r): ?><tr style="<?= $r['is_active'] ? '' : 'opacity:.55' ?>">
      <td><b><?= e($r['name']) ?></b><div class="muted small mono"><?= e($r['sku']) ?> · <?= e($r['category'] ?: 'No category') ?><?= $r['is_active'] ? '' : ' · hidden' ?></div></td>
      <td class="num">Rs. <?= num($r['price']) ?></td>
      <td class="num"><?php if ($owner): ?><form method="post" style="display:inline-flex;gap:4px"><?= csrf_field() ?><input type="hidden" name="act" value="stock_set"><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="number" name="stock" min="0" value="<?= (int)$r['stock'] ?>" aria-label="Stock for <?= e($r['sku']) ?>" style="width:70px;padding:5px 8px"><button class="btn sm">Set</button></form><?php else: ?><?= (int)$r['stock'] ?><?php endif; ?>
        <?php if ((int)$r['stock'] <= 0): ?><span class="badge err">out</span><?php elseif ((int)$r['stock'] <= 5): ?><span class="badge warn">low</span><?php endif; ?></td>
      <td class="small"><?= e($r['variants'] ?: '—') ?></td>
      <td style="white-space:nowrap"><?php if ($owner): ?><a class="btn sm" href="<?= url('app/store?edit=' . $r['id']) ?>#form">Edit</a>
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="product_toggle"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn sm"><?= $r['is_active'] ? 'Hide' : 'Show' ?></button></form>
        <form method="post" style="display:inline" data-confirm="Delete this product? Past orders keep their item names and prices."><?= csrf_field() ?><input type="hidden" name="act" value="product_delete"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn sm danger" aria-label="Delete product">×</button></form><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?></div>

  <?php if ($owner): ?><div class="stack">
  <form method="post" class="card stack" id="form"><?= csrf_field() ?><input type="hidden" name="act" value="product_save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>"><h3><?= $edit ? 'Edit product' : 'Add a product' ?></h3>
    <div class="row"><label>SKU<input name="sku" required value="<?= e($edit['sku'] ?? '') ?>" placeholder="TS-001"></label><label>Name<input name="name" required value="<?= e($edit['name'] ?? '') ?>"></label></div>
    <div class="row"><label>Price (Rs.)<input name="price" type="number" step="0.01" min="0" required value="<?= e($edit['price'] ?? '') ?>"></label><label>Stock<input name="stock" type="number" min="0" value="<?= e($edit['stock'] ?? 0) ?>"></label></div>
    <div class="row"><label>Category<input name="category" value="<?= e($edit['category'] ?? '') ?>" placeholder="Tops"></label><label>Cost price (private)<input name="cost_price" type="number" step="0.01" min="0" value="<?= e($edit['cost_price'] ?? 0) ?>"></label></div>
    <div class="row"><label>Sizes <small>comma separated</small><input name="variants" value="<?= e($edit['variants'] ?? '') ?>" placeholder="S,M,L"></label><label>Colours<input name="colors" value="<?= e($edit['colors'] ?? '') ?>" placeholder="Black,White"></label></div>
    <label>Description<textarea name="description" rows="2"><?= e($edit['description'] ?? '') ?></textarea></label>
    <label>Image link <small>https only</small><input name="image_url" value="<?= e($edit['image_url'] ?? '') ?>"></label>
    <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="is_active" value="1" <?= !$edit || $edit['is_active'] ? 'checked' : '' ?> style="width:auto"> Visible to customers and the AI</label>
    <div style="display:flex;gap:10px"><button class="btn primary"><?= $edit ? 'Save changes' : 'Add product' ?></button><?php if ($edit): ?><a class="btn" href="<?= url('app/store') ?>">Cancel</a><?php endif; ?></div>
    <p class="muted small" style="margin:0">Cost price is never shown to the AI or customers.</p></form>
  <form method="post" enctype="multipart/form-data" class="card stack"><?= csrf_field() ?><input type="hidden" name="act" value="import_csv"><h3>Import from CSV</h3>
    <p class="sub" style="margin:0">First row: <span class="mono">sku,name,price,stock,category,variants,colors,description</span>. Existing SKUs are updated.</p>
    <label>CSV file<input type="file" name="csv" accept=".csv,text/csv"></label><label>…or paste rows<textarea name="csv_text" rows="3" placeholder="sku,name,price,stock&#10;TS-009,Linen Shirt,3900,12"></textarea></label><button class="btn">Import</button></form>
  </div><?php endif; ?>
</div>

<?php if ($owner): ?><div class="card" style="margin-top:22px" id="sources"><h3>Import from WooCommerce or Shopify</h3><p class="sub">Read-only: names, prices, stock, sizes, colours and photos are copied here. Nothing is changed in your other shop. Products without a stock count are imported with 100 in stock; set them here afterwards.</p>
  <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr))">
  <?php foreach (['woocommerce' => 'WooCommerce', 'shopify' => 'Shopify'] as $tp => $tl): $sr = $srcBy[$tp] ?? null; ?><form method="post" class="stack" style="border:1px solid var(--line);border-radius:14px;padding:16px"><?= csrf_field() ?><input type="hidden" name="act" value="src_save"><input type="hidden" name="type" value="<?= $tp ?>"><b><?= $tl ?></b>
    <label><?= $tp === 'shopify' ? 'Shop address' : 'Website address' ?><input name="base_url" required value="<?= e($sr['base_url'] ?? '') ?>" placeholder="<?= $tp === 'shopify' ? 'myshop.myshopify.com' : 'https://myshop.lk' ?>"></label>
    <?php if ($tp === 'shopify'): ?><label>Admin API access token <small>read_products permission</small><input name="token" type="password" autocomplete="off" placeholder="<?= $sr ? 'saved; leave blank to keep' : 'shpat_…' ?>"></label>
    <?php else: ?><div class="row"><label>Consumer key<input name="key" type="password" autocomplete="off" placeholder="<?= $sr ? 'saved' : 'ck_…' ?>"></label><label>Consumer secret<input name="secret" type="password" autocomplete="off" placeholder="<?= $sr ? 'saved' : 'cs_…' ?>"></label></div><?php endif; ?>
    <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="auto_sync" value="1" <?= !empty($sr['auto_sync']) ? 'checked' : '' ?> style="width:auto"> Refresh every 6 hours</label>
    <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn">Save</button><?php if ($sr): ?><button class="btn primary" formaction="<?= url('app/store#sources') ?>" name="act" value="src_sync" formnovalidate>Import now</button><button class="btn danger" name="act" value="src_delete" formnovalidate data-x="1">Remove</button><input type="hidden" name="id" value="<?= (int)$sr['id'] ?>"><?php endif; ?></div>
    <?php if ($sr && $sr['last_status']): ?><div class="muted small">Last run <?= ago($sr['last_sync_at']) ?>: <?= e($sr['last_status']) ?></div><?php endif; ?></form><?php endforeach; ?></div></div><?php endif; ?>

<div class="grid" style="grid-template-columns:1.3fr 1fr;align-items:start;margin-top:22px" id="delivery">
  <div class="card"><h3>Delivery areas</h3><p class="sub">The AI quotes these. Towns not listed use the standard fee.</p>
    <?php if (!$zones): ?><div class="empty">No delivery areas yet.</div><?php else: ?><div class="tablewrap"><table><thead><tr><th>Town</th><th class="num">Fee</th><th class="num">Free over</th><th>Delivery time</th><th>COD</th><th></th></tr></thead><tbody>
    <?php foreach ($zones as $z): ?><tr><td><b><?= e($z['town']) ?></b></td><td class="num">Rs. <?= num($z['fee']) ?></td><td class="num"><?= $z['free_over'] !== null ? 'Rs. ' . num($z['free_over']) : 'standard' ?></td><td class="small"><?= e($z['eta'] ?: '—') ?></td><td><?= $z['cod_allowed'] ? '<span class="badge ok">yes</span>' : '<span class="badge gray">no</span>' ?></td>
      <td><?php if ($owner): ?><form method="post" data-confirm="Remove this delivery area?"><?= csrf_field() ?><input type="hidden" name="act" value="zone_delete"><input type="hidden" name="id" value="<?= $z['id'] ?>"><button class="btn sm danger" aria-label="Remove area">×</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
  <?php if ($owner): ?><div class="stack">
  <form method="post" class="card stack"><?= csrf_field() ?><input type="hidden" name="act" value="zone_save"><h3>Add or change an area</h3>
    <div class="row"><label>Town<input name="town" required placeholder="Kandy"></label><label>Fee (Rs.)<input name="fee" type="number" min="0" step="1" required></label></div>
    <div class="row"><label>Free over (Rs.) <small>blank = standard</small><input name="free_over" type="number" min="0"></label><label>Delivery time<input name="eta" placeholder="2-3 working days"></label></div>
    <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="cod_allowed" value="1" checked style="width:auto"> Cash on delivery available</label><button class="btn">Save area</button></form>
  <form method="post" class="card stack" id="settings"><?= csrf_field() ?><input type="hidden" name="act" value="settings_save"><h3>Store rules</h3>
    <div class="row"><label>Standard delivery fee (Rs.)<input name="default_fee" type="number" min="0" value="<?= e($cfg['default_fee']) ?>"></label><label>Free delivery over (Rs.) <small>0 = never</small><input name="free_over" type="number" min="0" value="<?= e($cfg['free_over']) ?>"></label></div>
    <label>Largest order the AI may place (Rs.) <small>0 = no limit. Bigger orders must be placed by your team.</small><input name="max_total" type="number" min="0" value="<?= e($cfg['max_total']) ?>"></label>
    <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="require_colour" value="1" <?= $cfg['require_colour'] ? 'checked' : '' ?> style="width:auto"> Ask for the colour when a product comes in several colours</label>
    <label>Shown on invoices <small>address, phone, bank account for transfers</small><textarea name="invoice_info" rows="3"><?= e($cfg['invoice_info']) ?></textarea></label>
    <fieldset style="border:0;padding:0;margin:0"><legend class="small muted" style="padding:0 0 6px">Payment methods you accept</legend><?php foreach ($allPay as $m): ?><label style="display:flex;gap:8px;align-items:center;margin-bottom:4px"><input type="checkbox" name="payments[]" value="<?= e($m) ?>" <?= in_array($m, $cfg['payments'], true) ? 'checked' : '' ?> style="width:auto"> <?= e($m) ?></label><?php endforeach; ?></fieldset>
    <button class="btn">Save rules</button></form></div><?php endif; ?>
</div>
