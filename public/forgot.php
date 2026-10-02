<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Csrf;
use Saqf\Core\Session;
use Saqf\Security\Auth;
use Saqf\Security\PasswordReset;
use Saqf\Web\View as V;

if (Auth::user()) {
    saqf_redirect('index.php');
}
if (!PasswordReset::available()) {
    Session::flash('info', 'Password reset by e-mail is not enabled. Contact the SAQF administrator.');
    saqf_redirect('login.php');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    saqf_require_post();
    PasswordReset::request((string) ($_POST['identifier'] ?? ''));
    // Same answer whether or not the account exists.
    Session::flash('info', 'If an account matches, a reset link has been e-mailed to its address. The link is valid for ' . PasswordReset::RESET_MINUTES . ' minutes.');
    saqf_redirect('login.php');
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reset password · SAQF</title><link rel="icon" href="assets/favicon.png"><link rel="stylesheet" href="assets/app.css?v=<?= SAQF_VERSION ?>"></head>
<body><div class="login"><section class="login-hero"><div><img src="assets/yu-logo.png" alt="Al Yamamah University"><h1>Reset your SAQF password</h1>
<p>Enter your username or university e-mail address. If it matches an account, SAQF e-mails you a link to choose a new password.</p></div></section>
<section class="login-form"><div class="login-lang"><?= \Saqf\Web\I18n::switchLink() ?></div><h2>Forgot password</h2><?= V::flash() ?>
<form method="post"><?= Csrf::field() ?>
  <div class="field"><label for="identifier">Username or e-mail</label><input type="text" id="identifier" name="identifier" required autofocus autocomplete="username"></div>
  <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;padding:10px">E-mail me a reset link</button></form>
<p class="small" style="margin-top:12px"><a href="login.php">Back to sign-in</a></p></section></div></body></html>
