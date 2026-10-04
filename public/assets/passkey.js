// SAQF passkeys: thin browser glue for WebAuthn. All verification happens on the server.
(function () {
  'use strict';
  function b64u(buf) {
    var s = '', b = new Uint8Array(buf);
    for (var i = 0; i < b.length; i++) s += String.fromCharCode(b[i]);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  function unb64u(s) {
    s = s.replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    var bin = atob(s), out = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out.buffer;
  }
  function L(key, fallback) { var e = document.querySelector('#ui-text [data-k="' + key + '"]'); return e ? e.textContent : fallback; }
  function say(box, text, bad) {
    if (!box) return;
    box.textContent = text;
    box.hidden = false;
    box.className = 'alert ' + (bad ? 'alert-error' : 'alert-success');
    box.setAttribute('role', bad ? 'alert' : 'status');
  }
  function call(csrf, payload) {
    return fetch('passkey.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(payload) })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: L('network', 'Network error') }; }); });
  }
  function creationOptions(o) {
    o.challenge = unb64u(o.challenge);
    o.user.id = unb64u(o.user.id);
    (o.excludeCredentials || []).forEach(function (c) { c.id = unb64u(c.id); });
    return o;
  }
  function requestOptions(o) {
    o.challenge = unb64u(o.challenge);
    (o.allowCredentials || []).forEach(function (c) { c.id = unb64u(c.id); });
    return o;
  }

  var supported = !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.create);
  Array.prototype.forEach.call(document.querySelectorAll('[data-passkey]'), function (btn) {
    var kind = btn.getAttribute('data-passkey');
    var csrf = btn.getAttribute('data-csrf') || '';
    var box = document.getElementById(btn.getAttribute('data-status') || 'passkey-status');
    if (!supported) {
      btn.disabled = true;
      say(box, L('passkey_unsupported', 'This browser cannot use passkeys.'), true);
      return;
    }
    btn.addEventListener('click', function () {
      btn.disabled = true;
      if (box) box.hidden = true;
      var fail = function (msg) { btn.disabled = false; say(box, msg || L('passkey_failed', 'The passkey could not be used.'), true); };
      if (kind === 'login') {
        call(csrf, { action: 'login_options' }).then(function (r) {
          if (!r.ok) { if (r.redirect) { location.href = r.redirect; return; } return fail(r.error); }
          return navigator.credentials.get({ publicKey: requestOptions(r.options) }).then(function (cred) {
            var a = cred.response;
            return call(csrf, { action: 'login_finish', credential: { id: cred.id, clientDataJSON: b64u(a.clientDataJSON), authenticatorData: b64u(a.authenticatorData), signature: b64u(a.signature) } });
          }).then(function (r2) {
            if (!r2) return;
            if (r2.ok) { location.href = r2.redirect || 'index.php'; } else if (r2.redirect) { location.href = r2.redirect; } else { fail(r2.error); }
          });
        }).catch(function () { fail(); });
      } else if (kind === 'register') {
        var label = (document.getElementById(btn.getAttribute('data-label') || 'passkey-label') || {}).value || '';
        call(csrf, { action: 'register_options' }).then(function (r) {
          if (!r.ok) return fail(r.error);
          return navigator.credentials.create({ publicKey: creationOptions(r.options) }).then(function (cred) {
            var a = cred.response;
            return call(csrf, { action: 'register_finish', label: label, credential: { id: cred.id, clientDataJSON: b64u(a.clientDataJSON), attestationObject: b64u(a.attestationObject) } });
          }).then(function (r2) {
            if (!r2) return;
            if (r2.ok) { location.reload(); } else { fail(r2.error); }
          });
        }).catch(function () { fail(); });
      } else if (kind === 'remove') {
        if (!window.confirm(btn.getAttribute('data-confirm') || 'Remove this passkey?')) { btn.disabled = false; return; }
        call(csrf, { action: 'remove', id: parseInt(btn.getAttribute('data-id'), 10) }).then(function (r) { if (r.ok) { location.reload(); } else { fail(r.error); } }).catch(function () { fail(); });
      }
    });
  });
})();
