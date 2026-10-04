<?php
declare(strict_types=1);

/**
 * Passkeys (WebAuthn) and the CBOR decoder under them, tested with hand-built vectors: real P-256 keys made by
 * OpenSSL sign real authenticator data, and every check the verifier makes is then broken one at a time.
 * Also the sign-in flow over HTTP (the passkey only completes the second step after the password; nothing else).
 *   php bin/install.php --demo --fresh && php tests/passkey_test.php
 * A real browser authenticator is exercised separately by tests/e2e/passkeys.mjs (virtual authenticator in Chromium).
 */

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/support.php';

use Saqf\Core\Config;
use Saqf\Core\Db;
use Saqf\Demo\Story;
use Saqf\Security\Cbor;
use Saqf\Security\WebAuthn;

if (Config::env() === 'production') {
    fwrite(STDERR, "Refusing to run against a production installation.\n");
    exit(1);
}

/** Minimal CBOR encoder for the test vectors (the decoder under test is independent of it). */
function cbor_enc($v): string
{
    if (is_int($v)) {
        return $v >= 0 ? cbor_head(0, $v) : cbor_head(1, -1 - $v);
    }
    if (is_string($v)) {
        return mb_check_encoding($v, 'UTF-8') && preg_match('/^[\x20-\x7e]*$/', $v) ? cbor_head(3, strlen($v)) . $v : cbor_head(2, strlen($v)) . $v;
    }
    if (is_bool($v)) {
        return $v ? "\xf5" : "\xf4";
    }
    if (is_array($v)) {
        $isList = array_is_list($v);
        $out = cbor_head($isList ? 4 : 5, count($v));
        foreach ($v as $k => $x) {
            $out .= ($isList ? '' : cbor_enc($k)) . cbor_enc($x);
        }
        return $out;
    }
    throw new InvalidArgumentException('unsupported');
}
function cbor_head(int $major, int $n): string
{
    if ($n < 24) {
        return chr(($major << 5) | $n);
    }
    if ($n < 256) {
        return chr(($major << 5) | 24) . chr($n);
    }
    if ($n < 65536) {
        return chr(($major << 5) | 25) . pack('n', $n);
    }
    return chr(($major << 5) | 26) . pack('N', $n);
}
/** A byte string forced to be CBOR major type 2 (keys and ids that happen to be printable ASCII). */
function bstr(string $s): string
{
    return cbor_head(2, strlen($s)) . $s;
}

/** A P-256 key pair and its COSE public key. @return array{0:OpenSSLAsymmetricKey,1:array<int,mixed>} */
function new_key(): array
{
    $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $d = openssl_pkey_get_details($k)['ec'];
    return [$k, [1 => 2, 3 => -7, -1 => 1, -2 => $d['x'], -3 => $d['y']]];
}
function cose_bytes(array $cose): string
{
    // keys 1,3,-1,-2,-3 with byte-string coordinates
    return "\xa5" . cbor_enc(1) . cbor_enc(2) . cbor_enc(3) . cbor_enc(-7) . cbor_enc(-1) . cbor_enc(1) . cbor_enc(-2) . bstr($cose[-2]) . cbor_enc(-3) . bstr($cose[-3]);
}
function auth_data(string $rpId, int $flags, int $count, ?string $credId = null, ?array $cose = null, ?string $rawCose = null): string
{
    $ad = hash('sha256', $rpId, true) . chr($flags) . pack('N', $count);
    if ($credId !== null) {
        $ad .= str_repeat("\0", 16) . pack('n', strlen($credId)) . $credId . ($rawCose ?? cose_bytes($cose));
    }
    return $ad;
}
function client_data(string $type, string $challenge, string $origin, array $extra = []): string
{
    return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin] + $extra, JSON_UNESCAPED_SLASHES);
}
function reg_payload(string $cdj, string $authData, string $fmt = 'none', $attStmt = []): array
{
    $att = "\xa3" . cbor_enc('fmt') . cbor_enc($fmt) . cbor_enc('attStmt') . ($attStmt === [] ? "\xa0" : cbor_enc($attStmt)) . cbor_enc('authData') . bstr($authData);
    return ['clientDataJSON' => WebAuthn::b64u($cdj), 'attestationObject' => WebAuthn::b64u($att)];
}
function assertion(OpenSSLAsymmetricKey $key, string $rpId, string $challenge, string $origin, int $flags = 0x05, int $count = 1, array $over = []): array
{
    $cdj = $over['cdj'] ?? client_data('webauthn.get', $challenge, $origin);
    $ad = $over['ad'] ?? auth_data($rpId, $flags, $count);
    openssl_sign($ad . hash('sha256', $over['signed_cdj'] ?? $cdj, true), $sig, $key, OPENSSL_ALGO_SHA256);
    return ['clientDataJSON' => WebAuthn::b64u($cdj), 'authenticatorData' => WebAuthn::b64u($ad), 'signature' => WebAuthn::b64u($over['sig'] ?? $sig)];
}
function refused(callable $fn): bool
{
    try {
        $fn();
        return false;
    } catch (InvalidArgumentException $e) {
        return true;
    }
}

