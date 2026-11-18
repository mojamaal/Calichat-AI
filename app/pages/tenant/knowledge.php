<?php
use CaliChat\Core\Audit;
use CaliChat\Core\Auth;
use CaliChat\Core\Db;
use CaliChat\Services\Knowledge;

$title = 'Knowledge';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); Auth::requireCan('knowledge_edit');
    $act = post('act');
    if ($act === 'delete') {
        Db::q("DELETE FROM knowledge_docs WHERE id=? AND tenant_id=?", [(int)post('id'), $tenantId]); // chunks cascade
        Audit::log('kb_delete', 'doc:' . (int)post('id')); flash('Document deleted.');
    } elseif ($act === 'reindex') {
        $n = Knowledge::reindex((int)$tenantId); flash($n ? "Created semantic embeddings for $n passages." : 'Everything is already indexed (or the AI service is unavailable).');
    } elseif ($act === 'clear_misses') {
        Db::q("DELETE FROM kb_misses WHERE tenant_id=?", [$tenantId]); flash('Cleared.');
    } else {
        $content = trim((string)($_POST['content'] ?? ''));
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['txt', 'md', 'csv'], true) && $_FILES['file']['size'] < 2000000) $content = trim(file_get_contents($_FILES['file']['tmp_name']));
        }
        if ($content === '') { flash('Add some text or a .txt/.md/.csv file.', 'err'); redirect('app/knowledge'); }
        if (!mb_check_encoding($content, 'UTF-8')) $content = mb_convert_encoding($content, 'UTF-8', 'auto');
        $content = mb_substr($content, 0, 200000);
        $title0 = mb_substr(post('title', 'Untitled'), 0, 200) ?: 'Untitled';
        if ($id = (int)post('id')) { Db::update('knowledge_docs', ['title' => $title0, 'content' => $content], 'id=? AND tenant_id=?', [$id, $tenantId]); }
        else { $id = Db::insert('knowledge_docs', ['tenant_id' => $tenantId, 'title' => $title0, 'content' => $content]); }
        Knowledge::index((int)$tenantId, $id, $content);
        Audit::log('kb_save', 'doc:' . $id, $title0);
        flash('Knowledge saved and indexed.');
    }
    redirect('app/knowledge');
}
$docs = Db::all("SELECT d.id, d.title, CHAR_LENGTH(d.content) len, d.created_at, (SELECT COUNT(*) FROM knowledge_chunks c WHERE c.doc_id=d.id) chunks, (SELECT COUNT(*) FROM knowledge_chunks c WHERE c.doc_id=d.id AND c.embedding IS NOT NULL) emb FROM knowledge_docs d WHERE d.tenant_id=? ORDER BY d.id DESC", [$tenantId]);
$edit = isset($_GET['id']) ? Db::one("SELECT * FROM knowledge_docs WHERE id=? AND tenant_id=?", [(int)$_GET['id'], $tenantId]) : null;
$misses = Db::all("SELECT query, hits, last_seen FROM kb_misses WHERE tenant_id=? ORDER BY hits DESC, last_seen DESC LIMIT 8", [$tenantId]);
$unembedded = array_sum(array_map(fn($d) => $d['chunks'] - $d['emb'], $docs));
$owner = Auth::can('knowledge_edit');
?>
<div class="page-head"><div><h1>Knowledge <span class="accent">Base</span></h1><p>FAQs, policies, shipping, returns, sizing guides — the agent searches these by meaning (English, Sinhala, Tamil) before answering.</p></div>
  <?php if ($owner && $unembedded): ?><div class="actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="reindex"><button class="btn"><?= icon('zap', 16) ?> Build semantic index (<?= $unembedded ?> passages)</button></form></div><?php endif; ?></div>
<div class="grid" style="grid-template-columns:1fr 1.2fr;align-items:start">
  <div class="stack">
    <div class="card"><h3>Documents</h3><p class="sub"><?= count($docs) ?> indexed</p>
      <?php foreach ($docs as $d): ?><div class="row" style="align-items:center;flex-wrap:nowrap;padding:10px 0;border-top:1px solid var(--line)"><div style="flex:1"><a href="<?= url('app/knowledge?id=' . $d['id']) ?>"><b><?= e($d['title']) ?></b></a><div class="muted small"><?= num($d['len']) ?> chars · <?= $d['chunks'] ?> passages · <?= $d['emb'] == $d['chunks'] ? 'semantic ✓' : 'keyword only' ?> · <?= ago($d['created_at']) ?></div></div>
        <?php if ($owner): ?><form method="post" data-confirm="Delete this document?" style="flex:none;min-width:0"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $d['id'] ?>"><button class="btn sm danger" aria-label="Delete document"><?= icon('trash', 15) ?></button></form><?php endif; ?></div><?php endforeach; ?>
      <?php if (!$docs): ?><div class="empty">Nothing yet.</div><?php endif; ?></div>
    <?php if ($misses): ?><div class="card"><h3>Unanswered questions</h3><p class="sub">Customers asked these and nothing relevant was found. Add them here.</p>
      <table><tbody><?php foreach ($misses as $m): ?><tr><td><?= e($m['query']) ?></td><td class="num"><?= $m['hits'] ?>×</td></tr><?php endforeach; ?></tbody></table>
      <?php if ($owner): ?><form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="act" value="clear_misses"><button class="btn sm">Clear list</button></form><?php endif; ?></div><?php endif; ?>
  </div>
  <?php if ($owner): ?><form method="post" enctype="multipart/form-data" class="card stack"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <h3><?= $edit ? 'Edit document' : 'Add knowledge' ?></h3>
    <label>Title<input name="title" required value="<?= e($edit['title'] ?? '') ?>" placeholder="Return & refund policy"></label>
    <label>Content <small>Separate topics with blank lines for better retrieval</small><textarea name="content" rows="12"><?= e($edit['content'] ?? '') ?></textarea></label>
    <label>…or upload a .txt / .md / .csv file<input type="file" name="file" accept=".txt,.md,.csv"></label>
    <div class="row" style="justify-content:flex-start"><button class="btn primary" style="flex:none">Save &amp; index</button><?php if ($edit): ?><a class="btn" style="flex:none" href="<?= url('app/knowledge') ?>">New</a><?php endif; ?></div>
  </form><?php endif; ?>
</div>
