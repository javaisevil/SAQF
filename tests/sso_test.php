<?php
declare(strict_types=1);

/**
 * End-to-end university sign-in (OpenID Connect) through real HTTP, plus the SSO-era login
 * rules, the health probe and administrator account creation. Starts its own SAQF web server
 * (configured for SSO) and a stand-in identity provider (tests/mock/idp.php).
 * Run against a FRESH demo database:
 *   php bin/install.php --demo --fresh && php tests/sso_test.php
 */

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/support.php';

use Saqf\Core\Db;
use Saqf\Demo\Story;

$idp = serve(__DIR__ . '/mock/idp.php');
$idpState = sys_get_temp_dir() . '/saqf-mock-idp-' . parse_url($idp, PHP_URL_PORT);
@mkdir($idpState, 0700, true);
$appPort = free_port();
$app = "http://127.0.0.1:$appPort";
serve(dirname(__DIR__) . '/public', [
    'SAQF_OIDC_ISSUER' => $idp, 'SAQF_OIDC_CLIENT_ID' => 'saqf-test', 'SAQF_OIDC_CLIENT_SECRET' => 'test-secret',
    'SAQF_BASE_URL' => $app, 'SAQF_PASSWORD_LOGIN' => 'admins', 'SAQF_OIDC_AUTO_PROVISION' => '1',
    'SAQF_OIDC_ROLE_CLAIM' => 'roles', 'SAQF_OIDC_ROLE_MAP' => 'SAQF.Faculty=faculty;SAQF.QA=qa',
], $appPort);

function http(string $jar, string $url, ?array $post = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'saqf-sso-test', CURLOPT_HEADER => true]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
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

/** Runs the browser side of a sign-in; returns [callback URL, page where SAQF sends the browser next]. */
function sso(string $jar, array $claims, array $options = [], ?string $completeWith = null): array
{
    global $app, $idpState;
    file_put_contents("$idpState/next.json", json_encode(['claims' => $claims, 'options' => $options]));
    [$code, , $toIdp] = http($jar, "$app/sso.php?start=1");
    if ($code !== 302) {
        return ['', 'start failed'];
    }
    [, , $callback] = http($jar, $toIdp);
    [, $body] = http($completeWith ?? $jar, $callback);
    return [$callback, preg_match('/url=([a-z.]+)/', $body, $m) ? $m[1] : 'none'];
}

function flash(string $jar): string
{
    global $app;
    [, $body] = http($jar, "$app/login.php");
    return preg_match('/class="alert[^"]*"[^>]*>(.*?)<\/div>/s', $body, $m) ? html_entity_decode(strip_tags($m[1])) : '';
}

section('1. Sign-in page and probes');
[$code, $body] = http(jar(), "$app/login.php");
ok($code === 200 && str_contains($body, 'Sign in with your university account') && str_contains($body, 'sso.php?start=1'), 'login page offers the university sign-in button');
ok(str_contains($body, 'Administrator sign-in'), 'password form is reduced to administrator break-glass access (SAQF_PASSWORD_LOGIN=admins)');
[$code, $body, , $headers] = http(jar(), "$app/health.php");
$h = json_decode($body, true);
ok($code === 200 && ($h['database'] ?? '') === 'ok' && !stripos($headers, 'Set-Cookie'), 'health probe: ' . $body . ' (no session cookie)');

section('2. University sign-in (authorization code + PKCE)');
$omar = jar();
file_put_contents("$idpState/next.json", json_encode(['claims' => ['sub' => 'omar-123', 'preferred_username' => 'f.omar@yu.edu.sa', 'name' => 'Dr. Omar Al-Harbi']]));
[$code, , $toIdp, $headers] = http($omar, "$app/sso.php?start=1");
parse_str((string) parse_url($toIdp, PHP_URL_QUERY), $q);
ok($code === 302 && str_starts_with($toIdp, "$idp/authorize") && ($q['code_challenge_method'] ?? '') === 'S256' && strlen($q['state'] ?? '') >= 40 && strlen($q['nonce'] ?? '') >= 40, 'browser sent to the identity provider with state, nonce and PKCE challenge');
ok(str_contains($headers, 'SAQF_SSO=') && stripos($headers, 'SameSite=Lax') !== false && stripos($headers, 'HttpOnly') !== false, 'sign-in attempt bound to this browser (HttpOnly, SameSite=Lax cookie)');
[, , $callback] = http($omar, $toIdp);
[$code, $body] = http($omar, $callback);
ok($code === 200 && str_contains($body, 'url=index.php'), 'callback verified the ID token and continues to SAQF');
[$code, , $loc] = http($omar, "$app/index.php");
[$code2, $body2] = http($omar, "$app/faculty.php");
ok(str_ends_with($loc, 'faculty.php') && $code2 === 200 && str_contains($body2, 'Omar'), 'Dr. Omar is signed in to his faculty home');
ok(Db::val('SELECT auth_source FROM users WHERE username = "f.omar"') === 'sso' && str_contains((string) Db::val('SELECT summary FROM audit_log WHERE action = "auth.login" ORDER BY id DESC LIMIT 1'), 'university SSO'), 'account linked to the university identity; sign-in audited as SSO');
[, $body] = http($omar, $callback);
$why = (string) Db::val('SELECT reason FROM login_attempts ORDER BY id DESC LIMIT 1');
ok(str_contains($body, 'url=login.php') && str_contains($why, 'expired'), 'replaying the same callback is refused (' . $why . ')');

