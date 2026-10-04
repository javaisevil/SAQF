<?php
declare(strict_types=1);

/**
 * Tests for the deployment features: multi-section courses, specification import, course-file
 * evidence (with virus scanning), NCAAA Word exports, two-step verification and the other security
 * controls, IT alerts and backup monitoring, the Arabic interface and the demo shortcuts.
 *   php bin/install.php --demo --fresh && php tests/features_test.php
 * Starts its own SAQF web servers (demo mode and production mode), a stand-in virus scanner and a
 * stand-in Teams/Slack webhook on free ports.
 */

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/support.php';

use Saqf\Core\Alerts;
use Saqf\Core\Audit;
use Saqf\Core\Db;
use Saqf\Core\Policy;
use Saqf\Core\Request;
use Saqf\Core\Secrets;
use Saqf\Core\Throttle;
use Saqf\Demo\SamplePdf;
use Saqf\Demo\Story;
use Saqf\Integration\Integrations;
use Saqf\Quality\Engine;
use Saqf\Quality\Evidence;
use Saqf\Quality\Findings;
use Saqf\Quality\NcaaaExport;
use Saqf\Quality\Scheduler;
use Saqf\Quality\Sections;
use Saqf\Quality\SpecImport;
use Saqf\Quality\Specs;
use Saqf\Security\Auth;
use Saqf\Security\Authz;
use Saqf\Security\Mfa;
use Saqf\Security\PasswordPolicy;
use Saqf\Security\SecurityCenter;
use Saqf\Security\Totp;
use Saqf\Web\I18n;
use Saqf\Web\Qr;
use Saqf\Web\View;
use Saqf\Web\Zip;

// Evidence goes to a scratch store shared by this script and its web server.
$store = tempdir('saqf-evidence');
putenv("SAQF_STORAGE_DIR=$store");
$app = serve(dirname(__DIR__) . '/public', ['SAQF_STORAGE_DIR' => $store]);

function http(string $jar, string $url, ?array $post = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'saqf-features-test', CURLOPT_HEADER => true]);
    if ($post !== null) {
        $multipart = (bool) array_filter($post, static fn($v) => $v instanceof CURLFile);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $post : http_build_query($post));
    }
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $loc = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return [$code, substr($raw, $size), $loc, substr($raw, 0, $size)];
}

function jar(): string
{
    return (string) tempnam(sys_get_temp_dir(), 'jar');
}

function csrf_of(string $html): string
{
    return preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

/** One-click demo sign-in; returns the cookie jar. */
function as_user(string $base, string $username): string
{
    $j = jar();
    [, $html] = http($j, "$base/login.php");
    http($j, "$base/demo.php", ['_csrf' => csrf_of($html), 'as' => $username, 'next' => 'index.php']);
    return $j;
}

function person(string $username): array
{
    $u = Db::one('SELECT u.*, d.college_id AS dc FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE username = ?', [$username]);
    $u['id'] = (int) $u['id'];
    $u['department_id'] = $u['department_id'] === null ? null : (int) $u['department_id'];
    $u['scope_college_id'] = (int) ($u['college_id'] ?? $u['dc']);
    Audit::actAs('user', $u['id'], $u['full_name'], $u['role']);
    $_SESSION['uid'] = $u['id'];
    return $u;
}

function offering_id(string $code, string $term = '2026-1'): int
{
    return (int) Db::val('SELECT o.id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id WHERE c.code = ? AND t.code = ?', [$code, $term]);
}

function open_rules(int $offeringId): array
{
    return array_column(array_filter(Findings::forOffering($offeringId), static fn($f) => $f['status'] === 'open'), 'rule_code');
}

/** Reads one file from a ZIP produced by Saqf\Web\Zip (no zip extension needed). */
function unzip_entry(string $zip, string $name): ?string
{
    $pos = 0;
    while (substr($zip, $pos, 4) === "PK\x03\x04") {
        $h = unpack('vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/vxlen', substr($zip, $pos + 4, 26));
        $entry = substr($zip, $pos + 30, $h['nlen']);
        $data = substr($zip, $pos + 30 + $h['nlen'] + $h['xlen'], $h['csize']);
        if ($entry === $name) {
            return $h['method'] === 8 ? (string) gzinflate($data) : $data;
        }
        $pos += 30 + $h['nlen'] + $h['xlen'] + $h['csize'];
    }
    return null;
}

function upload_file(string $name, string $bytes): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'up');
    file_put_contents($tmp, $bytes);
    return ['tmp_name' => $tmp, 'name' => $name, 'size' => strlen($bytes), 'error' => UPLOAD_ERR_OK];
}

