<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Csrf;
use Saqf\Security\Auth;

// Sign-out is POST + CSRF only, so a third-party page cannot log users out.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::valid()) {
    Auth::logout();
}
saqf_redirect('login.php');
