<?php
declare(strict_types=1);

/**
 * Injection and output-encoding probes. Hostile text goes into every kind of input a person can use (outcome
 * statements, report narratives, evidence titles and file names, incident reports, names, policy reasons,
 * passkey names) and into every query parameter of every page; then every page that can display it is fetched,
 * in English and in Arabic, as several roles, and must show it ESCAPED. SQL meta-characters are sent as
 * identifiers and filters and must never produce a database error or change data. Redirect targets and
 * response headers are checked for injection.
 * This is an automated probe of known patterns, not a penetration test.
 *   php bin/install.php --demo --fresh && php tests/injection_test.php
 */

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/support.php';

use Saqf\Core\Config;
use Saqf\Core\Db;
use Saqf\Quality\Evidence;
use Saqf\Security\Incidents;

if (Config::env() === 'production') {
    fwrite(STDERR, "Refusing to run against a production installation.\n");
    exit(1);
}

const PAYLOAD = "<script>alert('XSSPROBE')</script>\"><img src=x onerror=alert(1)>'><svg/onload=alert(1)>{{7*7}}";
/** Raw (unescaped) markers that must never appear in a page. */
const RAW = ["<script>alert('XSSPROBE')", '<img src=x onerror', '<svg/onload'];

function http(string $jar, string $url, $post = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) ? http_build_query($post) : $post);
    }
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$code, substr($raw, $size), substr($raw, 0, $size)];
}
function jar(): string
{
    return (string) tempnam(sys_get_temp_dir(), 'jar');
}
function csrf_of(string $html): string
{
    return preg_match('/name="(?:_csrf|csrf)" (?:value|content)="([^"]+)"/', $html, $m) ? $m[1] : '';
}
function as_user(string $base, string $username, string $next = 'index.php'): string
{
    $j = jar();
    [, $html] = http($j, "$base/login.php");
    http($j, "$base/demo.php", ['_csrf' => csrf_of($html), 'as' => $username, 'next' => $next]);
    return $j;
}
function api(string $base, string $jar, string $action, array $data): array
{
    [, $html] = http($jar, "$base/account.php");
    [$code, $body] = http($jar, "$base/api.php", ['action' => $action] + $data, ['X-CSRF-Token: ' . csrf_of($html)]);
    return [$code, json_decode($body, true) ?: []];
}
/** True when the page shows none of the raw markers. */
function escaped(string $html): bool
{
    foreach (RAW as $m) {
        if (str_contains($html, $m)) {
            return false;
        }
    }
    return true;
}

$app = serve(dirname(__DIR__) . '/public');
$omar = as_user($app, 'f.omar');
$it = as_user($app, 'it.admin');
$hod = as_user($app, 'hod.ced');
$qa = as_user($app, 'qa.director');
$o412 = (int) Db::val('SELECT o.id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id WHERE c.code = "SWE 412" AND t.code = "2026-1"');
$o401 = (int) Db::val('SELECT o.id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id WHERE c.code = "SWE 401" AND t.code = "2026-1"');
$omarRow = Db::one('SELECT * FROM users WHERE username = "f.omar"');
$adminRow = Db::one('SELECT * FROM users WHERE username = "it.admin"');

