// SAQF client helpers: CSRF-protected JSON actions, toasts that survive reloads, global search.
(function () {
  'use strict';
  var csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';

  function toast(html, ms) {
    var t = document.createElement('div');
    t.className = 'toast';
    t.innerHTML = html;
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, ms || 5200);
  }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

  // Show a toast stored before a reload (keeps the feedback after the page refreshes).
  try {
    var pending = sessionStorage.getItem('saqf.toast');
    if (pending) { sessionStorage.removeItem('saqf.toast'); toast(pending); }
    var y = sessionStorage.getItem('saqf.scroll');
    if (y) { sessionStorage.removeItem('saqf.scroll'); window.scrollTo(0, parseInt(y, 10) || 0); }
  } catch (e) { /* storage unavailable */ }

  function describe(res) {
    var out = '<div>' + esc(res.message || 'Saved') + '</div>';
    if (res.cleared && res.cleared.length) out += '<div class="ok">✓ Cleared automatically: ' + res.cleared.map(esc).join(' · ') + '</div>';
    if (res.opened && res.opened.length) out += '<div class="bad">• New check: ' + res.opened.map(esc).join(' · ') + '</div>';
    return out;
  }

  window.saqf = {
    post: function (action, data, opts) {
      opts = opts || {};
      var body = new URLSearchParams();
      body.append('action', action);
      Object.keys(data || {}).forEach(function (k) {
        var v = data[k];
        if (Array.isArray(v)) v.forEach(function (x) { body.append(k + '[]', x); });
        else body.append(k, v == null ? '' : v);
      });
      return fetch((document.body.dataset.base || '') + 'api.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded' }, body: body
      }).then(function (r) { return r.json(); }).then(function (res) {
        if (!res.ok) { toast('<span class="bad">' + esc(res.error || 'Could not save') + '</span>', 7000); return res; }
        if (opts.reload !== false) {
          try { sessionStorage.setItem('saqf.toast', describe(res)); sessionStorage.setItem('saqf.scroll', String(window.scrollY)); } catch (e) {}
          if (res.redirect) location.href = res.redirect; else location.reload();
        } else { toast(describe(res)); }
        return res;
      }).catch(function () { toast('<span class="bad">Network error — nothing was changed.</span>'); });
    },
    toast: toast
  };

  // No inline scripts anywhere (strict Content-Security-Policy): behaviour is declared with data attributes.

  // Submit buttons that set a field first: <button type="submit" data-set="decision=reject">
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-set]');
    if (!b || !b.form) return;
    b.dataset.set.split('&').forEach(function (pair) {
      var kv = pair.split('='), field = b.form.elements[kv[0]];
      if (field) field.value = kv.slice(1).join('=');
    });
  }, true);
  // Forms that ask before submitting: <form data-confirm="…">
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.dataset && f.dataset.confirm && !window.confirm(f.dataset.confirm)) { e.preventDefault(); e.stopImmediatePropagation(); }
  }, true);
  // Menu toggle, print, auto-submitting and navigating selects.
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-toggle]');
    if (t) { var target = document.querySelector(t.dataset.toggle); if (target) target.classList.toggle('open'); }
    if (e.target.closest('[data-print]')) window.print();
  });
  document.addEventListener('change', function (e) {
    var s = e.target;
    if (s.matches('select[data-autosubmit]') && s.form) s.form.submit();
    if (s.matches('select[data-nav]') && s.value) location.href = s.dataset.nav + encodeURIComponent(s.value);
  });

  // Declarative actions: <button data-act="map" data-clo="1" data-plo="2" data-on="1">
  // data-prompt asks for a reason first (sent as "reason"); data-weights collects the weight inputs.
  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-act]');
    if (!el) return;
    e.preventDefault();
    if (el.dataset.confirm && !window.confirm(el.dataset.confirm)) return;
    var data = {};
    Object.keys(el.dataset).forEach(function (k) { if (['act', 'confirm', 'prompt', 'weights'].indexOf(k) === -1) data[k] = el.dataset[k]; });
    if (el.dataset.prompt) {
      var reason = window.prompt(el.dataset.prompt);
      if (!reason) return;
      data.reason = reason;
    }
    if (el.hasAttribute('data-weights')) {
      data.w = [];
      document.querySelectorAll('input[data-weight]').forEach(function (i) { data.w.push(i.dataset.id + ':' + i.value); });
    }
    el.disabled = true;
    window.saqf.post(el.dataset.act, data).then(function () { el.disabled = false; });
  });

  // Forms that post to the API: <form data-api="save_clo">
  document.addEventListener('submit', function (e) {
    var f = e.target.closest('form[data-api]');
    if (!f) return;
    e.preventDefault();
    var data = {};
    new FormData(f).forEach(function (v, k) {
      if (k.slice(-2) === '[]') { k = k.slice(0, -2); (data[k] = data[k] || []).push(v); }
      else data[k] = v;
    });
    var btn = f.querySelector('[type=submit]'); if (btn) btn.disabled = true;
    window.saqf.post(f.dataset.api, data).then(function () { if (btn) btn.disabled = false; });
  });

  // Live assessment-weight total (error prevention: you see the total before saving).
  document.querySelectorAll('[data-weight-total]').forEach(function (box) {
    var target = parseFloat(box.dataset.weightTotal) || 100;
    function recalc() {
      var sum = 0;
      document.querySelectorAll('input[data-weight]').forEach(function (i) { sum += parseFloat(i.value) || 0; });
      box.textContent = (Math.round(sum * 100) / 100) + '% of ' + target + '%';
      box.className = 'pill ' + (Math.abs(sum - target) < 0.01 ? 'pill-green' : 'pill-red');
    }
    document.addEventListener('input', function (e) { if (e.target.matches('input[data-weight]')) recalc(); });
    recalc();
  });

  // Global search suggestions.
  var input = document.getElementById('globalSearch'), pop = document.getElementById('searchPop'), timer;
  if (input && pop) {
    input.addEventListener('input', function () {
      clearTimeout(timer);
      var q = input.value.trim();
      if (q.length < 2) { pop.style.display = 'none'; return; }
      timer = setTimeout(function () {
        fetch('search.php?format=json&q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (rows) {
          if (!rows.length) { pop.innerHTML = '<a>No matches</a>'; } else {
            pop.innerHTML = rows.slice(0, 12).map(function (r) { return '<a href="' + esc(r.link) + '"><b>' + esc(r.type) + '</b> · ' + esc(r.title) + '<small>' + esc(r.sub) + '</small></a>'; }).join('');
          }
          pop.style.display = 'block';
        });
      }, 180);
    });
    document.addEventListener('click', function (e) { if (!e.target.closest('.search')) pop.style.display = 'none'; });
  }

  // Study-plan browser: the level filter hides programs of the other level.
  var lv = document.getElementById('fLevel'), pr = document.getElementById('fProgram'), col = document.getElementById('fCollege');
  if (lv && pr) {
    var levelFilter = function () {
      Array.prototype.forEach.call(pr.options, function (o) {
        if (!o.value) return;
        var hide = (lv.value && o.dataset.level !== lv.value) || o.hidden;
        o.style.display = hide ? 'none' : '';
        o.disabled = !!hide;
      });
    };
    lv.addEventListener('change', levelFilter);
    if (col) col.addEventListener('change', levelFilter);
    levelFilter();
  }

  // Cascading selects (study-plan aware pickers): <select data-cascade="url" data-target="#id">
  document.querySelectorAll('select[data-filter-parent]').forEach(function (child) {
    var parent = document.querySelector(child.dataset.filterParent);
    if (!parent) return;
    function apply() {
      var v = parent.value, first = null;
      Array.prototype.forEach.call(child.options, function (o) {
        if (!o.value) return;
        var ok = !v || (o.dataset.parent || '').split(' ').indexOf(v) !== -1;
        o.hidden = !ok; o.disabled = !ok;
        if (ok && !first) first = o;
      });
      Array.prototype.forEach.call(child.querySelectorAll('optgroup'), function (g) {
        g.hidden = !Array.prototype.some.call(g.children, function (o) { return !o.hidden; });
      });
      if (child.selectedOptions[0] && child.selectedOptions[0].disabled) child.value = '';
      child.dispatchEvent(new Event('change'));
    }
    parent.addEventListener('change', apply);
    apply();
  });
})();