section('3. Attacks and failures');
$a = jar();
$b = jar();
[, $next] = sso($a, ['sub' => 'omar-123', 'preferred_username' => 'f.omar@yu.edu.sa'], [], $b);
$msg = flash($b);
ok($next === 'login.php' && str_contains($msg, 'another browser'), 'a sign-in started in another browser cannot be completed there (login CSRF): "' . $msg . '"');
[, $next] = sso($c = jar(), ['sub' => 'omar-123', 'preferred_username' => 'f.omar@yu.edu.sa'], ['bad_signature' => true]);
$msg = flash($c);
ok($next === 'login.php' && str_contains($msg, 'signature'), 'forged ID token signature refused: "' . $msg . '"');
[, $next] = sso($c = jar(), ['sub' => 'omar-123'], ['aud' => 'some-other-app']);
$msg = flash($c);
ok($next === 'login.php' && str_contains($msg, 'another application'), 'token issued for another application refused: "' . $msg . '"');
[, $next] = sso($c = jar(), [], ['error' => 'access_denied']);
$msg = flash($c);
ok($next === 'login.php' && str_contains($msg, 'did not complete'), 'sign-in cancelled at the provider is explained: "' . $msg . '"');
[, $next] = sso($c = jar(), ['sub' => 'stranger-1', 'preferred_username' => 'stranger@yu.edu.sa']);
$msg = flash($c);
ok($next === 'login.php' && str_contains($msg, 'not set up'), 'a university account without a SAQF role is refused with guidance: "' . $msg . '"');
ok((bool) Db::val('SELECT 1 FROM login_attempts WHERE reason LIKE "sso:%" AND success = 0'), 'refused SSO sign-ins appear in the security log');

section('4. Provisioning and roles from the identity provider');
[, $next] = sso($qa = jar(), ['sub' => 'qa-777', 'preferred_username' => 'l.qa@yu.edu.sa', 'name' => 'Dr. Lama Quality', 'email' => 'l.qa@yu.edu.sa', 'roles' => ['SAQF.QA']]);
[, , $loc] = http($qa, "$app/index.php");
[$code] = http($qa, "$app/quality.php");
$u = Db::one('SELECT * FROM users WHERE username = "l.qa"');
ok($u && $u['role'] === 'qa' && $u['provisioned_by'] === 'sso' && str_ends_with($loc, 'quality.php') && $code === 200, 'new QA staff member provisioned from the role claim and lands on the Exception Center');
$f = (int) Db::val('SELECT id FROM users WHERE username = "f.faisal"');
[, $next] = sso($fa = jar(), ['sub' => 'faisal-1', 'preferred_username' => 'f.faisal@yu.edu.sa', 'roles' => ['SAQF.Faculty']]);
ok($next === 'index.php' && Db::val('SELECT role FROM users WHERE id = ?', [$f]) === 'faculty', 'existing faculty member keeps the faculty role');

