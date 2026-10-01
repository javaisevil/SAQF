<?php
declare(strict_types=1);

// Health probe for load balancers, uptime monitors and container orchestrators.
// No session, no authentication, nothing sensitive: 200 when SAQF can serve requests, 503 otherwise.
define('SAQF_STATELESS', true);
require dirname(__DIR__) . '/src/bootstrap.php';

use Saqf\Core\Clock;
use Saqf\Core\Db;

header('Content-Type: application/json');
header('Cache-Control: no-store');
$out = ['status' => 'ok', 'version' => SAQF_VERSION, 'database' => 'ok', 'scheduler' => 'unknown', 'maintenance' => false];
try {
    Db::val('SELECT 1');
    $last = Db::val('SELECT value FROM system_settings WHERE setting_key = "scheduler.last_tick"');
    $age = $last ? Clock::now()->getTimestamp() - strtotime((string) $last) : null;
    // A stalled scheduler is reported, not treated as down: pages keep working while it is fixed.
    $out['scheduler'] = $age === null ? 'never run' : ($age <= 1200 ? 'ok' : 'stale');
    $out['maintenance'] = Db::val('SELECT value FROM system_settings WHERE setting_key = "maintenance_mode"') === '1';
} catch (Throwable $e) {
    $out['status'] = 'unavailable';
    $out['database'] = 'unreachable';
    http_response_code(503);
}
echo json_encode($out);
