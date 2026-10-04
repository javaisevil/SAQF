<?php
declare(strict_types=1);

/**
 * Mapped API connectors (SIS and LMS grades described by a mapping file, no code): validation of mapping files,
 * the field/transform engine, paging styles, authentication, refusal of unsafe behaviour, the offline checker
 * (bin/mapping_check.php), Go-live display, and a full pass through Sync and the achievement pipeline against a
 * SIMULATED university API (tests/mock/uni_api.php) whose JSON looks nothing like SAQF's own contract.
 * Run against a FRESH demo database on a test server only:
 *   php bin/install.php --demo --fresh && php tests/mapping_test.php
 */

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/support.php';

use Saqf\Core\Config;
use Saqf\Core\Db;
use Saqf\Core\Secrets;
use Saqf\Integration\GoLive;
use Saqf\Integration\Http;
use Saqf\Integration\Integrations;
use Saqf\Integration\MappedApi;
use Saqf\Integration\MappedLmsSource;
use Saqf\Integration\MappedSisSource;
use Saqf\Integration\Mapping;
use Saqf\Integration\Sync;
use Saqf\Quality\Achievement;

if (Config::env() === 'production') {
    fwrite(STDERR, "Refusing to run: this test changes data and must never run with APP_ENV=production.\n");
    exit(1);
}

$root = dirname(__DIR__);
$sisFile = "$root/docs/mappings/university-sis.simulated.json";
$lmsFile = "$root/docs/mappings/university-lms.simulated.json";
$sisMap = json_decode((string) file_get_contents($sisFile), true);
$lmsMap = json_decode((string) file_get_contents($lmsFile), true);

/** A copy of $m with the value at a dotted path replaced (null removes the key). */
function with(array $m, string $path, $value): array
{
    $ref = &$m;
    $keys = explode('.', $path);
    $last = array_pop($keys);
    foreach ($keys as $k) {
        $ref = &$ref[$k];
    }
    if ($value === null) {
        unset($ref[$last]);
    } else {
        $ref[$last] = $value;
    }
    return $m;
}

function mapping_file(array $m): string
{
    $f = (string) tempnam(sys_get_temp_dir(), 'map') . '.json';
    file_put_contents($f, json_encode($m, JSON_UNESCAPED_UNICODE));
    return $f;
}

function has_problem(array $m, string $system, string $needle): bool
{
    foreach (Mapping::problems($m, $system) as $p) {
        if (str_contains($p, $needle)) {
            return true;
        }
    }
    return false;
}

function db_contains(string $needle): array
{
    $hits = [];
    foreach (Db::all('SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE IN ("char","varchar","tinytext","text","mediumtext","longtext","json")') as $col) {
        if ((int) Db::val('SELECT COUNT(*) FROM `' . $col['t'] . '` WHERE `' . $col['c'] . '` LIKE ?', ['%' . $needle . '%'])) {
            $hits[] = $col['t'] . '.' . $col['c'];
        }
    }
    return $hits;
}

