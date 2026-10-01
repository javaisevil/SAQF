<?php
// Stand-in OpenID Connect provider (php -S router) for tests/sso_test.php.
// The test writes the identity to return next into <state dir>/next.json:
//   {"claims": {"sub": "...", "preferred_username": "...", ...}, "options": {"bad_signature": true, "error": "access_denied", "aud": "other"}}
$dir = sys_get_temp_dir() . '/saqf-mock-idp-' . $_SERVER['SERVER_PORT'];
@mkdir($dir, 0700, true);
$issuer = 'http://127.0.0.1:' . $_SERVER['SERVER_PORT'];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function idp_key(string $dir, string $name): OpenSSLAsymmetricKey
{
    $file = "$dir/$name.pem";
    if (!is_file($file)) {
        $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($k, $pem);
        file_put_contents($file, $pem);
    }
    return openssl_pkey_get_private((string) file_get_contents($file));
}
function b64u(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}
function json_out(int $code, array $data): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

if ($path === '/.well-known/openid-configuration') {
    json_out(200, ['issuer' => $issuer, 'authorization_endpoint' => "$issuer/authorize", 'token_endpoint' => "$issuer/token",
        'jwks_uri' => "$issuer/jwks", 'end_session_endpoint' => "$issuer/logout", 'response_types_supported' => ['code'],
        'id_token_signing_alg_values_supported' => ['RS256'], 'code_challenge_methods_supported' => ['S256']]);
}
if ($path === '/jwks') {
    $d = openssl_pkey_get_details(idp_key($dir, 'signing'));
    json_out(200, ['keys' => [['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => 'k1', 'n' => b64u($d['rsa']['n']), 'e' => b64u($d['rsa']['e'])]]]);
}
if ($path === '/authorize') {
    $next = json_decode((string) @file_get_contents("$dir/next.json"), true) ?: [];
    $q = $_GET;
    $back = (string) ($q['redirect_uri'] ?? '');
    if (($q['client_id'] ?? '') !== 'saqf-test' || ($q['response_type'] ?? '') !== 'code' || ($q['code_challenge_method'] ?? '') !== 'S256' || $back === '') {
        json_out(400, ['error' => 'invalid_request']);
    }
    if (!empty($next['options']['error'])) {
        header('Location: ' . $back . '?' . http_build_query(['error' => $next['options']['error'], 'error_description' => 'User cancelled', 'state' => $q['state'] ?? '']));
        exit;
    }
    $code = b64u(random_bytes(18));
    $codes = json_decode((string) @file_get_contents("$dir/codes.json"), true) ?: [];
    $codes[$code] = ['nonce' => $q['nonce'] ?? '', 'challenge' => $q['code_challenge'] ?? '', 'redirect_uri' => $back, 'next' => $next];
    file_put_contents("$dir/codes.json", json_encode($codes));
    header('Location: ' . $back . '?' . http_build_query(['code' => $code, 'state' => $q['state'] ?? '']));
    exit;
}
if ($path === '/token') {
    $p = $_POST;
    $codes = json_decode((string) @file_get_contents("$dir/codes.json"), true) ?: [];
    $c = $codes[$p['code'] ?? ''] ?? null;
    if (($p['client_id'] ?? '') !== 'saqf-test' || ($p['client_secret'] ?? '') !== 'test-secret') {
        json_out(401, ['error' => 'invalid_client']);
    }
    if (!$c || ($p['redirect_uri'] ?? '') !== $c['redirect_uri'] || b64u(hash('sha256', (string) ($p['code_verifier'] ?? ''), true)) !== $c['challenge']) {
        json_out(400, ['error' => 'invalid_grant', 'error_description' => 'code, redirect_uri or PKCE verifier mismatch']);
    }
    unset($codes[$p['code']]);
    file_put_contents("$dir/codes.json", json_encode($codes));
    $opt = $c['next']['options'] ?? [];
    $claims = ($c['next']['claims'] ?? []) + ['iss' => $issuer, 'aud' => $opt['aud'] ?? 'saqf-test', 'iat' => time(), 'exp' => time() + 300, 'nonce' => $c['nonce']];
    $h = b64u(json_encode(['alg' => 'RS256', 'kid' => 'k1', 'typ' => 'JWT']));
    $b = b64u(json_encode($claims));
    openssl_sign("$h.$b", $sig, idp_key($dir, !empty($opt['bad_signature']) ? 'attacker' : 'signing'), OPENSSL_ALGO_SHA256);
    json_out(200, ['access_token' => b64u(random_bytes(16)), 'token_type' => 'Bearer', 'expires_in' => 300, 'id_token' => "$h.$b." . b64u($sig)]);
}
if ($path === '/logout') {
    echo 'Signed out of the identity provider.';
    exit;
}
json_out(404, ['error' => 'not_found']);
