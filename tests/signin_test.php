<?php
declare(strict_types=1);

/**
 * Tests for the sign-in protections and comfort features: the robot check (proof of work, single use,
 * hidden trap, harder after failures), two-step verification for everyone (e-mailed codes, the
 * authenticator app for administrators, resend limits, five wrong codes), trusted browsers, the
 * last-sign-in notice, "This wasn't me", the security checkup, the session timer, help and shortcuts,
 * and the Arabic wording of all of it.
 *   php bin/install.php --demo --fresh && php tests/signin_test.php
 * Starts its own SAQF web server on a free port.
 */

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/support.php';

use Saqf\Core\Db;
use Saqf\Core\Policy;
use Saqf\Demo\Story;
use Saqf\Security\BotGuard;
use Saqf\Security\Mfa;
use Saqf\Security\SecurityCenter;
use Saqf\Security\TrustedDevices;

$app = serve(dirname(__DIR__) . '/public');
const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';

function http(string $jar, string $url, ?array $post = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => BROWSER, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers]);
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
    return preg_match('/name="(?:_csrf|csrf)" (?:value|content)="([^"]+)"/', $html, $m) ? $m[1] : '';
}

function last_attempt(string $username): array
{
    return Db::one('SELECT * FROM login_attempts WHERE username = ? ORDER BY id DESC LIMIT 1', [$username]) ?? [];
}

/** Password step through the real form. @return array{0:int,1:string,2:string} code, body, location */
function password_step(string $app, string $j, string $user, array $extra = [], bool $solve = true): array
{
    [, $html] = http($j, "$app/login.php");
    [$code, $body, $loc] = http($j, "$app/login.php", array_merge(['_csrf' => csrf_of($html), 'username' => $user, 'password' => Story::PASSWORD], $solve ? bot_fields($html) : [], $extra));
    return [$code, $body, $loc];
}

section('1. The robot check');
$c = BotGuard::challenge('login');
[$f, $exp, $bits, $salt, $sig] = explode('.', $c);
ok($f === 'login' && (int) $exp > time() && (int) $bits === BotGuard::BASE_BITS && strlen($salt) === 24 && strlen($sig) === 32, "a puzzle is signed, expires and asks for $bits zero bits");
$solve = static function (string $challenge): string {
    [, , $b, $s] = explode('.', $challenge);
    for ($n = 0; !BotGuard::solves($s, (string) $n, (int) $b); $n++) {
    }
    return (string) $n;
};
$t = microtime(true);
$nonce = $solve($c);
ok(BotGuard::verify('login', ['bot_challenge' => $c, 'bot_nonce' => $nonce]) === null, 'a solved puzzle passes (found in ' . round((microtime(true) - $t) * 1000) . ' ms, a browser takes about as long)');
ok(BotGuard::verify('login', ['bot_challenge' => $c, 'bot_nonce' => $nonce]) === 'bot_reused', 'the same solved puzzle cannot be used twice');
$c2 = BotGuard::challenge('login');
ok(BotGuard::verify('login', ['bot_challenge' => $c2, 'bot_nonce' => '0']) === 'bot_unsolved' || BotGuard::solves(explode('.', $c2)[3], '0', BotGuard::BASE_BITS), 'a wrong answer is refused');
ok(BotGuard::verify('login', []) === 'bot_missing', 'a form without the check is refused');
$forged = str_replace('.' . BotGuard::BASE_BITS . '.', '.1.', $c2);
ok(BotGuard::verify('login', ['bot_challenge' => $forged, 'bot_nonce' => '1']) === 'bot_invalid', 'an easier puzzle made up by a script is refused (signature)');
ok(BotGuard::verify('forgot', ['bot_challenge' => $c2, 'bot_nonce' => $solve($c2)]) === 'bot_invalid', 'a puzzle for one form does not work on another');
ok(BotGuard::verify('login', ['bot_challenge' => $c2, 'bot_nonce' => $solve($c2), BotGuard::TRAP => 'http://spam.example']) === 'bot_trap', 'the hidden trap field catches form-filling bots');
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
foreach ([1, 2, 3] as $i) {
    Db::insert('login_attempts', ['username' => 'nobody', 'ip' => '203.0.113.9', 'success' => 0, 'reason' => 'bad_password', 'created_at' => \Saqf\Core\Clock::stamp()]);
}
ok(BotGuard::bits() === BotGuard::HARD_BITS, 'after 3 failures from a network its puzzles become 16 times harder');
unset($_SERVER['REMOTE_ADDR']);
putenv('SAQF_BOT_CHECK=off');
ok(BotGuard::verify('login', []) === null && BotGuard::field('login') === '', 'IT can switch it off (SAQF_BOT_CHECK=off), and the Security center then says so');
putenv('SAQF_BOT_CHECK');
$checks = array_column(SecurityCenter::checks(), null, 'label');
ok(($checks['Robot check on public forms']['ok'] ?? null) === true && ($checks['Two-step verification policy']['ok'] ?? null) === true, 'the Security center shows the robot check and two-step verification for everyone as in place');

