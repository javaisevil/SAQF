<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Audit;
use Saqf\Core\Session;
use Saqf\Security\Auth;
use Saqf\Security\Oidc;
use Saqf\Security\SsoException;
use Saqf\Web\View as V;

// University single sign-on (OpenID Connect): ?start=1 sends the browser to the identity
// provider; the provider redirects back here with an authorization code.
if (!Oidc::enabled()) {
    http_response_code(404);
    exit('University sign-in is not configured.');
}
if (isset($_GET['start'])) {
    try {
        saqf_redirect(Oidc::begin());
    } catch (SsoException $e) {
        Session::flash('error', $e->getMessage());
        saqf_redirect('login.php');
    }
}

$claims = [];
try {
    $claims = Oidc::complete($_GET);
    $user = Auth::ssoUser($claims);
    Auth::login($user, 'sso');
    Auth::ssoSucceeded((string) $user['username']);
    $target = 'index.php';
} catch (SsoException $e) {
    Auth::ssoFailed($e->getMessage(), (string) ($claims['preferred_username'] ?? $claims['email'] ?? ''));
    Audit::asSystem(static fn() => Audit::record('security.sso_refused', 'auth', null, 'University sign-in refused: ' . mb_substr($e->getMessage(), 0, 300)), 'integration', 'University SSO');
    Session::flash('error', $e->getMessage());
    $target = 'login.php';
}
// Continue with a same-site navigation (not an HTTP redirect) so the browser sends the
// SameSite=Strict session cookie on the next page.
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=' . V::h($target) . '"><title>Signing in · SAQF</title></head>'
    . '<body style="font-family:system-ui;margin:60px auto;max-width:420px">Signing you in… <a href="' . V::h($target) . '">Continue</a></body></html>';
