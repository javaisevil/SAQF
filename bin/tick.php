<?php
declare(strict_types=1);

// Scheduler heartbeat — run from cron every 5 minutes in production, e.g. in /etc/cron.d/saqf:
//
//   */5 * * * *  www-data  php /var/www/saqf/bin/tick.php >> /var/log/saqf-tick.log 2>&1
//
// Syncs the SIS (hourly: calendar, teaching assignments, automatic term start), imports newly
// published LMS results (the event pipeline recalculates achievement and re-validates affected
// courses), re-syncs the catalogue and re-checks date-driven rules daily, and e-mails people about
// items that need them. Use --force to ignore the 5-minute guard. Exit code 2 = a step failed.

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
printf("%s tick %s in %.2fs: %d LMS batch(es) imported, %d SIS assignment(s) read, %d workspace(s) created%s, %d course(s) re-checked, %d program(s) re-evaluated, %d e-mail(s) sent%s\n",
    date('c'), $stats['errors'] ? 'finished with errors' : 'ok', microtime(true) - $t0, $stats['lms_batches'], $stats['assignments'], $stats['workspaces_created'],
    $stats['term_activated'] ? ', term activated: ' . $stats['term_activated'] : '', $stats['offerings_checked'], $stats['programs_checked'], $stats['emails'],
    $stats['errors'] ? " — {$stats['errors']} step(s) failed (see Administration → Error log)" : '');
exit($stats['errors'] ? 2 : 0);
