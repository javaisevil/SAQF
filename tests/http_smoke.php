<?php
declare(strict_types=1);

/**
 * HTTP smoke + authorization tests against a running SAQF (demo database).
 *   php -S 127.0.0.1:8080 -t public &   then   php tests/http_smoke.php http://127.0.0.1:8080
 * Signs in as every demo role, opens every page in its navigation plus key detail pages,
 * and deliberately attempts cross-role / cross-scope access that must be denied.
 */

require __DIR__ . '/../src/bootstrap.php';

use Saqf\Core\Db;
use Saqf\Demo\Story;

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
$fail = 0;
$pass = 0;

function client(): array
{
    return ['jar' => tempnam(sys_get_temp_dir(), 'saqf')];
}

function req(array $c, string $url, ?array $post = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $c['jar'], CURLOPT_COOKIEFILE => $c['jar'], CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => false, CURLOPT_USERAGENT => 'saqf-smoke', CURLOPT_HTTPHEADER => $headers]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loc = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return [$code, $body, $loc];
}

function check(bool $ok, string $label): void
{
    global $fail, $pass;
    if ($ok) {
        $pass++;
    } else {
        $fail++;
        echo "  FAIL  $label\n";
    }
}

function csrf(string $html): string
{
    return preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) ? $m[1] : (preg_match('/<meta name="csrf" content="([^"]+)"/', $html, $m2) ? $m2[1] : '');
}

function login(string $base, string $user, string $password = Story::PASSWORD): array
{
    $c = client();
    [, $html] = req($c, "$base/login.php");
    [$code, , $loc] = req($c, "$base/login.php", ['_csrf' => csrf($html), 'username' => $user, 'password' => $password]);
    $c['ok'] = $code === 302 && str_contains($loc, 'index.php');
    return $c;
}

function clean(string $body): bool
{
    return !preg_match('/(Fatal error|Warning<\/b>|Notice<\/b>|Deprecated<\/b>|Uncaught|Something went wrong|on line <b>)/', $body);
}

$offering = static fn(string $code, string $term) => (int) Db::val('SELECT o.id FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id WHERE c.code = ? AND t.code = ?', [$code, $term]);
$swe412 = $offering('SWE 412', '2026-1');
$swe401 = $offering('SWE 401', '2026-1');
$acc311 = $offering('ACC 311', '2026-1');
$swe401old = $offering('SWE 401', '2025-1');
$swe = (int) Db::val('SELECT id FROM programs WHERE code = "SWE"');
$acc = (int) Db::val('SELECT id FROM programs WHERE code = "ACC"');
$finding = (int) Db::val('SELECT id FROM findings WHERE rule_code = "COURSE_CREDIT_CONFLICT"');
$specPending = (int) Db::val('SELECT id FROM spec_versions WHERE status = "approved" ORDER BY id LIMIT 1');

$pages = [
    'f.faisal' => ['faculty.php', "workspace.php?id=$swe412", "workspace.php?id=$swe412&tab=structure", "workspace.php?id=$swe412&tab=results", "workspace.php?id=$swe412&tab=improve", "workspace.php?id=$swe412&tab=report", "workspace.php?id=$swe412&tab=history", 'improvements.php', 'catalog.php', "catalog.php?program=$swe", 'notifications.php', 'account.php', 'search.php?q=SWE', "program.php?id=$swe"],
    'f.omar' => ['faculty.php', "workspace.php?id=$swe401", "workspace.php?id=$swe401&tab=improve", "workspace.php?id=$swe401old", "report.php?type=course&id=$swe401old", "report.php?type=course&id=$swe401old&snapshot=1"],
    'hod.ced' => ['department.php', 'approvals.php', 'exceptions.php', 'programs.php', "program.php?id=$swe", "program.php?id=$swe&tab=plos", "program.php?id=$swe&tab=plan", "program.php?id=$swe&tab=matrix", 'improvements.php', 'assign.php', "assign.php?program=$swe", 'catalog.php', "workspace.php?id=$swe412", "report.php?type=program&id=$swe", "report.php?type=spec&id=$specPending", 'policies.php'],
    'qa.director' => ['quality.php', 'exceptions.php', "exceptions.php?finding=$finding", 'approvals.php', 'programs.php', 'improvements.php', 'policies.php', 'institution.php', "workspace.php?id=$acc311"],
    'dean.coe' => ['college.php', 'programs.php', 'exceptions.php', 'improvements.php', "program.php?id=$swe"],
    'vp.academic' => ['institution.php', 'programs.php', 'exceptions.php', 'improvements.php', 'college.php?college=1'],
    'it.admin' => ['admin.php', 'admin.php?tab=users', 'admin.php?tab=security', 'admin.php?tab=audit', 'admin.php?tab=errors', 'admin.php?tab=integrations', 'policies.php', 'catalog.php'],
];

echo "== pages render cleanly for every role\n";
foreach ($pages as $user => $list) {
    $c = login($base, $user);
    check($c['ok'], "$user can sign in");
    foreach ($list as $p) {
        [$code, $body] = req($c, "$base/$p");
        check($code === 200 && clean($body), "$user GET $p → $code" . (clean($body) ? '' : ' (PHP error in output)'));
    }
}

