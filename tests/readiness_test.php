<?php
declare(strict_types=1);

/**
 * Tests for connection readiness and visible security: the replaceable data pack and its checks,
 * the SIS/LMS templates, the go-live status, the access review, the live security self-test, the
 * security evidence report, and the faculty calendar file.
 *   php bin/install.php --demo --fresh && php tests/readiness_test.php
 * Starts its own SAQF web server on a free port.
 */

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/support.php';

use Saqf\Core\Db;
use Saqf\Core\Policy;
use Saqf\Integration\CatalogFileSource;
use Saqf\Integration\DataPack;
use Saqf\Integration\FileSisSource;
use Saqf\Integration\GoLive;
use Saqf\Integration\Gradebook;
use Saqf\Integration\InstitutionSource;
use Saqf\Integration\Integrations;
use Saqf\Integration\Sync;
use Saqf\Security\AccessReview;
use Saqf\Security\SecurityCenter;
use Saqf\Security\SelfTest;
use Saqf\Web\Ics;

$app = serve(dirname(__DIR__) . '/public');

function http(string $jar, string $url, ?array $post = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'saqf-readiness-test', CURLOPT_HEADER => true]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$code, substr($raw, $size), substr($raw, 0, $size)];
}

function as_user(string $base, string $username): string
{
    $j = (string) tempnam(sys_get_temp_dir(), 'jar');
    http($j, "$base/login.php");
    [, $html] = http($j, "$base/login.php");
    preg_match('/name="_csrf" value="([^"]+)"/', $html, $m);
    http($j, "$base/demo.php", ['_csrf' => $m[1] ?? '', 'as' => $username, 'next' => 'index.php']);
    return $j;
}

function csrf_of(string $html): string
{
    return preg_match('/name="(?:_csrf|csrf)" (?:value|content)="([^"]+)"/', $html, $m) ? $m[1] : '';
}

function copy_tree(string $from, string $to): void
{
    @mkdir($to, 0700, true);
    foreach (scandir($from) as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        is_dir("$from/$f") ? copy_tree("$from/$f", "$to/$f") : copy("$from/$f", "$to/$f");
    }
}

section('The data pack: the bundled YU catalogue passes, faulty exports are refused');
$yu = SAQF_ROOT . '/data/yu';
$r = DataPack::inspect($yu);
ok($r['ok'] && $r['summary']['programs'] === 14 && $r['summary']['courses'] > 300, "bundled catalogue passes: {$r['summary']['programs']} programs, {$r['summary']['courses']} courses");
ok(count($r['warnings']) >= 1 && str_contains(implode(' ', $r['warnings']), 'ARCH'), 'programs without published outcomes are noted, not rejected');
$bad = tempdir('saqf-pack');
copy_tree($yu, $bad);
$mba = json_decode((string) file_get_contents("$bad/programs/mba.json"), true);
$mba['department'] = 'NOPE';
$mba['courses'][0]['credits'] = 'many';
$mba['courses'][1]['type'] = 'maybe';
file_put_contents("$bad/programs/mba.json", json_encode($mba));
$r = DataPack::inspect($bad);
ok(!$r['ok'] && count($r['errors']) >= 3, 'a pack with an unknown department, text credits and a bad type is not usable (' . count($r['errors']) . ' problems)');
ok(str_contains(implode(' ', $r['errors']), "department 'NOPE'") && str_contains(implode(' ', $r['errors']), 'credit hours'), 'each problem is named in plain words');
file_put_contents("$bad/programs/mba.json", '{ not json');
ok(!DataPack::inspect($bad)['ok'], 'a file that is not valid JSON is reported, not a crash');
unlink("$bad/institution.json");
ok(!DataPack::inspect($bad)['ok'] && str_contains(DataPack::inspect($bad)['errors'][0], 'institution.json'), 'a missing institution.json is reported');
ok(!DataPack::inspect(sys_get_temp_dir() . '/saqf-no-such-pack')['ok'], 'a folder that does not exist is reported');