$o401 = offering_id('SWE 401');
$omar = person('f.omar');
$sara = person('f.sara');
$noura = person('f.noura');

section('1. Courses with several sections (coordinator + section instructors)');
$secs = Sections::forOffering($o401);
ok(count($secs) === 2 && $secs[0]['section_code'] === '01' && $secs[0]['instructor_id'] === $omar['id'] && $secs[1]['section_code'] === '02' && $secs[1]['instructor_id'] === $sara['id'],
    'SWE 401 has section 01 (Dr. Omar, coordinator) and section 02 (Dr. Sara), straight from the SIS');
$view = Authz::offering($sara, $o401);
ok(Authz::teaches($sara, $view) && !Authz::canEditOffering($sara, $view) && Authz::canEditOffering($omar, Authz::offering($omar, $o401)),
    'the section instructor works in the course, but only the coordinator changes the shared specification');
throws(static fn() => Authz::offering($noura, $o401), 'an instructor who teaches no section of the course cannot open it');
$bySection = Db::all('SELECT section_code, COUNT(DISTINCT student_ref) n FROM assessment_results WHERE offering_id = ? GROUP BY section_code ORDER BY section_code', [$o401]);
ok(count($bySection) === 2 && (int) $bySection[0]['n'] === 15 && (int) $bySection[1]['n'] === 14, 'results arrive tagged with each student\'s section (15 + 14 students)');
$gaps = Sections::gaps($o401, (float) Policy::get('section.gap_points'), (int) Policy::get('results.min_students'));
ok(count($gaps) >= 1 && $gaps[0]['low'] === '02' && abs($gaps[0]['gap'] - 15.7) < 0.05, sprintf('achievement is compared per section: %s section 02 trails section 01 by %.1f points', $gaps[0]['code'] ?? '?', $gaps[0]['gap'] ?? 0));
ok(in_array('SECTION_GAP', open_rules($o401), true), 'the gap is raised automatically for the coordinator (SECTION_GAP)');
$r = Sections::sync($o401, [['section' => '01', 'instructor_id' => $omar['id'], 'enrolled' => 15], ['section' => '02', 'instructor_id' => $sara['id'], 'enrolled' => 14], ['section' => '03', 'instructor_id' => $noura['id'], 'enrolled' => 12]]);
ok($r['added'] === 1 && Authz::teaches($noura, Db::one('SELECT o.*, t.status AS term_status FROM course_offerings o JOIN terms t ON t.id = o.term_id WHERE o.id = ?', [$o401])), 'a new section in the SIS feed gives its instructor access to the course');
ok((bool) Db::val('SELECT 1 FROM notifications WHERE user_id = ? AND link LIKE ?', [$noura['id'], "%workspace.php?id=$o401%"]), '…and the new section instructor is told about it');
Sections::sync($o401, [['section' => '01', 'instructor_id' => $omar['id'], 'enrolled' => 15], ['section' => '02', 'instructor_id' => $sara['id'], 'enrolled' => 14]]);
putenv('SAQF_LMS_COURSE_KEY={term}-{code_nospace}-{section}');
ok(Integrations::lmsKeyHasSection() && Integrations::lmsCourseKey('2026-1', 'SWE 401', '02') === '2026-1-SWE401-02', 'one LMS course per section is supported ({section} in SAQF_LMS_COURSE_KEY)');
putenv('SAQF_LMS_COURSE_KEY');