section('2. Signing in: robot check first');
$j = jar();
[, $html] = http($j, "$app/login.php");
ok(str_contains($html, 'data-botcheck') && str_contains($html, 'name="' . BotGuard::TRAP . '"') && str_contains($html, "I'm not a robot"), 'the sign-in form carries the robot check and the hidden trap');
ok(str_contains($html, 'Two-step sign-in') && str_contains($html, 'data-fill-user="f.omar"') && str_contains($html, 'skip sign-in'), 'the page explains the protections, and demo accounts fill in the real form (the shortcut is labelled as one)');
[$code, $body] = password_step($app, $j, 'f.omar', [], false);
ok($code === 200 && str_contains($body, 'not a robot') && last_attempt('f.omar')['reason'] === 'bot_missing', 'a correct password without the robot check is refused, and the attempt is recorded');
[$code] = http($j, "$app/faculty.php");
ok($code === 302, '…and nothing is signed in');
[$code, $body] = password_step($app, $j, 'f.omar', [BotGuard::TRAP => 'x']);
ok($code === 200 && last_attempt('f.omar')['reason'] === 'bot_trap', 'a filled-in trap field is refused');

section('3. Two-step verification by e-mailed code');
$j = jar();
[$code, , $loc] = password_step($app, $j, 'f.omar');
ok($code === 302 && str_ends_with($loc, 'mfa.php'), 'faculty now need a second step after the password');
[$code] = http($j, "$app/faculty.php");
ok($code === 302, 'nothing is reachable between the password and the code');
[, $mfa] = http($j, "$app/mfa.php");
$sent = demo_mail_code($mfa);
ok(str_contains($mfa, 'We sent a 6-digit code to') && str_contains($mfa, 'f•••') && $sent !== null, 'the code screen names the masked address; in demo mode the demo mailbox shows the e-mail');
ok(str_contains($mfa, 'name="trust"') && str_contains($mfa, 'Send a new code') && !str_contains($mfa, 'Use my authenticator app instead'), 'faculty may trust the browser and ask for a new code');
[$code, $body] = http($j, "$app/mfa.php", ['_csrf' => csrf_of($mfa), 'op' => 'resend']);
ok($code === 200 && str_contains($body, 'Wait') && demo_mail_code($body) === $sent, 'a new code cannot be requested within 30 seconds (the first one still works)');
[$code, $body] = http($j, "$app/mfa.php", ['_csrf' => csrf_of($mfa), 'code' => $sent === '000000' ? '111111' : '000000']);
ok($code === 200 && str_contains($body, 'not right') && last_attempt('f.omar')['reason'] === 'bad_mfa_code', 'a wrong code is refused and recorded');
[$code, , $loc] = http($j, "$app/mfa.php", ['_csrf' => csrf_of($mfa), 'code' => substr($sent, 0, 3) . ' ' . substr($sent, 3), 'trust' => '1']);
ok($code === 302 && str_ends_with($loc, 'index.php'), 'the e-mailed code completes the sign-in (spaces are fine)');
$omar = Db::one('SELECT * FROM users WHERE username = "f.omar"');
ok(last_attempt('f.omar')['reason'] === 'mfa_email' && str_contains((string) Db::val('SELECT summary FROM audit_log WHERE action = "auth.login" ORDER BY id DESC LIMIT 1'), 'e-mailed code'), 'the sign-in is recorded with how it was confirmed');
ok(count(TrustedDevices::forUser((int) $omar['id'])) === 1 && str_contains((string) file_get_contents($j), TrustedDevices::COOKIE), 'the browser is trusted: SAQF keeps only a hash, the browser an HttpOnly cookie');
ok((string) Db::val('SELECT token_hash FROM trusted_devices WHERE user_id = ?', [$omar['id']]) !== '' && !str_contains((string) Db::val('SELECT token_hash FROM trusted_devices WHERE user_id = ?', [$omar['id']]), (string) (preg_match('/saqf_trusted\s+(\w+)/', (string) file_get_contents($j), $m) ? $m[1] : 'none')), 'the stored value is not the cookie value');
[, $page] = http($j, "$app/faculty.php");
http($j, "$app/logout.php", ['_csrf' => csrf_of($page)]);
[$code, , $loc] = password_step($app, $j, 'f.omar');
ok($code === 302 && str_ends_with($loc, 'index.php') && last_attempt('f.omar')['reason'] === 'trusted_browser', 'next time, on the trusted browser, the password is enough');
[, $page] = http($j, "$app/faculty.php");
ok(str_contains($page, 'Welcome back. Your last sign-in was on') && str_contains($page, 'Chrome on Windows'), 'the first page says when and from where the account was last used');
$other = jar();
[$code, , $loc] = password_step($app, $other, 'f.omar');
ok($code === 302 && str_ends_with($loc, 'mfa.php'), 'another browser still needs the code');
for ($i = 0; $i < 5; $i++) {
    [, $mfa] = http($other, "$app/mfa.php");
    [$code, , $loc] = http($other, "$app/mfa.php", ['_csrf' => csrf_of($mfa), 'code' => '000001']);
}
ok($code === 302 && str_ends_with($loc, 'login.php'), 'after 5 wrong codes the sign-in starts again from the password');