section('A faulty catalogue never loads over good data');
$courses = (int) Db::val('SELECT COUNT(*) FROM courses');
$snap = (new CatalogFileSource($yu))->snapshot();
$snap['programs'][0]['department'] = 'NOPE';
$stub = new class($snap) implements InstitutionSource {
    public function __construct(private array $snap) {}
    public function label(): string { return 'stub'; }
    public function snapshot(): array { return $this->snap; }
};
throws(static fn() => Sync::institution($stub), 'the sync refuses the export as a whole', RuntimeException::class);
ok((int) Db::val('SELECT COUNT(*) FROM courses') === $courses, 'nothing was changed in the database');
ok(Db::val('SELECT status FROM sync_runs ORDER BY id DESC LIMIT 1') === 'failed' && str_contains((string) Db::val('SELECT message FROM sync_runs ORDER BY id DESC LIMIT 1'), 'nothing was changed'), 'the failed run is recorded with a plain reason (it raises the IT alert)');

section('Replaceable by the Registrar: a drop-in folder and an environment setting');
ok((new CatalogFileSource())->dir() === $yu, 'by default the bundled YU data is used');
putenv('SAQF_INSTITUTION_DIR=' . $yu . '/');
ok(rtrim(CatalogFileSource::defaultDir(), '/') === $yu, 'SAQF_INSTITUTION_DIR wins');
putenv('SAQF_INSTITUTION_DIR');
$zip = DataPack::zip($yu);
ok(str_starts_with($zip, 'PK') && str_contains($zip, 'institution.json') && str_contains($zip, 'programs/swe.json') && str_contains($zip, 'README.txt'), 'the catalogue template downloads as a ZIP with institution.json, programs/ and a README');

section('SIS and LMS templates are the exact layout the file connectors read');
$sisDir = tempdir('saqf-sis');
foreach (DataPack::sisTemplates() as $name => $csv) {
    file_put_contents("$sisDir/$name", $csv);
}
$fileSis = new FileSisSource($sisDir);
$demoSis = json_decode((string) file_get_contents(SAQF_ROOT . '/data/demo/sis.json'), true);
ok(count($fileSis->terms()) === count($demoSis['terms']), 'terms.csv reads back as the same ' . count($demoSis['terms']) . ' terms');
ok(count($fileSis->assignments('2026-1')) === count($demoSis['assignments']['2026-1']), 'assignments.csv reads back as the same teaching assignments');
$sections = array_filter($fileSis->assignments('2026-1'), static fn($a) => ($a['section'] ?? '') !== '');
ok(count($sections) >= 2 && count(array_filter($sections, static fn($a) => !empty($a['coordinator']))) >= 1, 'section and coordinator columns survive the round trip');
$gb = tempnam(sys_get_temp_dir(), 'gb') . '.csv';
file_put_contents($gb, DataPack::gradebookTemplate());
$parsed = Gradebook::parse($gb, 'lms');
ok(count($parsed['results']) >= 2 && count(reset($parsed['results'])) === 3, 'the gradebook example parses: ' . implode(', ', array_keys($parsed['results'])));
ok(!str_contains(implode(',', array_keys(reset($parsed['results']))), '2024010'), 'student numbers in it are replaced by pseudonyms on import');

section('Go-live status');
$sys = GoLive::systems();
$by = array_column($sys, null, 'key');
ok(count($sys) === 6 && $by['sis']['mode'] === 'demo' && $by['lms']['mode'] === 'demo' && $by['catalogue']['mode'] === 'demo', 'in the demo every system reports "demo data" and what to do next');
ok($by['sis']['next'] !== '' && str_contains($by['lms']['next'], 'SAQF_LMS_SOURCE'), 'each shows the exact next step');
putenv('SAQF_LMS_SOURCE=moodle');
Integrations::reset();
$by = array_column(GoLive::systems(), null, 'key');
ok($by['lms']['mode'] === 'live' && $by['lms']['missing'] === ['SAQF_MOODLE_URL', 'SAQF_MOODLE_TOKEN'], 'choosing Moodle lists the settings still missing');
putenv('SAQF_MOODLE_TOKEN=secret-value-123');
$dump = json_encode(GoLive::systems());
ok(!str_contains($dump, 'secret-value-123'), 'secret values are never shown, only whether they are set');
putenv('SAQF_LMS_SOURCE');
putenv('SAQF_MOODLE_TOKEN');
Integrations::reset();
ok(str_contains(GoLive::envSnippet(), 'SAQF_OIDC_ISSUER') && !preg_match('/=\S*password\S*/i', GoLive::envSnippet()), 'the settings file lists every connection and holds no secrets');