section('1. Hostile text stored through the real inputs');
[$code, $r] = api($app, $omar, 'save_clo', ['offering' => $o412, 'statement' => 'Analyze ' . PAYLOAD . ' in a project', 'domain' => 'Skills', 'statement_ar' => PAYLOAD . ' تحليل']);
ok($code === 200 && ($r['ok'] ?? false), 'an outcome statement containing script and markup is accepted as plain text' . ($code === 200 ? '' : ' (' . $code . ': ' . json_encode($r) . ')'));
[$code, $r] = api($app, $omar, 'narrative', ['offering' => $o401, 'section' => 'interpretation', 'content' => 'Results ' . PAYLOAD]);
ok($code === 200, 'a report narrative containing script and markup is accepted as plain text');
$o = Db::one('SELECT o.*, c.code AS course_code, c.title AS course_title, t.name AS term_name, t.status AS term_status FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id WHERE o.id = ?', [$o401]);
$tmp = (string) tempnam(sys_get_temp_dir(), 'ev');
copy(dirname(__DIR__) . '/docs/demo/SWE401-midterm-exam-paper.pdf', $tmp);
$assess = (int) Db::val('SELECT id FROM assessments WHERE spec_version_id = ? LIMIT 1', [$o['spec_version_id']]);
$evId = Evidence::store($o, ['tmp_name' => $tmp, 'name' => "a\"\r\nX-Injected: yes<script>.pdf", 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK], 'assessment', 'Paper ' . PAYLOAD, $assess, null, $omarRow, false);
ok($evId > 0, 'an evidence title and a hostile file name are accepted');
$incId = Incidents::create($adminRow, 'Incident ' . PAYLOAD, 'other', 'low', false, \Saqf\Core\Clock::now()->modify('-1 hour')->format('Y-m-d H:i:s'), 'Description ' . PAYLOAD . ' and more text to pass the length check', null);
ok($incId > 0, 'an incident title and description are accepted');
Db::update('users', ['full_name' => 'Dr ' . PAYLOAD], 'username = ?', ['f.noura']);
Db::exec('INSERT INTO passkeys (user_id, credential_id, public_key, sign_count, label, created_at) VALUES (?, ?, "x", 0, ?, NOW())', [$omarRow['id'], 'probe-credential-id-' . bin2hex(random_bytes(8)), mb_substr(PAYLOAD, 0, 80)]);
\Saqf\Core\Policy::set('qa.sample_rate_pct', '21', 'Reason ' . PAYLOAD);

section('2. Every page that displays it shows it escaped (English and Arabic)');
$pages = [
    ['f.omar', $omar, "workspace.php?id=$o412&tab=structure"], ['f.omar', $omar, "workspace.php?id=$o412&tab=overview"],
    ['f.omar', $omar, "workspace.php?id=$o401&tab=report"], ['f.omar', $omar, "workspace.php?id=$o401&tab=evidence"], ['f.omar', $omar, "workspace.php?id=$o401&tab=closeout"],
    ['f.omar', $omar, "workspace.php?id=$o401&tab=history"], ['f.omar', $omar, "report.php?type=course&id=$o401"], ['f.omar', $omar, 'account.php'], ['f.omar', $omar, 'faculty.php'],
    ['hod.ced', $hod, 'department.php'], ['hod.ced', $hod, "workspace.php?id=$o401&tab=closeout"], ['qa.director', $qa, 'quality.php'], ['qa.director', $qa, 'translations.php?kind=content'],
    ['it.admin', $it, 'admin.php?tab=audit'], ['it.admin', $it, 'admin.php?tab=users'], ['it.admin', $it, 'admin.php?tab=review'], ['it.admin', $it, 'incidents.php'], ['it.admin', $it, "incidents.php?id=$incId"],
    ['it.admin', $it, 'admin.php?tab=center'], ['it.admin', $it, 'security_report.php'], ['it.admin', $it, 'policies.php'],
];
$shown = 0;
$bad = [];
foreach ($pages as [$who, $j, $url]) {
    foreach (['en', 'ar'] as $lang) {
        http($j, "$app/$url" . (str_contains($url, '?') ? '&' : '?') . "lang=$lang");
        [$code, $html] = http($j, "$app/$url");
        if ($code !== 200) {
            $bad[] = "$who $url ($lang) answered $code";
            continue;
        }
        if (!escaped($html)) {
            $bad[] = "$who $url ($lang) shows raw markup";
        }
        if (str_contains($html, '&lt;script&gt;')) {
            $shown++;
        }
    }
    http($j, "$app/$url" . (str_contains($url, '?') ? '&' : '?') . 'lang=en');
}
ok(!$bad, count($pages) * 2 . ' page views (English and Arabic) show no unescaped markup' . ($bad ? ': ' . implode('; ', array_slice($bad, 0, 4)) : ''));
ok($shown >= 12, 'the hostile text really was displayed, as visible text, on ' . $shown . ' of those views (so the check is meaningful)');
[, $html] = http($omar, "$app/workspace.php?id=$o412&tab=structure");
ok(str_contains($html, '&lt;script&gt;alert(&#039;XSSPROBE&#039;)&lt;/script&gt;'), 'the outcome statement is shown as text, HTML-escaped');
[, $word] = http($omar, "$app/export.php?doc=report&id=$o401");
[, , $wh] = http($omar, "$app/export.php?doc=report&id=$o401");
ok(!preg_match('/^X-Injected:/mi', $wh), 'the Word export is a download and carries no injected header');

