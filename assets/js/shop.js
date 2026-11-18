/* Customer catalogue, laid out like the WhatsApp Business catalog: top bar (Cancel / Catalog / cart / menu), brand banner, a list of products with a + button.
   Tapping a product opens its sheet; the cart, order summary (delivery fee included) and Confirm / Cancel are sheets too.
   All text is inserted as text nodes (product names are business-supplied). Totals come from the server (/quote); the order is placed by /order. */
(function () {
  'use strict';
  var boot = JSON.parse(document.getElementById('boot').textContent), B = boot.brand, P = boot.products;
  var root = document.getElementById('app');
  var base = location.origin + location.pathname.replace(/\/+$/, '');
  var key = 'cc_cart:' + location.pathname.split('/').slice(-4, -1).join('/');
  var embedded = window.parent !== window; // inside the dashboard's catalogue modal
  var state = { cat: boot.category && boot.categories.indexOf(boot.category) > -1 ? boot.category : 'All', q: '', search: false, menu: false, cart: [], view: 'shop', focus: '', quote: null, busy: false, err: '',
    form: { name: '', address: '', city: '', payment: '' }, done: null, nonce: Math.random().toString(36).slice(2) + Date.now().toString(36) };
  var bySku = {}; P.forEach(function (p) { bySku[p.sku] = p; });
  var pick = {}; // sku -> {size,color,qty} chosen in the product sheet
  if (boot.focus && bySku[boot.focus]) { state.view = 'product'; state.focus = boot.focus; }
  try { var saved = JSON.parse(localStorage.getItem(key) || '[]'); if (Array.isArray(saved)) state.cart = saved.filter(function (l) { return bySku[l.sku] && l.qty > 0; }); } catch (e) {}
  if (boot.profile) { state.form.name = boot.profile.name || ''; state.form.address = boot.profile.address || ''; state.form.city = boot.profile.city || ''; state.form.payment = boot.profile.payment && boot.payments.indexOf(boot.profile.payment) > -1 ? boot.profile.payment : ''; }
  if (!state.form.payment && boot.payments.length === 1) state.form.payment = boot.payments[0];

  // ---------- helpers
  function h(tag, attrs) {
    var n = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      var v = attrs[k]; if (v == null || v === false) return;
      if (k === 'class') n.className = v; else if (k === 'text') n.textContent = v; else if (k === 'html') n.innerHTML = v; else if (k.indexOf('on') === 0) n.addEventListener(k.slice(2), v); else if (k === 'value') n.value = v; else n.setAttribute(k, v === true ? '' : v);
    });
    for (var i = 2; i < arguments.length; i++) { var c = arguments[i]; if (c == null || c === false) continue; if (Array.isArray(c)) c.forEach(function (x) { if (x) n.appendChild(typeof x === 'string' ? document.createTextNode(x) : x); }); else n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); }
    return n;
  }
  var ICON = { cart: 'M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42a.25.25 0 0 1-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49A1 1 0 0 0 20 4H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z',
    search: 'M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z',
    dots: 'M6 10c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm12 0c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm-6 0c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z' };
  function svg(name, size) { return '<svg viewBox="0 0 24 24" width="' + (size || 24) + '" height="' + (size || 24) + '" fill="currentColor" aria-hidden="true"><path d="' + ICON[name] + '"/></svg>'; }
  function rs(n) { return 'Rs. ' + Math.round(n).toLocaleString('en-US'); }
  function save() { try { localStorage.setItem(key, JSON.stringify(state.cart)); } catch (e) {} }
  function count() { return state.cart.reduce(function (a, l) { return a + l.qty; }, 0); }
  function subtotal() { return state.cart.reduce(function (a, l) { return a + l.qty * bySku[l.sku].price; }, 0); }
  function mix(hex, to, f) { var a = [1, 3, 5].map(function (i) { return parseInt(hex.slice(i, i + 2), 16); }), b = [1, 3, 5].map(function (i) { return parseInt(to.slice(i, i + 2), 16); }); return '#' + a.map(function (v, i) { return ('0' + Math.round(v * (1 - f) + b[i] * f).toString(16)).slice(-2); }).join(''); }
  function lum(hex) { var r = parseInt(hex.slice(1, 3), 16), g = parseInt(hex.slice(3, 5), 16), b = parseInt(hex.slice(5, 7), 16); return (0.299 * r + 0.587 * g + 0.114 * b) / 255; }
  function toast(msg) { var t = document.querySelector('.toast') || document.body.appendChild(h('div', { class: 'toast', role: 'status' })); t.textContent = msg; t.classList.add('on'); clearTimeout(toast.t); toast.t = setTimeout(function () { t.classList.remove('on'); }, 1800); }
  function lineKey(l) { return l.sku + '|' + (l.size || '') + '|' + (l.color || ''); }
  function optText(l) { return [l.size, l.color].filter(Boolean).join(', '); }
  function needsOptions(p) { return p.sizes.length > 0 || p.colors.length > 1; }
  function inCart(sku) { return state.cart.some(function (l) { return l.sku === sku; }); }
  function thumb(cls, p) { return h('div', { class: cls }, h('span', { 'aria-hidden': 'true', text: p.name.charAt(0).toUpperCase() }), p.image ? h('img', { src: p.image, alt: '', loading: 'lazy', onerror: function () { this.remove(); } }) : null); }

  // ---------- cart operations
  function addToCart(p, o) {
    var line = state.cart.filter(function (l) { return lineKey(l) === lineKey({ sku: p.sku, size: o.size, color: o.color }); })[0];
    var have = state.cart.filter(function (l) { return l.sku === p.sku; }).reduce(function (a, l) { return a + l.qty; }, 0);
    if (have + o.qty > p.stock) { toast('Only ' + p.stock + ' in stock'); return false; }
    if (line) line.qty += o.qty; else state.cart.push({ sku: p.sku, size: o.size || '', color: o.color || '', qty: o.qty });
    save(); state.quote = null; toast('Added to your cart'); return true;
  }
  function setQty(l, q) { var others = state.cart.filter(function (x) { return x !== l && x.sku === l.sku; }).reduce(function (a, x) { return a + x.qty; }, 0); if (q > bySku[l.sku].stock - others) { toast('Only ' + bySku[l.sku].stock + ' in stock'); return; } if (q < 1) return removeLine(l); l.qty = q; save(); state.quote = null; render(); if (state.view === 'checkout') requote(); }
  function removeLine(l) { state.cart = state.cart.filter(function (x) { return x !== l; }); save(); state.quote = null; if (!state.cart.length && state.view === 'checkout') state.view = 'cart'; render(); if (state.view === 'checkout') requote(); }

  // ---------- server quote (prices, stock and the delivery fee are the server's numbers)
  var qt;
  function requote() {
    clearTimeout(qt);
    qt = setTimeout(function () {
      if (!state.cart.length) { state.quote = null; render(); return; }
      fetch(base + '/quote', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ cart: state.cart, city: state.form.city, payment: state.form.payment }) }).then(function (r) { return r.json(); })
        .then(function (j) { state.quote = j.ok ? j : { problems: [j.error || 'Could not price your order'], lines: [] }; paintSummary(); }).catch(function () { state.quote = { problems: ['Could not reach the shop. Check your connection.'], lines: [] }; paintSummary(); });
    }, 250);
  }
  function place() {
    if (state.busy) return; state.busy = true; state.err = ''; paintSummary();
    fetch(base + '/order', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ cart: state.cart, name: state.form.name, address: state.form.address, city: state.form.city, payment: state.form.payment, nonce: state.nonce }) })
      .then(function (r) { return r.json(); }).then(function (j) {
        state.busy = false;
        if (j.ok) { state.done = j.order; state.cart = []; save(); state.view = 'done'; render(); } else { state.err = j.error || 'The order could not be placed.'; paintSummary(); }
      }).catch(function () { state.busy = false; state.err = 'Could not reach the shop. Please try again.'; paintSummary(); });
  }

  // ---------- page: top bar, banner, collections, list
  function cancel() {
    if (embedded) { window.parent.postMessage({ cc: 'close-shop' }, location.origin); return; }
    if (history.length > 1) history.back(); else { window.close(); toast('You can close this page now'); }
  }
  function topbar() {
    var cart = h('button', { class: 'ib', type: 'button', 'aria-label': 'Open your cart, ' + count() + ' items', onclick: function () { state.view = 'cart'; state.menu = false; render(); }, html: svg('cart', 25) }); if (count()) cart.appendChild(h('span', { class: 'cnt', text: String(count()) }));
    return h('header', { class: 'bar' }, h('button', { class: 'cancel', type: 'button', text: 'Cancel', onclick: cancel }), h('div', { class: 'ttl', text: 'Catalog' }),
      h('div', { class: 'acts' }, h('button', { class: 'ib', type: 'button', 'aria-label': 'Search', onclick: function () { state.search = !state.search; if (!state.search) state.q = ''; render(); if (state.search) { var i = document.querySelector('.srch input'); if (i) i.focus(); } }, html: svg('search', 23) }), cart,
        h('button', { class: 'ib ring', type: 'button', 'aria-label': 'More', 'aria-expanded': String(state.menu), onclick: function () { state.menu = !state.menu; render(); }, html: svg('dots', 20) })));
  }
  function menu() {
    if (!state.menu) return null;
    var info = [B.name, B.phone, B.address].filter(Boolean).join('\n');
    return h('div', { class: 'menu', role: 'menu' }, info && h('div', { class: 'info', style: 'white-space:pre-line', text: info }),
      count() ? h('button', { type: 'button', text: 'Clear cart', onclick: function () { if (confirm('Remove everything from your cart?')) { state.cart = []; save(); state.menu = false; render(); } } }) : null,
      h('button', { type: 'button', text: 'Close catalog', onclick: cancel }));
  }
  function hero() {
    var dark = mix(B.color, '#07070f', 0.7), darker = mix(B.color, '#000000', 0.82);
    var bg = B.theme === 'brand' ? 'linear-gradient(160deg,' + mix(B.color, '#000000', 0.25) + ',' + mix(B.color, '#000000', 0.6) + ')' : 'linear-gradient(160deg,' + dark + ',' + darker + ')';
    return h('section', { class: 'hero', style: 'background:' + bg },
      B.logo ? h('img', { class: 'wmimg', src: B.logo, alt: '' }) : h('span', { class: 'wm', 'aria-hidden': 'true', text: (B.name || '?').trim().charAt(0).toUpperCase() }),
      B.logo ? h('img', { class: 'lg', src: B.logo, alt: '' }) : null, h('h1', { text: B.name }), B.tagline && h('div', { class: 'tag', text: B.tagline }), B.intro && h('div', { class: 'intro', text: B.intro }));
  }
  function row(p) {
    var out = p.stock <= 0, low = !out && p.stock <= 5 && B.showStock, added = inCart(p.sku);
    var plus = h('button', { class: 'plus' + (added ? ' added' : ''), type: 'button', disabled: out, 'aria-label': (added ? 'In your cart: ' : 'Add ') + p.name, text: added ? '✓' : '+', onclick: function (e) {
      e.stopPropagation(); if (needsOptions(p)) { state.view = 'product'; state.focus = p.sku; render(); return; }
      if (addToCart(p, { size: '', color: p.colors[0] || '', qty: 1 })) render();
    } });
    return h('article', { class: 'row' + (out ? ' gone' : ''), tabindex: '0', role: 'button', 'aria-label': 'View ' + p.name, onclick: function () { state.view = 'product'; state.focus = p.sku; render(); }, onkeydown: function (e) { if (e.key === 'Enter') { state.view = 'product'; state.focus = p.sku; render(); } } },
      thumb('th', p), h('div', { class: 'tx' }, h('div', { class: 'nm', text: p.name }), B.showDesc && p.description && h('div', { class: 'ds', text: p.description }), h('div', { class: 'pr', text: rs(p.price) }),
        B.showSku && h('div', { class: 'sku', text: p.sku }), out ? h('div', { class: 'st out', text: 'Sold out' }) : (low ? h('div', { class: 'st', text: 'Only ' + p.stock + ' left' }) : null)), plus);
  }
  function shopPage() {
    var q = state.q.trim().toLowerCase(), list = P.filter(function (p) { return (state.cat === 'All' || p.category === state.cat) && (!q || (p.name + ' ' + p.category + ' ' + p.sku + ' ' + p.description + ' ' + p.colors.join(' ')).toLowerCase().indexOf(q) > -1); });
    var chips = boot.categories.length > 1 ? h('div', { class: 'chips', role: 'group', 'aria-label': 'Collections' }, ['All'].concat(boot.categories).map(function (c) { return h('button', { class: 'chip', type: 'button', 'aria-pressed': String(state.cat === c), text: c, onclick: function () { state.cat = c; render(); } }); })) : null;
    var s = null; if (state.search) { var inp = h('input', { type: 'search', placeholder: 'Search products', 'aria-label': 'Search products', value: state.q }); inp.addEventListener('input', function () { state.q = inp.value; var pos = inp.selectionStart; render(); var n = document.querySelector('.srch input'); if (n) { n.focus(); try { n.setSelectionRange(pos, pos); } catch (e) {} } }); s = h('div', { class: 'srch' }, inp); }
    var total = subtotal();
    return [topbar(), menu(), boot.preview && h('div', { class: 'preview', text: 'Preview: this is how customers see your catalogue. Ordering is switched off here.' }), hero(), chips, s,
      h('div', { class: 'list' }, list.length ? list.map(row) : h('div', { class: 'empty', text: P.length ? 'No products match your search.' : 'No products to show yet.' })),
      (B.footer || B.address || B.phone) && h('footer', { class: 'ft' }, B.footer && h('div', { text: B.footer }), h('div', { text: [B.name, B.phone].filter(Boolean).join(' · ') })),
      h('div', { class: 'cartbar' + (count() ? ' has' : '') }, h('button', { class: 'btn', type: 'button', onclick: function () { state.view = 'cart'; render(); } }, 'View cart', h('span', { class: 'pill', text: count() + ' item' + (count() === 1 ? '' : 's') }), rs(total)))];
  }

  // ---------- sheets
  function sheet(title, body, footer, onClose) {
    return [h('div', { class: 'veil', onclick: onClose }), h('aside', { class: 'sheet', role: 'dialog', 'aria-modal': 'true', 'aria-label': title }, h('div', { class: 'sh' }, h('h2', { text: title }), h('button', { class: 'x', type: 'button', 'aria-label': 'Close', text: '×', onclick: onClose })), h('div', { class: 'sb', id: 'sbody' }, body), footer && h('div', { class: 'sf', id: 'sfoot' }, footer))];
  }
  function productSheet() {
    var p = bySku[state.focus]; function close() { state.view = 'shop'; render(); }
    var st = pick[p.sku] = pick[p.sku] || { size: '', color: p.colors.length === 1 ? p.colors[0] : '', qty: 1 }, out = p.stock <= 0, err = h('div', { class: 'err', role: 'alert' });
    function sel(label, list, field) { var s = h('select', { 'aria-label': label, onchange: function () { st[field] = s.value; err.textContent = ''; } }, h('option', { value: '', text: label }), list.map(function (v) { return h('option', { value: v, text: v, selected: st[field] === v }); })); return s; }
    var qtyEl = h('span', { text: String(st.qty) });
    var body = [h('div', { class: 'pimg' }, h('span', { 'aria-hidden': 'true', text: p.name.charAt(0).toUpperCase() }), p.image ? h('img', { src: p.image, alt: p.name, onerror: function () { this.remove(); } }) : null), h('div', { class: 'pname', text: p.name }), h('div', { class: 'pprice', text: rs(p.price) }),
      p.description && h('div', { class: 'pdesc', text: p.description }), B.showStock && out ? h('div', { class: 'st out', text: 'Sold out' }) : null,
      (p.sizes.length || p.colors.length) && !out && h('div', { class: 'opts' }, p.sizes.length ? sel('Choose size', p.sizes, 'size') : null, p.colors.length > 1 ? sel('Choose colour', p.colors, 'color') : null), err];
    var foot = out ? [h('button', { class: 'btn', type: 'button', disabled: true, text: 'Sold out' })] : [h('div', { class: 'buy' },
      h('div', { class: 'qty' }, h('button', { type: 'button', 'aria-label': 'Fewer', text: '−', onclick: function () { if (st.qty > 1) { st.qty--; qtyEl.textContent = st.qty; } } }), qtyEl, h('button', { type: 'button', 'aria-label': 'More', text: '+', onclick: function () { if (st.qty < Math.min(99, p.stock)) { st.qty++; qtyEl.textContent = st.qty; } } })),
      h('button', { class: 'btn', type: 'button', text: B.button, onclick: function () {
        if (p.sizes.length && !st.size) { err.textContent = 'Please choose a size.'; return; } if (p.colors.length > 1 && !st.color) { err.textContent = 'Please choose a colour.'; return; }
        if (addToCart(p, { size: st.size, color: st.color || (p.colors.length === 1 ? p.colors[0] : ''), qty: st.qty })) { pick[p.sku] = null; state.view = 'shop'; render(); }
      } }))];
    return sheet(p.name, body, foot, close);
  }
  function lineEl(l) {
    var p = bySku[l.sku];
    return h('div', { class: 'line' }, thumb('lth', p), h('div', { class: 'lbody' }, h('div', { class: 'lname', text: p.name }), optText(l) && h('div', { class: 'muted small', text: optText(l) }),
      h('div', { class: 'lrow' }, h('div', { class: 'qty' }, h('button', { type: 'button', 'aria-label': 'Fewer ' + p.name, text: '−', onclick: function () { setQty(l, l.qty - 1); } }), h('span', { text: String(l.qty) }), h('button', { type: 'button', 'aria-label': 'More ' + p.name, text: '+', onclick: function () { setQty(l, l.qty + 1); } })), h('b', { text: rs(l.qty * p.price) })),
      h('button', { class: 'rm', type: 'button', text: 'Remove', onclick: function () { removeLine(l); } })));
  }
  function cartSheet() {
    function close() { state.view = 'shop'; render(); }
    var body = state.cart.length ? state.cart.map(lineEl) : [h('div', { class: 'empty', text: 'Your cart is empty. Tap + on a product to add it.' })];
    var foot = state.cart.length ? [h('div', { class: 'sum' }, h('div', { class: 'r' }, h('span', { text: 'Subtotal' }), h('b', { text: rs(subtotal()) })), h('div', { class: 'muted small', text: 'Delivery fee is added at the next step, once we know your town.' })),
      h('button', { class: 'btn', type: 'button', text: 'Continue to order summary', onclick: function () { state.view = 'checkout'; state.err = ''; render(); requote(); } }),
      h('div', { class: 'row2' }, h('button', { class: 'btn ghost', type: 'button', text: 'Add more items', onclick: close }), h('button', { class: 'btn danger', type: 'button', text: 'Clear cart', onclick: function () { if (confirm('Remove everything from your cart?')) { state.cart = []; save(); render(); } } }))] : [h('button', { class: 'btn', type: 'button', text: 'Browse products', onclick: close })];
    return sheet('Your cart', body, foot, close);
  }
  function summaryEl() {
    var q = state.quote, box = h('div', { class: 'box sum', id: 'sumbox' });
    if (!q) { box.appendChild(h('div', { class: 'muted', text: 'Calculating…' })); return box; }
    (q.lines || []).forEach(function (l) { box.appendChild(h('div', { class: 'r' }, h('span', { text: l.qty + ' × ' + l.name + (optText(l) ? ' (' + optText(l) + ')' : '') }), h('span', { text: rs(l.line_total) }))); });
    box.appendChild(h('div', { class: 'r', style: 'border-top:1px dashed var(--line);padding-top:8px' }, h('span', { class: 'muted', text: 'Subtotal' }), h('span', { text: rs(q.subtotal || 0) })));
    var cityKnown = state.form.city.trim() !== '';
    box.appendChild(h('div', { class: 'r' }, h('span', { class: 'muted', text: 'Delivery' + (cityKnown ? ' (' + state.form.city.trim() + ')' : '') }), cityKnown ? (q.delivery_fee > 0 ? h('span', { text: rs(q.delivery_fee) }) : h('span', { class: 'free', text: 'FREE' })) : h('span', { class: 'muted small', text: 'Enter your town' })));
    if (cityKnown && q.delivery_fee > 0 && q.free_over > 0) box.appendChild(h('div', { class: 'muted small', text: 'Free delivery on orders over ' + rs(q.free_over) }));
    box.appendChild(h('div', { class: 'r tot' }, h('span', { text: 'Total' }), h('span', { text: rs(q.total || 0) })));
    if (q.eta) box.appendChild(h('div', { class: 'muted small', text: 'Delivery time: ' + q.eta }));
    (q.problems || []).forEach(function (p) { box.appendChild(h('div', { class: 'err', role: 'alert', text: p })); });
    return box;
  }
  function canConfirm() { var f = state.form, q = state.quote; return !boot.preview && !state.busy && q && q.lines && q.lines.length && !(q.problems || []).length && f.name.trim() && f.address.trim().length >= 5 && f.city.trim() && f.payment; }
  function paintSummary() {
    var old = document.getElementById('sumbox'); if (old) old.replaceWith(summaryEl());
    var ce = document.getElementById('cerr'); if (ce) ce.textContent = state.err;
    var cb = document.getElementById('confirmbtn'); if (cb) { cb.disabled = !canConfirm(); cb.textContent = state.busy ? 'Placing your order…' : 'Confirm order'; }
  }
  function checkoutSheet() {
    function back() { state.view = 'cart'; state.err = ''; render(); }
    function field(label, name, a) { var i = h('input', Object.assign({ class: 'in-f', value: state.form[name], autocomplete: a.ac || 'off', required: true }, a.a || {})); i.addEventListener('input', function () { state.form[name] = i.value; if (name === 'city') requote(); paintSummary(); }); return h('label', null, label, i); }
    var addr = h('label', null, 'Full delivery address', (function () { var t = h('textarea', { class: 'in-f', rows: '3', autocomplete: 'street-address', required: true, text: state.form.address }); t.addEventListener('input', function () { state.form.address = t.value; paintSummary(); }); return t; })());
    var pays = h('div', { class: 'pay', role: 'radiogroup', 'aria-label': 'Payment method' }, boot.payments.map(function (m) { var r = h('input', { type: 'radio', name: 'pay', value: m, checked: state.form.payment === m }); r.addEventListener('change', function () { state.form.payment = m; requote(); paintSummary(); }); return h('label', null, r, m); }));
    var body = h('div', { class: 'form' }, boot.preview && h('div', { class: 'note', text: 'Preview mode: you can fill in the form, but ordering is switched off.' }), field('Your name', 'name', { ac: 'name' }), field('Delivery town or city', 'city', { ac: 'address-level2', a: { list: 'towns' } }),
      h('datalist', { id: 'towns' }, boot.towns.map(function (t) { return h('option', { value: t }); })), addr, h('div', null, h('div', { class: 'small muted', style: 'font-weight:600;margin-bottom:6px', text: 'Payment' }), pays),
      h('div', null, h('div', { class: 'small muted', style: 'font-weight:600;margin-bottom:6px', text: 'Order summary' }), summaryEl()), h('div', { class: 'muted small', text: boot.phoneHint ? 'We will confirm on WhatsApp (' + boot.phoneHint + ').' : '' }));
    var foot = [h('div', { class: 'err', id: 'cerr', role: 'alert', text: state.err }), h('div', { class: 'row2' }, h('button', { class: 'btn ghost', type: 'button', text: 'Cancel', onclick: back }), h('button', { class: 'btn', id: 'confirmbtn', type: 'button', text: 'Confirm order', disabled: !canConfirm(), onclick: place }))];
    return sheet('Confirm your order', body, foot, back);
  }
  function doneSheet() {
    var o = state.done; function close() { state.view = 'shop'; state.done = null; state.nonce = Math.random().toString(36).slice(2) + Date.now().toString(36); render(); }
    var body = h('div', { class: 'done' }, h('div', { class: 'tick', 'aria-hidden': 'true', text: '✓' }), h('h2', { text: 'Order placed!' }), h('p', { class: 'muted', text: 'Your order number is' }), h('div', { style: 'font-size:26px;font-weight:800;color:var(--brand)', text: o.order_no }),
      h('div', { class: 'box sum', style: 'text-align:left;margin-top:16px' }, (o.items || []).map(function (i) { return h('div', { class: 'r' }, h('span', { text: i.qty + ' × ' + i.name + ([i.size, i.color].filter(Boolean).length ? ' (' + [i.size, i.color].filter(Boolean).join(', ') + ')' : '') }), h('span', { text: rs(i.qty * i.unit_price) })); }),
        h('div', { class: 'r' }, h('span', { class: 'muted', text: 'Delivery' }), o.delivery_fee > 0 ? h('span', { text: rs(o.delivery_fee) }) : h('span', { class: 'free', text: 'FREE' })), h('div', { class: 'r tot' }, h('span', { text: 'Total' }), h('span', { text: rs(o.total) })), h('div', { class: 'muted small', text: o.payment + (o.eta ? ' · ' + o.eta : '') })),
      h('p', { class: 'muted small', text: 'We have sent the confirmation to your WhatsApp chat.' }), h('a', { href: o.invoice_url, target: '_blank', rel: 'noopener', text: 'View invoice' }));
    return sheet('Thank you', body, [h('button', { class: 'btn', type: 'button', text: 'Continue shopping', onclick: close })], close);
  }

  function applyTheme() {
    var r = document.documentElement; r.style.setProperty('--brand', B.color); r.style.setProperty('--on-brand', lum(B.color) > 0.62 ? '#111b21' : '#ffffff');
    r.setAttribute('data-theme', B.theme); r.setAttribute('data-corner', B.corner); r.setAttribute('data-layout', B.layout === 'grid' ? 'grid' : 'list');
  }
  function render() {
    var scroll = window.scrollY, sb = (document.getElementById('sbody') || {}).scrollTop || 0;
    var kids = shopPage(); var sh = state.view === 'product' ? productSheet() : state.view === 'cart' ? cartSheet() : state.view === 'checkout' ? checkoutSheet() : state.view === 'done' ? doneSheet() : null;
    var app = h('div', { class: 'app' }, kids); root.replaceChildren(app); if (sh) sh.forEach(function (n) { root.appendChild(n); });
    window.scrollTo(0, scroll); var nb = document.getElementById('sbody'); if (nb) nb.scrollTop = sb;
    document.body.style.overflow = sh ? 'hidden' : '';
  }
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (state.menu) { state.menu = false; render(); return; }
    if (state.view === 'checkout') { state.view = 'cart'; render(); } else if (state.view !== 'shop') { state.view = 'shop'; render(); } else if (embedded) window.parent.postMessage({ cc: 'close-shop' }, location.origin);
  });
  document.addEventListener('click', function (e) { if (state.menu && !e.target.closest('.menu') && !e.target.closest('.ib.ring')) { state.menu = false; render(); } });
  applyTheme(); render();
})();
