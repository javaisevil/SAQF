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
use Saqf\Demo\Story;
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

/** True when any PHP session file on this machine contains $needle (the preview is held in the session). */
function session_files_contain(string $needle): bool
{
    $dir = ini_get('session.save_path') ?: sys_get_temp_dir();
    $dir = preg_replace('/^\d+;(?:0?\d+;)?/', '', (string) $dir);
    foreach (glob(rtrim($dir, '/') . '/sess_*') ?: [] as $f) {
        if (is_file($f) && str_contains((string) @file_get_contents($f), $needle)) {
            return true;
        }
    }
    return false;
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
ok($code === 302 && str_contains($after, 'Check before importing') && str_contains($after, 'Nothing imported yet'), 'a manual upload with raw student numbers is first shown as a preview (nothing imported yet)');
ok(!str_contains($after, $raw[0]) && !str_contains($after, $raw[1]), 'the preview never shows a student number');
ok(!Db::val('SELECT 1 FROM assessment_results WHERE offering_id = ? AND assessment_id IN (SELECT id FROM assessments WHERE name = ?) AND score_pct = 77', [$o['id'], $names[0]]), 'nothing is written until a person confirms');
ok(!session_files_contain($raw[0]) && !session_files_contain($raw[1]), 'the server-side session holding the preview contains no student number either');
[$code] = http($omar, "$app/workspace.php?id={$o['id']}", ['_csrf' => csrf_of($after), 'op' => 'gb_confirm']);
[, $after] = http($omar, "$app/workspace.php?id={$o['id']}&tab=results");
ok($code === 302 && str_contains($after, '2 results imported'), 'confirming imports the marks (2 results)');
[$code, , ] = http($omar, "$app/workspace.php?id={$o['id']}", ['_csrf' => csrf_of($after), 'op' => 'gb_confirm']);
[, $after] = http($omar, "$app/workspace.php?id={$o['id']}&tab=results");
ok(str_contains($after, 'preview expired or had nothing to import'), 'a second confirmation has nothing left to import (the preview is single-use)');
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

// ---------------------------------------------------------------------------------------------
section('7. Integration dry run: counts and rejected rows, nothing written, no identities printed');
$dry = tempdir('saqf-dry');
write_csv("$dry/sis/terms.csv", [['code', 'name', 'academic_year', 'sequence', 'starts_on', 'ends_on', 'grades_due_on'], ['2026-1', 'Fall 2026', '2026-2027', '7', '2026-08-23', '2026-12-20', '2026-12-30']]);
write_csv("$dry/sis/assignments.csv", [['term', 'course', 'instructor_id', 'instructor_name', 'section'], ['2026-1', 'SWE 401', 'YU-T1', 'Dr. Synthetic', '01'], ['2026-1', 'ZZZ 100', 'YU-T2', 'Dr. Synthetic Two', '01'], ['2026-1', 'SWE 302', '', '', '']]);
write_csv("$dry/lms/2026-1/SWE401/mid.csv", [['student', $names[0], 'Attendance'], ['209955501', '77', '100'], ['209955502', '64', '90']]);
write_csv("$dry/lms/2026-1/SWE401/bad.csv", [['student', $names[0]], ['209955503', '150']]);
$counts = static fn() => [Db::val('SELECT COUNT(*) FROM terms'), Db::val('SELECT COUNT(*) FROM course_offerings'), Db::val('SELECT COUNT(*) FROM result_batches'), Db::val('SELECT COUNT(*) FROM assessment_results'), Db::val('SELECT COUNT(*) FROM audit_log')];
$before = $counts();
[$exit, $out] = run_cmd([PHP_BINARY, 'bin/dry_run.php', 'sis', "$dry/sis"]);
ok($exit === 1 && str_contains($out, '3 assignment row(s): 1 clean, 1 with a warning, 1 rejected') && str_contains($out, 'ZZZ 100 is not in the Registrar catalogue'), 'SIS dry run: row counts, the unknown course rejected, the row without an instructor warned about');
[$exit, $out] = run_cmd([PHP_BINARY, 'bin/dry_run.php', 'lms', "$dry/lms"]);
ok($exit === 1 && str_contains($out, '2 student(s), 1 column(s) match the specification') && str_contains($out, 'ignored: Attendance') && str_contains($out, 'REJECTED 2026-1/SWE401/bad.csv'), 'LMS dry run: students counted, matching and ignored columns, the faulty file rejected with its line');
ok(!str_contains($out, '2099555'), 'no student number is printed');
ok($counts() === $before, 'nothing was written to the database (terms, courses, grade batches, results, audit log unchanged)');

// ---------------------------------------------------------------------------------------------
section('8. Secrets from files, headers, security.txt');
$sf = tempdir('saqf-secrets');
file_put_contents("$sf/dbpass", "from-a-file-secret\n");
putenv("SAQF_DB_PASS_FILE=$sf/dbpass");
$savedPass = getenv('SAQF_DB_PASS');
putenv('SAQF_DB_PASS=');
ok(Config::get('SAQF_DB_PASS') === 'from-a-file-secret' && Config::isFromFile('SAQF_DB_PASS'), 'a secret supplied as <KEY>_FILE is read from the file (trailing newline removed) and reported as file-based');
putenv('SAQF_DB_PASS=explicit-value');
ok(Config::get('SAQF_DB_PASS') === 'explicit-value' && !Config::isFromFile('SAQF_DB_PASS'), 'an explicit value wins over the file');
putenv($savedPass === false ? 'SAQF_DB_PASS' : 'SAQF_DB_PASS=' . $savedPass);
putenv('SAQF_DB_PASS_FILE');
file_put_contents("$sf/other", "not-a-secret-key\n");
putenv("SAQF_SOMETHING_ELSE_FILE=$sf/other");
ok(Config::get('SAQF_SOMETHING_ELSE') === null, 'only the named secret settings accept *_FILE (no surprises for ordinary settings)');
putenv('SAQF_SOMETHING_ELSE_FILE');
file_put_contents("$sf/huge", str_repeat('x', 20000));
putenv("SAQF_MOODLE_TOKEN_FILE=$sf/huge");
ok(Config::get('SAQF_MOODLE_TOKEN') === null, 'an oversized secret file is ignored');
putenv('SAQF_MOODLE_TOKEN_FILE');

$keyFile = "$sf/appkey";
file_put_contents($keyFile, bin2hex(random_bytes(32)) . "\n");
Db::exec('DELETE FROM system_settings WHERE setting_key = "app.key"');
$prodFile = serve(dirname(__DIR__) . '/public', ['APP_ENV' => 'production', 'SAQF_DEMO' => 'false', 'SAQF_APP_KEY' => '', 'SAQF_APP_KEY_FILE' => $keyFile]);
[$code, $body] = http(jar(), "$prodFile/health.php");
ok($code === 200 && (json_decode($body, true)['app_key'] ?? '') === 'ok' && Secrets::storedKey() === null, 'production with SAQF_APP_KEY_FILE: health says the key is ok and nothing was stored in the database');
Db::exec('INSERT INTO system_settings (setting_key, value, updated_at) VALUES ("app.key", ?, NOW())', [$stored]);

$ch = curl_init("$app/login.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
$raw = (string) curl_exec($ch);
curl_close($ch);
ok(stripos($raw, "Cache-Control: no-store") !== false, 'pages are sent with Cache-Control: no-store (nothing cached after sign-out)');
$lj = jar();
[, $html] = http($lj, "$app/login.php");
$ch = curl_init("$app/logout.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['_csrf' => csrf_of($html)]), CURLOPT_COOKIEFILE => $lj, CURLOPT_COOKIEJAR => $lj]);
$raw = (string) curl_exec($ch);
curl_close($ch);
ok(stripos($raw, 'Clear-Site-Data: "cache", "storage"') !== false, 'signing out asks the browser to clear cached pages and site storage');
[$code] = http(jar(), "$app/security_txt.php");
ok($code === 404, 'security.txt names no contact (404) until the university configures one');
$secTxt = serve(dirname(__DIR__) . '/public', ['SAQF_SECURITY_CONTACT' => 'mailto:security@example.edu', 'SAQF_BASE_URL' => 'https://saqf.example.edu']);
[$code, $body] = http(jar(), "$secTxt/security_txt.php");
ok($code === 200 && str_contains($body, 'Contact: mailto:security@example.edu') && str_contains($body, 'Expires: ') && str_contains($body, 'Canonical: https://saqf.example.edu/.well-known/security.txt'), 'with a contact configured it serves an RFC 9116 security.txt');
$badTxt = serve(dirname(__DIR__) . '/public', ['SAQF_SECURITY_CONTACT' => 'javascript:alert(1)']);
[$code] = http(jar(), "$badTxt/security_txt.php");
ok($code === 404, 'a contact that is not a mailto: or https:// address is never published');

// ---------------------------------------------------------------------------------------------
section('9. Production preflight');
[$exit, $out] = run_cmd([PHP_BINARY, 'bin/preflight.php', '--json']);
$pf = json_decode($out, true);
$byId = array_column($pf['checks'] ?? [], null, 'id');
ok($exit === 1 && ($pf['summary']['ready'] ?? true) === false, 'a demo installation is reported NOT READY (exit code 1)');
ok(!($byId['demo_accounts']['pass'] ?? true) && str_contains($byId['demo_accounts']['detail'], 'f.omar'), 'active accounts that still use the published demo password are named');
ok(($byId['audit_chain']['pass'] ?? false) && ($byId['audit_guard']['pass'] ?? false), 'the audit chain and its append-only triggers pass');
ok(!str_contains($out, $stored) && !str_contains($out, Story::PASSWORD), 'the report never prints the application key or the demo password');
[$exit, $text] = run_cmd([PHP_BINARY, 'bin/preflight.php']);
ok(str_contains($text, 'NOT READY for real users or real data') && str_contains($text, 'not a security approval') === false, 'the human report says NOT READY and offers a fix for each failing line');

// ---------------------------------------------------------------------------------------------
section('10. Restore drill');
if (!($hasTools && trim((string) shell_exec('command -v mysqld')) !== '' && trim((string) shell_exec('command -v mysql')) !== '')) {
    echo "  (skipped: the restore drill needs mysqld, mysql, mysqldump and openssl on this machine; not counted as passed)\n";
} else {
    $bd = tempdir('saqf-drill-b');
    $sd = tempdir('saqf-drill-s');
    $env = $db0 = ['SAQF_DB_HOST' => (string) Config::get('SAQF_DB_HOST', '127.0.0.1'), 'SAQF_DB_PORT' => (string) Config::get('SAQF_DB_PORT', '3306'), 'SAQF_DB_NAME' => (string) Config::get('SAQF_DB_NAME', 'saqf'),
        'SAQF_DB_USER' => (string) Config::get('SAQF_DB_USER', 'root'), 'SAQF_DB_PASS' => (string) Config::get('SAQF_DB_PASS', ''), 'SAQF_BACKUP_DIR' => $bd, 'SAQF_BACKUP_STATUS_DIR' => $sd,
        'SAQF_BACKUP_FILES_DIR' => \Saqf\Quality\Evidence::dir(), 'SAQF_BACKUP_PASSPHRASE' => 'drill-test-passphrase'];
    $rd = static fn() => json_decode((string) @file_get_contents("$sd/last-restore-drill.json"), true) ?: [];
    [$exit] = run_cmd(['sh', 'docker/backup.sh'], $env);
    ok($exit === 0 && count(glob("$bd/saqf-2*.audithead") ?: []) === 1, 'the backup records the audit-chain head it contains');
    [$exit, $out] = run_cmd(['sh', 'docker/restore_drill.sh'], $env);
    $r = $rd();
    ok($exit === 0 && ($r['ok'] ?? false) === true && ($r['audit_head'] ?? '') === 'match' && ($r['triggers'] ?? 0) >= 2 && ($r['evidence_missing'] ?? 1) === 0 && ($r['tables'] ?? 0) >= 20, 'the drill restores the newest backup into a scratch server: ' . ($r['tables'] ?? '?') . ' tables, ' . ($r['audit_entries'] ?? '?') . ' audit entries, chain head matches, triggers present, ' . ($r['evidence_checked'] ?? '?') . ' evidence files all in the archive');
    putenv("SAQF_BACKUP_MONITOR_DIR=$sd");
    ok(SecurityCenter::restoreDrill()['ok'] === true && (check_by_label('Restore drill')['ok'] ?? null) === true, 'the Security center shows the recorded drill');
    $pre = array_column(\Saqf\Security\Preflight::run(), null, 'id');
    ok($pre['restore_drill']['pass'] === true, 'and the preflight counts it');
    [$exit, $out] = run_cmd(['sh', 'docker/restore_drill.sh'], ['SAQF_BACKUP_PASSPHRASE' => 'wrong-passphrase'] + $env);
    ok($exit !== 0 && ($rd()['ok'] ?? true) === false && str_contains((string) ($rd()['message'] ?? ''), 'decrypted'), 'a wrong passphrase fails the drill and records the failure');
    $bak = glob("$bd/saqf-2*.sql.gz.enc")[0];
    file_put_contents($bak, 'x', FILE_APPEND);
    [$exit] = run_cmd(['sh', 'docker/restore_drill.sh'], $env);
    ok($exit !== 0 && str_contains((string) ($rd()['message'] ?? ''), 'checksum'), 'a backup that was altered after it was written is refused by its checksum');
    putenv('SAQF_BACKUP_MONITOR_DIR');
}

// ---------------------------------------------------------------------------------------------
section('11. Security incident register (notification clock)');
$it = as_user($app, 'it.admin');
[$code, $html] = http($it, "$app/incidents.php");
ok($code === 200 && str_contains($html, 'does not replace it') && str_contains($html, 'SAQF never contacts an authority'), 'administrators see the register, with the statement that decisions stay with people');
foreach (['f.omar', 'hod.ced', 'qa.director', 'dean.coe', 'vp.academic'] as $u) {
    [$c] = http(as_user($app, $u), "$app/incidents.php");
    if ($c !== 403) {
        ok(false, "$u must not open the incident register ($c)");
    }
}
ok(true, 'no other role can open the incident register (403)');
[$code, , $loc] = http($it, "$app/incidents.php", ['_csrf' => 'forged', 'op' => 'open', 'title' => 'Forged request', 'category' => 'other', 'severity' => 'low', 'detected_at' => '2026-10-01T09:00', 'description' => 'A forged request without the token']);
ok(!Db::val('SELECT 1 FROM security_incidents WHERE title = "Forged request"'), 'a request without the anti-forgery token registers nothing');
[, $html] = http($it, "$app/incidents.php");
http($it, "$app/incidents.php", ['_csrf' => csrf_of($html), 'op' => 'open', 'title' => 'Mailbox rule forwarded exports', 'category' => 'data_exposure', 'severity' => 'high', 'detected_at' => \Saqf\Core\Clock::now()->modify('-2 hours')->format('Y-m-d\TH:i'), 'personal_data' => '1', 'subjects' => '40', 'description' => 'Synthetic test incident: course exports were forwarded to an outside address.']);
$inc = Db::one('SELECT * FROM security_incidents WHERE title = "Mailbox rule forwarded exports"');
$c = \Saqf\Security\Incidents::clock($inc);
ok($inc && $c['state'] === 'open' && $c['hours_left'] > 69 && $c['hours_left'] < 71, 'a personal-data incident starts a 72-hour clock (about ' . ($c['hours_left'] ?? '?') . ' h left)');
ok((bool) Db::val('SELECT 1 FROM audit_log WHERE action = "incident.opened"') && (bool) Db::val('SELECT 1 FROM incident_events WHERE incident_id = ?', [$inc['id']]), 'registering it is in the audit log and the timeline');
Db::exec('UPDATE security_incidents SET detected_at = ? WHERE id = ?', [\Saqf\Core\Clock::now()->modify('-80 hours')->format('Y-m-d H:i:s'), $inc['id']]);
\Saqf\Security\Incidents::watch();
ok((bool) Db::val('SELECT 1 FROM alerts WHERE kind = "incident.overdue" AND severity = "critical" AND resolved_at IS NULL'), 'past the window, a critical IT alert is raised');
[, $html] = http($it, "$app/incidents.php?id={$inc['id']}");
ok(str_contains($html, 'Past the 72-hour window'), 'and the incident page says so');
http($it, "$app/incidents.php", ['_csrf' => csrf_of($html), 'op' => 'update', 'id' => $inc['id'], 'action' => 'authority_notified', 'note' => '']);
ok(Db::val('SELECT authority_notified_at FROM security_incidents WHERE id = ?', [$inc['id']]) === null, 'recording a notification without saying who and how is refused');
[, $html] = http($it, "$app/incidents.php?id={$inc['id']}");
http($it, "$app/incidents.php", ['_csrf' => csrf_of($html), 'op' => 'update', 'id' => $inc['id'], 'action' => 'authority_notified', 'note' => 'Filed by the data protection officer, reference TEST-1']);
ok(Db::val('SELECT authority_notified_at FROM security_incidents WHERE id = ?', [$inc['id']]) !== null && !Db::val('SELECT 1 FROM alerts WHERE kind = "incident.overdue" AND resolved_at IS NULL'), 'recording who notified the authority clears the alert');
[, $html] = http($it, "$app/incidents.php?id={$inc['id']}");
http($it, "$app/incidents.php", ['_csrf' => csrf_of($html), 'op' => 'update', 'id' => $inc['id'], 'action' => 'closed', 'note' => 'too short']);
ok(Db::val('SELECT status FROM security_incidents WHERE id = ?', [$inc['id']]) !== 'closed', 'an incident cannot be closed without saying how it ended');
[, $html] = http($it, "$app/incidents.php?id={$inc['id']}");
http($it, "$app/incidents.php", ['_csrf' => csrf_of($html), 'op' => 'update', 'id' => $inc['id'], 'action' => 'closed', 'note' => 'The rule was removed, the recipient confirmed deletion, mailbox rules are now reviewed monthly.']);
ok(Db::val('SELECT status FROM security_incidents WHERE id = ?', [$inc['id']]) === 'closed', 'with an explanation it closes');
throws(static fn() => \Saqf\Security\Incidents::update(Db::one('SELECT * FROM users WHERE username = "it.admin"'), (int) $inc['id'], 'note', 'late note'), 'a closed incident cannot be changed', InvalidArgumentException::class);
$noPd = \Saqf\Security\Incidents::create(Db::one('SELECT * FROM users WHERE username = "it.admin"'), 'Planned outage', 'availability', 'low', false, \Saqf\Core\Clock::now()->modify('-1 hour')->format('Y-m-d H:i:s'), 'Synthetic: the server was unavailable for ten minutes during an upgrade.', null);
ok(\Saqf\Security\Incidents::clock(Db::one('SELECT * FROM security_incidents WHERE id = ?', [$noPd]))['state'] === 'not_applicable', 'an incident without personal data starts no notification clock');

// ---------------------------------------------------------------------------------------------
section('12. Audit-chain witnesses (checkpoints kept outside the server)');
$w = \Saqf\Security\Witness::take('test');
ok($w && preg_match('/^SAQF-WITNESS\/1 [0-9a-f]{10} \S+ id=\d+ entries=\d+ sha256=[0-9a-f]{64}$/', $w['line']) === 1 && $w['sent_to'] === '', 'a witness line is produced; with no e-mail or webhook it says it stayed on the server');
$wc = check_by_label('Audit-chain witnesses');
ok(\Saqf\Security\Witness::external() === false && $wc !== null && $wc['ok'] === null && str_contains($wc['status'], 'cannot prove anything'), 'and the Security center does not claim it proves anything');
ok(\Saqf\Security\Witness::verifyLine($w['line'])['ok'], 'the witness verifies against the log it was taken from');
\Saqf\Core\Audit::asSystem(static fn() => \Saqf\Core\Audit::record('test.after_witness', 'system', null, 'activity after the checkpoint'));
ok(\Saqf\Security\Witness::verifyLine($w['line'])['ok'], 'later activity does not invalidate an earlier witness');
preg_match('/id=(\d+) entries=(\d+) sha256=([0-9a-f]{64})/', $w['line'], $wm);
ok(!\Saqf\Security\Witness::verifyLine(str_replace($wm[3], str_repeat('0', 64), $w['line']))['ok'], 'a wrong hash is detected');
ok(!\Saqf\Security\Witness::verifyLine(str_replace('entries=' . $wm[2], 'entries=' . ($wm[2] + 5), $w['line']))['ok'], 'a wrong entry count is detected (removed or inserted entries)');
ok(!\Saqf\Security\Witness::verifyLine(str_replace('id=' . $wm[1], 'id=99999999', $w['line']))['ok'], 'an entry that no longer exists is detected (truncated log)');
ok(!\Saqf\Security\Witness::verifyLine('not a witness')['ok'], 'text that is not a witness line is refused');
[$exit, $out] = run_cmd([PHP_BINARY, 'bin/verify_audit.php', '--witness', $w['line']]);
ok($exit === 0 && str_contains($out, 'unchanged'), 'the command line verifies a witness an administrator kept (exit 0)');
$sent = Db::val('SELECT COUNT(*) FROM audit_witnesses');
\Saqf\Security\Witness::nightly();
ok((int) Db::val('SELECT COUNT(*) FROM audit_witnesses') === $sent + 1 && !Db::val('SELECT 1 FROM alerts WHERE kind = "audit.witness" AND resolved_at IS NULL'), 'the nightly job checks the earlier witnesses, raises no alert, and takes a new one');
$wl = \Saqf\Security\Witness::take('test');
[, $html] = http($it, "$app/admin.php?tab=audit");
ok(str_contains($html, 'Witnessed checkpoints') && str_contains($html, 'SAQF-WITNESS/1') && str_contains($html, 'Verify it against the log'), 'the Activity log page lists the checkpoints and verifies a pasted one');
[$code, , $loc] = http($it, "$app/admin.php?tab=audit", ['_csrf' => csrf_of($html), 'op' => 'witness_verify', 'line' => $wl['line']]);
ok($code === 302, 'pasting a witness line is accepted through the page (token required)');

// A database administrator drops the triggers and rewrites history from entry 10 on, recomputing every hash.
// The activity-log check cannot see it; the witness IT already holds can.
$lineBefore = \Saqf\Security\Witness::take('test')['line'];
Db::pdo()->exec('DROP TRIGGER IF EXISTS audit_log_no_update');
Db::pdo()->exec('DROP TRIGGER IF EXISTS audit_log_no_delete');
$prev = (string) Db::val('SELECT hash FROM audit_log WHERE id = 9');
foreach (Db::all('SELECT * FROM audit_log WHERE id >= 10 ORDER BY id') as $row) {
    if ((int) $row['id'] === 10) {
        $row['summary'] = 'quietly rewritten history';
    }
    $row['prev_hash'] = $prev;
    $row['hash'] = hash('sha256', $prev . '|' . \Saqf\Core\Audit::canonical($row));
    Db::exec('UPDATE audit_log SET summary = ?, prev_hash = ?, hash = ? WHERE id = ?', [$row['summary'], $row['prev_hash'], $row['hash'], $row['id']]);
    $prev = $row['hash'];
}
ok(\Saqf\Core\Audit::verify()['ok'] === true, 'a consistent rewrite passes the ordinary chain check (the limitation the witness exists for)');
$v = \Saqf\Security\Witness::verifyLine($lineBefore);
ok(!$v['ok'] && str_contains($v['message'], 'rewritten'), 'the witness taken before the rewrite catches it: ' . $v['message']);
ok(\Saqf\Security\Witness::verifyStored()['failed'] !== [], 'so do the stored checkpoints');
\Saqf\Security\Witness::nightly();
ok((bool) Db::val('SELECT 1 FROM alerts WHERE kind = "audit.witness" AND severity = "critical" AND resolved_at IS NULL'), 'and the nightly job raises a critical alert');
[$exit] = run_cmd([PHP_BINARY, 'bin/verify_audit.php', '--witness', $lineBefore]);
ok($exit === 2, 'the command line exits with 2 for a rewritten history');

finish();