section('4. Administrators use an authenticator app');
$a = jar();
[$code, , $loc] = password_step($app, $a, 'it.admin');
[, $mfa] = http($a, "$app/mfa.php");
ok(str_ends_with($loc, 'mfa.php') && str_contains($mfa, 'authenticator app') && !str_contains($mfa, 'E-mail me a code instead') && !str_contains($mfa, 'name="trust"'), 'the administrator is asked for the app code: no e-mailed codes and no trusted browsers');
ok(!Mfa::emailAllowed(['role' => 'admin', 'email' => 'it@yu.edu.sa']) && !TrustedDevices::allowed(['role' => 'admin']), 'the same rules hold in the code, not only on the page');

section('5. Account & security');
[, $acc] = http($j, "$app/account.php");
ok(str_contains($acc, 'Security checkup') && str_contains($acc, 'look good') && str_contains($acc, 'On · e-mailed code'), 'the security checkup and the two-step method are shown');
ok(str_contains($acc, 'Trusted browsers') && str_contains($acc, 'Chrome on Windows') && str_contains($acc, 'Signed in · trusted browser') && str_contains($acc, 'Password on a trusted browser'), 'trusted browsers and sign-in activity are listed in plain words');
[$code] = http($j, "$app/account.php", ['_csrf' => csrf_of($acc), 'op' => 'not_me']);
ok($code === 302 && !TrustedDevices::forUser((int) $omar['id']) && (int) Db::val('SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND ended_at IS NULL', [$omar['id']]) === 1, '"This wasn\'t me" signs out every other session and forgets every trusted browser');
ok((bool) Db::val('SELECT 1 FROM alerts WHERE kind = ? AND resolved_at IS NULL', ['security.reported.' . $omar['id']]) && (bool) Db::val('SELECT 1 FROM audit_log WHERE action = "security.reported_suspicious"'), '…and IT security is alerted');
TrustedDevices::trust($omar);
Db::exec('UPDATE users SET password_hash = ? WHERE id = ?', [\Saqf\Security\Auth::hash(Story::PASSWORD), $omar['id']]);
$_SESSION = ['uid' => $omar['id']];
\Saqf\Security\Auth::changePassword($omar, Story::PASSWORD, 'Violet-harbor-lantern-73');
ok(!TrustedDevices::forUser((int) $omar['id']), 'a password change forgets every trusted browser');
Db::exec('UPDATE users SET password_hash = ? WHERE id = ?', [\Saqf\Security\Auth::hash(Story::PASSWORD), $omar['id']]);

