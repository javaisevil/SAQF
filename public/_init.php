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
    // A body bigger than post_max_size arrives empty: say so, instead of blaming an expired form.
    if (!$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        Session::flash('error', 'That upload is bigger than the server accepts (' . ini_get('post_max_size') . ' at a time). Add fewer or smaller files and try again.');
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
        exit;
    }
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

/**
 * Safety-net heartbeat: in production the scheduler runs from the container loop or cron, so a page
 * request only runs it when that has stopped for 15 minutes (demo: at most every 5 minutes).
 */
function saqf_heartbeat(): void
{
    try {
        Scheduler::checkHeartbeat();
        Scheduler::tick(false, \Saqf\Core\Config::demoMode() ? 300 : 900);
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