section('Access review');
$admin = Db::one('SELECT * FROM users WHERE username = "it.admin"');
$rows = AccessReview::rows();
$due = array_values(array_filter($rows, static fn($r) => $r['due']));
ok(count($due) === 3 && !array_filter($rows, static fn($r) => $r['username'] === 'it.admin' && $r['due']), 'the demo has three accounts due; the only administrator is not asked to review themselves');
ok(AccessReview::progress()['due'] === 3, 'progress reports 3 due');
throws(static fn() => AccessReview::confirm([(int) $admin['id']], $admin), 'nobody can confirm their own access', DomainException::class);
$target = $due[0];
ok(AccessReview::confirm([(int) $target['id']], $admin, 'Checked against HR') === 1 && AccessReview::progress()['due'] === 2, 'confirming one person makes them current');
Db::exec('UPDATE users SET role = ? WHERE id = ?', [$target['role'] === 'dean' ? 'qa' : 'dean', $target['id']]);
ok(array_values(array_filter(AccessReview::rows(), static fn($r) => $r['id'] === $target['id']))[0]['due'], 'changing someone\'s role makes their review due again at once');
Db::exec('UPDATE users SET role = ? WHERE id = ?', [$target['role'], $target['id']]);
$victim = $due[1];
throws(static fn() => AccessReview::remove((int) $victim['id'], $admin, 'x'), 'removing access needs a real reason', DomainException::class);
AccessReview::remove((int) $victim['id'], $admin, 'Left the university (HR ref 4411)');
ok(Db::val('SELECT status FROM users WHERE id = ?', [$victim['id']]) === 'disabled', 'removed access disables the account');
Db::exec('UPDATE users SET status = "active" WHERE id = ?', [$victim['id']]); // the demo accounts are used by the page tests below
ok((int) Db::val('SELECT COUNT(*) FROM audit_log WHERE action IN ("security.access_confirmed","security.access_removed")') >= 2, 'both decisions are in the tamper-evident audit log');
$checks = array_column(SecurityCenter::checks(), null, 'label');
ok(isset($checks['Access review']) && $checks['Access review']['ok'] === false, 'the Security center flags the remaining overdue review');

section('Live security self-test');
$tests = SelfTest::run();
ok(count($tests) >= 7 && !array_filter($tests, static fn($t) => !$t['ok']), 'all ' . count($tests) . ' protections prove themselves: ' . implode('; ', array_map(static fn($t) => $t['name'], $tests)));
$stored = SelfTest::runAndStore();
ok(SelfTest::last()['passed'] === $stored['passed'] && $stored['passed'] === $stored['total'], 'the result is remembered for the Security center');

section('Calendar file');
$ics = Ics::calendar('Test; calendar', [['uid' => 'a', 'date' => '2026-12-27', 'title' => 'Final grades due, Fall 2026; sections 01/02', 'description' => str_repeat('Long description with, commas and ünïcode ', 6) . "\nsecond line"], ['uid' => 'b', 'date' => 'not-a-date', 'title' => 'ignored']]);
ok(str_starts_with($ics, "BEGIN:VCALENDAR\r\n") && str_ends_with($ics, "END:VCALENDAR\r\n") && substr_count($ics, 'BEGIN:VEVENT') === 1, 'valid calendar envelope; an invalid date is skipped');
ok(str_contains($ics, 'DTSTART;VALUE=DATE:20261227') && str_contains($ics, 'DTEND;VALUE=DATE:20261228'), 'all-day event on the due date');
ok(str_contains($ics, 'Fall 2026\; sections 01/02') && str_contains($ics, 'X-WR-CALNAME:Test\; calendar'), 'text is escaped');
$long = true;
foreach (explode("\r\n", $ics) as $line) {
    $long = $long && strlen($line) <= 75 && mb_check_encoding($line, 'UTF-8');
}
ok($long, 'no line exceeds 75 bytes and no UTF-8 character is cut');

