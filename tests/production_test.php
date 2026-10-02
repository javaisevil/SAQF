<?php
declare(strict_types=1);

/**
 * Production capability tests: university-system connectors, automatic semester cycle,
 * account provisioning, e-mail, password reset and SSO token validation.
 * Run against a FRESH demo database (the tests change data — reinstall afterwards):
 *   php bin/install.php --demo --fresh && php tests/production_test.php
 * Moodle, Blackboard, a SIS API and an SMTP server are played by local stand-ins (tests/mock);
 * nothing leaves the machine.
 */

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/support.php';

use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Db;
use Saqf\Core\Mailer;
use Saqf\Core\Migrations;
use Saqf\Core\Notify;
use Saqf\Core\Policy;
use Saqf\Core\Secrets;
use Saqf\Integration\BlackboardLmsSource;
use Saqf\Integration\FileLmsSource;
use Saqf\Integration\FileSisSource;
use Saqf\Integration\Integrations;
use Saqf\Integration\MoodleLmsSource;
use Saqf\Integration\NullLmsSource;
use Saqf\Integration\RestSisSource;
use Saqf\Quality\Achievement;
use Saqf\Quality\Scheduler;
use Saqf\Security\Auth;
use Saqf\Security\Jwt;
use Saqf\Security\JwtKeyNotFound;
use Saqf\Security\Oidc;
use Saqf\Security\PasswordReset;
use Saqf\Security\SsoException;
use Saqf\Security\Users;

$mock = __DIR__ . '/mock';
$offering = static fn(string $course, string $term): int => (int) Db::val('SELECT o.id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id WHERE c.code = ? AND t.code = ?', [$course, $term]);
$swe401 = $offering('SWE 401', '2026-1');
$score = static fn(int $oid, string $assessment, string $student) => Db::val('SELECT r.score_pct FROM assessment_results r JOIN assessments a ON a.id = r.assessment_id WHERE r.offering_id = ? AND a.name = ? AND r.student_ref = ?', [$oid, $assessment, $student]);
$lastAudit = static fn(string $action) => (string) Db::val('SELECT summary FROM audit_log WHERE action = ? ORDER BY id DESC LIMIT 1', [$action]);
Clock::set('2026-10-05 09:00:00');

section('1. Upgrades and secrets');
ok(Migrations::pending() === [], 'every database migration is applied on a fresh install');
ok(count(Migrations::statements("-- c\nCREATE TABLE a (x INT);\n\nALTER TABLE a ADD y INT;")) === 2, 'migration files are split into statements (comments ignored)');
$p1 = Secrets::pseudonym('lms', '202600001');
ok($p1 === Secrets::pseudonym('lms', '202600001') && strlen($p1) === 16 && $p1 !== Secrets::pseudonym('moodle', '202600001'), "student identifiers become stable keyed pseudonyms ($p1), different per source system");

