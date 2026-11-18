<?php
use CaliChat\Core\Audit;
use CaliChat\Core\Auth;
use CaliChat\Core\Crypto;
use CaliChat\Core\Db;
use CaliChat\Services\ToolRunner;

$title = 'Connectors';
$cid = (int)($_GET['c'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); Auth::requireOwner();
    $act = post('act');
    if ($act === 'conn_save') {
        $url = rtrim(post('base_url'), '/');
        if (!preg_match('#^https?://#i', $url)) { flash('Base URL must start with http:// or https://', 'err'); redirect('app/connectors'); }
        $d = ['name' => mb_substr(post('name', 'Connector'), 0, 120), 'base_url' => mb_substr($url, 0, 500),
              'auth_type' => in_array(post('auth_type'), ['none', 'bearer', 'header', 'basic', 'query'], true) ? post('auth_type') : 'none',
              'auth_name' => mb_substr(post('auth_name'), 0, 80), 'timeout_s' => max(3, min(30, (int)post('timeout_s', '12'))), 'is_active' => isset($_POST['is_active']) ? 1 : 0];
        if (post('auth_secret') !== '') $d['auth_secret'] = Crypto::encrypt(post('auth_secret'));
        if ($id = (int)post('id')) { Db::update('connectors', $d, 'id=? AND tenant_id=?', [$id, $tenantId]); }
        else { $id = Db::insert('connectors', $d + ['tenant_id' => $tenantId]); }
        Audit::log('connector_save', 'connector:' . $id, $d['base_url']);
        flash('Connector saved.'); redirect('app/connectors?c=' . $id);
    }
    if ($act === 'conn_delete') {
        $id = (int)post('id');
        Db::q("DELETE FROM connectors WHERE id=? AND tenant_id=?", [$id, $tenantId]); // tools cascade
        Audit::log('connector_delete', 'connector:' . $id);
        flash('Connector and its tools deleted.'); redirect('app/connectors');
    }
    if ($act === 'tool_save') {
        $conn = (int)post('connector_id');
        if (!Db::val("SELECT id FROM connectors WHERE id=? AND tenant_id=?", [$conn, $tenantId])) { flash('Connector not found.', 'err'); redirect('app/connectors'); }
        $name = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', post('name')));
        if (in_array($name, ['search_knowledge', 'handoff_to_human', ''], true)) { flash('Tool name is reserved or empty.', 'err'); redirect("app/connectors?c=$conn"); }
        $params = json_decode(post('params_json', '[]'), true);
        $clean = [];
        foreach (is_array($params) ? $params : [] as $p) {
            $pn = preg_replace('/[^a-zA-Z0-9_]/', '_', (string)($p['name'] ?? ''));
            if ($pn === '') continue;
            $pattern = mb_substr((string)($p['pattern'] ?? ''), 0, 200);
            if ($pattern !== '' && !ToolRunner::patternIsValid($pattern)) { flash("Parameter '$pn' has an invalid pattern.", 'err'); redirect("app/connectors?c=$conn"); }
            $src = in_array($p['source'] ?? '', ['ai', 'contact.phone', 'contact.external_id', 'contact.name', 'static'], true) ? $p['source'] : 'ai';
            $clean[] = ['name' => $pn, 'type' => in_array($p['type'] ?? '', ['string', 'number', 'integer', 'boolean'], true) ? $p['type'] : 'string',
                'in' => in_array($p['in'] ?? '', ['path', 'query', 'body'], true) ? $p['in'] : 'query', 'required' => !empty($p['required']) ? 1 : 0,
                'source' => $src, 'value' => (string)($p['value'] ?? ''), 'description' => mb_substr((string)($p['description'] ?? ''), 0, 200),
                'pattern' => $pattern, 'enum' => mb_substr((string)($p['enum'] ?? ''), 0, 300), 'max_len' => (int)($p['max_len'] ?? 0) ?: '', 'min' => $p['min'] ?? '', 'max' => $p['max'] ?? ''];
        }
        $method = in_array(post('method'), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true) ? post('method') : 'GET';
        $fields = mb_substr(post('response_fields'), 0, 500);
        $expose = isset($_POST['expose_all']) ? 1 : 0;
        if ($fields === '' && !$expose) { flash('List the response fields the AI may see, or tick “Expose all fields”. Unlisted fields are hidden by default.', 'err'); redirect("app/connectors?c=$conn" . (post('id') ? '&t=' . (int)post('id') : '&newtool=1')); }
        $d = ['connector_id' => $conn, 'name' => mb_substr($name, 0, 64), 'description' => mb_substr(post('description'), 0, 1000), 'method' => $method,
              'path' => '/' . ltrim(post('path', '/'), '/'), 'params' => json_encode($clean), 'response_path' => mb_substr(post('response_path'), 0, 200),
              'response_fields' => $fields, 'expose_all' => $expose, 'cache_ttl' => $method === 'GET' ? max(0, min(3600, (int)post('cache_ttl', '0'))) : 0,
              'allow_write' => ($method !== 'GET' && isset($_POST['allow_write'])) ? 1 : 0, 'requires_approval' => ($method !== 'GET' && isset($_POST['requires_approval'])) ? 1 : 0, 'is_active' => isset($_POST['is_active']) ? 1 : 0];
        try {
            if ($id = (int)post('id')) Db::update('tools', $d, 'id=? AND tenant_id=?', [$id, $tenantId]);
            else $id = Db::insert('tools', $d + ['tenant_id' => $tenantId]);
            Audit::log('tool_save', 'tool:' . $name, $method . ' ' . $d['path'] . ($d['allow_write'] ? ' WRITE' : ''));
            flash('Tool saved.');
        } catch (\PDOException $e) { flash('A tool with that name already exists.', 'err'); }
        redirect("app/connectors?c=$conn");
    }
    if ($act === 'tool_delete') {
        Db::q("DELETE FROM tools WHERE id=? AND tenant_id=?", [(int)post('id'), $tenantId]); Audit::log('tool_delete', 'tool:' . (int)post('id')); flash('Tool deleted.'); redirect('app/connectors?c=' . (int)post('connector_id'));
    }
}