section('2. Bulk import of existing course specifications');
$o411 = offering_id('SWE 411');
ok(in_array('OFFERING_NO_SPEC', open_rules($o411), true), 'SWE 411 starts the term with no specification in SAQF');
$csv = tempnam(sys_get_temp_dir(), 'spec');
$fh = fopen($csv, 'w');
foreach ([SpecImport::COLUMNS,
    ['SWE 411', 'objectives', '', 'Plan and carry out verification and validation of software systems.', '', '', '', '', '', ''],
    ['SWE 411', 'clo', 'CLO1', 'Explain verification and validation concepts and their place in the life cycle.', 'Knowledge and Understanding', '', '', '', '', 'SO1'],
    ['SWE 411', 'clo', 'CLO2', 'Design test cases using black-box and white-box techniques.', 'Skills', '', '', '', '', 'SO2'],
    ['SWE 411', 'assessment', '', 'Midterm exam', 'midterm', '40', '8', '', '', 'CLO1'],
    ['SWE 411', 'assessment', '', 'Testing project', 'project', '60', '14', '', '', 'CLO1;CLO2'],
    ['SWE 411', 'topic', '', 'Test design techniques', '', '', '', '9', '', ''],
    ['SWE 413', 'clo', 'CLO1', 'Explain secure coding.', 'Knowledge and Understanding', '', '', '', '', 'ACC:SO1'],
    ['SWE 413', 'assessment', '', 'Final exam', 'final', '100', '16', '', '', 'CLO1'],
] as $row) {
    fputcsv($fh, $row);
}
fclose($fh);
person('qa.director');
$res = SpecImport::run($csv, 'baseline', 'Specifications approved by the department council (test)');
ok($res['imported'] === ['SWE 411'], 'a valid specification is imported as the approved baseline (' . implode(', ', $res['imported']) . ')');
ok($res['skipped'] === 1 && str_contains(implode(' ', $res['errors']), 'SWE 413') && !Db::val('SELECT 1 FROM spec_versions WHERE course_id = (SELECT id FROM courses WHERE code = "SWE 413")'),
    'a course with a mistake (PLO of another program) is reported and nothing of it is written');
ok(!in_array('OFFERING_NO_SPEC', open_rules($o411), true) && (int) Db::val('SELECT spec_version_id FROM course_offerings WHERE id = ?', [$o411]) > 0, 'the running SWE 411 offering picks the imported specification up at once');
ok(str_starts_with(SpecImport::template(), "\xEF\xBB\xBFcourse,type,code,text"), 'the CSV template opens correctly in Excel (UTF-8 with BOM, Arabic safe)');

section('3. Course-file evidence: stored safely, checked, requested automatically');
$off = Authz::offering($omar, $o401);
$midterm = (int) Db::val('SELECT id FROM assessments WHERE spec_version_id = ? AND name = "Midterm exam"', [$off['spec_version_id']]);
ok(in_array('EVIDENCE_REQUESTED', open_rules($o401), true), 'results for the midterm arrived, so SAQF asks for the midterm paper (EVIDENCE_REQUESTED)');
$pdf = SamplePdf::make('SWE 401 Midterm exam', ['Question 1. Explain ISO/IEC 25010 product quality characteristics.']);
person('f.omar');
$evId = Evidence::store($off, upload_file('midterm.pdf', $pdf), 'assessment', 'Midterm exam paper', $midterm, null, $omar, false);
$ev = Db::one('SELECT * FROM evidence_files WHERE id = ?', [$evId]);
$path = Evidence::dir() . '/' . substr($ev['stored_name'], 0, 2) . '/' . $ev['stored_name'];
ok(is_file($path) && preg_match('/^[0-9a-f]{40}$/', $ev['stored_name']) && !str_starts_with(realpath($path), realpath(dirname(__DIR__) . '/public')), 'the file is stored outside the web root under a random name');
ok($ev['sha256'] === hash('sha256', $pdf), 'its SHA-256 fingerprint is recorded for integrity');
ok(!in_array('EVIDENCE_REQUESTED', open_rules($o401), true), 'uploading the midterm paper clears the request by itself');
throws(static fn() => Evidence::store($off, upload_file('fake.pdf', "This is not really a PDF\n"), 'assessment', '', null, null, $omar, false), 'a file whose content does not match its type is refused', InvalidArgumentException::class);
$macro = new Zip();
$macro->add('[Content_Types].xml', '<Types/>');
$macro->add('word/document.xml', '<w:document/>');
$macro->add('word/vbaProject.bin', 'macro');
throws(static fn() => Evidence::store($off, upload_file('rubric.docx', $macro->bytes()), 'rubric', '', null, null, $omar, false), 'Word files carrying macros are refused', InvalidArgumentException::class);
throws(static fn() => Evidence::store($off, upload_file('tool.exe', 'MZ'), 'other', '', null, null, $omar, false), 'program files are refused', InvalidArgumentException::class);

