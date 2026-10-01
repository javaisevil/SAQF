<?php
declare(strict_types=1);

namespace Saqf\Security;

use RuntimeException;
use Saqf\Core\Clock;
use Saqf\Core\Config;
use Saqf\Core\Db;
use Saqf\Core\Mailer;
use Saqf\Core\Request;
use Saqf\Integration\Http;

/**
 * University single sign-on with OpenID Connect (authorization code flow + PKCE). Works with
 * Microsoft Entra ID (Azure AD / Microsoft 365), Google Workspace, Keycloak, ADFS 2019+, Okta…
 *
 *   SAQF_OIDC_ISSUER          e.g. https://login.microsoftonline.com/<tenant-id>/v2.0
 *   SAQF_OIDC_CLIENT_ID       application (client) id registered for SAQF
 *   SAQF_OIDC_CLIENT_SECRET   client secret
 *   SAQF_BASE_URL             https://saqf.example.edu  → redirect URI {SAQF_BASE_URL}/sso.php
 *   optional: SAQF_OIDC_REDIRECT_URI, SAQF_OIDC_SCOPES ("openid profile email"),
 *   SAQF_OIDC_USERNAME_CLAIM (preferred_username), SAQF_OIDC_ROLE_CLAIM + SAQF_OIDC_ROLE_MAP
 *   ("SAQF.QA=qa;SAQF.Faculty=faculty"), SAQF_OIDC_AUTO_PROVISION, SAQF_OIDC_DEFAULT_ROLE,
 *   SAQF_OIDC_CLIENT_AUTH (post | basic), SAQF_OIDC_BUTTON_LABEL
 *
 * Security: state (CSRF) and nonce (replay) are single-use and bound to the browser with a
 * short-lived cookie; the ID token signature is verified against the provider's published keys;
 * issuer, audience, expiry and nonce are checked before a session is created.
 */
final class Oidc
{
    private const COOKIE = 'SAQF_SSO';
    private const TTL = 600;

    public static function enabled(): bool
    {
        return (string) Config::get('SAQF_OIDC_ISSUER', '') !== '' && (string) Config::get('SAQF_OIDC_CLIENT_ID', '') !== '';
    }

    public static function buttonLabel(): string
    {
        return (string) (Config::get('SAQF_OIDC_BUTTON_LABEL') ?: 'Sign in with your university account');
    }

    public static function redirectUri(): string
    {
        $uri = (string) Config::get('SAQF_OIDC_REDIRECT_URI', '');
        if ($uri !== '') {
            return $uri;
        }
        if (Mailer::baseUrl() === '') {
            throw new SsoException('University sign-in is not fully configured (SAQF_BASE_URL is missing).');
        }
        return Mailer::baseUrl() . '/sso.php';
    }