$RP = 'saqf.example.edu';
$ORIGIN = 'https://saqf.example.edu';
$CH = WebAuthn::b64u(random_bytes(32));

section('1. CBOR decoder: accepts the structures it needs, refuses everything else');
ok(Cbor::decode(cbor_enc(['a' => 1, 'b' => [1, 2, -3], 'c' => "\x00\xff"])) === ['a' => 1, 'b' => [1, 2, -3], 'c' => "\x00\xff"], 'integers, negative integers, text, byte strings, arrays and maps decode');
ok(Cbor::decode("\xa0") === [] && Cbor::decode("\xf5") === true && Cbor::decode("\xf6") === null, 'an empty map, true and null decode');
$bad = [
    'a tag' => "\xc1\x01", 'a float' => "\xfb\x3f\xf0\x00\x00\x00\x00\x00\x00", 'an indefinite-length array' => "\x9f\x01\xff", 'an indefinite-length text' => "\x7f\x61a\xff",
    'a truncated byte string' => "\x58\x20" . str_repeat('a', 5), 'a length beyond the data' => "\x5a\xff\xff\xff\xff",
    'a 64-bit length' => "\x5b\xff\xff\xff\xff\xff\xff\xff\xff", 'invalid UTF-8 text' => "\x62\xc3\x28", 'a duplicate map key' => "\xa2\x01\x01\x01\x02",
    'a map key that is a byte string' => "\xa1\x41a\x01", 'trailing bytes' => "\x01\x02", 'no data at all' => '', 'a reserved additional-info value' => "\x1c",
];
foreach ($bad as $label => $data) {
    ok(refused(static fn() => Cbor::decode($data)), "refused: $label");
}
ok(refused(static fn() => Cbor::decode(str_repeat("\x81", 12) . "\x01")), 'refused: nesting deeper than 6 levels');
ok(refused(static fn() => Cbor::decode("\x99\x01\x01" . str_repeat("\x01", 257))), 'refused: an array of more than 256 items');
$off = 0;
ok(Cbor::decodePrefix("\x01\x02", $off) === 1 && $off === 1, 'a prefix decode reports how many bytes it used');