$clamPort = free_port();
spawn([PHP_BINARY, __DIR__ . '/mock/clamd.php', (string) $clamPort]);
wait_port($clamPort);
putenv("SAQF_CLAMAV_HOST=127.0.0.1:$clamPort");
$clean = Evidence::store($off, upload_file('rubric.pdf', SamplePdf::make('Rubric', ['Criteria'])), 'rubric', 'Rubric', null, null, $omar, false);
ok(Db::val('SELECT scan_status FROM evidence_files WHERE id = ?', [$clean]) === 'clean', 'with ClamAV configured, a clean file is scanned and accepted');
$eicar = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
throws(static fn() => Evidence::store($off, upload_file('notes.txt', $eicar), 'other', '', null, null, $omar, false), 'the EICAR test virus is refused by the scanner', InvalidArgumentException::class);
ok((bool) Db::val('SELECT 1 FROM alerts WHERE kind = "security.malware" AND resolved_at IS NULL'), '…and IT gets a critical "malware blocked" alert');
putenv('SAQF_CLAMAV_HOST=127.0.0.1:' . free_port());
throws(static fn() => Evidence::store($off, upload_file('late.pdf', $pdf), 'other', '', null, null, $omar, false), 'if the scanner is down, uploads pause instead of skipping the scan (fail closed)', RuntimeException::class);
ok((bool) Db::val('SELECT 1 FROM alerts WHERE kind = "scanner.unreachable" AND resolved_at IS NULL'), '…and IT is alerted that the scanner is unreachable');
putenv('SAQF_CLAMAV_HOST');

[$code, $body, , $headers] = http(as_user($app, 'f.sara'), "$app/evidence.php?id=$evId");
ok($code === 200 && $body === $pdf && stripos($headers, 'attachment') !== false, 'the section 02 instructor can download it (sent as an attachment)');
[$code] = http(as_user($app, 'f.noura'), "$app/evidence.php?id=$evId");
ok(in_array($code, [403, 404], true), "an instructor of another department cannot ($code)");
ok((bool) Db::val('SELECT 1 FROM audit_log WHERE action = "evidence.downloaded" AND object_id = ?', [(string) $o401]), 'every download is in the audit log');
[$code, $body] = http(as_user($app, 'f.omar'), "$app/workspace.php?id=$o401&tab=evidence");
ok($code === 200 && str_contains($body, 'Midterm exam paper') && str_contains($body, 'Add evidence'), 'the Evidence tab lists the course file and offers the upload form');

section('4. Word documents in the NCAAA layout (English and Arabic)');
$approved = (int) Db::val('SELECT spec_version_id FROM course_offerings WHERE id = ?', [$o401]);
$docx = NcaaaExport::specification($approved)->bytes();
$xml = (string) unzip_entry($docx, 'word/document.xml');
ok(str_starts_with($docx, "PK\x03\x04") && str_contains($xml, 'SWE 401') && str_contains($xml, 'Course learning outcomes') !== false, 'course specification exports as a Word document with the NCAAA sections');
ok(unzip_entry($docx, '[Content_Types].xml') !== null && unzip_entry($docx, 'word/styles.xml') !== null, 'the package has the parts Word needs (content types, styles)');
$ar = (string) unzip_entry(NcaaaExport::specification($approved, 'ar')->bytes(), 'word/document.xml');
ok(preg_match('/\p{Arabic}/u', $ar) === 1 && str_contains($ar, '<w:bidi'), 'the Arabic version has Arabic headings and right-to-left paragraphs');
$report = (string) unzip_entry(NcaaaExport::courseReport($o401)->bytes(), 'word/document.xml');
ok(str_contains($report, 'Section') && str_contains($report, '02') && str_contains($report, 'Midterm exam paper'), 'the course report includes results by section and the evidence list');
[$code, $body, , $headers] = http(as_user($app, 'f.omar'), "$app/export.php?doc=report&id=$o401");
ok($code === 200 && str_starts_with($body, "PK\x03\x04") && stripos($headers, 'wordprocessingml') !== false, 'the Word report downloads from the Report tab');
[$code] = http(as_user($app, 'f.noura'), "$app/export.php?doc=report&id=$o401");
ok(in_array($code, [403, 404], true), "people outside the course cannot export it ($code)");