$conns = Db::all("SELECT c.*, (SELECT COUNT(*) FROM tools t WHERE t.connector_id=c.id) tools FROM connectors c WHERE c.tenant_id=? ORDER BY id", [$tenantId]);
$cur = $cid ? Db::one("SELECT * FROM connectors WHERE id=? AND tenant_id=?", [$cid, $tenantId]) : null;
$newConn = isset($_GET['new']);
$tools = $cur ? Db::all("SELECT * FROM tools WHERE connector_id=? AND tenant_id=? ORDER BY id", [$cur['id'], $tenantId]) : [];
$editTool = ($cur && isset($_GET['t'])) ? Db::one("SELECT * FROM tools WHERE id=? AND tenant_id=?", [(int)$_GET['t'], $tenantId]) : null;
$newTool = $cur && isset($_GET['newtool']);
$owner = Auth::isOwner();
?>
<div class="page-head"><div><h1>System <span class="accent">Connectors</span></h1><p>Give your agent live access to your ERP, CRM, store or any REST API. Each endpoint becomes a tool the AI can call.</p></div>
  <?php if ($owner): ?><div class="actions"><a class="btn primary" href="<?= url('app/connectors?new=1') ?>"><?= icon('plus', 18) ?> New connector</a></div><?php endif; ?></div>