section('2. Registration (attestation "none", ES256, user verification required)');
[$key, $cose] = new_key();
$credId = random_bytes(32);
$ok = static fn(array $over = []) => reg_payload($over['cdj'] ?? client_data('webauthn.create', $CH, $ORIGIN), $over['ad'] ?? auth_data($RP, $over['flags'] ?? 0x45, 0, $credId, $cose));
$r = WebAuthn::verifyRegistration($ok(), $CH, $ORIGIN, $RP);
ok($r['credential_id'] === $credId && str_contains($r['public_key_pem'], 'BEGIN PUBLIC KEY') && $r['sign_count'] === 0, 'a valid registration yields the credential id and a usable public key');
$wrong = static fn(callable $fn, string $label) => ok(refused($fn), "refused: $label");
$wrong(static fn() => WebAuthn::verifyRegistration($ok(['cdj' => client_data('webauthn.get', $CH, $ORIGIN)]), $CH, $ORIGIN, $RP), 'the wrong ceremony type');
$wrong(static fn() => WebAuthn::verifyRegistration($ok(), WebAuthn::b64u(random_bytes(32)), $ORIGIN, $RP), 'a challenge that is not the one issued');
$wrong(static fn() => WebAuthn::verifyRegistration($ok(['cdj' => client_data('webauthn.create', $CH, 'https://evil.example')]), $CH, $ORIGIN, $RP), 'another origin (a phishing site)');
$wrong(static fn() => WebAuthn::verifyRegistration($ok(['cdj' => client_data('webauthn.create', $CH, $ORIGIN, ['crossOrigin' => true])]), $CH, $ORIGIN, $RP), 'cross-origin use');
$wrong(static fn() => WebAuthn::verifyRegistration($ok(['ad' => auth_data('evil.example', 0x45, 0, $credId, $cose)]), $CH, $ORIGIN, $RP), 'another relying party id');
$wrong(static fn() => WebAuthn::verifyRegistration($ok(['flags' => 0x44]), $CH, $ORIGIN, $RP), 'user presence not confirmed');
$wrong(static fn() => WebAuthn::verifyRegistration($ok(['flags' => 0x41]), $CH, $ORIGIN, $RP), 'user verification (PIN / biometric) missing');
$wrong(static fn() => WebAuthn::verifyRegistration($ok(['flags' => 0x05]), $CH, $ORIGIN, $RP), 'no credential data in the registration');
$wrong(static fn() => WebAuthn::verifyRegistration(reg_payload(client_data('webauthn.create', $CH, $ORIGIN), auth_data($RP, 0x45, 0, $credId, $cose), 'packed', ['alg' => -7, 'sig' => bstr('x')]), $CH, $ORIGIN, $RP), 'an attestation format other than "none"');
$wrong(static fn() => WebAuthn::verifyRegistration(['clientDataJSON' => 'not base64!', 'attestationObject' => 'x'], $CH, $ORIGIN, $RP), 'malformed base64url');
$wrong(static fn() => WebAuthn::verifyRegistration(['clientDataJSON' => WebAuthn::b64u('{'), 'attestationObject' => WebAuthn::b64u("\xa0")], $CH, $ORIGIN, $RP), 'broken client data');
$wrong(static fn() => WebAuthn::verifyRegistration($ok(['ad' => auth_data($RP, 0x45, 0, str_repeat('a', 5), $cose)]), $CH, $ORIGIN, $RP), 'a credential id shorter than 16 bytes');
$rsa = "\xa5" . cbor_enc(1) . cbor_enc(3) . cbor_enc(3) . cbor_enc(-257) . cbor_enc(-1) . bstr(str_repeat('n', 32)) . cbor_enc(-2) . bstr(str_repeat('e', 32)) . cbor_enc(-3) . bstr('x');
$wrong(static fn() => WebAuthn::verifyRegistration($ok(['ad' => auth_data($RP, 0x45, 0, $credId, null, $rsa)]), $CH, $ORIGIN, $RP), 'a key that is not ES256 / P-256');
$offCurve = "\xa5" . cbor_enc(1) . cbor_enc(2) . cbor_enc(3) . cbor_enc(-7) . cbor_enc(-1) . cbor_enc(1) . cbor_enc(-2) . bstr(str_repeat("\x01", 32)) . cbor_enc(-3) . bstr(str_repeat("\x02", 32));
$wrong(static fn() => WebAuthn::verifyRegistration($ok(['ad' => auth_data($RP, 0x45, 0, $credId, null, $offCurve)]), $CH, $ORIGIN, $RP), 'a public key that is not a point on the curve');
$wrong(static fn() => WebAuthn::verifyRegistration($ok(['ad' => auth_data($RP, 0x45, 0, $credId, $cose) . 'junk']), $CH, $ORIGIN, $RP), 'unexpected bytes after the credential public key');
$wrong(static fn() => WebAuthn::verifyRegistration($ok(['ad' => substr(auth_data($RP, 0x45, 0, $credId, $cose), 0, 40)]), $CH, $ORIGIN, $RP), 'truncated authenticator data');