section('5. Sign-in security');
$rfc = Totp::base32('12345678901234567890');
ok(Totp::code($rfc, intdiv(59, 30)) === '287082' && Totp::code($rfc, intdiv(1111111109, 30)) === '081804' && Totp::code($rfc, intdiv(2000000000, 30)) === '279037', 'authenticator codes match the RFC 6238 test vectors');
$m = Qr::matrix('otpauth://totp/SAQF:f.omar?secret=' . $rfc . '&issuer=SAQF');
ok(count($m) >= 21 && count($m) === count($m[0]) && str_contains(Qr::svg('test'), '<svg'), 'the set-up QR code is generated on the server (' . count($m) . '×' . count($m) . ' modules, no external service)');
$sec = Totp::secret();
$enc = (string) Db::val('SELECT mfa_secret FROM users WHERE username = "it.admin"');
ok(!str_contains($enc, Story::ADMIN_TOTP_SECRET) && Secrets::decrypt($enc) === Story::ADMIN_TOTP_SECRET, 'authenticator secrets are stored encrypted (AES-256-GCM)');
person('f.noura');
$_SESSION['mfa_enrol'] = ['uid' => $noura['id'], 'secret' => $sec, 'at' => time()];
$codes = Mfa::confirm($noura, Totp::code($sec));
$nouraRow = Db::one('SELECT * FROM users WHERE id = ?', [$noura['id']]);
ok(count($codes) === Mfa::RECOVERY_CODES && Mfa::enabled($nouraRow), 'a faculty member turns on two-step verification and receives 10 recovery codes');
ok(Mfa::verify($nouraRow, Totp::code($sec)) === null, 'a code that was already used cannot be replayed');
ok(Mfa::verify($nouraRow, $codes[0]) === 0 && Mfa::verify(Db::one('SELECT * FROM users WHERE id = ?', [$noura['id']]), $codes[0]) === null, 'a recovery code works exactly once');
Mfa::disable($noura['id'], 'self');
ok(Mfa::required(['role' => 'admin']) && Mfa::required(['role' => 'faculty']) && !Mfa::emailAllowed(['role' => 'admin', 'email' => 'it@yu.edu.sa']), 'policy: two-step verification is required for everyone signing in with a password; administrators must use an authenticator app');
$j = jar();
[, $html] = http($j, "$app/login.php");
[$code, , $loc] = http($j, "$app/login.php", ['_csrf' => csrf_of($html), 'username' => 'it.admin', 'password' => Story::PASSWORD] + bot_fields($html));
ok($code === 302 && str_ends_with($loc, 'mfa.php'), 'the administrator\'s correct password alone does not sign in — a code is asked for');
[$code] = http($j, "$app/admin.php");
ok($code === 302, 'nothing is reachable between the password and the code');
[, $html] = http($j, "$app/mfa.php");
[$code, , $loc] = http($j, "$app/mfa.php", ['_csrf' => csrf_of($html), 'code' => '000000']);
ok($code === 200, 'a wrong code is refused');
[, $html] = http($j, "$app/mfa.php");
[$code, , $loc] = http($j, "$app/mfa.php", ['_csrf' => csrf_of($html), 'code' => Totp::code(Story::ADMIN_TOTP_SECRET)]);
ok($code === 302 && str_ends_with($loc, 'index.php'), 'the right code completes the sign-in');
Db::exec('UPDATE users SET mfa_last_step = NULL WHERE username = "it.admin"');

ok(str_starts_with(Auth::hash('Correct horse 7 battery'), '$argon2id$'), 'passwords are hashed with Argon2id');
$person = ['full_name' => 'Dr. Omar Al-Harbi', 'username' => 'f.omar', 'email' => 'f.omar@yu.edu.sa'];
foreach (['Password2026' => 'a common word', 'qwerty123456' => 'a keyboard run', 'OmarHarbi2026' => 'the person\'s name', 'Yamamah20262' => 'the university\'s name', 'short1' => 'too short'] as $bad => $why) {
    ok(PasswordPolicy::problem($bad, $person) !== null, "weak password refused ($why): \"$bad\"");
}
ok(PasswordPolicy::problem('Lantern orbit 47 cedar', $person) === null, 'a passphrase of unrelated words is accepted');

$a = as_user($app, 'f.omar');
$b = as_user($app, 'f.omar');
[, $html] = http($a, "$app/account.php");
http($a, "$app/account.php", ['_csrf' => csrf_of($html), 'op' => 'end_others']);
[$code] = http($b, "$app/faculty.php");
[$codeA] = http($a, "$app/faculty.php");
ok($code === 302 && $codeA === 200, '"Sign out all other sessions" ends the other browser and keeps this one');

