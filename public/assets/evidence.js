// SAQF evidence upload: several files at once. For every file chosen, SAQF suggests what it is and which
// assessment it belongs to FROM ITS FILE NAME (only the names are sent, never the contents), and the person
// corrects anything that is wrong before filing. Without JavaScript the form still works: the server makes the
// same suggestion. No inline script (strict CSP): the page supplies a row <template> and its wording in the
// page itself, so the same translation that covers the rest of the page covers this too.
(function () {
  'use strict';
  var input = document.getElementById('ev-files');
  var box = document.getElementById('ev-rows');
  var tpl = document.getElementById('ev-tpl');
  if (!input || !box || !tpl || !tpl.content) return;
  var csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';
  var MAX = parseInt(box.dataset.max || '12', 10);

  function text(key) { var e = document.querySelector('#ev-text [data-k="' + key + '"]'); return e ? e.textContent : ''; }
  function note(cls, msg, role) {
    var n = document.createElement('div');
    n.className = cls;
    n.textContent = msg;
    if (role) n.setAttribute('role', role);
    return n;
  }
  function size(b) { return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }

  function render(files, sugg) {
    box.textContent = '';
    if (files.length > MAX) {
      box.appendChild(note('alert alert-error', text('toomany'), 'alert'));
      return;
    }
    box.appendChild(note('tiny muted', text('hint')));
    files.forEach(function (f, i) {
      var s = sugg[i] || { kind: 'other', assessment: null, title: f.name.replace(/\.[^.]+$/, ''), sure: false };
      var row = tpl.content.firstElementChild.cloneNode(true);
      row.querySelector('.ev-name').textContent = f.name + ' · ' + size(f.size);
      var kind = row.querySelector('.ev-kind');
      var asm = row.querySelector('.ev-asm');
      var title = row.querySelector('.ev-title');
      kind.name = 'item_kind[' + i + ']';
      asm.name = 'item_assessment[' + i + ']';
      title.name = 'item_title[' + i + ']';
      kind.value = s.kind;
      asm.value = s.assessment == null ? '' : String(s.assessment);
      title.value = s.title;
      var warn = row.querySelector('.ev-note');
      if (s.sure) {
        warn.remove();
      } else {
        warn.hidden = false;
        row.classList.add('ev-unsure');
      }
      box.appendChild(row);
    });
  }

  input.addEventListener('change', function () {
    var files = Array.prototype.slice.call(input.files || []);
    box.textContent = '';
    if (!files.length) return;
    if (files.length > MAX) { render(files, []); return; }
    var body = new URLSearchParams();
    body.append('action', 'evidence_suggest');
    body.append('offering', box.dataset.offering || '');
    files.forEach(function (f) { body.append('names[]', f.name); });
    fetch((document.body.dataset.base || '') + 'api.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded' }, body: body
    }).then(function (r) { return r.json(); })
      .then(function (res) { render(files, res && res.ok ? res.suggestions : []); })
      .catch(function () { render(files, []); });
  });
})();
