<?php
declare(strict_types=1);

/**
 * Production preflight: a go / no-go check before real users or real data (see src/Security/Preflight.php).
 *   php bin/preflight.php            human-readable report
 *   php bin/preflight.php --json     the same as JSON (for a pipeline)
 * Exit code: 0 = no blockers, 1 = at least one blocker, 2 = the database cannot be read.
 * It judges the installation against the production rules whatever mode it runs in. It never prints
 * a secret. It is a list of known pre-conditions, not a penetration test or a certification.
 */

require __DIR__ . '/../src/bootstrap.php';

use Saqf\Core\Config;
use Saqf\Security\Preflight;

try {
    $checks = Preflight::run();
} catch (Throwable $e) {
    fwrite(STDERR, 'Cannot run the preflight: ' . $e->getMessage() . "\n");
    exit(2);
}
$sum = Preflight::summary($checks);
if (in_array('--json', $argv, true)) {
    echo json_encode(['mode' => Config::env(), 'summary' => $sum, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit($sum['ready'] ? 0 : 1);
}
echo 'SAQF ' . SAQF_VERSION . ' production preflight (this installation runs as APP_ENV=' . Config::env() . ")\n\n";
$mark = ['pass' => ' ok  ', 'blocker' => 'BLOCK', 'warning' => 'WARN ', 'info' => ' i   '];
foreach ($checks as $c) {
    $m = $c['pass'] ? $mark['pass'] : ($c['severity'] === 'info' ? $mark['info'] : $mark[$c['severity']]);
    echo sprintf("[%s] %s\n        %s\n", $m, $c['title'], $c['detail']);
    if (!$c['pass'] && $c['fix'] !== '') {
        echo '        fix: ' . $c['fix'] . "\n";
    }
}
echo sprintf("\n%d blocker(s), %d warning(s), %d checks. %s\n", $sum['blockers'], $sum['warnings'], $sum['total'],
    $sum['ready'] ? 'No blockers: these known pre-conditions are met. This is not a security approval.' : 'NOT READY for real users or real data.');
exit($sum['ready'] ? 0 : 1);
