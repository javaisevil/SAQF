<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Config;
use Saqf\Core\Csrf;
use Saqf\Core\Policy;
use Saqf\Core\Secrets;
use Saqf\Security\Auth;
use Saqf\Security\Mfa;
use Saqf\Security\TrustedDevices;
use Saqf\Security\Totp;
use Saqf\Web\View as V;

// Second step of a password sign-in: a code e-mailed to the person, or one from their authenticator app
// (or a recovery code).
if (Auth::user()) {
    saqf_redirect('index.php');
}
$pending = Auth::mfaPending();
if (!$pending) {
    \Saqf\Core\Session::flash('info', 'The sign-in expired. Enter your password again.');
    saqf_redirect('login.php');
}
$error = '';
$notice = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $op = (string) ($_POST['op'] ?? 'verify');
    if (!Csrf::valid()) {
        $error = 'The form expired. Please try again.';
    } elseif ($op === 'resend' || $op === 'use_email' || $op === 'use_app') {
        $err = $op === 'resend' ? Mfa::sendEmailCode($pending) : Auth::switchMfaMethod($op === 'use_app' ? 'app' : 'email');
        if ($err) {
            $error = $err;
        } else {
            $notice = $op === 'use_app' ? 'Enter the code from your authenticator app.' : 'A new code is on its way to your e-mail.';
        }
    } else {
        $r = Auth::completeMfa((string) ($_POST['code'] ?? ''), ($_POST['trust'] ?? '') === '1');
        if ($r['ok']) {
            saqf_redirect('index.php');
        }
        $error = $r['message'];
        if (!Auth::mfaPending()) {
            \Saqf\Core\Session::flash('error', $error);
            saqf_redirect('login.php');
        }
    }
}
$method = Auth::mfaMethod();
$email = $_SESSION['mfa_pending']['email'] ?? null;
$canEmail = Mfa::emailAllowed($pending);
$canApp = Mfa::enabled($pending);
$canTrust = TrustedDevices::allowed($pending);
// Demo mode only: show what the phone app would display, or the e-mail that would arrive, so the step can be demonstrated.
$demoApp = Config::demoMode() && $method === 'app' && $canApp ? Totp::code((string) Secrets::decrypt((string) $pending['mfa_secret'])) : null;
$demoMail = Config::demoMode() && $method === 'email' && $email ? ($email['demo'] ?? null) : null;
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Two-step verification · SAQF</title><link rel="icon" href="assets/favicon.png"><link rel="stylesheet" href="assets/app.css?v=<?= SAQF_VERSION ?>"></head>
<body><div class="login"><section class="login-hero"><div><img src="assets/yu-logo.png" alt="Al Yamamah University"><h1>Two-step verification</h1>
<p>Your password was correct. To finish signing in, prove it is really you with a second step: a one-time code <?= $method === 'email' ? 'we just e-mailed to your university address' : 'from your authenticator app (Microsoft Authenticator, Google Authenticator or similar)' ?>.</p>
<p>A stolen password alone is not enough to open your account: the thief would also need your <?= $method === 'email' ? 'e-mail' : 'phone' ?>.</p>
<ol class="steps-done"><li class="done">Password</li><li class="done">Robot check</li><li class="now">Code</li></ol></div></section>
<section class="login-form"><div class="login-lang"><?= \Saqf\Web\I18n::switchLink() ?></div><h2>Enter your code</h2>
<p class="muted">Signing in as <strong><?= V::h($pending['username']) ?></strong></p>
<?php if ($error): ?><div class="alert alert-error" role="alert"><?= V::h($error) ?></div><?php endif; ?>
<?php if ($notice): ?><div class="alert alert-success" role="status"><?= V::h($notice) ?></div><?php endif; ?>
<?php if ($method === 'email' && $email): ?><p class="small">We sent a 6-digit code to <strong translate="no"><?= V::h($email['to']) ?></strong>. It is valid for 10 minutes.</p>
<?php elseif ($method === 'app'): ?><p class="small">Open your authenticator app and enter the 6-digit code shown for SAQF. Lost your phone? Enter one of your recovery codes instead.</p><?php endif; ?>
<form method="post" autocomplete="off" data-protected><?= Csrf::field() ?><input type="hidden" name="op" value="verify">
  <div class="field"><label for="code">Verification code</label><input type="text" id="code" name="code" required autofocus inputmode="numeric" autocomplete="one-time-code" maxlength="12" placeholder="123456" class="otp" dir="ltr"></div>
  <?php if ($canTrust): ?><label class="row small" style="gap:8px;margin:-4px 0 12px"><input type="checkbox" name="trust" value="1" style="width:auto"> Don't ask again on this browser for <?= (int) Policy::get('auth.trusted_device_days') ?> days</label><?php endif; ?>
  <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;padding:10px">Verify and sign in</button></form>
<div class="mfa-alt small">
  <?php if ($method === 'email'): ?><form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="resend"><button class="linkbtn" type="submit">Send a new code</button></form><?php endif; ?>
  <?php if ($method === 'email' && $canApp): ?><form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="use_app"><button class="linkbtn" type="submit">Use my authenticator app instead</button></form><?php endif; ?>
  <?php if ($method === 'app' && $canEmail): ?><form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="use_email"><button class="linkbtn" type="submit">E-mail me a code instead</button></form><?php endif; ?>
  <a href="login.php">Cancel and start again</a>
</div>
<?php if ($demoMail): ?><div class="demo-mail" aria-label="Demo mailbox"><div class="demo-mail-h"><strong>Demo mailbox</strong><span class="tiny">what <?= V::h($pending['full_name']) ?> receives</span></div>
  <div class="demo-mail-b"><div class="tiny muted">To: <span translate="no"><?= V::h($pending['email']) ?></span> · Subject: Your SAQF sign-in code</div><div class="demo-mail-code" translate="no" dir="ltr"><?= V::h(substr($demoMail, 0, 3) . ' ' . substr($demoMail, 3)) ?></div><div class="tiny muted">Shown only in demo mode, because no mail server is connected. In production the code arrives only in the person's university mailbox.</div></div></div><?php endif; ?>
<?php if ($demoApp): ?><div class="demo-accounts"><strong>Demo mode</strong> — the code the authenticator app would show right now: <code style="font-size:16px" dir="ltr"><?= V::h(substr($demoApp, 0, 3) . ' ' . substr($demoApp, 3)) ?></code><div class="tiny muted">Shown only in demo mode. In production the code comes only from the person's phone.</div></div><?php endif; ?>
<p class="tiny muted" style="margin-top:12px">SAQF staff will never ask you for this code. After 5 wrong codes the sign-in starts again.</p>
</section></div><?= V::authFoot() ?></body></html>
