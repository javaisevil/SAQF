<?php
declare(strict_types=1);

/**
 * Regression tests for the privacy and security hardening of SAQF 2.5:
 *   1. every gradebook path pseudonymises student identifiers before anything is stored or logged
 *      (manual upload through the web page, LMS export folder, the import itself)
 *   2. the application key: production never generates or stores one, readiness says so
 *   3. outbound connections: production refuses plain http:// for SIS, LMS, identity provider, webhook
 *   4. backups: the status reflects what the backup run recorded (evidence, encryption, second copy),
 *      including real runs of docker/backup.sh against this test database when mysqldump is available
 *   5. university sign-in: the identity provider's MFA claim is recorded, optionally required, never assumed
 *   6. the Security center and report state facts, label the self-test, and make no fixed assurances
 * Run against a FRESH demo database on a test server only (never personal or production data):
 *   php bin/install.php --demo --fresh && php tests/hardening_test.php
 */

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/support.php';

use Saqf\Core\Config;
use Saqf\Core\Db;
use Saqf\Core\Secrets;
use Saqf\Integration\FileLmsSource;
use Saqf\Integration\Gradebook;
use Saqf\Integration\Http;
use Saqf\Quality\Achievement;
use Saqf\Security\Oidc;
use Saqf\Security\SecurityCenter;
use Saqf\Security\SelfTest;

if (Config::env() === 'production') {
    fwrite(STDERR, "Refusing to run: this test changes data and must never run with APP_ENV=production.\n");
    exit(1);
}

function http(string $jar, string $url, $post = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'saqf-hardening-test', CURLOPT_HEADER => true]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) && !array_filter($post, static fn($v) => $v instanceof CURLFile) ? http_build_query($post) : $post);
    }
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $loc = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return [$code, substr($raw, $size), $loc];
}

function jar(): string
{
    return (string) tempnam(sys_get_temp_dir(), 'jar');
}

function csrf_of(string $html): string
{
    return preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

function as_user(string $base, string $username): string
{
    $j = jar();
    [, $html] = http($j, "$base/login.php");
    http($j, "$base/demo.php", ['_csrf' => csrf_of($html), 'as' => $username, 'next' => 'index.php']);
    return $j;
}

/** Every text column of every table that contains $needle (table.column => rows). */
function db_contains(string $needle): array
{
    $hits = [];
    $cols = Db::all('SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND DATA_TYPE IN ("char","varchar","tinytext","text","mediumtext","longtext","json")');
    foreach ($cols as $col) {
        $n = (int) Db::val('SELECT COUNT(*) FROM `' . $col['t'] . '` WHERE `' . $col['c'] . '` LIKE ?', ['%' . $needle . '%']);
        if ($n) {
            $hits[$col['t'] . '.' . $col['c']] = $n;
        }
    }
    return $hits;
}

/** @return array{0:int,1:string} exit code and combined output */
function run_cmd(array $cmd, array $env = []): array
{
    $base = getenv();
    foreach ($env as $k => $v) {
        if ($v === null) {
            unset($base[$k]);
        } else {
            $base[$k] = $v;
        }
    }
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $base);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($p), (string) $out];
}

function check_by_label(string $label): ?array
{
    foreach (SecurityCenter::checks() as $c) {
        if ($c['label'] === $label) {
            return $c;
        }
    }
    return null;
}

$app = serve(dirname(__DIR__) . '/public');
$serverLog = sys_get_temp_dir() . '/saqf-test-servers.log';