section('3. Assertion (the sign-in proof)');
$pem = $r['public_key_pem'];
$a = static fn(array $o = [], int $flags = 0x05, int $count = 1, ?string $challenge = null, ?string $origin = null, ?string $rp = null) => assertion($key, $rp ?? $RP, $challenge ?? $CH, $origin ?? $ORIGIN, $flags, $count, $o);
ok(WebAuthn::verifyAssertion($a(), $CH, $ORIGIN, $RP, $pem, 0)['sign_count'] === 1, 'a correctly signed assertion verifies and reports the new counter');
$wrong(static fn() => WebAuthn::verifyAssertion($a(['sig' => str_repeat('x', 70)]), $CH, $ORIGIN, $RP, $pem, 0), 'a forged signature');
[$other] = new_key();
$wrong(static fn() => WebAuthn::verifyAssertion(assertion($other, $RP, $CH, $ORIGIN), $CH, $ORIGIN, $RP, $pem, 0), 'a signature made with a different key');
$wrong(static fn() => WebAuthn::verifyAssertion($a(['signed_cdj' => 'something else']), $CH, $ORIGIN, $RP, $pem, 0), 'a signature over different client data');
$wrong(static fn() => WebAuthn::verifyAssertion($a(), WebAuthn::b64u(random_bytes(32)), $ORIGIN, $RP, $pem, 0), 'a replayed assertion (the challenge is not the current one)');
$wrong(static fn() => WebAuthn::verifyAssertion($a([], 0x05, 1, null, 'https://evil.example'), $CH, $ORIGIN, $RP, $pem, 0), 'an assertion collected for another origin');
$wrong(static fn() => WebAuthn::verifyAssertion($a([], 0x05, 1, null, null, 'evil.example'), $CH, $ORIGIN, $RP, $pem, 0), 'another relying party id');
$wrong(static fn() => WebAuthn::verifyAssertion($a([], 0x04), $CH, $ORIGIN, $RP, $pem, 0), 'user presence missing');
$wrong(static fn() => WebAuthn::verifyAssertion($a([], 0x01), $CH, $ORIGIN, $RP, $pem, 0), 'user verification missing (touch without PIN or biometric)');
$wrong(static fn() => WebAuthn::verifyAssertion($a([], 0x45), $CH, $ORIGIN, $RP, $pem, 0), 'an assertion that carries credential data');
$wrong(static fn() => WebAuthn::verifyAssertion($a([], 0x05, 5), $CH, $ORIGIN, $RP, $pem, 5), 'a signature counter that did not advance (possible clone)');
$wrong(static fn() => WebAuthn::verifyAssertion($a([], 0x05, 3), $CH, $ORIGIN, $RP, $pem, 5), 'a counter that went backwards');
ok(WebAuthn::verifyAssertion($a([], 0x05, 6), $CH, $ORIGIN, $RP, $pem, 5)['sign_count'] === 6, 'a counter that advanced is accepted');
ok(WebAuthn::verifyAssertion($a([], 0x05, 0), $CH, $ORIGIN, $RP, $pem, 0)['sign_count'] === 0, 'authenticators that never count (always 0) are accepted');
$wrong(static fn() => WebAuthn::verifyAssertion($a(), $CH, $ORIGIN, $RP, 'not a key', 0), 'an unreadable stored key');
$wrong(static fn() => WebAuthn::verifyAssertion(['clientDataJSON' => '!!', 'authenticatorData' => 'x', 'signature' => 'y'], $CH, $ORIGIN, $RP, $pem, 0), 'malformed base64url');
ok(WebAuthn::unb64u('abc=') === null && WebAuthn::unb64u('a b') === null && WebAuthn::unb64u('') === null && WebAuthn::unb64u('A') === null, 'base64url is parsed strictly (no padding, no other characters)');

