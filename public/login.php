<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Config;
use Saqf\Core\Csrf;
use Saqf\Demo\Story;
use Saqf\Security\Auth;
use Saqf\Security\Oidc;
use Saqf\Security\PasswordReset;
use Saqf\Web\View as V;

if (Auth::user()) {
    saqf_redirect('index.php');
}
$error = '';
$sso = Oidc::enabled();
$mode = Auth::passwordLoginMode();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $mode !== 'off') {
    if (!Csrf::valid()) {
        $error = 'The sign-in form expired. Please try again.';
    } else {
        $r = Auth::attempt((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
        if ($r['ok']) {
            saqf_redirect('index.php');
        }
        $error = $r['message'];
    }
}
$flash = V::flash();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · SAQF — Al Yamamah University</title><link rel="icon" href="assets/favicon.png"><link rel="stylesheet" href="assets/app.css?v=<?= SAQF_VERSION ?>"></head>
<body>
<div class="login">
  <section class="login-hero">
    <div>
      <img src="assets/yu-logo.png" alt="Al Yamamah University">
      <h1>SAQF — academic quality that runs itself, with people deciding what matters.</h1>
      <p>An academic-quality automation layer for Al Yamamah University: institutional data flows in, routine quality checks run continuously, and only genuine academic decisions and exceptions reach people.</p>
      <ul class="rules">
        <li><b>01</b> If the university already knows it, nobody types it.</li>
        <li><b>02</b> If software can validate it, QA does not check it by hand.</li>
        <li><b>03</b> If it can be calculated, nobody calculates it manually.</li>
        <li><b>04</b> People spend their time on academic judgement, exceptions and improvement.</li>
      </ul>
    </div>
    <p class="tiny">Supports NCAAA-oriented academic quality workflows.<?= Config::demoMode() ? ' Demo mode: people, teaching assignments and student results are fictional, delivered through simulated SIS and LMS feeds.' : ' Institutional data is synchronised from the university Registrar, SIS and LMS.' ?></p>
  </section>
  <section class="login-form">
    <h2>Sign in</h2>
    <p class="muted">Use your university account.</p>
    <?= $flash ?>
    <?php if ($error): ?><div class="alert alert-error" role="alert"><?= V::h($error) ?></div><?php endif; ?>
    <?php if ($sso): ?><a class="btn btn-primary" href="sso.php?start=1" style="width:100%;justify-content:center;padding:10px;margin-bottom:14px"><?= V::h(Oidc::buttonLabel()) ?></a><?php endif; ?>
    <?php if ($mode !== 'off'): ?>
    <?php if ($sso && $mode === 'admins'): ?><details<?= $error ? ' open' : '' ?>><summary class="small muted">Administrator sign-in (break-glass)</summary><?php endif; ?>
    <form method="post" autocomplete="on" id="loginForm">
      <?= Csrf::field() ?>
      <div class="field"><label for="username">Username</label><input type="text" id="username" name="username" required <?= $sso ? '' : 'autofocus' ?> autocomplete="username" value="<?= V::h($_POST['username'] ?? '') ?>"></div>
      <div class="field"><label for="password">Password</label><input type="password" id="password" name="password" required autocomplete="current-password"></div>
      <button class="btn <?= $sso ? '' : 'btn-primary' ?>" type="submit" style="width:100%;justify-content:center;padding:10px">Sign in<?= $sso ? ' with password' : '' ?></button>
    </form>
    <?php if (PasswordReset::available()): ?><p class="small" style="margin-top:10px"><a href="forgot.php">Forgot your password?</a></p><?php endif; ?>
    <?php if ($sso && $mode === 'admins'): ?></details><?php endif; ?>
    <?php endif; ?>
    <p class="tiny muted" style="margin-top:12px">Accounts lock temporarily after repeated failed attempts. Sessions expire after inactivity.<?= $sso ? ' Sign-in is handled by the university identity provider.' : '' ?></p>
    <?php if (Config::demoMode()): ?>
    <div class="demo-accounts">
      <strong>Demo accounts</strong> (fictional people · password <code><?= V::h(Story::PASSWORD) ?></code>)
      <div class="acc">
        <?php foreach (Story::USERS as [$u, $name, $title, $role]): ?>
          <div><button type="button" onclick="document.getElementById('username').value='<?= V::h($u) ?>';document.getElementById('password').value='<?= V::h(Story::PASSWORD) ?>';document.getElementById('loginForm').submit()"><?= V::h($u) ?></button><small><?= V::h(Auth::ROLES[$role]) ?> · <?= V::h($name) ?></small></div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </section>
</div>
</body></html>