// ---------------------------------------------------------------------------------------------
section('1. Gradebook files: student numbers never reach the database or the logs');
$o = Db::one('SELECT o.id, o.spec_version_id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id WHERE c.code = "SWE 401" AND t.code = "2026-1"');
$names = Db::col('SELECT name FROM assessments WHERE spec_version_id = ? ORDER BY id', [$o['spec_version_id']]);
$raw = ['209912345', '209912346'];
$csv = (string) tempnam(sys_get_temp_dir(), 'gb') . '.csv';
write_csv($csv, [['student', $names[0]], [$raw[0], '77'], [$raw[1], '64.5']]);
$omar = as_user($app, 'f.omar');
[, $html] = http($omar, "$app/workspace.php?id={$o['id']}&tab=results");
ok(str_contains($html, 'name="results"') && str_contains($html, 'replaces it with a code before storing'), 'the course page offers the gradebook upload and says student numbers are replaced');
[$code, , $loc] = http($omar, "$app/workspace.php?id={$o['id']}", ['_csrf' => csrf_of($html), 'results' => new CURLFile($csv, 'text/csv', 'grades.csv')]);
[, $after] = http($omar, "$app/workspace.php?id={$o['id']}&tab=results");
ok($code === 302 && str_contains($after, '2 results imported'), 'a manual upload with raw student numbers is accepted (2 results)');
$expected = Secrets::pseudonym(Gradebook::SYSTEM, $raw[0]);
ok((float) Db::val('SELECT score_pct FROM assessment_results WHERE offering_id = ? AND student_ref = ?', [$o['id'], $expected]) === 77.0, "the score is stored under the keyed pseudonym $expected");
ok(!Db::val('SELECT 1 FROM assessment_results WHERE student_ref LIKE ? OR student_ref LIKE ?', ['%' . $raw[0] . '%', '%' . $raw[1] . '%']), 'no assessment record carries the student number');
$hits = array_merge(db_contains($raw[0]), db_contains($raw[1]));
ok(!$hits, 'the student numbers appear in no table and no column of the database (audit log, error log, batches included)' . ($hits ? ': ' . json_encode($hits) : ''));
ok((int) Db::val('SELECT COUNT(*) FROM audit_log WHERE action = "results.imported" AND object_id = ?', [(string) $o['id']]) >= 1, 'the import itself is audited (without identities)');

write_csv($csv, [['student', $names[0]], ['209912347', '150']]);
[, $html] = http($omar, "$app/workspace.php?id={$o['id']}&tab=results");
[$code] = http($omar, "$app/workspace.php?id={$o['id']}", ['_csrf' => csrf_of($html), 'results' => new CURLFile($csv, 'text/csv', 'bad.csv')]);
[, $after] = http($omar, "$app/workspace.php?id={$o['id']}&tab=results");
ok($code === 302 && str_contains($after, 'must be a percentage between 0 and 100') && !str_contains($after, '209912347'), 'a refused file explains the problem by line and assessment, never by student number');
ok(!db_contains('209912347'), 'nothing from the refused file is stored or logged');
clearstatcache();
$log = is_file($serverLog) ? (string) file_get_contents($serverLog) : '';
ok(!str_contains($log, '209912345') && !str_contains($log, '209912347'), 'the web server\'s error output does not contain the student numbers');

$dir = tempdir('saqf-lms');
@mkdir("$dir/2026-1/SWE401", 0700, true);
write_csv("$dir/2026-1/SWE401/export.csv", [['student', $names[0]], [$raw[0], '81']]);
touch("$dir/2026-1/SWE401/export.csv", time() - 120);
putenv('SAQF_LMS_PSEUDONYMIZE=false');
$batches = (new FileLmsSource($dir))->batches('2026-1', 'SWE 401');
putenv('SAQF_LMS_PSEUDONYMIZE');
$keys = array_keys($batches[0]['results'][$names[0]] ?? []);
ok($keys === [$expected], 'the LMS export folder pseudonymises too, even with SAQF_LMS_PSEUDONYMIZE=false (no opt-out), and with the same key as the manual upload, so both match');
throws(static fn() => Achievement::import((int) $o['id'], [$names[0] => ['209912348' => 70]], 'upload'), 'the import refuses a batch keyed by raw student numbers (defence in depth)', InvalidArgumentException::class);
ok(!db_contains('209912348'), '…and stores nothing from it');
throws(static fn() => Gradebook::parse($csv, ''), 'reading a gradebook without a pseudonym key space is impossible', InvalidArgumentException::class);
$privacy = check_by_label('Student privacy');
ok($privacy && $privacy['ok'] === true && str_contains($privacy['status'], 'manual gradebook upload'), 'the Security center states that every grade source is covered');

