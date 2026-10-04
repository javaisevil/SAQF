<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Csrf;
use Saqf\Security\Auth;
use Saqf\Security\Passkeys;

// JSON endpoint for passkeys (WebAuthn). Registration and removal need a signed-in person who confirmed
// their password recently; sign-in uses the pending second step of a password sign-in. Every call needs the
// anti-forgery token, and the proof itself is verified by Saqf\Security\WebAuthn.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function pk_out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode(\Saqf\Web\I18n::json($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    pk_out(['ok' => false, 'error' => 'POST required'], 405);
}
if (!Csrf::valid()) {
    pk_out(['ok' => false, 'error' => 'Security token expired. Reload the page.'], 403);
}
$raw = file_get_contents('php://input', false, null, 0, 16385);
$in = is_string($raw) && strlen($raw) <= 16384 ? json_decode($raw, true) : null;
if (!is_array($in)) {
    pk_out(['ok' => false, 'error' => 'The request could not be read.'], 400);
}
$action = (string) ($in['action'] ?? '');

try {
    switch ($action) {
        case 'login_options':
        case 'login_finish':
            $pending = Auth::mfaPending();
            if (!$pending) {
                pk_out(['ok' => false, 'error' => 'The sign-in expired. Enter your password again.', 'redirect' => 'login.php'], 401);
            }
            if ($action === 'login_options') {
                pk_out(['ok' => true, 'options' => Passkeys::loginOptions($pending)]);
            }
            $r = Auth::completePasskey(is_array($in['credential'] ?? null) ? $in['credential'] : []);
            pk_out($r['ok'] ? ['ok' => true, 'redirect' => 'index.php'] : ['ok' => false, 'error' => $r['message'], 'redirect' => Auth::mfaPending() ? null : 'login.php'], $r['ok'] ? 200 : 401);

        case 'register_options':
        case 'register_finish':
        case 'remove':
            $user = Auth::user();
            if (!$user) {
                pk_out(['ok' => false, 'error' => 'Your session has expired. Sign in again.'], 401);
            }
            if (!Auth::recentlyVerified()) {
                pk_out(['ok' => false, 'error' => 'For your security, sign out and sign in again before changing passkeys (your last confirmation is too old).'], 403);
            }
            if ($action === 'register_options') {
                pk_out(['ok' => true, 'options' => Passkeys::registerOptions($user)]);
            }
            if ($action === 'register_finish') {
                Passkeys::registerFinish($user, is_array($in['credential'] ?? null) ? $in['credential'] : [], (string) ($in['label'] ?? ''));
                pk_out(['ok' => true, 'message' => 'Passkey added.']);
            }
            Passkeys::remove($user, (int) ($in['id'] ?? 0));
            pk_out(['ok' => true, 'message' => 'Passkey removed.']);

        default:
            pk_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (InvalidArgumentException | RuntimeException $e) {
    pk_out(['ok' => false, 'error' => $e->getMessage()], 422);
}