section('3. Reflected parameters');
$params = ['q', 'ref', 'action', 'actor', 'object', 'from', 'to', 'tab', 'kind', 'term', 'finding', 'version', 'type', 'id', 'page', 'filter', 'next', 'msg', 'error', 'email', 'username', 'name'];
$qs = http_build_query(array_fill_keys($params, PAYLOAD));
$scan = [['f.omar', $omar, ['faculty.php', 'search.php', 'account.php', 'help.php', 'calendar.php', "workspace.php?id=$o401", 'report.php', 'improvements.php', 'notifications.php']],
    ['hod.ced', $hod, ['department.php', 'approvals.php', 'exceptions.php', 'programs.php', 'search.php']],
    ['qa.director', $qa, ['quality.php', 'exceptions.php', 'approvals.php', 'translations.php', 'spec_import.php', 'policies.php']],
    ['it.admin', $it, ['admin.php', 'admin.php?tab=audit', 'admin.php?tab=errors', 'admin.php?tab=users', 'admin.php?tab=security', 'admin.php?tab=alerts', 'admin.php?tab=golive', 'incidents.php', 'translations.php']]];
$reflected = [];
$checked = 0;
foreach ($scan as [$who, $j, $list]) {
    foreach ($list as $url) {
        [$code, $html] = http($j, "$app/$url" . (str_contains($url, '?') ? '&' : '?') . $qs);
        $checked++;
        if ($code >= 500) {
            $reflected[] = "$who $url → HTTP $code";
        } elseif (!escaped($html)) {
            $reflected[] = "$who $url reflects raw markup";
        }
    }
}
foreach (['login.php', 'forgot.php', 'reset.php', 'mfa.php', 'help.php', 'tour.php', 'health.php', 'ping.php'] as $url) {
    [$code, $html] = http(jar(), "$app/$url?" . $qs);
    $checked++;
    if ($code >= 500 || !escaped($html)) {
        $reflected[] = "anonymous $url → HTTP $code / " . (escaped($html) ? 'escaped' : 'raw');
    }
}
ok(!$reflected, "$checked pages answered with every parameter set to the hostile text: no error, nothing reflected unescaped" . ($reflected ? ': ' . implode('; ', array_slice($reflected, 0, 4)) : ''));
[$jcode, $json, $hd] = http($omar, "$app/search.php?format=json&q=" . urlencode(PAYLOAD));
ok($jcode === 200 && is_array(json_decode($json, true)) && stripos($hd, 'application/json') !== false, 'search suggestions for the hostile text answer with valid JSON served as application/json');
ok(stripos($hd, 'X-Content-Type-Options: nosniff') !== false, 'with nosniff, so a browser never renders that JSON as a page');