section('6. Staying signed in, help and shortcuts');
$s = jar();
[$code, , $loc] = password_step($app, $s, 'f.sara');
[, $mfa] = http($s, "$app/mfa.php");
http($s, "$app/mfa.php", ['_csrf' => csrf_of($mfa), 'code' => (string) demo_mail_code($mfa)]);
[, $page] = http($s, "$app/faculty.php");
ok(str_contains($page, 'name="saqf-idle" content="' . Policy::get('session.idle_minutes') * 60 . '"') && str_contains($page, 'id="sessionWarn"') && str_contains($page, 'Stay signed in'), 'every page knows the idle limit and carries the "you will be signed out soon" warning');
[$code, $body] = http($s, "$app/ping.php");
$peek = json_decode($body, true);
ok($code === 200 && $peek['signedIn'] === true && $peek['remaining'] > 0 && $peek['remaining'] <= Policy::get('session.idle_minutes') * 60, 'the page can ask how long is left without counting as activity');
[$code] = http($s, "$app/ping.php", []);
ok($code === 401, '"Stay signed in" without the page\'s token is refused');
[$code, $body] = http($s, "$app/ping.php", [], ['X-CSRF-Token: ' . csrf_of($page)]);
ok($code === 200 && json_decode($body, true)['remaining'] === Policy::get('session.idle_minutes') * 60, '"Stay signed in" resets the timer');
[$code, $body] = http(jar(), "$app/ping.php");
ok(json_decode($body, true)['signedIn'] === false, 'a signed-out browser is told so');
[$code, $help] = http($s, "$app/help.php");
ok($code === 200 && str_contains($help, 'Common questions') && str_contains($help, 'How do I add an exam paper as evidence?') && str_contains($help, 'Keeping your account safe'), 'the help page answers the questions faculty ask and explains how to stay safe');
ok(str_contains($page, 'id="kbdHelp"') && str_contains($page, 'class="helpbtn"') && str_contains($page, 'help.php'), 'every page has the help button and the keyboard-shortcut list');
[, $js] = http($s, "$app/assets/app.js");
ok(str_contains($js, 'sha256First') && str_contains($js, 'data-strength') && str_contains($js, 'CapsLock') && str_contains($js, 'beforeunload'), 'the page script solves the robot check, rates new passwords, warns about Caps Lock and unsaved changes');

section('7. The policy decides');
Policy::set('auth.mfa_required', 'admins', 'test');
$p = jar();
[$code, , $loc] = password_step($app, $p, 'f.noura');
ok($code === 302 && str_ends_with($loc, 'index.php'), 'with two-step verification required for administrators only, faculty sign in with the password');
Policy::set('auth.mfa_required', 'all', 'test');
Policy::set('auth.trusted_device_days', '0', 'test');
$p = jar();
password_step($app, $p, 'f.noura');
[, $mfa] = http($p, "$app/mfa.php");
ok(!str_contains($mfa, 'name="trust"'), 'with trusted browsers switched off, the option disappears');
Policy::set('auth.trusted_device_days', '30', 'test');

section('8. Arabic');
$ar = jar();
http($ar, "$app/login.php?lang=ar");
[, $html] = http($ar, "$app/login.php");
ok(str_contains($html, 'لست روبوتاً') && str_contains($html, 'تسجيل دخول بخطوتين') && !str_contains($html, "I'm not a robot"), 'the robot check and the sign-in protections read in Arabic');
[$code, , $loc] = http($ar, "$app/login.php", ['_csrf' => csrf_of($html), 'username' => 'f.sara', 'password' => Story::PASSWORD] + bot_fields($html));
[, $mfa] = http($ar, "$app/mfa.php");
ok(str_contains($mfa, 'أرسلنا رمزاً من 6 أرقام إلى') && str_contains($mfa, 'صندوق بريد العرض التجريبي'), 'the code screen and the demo mailbox read in Arabic');
http($ar, "$app/mfa.php", ['_csrf' => csrf_of($mfa), 'code' => (string) demo_mail_code($mfa)]);
[, $acc] = http($ar, "$app/account.php");
ok(str_contains($acc, 'فحص الأمان') && str_contains($acc, 'لم أكن أنا') && str_contains($acc, 'سيُسجَّل خروجك قريباً'), 'the security checkup, "This wasn\'t me" and the session warning read in Arabic');

finish();
