<?php
declare(strict_types=1);

/**
 * Re-computes the SHA-256 hash chain over the whole audit log.
 * Exit code 0 = intact, 2 = tampering detected. Suitable for a nightly cron / monitoring probe
 * and for verifying a restored backup.
 *
 *   php bin/verify_audit.php
 */

require __DIR__ . '/../src/bootstrap.php';

use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Db;

$v = Audit::verify();
Db::exec(
    'INSERT INTO system_settings (setting_key, value, updated_at) VALUES ("audit.last_verify", ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
    [json_encode($v + ['at' => Clock::stamp()]), Clock::stamp()]
);
Audit::asSystem(static fn() => Audit::record('audit.verified', 'audit_log', null, 'Audit chain verification (CLI): ' . ($v['ok'] ? 'intact' : 'BROKEN') . " ({$v['checked']} entries)"));
echo $v['message'] . "\n";
exit($v['ok'] ? 0 : 2);