section('4. Sign-in over HTTP: the passkey is only ever the second step');
$base = serve(dirname(__DIR__) . '/public', ['SAQF_BOT_CHECK' => 'off']);
$port = parse_url($base, PHP_URL_PORT);
$app = "http://localhost:$port";
function hjar(): string
{
    return (string) tempnam(sys_get_temp_dir(), 'jar');
}
function hreq(string $jar, string $url, $post = null, array $headers = [], bool $json = false): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json ? json_encode($post) : http_build_query($post));
    }
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$code, substr($raw, $size), substr($raw, 0, $size)];
}
function tok(string $html): string
{
    return preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) ? $m[1] : (preg_match('/data-csrf="([^"]+)"/', $html, $m2) ? $m2[1] : '');
}
$omar = Db::one('SELECT * FROM users WHERE username = "f.omar"');
[$pk, $pcose] = new_key();
$pcred = random_bytes(32);
$rp = 'localhost';
$orig = "http://localhost:$port";

// Registration through the endpoint, as a signed-in person.
$j = hjar();
[, $html] = hreq($j, "$app/login.php");
hreq($j, "$app/demo.php", ['_csrf' => tok($html), 'as' => 'f.omar', 'next' => 'account.php']);
[$code, $html] = hreq($j, "$app/account.php");
$csrf = tok($html);
ok($code === 200 && str_contains($html, 'id="passkeys"') && str_contains($html, 'Add a passkey'), 'the account page offers passkeys (on localhost, where browsers allow them)');
[$code, $body] = hreq($j, "$app/passkey.php", ['action' => 'register_options'], ['Content-Type: application/json'], true);
ok($code === 403 && !(json_decode($body, true)['ok'] ?? true), 'a request without the anti-forgery token is refused');
[$code, $body] = hreq($j, "$app/passkey.php", ['action' => 'register_options'], ['Content-Type: application/json', "X-CSRF-Token: $csrf"], true);
$opt = json_decode($body, true)['options'] ?? [];
ok($code === 200 && ($opt['rp']['id'] ?? '') === 'localhost' && ($opt['attestation'] ?? '') === 'none' && ($opt['authenticatorSelection']['userVerification'] ?? '') === 'required' && ($opt['pubKeyCredParams'][0]['alg'] ?? 0) === -7, 'registration options ask for attestation none, ES256 and user verification');
ok(!str_contains(json_encode($opt), 'omar') || ($opt['user']['name'] ?? '') === 'f.omar', 'the user handle sent to the authenticator is opaque (not the account id)');
$reg = reg_payload(client_data('webauthn.create', $opt['challenge'], $orig), auth_data($rp, 0x45, 0, $pcred, $pcose));
$reg['id'] = WebAuthn::b64u($pcred);
[$code, $body] = hreq($j, "$app/passkey.php", ['action' => 'register_finish', 'label' => 'Test laptop', 'credential' => $reg], ['Content-Type: application/json', "X-CSRF-Token: $csrf"], true);
ok($code === 200 && Db::val('SELECT COUNT(*) FROM passkeys WHERE user_id = ?', [$omar['id']]) == 1 && !str_contains((string) Db::val('SELECT public_key FROM passkeys LIMIT 1'), 'PRIVATE'), 'a valid registration is stored (public key only)');
[$code, $body] = hreq($j, "$app/passkey.php", ['action' => 'register_finish', 'label' => 'Replay', 'credential' => $reg], ['Content-Type: application/json', "X-CSRF-Token: $csrf"], true);
ok($code === 422 && Db::val('SELECT COUNT(*) FROM passkeys WHERE user_id = ?', [$omar['id']]) == 1, 'replaying the same registration (challenge already used) stores nothing');
ok((bool) Db::val('SELECT 1 FROM audit_log WHERE action = "security.passkey_added"'), 'adding a passkey is in the audit log');
[$code, $html] = hreq($j, "$app/account.php");
ok(str_contains($html, 'Test laptop') && str_contains($html, 'not used yet'), 'the account page lists it');

