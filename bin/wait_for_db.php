<?php
declare(strict_types=1);

// Waits (up to SAQF_DB_WAIT seconds, default 90) until the MySQL server accepts connections.
// Used by the Docker entrypoint so the app never starts against a database that is still booting.

require __DIR__ . '/../src/bootstrap.php';

use Saqf\Core\Config;

$deadline = time() + (int) Config::get('SAQF_DB_WAIT', '90');
$dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', Config::get('SAQF_DB_HOST', '127.0.0.1'), Config::get('SAQF_DB_PORT', '3306'));
do {
    try {
        new PDO($dsn, (string) Config::get('SAQF_DB_USER', 'root'), (string) Config::get('SAQF_DB_PASS', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
        echo "Database server is reachable.\n";
        exit(0);
    } catch (PDOException $e) {
        fwrite(STDERR, "Waiting for the database server… ({$e->getMessage()})\n");
        sleep(2);
    }
} while (time() < $deadline);
fwrite(STDERR, "Database server not reachable after waiting; giving up.\n");
exit(1);