section('2. LMS: gradebook export folder');
$lmsDir = tempdir('saqf-lms');
$courseDir = "$lmsDir/2026-1/SWE401";
write_csv("$courseDir/midterm-export.csv", [['student', 'Midterm exam', 'Attendance'], ['202600001', '77', '100'], ['202600002', '64.5', '90'], ['202600003', '91', '95']]);
write_csv("$courseDir/broken.csv", [['student', 'Quiz'], ['202600001', '150']]);
write_csv("$courseDir/zz-being-written.csv", [['student', 'Quiz'], ['202600001', '70']]);
touch("$courseDir/midterm-export.csv", time() - 120);
touch("$courseDir/broken.csv", time() - 120);
$fileLms = new FileLmsSource($lmsDir);
ok($fileLms->check()['ok'], 'connection test: ' . $fileLms->check()['message']);
Integrations::use(null, null, $fileLms);
ok(Achievement::syncFromLms($swe401) === 1, 'the export is imported by the LMS pipeline (one batch)');
ok((float) $score($swe401, 'Midterm exam', Secrets::pseudonym('lms', '202600001')) === 77.0, 'scores land under the pseudonymous key — no student number is stored');
ok(!Db::val('SELECT 1 FROM assessment_results WHERE student_ref LIKE "2026%"'), 'raw student numbers never reach the database');
ok(str_contains($lastAudit('results.columns_ignored'), 'Attendance'), 'gradebook columns outside the specification are ignored and noted: "' . mb_strimwidth($lastAudit('results.columns_ignored'), 0, 80, '…') . '"');
ok((bool) Db::val('SELECT 1 FROM system_errors WHERE message LIKE "%broken.csv%"'), 'an invalid export (score 150) is skipped and reported in the error log');
ok(!$score($swe401, 'Quiz', Secrets::pseudonym('lms', '202600001')), 'a file still being written (modified seconds ago) is left for the next run');
ok(Achievement::syncFromLms($swe401) === 0, 'an unchanged export is not imported twice');
write_csv("$courseDir/midterm-export.csv", [['student', 'Midterm exam'], ['202600001', '81']]);
touch("$courseDir/midterm-export.csv", time() - 120);
ok(Achievement::syncFromLms($swe401) === 1 && (float) $score($swe401, 'Midterm exam', Secrets::pseudonym('lms', '202600001')) === 81.0, 'a corrected export is re-imported and the score updated');

section('3. LMS: Moodle web services');
$moodleUrl = serve("$mock/moodle.php");
$moodle = new MoodleLmsSource($moodleUrl, 'moodle-test-token');
$check = $moodle->check();
ok($check['ok'] && str_contains($check['message'], 'YU Moodle'), 'connection test: ' . $check['message']);
$bad = (new MoodleLmsSource($moodleUrl, 'wrong-token'))->check();
ok(!$bad['ok'] && str_contains($bad['message'], 'Invalid token'), 'a wrong token is reported: ' . $bad['message']);
ok(Integrations::lmsCourseKey('2026-1', 'SWE 401') === '2026-1-SWE401', 'courses are matched by id number 2026-1-SWE401 (SAQF_LMS_COURSE_KEY)');
$batches = $moodle->batches('2026-1', 'SWE 401');
$r = $batches[0]['results'] ?? [];
ok(count($batches) === 1 && array_keys($r) === ['Attendance', 'Midterm exam', 'Quiz'], 'graded items read; course total, hidden and ungraded items skipped');
ok(($r['Midterm exam'][Secrets::pseudonym('moodle', '101')] ?? null) === 90.0 && ($r['Quiz'][Secrets::pseudonym('moodle', '104')] ?? null) === 75.0, 'raw grades converted to percentages with each item\'s own range (18/20 → 90%, 7.5/10 → 75%)');
ok($moodle->batches('2026-1', 'SWE 999') === [], 'a course that is not in Moodle yields nothing');
Integrations::use(null, null, $moodle);
ok(Achievement::syncFromLms($swe401) === 1 && (float) $score($swe401, 'Quiz', Secrets::pseudonym('moodle', '103')) === 100.0, 'Moodle results flow through the achievement pipeline');
ok(Achievement::syncFromLms($swe401) === 0, 'unchanged Moodle grades are not imported twice');

section('4. LMS: Blackboard Learn REST');
$bbUrl = serve("$mock/blackboard.php");
$bb = new BlackboardLmsSource($bbUrl, 'bb-key', 'bb-secret');
$check = $bb->check();
ok($check['ok'] && str_contains($check['message'], '3900'), 'connection test (OAuth2 client credentials): ' . $check['message']);
$bad = (new BlackboardLmsSource($bbUrl, 'bb-key', 'wrong'))->check();
ok(!$bad['ok'] && str_contains($bad['message'], '401'), 'wrong credentials are reported: ' . $bad['message']);
$r = $bb->batches('2026-1', 'SWE 401')[0]['results'] ?? [];
ok(($r['Midterm exam'][Secrets::pseudonym('blackboard', '_501_1')] ?? null) === 80.0 && ($r['Midterm exam'][Secrets::pseudonym('blackboard', '_502_1')] ?? null) === 50.0, 'scores converted to percentages of points possible (40/50 → 80%)');
ok(isset($r['Quiz']) && !isset($r['Total']) && count($r['Midterm exam'] ?? []) === 2, 'paged columns read; external total column and ungraded attempts skipped');
ok($bb->batches('2026-1', 'CIS 443') === [], 'a course missing in Learn (HTTP 404) yields nothing');
Integrations::use(null, null, $bb);
ok(Achievement::syncFromLms($swe401) === 1, 'Blackboard results flow through the achievement pipeline');

