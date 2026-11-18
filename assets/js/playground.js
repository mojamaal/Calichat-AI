/* Playground: a WhatsApp-style chat with the agent. The tester is the customer (green bubbles, right); the AI is the business (white, left).
   Messages are inserted as escaped text; only WhatsApp formatting (*bold*, _italic_, ~strike~) and links are turned into markup. */
(function () {
  'use strict';
  var root = document.getElementById('pg'); if (!root) return;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var base = ($('meta[name=base]') || {}).content || '', csrf = ($('meta[name=csrf]') || {}).content || '';
  var msgs = $('#pg-msgs'), form = $('#pg-form'), input = $('input[name=text]', form), bar = form, status = $('#pg-status'), phone = $('#pg-phone'), writes = $('#pg-writes');
  var busy = false, lastSide = null, welcome = status.textContent;

  function api(body) {
    return fetch(base + '/api/playground/send', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF': csrf, 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return { error: 'Bad response (' + r.status + ')' }; }); });
  }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
  function fmt(t) { // WhatsApp formatting on already-escaped text
    var h = esc(t);
    h = h.replace(/(https?:\/\/[^\s<]+[^\s<.,;:!?)\]'"])/g, function (u) { return '<a href="' + u + '" target="_blank" rel="noopener noreferrer">' + u + '</a>'; });
    h = h.replace(/(^|[\s(])\*([^*\n]+)\*(?=[\s).,!?:;]|$)/g, '$1<b>$2</b>').replace(/(^|[\s(])_([^_\n]+)_(?=[\s).,!?:;]|$)/g, '$1<i>$2</i>').replace(/(^|[\s(])~([^~\n]+)~(?=[\s).,!?:;]|$)/g, '$1<s>$2</s>');
    return h;
  }
  function clock() { return new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); }
  var SENT = '<svg viewBox="0 0 16 11" class="tk" aria-label="Sent"><path d="M5 8.6 1.9 5.5M5 8.6 13 1.5" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  var DELIVERED = '<svg viewBox="0 0 16 11" class="tk" aria-label="Delivered"><path d="M1 5.8l3.2 3.2L10.5 1.5M6 8.6l1 .9L14.5 1.5" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  function toBottom() { msgs.scrollTop = msgs.scrollHeight; }
  function setStatus(t) { status.textContent = t || welcome; }

  function intro() {
    msgs.innerHTML = '<div class="wa-date">Today</div><div class="wa-note">This is a test chat. Replies come from your AI agent, and nothing here counts toward quotas or dashboards.</div>';
    lastSide = null;
  }
  function bubble(side, html, extra) {
    var el = document.createElement('div'); el.className = 'wa-b ' + side + (lastSide !== side ? ' first' : '') + (extra && extra.cls ? ' ' + extra.cls : ''); lastSide = side;
    el.innerHTML = html + '<span class="wa-m">' + clock() + (side === 'me' ? ' ' + SENT : '') + '</span>';
    msgs.appendChild(el); toBottom(); return el;
  }
  function sys(text, err) { var e = document.createElement('div'); e.className = 'wa-sys' + (err ? ' err' : ''); e.textContent = text; msgs.appendChild(e); lastSide = null; toBottom(); }
  function markRead(el) { var t = el && el.querySelector('.tk'); if (t) { t.outerHTML = DELIVERED.replace('class="tk"', 'class="tk read"'); } }
  function typing() { var el = document.createElement('div'); el.className = 'wa-b them typing' + (lastSide !== 'them' ? ' first' : ''); lastSide = 'them'; el.innerHTML = '<span class="wa-dot"></span><span class="wa-dot"></span><span class="wa-dot"></span>'; msgs.appendChild(el); toBottom(); return el; }
  function tools(list) {
    if (!list || !list.length) return '';
    return '<div class="wa-tools">' + list.map(function (x) { return '<span class="wa-tool' + (x.ok ? '' : ' bad') + '">' + (x.ok ? '⚙' : '⚠') + ' ' + esc(x.name) + (x.ms ? ' · ' + x.ms + 'ms' : '') + (x.cache ? ' · cached' : '') + '</span>'; }).join('') + '</div>';
  }
  var REPLY = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M10 9V5l-7 7 7 7v-4.1c5 0 8.5 1.6 11 5.1-1-5-4-10-11-11z"/></svg>';
  function buttons(list) {
    var wrap = document.createElement('div'); wrap.className = 'wa-btns';
    list.forEach(function (b) {
      var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'wa-btn'; btn.innerHTML = REPLY; btn.appendChild(document.createTextNode(b.title));
      btn.addEventListener('click', function () { if (busy) return; Array.prototype.forEach.call(wrap.querySelectorAll('button'), function (x) { x.disabled = true; }); send(b.title, b.id); });
      wrap.appendChild(btn);
    });
    msgs.appendChild(wrap); toBottom();
  }

  function send(text, replyId) {
    if (busy) return; busy = true;
    var mine = bubble('me', fmt(text)); input.value = ''; bar.classList.remove('has-text');
    var tp; var delay = setTimeout(function () { setStatus('typing…'); tp = typing(); }, 450);
    var body = { text: text, phone: phone.value, writes: writes.checked }; if (replyId) body.reply_id = replyId;
    api(body).then(function (r) {
      clearTimeout(delay); if (tp) tp.remove(); setStatus(''); busy = false; markRead(mine);
      if (r.error && !r.reply && !(r.rich && r.rich.length)) { sys(r.skipped ? 'Not answered by AI: ' + String(r.skipped).replace('_', ' ') : 'Error: ' + r.error, true); return; }
      if (r.rich && r.rich.length && window.CCRich) { var rm = document.createElement('div'); rm.className = 'richmsg'; rm.innerHTML = window.CCRich.html(r.rich); msgs.appendChild(rm); lastSide = 'them'; toBottom(); }
      if (r.reply) bubble('them', fmt(r.reply) + tools(r.tools)); else if (r.skipped) sys('AI paused: ' + String(r.skipped).replace('_', ' '));
      if (r.buttons && r.buttons.length) buttons(r.buttons);
      if (r.handoff) sys('Handed off to a human: ' + r.handoff);
      if (r.error && r.reply) sys('Note: ' + r.error, true);
    }).catch(function () { clearTimeout(delay); if (tp) tp.remove(); setStatus(''); busy = false; sys('Network error. Check your connection and try again.', true); });
  }

  form.addEventListener('submit', function (e) { e.preventDefault(); var t = input.value.trim(); if (!t) { sys('Voice notes are not available in the Playground.'); return; } send(t); });
  input.addEventListener('input', function () { bar.classList.toggle('has-text', input.value.trim() !== ''); });
  Array.prototype.forEach.call(document.querySelectorAll('[data-try]'), function (b) { b.addEventListener('click', function () { input.value = b.getAttribute('data-try'); bar.classList.add('has-text'); input.focus(); }); });

  function reset() { return api({ reset: 1, phone: phone.value }).then(function () { intro(); sys('Chat and memory cleared'); }); }
  $('#pg-reset').addEventListener('click', reset);
  var menu = $('#pg-menu'), box = $('#pg-menubox');
  menu.addEventListener('click', function (e) { e.stopPropagation(); box.hidden = !box.hidden; menu.setAttribute('aria-expanded', String(!box.hidden)); });
  $('#pg-reset2').addEventListener('click', function () { box.hidden = true; reset(); });
  document.addEventListener('click', function (e) { if (!box.hidden && !e.target.closest('.wa-menuwrap')) { box.hidden = true; menu.setAttribute('aria-expanded', 'false'); } });
  intro();
})();
