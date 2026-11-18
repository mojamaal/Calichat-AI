/* Inbox, WhatsApp Web style: chat list on the left, the conversation on the right.
   Behaviour is unchanged (filters, search, labels, assign, pin, take over / hand back to the AI, internal notes, saved replies, live updates);
   only the presentation is new. All customer-supplied text goes through esc(). */
(function () {
  'use strict';
  var inbox = document.getElementById('inbox'); if (!inbox) return;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var base = ($('meta[name=base]') || {}).content || '', csrf = ($('meta[name=csrf]') || {}).content || '';
  var uid = +(($('meta[name=uid]') || {}).content || 0);
  function api(path, body) {
    var o = { method: body ? 'POST' : 'GET', headers: { 'X-CSRF': csrf, 'Content-Type': 'application/json' }, credentials: 'same-origin' }; if (body) o.body = JSON.stringify(body);
    return fetch(base + '/api/' + path, o).then(function (r) { return r.json().catch(function () { return { error: 'Bad response (' + r.status + ')' }; }); });
  }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
  function richHtml(list) { return window.CCRich ? window.CCRich.html(list) : ''; }

  var list = $('#convs'), msgs = $('#msgs'), head = $('#thread-head'), comp = $('#composer'), sum = $('#summary'), chat = $('#wi-chat'), splash = $('#wi-splash'), input = $('input[name=text]', comp), labelsBar = $('#thread-labels');
  var cur = null, lastId = 0, firstId = 0, filter = inbox.getAttribute('data-filter') || 'all', q = '', mine = false, labelF = 0, allLabels = null, limit = 40, seen = null, humanSeen = null, canned = null;
  var mode = 'reply', today = '', yesterday = '', lastDate = null, lastSide = null;
  var COLORS = ['#6a7cd6', '#e07a5f', '#3d9970', '#b5651d', '#8e6bbf', '#2a9d8f', '#d1495b', '#4a7fb5', '#c77dff', '#5c8a3c'];
  function color(s) { var h = 0; s = String(s || '?'); for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0; return COLORS[h % COLORS.length]; }
  function initial(s) { return esc(String(s || '?').trim().charAt(0).toUpperCase() || '?'); }
  var TICKS = '<svg viewBox="0 0 16 11" aria-label="Delivered"><path d="M1 5.8l3.2 3.2L10.5 1.5M6 8.6l1 .9L14.5 1.5" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  var BACK = '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20z"/></svg>';
  var DOTS = '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true"><path d="M12 7a2 2 0 1 0 0-4 2 2 0 0 0 0 4zm0 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm0 6a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/></svg>';
  var PIN = '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M16 9V4h1c.55 0 1-.45 1-1s-.45-1-1-1H7c-.55 0-1 .45-1 1s.45 1 1 1h1v5c0 1.66-1.34 3-3 3v2h5.97v7l1 1 1-1v-7H19v-2c-1.66 0-3-1.34-3-3z"/></svg>';

  // ---------- chat list
  function slaTag(s) { if (s == null) return ''; var m = Math.floor(s / 60), c = m < 5 ? 'ok' : (m < 15 ? 'warn' : 'bad'); return '<span class="wi-tag sla ' + c + '">waiting ' + (m < 60 ? m + 'm' : Math.floor(m / 60) + 'h') + '</span>'; }
  function convHtml(c) {
    var st = c.status === 'ai' ? 'AI' : (c.status === 'human' ? 'Human' : 'Closed');
    var tags = (c.labels || []).map(function (l) { return '<span class="wi-tag" style="background:' + esc(l.color) + '22;color:' + esc(l.color) + '">' + esc(l.name) + '</span>'; }).join('') + slaTag(c.waiting_s) + (c.assignee ? '<span class="wi-tag">' + esc(c.assignee) + '</span>' : '') + '<span class="wi-tag">' + esc(c.channel_type) + '</span>';
    return '<button type="button" class="wi-conv' + (cur === c.id ? ' on' : '') + (c.unread > 0 ? ' unread' : '') + '" data-id="' + c.id + '"><span class="wi-av" style="background:' + color(c.title) + '">' + initial(c.title) + '</span><span class="wi-cm">' +
      '<span class="wi-r1"><span class="wi-nm">' + esc(c.title) + '</span><time class="wi-tm">' + esc(c.time_label || c.ago || '') + '</time></span>' +
      '<span class="wi-r2"><span class="wi-pv"><span class="wi-st ' + esc(c.status) + '">' + st + '</span>' + esc(c.last_preview || '') + '</span><span class="wi-bd">' + (c.pinned ? PIN : '') + (c.unread > 0 ? '<span class="wi-u">' + c.unread + '</span>' : '') + '</span></span>' +
      '<span class="wi-r3">' + tags + '</span></span></button>';
  }
  function loadList() {
    api('inbox/list?status=' + filter + '&limit=' + limit + (mine ? '&mine=1' : '') + (labelF ? '&label=' + labelF : '') + (q ? '&q=' + encodeURIComponent(q) : '')).then(function (r) {
      var rows = r.conversations || [];
      list.innerHTML = rows.map(convHtml).join('') || '<div class="wi-none">No conversations match.</div>';
      if (rows.length >= limit) list.insertAdjacentHTML('beforeend', '<button type="button" class="wi-more" id="more">Show more</button>');
    });
  }

  // ---------- messages
  function dateLabel(d) { return d === today ? 'Today' : (d === yesterday ? 'Yesterday' : d); }
  function msgHtml(m) {
    var t = String(m.time || ''), i = t.lastIndexOf(', '), date = i > -1 ? t.slice(0, i) : '', clock = i > -1 ? t.slice(i + 2) : t, out = '';
    if (date && date !== lastDate) { out += '<div class="wi-date">' + esc(dateLabel(date)) + '</div>'; lastDate = date; lastSide = null; }
    var meta = m.meta || {}, who = m.sender === 'ai' || m.sender === 'system' ? 'AI' : (meta.user || 'Team');
    if (meta.rich) { lastSide = 'me'; return out + '<div class="wi-rich" data-mid="' + m.id + '">' + richHtml([meta.rich]) + '<span class="meta">' + esc(who) + ' ' + esc(clock) + '</span></div>'; }
    if (m.sender === 'system') { lastSide = null; return out + '<div class="wi-sys">' + esc(m.content) + '</div>'; }
    if (m.sender === 'note') { lastSide = null; return out + '<div class="wi-note" data-mid="' + m.id + '"><span class="wi-snd">🔒 Private note' + (meta.user ? ' · ' + esc(meta.user) : '') + '</span>' + esc(m.content) + '<span class="wi-m">' + esc(clock) + '</span></div>'; }
    var me = m.sender !== 'customer', side = me ? 'me' : 'them', first = lastSide !== side; lastSide = side;
    var tools = (meta.tools || []).map(function (x) { return '<span class="wi-tool">⚙ ' + esc(x) + '</span>'; }).join('');
    var tick = me ? (meta.delivery_error ? '<span class="err" title="' + esc(meta.delivery_error) + '">⚠ not delivered</span>' : TICKS) : '';
    return out + '<div class="wi-b ' + side + (first ? ' first' : '') + '" data-mid="' + m.id + '">' + (me ? '<span class="wi-snd ' + (m.sender === 'ai' ? 'ai' : 'hu') + '">' + esc(who) + '</span>' : '') + esc(m.content) + (tools ? '<div class="wi-tools">' + tools + '</div>' : '') + '<span class="wi-m">' + esc(clock) + (tick ? ' ' + tick : '') + '</span></div>';
  }
  function toBottom() { msgs.scrollTop = msgs.scrollHeight; }

  // ---------- header and labels
  function subtitle(c) {
    var s = c.status === 'ai' ? 'AI is replying' : (c.status === 'human' ? 'Human' + (c.assignee ? ' · ' + c.assignee : ' · unassigned') : 'Closed');
    return s + ' · ' + (c.channel || '') + (c.consent === 'opted_out' ? ' · opted out of promotions' : '') + (c.handoff_reason ? ' · ' + c.handoff_reason : '');
  }
  function renderHead(c) {
    head.innerHTML = '<button type="button" class="wi-back" id="wi-back" aria-label="Back to chats">' + BACK + '</button><span class="wi-av sm" style="background:' + color(c.title) + '">' + initial(c.title) + '</span>' +
      '<span class="wi-who"><b>' + esc(c.title) + '</b><small>' + esc(subtitle(c)) + '</small></span><span class="wi-acts">' +
      (c.status !== 'ai' ? '<button type="button" class="wi-btn pri" data-st="ai" title="Let the AI agent answer this customer again">Hand back to AI</button>' : '<button type="button" class="wi-btn" data-st="human" title="Pause the AI and reply yourself">Take over</button>') +
      '<button type="button" class="wi-ib" data-pin="' + (c.pinned ? 0 : 1) + '" title="' + (c.pinned ? 'Unpin chat' : 'Pin chat') + '" aria-label="' + (c.pinned ? 'Unpin chat' : 'Pin chat') + '" style="' + (c.pinned ? 'color:#008069' : '') + '">' + PIN + '</button>' +
      '<span class="wi-menuwrap"><button type="button" class="wi-ib" data-menu="1" aria-label="Chat menu" aria-haspopup="true">' + DOTS + '</button><div class="wi-menu" id="wi-chatmenu" hidden>' +
      (c.assigned_to === uid ? '<button type="button" data-assign="0">Unassign from me</button>' : '<button type="button" data-assign="' + uid + '">Assign to me</button>') + '<button type="button" data-unread="1">Mark as unread</button>' +
      (c.status !== 'closed' ? '<button type="button" data-st="closed">Close chat</button>' : '') + '</div></span></span>';
    sum.hidden = !c.summary; sum.textContent = c.summary ? 'Earlier in this chat: ' + c.summary : '';
    renderLabels(c);
  }
  function renderLabels(c) {
    function draw() {
      var have = {}; (c.labels || []).forEach(function (l) { have[l.id] = 1; });
      labelsBar.innerHTML = '<span>Labels</span>' + (c.labels || []).map(function (l) { return '<span class="wi-lbl" style="background:' + esc(l.color) + '22;color:' + esc(l.color) + '">' + esc(l.name) + '<button type="button" data-unlabel="' + l.id + '" aria-label="Remove label ' + esc(l.name) + '">×</button></span>'; }).join('') +
        '<select id="addlabel" aria-label="Add a label"><option value="">+ Add label</option>' + (allLabels || []).filter(function (l) { return !have[l.id]; }).map(function (l) { return '<option value="' + l.id + '">' + esc(l.name) + '</option>'; }).join('') + '<option value="new">New label…</option></select>';
    }
    if (allLabels) draw(); else api('labels/list').then(function (r) { allLabels = r.labels || []; draw(); });
    labelsBar.onclick = function (e) { var b = e.target.closest('[data-unlabel]'); if (!b) return; api('inbox/label', { id: cur, label_id: +b.getAttribute('data-unlabel'), on: 0 }).then(function () { open(cur, true); loadList(); }); };
    labelsBar.onchange = function (e) {
      if (e.target.id !== 'addlabel' || !e.target.value) return;
      if (e.target.value === 'new') { var n = prompt('Name for the new label'); e.target.value = ''; if (!n) return; api('labels/save', { name: n }).then(function (r) { if (r.error) { alert(r.error); return; } allLabels = null; api('inbox/label', { id: cur, label_id: r.id, on: 1 }).then(function () { open(cur, true); loadList(); }); }); return; }
      api('inbox/label', { id: cur, label_id: +e.target.value, on: 1 }).then(function () { open(cur, true); loadList(); });
    };
  }

  // ---------- open a conversation
  function open(id, reset) {
    if (reset) { cur = id; lastId = 0; firstId = 0; lastDate = null; lastSide = null; msgs.innerHTML = ''; }
    api('inbox/thread?id=' + id + (reset ? '' : '&after=' + lastId)).then(function (r) {
      if (!r.conversation || cur !== id) return;
      today = r.today || today; yesterday = r.yesterday || yesterday;
      splash.hidden = true; chat.hidden = false; inbox.classList.add('chat-open'); renderHead(r.conversation);
      var stick = msgs.scrollTop + msgs.clientHeight >= msgs.scrollHeight - 80 || reset;
      if (reset && r.has_more) msgs.insertAdjacentHTML('afterbegin', '<button type="button" class="wi-older" id="older">Load earlier messages</button>');
      (r.messages || []).forEach(function (m) { msgs.insertAdjacentHTML('beforeend', msgHtml(m)); lastId = Math.max(lastId, m.id); if (!firstId || m.id < firstId) firstId = m.id; });
      if (stick) toBottom();
      $$('.wi-conv', list).forEach(function (x) { x.classList.toggle('on', +x.getAttribute('data-id') === cur); });
    });
  }
  list.addEventListener('click', function (e) {
    if (e.target.id === 'more') { limit += 40; loadList(); return; }
    var c = e.target.closest('.wi-conv'); if (!c) return; open(+c.getAttribute('data-id'), true);
  });
  msgs.addEventListener('click', function (e) {
    if (e.target.id !== 'older') return;
    var btn = e.target; api('inbox/thread?id=' + cur + '&before=' + firstId).then(function (r) {
      var keepD = lastDate, keepS = lastSide; lastDate = null; lastSide = null; var html = (r.messages || []).map(msgHtml).join(''); lastDate = keepD; lastSide = keepS; btn.insertAdjacentHTML('afterend', html);
      (r.messages || []).forEach(function (m) { if (!firstId || m.id < firstId) firstId = m.id; }); if (!r.has_more) btn.remove();
    });
  });
  head.addEventListener('click', function (e) {
    if (e.target.closest('#wi-back')) { inbox.classList.remove('chat-open'); return; }
    var mn = e.target.closest('[data-menu]'); if (mn) { var box = $('#wi-chatmenu'); box.hidden = !box.hidden; e.stopPropagation(); return; }
    var pn = e.target.closest('[data-pin]'), un = e.target.closest('[data-unread]'), b = e.target.closest('[data-st]'), a = e.target.closest('[data-assign]');
    if (pn) api('inbox/pin', { id: cur, pinned: +pn.getAttribute('data-pin') }).then(function (r) { if (r.error) alert(r.error); open(cur, true); loadList(); });
    if (un) api('inbox/unread', { id: cur }).then(function () { var bx = $('#wi-chatmenu'); if (bx) bx.hidden = true; loadList(); });
    if (b) {
      var st = b.getAttribute('data-st'), payload = { id: cur, status: st };
      if (st === 'ai') { // hand the chat back: the AI answers any message still waiting, and can use a short note from the team
        var note = prompt('Hand this chat back to the AI agent.\n\nOptional note for the AI (for example: "Refund approved, tell the customer it takes 3 days"). Leave empty for none.', '');
        if (note === null) return; payload.note = note;
      }
      api('inbox/status', payload).then(function (r) { if (r && r.error) { alert(r.error); return; } open(cur, true); loadList(); });
    }
    if (a) api('inbox/assign', { id: cur, user_id: +a.getAttribute('data-assign') }).then(function () { open(cur, true); loadList(); });
  });
  document.addEventListener('click', function (e) { $$('.wi-menu').forEach(function (m) { if (!m.hidden && !e.target.closest('.wi-menuwrap')) m.hidden = true; }); var sm = $('#wi-sm'); if (sm) sm.setAttribute('aria-expanded', String(!$('#wi-smbox').hidden)); if (!e.target.closest('#qpop') && !e.target.closest('#qr')) $('#qpop').hidden = true; });
  $('#wi-sm').addEventListener('click', function (e) { e.stopPropagation(); var b = $('#wi-smbox'); b.hidden = !b.hidden; this.setAttribute('aria-expanded', String(!b.hidden)); });

  // ---------- filters and search
  $$('.wi-pills button[data-f]', inbox).forEach(function (b) { b.addEventListener('click', function () { filter = b.getAttribute('data-f'); limit = 40; $$('.wi-pills button[data-f]', inbox).forEach(function (x) { x.classList.toggle('on', x === b); }); loadList(); }); });
  var st; $('#q', inbox).addEventListener('input', function (e) { clearTimeout(st); st = setTimeout(function () { q = e.target.value.trim(); limit = 40; loadList(); }, 300); });
  $('#mine', inbox).addEventListener('change', function (e) { mine = e.target.checked; loadList(); });
  $('#labelf', inbox).addEventListener('change', function (e) { labelF = +e.target.value || 0; limit = 40; loadList(); });

  // ---------- composer: reply / private note, quick replies
  var qpop = $('#qpop');
  function setMode(m) {
    mode = m; comp.classList.toggle('note', m === 'note'); $$('.wi-mode button', comp).forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-mode') === m); });
    input.placeholder = m === 'note' ? 'Add a private note (the customer never sees it)' : 'Type a message (the AI pauses while you reply)'; input.focus();
  }
  $$('.wi-mode button', comp).forEach(function (b) { b.addEventListener('click', function () { setMode(b.getAttribute('data-mode')); }); });
  function showQuick(filterText) {
    function draw() {
      var f = (filterText || '').toLowerCase(), rows = (canned || []).filter(function (c) { return !f || (c.title + ' ' + c.body).toLowerCase().indexOf(f) > -1; });
      qpop.innerHTML = rows.length ? rows.map(function (c) { var i = canned.indexOf(c); return '<button type="button" data-q="' + i + '"><b>' + esc(c.title) + '</b><span>' + esc(c.body) + '</span></button>'; }).join('') : '<div class="wi-none">No saved replies' + (f ? ' match' : ' yet') + '.</div>';
      qpop.hidden = false;
    }
    if (canned) draw(); else api('canned/list').then(function (r) { canned = r.replies || []; draw(); });
  }
  $('#qr').addEventListener('click', function (e) { e.stopPropagation(); if (!qpop.hidden) { qpop.hidden = true; return; } showQuick(''); });
  qpop.addEventListener('click', function (e) { var b = e.target.closest('[data-q]'); if (!b || !canned) return; input.value = canned[+b.getAttribute('data-q')].body; comp.classList.add('has-text'); qpop.hidden = true; input.focus(); });
  input.addEventListener('input', function () { comp.classList.toggle('has-text', input.value.trim() !== ''); if (input.value.charAt(0) === '/') showQuick(input.value.slice(1)); else if (!qpop.hidden) qpop.hidden = true; });
  input.addEventListener('keydown', function (e) { if (e.key === 'Escape') qpop.hidden = true; });
  comp.addEventListener('submit', function (e) {
    e.preventDefault(); var t = input.value.trim(); if (!t || !cur) return; input.value = ''; comp.classList.remove('has-text'); qpop.hidden = true;
    api(mode === 'note' ? 'inbox/note' : 'inbox/reply', { id: cur, text: t }).then(function (r) { if (r.error) alert(r.error); open(cur, false); loadList(); });
  });

  // ---------- live updates and desktop alerts (only for new customer messages / hand-offs, never on first load)
  function notify(txt) {
    try { var a = new (window.AudioContext || window.webkitAudioContext)(), o = a.createOscillator(), g = a.createGain(); o.connect(g); g.connect(a.destination); o.frequency.value = 880; g.gain.value = .05; o.start(); o.stop(a.currentTime + .12); } catch (e) {}
    if ('Notification' in window && Notification.permission === 'granted' && document.hidden) new Notification('CaliChat', { body: txt });
  }
  $('#notif').addEventListener('click', function () { if ('Notification' in window) Notification.requestPermission(); this.textContent = 'Desktop alerts on'; $('#wi-smbox').hidden = true; });
  function poll() {
    api('inbox/poll').then(function (r) {
      if (r.latest == null) return;
      if (seen !== null && r.latest > seen) { loadList(); if (cur) open(cur, false); notify(r.human > humanSeen ? 'A conversation needs a human' : 'New customer message'); }
      seen = r.latest; humanSeen = r.human;
    });
    setTimeout(poll, document.hidden ? 20000 : 4000);
  }
  loadList(); poll();
  var pre = new URLSearchParams(location.search).get('c'); if (pre) open(+pre, true);
  setInterval(function () { if (!document.hidden && $$('.sla', list).length) loadList(); }, 60000);
})();