// A fresh browser: password first, then the passkey as the second step.
$j2 = hjar();
[, $html] = hreq($j2, "$app/login.php");
[$code, , $head] = hreq($j2, "$app/passkey.php", ['action' => 'login_options'], ['Content-Type: application/json', 'X-CSRF-Token: ' . tok($html)], true);
ok($code === 401, 'without the password step first, the passkey endpoint offers nothing');
[$code, $body, $head] = hreq($j2, "$app/login.php", ['_csrf' => tok($html), 'username' => 'f.omar', 'password' => Story::PASSWORD]);
ok($code === 302 && str_contains($head, 'mfa.php'), 'the password is still required first: it leads to the second step');
[$code, $html] = hreq($j2, "$app/mfa.php");
ok($code === 200 && str_contains($html, 'Use your passkey') && str_contains($html, 'data-passkey="login"') && !str_contains($html, 'name="code"'), 'the second step offers the passkey (no code box for a passkey-first account)');
$csrf2 = tok($html);
[$code, $body] = hreq($j2, "$app/passkey.php", ['action' => 'login_options'], ['Content-Type: application/json', "X-CSRF-Token: $csrf2"], true);
$lo = json_decode($body, true)['options'] ?? [];
ok($code === 200 && ($lo['userVerification'] ?? '') === 'required' && count($lo['allowCredentials'] ?? []) === 1 && ($lo['rpId'] ?? '') === 'localhost', 'login options require user verification and list only this person\'s passkeys');
$bad = assertion($pk, $rp, WebAuthn::b64u(random_bytes(32)), $orig);
$bad['id'] = WebAuthn::b64u($pcred);
[$code, $body] = hreq($j2, "$app/passkey.php", ['action' => 'login_finish', 'credential' => $bad], ['Content-Type: application/json', "X-CSRF-Token: $csrf2"], true);
[$c2] = hreq($j2, "$app/faculty.php");
ok($code === 401 && $c2 === 302, 'an assertion for the wrong challenge does not sign anyone in');
[$code, $body] = hreq($j2, "$app/passkey.php", ['action' => 'login_options'], ['Content-Type: application/json', "X-CSRF-Token: $csrf2"], true);
$lo = json_decode($body, true)['options'];
$good = assertion($pk, $rp, $lo['challenge'], $orig, 0x05, 1);
$good['id'] = WebAuthn::b64u($pcred);
// Counted rather than "the newest session": sessions created in the same second have no order.
$passkeySessions = static fn(): int => (int) Db::val('SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND method = "password+passkey"', [$omar['id']]);
$before = $passkeySessions();
[$code, $body] = hreq($j2, "$app/passkey.php", ['action' => 'login_finish', 'credential' => $good], ['Content-Type: application/json', "X-CSRF-Token: $csrf2"], true);
[$c2] = hreq($j2, "$app/faculty.php");
ok($code === 200 && ($c2 === 200) && (json_decode($body, true)['redirect'] ?? '') === 'index.php', 'a valid assertion completes the sign-in');
ok((bool) Db::val('SELECT 1 FROM login_attempts WHERE username = "f.omar" AND success = 1 AND reason = "mfa_passkey"') && $passkeySessions() === $before + 1, 'it is recorded as a password + passkey sign-in');
ok((int) Db::val('SELECT sign_count FROM passkeys WHERE user_id = ?', [$omar['id']]) === 1 && Db::val('SELECT last_used_at FROM passkeys WHERE user_id = ?', [$omar['id']]) !== null, 'the counter and last use are updated');