$_SESSION['login_at'] = time() - 3600;
$_SESSION['reauth_at'] = 0;
ok(!Auth::recentlyVerified(), 'an administrator session older than ' . Policy::get('security.reauth_minutes') . ' minutes must re-confirm identity before account changes');
$admin = person('it.admin');
ok(Auth::reauthenticate($admin, 'wrong-password', '') !== null && Auth::reauthenticate($admin, Story::PASSWORD, '123') !== null, '…a wrong password or a missing authenticator code is refused');
ok(Auth::reauthenticate($admin, Story::PASSWORD, Totp::code(Story::ADMIN_TOTP_SECRET)) === null && Auth::recentlyVerified(), '…password plus code confirms it');
Db::exec('UPDATE users SET mfa_last_step = NULL WHERE username = "it.admin"');

ok(Request::inRanges('10.20.30.40', '10.0.0.0/8, 192.168.1.0/24') && !Request::inRanges('11.0.0.1', '10.0.0.0/8') && Request::inRanges('2001:db8::5', '2001:db8::/32') && !Request::inRanges('2001:db9::1', '2001:db8::/32'),
    'network ranges (IPv4 and IPv6) are matched exactly');
putenv('SAQF_ADMIN_ALLOWED_IPS=10.0.0.0/8');
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$blocked = !Auth::adminNetworkAllowed();
$_SERVER['REMOTE_ADDR'] = '10.1.2.3';
ok($blocked && Auth::adminNetworkAllowed(), 'administrators can be limited to the campus network (SAQF_ADMIN_ALLOWED_IPS)');
putenv('SAQF_ADMIN_ALLOWED_IPS');
putenv('SAQF_TRUSTED_PROXIES=172.28.250.10');
$_SERVER['REMOTE_ADDR'] = '172.28.250.10';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6, 198.51.100.7';
$viaProxy = Request::ip();
$_SERVER['REMOTE_ADDR'] = '198.51.100.99';
$direct = Request::ip();
ok($viaProxy === '198.51.100.7' && $direct === '198.51.100.99', 'the client address is taken from X-Forwarded-For only when the HTTPS proxy sent it (no spoofing)');
putenv('SAQF_TRUSTED_PROXIES');
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$bucket = 'test:' . bin2hex(random_bytes(4));
$hits = 0;
while (Throttle::hit($bucket, 3, 60) && $hits < 10) {
    $hits++;
}
ok($hits === 3, 'rate limits stop repeated actions (3 allowed, the 4th refused)');

[, $html, , $headers] = http(jar(), "$app/login.php");
preg_match('/Content-Security-Policy: ([^\r\n]+)/i', $headers, $csp);
$scriptSrc = preg_match("/script-src ([^;]+)/", $csp[1] ?? '', $sm) ? $sm[1] : '';
ok(trim($scriptSrc) === "'self'" && str_contains($csp[1] ?? '', "object-src 'none'") && str_contains($csp[1] ?? '', "frame-ancestors 'none'"), 'Content-Security-Policy allows scripts from SAQF only (no inline scripts)');
$inline = 0;
foreach (['f.omar' => ["workspace.php?id=$o401", "workspace.php?id=$o401&tab=structure", 'faculty.php', 'catalog.php'], 'it.admin' => ['admin.php?tab=users', 'admin.php?tab=center'], 'qa.director' => ['exceptions.php', 'policies.php']] as $who => $pages) {
    $jw = as_user($app, $who);
    foreach ($pages as $p) {
        [, $page] = http($jw, "$app/$p");
        $inline += preg_match_all('/<script(?![^>]*\bsrc=)[^>]*>|\son(?:click|change|submit|load|input)\s*=/i', $page);
    }
}
ok($inline === 0, 'no page contains inline scripts or inline event handlers');
ok(stripos($headers, 'Cross-Origin-Opener-Policy: same-origin') !== false && stripos($headers, 'X-Frame-Options: DENY') !== false, 'browser isolation headers are sent');

