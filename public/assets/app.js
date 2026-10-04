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
  // Interface words rendered by the server (so the Arabic interface translates them too).
  function L(key, fallback) { var e = document.querySelector('#ui-text [data-k="' + key + '"]'); return e ? e.textContent : fallback; }

  // Show a toast stored before a reload (keeps the feedback after the page refreshes).
  try {
    var pending = sessionStorage.getItem('saqf.toast');
    if (pending) { sessionStorage.removeItem('saqf.toast'); toast(pending); }
    var y = sessionStorage.getItem('saqf.scroll');
    if (y) { sessionStorage.removeItem('saqf.scroll'); window.scrollTo(0, parseInt(y, 10) || 0); }
  } catch (e) { /* storage unavailable */ }

  function describe(res) {
    var out = '<div>' + esc(res.message || L('saved', 'Saved')) + '</div>';
    if (res.cleared && res.cleared.length) out += '<div class="ok">✓ ' + esc(L('cleared', 'Cleared automatically:')) + ' ' + res.cleared.map(esc).join(' · ') + '</div>';
    if (res.opened && res.opened.length) out += '<div class="bad">• ' + esc(L('opened', 'New check:')) + ' ' + res.opened.map(esc).join(' · ') + '</div>';
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
        if (!res.ok) { toast('<span class="bad">' + esc(res.error || L('failed', 'Could not save')) + '</span>', 7000); return res; }
        if (opts.reload !== false) {
          try { sessionStorage.setItem('saqf.toast', describe(res)); sessionStorage.setItem('saqf.scroll', String(window.scrollY)); } catch (e) {}
          if (res.redirect) location.href = res.redirect; else location.reload();
        } else { toast(describe(res)); }
        return res;
      }).catch(function () { toast('<span class="bad">' + esc(L('network', 'Network error — nothing was changed.')) + '</span>'); });
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
  // Never lose typed work: leaving a page with an edited form asks first; saving clears the warning.
  (function () {
    var dirty = false;
    document.addEventListener('input', function (e) {
      var f = e.target.form;
      if (f && f.method === 'post' && f.closest('main') && e.target.type !== 'password' && e.target.type !== 'hidden' && !e.target.matches('[data-autosubmit],[data-nav]')) dirty = true;
    });
    document.addEventListener('submit', function () { dirty = false; });
    window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  })();
  // Press / anywhere (outside a field) to jump to the search box.
  document.addEventListener('keydown', function (e) {
    var t = e.target, s = document.getElementById('globalSearch');
    if (e.key === '/' && s && !e.ctrlKey && !e.metaKey && !e.altKey && !/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) && !t.isContentEditable) { e.preventDefault(); s.focus(); }
  });
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
      box.textContent = L('total', 'Total') + ' ' + (Math.round(sum * 100) / 100) + '% · ' + L('must', 'must be') + ' ' + target + '%';
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
          if (!rows.length) { pop.innerHTML = '<a>' + esc(L('nomatch', 'No matches')) + '</a>'; } else {
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

  // ---------------------------------------------------------------- sign-in protection and comfort

  // Robot check: find a number whose SHA-256 (with the server's puzzle) starts with N zero bits.
  // Pure JavaScript so it also works on plain-http campus addresses; the input always fits one block.
  var K = [0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,
    0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,
    0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,
    0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2];
  var W = new Array(64);
  function sha256First(msg) { // first 32 bits of SHA-256 of a short ASCII string (< 56 bytes)
    var i, n = msg.length;
    for (i = 0; i < 16; i++) W[i] = 0;
    for (i = 0; i < n; i++) W[i >> 2] |= msg.charCodeAt(i) << (24 - (i % 4) * 8);
    W[n >> 2] |= 0x80 << (24 - (n % 4) * 8);
    W[15] = n * 8;
    for (i = 16; i < 64; i++) {
      var x = W[i - 15], y = W[i - 2];
      W[i] = (W[i - 16] + ((x >>> 7 | x << 25) ^ (x >>> 18 | x << 14) ^ (x >>> 3)) + W[i - 7] + ((y >>> 17 | y << 15) ^ (y >>> 19 | y << 13) ^ (y >>> 10))) | 0;
    }
    var a = 0x6a09e667, b = 0xbb67ae85, c = 0x3c6ef372, d = 0xa54ff53a, e = 0x510e527f, f = 0x9b05688c, g = 0x1f83d9ab, h = 0x5be0cd19;
    for (i = 0; i < 64; i++) {
      var t1 = (h + ((e >>> 6 | e << 26) ^ (e >>> 11 | e << 21) ^ (e >>> 25 | e << 7)) + ((e & f) ^ (~e & g)) + K[i] + W[i]) | 0;
      var t2 = (((a >>> 2 | a << 30) ^ (a >>> 13 | a << 19) ^ (a >>> 22 | a << 10)) + ((a & b) ^ (a & c) ^ (b & c))) | 0;
      h = g; g = f; f = e; e = (d + t1) | 0; d = c; c = b; b = a; a = (t1 + t2) | 0;
    }
    return (a + 0x6a09e667) >>> 0;
  }
  document.querySelectorAll('[data-botcheck]').forEach(function (box) {
    var form = box.closest('form'), ch = box.querySelector('[name=bot_challenge]'), out = box.querySelector('[name=bot_nonce]');
    var parts = ch.value.split('.'), bits = parseInt(parts[2], 10), salt = parts[3], state = 'idle', waiting = false;
    function set(s) { state = s; box.setAttribute('data-state', s); var b = box.querySelector('.bc-box'); if (b) b.setAttribute('aria-pressed', s === 'done' ? 'true' : 'false'); }
    set('idle');
    function start() {
      if (state !== 'idle') return;
      set('working');
      var nonce = 0, began = Date.now(), shift = 32 - bits;
      (function chunk() {
        for (var end = nonce + 4000; nonce < end; nonce++) {
          if ((sha256First(salt + ':' + nonce) >>> shift) === 0) {
            out.value = String(nonce);
            // A short pause even on fast machines, so the person sees the check happen.
            setTimeout(function () { set('done'); if (waiting && form) { waiting = false; form.requestSubmit ? form.requestSubmit() : form.submit(); } }, Math.max(0, 500 - (Date.now() - began)));
            return;
          }
        }
        if (nonce > 50000000) { set('fail'); return; }
        setTimeout(chunk, 0);
      })();
    }
    box.querySelector('.bc-box').addEventListener('click', start);
    if (form) {
      // Starts quietly as soon as the person begins filling in the form.
      form.addEventListener('focusin', start);
      form.addEventListener('submit', function (e) {
        if (state === 'done') return;
        e.preventDefault(); e.stopImmediatePropagation();
        waiting = true; start();
      }, true);
    }
  });

  // Demo accounts: fill in the real form, so the demonstration goes through every sign-in step.
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-fill-user]');
    if (!b) return;
    var u = document.getElementById('username'), p = document.getElementById('password');
    if (!u || !p) return;
    u.value = b.dataset.fillUser; p.value = b.dataset.fillPass || '';
    var form = u.closest('form'), btn = form && form.querySelector('[type=submit]');
    u.dispatchEvent(new Event('focusin', { bubbles: true }));
    if (btn) { btn.focus(); btn.classList.add('pulse'); }
  });

  // Password fields: show/hide, Caps Lock warning and (for new passwords) a strength meter with tips.
  var COMMON = /^(password|passw0rd|qwerty|azerty|abc123|letmein|welcome|admin|iloveyou|monkey|dragon|111111|123123|123456|1234567|12345678|123456789|1234567890|yamamah|alyamamah|saqf)/i;
  document.querySelectorAll('input[type=password]').forEach(function (pw) {
    var wrap = document.createElement('div'); wrap.className = 'pw-wrap';
    pw.parentNode.insertBefore(wrap, pw); wrap.appendChild(pw);
    var eye = document.createElement('button'); eye.type = 'button'; eye.className = 'pw-eye'; eye.textContent = L('show', 'Show'); eye.setAttribute('aria-label', L('showpw', 'Show password'));
    wrap.appendChild(eye);
    eye.addEventListener('click', function () {
      var shown = pw.type === 'text';
      pw.type = shown ? 'password' : 'text';
      eye.textContent = shown ? L('show', 'Show') : L('hide', 'Hide');
      eye.setAttribute('aria-label', shown ? L('showpw', 'Show password') : L('hidepw', 'Hide password'));
      pw.focus();
    });
    var caps = document.createElement('div'); caps.className = 'pw-caps'; caps.hidden = true; caps.setAttribute('role', 'status'); caps.textContent = L('caps', 'Caps Lock is on');
    wrap.parentNode.insertBefore(caps, wrap.nextSibling);
    function capsCheck(e) { if (e.getModifierState) caps.hidden = !e.getModifierState('CapsLock'); }
    pw.addEventListener('keydown', capsCheck); pw.addEventListener('keyup', capsCheck);
    pw.addEventListener('blur', function () { caps.hidden = true; });
    if (!pw.hasAttribute('data-strength')) return;
    var meter = document.createElement('div'); meter.className = 'pw-meter';
    meter.innerHTML = '<div class="pw-bar"><span></span></div><div class="pw-label tiny"></div><ul class="pw-tips tiny">'
      + ['len', 'mix', 'long', 'common'].map(function (k) { return '<li data-tip="' + k + '">' + esc(L(k, k)) + '</li>'; }).join('') + '</ul>';
    caps.parentNode.insertBefore(meter, caps.nextSibling);
    pw.addEventListener('input', function () {
      var v = pw.value, ok = {
        len: v.length >= 10,
        mix: /[A-Za-z\u0600-\u06FF]/.test(v) && /\d/.test(v),
        long: v.length >= 14 || (v.match(/[\s\-_.]/g) || []).length >= 2,
        common: v.length > 0 && !COMMON.test(v) && !/(.)\1{3,}/.test(v) && !/(0123|1234|2345|3456|4567|5678|6789|abcd|qwer|asdf)/i.test(v)
      };
      var score = (ok.len ? 1 : 0) + (ok.mix ? 1 : 0) + (ok.long ? 1 : 0) + (ok.common ? 1 : 0);
      if (!ok.len || !ok.common) score = Math.min(score, 1);
      var level = ['weak', 'weak', 'fair', 'good', 'strong'][score];
      meter.setAttribute('data-level', v ? level : '');
      meter.querySelector('.pw-label').textContent = v ? L(level, level) : '';
      Object.keys(ok).forEach(function (k) { meter.querySelector('[data-tip="' + k + '"]').className = ok[k] ? 'ok' : ''; });
    });
  });

  // Buttons cannot be pressed twice: a submitted form shows that it is working.
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (e.defaultPrevented || f.matches('[data-api]') || f.method !== 'post') return;
    var btn = e.submitter || f.querySelector('[type=submit]');
    if (!btn || btn.dataset.busy) return;
    setTimeout(function () { btn.dataset.busy = '1'; btn.disabled = true; btn.classList.add('busy'); btn.setAttribute('aria-busy', 'true'); }, 0);
    setTimeout(function () { delete btn.dataset.busy; btn.disabled = false; btn.classList.remove('busy'); btn.removeAttribute('aria-busy'); }, 15000);
  });

  // Messages at the top of the page can be dismissed; confirmations fade by themselves.
  document.querySelectorAll('.alert').forEach(function (a) {
    if (a.closest('.login-form') && a.classList.contains('alert-error')) return;
    var x = document.createElement('button'); x.type = 'button'; x.className = 'alert-x'; x.setAttribute('aria-label', L('dismiss', 'Dismiss')); x.textContent = '×';
    x.addEventListener('click', function () { a.remove(); });
    a.appendChild(x);
    if (a.classList.contains('alert-success')) setTimeout(function () { a.classList.add('fade'); setTimeout(function () { a.remove(); }, 600); }, 9000);
  });

  // Session timer: two minutes before the idle sign-out, warn and offer to stay signed in.
  var idleMeta = document.querySelector('meta[name="saqf-idle"]'), warn = document.getElementById('sessionWarn');
  if (idleMeta && warn) {
    var idle = parseInt(idleMeta.content, 10) || 1800, deadline = Date.now() + idle * 1000, tick;
    var show = function () {
      warn.hidden = false;
      clearInterval(tick);
      tick = setInterval(function () {
        var left = Math.max(0, Math.round((deadline - Date.now()) / 1000));
        warn.querySelector('[data-countdown]').textContent = Math.floor(left / 60) + ':' + ('0' + left % 60).slice(-2);
        if (left <= 0) { clearInterval(tick); location.href = 'login.php'; }
      }, 1000);
    };
    var check = function () {
      // Another tab may have kept the session alive: ask the server before warning.
      fetch('ping.php', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (s) {
        if (!s.signedIn) { location.href = 'login.php'; return; }
        deadline = Date.now() + s.remaining * 1000;
        if (s.remaining <= 125) show(); else { warn.hidden = true; setTimeout(check, (s.remaining - 120) * 1000); }
      }).catch(function () { setTimeout(check, 30000); });
    };
    setTimeout(check, Math.max(5, idle - 120) * 1000);
    warn.querySelector('[data-stay]').addEventListener('click', function () {
      fetch('ping.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } }).then(function (r) { return r.json(); }).then(function (s) {
        if (!s.signedIn) { location.href = 'login.php'; return; }
        clearInterval(tick); warn.hidden = true; deadline = Date.now() + s.remaining * 1000;
        setTimeout(check, Math.max(5, s.remaining - 120) * 1000);
        toast(esc(L('stayed', 'You are still signed in.')), 3000);
      });
    });
  }

  // Keyboard shortcuts: ? lists them; g then h / n / a goes home, to notifications, to the account.
  var kbd = document.getElementById('kbdHelp'), gPressed = 0;
  function closeKbd() { if (kbd) kbd.hidden = true; }
  if (kbd) {
    kbd.addEventListener('click', function (e) { if (e.target === kbd || e.target.closest('[data-close]')) closeKbd(); });
  }
  document.addEventListener('keydown', function (e) {
    var t = e.target;
    if (e.key === 'Escape') { closeKbd(); if (pop) pop.style.display = 'none'; return; }
    if (/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) || t.isContentEditable || e.ctrlKey || e.metaKey || e.altKey) return;
    if (e.key === '?' && kbd) { e.preventDefault(); kbd.hidden = !kbd.hidden; if (!kbd.hidden) kbd.querySelector('[data-close]').focus(); return; }
    if (!document.getElementById('globalSearch')) return; // shortcuts only inside the signed-in app
    if (e.key === 'g') { gPressed = Date.now(); return; }
    if (Date.now() - gPressed < 1200) {
      var to = { h: 'index.php', n: 'notifications.php', a: 'account.php' }[e.key];
      gPressed = 0;
      if (to) { e.preventDefault(); location.href = to; }
    }
  });

  // Accessibility helpers (progressive enhancement; pages render labels themselves where they can):
  // a <label> next to a control inside .field is tied to it; any other control still without a name gets one
  // from nearby text (its placeholder, the first cell of its row, the heading of its card); and scrollable regions
  // can be reached with the keyboard.
  function labelControls() {
    var n = 0;
    document.querySelectorAll('.field > label:not([for])').forEach(function (lab) {
      var c = lab.parentNode.querySelector('input:not([type=hidden]), select, textarea');
      if (!c || c.closest('label')) return;
      if (!c.id) c.id = 'f-auto-' + (++n);
      lab.setAttribute('for', c.id);
    });
    document.querySelectorAll('input:not([type=hidden]):not([type=submit]):not([type=button]), select, textarea').forEach(function (c) {
      if ((c.labels && c.labels.length) || c.getAttribute('aria-label') || c.getAttribute('aria-labelledby') || c.title) return;
      var name = c.getAttribute('placeholder');
      if (!name) { var td = c.closest('td'), row = c.closest('tr'), cell = row && row.querySelector('th, td'); name = cell && cell !== td ? cell.textContent : ''; }
      if (!name) { var card = c.closest('.card'), h = card && card.querySelector('h2, h3'); name = h ? h.textContent : ''; }
      name = (name || '').replace(/\s+/g, ' ').trim().slice(0, 100);
      if (name) c.setAttribute('aria-label', name);
    });
  }
  function scrollRegions() {
    document.querySelectorAll('.table-wrap, .card-b').forEach(function (el) {
      if (el.hasAttribute('tabindex') || el.scrollWidth <= el.clientWidth + 1 || !/(auto|scroll)/.test(getComputedStyle(el).overflowX)) return;
      var card = el.closest('.card'), h = card && card.querySelector('h2, h3');
      el.setAttribute('tabindex', '0');
      el.setAttribute('role', 'region');
      el.setAttribute('aria-label', h ? h.textContent.trim() : L('scrollable', 'Scrollable content'));
    });
  }
  labelControls();
  scrollRegions();
  window.addEventListener('resize', scrollRegions);
})();
