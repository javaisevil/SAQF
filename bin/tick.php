<?php
declare(strict_types=1);

// Scheduler heartbeat — run from cron every 5 minutes in production, e.g. in /etc/cron.d/saqf:
//
//   */5 * * * *  www-data  php /var/www/saqf/bin/tick.php >> /var/log/saqf-tick.log 2>&1
//
// Imports newly published LMS results (the event pipeline recalculates achievement and
// re-validates affected courses), re-checks date-driven rules, and once a day re-evaluates
// program-level patterns. Use --force to ignore the 5-minute guard.

require __DIR__ . '/../src/bootstrap.php';

use Saqf\Quality\Scheduler;

$force = in_array('--force', array_slice($argv, 1), true);
$t0 = microtime(true);
try {
    $stats = Scheduler::tick($force);
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . " tick failed: " . $e->getMessage() . "\n");
    exit(1);
}
if (!empty($stats['skipped'])) {
    echo date('c') . " tick skipped (last run under 5 minutes ago; use --force)\n";
    exit(0);
}
printf("%s tick ok in %.2fs: %d LMS batch(es) imported, %d course(s) re-checked, %d program(s) re-evaluated\n",
    date('c'), microtime(true) - $t0, $stats['lms_batches'], $stats['offerings_checked'], $stats['programs_checked']);