    /** Starts a sign-in: stores the transaction and returns the provider URL to redirect to. */
    public static function begin(): string
    {
        $meta = self::metadata();
        $state = self::random();
        $nonce = self::random();
        $verifier = self::random(48);
        $browser = self::random();
        Db::exec('DELETE FROM sso_transactions WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
        Db::insert('sso_transactions', [
            'state_hash' => hash('sha256', $state), 'nonce' => $nonce, 'verifier' => $verifier,
            'browser_hash' => hash('sha256', $browser), 'created_at' => date('Y-m-d H:i:s'),
        ]);
        // SameSite=Lax so the cookie survives the top-level redirect back from the provider.
        setcookie(self::COOKIE, $browser, ['expires' => time() + self::TTL, 'path' => '/', 'secure' => Request::isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
        return $meta['authorization_endpoint'] . (str_contains($meta['authorization_endpoint'], '?') ? '&' : '?') . http_build_query([
            'response_type' => 'code',
            'client_id' => (string) Config::get('SAQF_OIDC_CLIENT_ID'),
            'redirect_uri' => self::redirectUri(),
            'scope' => (string) (Config::get('SAQF_OIDC_SCOPES') ?: 'openid profile email'),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    /** Completes a sign-in from the provider's redirect. @return array verified ID token claims */
    public static function complete(array $query): array
    {
        if (isset($query['error'])) {
            throw new SsoException('The university sign-in did not complete (' . mb_substr((string) ($query['error_description'] ?? $query['error']), 0, 160) . ').');
        }
        $state = (string) ($query['state'] ?? '');
        $code = (string) ($query['code'] ?? '');
        $browser = (string) ($_COOKIE[self::COOKIE] ?? '');
        setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => Request::isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
        if ($state === '' || $code === '') {
            throw new SsoException('The university sign-in response was incomplete. Please try again.');
        }
        $tx = Db::one('SELECT * FROM sso_transactions WHERE state_hash = ?', [hash('sha256', $state)]);
        if (!$tx || $tx['used_at'] !== null || strtotime((string) $tx['created_at']) < time() - self::TTL
            || $browser === '' || !hash_equals((string) $tx['browser_hash'], hash('sha256', $browser))) {
            throw new SsoException('This sign-in link has expired or was started in another browser. Please sign in again.');
        }
        Db::update('sso_transactions', ['used_at' => date('Y-m-d H:i:s')], 'state_hash = ?', [$tx['state_hash']]);

        $meta = self::metadata();
        $params = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::redirectUri(),
            'client_id' => (string) Config::get('SAQF_OIDC_CLIENT_ID'), 'code_verifier' => $tx['verifier']];
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];
        $secret = (string) Config::get('SAQF_OIDC_CLIENT_SECRET', '');
        if ($secret !== '') {
            if (Config::get('SAQF_OIDC_CLIENT_AUTH') === 'basic') {
                $headers['Authorization'] = 'Basic ' . base64_encode(rawurlencode((string) Config::get('SAQF_OIDC_CLIENT_ID')) . ':' . rawurlencode($secret));
            } else {
                $params['client_secret'] = $secret;
            }
        }
        try {
            $tokens = Http::json('POST', $meta['token_endpoint'], $headers, http_build_query($params));
        } catch (RuntimeException $e) {
            throw new SsoException('The university identity provider refused the sign-in (' . $e->getMessage() . ').');
        }
        if (empty($tokens['id_token'])) {
            throw new SsoException('The identity provider did not return an ID token.');
        }
        try {
            $claims = Jwt::verify((string) $tokens['id_token'], self::jwks());
        } catch (JwtKeyNotFound $e) {
            try {
                $claims = Jwt::verify((string) $tokens['id_token'], self::jwks(true)); // provider rotated its keys
            } catch (JwtKeyNotFound $e) {
                throw new SsoException('The ID token is signed with a key the identity provider does not publish.');
            }
        }
        self::validate($claims, $meta['issuer'], (string) $tx['nonce']);
        return $claims;
    }

    /** Standard ID token claim checks (OpenID Connect Core §3.1.3.7). Real time, never the demo clock. */
    public static function validate(array $claims, string $issuer, string $nonce): void
    {
        $clientId = (string) Config::get('SAQF_OIDC_CLIENT_ID');
        $aud = (array) ($claims['aud'] ?? []);
        $now = time();
        if (($claims['iss'] ?? null) !== $issuer) {
            throw new SsoException('The ID token was issued by an unexpected identity provider.');
        }
        if (!in_array($clientId, $aud, true) || (count($aud) > 1 && ($claims['azp'] ?? null) !== $clientId)) {
            throw new SsoException('The ID token was issued for another application.');
        }
        if (!isset($claims['exp']) || (int) $claims['exp'] < $now - 60) {
            throw new SsoException('The ID token has expired. Please sign in again.');
        }
        if (isset($claims['iat']) && (int) $claims['iat'] > $now + 300) {
            throw new SsoException('The ID token is dated in the future (check the server clock).');
        }
        if (!hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
            throw new SsoException('The ID token does not belong to this sign-in attempt.');
        }
    }

    /** Role from the provider's claims via SAQF_OIDC_ROLE_MAP; the most senior mapped role wins. */
    public static function roleFromClaims(array $claims): ?string
    {
        $claim = (string) Config::get('SAQF_OIDC_ROLE_CLAIM', '');
        $map = [];
        foreach (preg_split('/[;,]/', (string) Config::get('SAQF_OIDC_ROLE_MAP', '')) ?: [] as $pair) {
            [$value, $role] = array_map('trim', array_pad(explode('=', $pair, 2), 2, ''));
            if ($value !== '' && isset(Auth::ROLES[$role])) {
                $map[$value] = $role;
            }
        }
        if ($claim === '' || !$map || !isset($claims[$claim])) {
            return null;
        }
        $found = array_values(array_intersect_key($map, array_flip(array_map('strval', (array) $claims[$claim]))));
        if (!$found) {
            return null;
        }
        $rank = array_flip(['faculty', 'hod', 'qa', 'dean', 'leadership', 'admin']);
        usort($found, static fn($a, $b) => $rank[$b] <=> $rank[$a]);
        return $found[0];
    }

    /** Provider sign-out URL (RP-initiated logout), when the provider supports it. */
    public static function logoutUrl(): ?string
    {
        try {
            $meta = self::metadata();
        } catch (\Throwable $e) {
            return null;
        }
        if (empty($meta['end_session_endpoint']) || Mailer::baseUrl() === '') {
            return null;
        }
        return $meta['end_session_endpoint'] . (str_contains($meta['end_session_endpoint'], '?') ? '&' : '?') . http_build_query([
            'client_id' => (string) Config::get('SAQF_OIDC_CLIENT_ID'), 'post_logout_redirect_uri' => Mailer::baseUrl() . '/login.php',
        ]);
    }

    /** Provider metadata from /.well-known/openid-configuration, cached for a day. */
    public static function metadata(): array
    {
        $issuer = rtrim((string) Config::get('SAQF_OIDC_ISSUER'), '/');
        $cached = json_decode((string) Db::val('SELECT value FROM system_settings WHERE setting_key = "oidc.metadata"'), true);
        if (is_array($cached) && ($cached['_for'] ?? '') === $issuer && ($cached['_at'] ?? 0) > time() - 86400) {
            return $cached;
        }
        try {
            $meta = Http::json('GET', $issuer . '/.well-known/openid-configuration');
        } catch (RuntimeException $e) {
            throw new SsoException('The university identity provider is not reachable right now (' . $e->getMessage() . ').');
        }
        foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $k) {
            if (empty($meta[$k]) || !is_string($meta[$k])) {
                throw new SsoException("The identity provider's configuration is missing $k.");
            }
        }
        if (rtrim($meta['issuer'], '/') !== $issuer) {
            throw new SsoException('The identity provider reports a different issuer than SAQF_OIDC_ISSUER.');
        }
        $meta = array_intersect_key($meta, array_flip(['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri', 'end_session_endpoint'])) + ['_for' => $issuer, '_at' => time()];
        self::store('oidc.metadata', $meta);
        return $meta;
    }

    private static function jwks(bool $refresh = false): array
    {
        $meta = self::metadata();
        $cached = json_decode((string) Db::val('SELECT value FROM system_settings WHERE setting_key = "oidc.jwks"'), true);
        if (!$refresh && is_array($cached) && ($cached['_for'] ?? '') === $meta['jwks_uri'] && ($cached['_at'] ?? 0) > time() - 86400) {
            return $cached;
        }
        try {
            $jwks = Http::json('GET', $meta['jwks_uri']);
        } catch (RuntimeException $e) {
            throw new SsoException('Could not load the identity provider signing keys (' . $e->getMessage() . ').');
        }
        $jwks = ['keys' => $jwks['keys'] ?? [], '_for' => $meta['jwks_uri'], '_at' => time()];
        self::store('oidc.jwks', $jwks);
        return $jwks;
    }

    private static function store(string $key, array $value): void
    {
        Db::exec('INSERT INTO system_settings (setting_key, value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)', [$key, json_encode($value), Clock::stamp()]);
    }

    private static function random(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
