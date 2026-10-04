// SAQF evidence upload: several files at once. For every file chosen, SAQF suggests what it is and which
// assessment it belongs to FROM ITS FILE NAME (only the names are sent, never the contents), and the person
// corrects anything that is wrong before filing. Without JavaScript the form still works: the server makes the
// same suggestion. No inline script: the page declares what it needs in data attributes (strict CSP).
(function () {
  'use strict';
  var input = document.getElementById('ev-files');
  var box = document.getElementById('ev-rows');
  if (!input || !box) return;
  var csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';
  var kinds = {}, assessments = [], text = {};
  try { kinds = JSON.parse(box.dataset.kinds || '{}'); assessments = JSON.parse(box.dataset.assessments || '[]'); text = JSON.parse(box.dataset.text || '{}'); } catch (e) { return; }
  var MAX = parseInt(box.dataset.max || '12', 10);

  function el(tag, attrs, children) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) { if (k === 'text') n.textContent = attrs[k]; else n.setAttribute(k, attrs[k]); });
    (children || []).forEach(function (c) { n.appendChild(c); });
    return n;
  }
  function select(name, label, options, value) {
    var s = el('select', { name: name, 'aria-label': label });
    options.forEach(function (o) {
      var op = el('option', { value: o[0], text: o[1] });
      if (String(o[0]) === String(value)) op.selected = true;
      s.appendChild(op);
    });
    return s;
  }
  function size(b) { return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }

  function render(files, sugg) {
    box.textContent = '';
    if (files.length > MAX) {
      box.appendChild(el('div', { 'class': 'alert alert-error', role: 'alert', text: (text.tooMany || 'Add up to {n} files at a time.').replace('{n}', MAX) }));
      return;
    }
    box.appendChild(el('p', { 'class': 'tiny muted', text: text.hint || 'SAQF suggested these from the file names. Please check each one.' }));
    var kindOptions = Object.keys(kinds).map(function (k) { return [k, kinds[k]]; });
    var asmOptions = [['', text.general || 'General (not one assessment)']].concat(assessments.map(function (a) { return [a.id, a.name]; }));
    files.forEach(function (f, i) {
      var s = sugg[i] || { kind: 'other', assessment: null, title: f.name.replace(/\.[^.]+$/, ''), sure: false };
      var row = el('div', { 'class': 'ev-row' + (s.sure ? '' : ' ev-unsure') }, [
        el('div', { 'class': 'ev-name', text: f.name + ' · ' + size(f.size) }),
        select('item_kind[' + i + ']', text.kind || 'What is it?', kindOptions, s.kind),
        select('item_assessment[' + i + ']', text.assessment || 'Assessment', asmOptions, s.assessment == null ? '' : s.assessment),
        el('input', { type: 'text', name: 'item_title[' + i + ']', maxlength: '200', value: s.title, 'aria-label': text.title || 'Title' })
      ]);
      if (!s.sure) row.appendChild(el('div', { 'class': 'tiny', text: text.unsure || 'Not sure about this one: please check.' }));
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