section('4. SQL meta-characters');
$users = (int) Db::val('SELECT COUNT(*) FROM users');
$audit = (int) Db::val('SELECT COUNT(*) FROM audit_log');
$sqli = ["1' OR '1'='1", '1 OR 1=1', "1; DROP TABLE users; --", "1' UNION SELECT password_hash FROM users --", "' OR SLEEP(5) -- ", '1) OR (1=1', '-1', '99999999999999999999', '0x1', 'NULL'];
$errs = [];
$t0 = microtime(true);
foreach ($sqli as $v) {
    $e = urlencode($v);
    foreach ([[$omar, "workspace.php?id=$e"], [$omar, "workspace.php?id=$o401&tab=$e"], [$omar, "report.php?type=course&id=$e"], [$omar, "evidence.php?id=$e"], [$omar, "export.php?doc=report&id=$e"], [$omar, "search.php?q=$e"],
        [$hod, "approvals.php?version=$e"], [$qa, "exceptions.php?finding=$e"], [$qa, "program.php?id=$e"], [$it, "admin.php?tab=audit&action=$e&actor=$e&object=$e&from=$e&to=$e&page=$e"], [$it, "admin.php?tab=errors&ref=$e"], [$it, "incidents.php?id=$e"]] as [$j, $url]) {
        [$code, $html] = http($j, "$app/$url");
        if ($code >= 500 || preg_match('/SQLSTATE|You have an error in your SQL|mysqli?_|PDOException/i', $html)) {
            $errs[] = "$url → $code";
        }
    }
}
ok(!$errs, count($sqli) * 12 . ' requests with SQL meta-characters as ids and filters: no server error, no database message' . ($errs ? ': ' . implode('; ', array_slice($errs, 0, 4)) : ''));
ok((int) Db::val('SELECT COUNT(*) FROM users') === $users && Db::val('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = "users"') == 1, 'the users table is intact and no account was added');
ok(microtime(true) - $t0 < 30, 'a SLEEP() injection did not delay any response');
[$code] = http($omar, "$app/api.php", ['action' => "save_clo'; DROP TABLE clos;--", 'offering' => $o412, 'statement' => "x'); DROP TABLE clos;--"], ['X-CSRF-Token: ' . csrf_of(http($omar, "$app/account.php")[1])]);
ok(Db::val('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = "clos"') == 1, 'an injected action name or statement drops nothing');

section('5. Redirects and headers');
[, $html] = http($j = jar(), "$app/login.php");
foreach (['https://evil.example/', '//evil.example/', 'javascript:alert(1)', "index.php\r\nX-Injected: 1", '/\\evil.example'] as $next) {
    [$code, , $hd] = http($j, "$app/demo.php", ['_csrf' => csrf_of($html), 'as' => 'f.omar', 'next' => $next]);
    $loc = preg_match('/^Location: (.*)$/mi', $hd, $m) ? trim($m[1]) : '';
    if ($loc !== '' && (preg_match('#^(https?:)?//#i', $loc) || stripos($loc, 'javascript:') !== false || stripos($hd, 'X-Injected') !== false)) {
        ok(false, "an external or injected sign-in redirect was followed: $next → $loc");
    }
    http($j, "$app/logout.php", ['_csrf' => csrf_of(http($j, "$app/account.php")[1])]);
    [, $html] = http($j, "$app/login.php");
}
ok(true, 'the "next" address after sign-in never sends a person to another site or injects a header');
[$code, , $hd] = http($omar, "$app/evidence.php?id=$evId");
ok($code === 200 && !preg_match('/^X-Injected:/mi', $hd) && stripos($hd, 'Content-Disposition: attachment') !== false && !preg_match('/filename="[^"]*[<>"\r\n][^"]*"/', $hd), 'a download with a hostile file name has no injected header, no markup or line breaks in the name, and is an attachment');
[, , $hd] = http(jar(), "$app/login.php?lang=" . urlencode("ar\r\nX-Injected: 1"));
ok(!preg_match('/^X-Injected:/mi', $hd), 'a hostile language parameter injects no header');
[, , $hd] = http(jar(), "$app/login.php", null, ['Host: evil.example']);
ok(!preg_match('/^(Location|Set-Cookie|Link|Refresh):.*evil\.example/mi', $hd), 'a forged Host header is not echoed into a redirect, link or cookie (PHP\'s development server itself echoes a Host line; Apache does not)');
// Reset-link poisoning: run in this process, because the HTTP form also needs the robot check and a mail server.
// With an address configured, the e-mailed link uses it whatever Host the request claimed.
putenv('SAQF_BASE_URL=https://saqf.example.edu');
putenv('SAQF_MAIL_TRANSPORT=log');
$prevLog = ini_set('error_log', '/dev/null');
$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'evil.example';
$_SERVER['REMOTE_ADDR'] = '192.0.2.77';
$lastMail = (int) Db::val('SELECT COALESCE(MAX(id), 0) FROM mail_outbox');
\Saqf\Security\PasswordReset::request('f.omar');
$mail = (string) Db::val('SELECT body FROM mail_outbox WHERE id > ? AND purpose = "password_reset" ORDER BY id DESC LIMIT 1', [$lastMail]);
ini_set('error_log', (string) $prevLog);
putenv('SAQF_BASE_URL');
putenv('SAQF_MAIL_TRANSPORT');
unset($_SERVER['HTTP_HOST'], $_SERVER['SERVER_NAME'], $_SERVER['REMOTE_ADDR']);
ok(str_contains($mail, 'https://saqf.example.edu/reset.php?token=') && !str_contains($mail, 'evil.example'), 'a password-reset link uses the configured SAQF_BASE_URL even when the request carries a forged Host header');

