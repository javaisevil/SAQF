<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Audit;
use Saqf\Core\Config;
use Saqf\Core\Csrf;
use Saqf\Core\Db;
use Saqf\Demo\Story;
use Saqf\Security\Auth;

// Demo mode only: one-click sign-in as one of the fictional demo accounts (no password), used by the
// role buttons, the role switcher and the judges' tour. In production this endpoint does not exist.
if (!Config::demoMode()) {
    Audit::asSystem(static fn() => Audit::record('security.demo_refused', 'access', null, 'Demo sign-in attempted while demo mode is off'));
    http_response_code(404);
    exit('Not found');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !Csrf::valid()) {
    saqf_redirect('login.php');
}
$username = (string) ($_POST['as'] ?? '');
if (!in_array($username, array_column(Story::USERS, 0), true)) {
    saqf_redirect('login.php');
}
$next = (string) ($_POST['next'] ?? '');
if (!preg_match('/^[a-z_]+\.php(\?[A-Za-z0-9=&_%.\-]*)?$/', $next)) {
    $next = 'index.php';
}
$target = Db::one('SELECT * FROM users WHERE username = ? AND status <> "disabled"', [$username]);
if (!$target) {
    saqf_redirect('login.php');
}
if (Auth::user()) {
    Auth::logout('switching demo role');
    \Saqf\Core\Session::start();
}
Auth::login($target, 'demo');
saqf_redirect($next);