// ---------------------------------------------------------------------------------------------
section('2. Application key');
ok(Secrets::keyProblem('short') !== null && Secrets::keyProblem(str_repeat('ab', 20)) !== null && Secrets::keyProblem('change-me-change-me-change-me-1234567') !== null, 'short, repetitive and placeholder keys are refused');
ok(Secrets::keyProblem(bin2hex(random_bytes(32))) === null, 'a 64-character random key is accepted');
$stored = Secrets::storedKey();
ok($stored !== null && Secrets::keyStatus()['source'] === 'database' && Secrets::keyStatus()['ok'] === null, 'demo mode: a development key kept in the database is a note, not a failure');

putenv('APP_ENV=production');
Secrets::reset();
ok(Secrets::keyStatus()['ok'] === false && str_contains(Secrets::keyStatus()['message'], 'Move it to SAQF_APP_KEY'), 'production with a database-stored key is reported as not ready, with the migration step');
Db::exec('DELETE FROM system_settings WHERE setting_key = "app.key"');
Secrets::reset();
throws(static fn() => Secrets::appKey(), 'production without SAQF_APP_KEY refuses to create a key', RuntimeException::class);
ok(Secrets::storedKey() === null, '…and nothing was written to the database');
ok(Secrets::keyStatus()['source'] === 'none' && Secrets::keyStatus()['ok'] === false, 'readiness says the key is missing');
$strong = bin2hex(random_bytes(32));
putenv("SAQF_APP_KEY=$strong");
Secrets::reset();
ok(Secrets::appKey() === $strong && Secrets::keyStatus()['ok'] === true && Secrets::storedKey() === null, 'a strong SAQF_APP_KEY is used as given and never stored');
putenv('SAQF_APP_KEY=too-short');
ok(Secrets::keyStatus()['ok'] === false && str_contains(Secrets::keyStatus()['message'], 'too weak'), 'a weak SAQF_APP_KEY is reported as not ready');
putenv('SAQF_APP_KEY');
putenv('APP_ENV=local');
Db::exec('INSERT INTO system_settings (setting_key, value, updated_at) VALUES ("app.key", ?, NOW())', [$stored]);
Secrets::reset();
ok(Secrets::appKey() === $stored, 'the original key is back for the rest of the tests');
putenv("SAQF_APP_KEY=$strong");
ok(Secrets::keyStatus()['ok'] === null && str_contains(Secrets::keyStatus()['message'], 'differs from the key'), 'an environment key that differs from the stored one is flagged (pseudonyms would stop matching)');
putenv('SAQF_APP_KEY');
Secrets::reset();

$prod = serve(dirname(__DIR__) . '/public', ['APP_ENV' => 'production', 'SAQF_DEMO' => 'false', 'SAQF_APP_KEY' => '']);
[$code, $body] = http(jar(), "$prod/health.php");
ok($code === 200 && (json_decode($body, true)['app_key'] ?? '') === 'not ready (database)', 'health probe in production with a stored key: up, but app_key "not ready (database)"');
Db::exec('DELETE FROM system_settings WHERE setting_key = "app.key"');
[$code, $body] = http(jar(), "$prod/health.php");
ok($code === 503 && (json_decode($body, true)['status'] ?? '') === 'not ready', 'health probe in production with no key at all: 503 not ready');
[$code] = http(jar(), "$prod/login.php");
ok($code === 500 && Secrets::storedKey() === null, 'the sign-in page fails rather than silently creating a production key');
Db::exec('INSERT INTO system_settings (setting_key, value, updated_at) VALUES ("app.key", ?, NOW())', [$stored]);

