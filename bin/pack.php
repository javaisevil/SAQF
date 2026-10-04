<?php
declare(strict_types=1);

/**
 * Check or package the university data pack (the Registrar catalogue) without touching the database.
 *   php bin/pack.php validate [folder]      checks a catalogue (default: the one SAQF would use); exit code 1 if not usable
 *   php bin/pack.php export <file.zip>      writes the current catalogue as the template for the Registrar's export
 *   php bin/pack.php templates <folder>     writes terms.csv, assignments.csv and a gradebook example (the SIS/LMS file layouts)
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Saqf\Integration\CatalogFileSource;
use Saqf\Integration\DataPack;

$cmd = $argv[1] ?? 'validate';
switch ($cmd) {
    case 'validate':
        $dir = $argv[2] ?? CatalogFileSource::defaultDir();
        $r = DataPack::inspect($dir);
        echo "Catalogue: $dir\n";
        foreach ($r['summary'] as $k => $v) {
            echo sprintf("  %-26s %s\n", $k, $v);
        }
        foreach ($r['errors'] as $e) {
            echo "  PROBLEM  $e\n";
        }
        foreach ($r['warnings'] as $w) {
            echo "  note     $w\n";
        }
        echo $r['ok'] ? "\nPASSED: SAQF can use this catalogue.\n" : "\nNOT USABLE: fix the problems above; SAQF refuses a faulty catalogue as a whole.\n";
        exit($r['ok'] ? 0 : 1);
    case 'export':
        $out = $argv[2] ?? '';
        if ($out === '') {
            fwrite(STDERR, "Usage: php bin/pack.php export <file.zip>\n");
            exit(2);
        }
        file_put_contents($out, DataPack::zip(CatalogFileSource::defaultDir()));
        echo "Written $out\n";
        exit(0);
    case 'templates':
        $dir = rtrim($argv[2] ?? '', '/');
        if ($dir === '') {
            fwrite(STDERR, "Usage: php bin/pack.php templates <folder>\n");
            exit(2);
        }
        @mkdir($dir, 0775, true);
        foreach (DataPack::sisTemplates() as $name => $csv) {
            file_put_contents("$dir/$name", $csv);
            echo "Written $dir/$name\n";
        }
        file_put_contents("$dir/gradebook-example.csv", DataPack::gradebookTemplate());
        echo "Written $dir/gradebook-example.csv\n";
        exit(0);
    default:
        fwrite(STDERR, "Usage: php bin/pack.php validate|export|templates\n");
        exit(2);
}