<div class="grid" style="grid-template-columns:300px 1fr;align-items:start">
  <div class="stack" style="gap:10px">
    <?php foreach ($conns as $c): ?>
      <a href="<?= url('app/connectors?c=' . $c['id']) ?>" class="card" style="padding:16px 18px;color:inherit;<?= $cur && $cur['id'] == $c['id'] ? 'border-color:var(--brand)' : '' ?>">
        <b><?= e($c['name']) ?></b> <span class="badge <?= $c['is_active'] ? 'ok' : 'gray' ?>"><?= $c['is_active'] ? 'live' : 'off' ?></span>
        <div class="muted small" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($c['base_url']) ?></div><div class="muted small"><?= (int)$c['tools'] ?> tool<?= $c['tools'] == 1 ? '' : 's' ?></div></a>
    <?php endforeach; ?>
    <?php if (!$conns): ?><div class="card empty">No connectors yet.</div><?php endif; ?>
  </div>

  <div class="stack">
  <?php if (($newConn || $cur) && $owner): $c = $cur ?: ['id' => 0, 'name' => '', 'base_url' => '', 'auth_type' => 'bearer', 'auth_name' => '', 'timeout_s' => 12, 'is_active' => 1]; ?>
    <form method="post" class="card stack"><?= csrf_field() ?><input type="hidden" name="act" value="conn_save"><input type="hidden" name="id" value="<?= $c['id'] ?>">
      <h3><?= $c['id'] ? 'Connection settings' : 'New connector' ?></h3>
      <div class="row"><label>Name<input name="name" required value="<?= e($c['name']) ?>" placeholder="e.g. Calimax ERP"></label><label>Base URL<input name="base_url" required value="<?= e($c['base_url']) ?>" placeholder="<?= e(abs_url('mock/erp')) ?>"></label></div>
      <div class="row"><label>Authentication<select name="auth_type"><?php foreach (['none' => 'None', 'bearer' => 'Bearer token', 'header' => 'Custom header (API key)', 'basic' => 'Basic (user:pass)', 'query' => 'Query-string key'] as $k => $l): ?><option value="<?= $k ?>" <?= $c['auth_type'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
        <label>Header / param name <small>for header & query auth</small><input name="auth_name" value="<?= e($c['auth_name']) ?>" placeholder="X-API-Key"></label>
        <label>Secret <small><?= $c['id'] ? 'leave blank to keep' : 'stored encrypted' ?></small><input name="auth_secret" type="password" autocomplete="off"></label></div>
      <div class="row"><label>Timeout (seconds)<input type="number" name="timeout_s" min="3" max="30" value="<?= (int)$c['timeout_s'] ?>"></label><label class="check" style="align-self:end"><input type="checkbox" name="is_active" <?= $c['is_active'] ? 'checked' : '' ?>> Enabled</label></div>
      <div class="row" style="justify-content:flex-start"><button class="btn primary" style="flex:none">Save connector</button></div>
    </form>
    <?php if ($c['id']): ?>
      <form method="post" data-confirm="Delete this connector and all its tools?"><?= csrf_field() ?><input type="hidden" name="act" value="conn_delete"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn sm danger">Delete connector</button></form>

      <div class="card"><div class="row" style="align-items:center;flex-wrap:nowrap"><div style="flex:1"><h3>Tools</h3><p class="sub" style="margin:0">Endpoints the agent may call</p></div><a class="btn sm primary" style="flex:none" href="<?= url('app/connectors?c=' . $c['id'] . '&newtool=1') ?>"><?= icon('plus', 16) ?> Add tool</a></div>
        <div style="margin-top:16px">
        <?php foreach ($tools as $t): ?>
          <details class="fold"><summary><span class="badge gray"><?= e($t['method']) ?></span> <b class="mono"><?= e($t['name']) ?></b> <span class="muted small"><?= e($t['path']) ?></span> <?= $t['is_active'] ? '' : '<span class="badge gray">off</span>' ?><?= $t['cache_ttl'] ? ' <span class="badge">cache ' . (int)$t['cache_ttl'] . 's</span>' : '' ?></summary>
            <p class="muted small"><?= e($t['description']) ?></p>
            <div id="test-<?= $t['id'] ?>" class="stack" style="margin-bottom:14px">
              <?php if ($t['method'] === 'GET'): ?>
              <div class="row"><?php foreach (ToolRunner::paramDefs($t) as $p): if (($p['source'] ?? 'ai') !== 'ai') continue; ?><label><?= e($p['name']) ?><input data-arg="<?= e($p['name']) ?>"></label><?php endforeach; ?>
                <label>Test customer phone <small>for contact-bound params</small><input data-phone value="94771234567"></label></div>
              <div><button type="button" class="btn sm" data-test-tool="<?= $t['id'] ?>"><?= icon('zap', 15) ?> Run test</button> <a class="btn sm" href="<?= url('app/connectors?c=' . $c['id'] . '&t=' . $t['id']) ?>">Edit</a></div>
              <pre class="out card mono" style="padding:14px;margin:0;max-height:260px;overflow:auto;white-space:pre-wrap;background:var(--card-2);box-shadow:none">Run a test to see the live response…</pre>
              <?php else: ?><div class="muted small">Write tools cannot be run from the test panel. Use them in a real chat, where the customer must confirm first.</div><a class="btn sm" style="align-self:flex-start" href="<?= url('app/connectors?c=' . $c['id'] . '&t=' . $t['id']) ?>">Edit</a><?php endif; ?>
            </div></details>
        <?php endforeach; ?>
        <?php if (!$tools): ?><div class="empty">No tools yet — add one so the agent can look up products, orders, customers…</div><?php endif; ?>
        </div></div>

      <?php if ($newTool || $editTool): $t = $editTool ?: ['id' => 0, 'name' => '', 'description' => '', 'method' => 'GET', 'path' => '/', 'params' => '[]', 'response_path' => '', 'response_fields' => '', 'expose_all' => 0, 'cache_ttl' => 0, 'allow_write' => 0, 'requires_approval' => 0, 'is_active' => 1]; ?>
      <form method="post" class="card stack" id="tool-form"><?= csrf_field() ?><input type="hidden" name="act" value="tool_save"><input type="hidden" name="id" value="<?= $t['id'] ?>"><input type="hidden" name="connector_id" value="<?= $c['id'] ?>">
        <h3><?= $t['id'] ? 'Edit tool' : 'New tool' ?></h3>
        <div class="row"><label>Tool name <small>letters, numbers, underscore</small><input name="name" required value="<?= e($t['name']) ?>" placeholder="get_order_status"></label>
          <label>Method<select name="method"><?php foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $m): ?><option <?= $t['method'] === $m ? 'selected' : '' ?>><?= $m ?></option><?php endforeach; ?></select></label>
          <label>Path <small>use {placeholders}</small><input name="path" value="<?= e($t['path']) ?>" placeholder="/orders/{order_no}"></label></div>
        <label>When should the AI use this tool? <small>Be specific — this is the instruction the model reads</small><textarea name="description" rows="3" required><?= e($t['description']) ?></textarea></label>
        <div><div class="eyebrow" style="margin-bottom:8px">Parameters</div>
          <div class="muted small" style="margin-bottom:8px">Value source: <b>ai</b> = the model fills it from the chat · <b>contact.phone</b> = forced from the verified sender (use this so customers can only read their own data) · <b>static</b> = fixed value. Add a pattern, allowed values or a length limit to every AI-filled parameter. Path parameters are always restricted to letters, numbers, dot, dash and underscore.</div>
          <div id="params-list" data-initial="<?= e($t['params']) ?>"></div>
          <button type="button" class="btn sm" id="add-param"><?= icon('plus', 15) ?> Add parameter</button><input type="hidden" name="params_json" id="params-json"></div>
        <div class="row"><label>Response path <small>dot path to the useful part, e.g. data</small><input name="response_path" value="<?= e($t['response_path']) ?>"></label>
          <label>Fields the AI may see <small>comma list — everything else is hidden from the AI and from Google</small><input name="response_fields" value="<?= e($t['response_fields']) ?>" placeholder="order_no,status,eta,total,items,name,qty"></label></div>
        <div class="row"><label class="check"><input type="checkbox" name="expose_all" <?= $t['expose_all'] ? 'checked' : '' ?>> Expose ALL fields (not recommended: internal data may reach customers)</label>
          <label>Cache GET results for (seconds, 0 = off)<input type="number" name="cache_ttl" min="0" max="3600" value="<?= (int)$t['cache_ttl'] ?>"></label></div>
        <div class="row"><label class="check"><input type="checkbox" name="is_active" <?= $t['is_active'] ? 'checked' : '' ?>> Enabled</label><label class="check"><input type="checkbox" name="allow_write" <?= $t['allow_write'] ? 'checked' : '' ?>> Allow this non-GET tool to change data</label></div>
        <div class="row"><label class="check"><input type="checkbox" name="requires_approval" <?= $t['requires_approval'] ? 'checked' : '' ?>> Require team approval before it runs (otherwise the AI acts on its own once the customer confirms)</label></div>
        <div class="muted small">Safety built in for every write tool: the system first shows the customer a preview, only runs after the customer replies to it, limits changes per chat, never repeats a request twice, and records each action in the audit log.</div>
        <div class="row" style="justify-content:flex-start"><button class="btn primary" style="flex:none">Save tool</button>
          <?php if ($t['id']): ?><button class="btn danger" style="flex:none" form="tool-del">Delete</button><?php endif; ?></div>
      </form>
      <?php if ($t['id']): ?><form id="tool-del" method="post" data-confirm="Delete this tool?"><?= csrf_field() ?><input type="hidden" name="act" value="tool_delete"><input type="hidden" name="id" value="<?= $t['id'] ?>"><input type="hidden" name="connector_id" value="<?= $c['id'] ?>"></form><?php endif; ?>
      <template id="param-tpl"><div class="prm"><div class="paramrow">
        <input data-f="name" placeholder="name" aria-label="Parameter name">
        <select data-f="type" aria-label="Type"><option>string</option><option>number</option><option>integer</option><option>boolean</option></select>
        <select data-f="in" aria-label="Location"><option>query</option><option>path</option><option>body</option></select>
        <select data-f="source" aria-label="Value source"><option value="ai">ai</option><option value="contact.phone">contact.phone</option><option value="contact.external_id">contact.external_id</option><option value="contact.name">contact.name</option><option value="static">static</option></select>
        <input data-f="description" placeholder="description for AI" aria-label="Description">
        <input data-f="value" placeholder="static value" aria-label="Static value">
        <label class="check" style="font-size:12px"><input type="checkbox" data-f="required" value="1">req</label><button type="button" class="btn sm danger" data-del aria-label="Remove parameter">×</button></div>
        <div class="paramrow2"><input data-f="pattern" placeholder="pattern e.g. ^ORD-[0-9]{3,8}$" aria-label="Allowed pattern"><input data-f="enum" placeholder="allowed values: a,b,c" aria-label="Allowed values"><input data-f="max_len" type="number" placeholder="max len" aria-label="Maximum length"><input data-f="min" placeholder="min" aria-label="Minimum"><input data-f="max" placeholder="max" aria-label="Maximum"></div></div></template>
      <?php endif; ?>
    <?php endif; ?>
  <?php elseif (!$cur): ?>
    <div class="card empty"><h3>Connect your first system</h3><p>For a quick demo, create a connector with base URL <span class="mono"><?= e(abs_url('mock/erp')) ?></span>, Bearer token <span class="mono">demo-erp-token</span>.</p></div>
  <?php else: ?>
    <div class="card"><h3><?= e($cur['name']) ?></h3><p class="muted">Only owners can change connectors and tools.</p></div>
  <?php endif; ?>
  </div>
</div>
