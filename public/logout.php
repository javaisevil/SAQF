<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Csrf;
use Saqf\Security\Auth;
use Saqf\Security\Oidc;

// Sign-out is POST + CSRF only, so a third-party page cannot log users out.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::valid()) {
    $viaSso = ($_SESSION['auth'] ?? '') === 'sso';
    Auth::logout();
    // Also end the university identity-provider session when SAQF was entered through it.
    if ($viaSso && ($url = Oidc::logoutUrl())) {
        saqf_redirect($url);
    }
}
saqf_redirect('login.php');