function run_cli(array $args, ?array $env = null): array
{
    $p = proc_open(array_merge([PHP_BINARY, dirname(__DIR__) . '/bin/mapping_check.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $env === null ? null : $env + getenv());
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($p), $out];
}

// ---------------------------------------------------------------------------------------------
section('1. A mapping file is data that is checked before anything runs');
ok(Mapping::problems($sisMap, 'sis') === [] && Mapping::problems($lmsMap, 'lms') === [], 'the two example mappings are valid');
ok(has_problem(with($sisMap, 'version', 2), 'sis', '"version" must be 1'), 'an unknown version is refused');
ok(has_problem(with($sisMap, 'name', null), 'sis', '"name" is required'), 'a mapping needs a name');
ok(has_problem(with($sisMap, 'auth', ['type' => 'query']), 'sis', 'auth.type'), 'a token in the address is not an authentication type');
ok(has_problem(with($lmsMap, 'auth', ['type' => 'header', 'name' => 'Host']), 'lms', 'auth.name'), 'an API-key header cannot be a reserved header such as Host');
ok(has_problem(with($lmsMap, 'auth', ['type' => 'oauth2']), 'lms', 'auth.token_url'), 'OAuth2 needs a token address');
ok(has_problem(with($sisMap, 'terms.path', 'https://evil.example/x'), 'sis', 'terms.path'), 'a path cannot name another host');
ok(has_problem(with($sisMap, 'terms.path', '/a/../b'), 'sis', 'terms.path'), 'a path cannot climb out with ..');
ok(has_problem(with($sisMap, 'assignments.path', '/x/{student}'), 'sis', 'assignments.path'), 'only the documented placeholders are allowed in a path');
ok(has_problem(with($lmsMap, 'grades.query', ['api_token' => 'x']), 'lms', 'credentials never go in the address'), 'a credential in the query string is refused');
ok(has_problem(with($sisMap, 'terms.fields.colour', 'x'), 'sis', 'not a SAQF field'), 'a field SAQF does not have is refused');
ok(has_problem(with($sisMap, 'terms.fields.code', null), 'sis', 'terms.fields.code" is required'), 'a required field cannot be left out');
ok(has_problem(with($sisMap, 'terms.fields.sequence', ['path' => 'ordinal', 'transform' => ['eval']]), 'sis', 'unknown transform'), 'only whitelisted conversions exist (no code can be named)');
ok(has_problem(with($sisMap, 'terms.fields.sequence', ['path' => 'ordinal', 'transform' => ['date:%s%s']]), 'sis', 'supported date format'), 'a date format is limited to date letters and separators');
ok(has_problem(with($sisMap, 'terms.fields.sequence', ['path' => 'ordinal', 'sql' => 'DROP']), 'sis', 'unknown key'), 'an unknown key in a field is refused, not ignored');
ok(has_problem(with($sisMap, 'terms.pagination', ['type' => 'sql']), 'sis', 'pagination.type'), 'unknown paging styles are refused');
ok(has_problem(with($sisMap, 'terms.pagination', ['type' => 'cursor', 'param' => 'a']), 'sis', 'next_path'), 'cursor paging must say where the next cursor is');
ok(has_problem(with($lmsMap, 'grades.fields.max', null), 'lms', 'percentage'), 'grades need a percentage, or score together with max');
ok(has_problem(with($lmsMap, 'grades.empty_statuses', [500]), 'lms', 'empty_statuses'), 'only 204/404 may mean "nothing there" (a 500 is never silently empty)');
ok(has_problem($sisMap, 'lms', '"grades" is required'), 'an SIS mapping is not usable as an LMS mapping');
throws(static fn() => Mapping::load(mapping_file(['version' => 2]), 'sis'), 'load() refuses an invalid mapping with its problems', RuntimeException::class);
$junk = (string) tempnam(sys_get_temp_dir(), 'junk');
file_put_contents($junk, '{not json');
throws(static fn() => Mapping::load($junk, 'sis'), 'load() refuses a file that is not JSON', RuntimeException::class);
file_put_contents($junk, json_encode(['version' => 1, 'pad' => str_repeat('x', 70000)]));
throws(static fn() => Mapping::load($junk, 'sis'), 'load() refuses a file over 64 KB', RuntimeException::class);
throws(static fn() => Mapping::load('/nonexistent/mapping.json', 'sis'), 'load() reports a missing file', RuntimeException::class);

// ---------------------------------------------------------------------------------------------
section('2. The field engine: paths, joins, tables, strict conversions');
$row = ['a' => ['b' => [['c' => ' X ']]], 'n' => '3.6', 'd' => '05/09/2026', 'flag' => 'Y', 'first' => 'Omar', 'last' => '', 'code' => 'swe401', 'empty' => '  ', 'obj' => ['k' => 1], 'bool' => true];
ok(Mapping::get($row, 'a.b[0].c') === ' X ' && Mapping::get($row, 'a.b[1].c') === null && Mapping::get($row, 'nope.x') === null, 'dotted paths with list positions; anything missing is null');
ok(Mapping::field($row, ['path' => 'a.b[0].c', 'transform' => ['trim', 'lower']]) === 'x', 'trim and lower');
ok(Mapping::field($row, ['path' => 'n', 'transform' => ['int']]) === 4, 'int rounds ("3.6" → 4)');
ok(Mapping::field($row, ['path' => 'd', 'transform' => ['date:d/m/Y']]) === '2026-09-05', 'a d/m/Y date becomes an ISO date');
throws(static fn() => Mapping::field(['d' => '31/02/2026'], ['path' => 'd', 'transform' => ['date:d/m/Y']]), 'an impossible date (31 February) is refused, not rolled over', RuntimeException::class);
throws(static fn() => Mapping::field(['d' => '2026-09-05'], ['path' => 'd', 'transform' => ['date:d/m/Y']]), 'a date in the wrong format is refused', RuntimeException::class);
ok(Mapping::field($row, ['path' => 'flag', 'transform' => ['bool']]) === 'yes' && Mapping::field(['f' => 'N'], ['path' => 'f', 'transform' => ['bool']]) === 'no', 'Y/N flags become yes/no');
ok(Mapping::field($row, ['join' => ['first', 'last'], 'sep' => ' ']) === 'Omar', 'join skips empty parts');
ok(Mapping::field($row, ['path' => 'code', 'transform' => ['course']]) === 'SWE 401', 'course codes are put in the catalogue format');
ok(Mapping::field($row, ['path' => 'empty', 'default' => 'fallback']) === 'fallback' && Mapping::field($row, 'missing') === null, 'blank values use the default; absent values stay absent');
ok(Mapping::field($row, ['path' => 'flag', 'map' => ['Y' => 'coordinator', 'N' => 'no']]) === 'coordinator', 'a value table translates codes');
ok(Mapping::field($row, 'obj') === null, 'an object or list is never taken as a value');
ok(Mapping::field(['x' => 1], ['const' => 'fixed']) === 'fixed', 'a constant can fill a field');
throws(static fn() => Mapping::items(['x' => []], $sisMap['terms'], 'terms'), 'a response without the mapped list says where it looked', RuntimeException::class);
throws(static fn() => Mapping::items(['payload' => ['rows' => ['text']]], $sisMap['terms'], 'terms'), 'a row that is not an object is refused', RuntimeException::class);
throws(static fn() => Mapping::mapRows($sisMap, 'terms', [['semester_code' => '2026-1']]), 'a row missing a required value is refused, naming the row and the field', RuntimeException::class);

// ---------------------------------------------------------------------------------------------
section('3. SIS through a mapping, against the simulated university API');
$api = serve("$root/tests/mock/uni_api.php");
putenv('SAQF_SIS_TOKEN=uni-token');
putenv('SAQF_LMS_TOKEN=uni-api-key');
$sis = new MappedSisSource($sisFile, $api);
ok(str_contains($sis->label(), 'SIMULATED') && str_contains($sis->label(), '127.0.0.1'), 'the label says SIMULATED and names the host: ' . $sis->label());
$terms = $sis->terms();
ok(count($terms) === 3 && array_column($terms, 'code') === ['2026-1', '2026-2', '2026-3'], 'terms read across two pages (page/per_page paging): 3 terms');
ok($terms[1]['starts_on'] === '2027-01-10' && $terms[1]['grades_due_on'] === '2027-05-30' && $terms[1]['sequence'] === 4, 'd/m/Y dates and "ordinal" mapped to SAQF\'s fields');
$as = $sis->assignments('2026-2');
ok(count($as) === 3 && $as[0]['course'] === 'SWE 401' && $as[2]['course'] === 'CIS 491', 'assignments read across two pages (cursor paging); "swe401" and "CIS 491" both become catalogue codes');
ok($as[0]['section'] === '01' && $as[1]['section'] === '02' && $as[0]['coordinator'] === true && $as[1]['coordinator'] === false, 'sections and the coordinator flag come through');
ok($as[0]['instructor'] === 'YU-F1034' && $as[0]['instructor_name'] === 'Omar Al-Harbi' && $as[0]['instructor_email'] === 'omar.sim@yu.example' && $as[0]['enrolled'] === 21, 'nested staff fields, a joined name and the enrolment');
ok($sis->assignments('2026-1') === [], 'a term with no rows gives an empty list');
$c = $sis->check();
ok($c['ok'] && str_contains($c['message'], 'answered the check request') && str_contains($c['message'], '3 term(s)'), 'the connection test uses the mapping\'s check path and reads the terms: ' . $c['message']);
putenv('SAQF_SIS_TOKEN=wrong-token');
$c = (new MappedSisSource($sisFile, $api))->check();
ok(!$c['ok'] && str_contains($c['message'], 'refused the credentials') && !str_contains($c['message'], 'wrong-token'), 'a rejected token is reported without echoing it: ' . $c['message']);
putenv('SAQF_SIS_TOKEN');
$c = (new MappedSisSource($sisFile, $api))->check();
ok(!$c['ok'] && str_contains($c['message'], 'SAQF_SIS_TOKEN is not set'), 'a missing token is named: ' . $c['message']);
putenv('SAQF_SIS_TOKEN=uni-token');
$c = (new MappedSisSource(mapping_file(with($sisMap, 'terms.path', '/nothing/here')), $api))->check();
ok(!$c['ok'] && str_contains($c['message'], 'HTTP 404'), 'an unknown endpoint is reported with its status');
$c = (new MappedSisSource(mapping_file(with($sisMap, 'terms.path', '/html')), $api))->check();
ok(!$c['ok'] && str_contains($c['message'], 'did not return JSON'), 'a maintenance page instead of JSON is reported');
$c = (new MappedSisSource(mapping_file(with($sisMap, 'terms.list', 'payload.items')), $api))->check();
ok(!$c['ok'] && str_contains($c['message'], 'payload.items'), 'a wrong list path is reported with the path it tried');
$c = (new MappedSisSource($sisFile, null))->check();
ok(!$c['ok'] && str_contains($c['message'], 'SAQF_SIS_URL'), 'a missing address is named');
$c = (new MappedSisSource(null, $api))->check();
ok(!$c['ok'] && str_contains($c['message'], 'SAQF_SIS_MAPPING'), 'a missing mapping file is named');
$c = (new MappedSisSource(mapping_file(with($sisMap, 'terms.path', '/registry/v2/big')), $api))->check();
ok(!$c['ok'] && str_contains($c['message'], 'more than 32 MB'), 'an answer over 32 MB is refused (a remote system cannot exhaust memory)');
putenv('APP_ENV=production');
$c = (new MappedSisSource($sisFile, $api))->check();
putenv('APP_ENV=local');
ok(!$c['ok'] && str_contains($c['message'], 'plain http://'), 'in production a plain http:// API is refused: ' . $c['message']);
ok(has_problem(with($lmsMap, 'auth', ['type' => 'oauth2', 'token_url' => 'http://idp.example/token']), 'lms', 'https://') === false, 'outside production an http token address is allowed (for stand-in servers)');
putenv('APP_ENV=production');
ok(has_problem(with($lmsMap, 'auth', ['type' => 'oauth2', 'token_url' => 'http://idp.example/token']), 'lms', 'token_url'), 'in production a mapping with an http:// token address is invalid');
putenv('APP_ENV=local');

// ---------------------------------------------------------------------------------------------
section('4. LMS grades through a mapping: pseudonymised on the way in');
$lms = new MappedLmsSource($lmsFile, $api);
ok(str_contains($lms->label(), 'SIMULATED'), 'the label says SIMULATED: ' . $lms->label());
$b = $lms->batches('2026-1', 'SWE 401');
ok(count($b) === 1 && str_starts_with($b[0]['ref'], 'MAP-'), 'one batch for the course, with a reference that changes when the marks change');
$res = $b[0]['results'];
ok(array_keys($res) === ['Final exam', 'Midterm exam'], 'coded columns MT/FN are translated by the mapping; the LMS total column is left out');
$s1 = Secrets::pseudonym(Gradebook_system(), '209900001');
ok(count($res['Midterm exam']) === 12 && count($res['Final exam']) === 11, '12 students on the midterm; 11 on the final (one mark not given yet is not invented)');
ok($res['Midterm exam'][$s1] === 44.0 && $res['Final exam'][$s1] === 41.0, 'points are converted to percentages of points possible (22/50 → 44%, 41/100 → 41%)');
$ids = array_keys($res['Midterm exam']);
ok(!array_filter($ids, static fn($k) => ctype_digit((string) $k) || str_contains((string) $k, '2099')), 'every student key is a keyed pseudonym; no student number survives');
ok(count(array_unique($b[0]['sections'])) === 2 && $b[0]['sections'][$s1] === '01', 'the section of each student is carried (two sections)');
$offsetMap = with(with($lmsMap, 'grades.pagination', ['type' => 'offset', 'param' => 'offset', 'size_param' => 'limit', 'size' => 10]), 'grades.query', null);
$b2 = (new MappedLmsSource(mapping_file($offsetMap), $api))->batches('2026-1', 'SWE 401');
ok($b2 && $b2[0]['results'] === $res, 'offset paging reads exactly the same marks as next-link paging');
ok($lms->batches('2026-1', 'XYZ 999') === [], 'a course the LMS does not know (HTTP 404) simply has no results yet');
$c = $lms->check();
ok($c['ok'] && str_contains($c['message'], 'answered the check request'), 'connection test: ' . $c['message']);
$c = (new MappedLmsSource(mapping_file(with($lmsMap, 'check', null)), $api))->check();
ok(!$c['ok'] && str_contains($c['message'], 'cannot be tested'), 'with no check path the test says so instead of pretending to succeed');
putenv('SAQF_LMS_COURSE_KEY={term}-{code_nospace}-{section}');
$keyed = (new MappedLmsSource(mapping_file(with($lmsMap, 'grades.path', '/lms/v1/courses/{term}~{code}~{section}/marks')), $api))->batches('2026-1', 'SWE 401', '01');
putenv('SAQF_LMS_COURSE_KEY');
ok($keyed === [], 'every documented placeholder is substituted (and URL-encoded) in the path');
throws(static fn() => (new MappedLmsSource(mapping_file(with($lmsMap, 'grades.path', '/lms/v1/courses/LOOP-1/marks')), $api))->batches('2026-1', 'SWE 401'), 'an API that sends the same page again is stopped, not looped on', RuntimeException::class);
throws(static fn() => (new MappedLmsSource(mapping_file(with($lmsMap, 'grades.path', '/lms/v1/courses/OTHER-1/marks')), $api))->batches('2026-1', 'SWE 401'), 'a next-page link to another host is not followed (credentials would leave the configured system)', RuntimeException::class);
throws(static fn() => (new MappedLmsSource(mapping_file(with($lmsMap, 'grades.pagination', ['type' => 'next_link', 'next_path' => 'links.next', 'max_pages' => 1])), $api))->batches('2026-1', 'SWE 401'), 'when the page limit is reached with more to read, SAQF stops with an error instead of dropping data', RuntimeException::class);

// ---------------------------------------------------------------------------------------------
section('5. OAuth2 client credentials');
$oauth = with($lmsMap, 'auth', ['type' => 'oauth2', 'token_url' => "$api/oauth/token", 'scope' => 'grades.read']);
$oauthFile = mapping_file($oauth);
putenv('SAQF_LMS_TOKEN');
putenv('SAQF_LMS_CLIENT_ID=saqf-sim');
putenv('SAQF_LMS_CLIENT_SECRET=sim-secret');
MappedApi::forgetTokens();
ok(count((new MappedLmsSource($oauthFile, $api))->batches('2026-1', 'SWE 401')) === 1, 'a token is fetched from the token endpoint and used for the grades');
putenv('SAQF_LMS_CLIENT_SECRET=wrong-secret');
MappedApi::forgetTokens();
$c = (new MappedLmsSource($oauthFile, $api))->check();
ok(!$c['ok'] && !str_contains($c['message'], 'wrong-secret'), 'a rejected client secret fails without echoing it: ' . $c['message']);
putenv('SAQF_LMS_CLIENT_SECRET');
putenv('SAQF_LMS_CLIENT_ID');
MappedApi::forgetTokens();
$c = (new MappedLmsSource($oauthFile, $api))->check();
ok(!$c['ok'] && str_contains($c['message'], 'SAQF_LMS_CLIENT_ID and SAQF_LMS_CLIENT_SECRET are not set'), 'missing client settings are named: ' . $c['message']);
putenv('SAQF_LMS_TOKEN=uni-api-key');

// ---------------------------------------------------------------------------------------------
section('6. All the way through SAQF: terms, assignments and grades from the simulated API');
Integrations::use(null, $sis, $lms);
ok(Sync::terms($sis) === 3 && (string) Db::val('SELECT ends_on FROM terms WHERE code = "2026-3"') === '2027-07-30', 'terms are synchronised from the mapped SIS (ISO dates in the database)');
$stats = Sync::assignments('2026-2', $sis);
ok($stats['assignments'] === 3 && $stats['unknown_courses'] === 0, 'assignments are read and matched to catalogue courses: ' . json_encode($stats));
$off = Db::one('SELECT o.id, o.enrolled, o.sections, o.instructor_id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id WHERE c.code = "SWE 401" AND t.code = "2026-2"');
ok($off && (int) $off['sections'] === 2 && (int) $off['enrolled'] === 41, 'SWE 401 has two sections and 41 students, from the mapped rows');
ok(Db::val('SELECT external_id FROM users WHERE id = ?', [$off['instructor_id']]) === 'YU-F1034', 'the coordinator flag decided who owns the course');
ok((bool) Db::val('SELECT 1 FROM users WHERE external_id = "YU-F2210" AND provisioned_by = "sis"'), 'the second instructor got an account from the feed (name and e-mail mapped)');
$swe = (int) Db::val('SELECT o.id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id WHERE c.code = "SWE 401" AND t.code = "2026-1"');
$before = (int) Db::val('SELECT COUNT(*) FROM assessment_results WHERE offering_id = ?', [$swe]);
ok(Achievement::syncFromLms($swe) === 1, 'the grades flow through the same pipeline as every other LMS');
$added = (int) Db::val('SELECT COUNT(*) FROM assessment_results WHERE offering_id = ?', [$swe]) - $before;
ok($added === 23, "23 marks were added (12 midterm + 11 final), none for the skipped total column (added: $added)");
ok(!db_contains('209900001') && !db_contains('209900012'), 'the student numbers appear in no table and no column of the database');
ok((bool) Db::val('SELECT 1 FROM assessment_results WHERE offering_id = ? AND student_ref = ?', [$swe, $s1]), 'the marks are filed under the keyed pseudonym, the same one a manual upload of that student would get');
ok((int) Db::val('SELECT COUNT(DISTINCT section_code) FROM assessment_results WHERE offering_id = ? AND student_ref IN (' . implode(',', array_fill(0, 12, '?')) . ')', array_merge([$swe], array_map(static fn($i) => Secrets::pseudonym(Gradebook_system(), '2099' . str_pad((string) $i, 5, '0', STR_PAD_LEFT)), range(1, 12)))) === 2, 'the two sections are kept apart');
ok(Achievement::syncFromLms($swe) === 0, 'reading the same marks again changes nothing (idempotent)');
ok((string) Db::val('SELECT source FROM result_batches WHERE offering_id = ? AND external_ref LIKE "MAP-%" ORDER BY id DESC LIMIT 1', [$swe]) === 'lms', 'the batch is recorded as an LMS batch');
Integrations::reset();

// ---------------------------------------------------------------------------------------------
section('7. Check a mapping offline, before connecting anything (bin/mapping_check.php)');
$ctx = stream_context_create(['http' => ['header' => "Authorization: Bearer uni-token\r\nX-Api-Key: uni-api-key\r\n"]]);
$sampleTerms = (string) tempnam(sys_get_temp_dir(), 's') . '.json';
$sampleAssign = (string) tempnam(sys_get_temp_dir(), 's') . '.json';
$sampleGrades = (string) tempnam(sys_get_temp_dir(), 's') . '.json';
file_put_contents($sampleTerms, file_get_contents("$api/registry/v2/semesters?per_page=10", false, $ctx));
file_put_contents($sampleAssign, file_get_contents("$api/registry/v2/semesters/2026-2/teaching", false, $ctx));
$sampleAssign2 = (string) tempnam(sys_get_temp_dir(), 's') . '.json';
file_put_contents($sampleAssign2, file_get_contents("$api/registry/v2/semesters/2026-2/teaching?after=2", false, $ctx));
file_put_contents($sampleGrades, file_get_contents("$api/lms/v1/courses/2026-1-SWE401/marks?limit=100", false, $ctx));
[$code, $out] = run_cli([$sisFile, '--system=sis']);
ok($code === 0 && str_contains($out, 'the mapping is valid') && str_contains($out, 'pass --sample=terms'), 'a valid mapping with no sample: exit 0 and a hint to add a sample');
[$code, $out] = run_cli([$sisFile, '--system=sis', "--sample=terms=$sampleTerms", "--sample=assignments=$sampleAssign", "--sample=assignments=$sampleAssign2", '--term=2026-2']);
ok($code === 0 && str_contains($out, 'terms: 3 row(s) read, 3 accepted') && str_contains($out, 'assignments: 3 row(s) read, 3 accepted'), 'a saved SIS response (two pages of one list) is mapped and every row passes the live connector\'s checks');
ok(str_contains($out, 'o***@yu.example') && !str_contains($out, 'omar.sim@'), 'instructor e-mail addresses are masked in the output');
[$code, $out] = run_cli([$lmsFile, '--system=lms', "--sample=grades=$sampleGrades"]);
ok($code === 0 && str_contains($out, '12 student(s)') && str_contains($out, 'Midterm exam') && !str_contains($out, '209900001') && str_contains($out, 'shown nowhere'), 'a saved gradebook response is summarised without any student identifier');
[$code, $out] = run_cli([$lmsFile, '--system=lms', "--sample=grades=$sampleGrades"], ['SAQF_DB_HOST' => '127.0.0.1', 'SAQF_DB_PORT' => '1', 'SAQF_APP_KEY' => '']);
ok($code === 0 && str_contains($out, '12 student(s)'), 'the checker needs no database: with the database unreachable and no key set, a gradebook sample is still checked');
[$code, $out] = run_cli([mapping_file(with($sisMap, 'version', 3)), '--system=sis']);
ok($code === 1 && str_contains($out, 'problem'), 'an invalid mapping exits 1 and lists what is wrong');
$broken = (string) tempnam(sys_get_temp_dir(), 's') . '.json';
file_put_contents($broken, json_encode(['payload' => ['nothing' => []]]));
[$code, $out] = run_cli([$sisFile, '--system=sis', "--sample=terms=$broken"]);
ok($code === 1 && str_contains($out, 'payload.rows'), 'a sample that does not match the mapping exits 1 and names the path it looked for');
[$code] = run_cli([$sisFile]);
ok($code === 2, 'missing arguments exit 2');
[$code] = run_cli([$sisFile, '--system=lms']);
ok($code === 1, 'an SIS mapping checked as an LMS mapping fails');

// ---------------------------------------------------------------------------------------------
section('8. Go-live shows what is still needed, and says SIMULATED');
putenv('SAQF_SIS_SOURCE=mapped');
putenv("SAQF_SIS_MAPPING=$sisFile");
putenv('SAQF_SIS_TOKEN');
putenv('SAQF_SIS_URL');
Integrations::reset();
$find = static function (string $key): array {
    foreach (GoLive::systems() as $s) {
        if ($s['key'] === $key) {
            return $s;
        }
    }
    return [];
};
$g = $find('sis');
ok($g['mode'] === 'live' && $g['headline'] === 'SIS API through a mapping file' && in_array('SAQF_SIS_URL', $g['missing'], true) && in_array('SAQF_SIS_TOKEN', $g['missing'], true), 'the SIS card lists the address and token that are still missing: ' . implode(', ', $g['missing']));
putenv("SAQF_SIS_URL=$api");
putenv('SAQF_SIS_TOKEN=uni-token');
Integrations::reset();
$g = Gl_find('sis');
ok(!$g['missing'] && str_contains($g['detail'], 'SIMULATED'), 'with everything set, the card shows the mapping\'s name and that it is simulated');
ok(($find('sis')['next'] ?? '') === 'Press "Test connections".', 'and the next step is to test the connection');
putenv('SAQF_SIS_MAPPING=docs/mappings/does-not-exist.json');
Integrations::reset();
ok(str_contains(implode(' ', Gl_find('sis')['missing']), 'a valid mapping file'), 'an unreadable mapping is reported as the thing to fix');
putenv('SAQF_LMS_URL=http://lms.example.invalid');
ok((bool) array_filter(Http::readiness(), static fn($r) => $r['setting'] === 'SAQF_LMS_URL'), 'a plain http:// LMS address is flagged by the production-readiness check');
foreach (['SAQF_SIS_SOURCE', 'SAQF_SIS_MAPPING', 'SAQF_SIS_URL', 'SAQF_SIS_TOKEN', 'SAQF_LMS_URL', 'SAQF_LMS_TOKEN'] as $k) {
    putenv($k);
}
Integrations::reset();
ok(in_array('mapped', Integrations::SIS_KINDS, true) && in_array('mapped', Integrations::LMS_KINDS, true), 'SAQF_SIS_SOURCE and SAQF_LMS_SOURCE accept "mapped"');
foreach (['SAQF_SIS_CLIENT_SECRET', 'SAQF_LMS_TOKEN', 'SAQF_LMS_CLIENT_SECRET'] as $k) {
    ok(in_array($k, Config::SECRET_KEYS, true), "$k can be given as a secret file (…_FILE)");
}

finish();

function Gradebook_system(): string
{
    return \Saqf\Integration\Gradebook::SYSTEM;
}

function Gl_find(string $key): array
{
    foreach (GoLive::systems() as $s) {
        if ($s['key'] === $key) {
            return $s;
        }
    }
    return [];
}
