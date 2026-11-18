(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var base = ($('meta[name=base]') || {}).content || '';
  var csrf = ($('meta[name=csrf]') || {}).content || '';
  function api(path, body) {
    var o = { method: body ? 'POST' : 'GET', headers: { 'X-CSRF': csrf, 'Content-Type': 'application/json' }, credentials: 'same-origin' };
    if (body) o.body = JSON.stringify(body);
    return fetch(base + '/api/' + path, o).then(function (r) { return r.json().catch(function () { return { error: 'Bad response (' + r.status + ')' }; }); });
  }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
  function toBottom(el) { el.scrollTop = el.scrollHeight; }

  // ---- in-chat cards (product card, catalogue) and the modal they open ----
  // The AI sends these to the customer; the dashboard chats (Playground, Inbox) draw them as real cards, like WhatsApp's catalogue.
  // Clicking one opens the customer catalogue in a modal on top of the chat (no new browser tab).
  function money(n) { return 'Rs. ' + Math.round(+n || 0).toLocaleString('en-US'); }
  function media(p) { return p ? base + '/' + String(p).replace(/^\//, '') : ''; }
  function initial(s) { return esc(String(s || '?').trim().charAt(0).toUpperCase()); }
  function safeColor(c) { return /^#[0-9A-Fa-f]{6}$/.test(c || '') ? c : '#5B5BF0'; }
  function richCard(r) {
    var head = '<div class="rc-h">' + (r.logo ? '<img class="rc-logo" src="' + esc(media(r.logo)) + '" alt="">' : '<span class="rc-logo ph">' + initial(r.brand) + '</span>') + '<span class="rc-brand"><b>' + esc(r.brand) + '</b>' + (r.tagline ? '<small>' + esc(r.tagline) + '</small>' : '') + '</span></div>';
    var st = 'style="--rc:' + safeColor(r.color) + '"';
    if (r.type === 'product') {
      var tag = r.stock <= 0 ? '<span class="rc-tag out">Sold out</span>' : (r.stock <= 5 ? '<span class="rc-tag low">Only ' + (+r.stock) + ' left</span>' : '<span class="rc-tag in">In stock</span>');
      var opts = [(r.sizes && r.sizes.length ? 'Sizes: ' + r.sizes.join(' ') : ''), (r.colors && r.colors.length ? 'Colours: ' + r.colors.join(', ') : '')].filter(Boolean).join(' · ');
      return '<div class="rc" role="button" tabindex="0" data-rich="product" data-sku="' + esc(r.sku) + '" data-cat="' + esc(r.category || '') + '" ' + st + ' aria-label="Open ' + esc(r.name) + '">' + head +
        '<div class="rc-i"><span class="rc-ini">' + initial(r.name) + '</span>' + (r.photo ? '<img src="' + esc(media(r.photo)) + '" alt="" loading="lazy" data-hide-on-error>' : '') + '</div>' +
        '<div class="rc-b"><div class="rc-n">' + esc(r.name) + '</div><div class="rc-row"><span class="rc-p">' + money(r.price) + '</span>' + tag + '</div>' + (opts ? '<div class="rc-s">' + esc(opts) + '</div>' : '') + '</div>' +
        '<div class="rc-a">View product</div></div>';
    }
    var tiles = (r.items || []).map(function (it, i) {
      var more = i === 3 && r.count > 4 ? '<span class="rc-more">+' + (r.count - 3) + '</span>' : '';
      return '<div class="rc-tile"><span class="rc-ini">' + initial(it.name) + '</span>' + (it.photo ? '<img src="' + esc(media(it.photo)) + '" alt="" loading="lazy" data-hide-on-error>' : '') + more + '</div>';
    }).join('');
    return '<div class="rc cat" role="button" tabindex="0" data-rich="catalogue" data-cat="' + esc(r.category || '') + '" ' + st + ' aria-label="Open catalogue">' + head +
      (tiles ? '<div class="rc-g">' + tiles + '</div>' : '') + '<div class="rc-b"><div class="rc-n">' + esc(r.title) + (r.category ? ' · ' + esc(r.category) : '') + '</div><div class="rc-s">' + (r.intro ? esc(r.intro) : (+r.count || 0) + ' products') + '</div></div><div class="rc-a">View catalogue</div></div>';
  }
  function richHtml(list) { return (list || []).map(richCard).join(''); }
  window.CCRich = { html: richHtml }; // the Playground page (playground.js) draws the same cards
  // product photos that fail to load simply fall back to the initial (no inline error handlers: strict CSP)
  document.addEventListener('error', function (e) { var t = e.target; if (t && t.tagName === 'IMG' && t.hasAttribute('data-hide-on-error')) t.remove(); }, true);

  var shopModal = null;
  function closeShop() { if (shopModal) { shopModal.remove(); shopModal = null; document.body.style.overflow = ''; } }
  function openShop(src) {
    closeShop();
    shopModal = document.createElement('div'); shopModal.className = 'mveil'; shopModal.setAttribute('role', 'dialog'); shopModal.setAttribute('aria-modal', 'true'); shopModal.setAttribute('aria-label', 'Catalogue');
    shopModal.innerHTML = '<button type="button" class="mx" aria-label="Close catalogue">×</button><div class="mbox"><iframe title="Catalogue" src="' + esc(src) + '"></iframe></div>';
    shopModal.addEventListener('click', function (e) { if (e.target === shopModal || e.target.closest('.mx')) closeShop(); });
    document.body.appendChild(shopModal); document.body.style.overflow = 'hidden'; var x = $('.mx', shopModal); if (x) x.focus();
  }
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeShop(); });
  window.addEventListener('message', function (e) { if (e.origin === location.origin && e.data && e.data.cc === 'close-shop') closeShop(); });
  function openRich(card) {
    var body = { sku: card.getAttribute('data-sku') || '', category: card.getAttribute('data-cat') || '' };
    if (card.getAttribute('data-rich') === 'catalogue') body.sku = '';
    var pgw = $('#pg-writes'); if (pgw && pgw.checked && $('#pg')) { body.live = 1; body.phone = ($('#pg-phone') || {}).value; } // Playground with write actions on: the customer's own link, so the order really can be placed
    card.classList.add('busy');
    api('catalogue/link', body).then(function (r) { card.classList.remove('busy'); if (r.error) { alert(r.error); return; } openShop(r.url); });
  }
  document.addEventListener('click', function (e) { var c = e.target.closest('.rc[data-rich]'); if (c) openRich(c); });
  document.addEventListener('keydown', function (e) { if ((e.key === 'Enter' || e.key === ' ') && e.target.classList && e.target.classList.contains('rc')) { e.preventDefault(); openRich(e.target); } });

  // ---- sidebar sections: remember which ones a person opened or closed (the section holding the current page is always open) ----
  (function () {
    var groups = $$('details.nav-group'); if (!groups.length) return; var st = {};
    try { st = JSON.parse(localStorage.getItem('cc_nav') || '{}') || {}; } catch (e) {}
    groups.forEach(function (d) {
      var k = d.getAttribute('data-key'); if (d.getAttribute('data-active') !== '1' && Object.prototype.hasOwnProperty.call(st, k)) d.open = !!st[k];
      var s = d.querySelector('summary'); if (s) s.addEventListener('click', function () { setTimeout(function () { st[k] = d.open; try { localStorage.setItem('cc_nav', JSON.stringify(st)); } catch (e) {} }, 0); });
    });
  })();
  // ---- generic UI behaviours (no inline handlers: strict CSP) ----
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-toggle]');
    if (t) { var el = document.getElementById(t.getAttribute('data-toggle')); if (el) el.classList.toggle('open'); }
    var c = e.target.closest('[data-copy]');
    if (c) { var txt = c.getAttribute('data-copy'), old = c.textContent; (navigator.clipboard ? navigator.clipboard.writeText(txt) : Promise.reject()).then(function () { c.textContent = 'Copied'; setTimeout(function () { c.textContent = old; }, 1200); }).catch(function () { window.prompt('Copy:', txt); }); }
    var rq = e.target.closest('[data-requeue]');
    if (rq) { rq.disabled = true; api('events/requeue', { id: +rq.getAttribute('data-requeue') }).then(function () { location.reload(); }); }
    var rs = e.target.closest('[data-resolve]');
    if (rs) { api('alerts/resolve', { id: +rs.getAttribute('data-resolve') }).then(function () { location.reload(); }); }
    var vf = e.target.closest('[data-verify]');
    if (vf) {
      var out = document.getElementById('verify-' + vf.getAttribute('data-verify')); vf.disabled = true; out.textContent = 'Checking…';
      api('channels/verify', { id: +vf.getAttribute('data-verify') }).then(function (r) { vf.disabled = false; out.textContent = r.ok ? '✓ Connected as ' + r.name : '✗ ' + (r.error || 'Failed'); out.className = r.ok ? 'up' : 'down'; });
    }
  });
  document.addEventListener('submit', function (e) { var m = e.target.getAttribute('data-confirm'); if (m && !confirm(m)) e.preventDefault(); });
  // Selects marked data-autosubmit send their form as soon as the choice changes (lead board "Move to").
  document.addEventListener('change', function (e) { var s = e.target; if (s && s.hasAttribute && s.hasAttribute('data-autosubmit') && s.form) s.form.submit(); });
  // Live template preview: textarea[data-tpl-preview] renders {placeholders} with sample values into #tpl-preview.
  (function () {
    var src = document.querySelector('[data-tpl-preview]'), out = document.getElementById('tpl-preview'); if (!src || !out) return;
    var sample = {}; try { sample = JSON.parse(src.getAttribute('data-samples') || '{}'); } catch (x) {}
    function esc(s) { return s.replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }); }
    function draw() {
      var t = src.value, vars = [], seen = {};
      t.replace(/\{([a-z_]{1,20})\}/g, function (m, n) { if (!seen[n]) { seen[n] = vars.length + 1; vars.push(n); } return m; });
      var html = esc(t).replace(/\{([a-z_]{1,20})\}/g, function (m, n) { return '<mark title="Meta receives {{' + seen[n] + '}}">' + esc(sample[n] || n) + '</mark>'; }).replace(/\n/g, '<br>');
      var head = document.getElementById('tpl-header'), foot = document.getElementById('tpl-footer');
      out.innerHTML = (head && head.value ? '<b>' + esc(head.value) + '</b><br>' : '') + (html || '<span class="muted">Your message appears here</span>') + (foot && foot.value ? '<div class="muted small" style="margin-top:6px">' + esc(foot.value) + '</div>' : '');
      var map = document.getElementById('tpl-map'); if (map) map.textContent = vars.length ? vars.map(function (n, i) { return '{{' + (i + 1) + '}} = ' + n; }).join('  ·  ') : 'No placeholders yet';
    }
    ['input', 'change'].forEach(function (ev) { document.querySelectorAll('[data-tpl-preview],#tpl-header,#tpl-footer').forEach(function (el) { el.addEventListener(ev, draw); }); });
    document.querySelectorAll('[data-insert]').forEach(function (b) { b.addEventListener('click', function () { var s = src.selectionStart || src.value.length; src.value = src.value.slice(0, s) + b.getAttribute('data-insert') + src.value.slice(src.selectionEnd || s); src.focus(); draw(); }); });
    draw();
  })();
  $$('[data-nav-select]').forEach(function (s) { s.addEventListener('change', function () { location.href = s.getAttribute('data-nav-select') + s.value; }); });
  $$('[data-autosubmit]').forEach(function (s) { s.addEventListener('change', function () { s.form.submit(); }); });

  // ---- tool parameter editor ----
  var plist = $('#params-list');
  if (plist) {
    var tpl = $('#param-tpl');
    var add = function (data) {
      var n = tpl.content.firstElementChild.cloneNode(true);
      if (data) $$('[data-f]', n).forEach(function (i) { var v = data[i.getAttribute('data-f')]; if (v == null) return; if (i.type === 'checkbox') i.checked = !!+v; else i.value = v; });
      $('[data-del]', n).addEventListener('click', function () { n.remove(); });
      plist.appendChild(n);
    };
    $('#add-param').addEventListener('click', function () { add(); });
    try { (JSON.parse(plist.getAttribute('data-initial') || '[]')).forEach(add); } catch (e) {}
    plist.closest('form').addEventListener('submit', function () {
      var out = $$('.prm', plist).map(function (r) { var o = {}; $$('[data-f]', r).forEach(function (i) { o[i.getAttribute('data-f')] = i.type === 'checkbox' ? (i.checked ? 1 : 0) : i.value; }); return o; }).filter(function (o) { return o.name; });
      $('#params-json').value = JSON.stringify(out);
    });
  }

  // ---- tool tester ----
  $$('[data-test-tool]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var box = document.getElementById('test-' + btn.getAttribute('data-test-tool'));
      var args = {}; $$('[data-arg]', box).forEach(function (i) { if (i.value) args[i.getAttribute('data-arg')] = i.value; });
      var out = $('.out', box); out.textContent = 'Running…';
      api('tools/test', { tool_id: +btn.getAttribute('data-test-tool'), args: args, phone: ($('[data-phone]', box) || {}).value || '' }).then(function (r) {
        out.textContent = (r.ok ? '✓ ' : '✗ ') + 'HTTP ' + r.status + ' · ' + r.ms + 'ms' + (r.cache_hit ? ' · cached' : '') + (r.error ? ' · ' + r.error : '') + '\n' + JSON.stringify(r.data, null, 2);
      });
    });
  });


})();