section('6. IT alerts and backup monitoring');
$hookPort = free_port();
serve(__DIR__ . '/mock/webhook.php', [], $hookPort);
putenv("SAQF_ALERT_WEBHOOK=http://127.0.0.1:$hookPort/");
$failing = new class implements \Saqf\Integration\LmsSource {
    public function label(): string
    {
        return 'Broken LMS';
    }

    public function batches(string $termCode, string $courseCode, ?string $section = null): array
    {
        throw new RuntimeException('LMS API request timed out after 20 s');
    }

    public function pending(): array
    {
        return [];
    }

    public function check(): array
    {
        return ['ok' => false, 'message' => 'down'];
    }
};
Integrations::use(null, null, $failing);
Db::exec('DELETE FROM alerts WHERE kind = "connector.lms"');
Scheduler::tick(true);
ok(!Db::val('SELECT 1 FROM alerts WHERE kind = "connector.lms" AND resolved_at IS NULL'), 'one failed LMS run does not wake IT (could be a blip)');
Scheduler::tick(true);
$alert = Db::one('SELECT * FROM alerts WHERE kind = "connector.lms" AND resolved_at IS NULL');
ok($alert && str_contains($alert['detail'], 'timed out'), 'two failed runs in a row raise "' . ($alert['title'] ?? '?') . '"');
ok((bool) Db::val('SELECT 1 FROM notifications n JOIN users u ON u.id = n.user_id WHERE u.role = "admin" AND n.kind = "alert" AND n.title LIKE "%LMS results import%"'), 'administrators are notified in SAQF (and by e-mail when configured)');
usleep(300000);
$posted = glob(sys_get_temp_dir() . "/saqf-mock-webhook-$hookPort/*.json") ?: [];
$msg = $posted ? json_decode((string) file_get_contents(end($posted)), true) : null;
ok(is_array($msg) && str_contains((string) ($msg['text'] ?? ''), 'LMS results import is failing'), 'the alert is posted to the Teams/Slack webhook');
Integrations::reset();
Scheduler::tick(true);
ok((bool) Db::val('SELECT 1 FROM alerts WHERE kind = "connector.lms" AND resolved_at IS NOT NULL'), 'the alert clears itself when the LMS answers again');
putenv('SAQF_ALERT_WEBHOOK');

$monitor = tempdir('saqf-backups');
putenv("SAQF_BACKUP_MONITOR_DIR=$monitor");
file_put_contents("$monitor/last-backup.json", json_encode(['status' => 'failed', 'finished_at' => gmdate('Y-m-d\TH:i:s\Z'), 'file' => '', 'bytes' => 0, 'encrypted' => false, 'offsite' => false, 'files' => false, 'verified' => false, 'message' => 'database dump failed']));
Scheduler::tick(true);
ok((bool) Db::val('SELECT 1 FROM alerts WHERE kind = "backup.failed" AND severity = "critical" AND resolved_at IS NULL'), 'a failed nightly backup raises a critical alert');
file_put_contents("$monitor/last-backup.json", json_encode(['status' => 'ok', 'finished_at' => gmdate('Y-m-d\TH:i:s\Z'), 'file' => 'saqf-20261002-023000.sql.gz.enc', 'bytes' => 150000, 'encrypted' => true, 'offsite' => true, 'files' => true, 'verified' => true, 'message' => '']));
Scheduler::tick(true);
ok((bool) Db::val('SELECT 1 FROM alerts WHERE kind = "backup.failed" AND resolved_at IS NOT NULL') && str_contains(SecurityCenter::backupLine(), 'encrypted') && str_contains(SecurityCenter::backupLine(), 'copied off-site'),
    'the next good backup clears it; System health shows "' . SecurityCenter::backupLine() . '"');
