<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Csrf;
use Saqf\Core\Policy;
use Saqf\Security\Auth;

// Session timer for the page script. GET only reports the seconds left without counting as activity
// (so a tab left open does not keep the session alive); POST with the page's anti-forgery token is
// "Stay signed in" and resets the idle timer.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$idle = (int) Policy::get('session.idle_minutes') * 60;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Csrf::valid() || !Auth::user()) {
        http_response_code(401);
        echo json_encode(['signedIn' => false, 'remaining' => 0]);
        exit;
    }
    echo json_encode(['signedIn' => true, 'remaining' => $idle]);
    exit;
}
$signedIn = !empty($_SESSION['uid']);
$left = $signedIn ? max(0, $idle - (time() - (int) ($_SESSION['seen_at'] ?? 0))) : 0;
echo json_encode(['signedIn' => $signedIn && $left > 0, 'remaining' => $left]);