section('5. SIS: REST API');
$sisUrl = serve("$mock/sis.php");
$rest = new RestSisSource("$sisUrl/api", 'sis-token');
$check = $rest->check();
ok($check['ok'] && str_contains($check['message'], '2 term(s)'), 'connection test: ' . $check['message']);
$a = $rest->assignments('2026-2');
ok($a[0]['course'] === 'SWE 401' && $a[0]['sections'] === 2 && $a[0]['enrolled'] === 41 && $a[1]['course'] === 'CIS 491', 'course codes normalised ("swe 401", "CIS491" → catalogue format)');
ok($a[1]['instructor'] === 'YU-F9100' && $a[1]['instructor_email'] === 'reem@yu.example', 'instructor identity carried for provisioning');
$bad = (new RestSisSource("$sisUrl/api", 'wrong'))->check();
ok(!$bad['ok'] && str_contains($bad['message'], '401'), 'a rejected token is reported: ' . $bad['message']);

section('6. Scheduler keeps going when one system is down');
Integrations::use(null, null, new MoodleLmsSource('http://127.0.0.1:' . free_port(), 'x'));
$s = Scheduler::tick(true);
ok($s['errors'] > 0 && empty($s['skipped']), "unreachable LMS recorded as {$s['errors']} failed step(s); the run completes");
ok((bool) Db::val('SELECT 1 FROM system_errors WHERE message LIKE "%Could not reach%"'), 'the failure is in the admin error log with the reason');

