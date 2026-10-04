<?php
declare(strict_types=1);

/**
 * Application key (SAQF_APP_KEY) helper for IT. Nothing here rotates or replaces a key in use.
 *
 *   php bin/app_key.php status          where the key comes from and whether production accepts it (never prints it)
 *   php bin/app_key.php generate        prints a new random key for a NEW installation (stores nothing)
 *   php bin/app_key.php export-stored   prints the key an older installation kept in the database, so IT can
 *                                       move it to the password vault and SAQF_APP_KEY (the same key, so every
 *                                       pseudonym and encrypted secret keeps working)
 *   php bin/app_key.php forget-stored   removes that database copy, only once SAQF_APP_KEY holds the same key
 *
 * Changing the key of an installation that has data is not supported: every student pseudonym would
 * change and stored two-step verification keys could no longer be read (docs/OPERATIONS.md).
 */

require __DIR__ . '/../src/bootstrap.php';

use Saqf\Core\Audit;
use Saqf\Core\Config;
use Saqf\Core\Db;
use Saqf\Core\Secrets;

$cmd = $argv[1] ?? 'status';
switch ($cmd) {
    case 'generate':
        echo bin2hex(random_bytes(32)), "\n";
        fwrite(STDERR, "Put this value in SAQF_APP_KEY (and the university password vault) BEFORE the first production install.\n"
            . "Do not use it to replace the key of an installation that already has data.\n");
        exit(0);

    case 'status':
        $s = Secrets::keyStatus();
        echo 'Environment: ' . Config::env() . "\n";
        echo 'Key source:  ' . $s['source'] . "\n";
        echo 'Status:      ' . ($s['ok'] === true ? 'ready' : ($s['ok'] === false ? 'NOT READY' : 'development')) . "\n";
        echo $s['message'] . "\n";
        exit($s['ok'] === false ? 2 : 0);

    case 'export-stored':
        $stored = Secrets::storedKey();
        if ($stored === null) {
            fwrite(STDERR, "No key is stored in the database.\n");
            exit(1);
        }
        fwrite(STDERR, "The key below protects every student pseudonym and two-step verification secret. Store it in the\n"
            . "password vault, set SAQF_APP_KEY to exactly this value, restart SAQF, then run: php bin/app_key.php forget-stored\n");
        echo $stored, "\n";
        Audit::asSystem(static fn() => Audit::record('security.app_key_exported', 'system', null, 'Stored application key exported on the command line for migration to SAQF_APP_KEY'));
        exit(0);

    case 'forget-stored':
        $stored = Secrets::storedKey();
        $env = (string) Config::get('SAQF_APP_KEY', '');
        if ($stored === null) {
            echo "No key is stored in the database; nothing to do.\n";
            exit(0);
        }
        if ($env === '' || !hash_equals($stored, $env)) {
            fwrite(STDERR, "Refused: SAQF_APP_KEY must be set to the same key before the database copy is removed\n"
                . "(otherwise pseudonyms and encrypted secrets would stop matching).\n");
            exit(1);
        }
        Db::exec('DELETE FROM system_settings WHERE setting_key = "app.key"');
        Audit::asSystem(static fn() => Audit::record('security.app_key_moved', 'system', null, 'Database copy of the application key removed; SAQF_APP_KEY supplies the same key'));
        echo "Database copy removed. The key now comes only from SAQF_APP_KEY.\n";
        exit(0);

    default:
        fwrite(STDERR, "usage: php bin/app_key.php status | generate | export-stored | forget-stored\n");
        exit(1);
}
