<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Config;
use Saqf\Core\Csrf;
use Saqf\Core\Policy;
use Saqf\Demo\Story;
use Saqf\Security\Auth;
use Saqf\Security\BotGuard;
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
    $username = (string) ($_POST['username'] ?? '');
    if (!Csrf::valid()) {
        $error = 'The sign-in form expired. Please try again.';
    } elseif ($why = BotGuard::verify('login', $_POST)) {
        Auth::refuse($username, $why);
        $error = $why === 'bot_expired' ? 'The robot check expired. Please sign in again.' : 'Please confirm you are not a robot (tick the box above the Sign in button), then sign in again.';
    } else {
        $r = Auth::attempt($username, (string) ($_POST['password'] ?? ''));
        if ($r['ok']) {
            saqf_redirect(!empty($r['mfa']) ? 'mfa.php' : 'index.php');
        }
        $error = $r['message'];
    }
}
$flash = V::flash();
$mfaAll = Policy::get('auth.mfa_required') === 'all';
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · SAQF — Al Yamamah University</title><link rel="icon" href="assets/favicon.png"><link rel="stylesheet" href="assets/app.css?v=<?= SAQF_VERSION ?>"></head>
<body>
<a class="skip" href="#loginForm">Skip to the sign-in form</a>
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
      <div class="trust">
        <div><?= V::icon('lock') ?><span><strong>Two-step sign-in</strong><?= $mfaAll ? 'A code by e-mail or from an authenticator app after your password' : 'Required for administrators, available to everyone' ?></span></div>
        <div><?= V::icon('shield') ?><span><strong>Robot check</strong>Private, self-hosted; stops automated password guessing</span></div>
        <div><?= V::icon('list') ?><span><strong>Every sign-in recorded</strong>See your own sign-in history under Account &amp; security</span></div>
      </div>
    </div>
    <p class="tiny">Supports NCAAA-oriented academic quality workflows.<?= Config::demoMode() ? ' Demo mode: people, teaching assignments and student results are fictional, delivered through simulated SIS and LMS feeds.' : ' Institutional data is synchronised from the university Registrar, SIS and LMS.' ?></p>
  </section>
  <section class="login-form">
    <div class="login-lang"><?= \Saqf\Web\I18n::switchLink() ?></div>
    <h2>Sign in</h2>
    <p class="muted">Use your university account.</p>
    <?= $flash ?>
    <?php if ($error): ?><div class="alert alert-error" role="alert"><?= V::h($error) ?></div><?php endif; ?>
    <?php if ($sso): ?><a class="btn btn-primary" href="sso.php?start=1" style="width:100%;justify-content:center;padding:10px;margin-bottom:14px"><?= V::h(Oidc::buttonLabel()) ?></a><?php endif; ?>
    <?php if ($mode !== 'off'): ?>
    <?php if ($sso && $mode === 'admins'): ?><details<?= $error ? ' open' : '' ?>><summary class="small muted">Administrator sign-in (break-glass)</summary><?php endif; ?>
    <form method="post" autocomplete="on" id="loginForm" data-protected>
      <?= Csrf::field() ?>
      <div class="field"><label for="username">Username</label><input type="text" id="username" name="username" required <?= $sso ? '' : 'autofocus' ?> autocomplete="username" autocapitalize="none" spellcheck="false" value="<?= V::h($_POST['username'] ?? '') ?>"></div>
      <div class="field"><label for="password">Password</label><input type="password" id="password" name="password" required autocomplete="current-password"></div>
      <?= BotGuard::field('login') ?>
      <button class="btn <?= $sso ? '' : 'btn-primary' ?>" type="submit" style="width:100%;justify-content:center;padding:10px">Sign in<?= $sso ? ' with password' : '' ?></button>
    </form>
    <?php if (PasswordReset::available()): ?><p class="small" style="margin-top:10px"><a href="forgot.php">Forgot your password?</a></p><?php endif; ?>
    <?php if ($sso && $mode === 'admins'): ?></details><?php endif; ?>
    <?php endif; ?>
    <p class="tiny muted" style="margin-top:12px">Accounts lock for <?= (int) Policy::get('auth.lockout_minutes') ?> minutes after <?= (int) Policy::get('auth.max_failed_logins') ?> wrong passwords. You are signed out after <?= (int) Policy::get('session.idle_minutes') ?> minutes without activity.<?= $sso ? ' Sign-in is handled by the university identity provider.' : '' ?></p>
    <?php if (Config::demoMode()): $byName = []; foreach (Story::USERS as $row) { $byName[$row[0]] = $row; } ?>
    <div class="demo-accounts">
      <div class="row between"><strong>Demo accounts</strong><a class="btn btn-sm btn-primary" href="tour.php">Guided 5-minute tour</a></div>
      <div class="tiny muted" style="margin:4px 0 8px">Fictional people. <strong>Use this account</strong> fills in the form above, so you sign in the real way: password, robot check, then a two-step code (e-mailed to the person; in demo mode the e-mail is shown on the next screen). Password for every account: <code><?= V::h(Story::PASSWORD) ?></code></div>
      <div class="acc">
        <?php foreach (Story::ROLE_ACCOUNTS as $u): [, $name, , $role] = $byName[$u]; ?>
          <div class="acc-item"><button type="button" class="acc-fill" data-fill-user="<?= V::h($u) ?>" data-fill-pass="<?= V::h(Story::PASSWORD) ?>"><?= V::h(Auth::ROLES[$role]) ?></button><small><?= V::h($name) ?> · <?= V::h($u) ?></small>
            <form method="post" action="demo.php"><?= Csrf::field() ?><input type="hidden" name="as" value="<?= V::h($u) ?>"><button type="submit" class="linkbtn tiny" title="Demo shortcut: signs in without password, robot check or code">skip sign-in ›</button></form></div>
        <?php endforeach; ?>
      </div>
      <div class="tiny muted" style="margin-top:6px">Also in the story: <?php foreach (array_diff(array_keys($byName), Story::ROLE_ACCOUNTS) as $u): ?><span class="mono"><?= V::h($u) ?></span> (<?= V::h($byName[$u][1]) ?>) <?php endforeach; ?></div>
    </div>
    <?php endif; ?>
  </section>
</div>
<?= V::authFoot() ?>
</body></html>