section('7. SIS export folder and the automatic semester cycle');
$sisDir = tempdir('saqf-sis');
write_csv("$sisDir/terms.csv", [
    ['code', 'name', 'academic_year', 'sequence', 'starts_on', 'ends_on', 'grades_due_on'],
    ['2026-1', 'Fall 2026', '2026-2027', '3', '2026-08-23', '2026-12-20', '2026-12-30'],
    ['2026-2', 'Spring 2027', '2026-2027', '4', '2027-01-10', '2027-05-20', '2027-05-30'],
    ['2026-3', 'Summer 2027', '2026-2027', '5', '2027-06-06', '2027-07-30', '2027-08-05'],
    ['2027-1', 'Fall 2027', '2027-2028', '6', '2027-08-22', '2027-12-19', '2027-12-29'],
]);
write_csv("$sisDir/assignments.csv", [
    ['term', 'course', 'instructor_id', 'instructor_name', 'instructor_email', 'department', 'sections', 'enrolled'],
    ['2026-2', 'SWE 401', 'YU-F1034', '', '', '', '2', '41'],
    ['2026-2', 'swe412', 'YU-F1034', '', '', '', '1', '22'],
    ['2026-2', 'CIS 491', 'YU-F9001', 'Dr. Hala Al-Mutairi', 'hala@yu.example', '', '1', '19'],
    ['2026-2', 'XYZ 999', 'YU-F1034', '', '', '', '1', '10'],
    ['2026-2', 'SWE 302', 'YU-F9002', '', '', '', '1', '30'],
]);
$fileSis = new FileSisSource($sisDir);
ok($fileSis->check()['ok'], 'connection test: ' . $fileSis->check()['message']);
Integrations::use(null, $fileSis, new NullLmsSource());
Clock::set('2026-11-01 10:00:00');
$s = Scheduler::tick(true);
ok($s['sis_terms'] === 4 && $s['term_activated'] === null && Db::val('SELECT status FROM terms WHERE code = "2026-1"') === 'active', 'mid-term: calendar synced (4 terms), nothing to roll over yet');
Clock::set('2027-01-12 08:00:00');
$s = Scheduler::tick(true);
ok($s['term_activated'] === 'Spring 2027', 'on Spring 2027\'s start date the scheduler activates it without anyone clicking');
ok(Db::val('SELECT status FROM terms WHERE code = "2026-1"') === 'closed', 'Fall 2026 is closed (reports frozen)');
$spring = (int) Db::val('SELECT id FROM terms WHERE code = "2026-2"');
ok((int) Db::val('SELECT COUNT(*) FROM course_offerings WHERE term_id = ?', [$spring]) === 4, 'four workspaces created from the SIS file (the unknown course XYZ 999 is skipped)');
$hala = Db::one('SELECT * FROM users WHERE external_id = "YU-F9001"');
ok($hala && $hala['role'] === 'faculty' && $hala['provisioned_by'] === 'sis' && $hala['email'] === 'hala@yu.example' && $hala['username'] === 'hala', 'new instructor Dr. Hala Al-Mutairi got a faculty account from the SIS feed');
ok((int) Db::val('SELECT instructor_id FROM course_offerings WHERE term_id = ? AND course_id = (SELECT id FROM courses WHERE code = "CIS 491")', [$spring]) === (int) ($hala['id'] ?? 0), '…and owns the CIS 491 workspace');
ok(Db::val('SELECT instructor_id FROM course_offerings WHERE term_id = ? AND course_id = (SELECT id FROM courses WHERE code = "SWE 302")', [$spring]) === null, 'an instructor with no name in the feed is not invented (workspace waits for assignment)');
$run = json_decode((string) Db::val('SELECT stats FROM sync_runs WHERE source = "sis.assignments" ORDER BY id DESC LIMIT 1'), true);
ok(($run['unknown_courses'] ?? 0) === 1 && ($run['unknown_instructors'] ?? 0) === 1, 'data problems are counted in the integration run log');
$s = Scheduler::tick(true);
ok($s['term_activated'] === null && (int) Db::val('SELECT COUNT(*) FROM course_offerings WHERE term_id = ?', [$spring]) === 4, 'running again changes nothing (idempotent)');
Clock::set('2027-09-01 08:00:00');
$s = Scheduler::tick(true);
ok($s['term_activated'] === 'Fall 2027' && Db::val('SELECT status FROM terms WHERE code = "2026-3"') === 'closed', 'after the summer the scheduler moves to Fall 2027; the unused Summer term is closed');
ok(str_contains($lastAudit('term.skipped'), 'Summer 2027'), 'the skipped term is explained in the audit log');