echo "== authorization is enforced server-side\n";
$deny = [
    ['f.faisal', 'department.php'], ['f.faisal', 'quality.php'], ['f.faisal', 'admin.php'], ['f.faisal', "workspace.php?id=$swe401"],
    ['f.faisal', "workspace.php?id=$acc311"], ['f.faisal', "program.php?id=$acc"], ['f.faisal', "exceptions.php?finding=$finding"],
    ['hod.ced', "workspace.php?id=$acc311"], ['hod.ced', "program.php?id=$acc"], ['hod.ced', 'admin.php'], ['hod.ced', 'quality.php'],
    ['dean.coe', "workspace.php?id=$acc311"], ['dean.coe', 'admin.php'], ['it.admin', "workspace.php?id=$swe412"], ['it.admin', 'department.php'],
    ['it.admin', "program.php?id=$swe"], ['vp.academic', 'admin.php'], ['qa.director', 'admin.php'], ['f.omar', "report.php?type=course&id=$acc311"],
];
foreach ($deny as [$user, $p]) {
    $c = login($base, $user);
    [$code, $body] = req($c, "$base/$p");
    check(in_array($code, [403, 404], true) && str_contains($body, 'Access denied'), "$user denied $p (got $code)");
}

echo "== API mutations are scoped and CSRF-protected\n";
$c = login($base, 'f.omar');
[, $html] = req($c, "$base/faculty.php");
$tok = csrf($html);
[$code, $body] = req($c, "$base/api.php", ['action' => 'save_clo', 'offering' => $swe412, 'statement' => 'Analyze things.', 'domain' => 'Skills'], ["X-CSRF-Token: $tok"]);
check($code === 403 && str_contains($body, '"ok":false'), "f.omar cannot edit SWE 412 (Fall 2026 belongs to f.faisal) → $code");
[$code] = req($c, "$base/api.php", ['action' => 'save_clo', 'offering' => $swe401, 'statement' => 'Analyze things.', 'domain' => 'Skills']);
check($code === 403, "missing CSRF token rejected → $code");
[$code, $body] = req($c, "$base/api.php", ['action' => 'policy_set', 'p' => ['clo.default_target_pct=10']], ["X-CSRF-Token: $tok"]);
check($code === 403, "faculty cannot change quality policy → $code");
[$code] = req($c, "$base/api.php", ['action' => 'hod_decide', 'version' => 1, 'decision' => 'approve'], ["X-CSRF-Token: $tok"]);
check($code === 403, "faculty cannot approve specifications → $code");
$anon = client();
[$code] = req($anon, "$base/api.php", ['action' => 'save_clo']);
check($code === 401, "anonymous API call rejected → $code");
[$code, , $loc] = req($anon, "$base/workspace.php?id=$swe412");
check($code === 302 && str_contains($loc, 'login.php'), 'anonymous page access redirected to login');
$h = login($base, 'hod.ced');
[, $html] = req($h, "$base/department.php");
$ht = csrf($html);
$accPlo = (int) Db::val('SELECT id FROM plos WHERE program_id = ? LIMIT 1', [$acc]);
[$code] = req($h, "$base/api.php", ['action' => 'plo_save', 'program' => $acc, 'code' => 'PLO1', 'statement' => 'Hijacked statement for testing', 'domain' => 'Skills', 'reason' => 'test'], ["X-CSRF-Token: $ht"]);
check($code === 403, "HoD of CED cannot change Accounting PLOs → $code");

echo "== account lockout\n";
$victim = 'f.lina';
for ($i = 0; $i < 5; $i++) {
    login($base, $victim, 'wrong-password-' . $i);
}
$c = login($base, $victim);
check(!$c['ok'], 'account locked after 5 failed attempts (correct password refused)');
Db::exec('UPDATE users SET status = "active", locked_until = NULL, failed_logins = 0 WHERE username = ?', [$victim]);
Db::exec('DELETE FROM login_attempts WHERE username = ? AND success = 0', [$victim]);

echo "== error log, audit integrity and maintenance mode\n";
$ref = \Saqf\Core\ErrorLog::record(new RuntimeException('smoke-test simulated failure'));
$adm = login($base, 'it.admin');
[$code, $html] = req($adm, "$base/admin.php?tab=errors&ref=$ref");
check($code === 200 && str_contains($html, 'smoke-test simulated failure'), "error recorded with reference $ref and findable by IT");
Db::exec('DELETE FROM system_errors WHERE ref = ?', [$ref]);
[, $html] = req($adm, "$base/admin.php");
[$code] = req($adm, "$base/admin.php", ['_csrf' => csrf($html), 'op' => 'verify']);
[, $html] = req($adm, "$base/admin.php");
check(str_contains($html, 'Intact ('), 'audit hash chain verified from the admin console');
[, $html] = req($adm, "$base/admin.php");
req($adm, "$base/admin.php", ['_csrf' => csrf($html), 'op' => 'maintenance', 'value' => '1', 'reason' => 'smoke test window']);
$fac = login($base, 'f.omar');
[$code, $body] = req($fac, "$base/faculty.php");
check($code === 503 && str_contains($body, 'under maintenance'), "maintenance mode blocks non-admin users → $code");
[$code] = req($adm, "$base/admin.php");
check($code === 200, 'administrator still has access during maintenance');
Db::exec('UPDATE system_settings SET value = "0" WHERE setting_key = "maintenance_mode"');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