$keyDb = Config::get('SAQF_DB_NAME', 'saqf') . '_keycheck';
[$exit, $out] = run_cmd([PHP_BINARY, 'bin/install.php'], ['APP_ENV' => 'production', 'SAQF_DB_NAME' => $keyDb, 'SAQF_APP_KEY' => null]);
$created = (bool) Db::val('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$keyDb]);
if ($created) {
    Db::exec("DROP DATABASE `$keyDb`");
}
ok($exit === 1 && str_contains($out, 'SAQF_APP_KEY is required in production') && !$created, 'a production install without SAQF_APP_KEY is refused before anything is created');
[$exit, $out] = run_cmd([PHP_BINARY, 'bin/app_key.php', 'status'], ['APP_ENV' => 'production']);
ok($exit === 2 && str_contains($out, 'NOT READY') && !str_contains($out, $stored), 'bin/app_key.php status reports "not ready" and never prints the key');
[$exit, $out] = run_cmd([PHP_BINARY, 'bin/app_key.php', 'forget-stored'], ['SAQF_APP_KEY' => $strong]);
ok($exit === 1 && Secrets::storedKey() === $stored, 'removing the stored key is refused unless SAQF_APP_KEY holds the same key');
[$exit, $out] = run_cmd([PHP_BINARY, 'bin/app_key.php', 'generate']);
ok($exit === 0 && Secrets::keyProblem(trim(strtok($out, "\n"))) === null, 'bin/app_key.php generate prints a strong key and stores nothing');

// ---------------------------------------------------------------------------------------------
section('3. Outbound connections: https only in production');
ok(Http::urlProblem('https://sis.example.edu', true) === null && Http::urlProblem('http://sis.example.edu', true) !== null && Http::urlProblem('ftp://x', false) !== null, 'production accepts https:// only; other schemes are never accepted');
ok(Http::urlProblem('http://127.0.0.1:8080', false) === null, 'local and demo mode keep plain http:// for stand-in servers');
ok(Http::request('GET', "$app/ping.php")['status'] > 0, '…and such a local request still works outside production');
putenv('APP_ENV=production');
throws(static fn() => Http::request('GET', "$app/ping.php"), 'in production a plain http:// request is refused before any connection', RuntimeException::class);
putenv('SAQF_OIDC_ISSUER=http://127.0.0.1:9');
putenv('SAQF_OIDC_CLIENT_ID=saqf-test');
try {
    Oidc::metadata();
    ok(false, 'an http:// identity provider is refused in production');
} catch (Throwable $e) {
    ok(str_contains($e->getMessage(), 'not allowed in production'), 'an http:// identity provider is refused in production — "' . mb_strimwidth($e->getMessage(), 0, 90, '…') . '"');
}
putenv('SAQF_SIS_URL=http://sis.example.edu/api');
$conn = check_by_label('Encrypted connections to university systems');
ok($conn && $conn['ok'] === false && str_contains($conn['status'], 'SAQF_SIS_URL') && str_contains($conn['status'], 'SAQF_OIDC_ISSUER'), 'the Security center names each plain-http setting as needing attention');
putenv('APP_ENV=local');
putenv('SAQF_OIDC_ISSUER');
putenv('SAQF_OIDC_CLIENT_ID');
putenv('SAQF_SIS_URL');
ok(count(Http::readiness()) === 0, 'with nothing configured there is nothing to flag');

// ---------------------------------------------------------------------------------------------
section('4. Backups: the status is what the backup run recorded');
$monitor = tempdir('saqf-backup-status');
putenv("SAQF_BACKUP_MONITOR_DIR=$monitor");
$status = static function (array $over) use ($monitor) {
    file_put_contents("$monitor/last-backup.json", json_encode($over + ['status' => 'ok', 'finished_at' => gmdate('Y-m-d\TH:i:s\Z'), 'file' => 'saqf-x.sql.gz.enc', 'bytes' => 1000, 'encrypted' => true, 'offsite' => true, 'files' => true, 'verified' => true, 'message' => '', 'files_status' => 'ok', 'offsite_status' => 'ok']));
};
ok(SecurityCenter::backupOk() === null && str_contains(SecurityCenter::backupLine(), 'cannot confirm any backup exists'), 'no status file: SAQF says it cannot confirm a backup (never "healthy")');
$status([]);
ok(SecurityCenter::backupOk() === true && str_contains(SecurityCenter::backupLine(), 'second location') && !str_contains(SecurityCenter::backupLine(), 'off-site'), 'a complete run is healthy; the copy is called a "second location", not proven off-site');
ok(str_contains(SecurityCenter::backupLine(), 'restore has not been tested'), '…and the line says a restore was not tested from SAQF');
$status(['files' => false, 'files_status' => 'not_configured']);
ok(SecurityCenter::backupOk() === false && str_contains(SecurityCenter::backupLine(), 'evidence files NOT included although SAQF holds evidence'), 'a database-only backup is not healthy while SAQF holds evidence files');
$status(['encrypted' => false, 'offsite' => false, 'offsite_status' => 'not_configured']);
ok(SecurityCenter::backupOk() === null && str_contains(SecurityCenter::backupLine(), 'NOT encrypted') && str_contains(SecurityCenter::backupLine(), 'no second copy'), 'outside production, a backup without encryption or second copy is a note');
putenv('APP_ENV=production');
ok(SecurityCenter::backupOk() === false, 'in production the same backup is not healthy');
putenv('APP_ENV=local');
$status(['verified' => false]);
ok(SecurityCenter::backupOk() === false, 'an unverified backup is not healthy');

$hasTools = trim((string) shell_exec('command -v mysqldump')) !== '' && trim((string) shell_exec('command -v openssl')) !== '';
if (!$hasTools) {
    echo "  (skipped: docker/backup.sh runs need mysqldump and openssl on this machine; not counted as passed)\n";
} else {
    $bdir = tempdir('saqf-bk');
    $sdir = tempdir('saqf-bk-status');
    $odir = tempdir('saqf-bk-second');
    $edir = tempdir('saqf-bk-evidence');
    file_put_contents("$edir/sample.pdf", '%PDF-1.4 synthetic evidence');
    $db = ['SAQF_DB_HOST' => (string) Config::get('SAQF_DB_HOST', '127.0.0.1'), 'SAQF_DB_PORT' => (string) Config::get('SAQF_DB_PORT', '3306'), 'SAQF_DB_NAME' => (string) Config::get('SAQF_DB_NAME', 'saqf'),
        'SAQF_DB_USER' => (string) Config::get('SAQF_DB_USER', 'root'), 'SAQF_DB_PASS' => (string) Config::get('SAQF_DB_PASS', ''), 'SAQF_BACKUP_DIR' => $bdir, 'SAQF_BACKUP_STATUS_DIR' => $sdir];
    $read = static fn() => json_decode((string) @file_get_contents("$sdir/last-backup.json"), true) ?: [];
    [$exit, $out] = run_cmd(['sh', 'docker/backup.sh'], $db + ['SAQF_BACKUP_FILES_DIR' => $edir, 'SAQF_BACKUP_PASSPHRASE' => 'test-only-passphrase', 'SAQF_BACKUP_OFFSITE_DIR' => $odir]);
    $s = $read();
    ok($exit === 0 && ($s['status'] ?? '') === 'ok' && $s['encrypted'] === true && $s['offsite'] === true && $s['files'] === true && ($s['files_status'] ?? '') === 'ok' && ($s['offsite_status'] ?? '') === 'ok', 'a full run of docker/backup.sh records database, evidence, encryption and second copy as verified');
    ok(count(glob("$odir/saqf-*.enc") ?: []) === 2 && !str_contains((string) file_get_contents((string) (glob("$bdir/saqf-2*.sql.gz.enc")[0] ?? '/dev/null')), 'CREATE TABLE'), 'both archives are encrypted and copied to the second location');
    [$exit] = run_cmd(['sh', 'docker/backup.sh'], $db + ['SAQF_BACKUP_FILES_DIR' => "$edir-missing", 'SAQF_BACKUP_PASSPHRASE' => 'test-only-passphrase']);
    $s = $read();
    ok($exit !== 0 && ($s['status'] ?? '') === 'failed' && ($s['files_status'] ?? '') === 'failed' && str_contains((string) ($s['message'] ?? ''), 'evidence'), 'a successful database dump with a failed evidence archive is recorded as FAILED, not ok');
    putenv("SAQF_BACKUP_MONITOR_DIR=$sdir");
    ok(SecurityCenter::backupOk() === false && str_contains(SecurityCenter::backupLine(), 'LAST BACKUP FAILED'), 'and SAQF shows it as failed');
    if (!is_dir('/evidence')) {
        [$exit] = run_cmd(['sh', 'docker/backup.sh'], $db + ['SAQF_BACKUP_FILES_DIR' => null, 'SAQF_BACKUP_PASSPHRASE' => null, 'SAQF_BACKUP_OFFSITE_DIR' => null]);
        $s = $read();
        ok($exit === 0 && $s['encrypted'] === false && $s['offsite'] === false && ($s['files_status'] ?? '') === 'not_configured', 'a database-only, unencrypted run says exactly that');
        ok(SecurityCenter::backupOk() === false, '…which SAQF does not call healthy while it holds evidence files');
    }
}
putenv('SAQF_BACKUP_MONITOR_DIR');

// ---------------------------------------------------------------------------------------------
section('5. University sign-in: MFA is the identity provider\'s, and SAQF says so');
ok(Oidc::mfaReported(['amr' => ['pwd', 'mfa']]) === true && Oidc::mfaReported(['amr' => ['pwd']]) === false && Oidc::mfaReported([]) === null, 'the "amr" claim is read as reported / not reported / absent');
$idp = serve(__DIR__ . '/mock/idp.php');
$idpState = sys_get_temp_dir() . '/saqf-mock-idp-' . parse_url($idp, PHP_URL_PORT);
@mkdir($idpState, 0700, true);
$ssoEnv = ['SAQF_OIDC_ISSUER' => $idp, 'SAQF_OIDC_CLIENT_ID' => 'saqf-test', 'SAQF_OIDC_CLIENT_SECRET' => 'test-secret', 'SAQF_PASSWORD_LOGIN' => 'admins'];
$sso = static function (string $base, array $claims) use ($idpState): string {
    $j = jar();
    file_put_contents("$idpState/next.json", json_encode(['claims' => $claims]));
    [$code, , $toIdp] = http($j, "$base/sso.php?start=1");
    if ($code !== 302) {
        return 'start failed';
    }
    [, , $callback] = http($j, $toIdp);
    [, $body] = http($j, $callback);
    return preg_match('/url=([a-z.]+)/', $body, $m) ? $m[1] : 'none';
};
$p1 = free_port();
serve(dirname(__DIR__) . '/public', $ssoEnv + ['SAQF_BASE_URL' => "http://127.0.0.1:$p1"], $p1);
ok($sso("http://127.0.0.1:$p1", ['sub' => 'omar-1', 'preferred_username' => 'f.omar']) === 'index.php', 'by default a university sign-in without an MFA claim is accepted (the provider\'s policy applies)');
ok((Oidc::lastMfaObservation()['reported'] ?? '') === 'not_reported', '…and SAQF records that the provider did not report MFA');
putenv("SAQF_OIDC_ISSUER=$idp");
putenv('SAQF_OIDC_CLIENT_ID=saqf-test');
$c = check_by_label('Two-step verification for university sign-in');
ok($c && $c['ok'] === null && str_contains($c['status'], 'Not enforced by SAQF') && str_contains($c['status'], 'University IT must confirm'), 'the Security center says SAQF does not enforce MFA for university sign-in and IT must confirm the policy');
$p2 = free_port();
serve(dirname(__DIR__) . '/public', $ssoEnv + ['SAQF_BASE_URL' => "http://127.0.0.1:$p2", 'SAQF_OIDC_REQUIRE_MFA' => 'true'], $p2);
ok($sso("http://127.0.0.1:$p2", ['sub' => 'omar-1', 'preferred_username' => 'f.omar', 'amr' => ['pwd']]) === 'login.php', 'with SAQF_OIDC_REQUIRE_MFA=true a sign-in reporting password only is refused');
ok($sso("http://127.0.0.1:$p2", ['sub' => 'omar-1', 'preferred_username' => 'f.omar']) === 'login.php', '…and so is one that does not report MFA at all');
ok($sso("http://127.0.0.1:$p2", ['sub' => 'omar-1', 'preferred_username' => 'f.omar', 'amr' => ['pwd', 'mfa']]) === 'index.php', '…while one reporting MFA is accepted');
putenv('SAQF_OIDC_REQUIRE_MFA=true');
$c = check_by_label('Two-step verification for university sign-in');
ok($c && $c['ok'] === true && str_contains($c['status'], 'checked by the identity provider, not by SAQF'), 'with the requirement on, the Security center still says the second factor itself is the provider\'s');
putenv('SAQF_OIDC_REQUIRE_MFA');
putenv('SAQF_OIDC_ISSUER');
putenv('SAQF_OIDC_CLIENT_ID');

// ---------------------------------------------------------------------------------------------
section('6. Security center and report: facts, not assurances');
$scan = check_by_label('Evidence virus scanning');
ok($scan && $scan['ok'] === null && str_contains($scan['status'], 'NOT configured') && str_contains($scan['status'], 'stored without a scan'), 'without a scanner the page says files are not scanned, with the recorded counts');
$audit = check_by_label('Tamper-evident audit log');
ok($audit && str_contains($audit['status'], 'not tamper-proof') && str_contains($audit['status'], 'triggers are installed'), 'the audit log is described as tamper-evident, not tamper-proof, with the trigger state read from the database');
$st = array_column(SelfTest::run(), null, 'name');
ok(isset($st['Gradebook files never keep student numbers']) && $st['Gradebook files never keep student numbers']['ok'], 'the self-test exercises the real gradebook reader');
ok(str_contains(SelfTest::DISCLAIMER, 'not a penetration test'), 'the self-test is labelled as a self-test, not a penetration test');
$it = as_user($app, 'it.admin');
[$code, $html] = http($it, "$app/security_report.php");
$text = html_entity_decode(strip_tags($html));
ok($code === 200 && str_contains($text, 'Self-assessment') && str_contains($text, 'not a penetration test, an independent audit or a certification'), 'the security report calls itself a self-assessment');
ok(str_contains($text, 'NO virus scanner is configured') && str_contains($text, 'single sign-on is not configured'), 'its set-up statements follow this installation\'s configuration (no scanner, no SSO in the demo)');
ok(!str_contains($text, 'Uploaded evidence is virus-scanned') && !str_contains($text, 'Backups are encrypted and copied off-site') && str_contains($text, 'tamper-evident, not tamper-proof'), 'none of the former fixed assurances (virus-scanned, encrypted off-site backups) remain; the audit log is called tamper-evident');
[$code, $html] = http($it, "$app/admin.php?tab=golive");
ok($code === 200 && !str_contains($html, '>Live<') && str_contains($html, 'counts as working only after'), 'Go-live never labels a connection "Live" just because it is selected');

finish();