section('8. Accounts');
throws(static fn() => Users::save(['username' => 'Bad Name!', 'full_name' => 'X', 'role' => 'faculty']), 'invalid usernames are refused', InvalidArgumentException::class);
throws(static fn() => Users::save(['username' => 'hod.x', 'full_name' => 'X', 'role' => 'hod']), 'a Head of Department needs a department', InvalidArgumentException::class);
throws(static fn() => Users::save(['username' => 'f.x', 'full_name' => 'X', 'role' => 'faculty', 'department' => 'NOPE']), 'unknown department codes are refused', InvalidArgumentException::class);
$r = Users::save(['username' => 'n.test', 'full_name' => 'Dr. Noura Test', 'email' => 'n.test@yu.example', 'role' => 'faculty', 'department' => 'CED', 'external_id' => 'YU-T1']);
$u = Db::one('SELECT * FROM users WHERE id = ?', [$r['id']]);
ok($r['created'] && $r['temp_password'] && (int) $u['must_change_password'] === 1 && password_verify($r['temp_password'], $u['password_hash']), 'account created with a one-time password that must be changed');
$r = Users::save(['username' => 'n.test', 'full_name' => 'Dr. Noura Test', 'email' => 'n.test@yu.example', 'role' => 'faculty', 'department' => 'CED', 'external_id' => 'YU-T1', 'title' => 'Professor']);
ok(!$r['created'] && $r['changed'] && str_contains($lastAudit('user.updated'), 'title'), 'saving again updates only what changed (audited)');
throws(static fn() => Users::save(['username' => 'other.x', 'full_name' => 'X', 'role' => 'faculty', 'external_id' => 'YU-T1']), 'one SIS/HR identifier cannot belong to two accounts', InvalidArgumentException::class);
$csv = tempnam(sys_get_temp_dir(), 'users');
write_csv($csv, [Users::CSV_COLUMNS,
    ['imp.one', 'Imp One', 'imp1@yu.example', 'faculty', 'CED', '', 'YU-T2', 'Lecturer'],
    ['imp.two', 'Imp Two', '', 'qa', '', '', '', ''],
    ['n.test', 'Dr. Noura Test', 'n.test@yu.example', 'faculty', 'CED', '', 'YU-T1', 'Associate Professor'],
    ['imp.bad', 'Bad Role', '', 'wizard', '', '', '', ''],
    ['imp.hod', 'HoD Without Dept', '', 'hod', '', '', '', ''],
]);
$imp = Users::importCsv($csv, 'HR import test');
ok($imp['created'] === 2 && $imp['updated'] === 1 && count($imp['errors']) === 2 && count($imp['credentials']) === 2, "CSV import: {$imp['created']} created, {$imp['updated']} updated, " . count($imp['errors']) . ' rejected with line numbers');
Users::save(['username' => 'link.me', 'full_name' => 'Link Me', 'email' => 'link.me@yu.example', 'role' => 'faculty']);
$linked = Users::provisionInstructor('YU-T9', 'Link Me', 'LINK.ME@yu.example', null);
ok($linked === (int) Db::val('SELECT id FROM users WHERE username = "link.me"') && Db::val('SELECT external_id FROM users WHERE username = "link.me"') === 'YU-T9', 'a SIS instructor matching an existing e-mail is linked, not duplicated');
Policy::set('integration.provision_instructors', '0', 'test');
ok(Users::provisionInstructor('YU-T10', 'Nobody', 'nobody@yu.example', null) === null, 'automatic instructor accounts can be switched off by policy');
Policy::set('integration.provision_instructors', '1', 'test');

