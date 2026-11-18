/* Catalogue designer: reload the preview with the unsaved form values; switch the sample product card. */
(function () {
  'use strict';
  var form = document.getElementById('designer'), frame = document.getElementById('pv'); if (!form || !frame) return;
  var timer;
  function params() {
    var q = new URLSearchParams(), fd = new FormData(form);
    q.append('d[_catalogue_form]', '1');
    fd.forEach(function (v, k) {
      if (typeof v !== 'string' || k === '_csrf' || k === 'act' || k === '_catalogue_form') return;
      if (k.slice(-2) === '[]') q.append('d[' + k.slice(0, -2) + '][]', v); else q.append('d[' + k + ']', v);
    });
    return q.toString();
  }
  function refresh() { clearTimeout(timer); timer = setTimeout(function () { frame.src = frame.getAttribute('data-src') + '?' + params(); }, 350); }
  form.addEventListener('input', refresh); form.addEventListener('change', refresh);
  var pick = document.getElementById('cardpick'), img = document.getElementById('cardimg');
  if (pick && img) pick.addEventListener('change', function () { img.src = img.getAttribute('data-base') + '/' + encodeURIComponent(pick.value) + '?t=' + Date.now(); });
})();
