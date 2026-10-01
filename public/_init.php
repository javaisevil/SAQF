<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Saqf\Core\Csrf;
use Saqf\Core\Db;
use Saqf\Core\ErrorLog;
use Saqf\Core\Session;
use Saqf\Security\Authz;
use Saqf\Quality\Scheduler;

/** Maintenance mode: only administrators can use the system while it is on. */
function saqf_maintenance(): bool
{
    try {
        return Db::val('SELECT value FROM system_settings WHERE setting_key = "maintenance_mode"') === '1';
    } catch (Throwable $e) {
        return false;
    }
}

/** Every state-changing form must carry a valid CSRF token. */
function saqf_require_post(): void
{
    if (!Csrf::valid()) {
        Session::flash('error', 'Your form expired for security reasons. Please try again.');
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
        exit;
    }
}

function saqf_redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

/** Opportunistic background heartbeat (guarded to run at most every 5 minutes). */
function saqf_heartbeat(): void
{
    try {
        Scheduler::tick();
    } catch (Throwable $e) {
        ErrorLog::record($e, 'warning');
    }
}

function saqf_page(array $roles = []): array
{
    $user = \Saqf\Security\Auth::require($roles);
    if (saqf_maintenance() && $user['role'] !== 'admin') {
        http_response_code(503);
        echo '<!doctype html><meta charset="utf-8"><title>SAQF maintenance</title><div style="font-family:system-ui;max-width:520px;margin:90px auto;padding:24px;border:1px solid #e5e7eb;border-radius:12px"><h2>SAQF is under maintenance</h2><p>The system administrator has temporarily paused access. No data is lost; please try again shortly.</p></div>';
        exit;
    }
    saqf_heartbeat();
    return $user;
}