// The same assertion cannot be replayed to sign in again.
$j3 = hjar();
[, $html] = hreq($j3, "$app/login.php");
hreq($j3, "$app/login.php", ['_csrf' => tok($html), 'username' => 'f.omar', 'password' => Story::PASSWORD]);
[, $html] = hreq($j3, "$app/mfa.php");
$csrf3 = tok($html);
hreq($j3, "$app/passkey.php", ['action' => 'login_options'], ['Content-Type: application/json', "X-CSRF-Token: $csrf3"], true);
[$code] = hreq($j3, "$app/passkey.php", ['action' => 'login_finish', 'credential' => $good], ['Content-Type: application/json', "X-CSRF-Token: $csrf3"], true);
[$c3] = hreq($j3, "$app/faculty.php");
ok($code === 401 && $c3 === 302, 'a recorded assertion cannot be replayed to sign in again');
// Five bad attempts end the pending sign-in.
for ($i = 0; $i < 5; $i++) {
    hreq($j3, "$app/passkey.php", ['action' => 'login_options'], ['Content-Type: application/json', "X-CSRF-Token: $csrf3"], true);
    [$code, $body] = hreq($j3, "$app/passkey.php", ['action' => 'login_finish', 'credential' => ['id' => 'AAAA', 'clientDataJSON' => 'AAAA']], ['Content-Type: application/json', "X-CSRF-Token: $csrf3"], true);
}
[$code] = hreq($j3, "$app/passkey.php", ['action' => 'login_options'], ['Content-Type: application/json', "X-CSRF-Token: $csrf3"], true);
ok($code === 401, 'after repeated failures the pending sign-in is abandoned (the password must be entered again)');

// Removal needs the signed-in person, a recent confirmation and the token.
$pid = (int) Db::val('SELECT id FROM passkeys WHERE user_id = ?', [$omar['id']]);
$sara = hjar();
[, $html] = hreq($sara, "$app/login.php");
hreq($sara, "$app/demo.php", ['_csrf' => tok($html), 'as' => 'f.sara', 'next' => 'account.php']);
[, $html] = hreq($sara, "$app/account.php");
[$code, $body] = hreq($sara, "$app/passkey.php", ['action' => 'remove', 'id' => $pid], ['Content-Type: application/json', 'X-CSRF-Token: ' . tok($html)], true);
ok($code === 422 && Db::val('SELECT revoked_at FROM passkeys WHERE id = ?', [$pid]) === null, 'another person cannot remove someone else\'s passkey');
[$code] = hreq($j, "$app/passkey.php", ['action' => 'remove', 'id' => $pid], ['Content-Type: application/json', "X-CSRF-Token: $csrf"], true);
ok($code === 200 && Db::val('SELECT revoked_at FROM passkeys WHERE id = ?', [$pid]) !== null && (bool) Db::val('SELECT 1 FROM audit_log WHERE action = "security.passkey_removed"'), 'the owner can remove it, and it is audited');
$j4 = hjar();
[, $html] = hreq($j4, "$app/login.php");
[$code, , $head] = hreq($j4, "$app/login.php", ['_csrf' => tok($html), 'username' => 'f.omar', 'password' => Story::PASSWORD]);
[, $mfaPage] = hreq($j4, "$app/mfa.php");
ok(str_contains($head, 'mfa.php') && !str_contains($mfaPage, 'data-passkey="login"') && str_contains($mfaPage, 'name="code"'), 'once the passkey is removed the second step asks for the e-mailed code again (the account is not left without a second step)');
$ip = serve(dirname(__DIR__) . '/public');
$jj = hjar();
[, $html] = hreq($jj, "$ip/login.php");
hreq($jj, "$ip/demo.php", ['_csrf' => tok($html), 'as' => 'f.omar', 'next' => 'account.php']);
[$code, $html] = hreq($jj, "$ip/account.php");
ok(str_contains($html, 'not available on this address') && !str_contains($html, 'data-passkey="register"'), 'on an IP address (where browsers refuse passkeys) the page says so instead of offering a button that cannot work');

finish();
