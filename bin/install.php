<?php
declare(strict_types=1);

/**
 * SAQF installer.
 *
 *   php bin/install.php --demo            create schema, sync YU data, replay the demo story
 *   php bin/install.php --demo --fresh    drop and recreate the database first (DESTROYS DATA)
 *   php bin/install.php                   production-style: schema + institutional sync + one admin account
 *   add --skip-if-installed               exit quietly (code 0) when the database already has tables (container start-up)
 *
 * Connection settings come from environment variables / config.local.php (see README).
 */

require __DIR__ . '/../src/bootstrap.php';

use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Config;
use Saqf\Core\Db;
use Saqf\Core\Migrations;
use Saqf\Core\Policy;
use Saqf\Demo\Seeder;
use Saqf\Integration\Sync;

$args = array_slice($argv, 1);
$demo = in_array('--demo', $args, true);
$fresh = in_array('--fresh', $args, true);
$skipIfInstalled = in_array('--skip-if-installed', $args, true);
$t0 = microtime(true);

$name = (string) Config::get('SAQF_DB_NAME', 'saqf');
if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
    fwrite(STDERR, "Invalid database name\n");
    exit(1);
}
$server = new PDO(
    sprintf('mysql:host=%s;port=%s;charset=utf8mb4', Config::get('SAQF_DB_HOST', '127.0.0.1'), Config::get('SAQF_DB_PORT', '3306')),
    (string) Config::get('SAQF_DB_USER', 'root'),
    (string) Config::get('SAQF_DB_PASS', ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$exists = (bool) $server->query("SHOW DATABASES LIKE " . $server->quote($name))->fetchColumn();
if ($exists && !$fresh) {
    $hasTables = (bool) $server->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = " . $server->quote($name))->fetchColumn();
    if ($hasTables && $skipIfInstalled) {
        echo "Database `$name` is already installed — nothing to do.\n";
        exit(0);
    }
    if ($hasTables) {
        fwrite(STDERR, "Database `$name` already contains tables. Re-run with --fresh to rebuild it (this deletes its data).\n");
        exit(1);
    }
}
// The catalogue is checked before anything is written or dropped. Otherwise a faulty Registrar export would stop the
// install after the schema but before the administrator account, and later starts would skip that database
// as "already installed", leaving SAQF with nobody who can sign in.
$catalogue = \Saqf\Integration\CatalogFileSource::defaultDir();
$pack = \Saqf\Integration\DataPack::inspect($catalogue);
if (!$pack['ok']) {
    fwrite(STDERR, "The institutional catalogue in $catalogue cannot be used, so nothing was installed.\n");
    foreach (array_slice($pack['errors'], 0, 5) as $e) {
        fwrite(STDERR, "  PROBLEM  $e\n");
    }
    fwrite(STDERR, "Fix the export (php bin/pack.php validate lists every problem) and start again.\n");
    exit(1);
}

if ($fresh && $exists) {
    if (Config::env() === 'production') {
        fwrite(STDERR, "--fresh is disabled when APP_ENV=production.\n");
        exit(1);
    }
    $server->exec("DROP DATABASE `$name`");
    echo "Dropped database `$name`\n";
    // Evidence files belonged to the dropped database: remove them too (only SAQF's own random names).
    $evidenceDir = \Saqf\Quality\Evidence::dir();
    foreach (glob($evidenceDir . '/[0-9a-f][0-9a-f]/*') ?: [] as $f) {
        if (preg_match('/^[0-9a-f]{40}$/', basename($f))) {
            @unlink($f);
        }
    }
}
// A production installation needs its application key from the environment before anything is
// written: SAQF never generates or stores a production key (see bin/app_key.php).
if (Config::env() === 'production') {
    $appKey = (string) Config::get('SAQF_APP_KEY', '');
    $problem = $appKey === '' ? 'it is not set' : \Saqf\Core\Secrets::keyProblem($appKey);
    if ($problem !== null) {
        fwrite(STDERR, "SAQF_APP_KEY is required in production and $problem.\n"
            . "Generate one with: php bin/app_key.php generate   — keep it in the password vault, then set SAQF_APP_KEY.\n");
        exit(1);
    }
}

$server->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "Database `$name` ready\n";

$sql = (string) file_get_contents(SAQF_ROOT . '/database/schema.sql');
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
foreach (array_filter(array_map('trim', explode(';', (string) $sql))) as $statement) {
    Db::pdo()->exec($statement);
}
$guarded = true;
foreach (['UPDATE', 'DELETE'] as $op) {
    try {
        Db::pdo()->exec("CREATE TRIGGER audit_log_no_" . strtolower($op) . " BEFORE $op ON audit_log FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only'; END");
    } catch (PDOException $e) {
        $guarded = false;
        fwrite(STDERR, "WARNING: could not create the append-only trigger on audit_log ({$e->getMessage()}).\n"
            . "         With binary logging enabled, MySQL needs log_bin_trust_function_creators=1 (or a privileged user) to create triggers.\n"
            . "         The hash chain still detects tampering; ask your DBA to install the triggers from database/audit_guard.sql.\n");
    }
}
echo 'Schema applied' . ($guarded ? ' (audit log protected as append-only)' : '') . "\n";

$migrated = Migrations::run();
echo count($migrated) . " migration(s) applied\n";
Policy::seedDefaults();
Db::exec('INSERT INTO system_settings (setting_key, value, updated_at) VALUES ("installed_at", ?, ?)', [date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);

if ($demo) {
    Clock::set('2025-06-01 09:00:00');
}
Audit::asSystem(static fn() => Audit::record('system.installed', 'system', null, 'SAQF ' . SAQF_VERSION . ' installed' . ($demo ? ' with demo scenario' : '')));
$stats = Sync::institution();
echo "Institutional data synced: {$stats['programs']} programs, {$stats['courses']} courses, {$stats['plan_entries']} study-plan entries, {$stats['requisites']} prerequisite links, {$stats['plos']} PLOs, {$stats['conflicts']} source conflict(s)\n";

if ($demo) {
    $seeder = new Seeder();
    $seeder->createUsers();
    Sync::terms();
    echo "Replaying the demo scenario through the live engine…\n";
    $seeder->run();
    if (Config::get('SAQF_DEMO_CLOCK') !== 'real') {
        Clock::anchorDemo(\Saqf\Demo\Story::DEMO_NOW);
        echo "Demo clock anchored at " . \Saqf\Demo\Story::DEMO_NOW . " (Fall 2026, week 6) and running forward from now. Set SAQF_DEMO_CLOCK=real to use the real date.\n";
    }
} else {
    Sync::terms();
    $password = rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'Ab'), '=') . '7';
    Db::insert('users', ['username' => 'admin', 'password_hash' => \Saqf\Security\Auth::hash($password), 'full_name' => 'System Administrator', 'role' => 'admin', 'status' => 'active', 'must_change_password' => 1, 'created_at' => date('Y-m-d H:i:s')]);
    echo "\nAdministrator account created.\n  username: admin\n  password: $password   (shown once — you must change it at first sign-in)\n";
}

$verify = Audit::verify();
printf("\nDone in %.1fs. %s\n", microtime(true) - $t0, $verify['message']);
if ($demo) {
    echo "Demo accounts use the password: " . \Saqf\Demo\Story::PASSWORD . "  (fictional accounts — never use demo mode in production)\n";
}
