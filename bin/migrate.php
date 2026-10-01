<?php
declare(strict_types=1);

/**
 * Applies pending database migrations (database/migrations/*.sql). Safe to run on every
 * deployment and container start: already-applied migrations are skipped.
 *
 *   php bin/migrate.php            apply pending migrations
 *   php bin/migrate.php --status   list pending migrations without applying them
 */

require __DIR__ . '/../src/bootstrap.php';

use Saqf\Core\Db;
use Saqf\Core\Migrations;

try {
    if (!Db::val('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = "users"')) {
        fwrite(STDERR, "SAQF is not installed in this database yet — run php bin/install.php first.\n");
        exit(3);
    }
    if (in_array('--status', array_slice($argv, 1), true)) {
        $pending = Migrations::pending();
        echo $pending ? "Pending migrations:\n  " . implode("\n  ", $pending) . "\n" : "Database schema is up to date.\n";
        exit(0);
    }
    $done = Migrations::run(static fn(string $name) => print("Applied $name\n"));
    echo $done ? count($done) . " migration(s) applied.\n" : "Database schema is up to date.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