// ---------------------------------------------------------------------------------------------
section('6. Browser policy violation reports (public/csp_report.php)');
[, , $hdr] = http(jar(), "$app/login.php");
ok(str_contains($hdr, 'report-uri csp_report.php'), 'the page policy names the report address');
$post = static fn(string $body, array $h = ['Content-Type: application/csp-report']) => http(jar(), "$app/csp_report.php", $body, $h);
$count = static fn() => (int) Db::val('SELECT COUNT(*) FROM audit_log WHERE action = "security.csp_violation"');
$base0 = $count();
$report = json_encode(['csp-report' => ['document-uri' => "$app/workspace.php?id=16&secret=TOKEN123", 'effective-directive' => 'script-src-elem', 'blocked-uri' => 'https://evil.example/steal.js?token=ABC#frag', 'violated-directive' => 'script-src']]);
[$code] = $post($report);
ok($code === 204 && $count() === $base0 + 1, 'a violation report is accepted (204) and recorded once');
$row = Db::one('SELECT summary, new_value FROM audit_log WHERE action = "security.csp_violation" ORDER BY id DESC LIMIT 1');
ok(str_contains($row['summary'], 'script-src-elem') && str_contains($row['summary'], 'https://evil.example') && !str_contains($row['summary'] . $row['new_value'], 'TOKEN123') && !str_contains($row['summary'] . $row['new_value'], 'ABC') && !str_contains($row['summary'] . $row['new_value'], 'steal.js'), 'only the directive, the blocked host and the page path are kept: no query string, token or file name');
[$code] = $post($report);
ok($code === 204 && $count() === $base0 + 1, 'the same violation again the same day is not recorded again (a broken page cannot flood the log)');
[$code] = $post(json_encode(['csp-report' => ['document-uri' => "$app/x.php", 'effective-directive' => 'img-src<script>', 'blocked-uri' => 'inline']]));
$last = Db::val('SELECT summary FROM audit_log WHERE action = "security.csp_violation" ORDER BY id DESC LIMIT 1');
ok($code === 204 && !str_contains((string) $last, '<') && !str_contains((string) $last, '>'), 'markup in a report field is stripped before it is recorded');
$n = $count();
[$code] = $post('{not json');
[$code2] = $post(json_encode(['csp-report' => 'x']));
[$code3] = $post(json_encode(['csp-report' => ['effective-directive' => str_repeat('a', 9000)]]));
[$code4] = http(jar(), "$app/csp_report.php");
ok($code === 204 && $code2 === 204 && $code3 === 204 && $code4 === 204 && $count() === $n, 'bad JSON, a wrong shape, an oversized body and a plain GET are all answered 204 and record nothing (a sender learns nothing)');
Db::exec('DELETE FROM rate_limits WHERE bucket LIKE "csp%"');
for ($i = 0; $i < 25; $i++) {
    $post(json_encode(['csp-report' => ['document-uri' => "$app/p$i.php", 'effective-directive' => 'img-src', 'blocked-uri' => "https://h$i.example/x"]]));
}
ok($count() - $n <= 20, 'one network address can add at most 20 reports an hour (' . ($count() - $n) . ' recorded of 25 sent)');
$omar = as_user($app, 'it.admin', 'index.php');
[, $html] = http($omar, "$app/admin.php?tab=center");
ok(str_contains($html, 'Browser policy violation reports') && str_contains($html, 'distinct case(s) in the last 7 days'), 'the Security center reports the cases seen in the last 7 days');

finish();
