<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Csrf;
use Saqf\Core\Db;
use Saqf\Core\Policy;
use Saqf\Core\Session;
use Saqf\Security\Auth;
use Saqf\Web\View as V;

$user = Auth::require();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
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
<section class="card"><div class="card-h"><h2>Change password</h2></div><div class="card-b">
  <form method="post"><?= Csrf::field() ?>
    <div class="field"><label>Current password</label><input type="password" name="current" required autocomplete="current-password"></div>
    <div class="field"><label>New password</label><input type="password" name="new" required minlength="<?= (int) Policy::get('auth.min_password_length') ?>" autocomplete="new-password"><div class="field-help">At least <?= (int) Policy::get('auth.min_password_length') ?> characters with letters and numbers; must not contain your username.</div></div>
    <div class="field"><label>Confirm new password</label><input type="password" name="confirm" required autocomplete="new-password"></div>
    <button class="btn btn-primary" type="submit">Update password</button></form></div></section>
<section class="card"><div class="card-h"><h2>Recent sign-in activity</h2></div><div class="card-b tight"><table><tbody>
  <?php foreach ($logins as $l): ?><tr><td class="small"><?= V::h(V::date($l['created_at'], 'j M Y H:i')) ?></td><td class="small mono"><?= V::h($l['ip']) ?></td><td><?= (int) $l['success'] ? V::pill('success', 'green') : V::pill(str_replace('_', ' ', (string) $l['reason']), 'red') ?></td></tr><?php endforeach; ?>
  </tbody></table><div class="card-b tiny muted">If you don't recognise an attempt, change your password and inform IT security. Sessions end after <?= (int) Policy::get('session.idle_minutes') ?> minutes of inactivity.</div></div></section>
</div>
<?php V::footer();
