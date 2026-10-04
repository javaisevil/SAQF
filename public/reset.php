<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Csrf;
use Saqf\Core\Policy;
use Saqf\Core\Session;
use Saqf\Security\PasswordReset;
use Saqf\Web\View as V;

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Csrf::valid()) {
        $error = 'The form expired. Please try again.';
    } elseif (($error = PasswordReset::complete($token, (string) ($_POST['new'] ?? ''), (string) ($_POST['confirm'] ?? ''))) === null) {
        Session::flash('success', 'Your password has been set. Sign in with your new password.');
        saqf_redirect('login.php');
    }
}
$user = PasswordReset::find($token);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer">
<title>Choose a password · SAQF</title><link rel="icon" href="assets/favicon.png"><link rel="stylesheet" href="assets/app.css?v=<?= SAQF_VERSION ?>"></head>
<body><div class="login"><section class="login-hero"><div><img src="assets/yu-logo.png" alt="Al Yamamah University"><h1>Choose your SAQF password</h1>
<p>Use at least <?= (int) Policy::get('auth.min_password_length') ?> characters with letters and numbers. Do not reuse your password from other systems.</p></div></section>
<section class="login-form"><div class="login-lang"><?= \Saqf\Web\I18n::switchLink() ?></div><h2>New password</h2>
<?php if ($error): ?><div class="alert alert-error" role="alert"><?= V::h($error) ?></div><?php endif; ?>
<?php if (!$user): ?>
  <div class="alert alert-error">This link is invalid or has expired.</div><p class="small"><a href="forgot.php">Request a new link</a> · <a href="login.php">Sign in</a></p>
<?php else: ?>
  <p class="muted">Account: <strong><?= V::h($user['username']) ?></strong></p>
  <form method="post"><?= Csrf::field() ?><input type="hidden" name="token" value="<?= V::h($token) ?>">
    <div class="field"><label for="new">New password</label><input type="password" id="new" name="new" required minlength="<?= (int) Policy::get('auth.min_password_length') ?>" autocomplete="new-password" autofocus data-strength></div>
    <div class="field"><label for="confirm">Confirm new password</label><input type="password" id="confirm" name="confirm" required autocomplete="new-password"></div>
    <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;padding:10px">Set password</button></form>
<?php endif; ?></section></div><?= V::authFoot() ?></body></html>