file_put_contents("$monitor/last-backup.json", json_encode(['status' => 'ok', 'finished_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 30 * 3600), 'file' => 'old.sql.gz', 'bytes' => 1, 'encrypted' => true, 'offsite' => true, 'files' => true, 'verified' => true, 'message' => '']));
ok(SecurityCenter::backupOk() === false, 'a backup older than 26 hours counts as missing');
putenv('SAQF_BACKUP_MONITOR_DIR');
$checks = SecurityCenter::checks();
ok(count($checks) >= 15, 'the Security center lists ' . count($checks) . ' controls with their live status');

section('7. Arabic interface');
ok(I18n::phrase('Course learning outcomes') === 'مخرجات تعلم المقرر' && I18n::phrase('Fall 2026 · week 6') === 'خريف 2026 · الأسبوع 6', 'interface phrases and patterns translate ("Fall 2026 · week 6" → "خريف 2026 · الأسبوع 6")');
ok(I18n::phrase('SWE 401 CLO1: section 02 is 15.7 points below section 01') === 'SWE 401 CLO1: الشعبة 02 أقل بـ 15.7 نقطة من الشعبة 01', 'automatic findings are translated with their numbers');
$missingNav = [];
foreach (['faculty', 'hod', 'qa', 'dean', 'leadership', 'admin'] as $role) {
    foreach (View::nav(['role' => $role, 'id' => 0]) as [, $label]) {
        if (I18n::phrase($label) === null) {
            $missingNav[] = $label;
        }
    }
}
ok(!$missingNav, 'every navigation item of every role has an Arabic label' . ($missingNav ? ' (missing: ' . implode(', ', $missingNav) . ')' : ''));
$size = I18n::size();
ok($size['strings'] > 900, "the dictionary holds {$size['strings']} phrases and {$size['patterns']} patterns");
$aj = jar();
[$code, , $loc] = http($aj, "$app/login.php?lang=ar");
[, $html] = http($aj, "$app/login.php");
ok($code === 302 && str_contains($html, '<html lang="ar" dir="rtl">') && str_contains($html, 'تسجيل الدخول') && str_contains($html, 'lang=en'), 'العربية switches the sign-in page to Arabic, right to left, with a way back to English');
[, $html] = http($aj, "$app/demo.php", ['_csrf' => csrf_of($html), 'as' => 'f.omar', 'next' => "workspace.php?id=$o401&tab=results"]);
[$code, $html] = http($aj, "$app/workspace.php?id=$o401&tab=results");
ok($code === 200 && str_contains($html, 'التحقق حسب الشعبة') && str_contains($html, 'dir="rtl"') && !preg_match('/Fatal error|Warning<\/b>/', $html), 'the course workspace renders in Arabic (results by section)');
http($aj, "$app/faculty.php?lang=ar");
$fresh = as_user($app, 'f.omar');
[, $html] = http($fresh, "$app/faculty.php");
ok((string) Db::val('SELECT locale FROM users WHERE username = "f.omar"') === 'ar' && str_contains($html, 'dir="rtl"'), 'the choice is saved on the account and follows the person to another browser');
[$code, $body] = http($aj, "$app/export.php?doc=report&id=$o401");
$axml = (string) unzip_entry($body, 'word/document.xml');
ok($code === 200 && str_starts_with($body, "PK\x03\x04") && preg_match('/\p{Arabic}/u', $axml) === 1, 'downloads are not touched by the translation, and Word exports follow the Arabic interface');
[, $js] = http($aj, "$app/assets/app.js");
ok(!preg_match('/\p{Arabic}/u', $js), 'scripts are never translated');
Db::exec('UPDATE users SET locale = NULL WHERE username = "f.omar"');

section('8. Demo shortcuts exist only in demo mode');
ok(count(Story::USERS) === 8 && count(Story::ROLE_ACCOUNTS) === 6, 'the demo cast is 8 people: one per role plus two section/department colleagues');
$dj = jar();
[, $html] = http($dj, "$app/login.php");
[$code, , $loc] = http($dj, "$app/demo.php", ['_csrf' => csrf_of($html), 'as' => 'hod.ced', 'next' => 'department.php']);
ok($code === 302 && str_ends_with($loc, 'department.php'), 'one-click sign-in opens the Head of Department view');
[, $html] = http($dj, "$app/department.php");
[$code, , $loc] = http($dj, "$app/demo.php", ['_csrf' => csrf_of($html), 'as' => 'it.admin', 'next' => 'admin.php?tab=center']);
ok($code === 302 && str_contains($loc, 'admin.php?tab=center'), 'the role switcher moves to another role in one step');
[$code, $html] = http(jar(), "$app/tour.php");
ok($code === 200 && substr_count($html, 'class="card"') >= 7 && str_contains($html, 'SAQF in five minutes'), 'the guided 5-minute tour lists its 7 steps');
[$code, , $loc] = http(jar(), "$app/demo.php", ['as' => 'it.admin', 'next' => 'admin.php']);
ok($code === 302 && str_ends_with($loc, 'login.php'), 'a demo sign-in without the form token is refused');
$prod = serve(dirname(__DIR__) . '/public', ['APP_ENV' => 'production', 'SAQF_DEMO' => 'false', 'SAQF_STORAGE_DIR' => $store]);
$pj = jar();
[, $html] = http($pj, "$prod/login.php");
[$code] = http($pj, "$prod/demo.php", ['_csrf' => csrf_of($html), 'as' => 'it.admin', 'next' => 'admin.php']);
ok($code === 404 && !str_contains($html, 'one-click') && !str_contains($html, Story::PASSWORD), 'in production mode the demo sign-in does not exist and no demo hints are shown');
[$code, , $loc] = http(jar(), "$prod/tour.php");
ok($code === 302 && str_ends_with($loc, 'login.php'), 'the guided tour is off in production mode');
[, , , $ph] = http(jar(), "$prod/login.php");
ok((bool) Db::val('SELECT 1 FROM audit_log WHERE action = "security.demo_refused"'), 'the refused attempt is recorded as a security event');

finish();
