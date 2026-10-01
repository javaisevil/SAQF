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
use Saqf\Web\View as V;

$user = Auth::require();
$passwordAllowed = Auth::passwordLoginAllowed($user['role']);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['op'] ?? '') === 'prefs') {
    saqf_require_post();
    $on = ($_POST['notify_email'] ?? '') === '1' ? 1 : 0;
    Db::update('users', ['notify_email' => $on], 'id = ?', [$user['id']]);
    Audit::record('user.preferences', 'user', $user['id'], "{$user['full_name']} turned e-mail notifications " . ($on ? 'on' : 'off'));
    Session::flash('success', 'E-mail notifications turned ' . ($on ? 'on' : 'off') . '.');
    saqf_redirect('account.php');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $passwordAllowed) {
    saqf_require_post();
    $new = (string) ($_POST['new'] ?? '');
    if ($new !== (string) ($_POST['confirm'] ?? '')) {
        Session::flash('error', 'The new passwords do not match.');
    } elseif ($err = Auth::changePassword($user, (string) ($_POST['current'] ?? ''), $new)) {
        Session::flash('error', $err);
    } else {
        Session::flash('success', 'Password changed. Other sessions are invalidated at their next request.');
        saqf_redirect('index.php');
    }
    saqf_redirect('account.php');
}
$logins = Db::all('SELECT * FROM login_attempts WHERE username = ? ORDER BY id DESC LIMIT 12', [$user['username']]);
V::header('Account & security', $user, ['subtitle' => V::h($user['username']) . ' · ' . V::h(Auth::ROLES[$user['role']])]);
?>
<?php if (!empty($_GET['required'])): ?><div class="alert alert-warning">You must set a new password before continuing.</div><?php endif; ?>
<div class="grid g2">
<div class="stack">
<?php if ($passwordAllowed): ?>
<section class="card"><div class="card-h"><h2>Change password</h2></div><div class="card-b">
  <form method="post"><?= Csrf::field() ?>
    <div class="field"><label>Current password</label><input type="password" name="current" required autocomplete="current-password"></div>
    <div class="field"><label>New password</label><input type="password" name="new" required minlength="<?= (int) Policy::get('auth.min_password_length') ?>" autocomplete="new-password"><div class="field-help">At least <?= (int) Policy::get('auth.min_password_length') ?> characters with letters and numbers; must not contain your username.</div></div>
    <div class="field"><label>Confirm new password</label><input type="password" name="confirm" required autocomplete="new-password"></div>
    <button class="btn btn-primary" type="submit">Update password</button></form></div></section>
<?php else: ?>
<section class="card"><div class="card-h"><h2>Sign-in</h2></div><div class="card-b small">You sign in with your university account; your password is managed by the university identity provider.</div></section>
<?php endif; ?>
<section class="card"><div class="card-h"><h2>E-mail notifications</h2></div><div class="card-b small">
  <?php if (!Mailer::enabled()): ?><p class="muted">E-mail delivery is not configured on this server; notifications appear in SAQF only.</p><?php endif; ?>
  <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="prefs">
    <label class="row" style="gap:8px"><input type="checkbox" name="notify_email" value="1" <?= (int) ($user['notify_email'] ?? 1) ? 'checked' : '' ?> style="width:auto"> E-mail me when something needs my action<?= $user['email'] ? ' (' . V::h($user['email']) . ')' : ' — no e-mail address on file' ?></label>
    <button class="btn btn-sm" type="submit" style="margin-top:8px">Save</button></form>
  <p class="tiny muted" style="margin-top:8px">SAQF writes only when a person must act, at most one summary per scheduler run.</p></div></section>
</div>
<section class="card"><div class="card-h"><h2>Recent sign-in activity</h2></div><div class="card-b tight"><table><tbody>
  <?php foreach ($logins as $l): ?><tr><td class="small"><?= V::h(V::date($l['created_at'], 'j M Y H:i')) ?></td><td class="small mono"><?= V::h($l['ip']) ?></td><td><?= (int) $l['success'] ? V::pill('success', 'green') : V::pill(str_replace('_', ' ', (string) $l['reason']), 'red') ?></td></tr><?php endforeach; ?>
  </tbody></table><div class="card-b tiny muted">If you don't recognise an attempt, change your password and inform IT security. Sessions end after <?= (int) Policy::get('session.idle_minutes') ?> minutes of inactivity.</div></div></section>
</div>
<?php V::footer();
