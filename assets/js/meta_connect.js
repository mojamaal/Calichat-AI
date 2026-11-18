/* WhatsApp Embedded Signup (Meta JS SDK). Config comes from data-* on #wa-signup; no inline scripts (strict CSP). */
(function () {
  'use strict';
  var btn = document.getElementById('wa-signup'); if (!btn) return;
  var out = document.getElementById('wa-status');
  var base = (document.querySelector('meta[name=base]') || {}).content || '';
  var csrf = (document.querySelector('meta[name=csrf]') || {}).content || '';
  var session = null;
  function say(t, bad) { out.textContent = t; out.className = 'small ' + (bad ? 'down' : 'muted'); }

  // Meta posts the chosen WhatsApp Business Account and phone number from the pop-up
  window.addEventListener('message', function (e) {
    if (e.origin !== 'https://www.facebook.com' && e.origin !== 'https://web.facebook.com') return;
    var d; try { d = typeof e.data === 'string' ? JSON.parse(e.data) : e.data; } catch (x) { return; }
    if (!d || d.type !== 'WA_EMBEDDED_SIGNUP') return;
    if (d.event === 'FINISH') session = d.data; else if (d.event === 'CANCEL') say('Signup was cancelled.', true);
  });

  function loadSdk(cb) {
    if (window.FB) return cb();
    window.fbAsyncInit = function () { FB.init({ appId: btn.dataset.appId, autoLogAppEvents: true, xfbml: false, version: btn.dataset.version }); cb(); };
    var s = document.createElement('script'); s.src = 'https://connect.facebook.net/en_US/sdk.js'; s.async = true; s.defer = true;
    s.onerror = function () { say('Could not load Facebook. Check your connection or ad blocker.', true); };
    document.head.appendChild(s);
  }
  function finish(code) {
    var tries = 0;
    (function wait() {
      if (session || tries++ > 24) {
        say('Connecting your number…');
        fetch(base + '/api/meta/wa_signup', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf },
          body: JSON.stringify({ code: code, phone_number_id: session && session.phone_number_id, waba_id: session && session.waba_id }) })
          .then(function (r) { return r.json(); })
          .then(function (j) { if (j.ok) { say('Connected: ' + j.name + '. Reloading…'); setTimeout(function () { location.reload(); }, 900); } else say(j.error || 'Something went wrong.', true); })
          .catch(function () { say('Network error. Please try again.', true); });
      } else setTimeout(wait, 250);
    })();
  }
  btn.addEventListener('click', function () {
    say('Opening Facebook…');
    loadSdk(function () {
      FB.login(function (r) {
        if (r.authResponse && r.authResponse.code) finish(r.authResponse.code); else say('Signup was cancelled.', true);
      }, { config_id: btn.dataset.configId, response_type: 'code', override_default_response_type: true, extras: { setup: {}, featureType: '', sessionInfoVersion: '3' } });
    });
  });
})();
