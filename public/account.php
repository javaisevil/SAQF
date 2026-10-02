<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Audit;
use Saqf\Core\Csrf;
use Saqf\Core\Db;
use Saqf\Core\Mailer;
use Saqf\Core\Policy;
use Saqf\Core\Session;
use Saqf\Security\Auth;
use Saqf\Security\Mfa;
use Saqf\Security\Sessions;
use Saqf\Security\Totp;
use Saqf\Web\Qr;
use Saqf\Web\View as V;

$user = Auth::require();
$passwordAllowed = Auth::passwordLoginAllowed($user['role']);
$op = (string) ($_POST['op'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    saqf_require_post();
    try {
        switch ($op) {
            case 'prefs':
                $on = ($_POST['notify_email'] ?? '') === '1' ? 1 : 0;
                Db::update('users', ['notify_email' => $on], 'id = ?', [$user['id']]);
                Audit::record('user.preferences', 'user', $user['id'], "{$user['full_name']} turned e-mail notifications " . ($on ? 'on' : 'off'));
                Session::flash('success', 'E-mail notifications turned ' . ($on ? 'on' : 'off') . '.');
                break;
            case 'mfa_start':
                Mfa::begin($user);
                break;
            case 'mfa_confirm':
                $_SESSION['recovery_codes'] = Mfa::confirm($user, (string) ($_POST['code'] ?? ''));
                Session::flash('success', 'Two-step verification is on. Keep the recovery codes below somewhere safe — they are shown only once.');
                break;
            case 'mfa_codes':
                if (Auth::reauthenticate($user, (string) ($_POST['password'] ?? ''), (string) ($_POST['code'] ?? '')) !== null) {
                    throw new InvalidArgumentException('Confirm with your password and a current code to get new recovery codes.');
                }
                $_SESSION['recovery_codes'] = Mfa::newRecoveryCodes($user['id']);
                Audit::record('security.mfa_codes_renewed', 'user', $user['id'], "{$user['full_name']} generated new recovery codes");
                Session::flash('success', 'New recovery codes generated; the old ones no longer work.');
                break;
            case 'mfa_off':
                if (Mfa::required($user)) {
                    throw new InvalidArgumentException('Two-step verification is required for your role, so it cannot be turned off.');
                }
                if ($err = Auth::reauthenticate($user, (string) ($_POST['password'] ?? ''), (string) ($_POST['code'] ?? ''))) {
                    throw new InvalidArgumentException($err);
                }
                Mfa::disable($user['id'], 'self');
                Session::flash('success', 'Two-step verification turned off.');
                break;
            case 'end_session':
                $hash = (string) ($_POST['session'] ?? '');
                if (!Db::val('SELECT 1 FROM user_sessions WHERE token_hash = ? AND user_id = ?', [$hash, $user['id']])) {
                    throw new InvalidArgumentException('Session not found.');
                }
                Sessions::end($hash, 'signed out from another session');
                Audit::record('security.session_ended', 'user', $user['id'], "{$user['full_name']} signed out one of their other sessions");
                Session::flash('success', 'That session was signed out.');
                break;
            case 'end_others':
                $n = Sessions::endAll($user['id'], 'signed out from another session', true);
                Audit::record('security.sessions_ended', 'user', $user['id'], "{$user['full_name']} signed out $n other session(s)");
                Session::flash('success', "$n other session(s) signed out.");
                break;
            default:
                if (!$passwordAllowed) {
                    break;
                }
                $new = (string) ($_POST['new'] ?? '');
                if ($new !== (string) ($_POST['confirm'] ?? '')) {
                    throw new InvalidArgumentException('The new passwords do not match.');
                }
                if ($err = Auth::changePassword($user, (string) ($_POST['current'] ?? ''), $new)) {
                    throw new InvalidArgumentException($err);
                }
                Session::flash('success', 'Password changed. Every other session was signed out.');
                saqf_redirect('index.php');
        }
    } catch (InvalidArgumentException $e) {
        Session::flash('error', $e->getMessage());
    }
    saqf_redirect('account.php' . (in_array($op, ['mfa_start', 'mfa_confirm'], true) ? '#mfa' : ''));
}

$user = Auth::user();
$logins = Db::all('SELECT * FROM login_attempts WHERE username = ? ORDER BY id DESC LIMIT 12', [$user['username']]);
$sessions = Sessions::forUser($user['id']);
$current = Sessions::currentHash();
$pendingSecret = Mfa::pendingSecret($user);
$codes = $_SESSION['recovery_codes'] ?? null;
unset($_SESSION['recovery_codes']);
V::header('Account & security', $user, ['subtitle' => V::h($user['username']) . ' · ' . V::h(Auth::ROLES[$user['role']])]);
?>
<?php if (!empty($_GET['required'])): ?><div class="alert alert-warning">You must set a new password before continuing.</div><?php endif; ?>
<?php if (($_GET['mfa'] ?? '') === 'required' && !Mfa::enabled($user)): ?><div class="alert alert-warning">Your role requires two-step verification. Set it up below to continue — it takes a minute with an authenticator app.</div><?php endif; ?>
<?php if ($codes): ?><section class="card" style="border-color:#F6DFC3;margin-bottom:16px"><div class="card-h"><h2>Your recovery codes</h2><span class="muted small right">Shown once</span></div><div class="card-b">
  <p class="small">Each code works once, instead of a code from your phone. Print them or keep them in a password manager.</p>
  <div class="grid g4 mono" style="gap:6px"><?php foreach ($codes as $c): ?><div class="pill pill-grey" style="font-size:14px;justify-content:center"><?= V::h($c) ?></div><?php endforeach; ?></div></div></section><?php endif; ?>
<div class="grid g2">
<div class="stack">
<?php if ($passwordAllowed): ?>
<section class="card"><div class="card-h"><h2>Change password</h2></div><div class="card-b">
  <form method="post"><?= Csrf::field() ?>
    <div class="field"><label>Current password</label><input type="password" name="current" required autocomplete="current-password"></div>
    <div class="field"><label>New password</label><input type="password" name="new" required minlength="<?= (int) Policy::get('auth.min_password_length') ?>" autocomplete="new-password"><div class="field-help">At least <?= (int) Policy::get('auth.min_password_length') ?> characters with letters and numbers. Common words, keyboard runs, your name and the university's name are refused — a passphrase of unrelated words works well.</div></div>
    <div class="field"><label>Confirm new password</label><input type="password" name="confirm" required autocomplete="new-password"></div>
    <button class="btn btn-primary" type="submit">Update password</button>
    <p class="tiny muted" style="margin-top:8px">Changing your password signs out every other session.</p></form></div></section>

<section class="card" id="mfa"><div class="card-h"><h2>Two-step verification</h2><?= Mfa::enabled($user) ? V::pill('On', 'green') : (Mfa::required($user) ? V::pill('Required for your role', 'red') : V::pill('Off', 'grey')) ?></div><div class="card-b small">
  <?php if (Mfa::enabled($user)): ?>
    <p>Password sign-ins also need a code from your authenticator app. Turned on <?= V::h(V::date($user['mfa_enabled_at'], 'j M Y')) ?> · <?= Mfa::recoveryLeft($user['id']) ?> recovery code(s) left.</p>
    <details><summary class="btn btn-sm">New recovery codes</summary><form method="post" class="row" style="margin-top:8px"><?= Csrf::field() ?><input type="hidden" name="op" value="mfa_codes"><input type="password" name="password" placeholder="Password" required autocomplete="current-password"><input type="text" name="code" placeholder="Current code" required inputmode="numeric" style="width:120px"><button class="btn btn-sm">Generate</button></form></details>
    <?php if (!Mfa::required($user)): ?><details style="margin-top:8px"><summary class="btn btn-sm btn-ghost">Turn off</summary><form method="post" class="row" style="margin-top:8px"><?= Csrf::field() ?><input type="hidden" name="op" value="mfa_off"><input type="password" name="password" placeholder="Password" required autocomplete="current-password"><input type="text" name="code" placeholder="Current code" required inputmode="numeric" style="width:120px"><button class="btn btn-sm btn-red">Turn off</button></form></details><?php endif; ?>
  <?php elseif ($pendingSecret): $uri = Totp::uri($pendingSecret, $user['username']); ?>
    <p><strong>1.</strong> In your authenticator app, add an account and scan this code:</p>
    <div style="text-align:center;margin:8px 0"><?= Qr::svg($uri, 4, 'Authenticator set-up code') ?></div>
    <p class="tiny muted">Can't scan? Enter this key manually: <span class="mono" style="user-select:all"><?= V::h(trim(chunk_split($pendingSecret, 4, ' '))) ?></span> (time-based, 6 digits)</p>
    <p><strong>2.</strong> Enter the 6-digit code the app shows:</p>
    <form method="post" class="row"><?= Csrf::field() ?><input type="hidden" name="op" value="mfa_confirm"><input type="text" name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="123456" style="width:140px;font-size:18px;letter-spacing:3px"><button class="btn btn-primary btn-sm">Turn on</button></form>
  <?php else: ?>
    <p>Protect password sign-ins with a code from Microsoft Authenticator, Google Authenticator or a similar app. <?= Mfa::required($user) ? '<strong>Required for your role.</strong>' : '' ?></p>
    <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="mfa_start"><button class="btn btn-sm btn-primary">Set up two-step verification</button></form>
  <?php endif; ?>
</div></section>
<?php else: ?>
<section class="card"><div class="card-h"><h2>Sign-in</h2></div><div class="card-b small">You sign in with your university account; your password and multi-factor authentication are managed by the university identity provider.</div></section>
<?php endif; ?>
<section class="card"><div class="card-h"><h2>E-mail notifications</h2></div><div class="card-b small">
  <?php if (!Mailer::enabled()): ?><p class="muted">E-mail delivery is not configured on this server; notifications appear in SAQF only.</p><?php endif; ?>
  <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="prefs">
    <label class="row" style="gap:8px"><input type="checkbox" name="notify_email" value="1" <?= (int) ($user['notify_email'] ?? 1) ? 'checked' : '' ?> style="width:auto"> E-mail me when something needs my action<?= $user['email'] ? ' (' . V::h($user['email']) . ')' : ' — no e-mail address on file' ?></label>
    <button class="btn btn-sm" type="submit" style="margin-top:8px">Save</button></form>
  <p class="tiny muted" style="margin-top:8px">SAQF writes only when a person must act, at most one summary per scheduler run.</p></div></section>
</div>
<div class="stack">
<section class="card"><div class="card-h"><h2>Where you're signed in</h2><?php if (count(array_filter($sessions, static fn($s) => $s['ended_at'] === null)) > 1): ?><form method="post" class="right"><?= Csrf::field() ?><input type="hidden" name="op" value="end_others"><button class="btn btn-sm">Sign out all other sessions</button></form><?php endif; ?></div><div class="card-b tight"><table><tbody>
  <?php foreach ($sessions as $s): $isCurrent = $s['token_hash'] === $current; ?><tr><td class="small"><strong><?= V::h(Sessions::describe($s['user_agent'])) ?></strong><?= $isCurrent ? ' ' . V::pill('this session', 'blue') : '' ?><div class="tiny muted mono"><?= V::h($s['ip']) ?> · <?= V::h($s['method']) ?></div></td>
    <td class="small"><?= $s['ended_at'] ? '<span class="muted">ended ' . V::h(V::ago($s['ended_at'])) . ($s['end_reason'] ? ' · ' . V::h($s['end_reason']) : '') . '</span>' : 'active · ' . V::h(V::ago($s['last_seen_at'])) ?></td>
    <td class="num"><?php if (!$s['ended_at'] && !$isCurrent): ?><form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="end_session"><input type="hidden" name="session" value="<?= V::h($s['token_hash']) ?>"><button class="btn btn-sm btn-ghost">Sign out</button></form><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div></section>
<section class="card"><div class="card-h"><h2>Recent sign-in activity</h2></div><div class="card-b tight"><table><tbody>
  <?php if (!$logins): ?><tr><td class="small muted">No password sign-ins recorded for this account<?= ($_SESSION['auth'] ?? '') === 'demo' ? ' (demo sign-ins skip the password)' : '' ?>.</td></tr><?php endif; ?>
  <?php foreach ($logins as $l): ?><tr><td class="small"><?= V::h(V::date($l['created_at'], 'j M Y H:i')) ?></td><td class="small mono"><?= V::h($l['ip']) ?></td><td><?= (int) $l['success'] ? V::pill('success', 'green') : V::pill(str_replace('_', ' ', (string) $l['reason']), 'red') ?></td></tr><?php endforeach; ?>
  </tbody></table><div class="card-b tiny muted">If you don't recognise an attempt, change your password and inform IT security. Sessions end after <?= (int) Policy::get('session.idle_minutes') ?> minutes of inactivity.</div></div></section>
</div>
</div>
<?php V::footer();