section('Pages');
$it = as_user($app, 'it.admin');
[$code, $html] = http($it, "$app/admin.php?tab=golive");
ok($code === 200 && str_contains($html, 'Ready to connect to the university') && str_contains($html, 'Demo data') && str_contains($html, 'catalogue-pack'), 'Go-live tab lists every system with its mode and the templates');
[$code, $html] = http($it, "$app/admin.php?tab=golive");
[, $page] = http($it, "$app/admin.php?tab=golive");
[$code, , $head] = http($it, "$app/admin.php?tab=golive", ['_csrf' => csrf_of($page), 'op' => 'pack_check', 'which' => 'active']);
ok($code === 302, 'checking the catalogue redirects back');
[, $html] = http($it, "$app/admin.php?tab=golive");
ok(str_contains($html, 'Passed') && str_contains($html, '14 programs'), 'and shows the report: ' . (preg_match('/(\d+) courses/', $html, $m) ? $m[0] : 'no courses line'));
foreach (['catalogue-pack' => 'application/zip', 'sis-terms' => 'text/csv', 'sis-assignments' => 'text/csv', 'gradebook' => 'text/csv', 'env' => 'text/plain'] as $d => $type) {
    [$code, $body, $head] = http($it, "$app/admin.php?tab=golive&download=$d");
    ok($code === 200 && stripos($head, "Content-Type: $type") !== false && strlen($body) > 40 && stripos($head, 'attachment') !== false, "download $d");
}
[$code, $html] = http($it, "$app/admin.php?tab=review");
ok($code === 200 && str_contains($html, 'Access review') && str_contains($html, 'Remove someone') && str_contains($html, 'Quarterly') === false, 'Access review tab renders');
[$code, $html] = http($it, "$app/admin.php?tab=center");
ok(str_contains($html, 'Live security self-test') && str_contains($html, 'Run security self-test') && str_contains($html, 'Access review'), 'Security center shows the self-test and the access-review control');
[$code, $html] = http($it, "$app/admin.php?tab=center");
[$code] = http($it, "$app/admin.php?tab=center", ['_csrf' => csrf_of($html), 'op' => 'selftest']);
[, $html] = http($it, "$app/admin.php?tab=center");
ok($code === 302 && preg_match('/\d+ of \d+ passed/', $html) === 1, 'running the self-test from the page records the result');
[$code, $html] = http($it, "$app/security_report.php");
ok($code === 200 && str_contains($html, 'Security evidence report') && str_contains($html, 'Report fingerprint') && str_contains($html, 'Live self-test') && str_contains($html, 'hash-chained'), 'the security evidence report is complete');
ok((int) Db::val('SELECT COUNT(*) FROM audit_log WHERE action = "security.report_generated"') === 1, 'generating the report is itself audited');
foreach (['f.omar', 'hod.ced', 'qa.director'] as $u) {
    $j = as_user($app, $u);
    [$c1] = http($j, "$app/security_report.php");
    [$c2] = http($j, "$app/admin.php?tab=golive");
    [$c3] = http($j, "$app/admin.php?tab=review");
    ok($c1 === 403 && $c2 === 403 && $c3 === 403, "$u cannot open the security report, Go-live or Access review");
}
$omar = as_user($app, 'f.omar');
[$code, $body, $head] = http($omar, "$app/calendar.php");
ok($code === 200 && stripos($head, 'text/calendar') !== false && str_starts_with($body, 'BEGIN:VCALENDAR') && substr_count($body, 'BEGIN:VEVENT') >= 2, 'faculty download a calendar with ' . substr_count($body, 'BEGIN:VEVENT') . ' dates');
ok(str_contains($body, 'Final grades due') && !str_contains($body, 'Noura') , 'it holds their own term dates and nobody else\'s');
[, $html] = http($omar, "$app/faculty.php");
ok(str_contains($html, 'calendar.php'), 'My courses offers it');
[$code] = http((string) tempnam(sys_get_temp_dir(), 'jar'), "$app/calendar.php");
ok($code === 302, 'signed-out visitors are sent to sign in');

finish();
