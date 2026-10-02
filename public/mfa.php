<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Config;
use Saqf\Core\Csrf;
use Saqf\Core\Secrets;
use Saqf\Security\Auth;
use Saqf\Security\Totp;
use Saqf\Web\View as V;

// Second step of a password sign-in: a code from the authenticator app (or a recovery code).
if (Auth::user()) {
    saqf_redirect('index.php');
}
$pending = Auth::mfaPending();
if (!$pending) {
    saqf_redirect('login.php');
}
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Csrf::valid()) {
        $error = 'The form expired. Please try again.';
    } else {
        $r = Auth::completeMfa((string) ($_POST['code'] ?? ''));
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
// Demo mode only: show the code an authenticator app would display, so the step can be demonstrated.
$demoCode = Config::demoMode() ? Totp::code((string) Secrets::decrypt((string) $pending['mfa_secret'])) : null;
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Two-step verification · SAQF</title><link rel="icon" href="assets/favicon.png"><link rel="stylesheet" href="assets/app.css?v=<?= SAQF_VERSION ?>"></head>
<body><div class="login"><section class="login-hero"><div><img src="assets/yu-logo.png" alt="Al Yamamah University"><h1>Two-step verification</h1>
<p>Your password was correct. To finish signing in, enter the 6-digit code shown in your authenticator app (Microsoft Authenticator, Google Authenticator or similar). Lost your phone? Use one of your recovery codes.</p></div></section>
<section class="login-form"><div class="login-lang"><?= \Saqf\Web\I18n::switchLink() ?></div><h2>Enter your code</h2>
<p class="muted">Signing in as <strong><?= V::h($pending['username']) ?></strong></p>
<?php if ($error): ?><div class="alert alert-error" role="alert"><?= V::h($error) ?></div><?php endif; ?>
<form method="post" autocomplete="off"><?= Csrf::field() ?>
  <div class="field"><label for="code">Verification code</label><input type="text" id="code" name="code" required autofocus inputmode="numeric" autocomplete="one-time-code" maxlength="12" placeholder="123456" style="font-size:22px;letter-spacing:4px;text-align:center"></div>
  <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;padding:10px">Verify and sign in</button></form>
<?php if ($demoCode): ?><div class="demo-accounts"><strong>Demo mode</strong> — the code the authenticator app would show right now: <code style="font-size:16px"><?= V::h(substr($demoCode, 0, 3) . ' ' . substr($demoCode, 3)) ?></code><div class="tiny muted">Shown only in demo mode. In production the code comes only from the person's phone.</div></div><?php endif; ?>
<p class="small" style="margin-top:12px"><a href="login.php">Cancel and start again</a></p></section></div></body></html>