section('5. Password rules once SSO is on');
$p = jar();
[, $html] = http($p, "$app/login.php");
[$code, $right] = http($p, "$app/login.php", ['_csrf' => csrf_of($html), 'username' => 'f.sara', 'password' => Story::PASSWORD]);
ok($code === 200 && str_contains($right, 'Unless you are a SAQF administrator') && !Db::val('SELECT 1 FROM login_attempts WHERE username = "f.sara" AND success = 1'), 'faculty can no longer sign in with a password');
[, $html] = http($p, "$app/login.php");
[, $wrong] = http($p, "$app/login.php", ['_csrf' => csrf_of($html), 'username' => 'f.sara', 'password' => 'not-her-password']);
[, $html] = http($p, "$app/login.php");
[, $nobody] = http($p, "$app/login.php", ['_csrf' => csrf_of($html), 'username' => 'nobody.here', 'password' => 'whatever']);
$alert = static fn(string $h): string => preg_match('/alert-error[^>]*>(.*?)<\/div>/s', $h, $m) ? $m[1] : '';
ok($alert($right) !== '' && $alert($right) === $alert($wrong) && $alert($wrong) === $alert($nobody), 'the answer is identical for a right password, a wrong one and an unknown user (no account or password probing)');
$adm = jar();
[, $html] = http($adm, "$app/login.php");
[$code, , $loc] = http($adm, "$app/login.php", ['_csrf' => csrf_of($html), 'username' => 'it.admin', 'password' => Story::PASSWORD]);
ok($code === 302 && str_ends_with($loc, 'index.php'), 'administrators keep break-glass password access');
[$code, , $loc] = http(jar(), "$app/forgot.php");
ok($code === 302 && str_ends_with($loc, 'login.php'), 'password reset page is off while e-mail is not configured');

section('6. Administration');
[, $html] = http($adm, "$app/admin.php?tab=users");
http($adm, "$app/admin.php?tab=users", ['_csrf' => csrf_of($html), 'op' => 'create_user', 'username' => 'm.new', 'full_name' => 'Dr. Mona New', 'email' => 'm.new@yu.edu.sa', 'role' => 'hod', 'department' => 'CED', 'reason' => 'HR-1234']);
[, $html] = http($adm, "$app/admin.php?tab=users");
$mona = Db::one('SELECT * FROM users WHERE username = "m.new"');
ok($mona && $mona['role'] === 'hod' && $mona['auth_source'] === 'sso' && str_contains($html, 'Dr. Mona New') && str_contains($html, 'signs in with their university account'), 'administrator adds a Head of Department; SSO account, no password issued');
[$code, $csv] = http($adm, "$app/admin.php?tab=users&template=csv");
ok($code === 200 && str_starts_with($csv, 'username,full_name,email,role'), 'CSV import template downloads');
[, $html] = http($adm, "$app/admin.php?tab=integrations");
http($adm, "$app/admin.php?tab=integrations", ['_csrf' => csrf_of($html), 'op' => 'check']);
[, $html] = http($adm, "$app/admin.php?tab=integrations");
ok(str_contains($html, 'SIS: OK') && str_contains($html, 'LMS: OK'), 'connection test runs from the Integrations tab');
http($adm, "$app/admin.php?tab=integrations", ['_csrf' => csrf_of($html), 'op' => 'term_add', 'code' => '2027-1', 'name' => 'Fall 2027', 'academic_year' => '2027-2028', 'sequence' => '5',
    'starts_on' => '2027-08-22', 'ends_on' => '2027-12-19', 'grades_due_on' => '2027-12-29', 'reason' => 'calendar test']);
ok(Db::val('SELECT status FROM terms WHERE code = "2027-1"') === 'upcoming', 'administrator adds a term to the academic calendar');
[, $html] = http($adm, "$app/admin.php?tab=integrations");
$spring = (int) Db::val('SELECT id FROM terms WHERE code = "2026-2"');
http($adm, "$app/admin.php?tab=integrations", ['_csrf' => csrf_of($html), 'op' => 'term_activate', 'term' => (string) $spring, 'reason' => 'Registrar asked to open Spring early']);
ok(Db::val('SELECT status FROM terms WHERE id = ?', [$spring]) === 'active' && Db::val('SELECT status FROM terms WHERE code = "2026-1"') === 'closed'
    && (bool) Db::val('SELECT 1 FROM audit_log WHERE action = "term.started_manually" AND reason LIKE "Registrar asked%"'), 'administrator starts a term by hand with an audited reason (previous term frozen)');

section('7. Sign-out ends the university session too');
[, $html] = http($omar, "$app/faculty.php");
[$code, , $loc] = http($omar, "$app/logout.php", ['_csrf' => csrf_of($html)]);
ok($code === 302 && str_starts_with($loc, "$idp/logout?") && str_contains(urldecode($loc), "post_logout_redirect_uri=$app/login.php"), 'sign-out redirects to the identity provider\'s logout');
[$code, , $loc] = http($omar, "$app/faculty.php");
ok($code === 302 && str_contains($loc, 'login.php'), 'the SAQF session is gone');

finish();