section('9. E-mail (SMTP)');
$capture = tempnam(sys_get_temp_dir(), 'smtp');
$smtpPort = free_port();
spawn([PHP_BINARY, "$mock/smtp.php", (string) $smtpPort, $capture]);
wait_port($smtpPort);
foreach (['SAQF_MAIL_HOST' => '127.0.0.1', 'SAQF_MAIL_PORT' => (string) $smtpPort, 'SAQF_MAIL_ENCRYPTION' => 'none', 'SAQF_MAIL_USERNAME' => 'saqf',
    'SAQF_MAIL_PASSWORD' => 'secret-pass', 'SAQF_MAIL_FROM' => 'saqf@yu.example', 'SAQF_BASE_URL' => 'https://saqf.yu.example'] as $k => $v) {
    putenv("$k=$v");
}
$mails = static function () use ($capture): array {
    $out = [];
    foreach (file($capture, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $m = json_decode($line, true);
        [$head, $body] = array_pad(explode("\r\n\r\n", $m['data'], 2), 2, '');
        $m['headers'] = $head;
        $m['text'] = (string) base64_decode(str_replace("\r\n", '', $body));
        $out[] = $m;
    }
    return $out;
};
$lastMail = static function () use ($mails): array {
    $all = $mails();
    return $all ? $all[count($all) - 1] : [];
};
ok(Mailer::enabled() && Mailer::transport() === 'smtp', 'SMTP is enabled by configuration');
Mailer::queue('test@yu.example', 'Test Person', 'تقرير الجودة — Quality report', "السلام عليكم\nLine two\n.\nAfter a lone dot", 'test');
ok(Mailer::flush() === 1, 'queued message delivered');
$m = $mails()[0] ?? [];
ok(($m['auth'] ?? '') === "\0saqf\0secret-pass", 'authenticated with AUTH PLAIN');
ok(str_contains($m['headers'] ?? '', 'Subject: =?UTF-8?B?' . base64_encode('تقرير الجودة — Quality report') . '?='), 'Arabic subject encoded (RFC 2047)');
ok(($m['text'] ?? '') === "السلام عليكم\r\nLine two\r\n.\r\nAfter a lone dot", 'UTF-8 body intact, including a line with a single dot');
Mailer::queue('test@yu.example', null, "Hello\r\nBcc: evil@example.com", 'x', 'test');
Mailer::flush();
ok(!preg_match('/^Bcc:/mi', (string) ($lastMail()['headers'] ?? '')), 'header injection through the subject is impossible');
$omar = (int) Db::val('SELECT id FROM users WHERE username = "f.omar"');
$sara = (int) Db::val('SELECT id FROM users WHERE username = "f.sara"');
Notify::user($omar, 'action', 'Digest test one', 'First thing to do', 'workspace.php?id=' . $swe401, 'digest-test-1');
Notify::user($omar, 'action', 'Digest test two', null, null, 'digest-test-2');
Db::update('users', ['notify_email' => 0], 'id = ?', [$sara]);
Notify::user($sara, 'action', 'Digest test three', null, null, 'digest-test-3');
ok(Mailer::queueNotificationDigests() >= 1, 'notification digests queued by the scheduler step');
Mailer::flush();
$toOmar = array_values(array_filter($mails(), static fn($m) => str_contains(implode(' ', $m['to']), 'f.omar@')));
ok(count($toOmar) === 1 && str_contains($toOmar[0]['text'], 'Digest test one') && str_contains($toOmar[0]['text'], 'Digest test two'), 'one digest per person, listing every open item');
ok(str_contains($toOmar[0]['text'] ?? '', 'https://saqf.yu.example/workspace.php?id=' . $swe401), 'digest links use SAQF_BASE_URL');
ok(!array_filter($mails(), static fn($m) => str_contains($m['text'], 'Digest test three')), 'people who turned e-mail off are not e-mailed');
ok(Mailer::queueNotificationDigests() === 0, 'items are e-mailed once');
putenv('SAQF_MAIL_PORT=' . free_port());
$id = Mailer::queue('test@yu.example', null, 'Will fail', 'x', 'test');
ok(Mailer::flush() === 0, 'mail server down: nothing sent');
$row = Db::one('SELECT * FROM mail_outbox WHERE id = ?', [$id]);
ok((int) $row['attempts'] === 1 && str_contains((string) $row['last_error'], 'Cannot connect') && $row['next_attempt_at'] > Clock::stamp(), 'failure kept in the queue with the reason and a retry time');
putenv("SAQF_MAIL_PORT=$smtpPort");

section('10. Password reset and invitations');
ok(PasswordReset::available(), 'self-service reset is available (mail + SAQF_BASE_URL configured)');
$before = count($mails());
PasswordReset::request('f.omar');
$reset = $mails()[$before] ?? [];
preg_match('#https://saqf\.yu\.example/reset\.php\?token=([A-Za-z0-9_\-]+)#', $reset['text'] ?? '', $mm);
$token = $mm[1] ?? '';
ok($token !== '' && str_contains(implode(' ', $reset['to'] ?? []), 'f.omar@'), 'reset link e-mailed immediately to the account\'s address');
ok((PasswordReset::find($token)['username'] ?? '') === 'f.omar', 'the link identifies the account');
ok(is_string(PasswordReset::complete($token, 'short', 'short')), 'weak passwords are still refused');
ok(PasswordReset::complete($token, 'Better-Pass-2027', 'Better-Pass-2027') === null, 'new password set through the link');
ok(Auth::attempt('f.omar', 'Better-Pass-2027')['ok'], 'the new password signs in');
ok(PasswordReset::find($token) === null, 'the link works only once');
$before = count($mails());
PasswordReset::request('nobody-by-this-name');
ok(count($mails()) === $before, 'unknown accounts get no e-mail (and the page answers the same)');
for ($i = 0; $i < 6; $i++) {
    PasswordReset::request('f.sara');
}
ok(count($mails()) - $before === 3, 'requests are throttled (3 per account per hour)');
$r = Users::save(['username' => 'inv.user', 'full_name' => 'Invited User', 'email' => 'inv.user@yu.example', 'role' => 'faculty', 'department' => 'CED']);
Mailer::flush();
$inv = $lastMail();
ok($r['invited'] && $r['temp_password'] === null && str_contains($inv['text'] ?? '', 'reset.php?token=') && str_contains($inv['headers'] ?? '', 'Your SAQF account'), 'new accounts get an e-mail invitation instead of a shared password');

section('11. SSO tokens (OpenID Connect)');
$b64u = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$sign = static function (array $header, array $claims, $key, int $algo) use ($b64u): string {
    $input = $b64u(json_encode($header)) . '.' . $b64u(json_encode($claims));
    openssl_sign($input, $sig, $key, $algo);
    return $input . '.' . $b64u($sig);
};
$rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
$rd = openssl_pkey_get_details($rsa);
$jwks = ['keys' => [['kty' => 'RSA', 'use' => 'sig', 'kid' => 'r1', 'n' => $b64u($rd['rsa']['n']), 'e' => $b64u($rd['rsa']['e'])]]];
$claims = ['iss' => 'https://idp.example', 'aud' => 'saqf-test', 'sub' => 'u1', 'exp' => time() + 300, 'iat' => time(), 'nonce' => 'n1'];
$token = $sign(['alg' => 'RS256', 'kid' => 'r1'], $claims, $rsa, OPENSSL_ALGO_SHA256);
ok(Jwt::verify($token, $jwks)['sub'] === 'u1', 'RS256 ID token verified against the published key set');
ok(openssl_pkey_get_details(openssl_pkey_get_public(Jwt::pem($jwks['keys'][0])))['rsa']['n'] === $rd['rsa']['n'], 'JWK → PEM conversion reproduces the key');
[$h, , $sg] = explode('.', $token);
throws(static fn() => Jwt::verify($h . '.' . $b64u(json_encode(['sub' => 'admin'] + $claims)) . '.' . $sg, $jwks), 'a modified token is rejected', SsoException::class);
throws(static fn() => Jwt::verify($b64u(json_encode(['alg' => 'none'])) . '.' . $b64u(json_encode($claims)) . '.', $jwks), 'alg "none" is rejected', SsoException::class);
throws(static fn() => Jwt::verify($b64u(json_encode(['alg' => 'HS256', 'kid' => 'r1'])) . '.' . $b64u(json_encode($claims)) . '.' . $b64u(hash_hmac('sha256', 'x', 'k', true)), $jwks), 'shared-secret HS256 is rejected (algorithm confusion)', SsoException::class);
throws(static fn() => Jwt::verify($sign(['alg' => 'RS256', 'kid' => 'other'], $claims, $rsa, OPENSSL_ALGO_SHA256), $jwks), 'unknown key id → key set refreshed by the caller', JwtKeyNotFound::class);
$ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$ed = openssl_pkey_get_details($ec);
$ecJwks = ['keys' => [['kty' => 'EC', 'crv' => 'P-256', 'kid' => 'e1', 'x' => $b64u(str_pad($ed['ec']['x'], 32, "\0", STR_PAD_LEFT)), 'y' => $b64u(str_pad($ed['ec']['y'], 32, "\0", STR_PAD_LEFT))]]];
$input = $b64u(json_encode(['alg' => 'ES256', 'kid' => 'e1'])) . '.' . $b64u(json_encode($claims));
openssl_sign($input, $der, $ec, OPENSSL_ALGO_SHA256);
$off = 3 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7f : 0);
$rl = ord($der[$off]);
$rr = substr($der, $off + 1, $rl);
$sl = ord($der[$off + 2 + $rl]);
$ss = substr($der, $off + 3 + $rl, $sl);
$raw = str_pad(ltrim($rr, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($ss, "\0"), 32, "\0", STR_PAD_LEFT);
ok(Jwt::verify($input . '.' . $b64u($raw), $ecJwks)['sub'] === 'u1', 'ES256 (elliptic curve) ID token verified');
putenv('SAQF_OIDC_CLIENT_ID=saqf-test');
Oidc::validate($claims, 'https://idp.example', 'n1');
ok(true, 'valid issuer, audience, expiry and nonce accepted');
throws(static fn() => Oidc::validate(['iss' => 'https://evil.example'] + $claims, 'https://idp.example', 'n1'), 'wrong issuer rejected', SsoException::class);
throws(static fn() => Oidc::validate(['aud' => 'another-app'] + $claims, 'https://idp.example', 'n1'), 'token for another application rejected', SsoException::class);
throws(static fn() => Oidc::validate(['exp' => time() - 600] + $claims, 'https://idp.example', 'n1'), 'expired token rejected', SsoException::class);
throws(static fn() => Oidc::validate($claims, 'https://idp.example', 'other-nonce'), 'replayed token (nonce mismatch) rejected', SsoException::class);
throws(static fn() => Oidc::validate(['aud' => ['saqf-test', 'x']] + $claims, 'https://idp.example', 'n1'), 'multi-audience token without azp rejected', SsoException::class);
putenv('SAQF_OIDC_ROLE_CLAIM=roles');
putenv('SAQF_OIDC_ROLE_MAP=SAQF.Faculty=faculty;SAQF.QA=qa;SAQF.Admin=admin');
ok(Oidc::roleFromClaims(['roles' => ['SAQF.Faculty', 'SAQF.QA']]) === 'qa' && Oidc::roleFromClaims(['roles' => ['Other']]) === null, 'role mapping picks the most senior mapped role');

section('12. SSO accounts');
putenv('SAQF_OIDC_ROLE_CLAIM=');
$u = Auth::ssoUser(['iss' => 'https://idp.example', 'sub' => 'sara-sub', 'preferred_username' => 'F.Sara@yu.edu.sa', 'email' => 'sara@elsewhere.example']);
ok($u['username'] === 'f.sara' && Db::val('SELECT auth_source FROM users WHERE id = ?', [$u['id']]) === 'sso', 'first university sign-in links the existing account by username');
ok((int) Auth::ssoUser(['iss' => 'https://idp.example', 'sub' => 'sara-sub'])['id'] === (int) $u['id'], 'later sign-ins match the linked identity');
throws(static fn() => Auth::ssoUser(['iss' => 'https://idp.example', 'sub' => 'ghost', 'preferred_username' => 'ghost@yu.edu.sa']), 'people without a SAQF account are refused with guidance', SsoException::class);
putenv('SAQF_OIDC_AUTO_PROVISION=1');
putenv('SAQF_OIDC_DEFAULT_ROLE=faculty');
$g = Auth::ssoUser(['iss' => 'https://idp.example', 'sub' => 'ghost', 'preferred_username' => 'ghost@yu.edu.sa', 'name' => 'Dr. Ghost', 'email' => 'ghost@yu.edu.sa']);
ok($g['username'] === 'ghost' && $g['role'] === 'faculty' && Db::val('SELECT provisioned_by FROM users WHERE id = ?', [$g['id']]) === 'sso', 'with auto-provisioning on, the account is created on first sign-in');
putenv('SAQF_OIDC_ROLE_CLAIM=roles');
$g = Auth::ssoUser(['iss' => 'https://idp.example', 'sub' => 'ghost', 'roles' => ['SAQF.QA']]);
ok($g['role'] === 'qa' && str_contains($lastAudit('user.role_synced'), 'identity provider'), 'roles follow the identity provider (audited)');
Db::update('users', ['status' => 'disabled'], 'id = ?', [$g['id']]);
throws(static fn() => Auth::ssoUser(['iss' => 'https://idp.example', 'sub' => 'ghost']), 'disabled accounts cannot sign in through SSO', SsoException::class);

finish();
