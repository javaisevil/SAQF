<?php
declare(strict_types=1);

namespace Saqf\Security;

use InvalidArgumentException;
use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Config;
use Saqf\Core\Db;
use Saqf\Core\Request;
use Saqf\Core\Throttle;

/**
 * Passkeys as a second step after the password (never instead of it): registration under Account &
 * security, and use on the two-step page. The cryptography is in WebAuthn; this class holds the
 * challenges (single use, five minutes, bound to the person), the stored public keys and the audit trail.
 *
 * A passkey does not replace the authenticator app for administrators, and it cannot be used to sign in
 * without the password. Passkeys are offered only where the browser can use them: over HTTPS or on localhost.
 */
final class Passkeys
{
    private const TTL = 300;

    /** Relying party id: the host of SAQF_BASE_URL (production) or of the request (demo / local). Null when unusable. */
    public static function rpId(): ?string
    {
        $base = (string) Config::get('SAQF_BASE_URL', '');
        $host = $base !== '' ? (string) parse_url($base, PHP_URL_HOST) : strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
        $host = strtolower($host);
        if ($host === '' || !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host) || filter_var($host, FILTER_VALIDATE_IP)) {
            return null; // browsers refuse IP addresses as relying party ids
        }
        return $host;
    }

    /** The origin the browser reports for this site. */
    public static function origin(): string
    {
        $base = (string) Config::get('SAQF_BASE_URL', '');
        if ($base !== '') {
            $p = parse_url($base);
            return strtolower((string) ($p['scheme'] ?? 'https')) . '://' . strtolower((string) ($p['host'] ?? '')) . (isset($p['port']) ? ':' . $p['port'] : '');
        }
        return (Request::isHttps() ? 'https' : 'http') . '://' . strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    }

    /** True when passkeys can work here (a usable relying party id, over HTTPS or on localhost). */
    public static function available(): bool
    {
        $rp = self::rpId();
        return $rp !== null && (Request::isHttps() || $rp === 'localhost' || str_ends_with($rp, '.localhost'));
    }

    public static function count(array $user): int
    {
        return (int) Db::val('SELECT COUNT(*) FROM passkeys WHERE user_id = ? AND revoked_at IS NULL', [$user['id']]);
    }

    /** @return list<array<string,mixed>> */
    public static function forUser(int $userId): array
    {
        return Db::all('SELECT id, label, created_at, last_used_at, sign_count FROM passkeys WHERE user_id = ? AND revoked_at IS NULL ORDER BY id', [$userId]);
    }

    /** An opaque, stable handle for the authenticator (never the user id or any personal data). */
    private static function userHandle(array $user): string
    {
        return hash_hmac('sha256', 'saqf:passkey-user:' . $user['id'], \Saqf\Core\Secrets::appKey(), true);
    }

    private static function newChallenge(string $purpose, int $userId): string
    {
        $c = WebAuthn::b64u(random_bytes(32));
        $_SESSION['webauthn'] = ['purpose' => $purpose, 'challenge' => $c, 'uid' => $userId, 'exp' => time() + self::TTL];
        return $c;
    }

    /** Takes the challenge for this ceremony; it can never be used twice. */
    private static function takeChallenge(string $purpose, int $userId): string
    {
        $w = $_SESSION['webauthn'] ?? null;
        unset($_SESSION['webauthn']);
        if (!is_array($w) || ($w['purpose'] ?? '') !== $purpose || (int) ($w['uid'] ?? 0) !== $userId || (int) ($w['exp'] ?? 0) < time()) {
            throw new InvalidArgumentException('The request expired. Start again.');
        }
        return (string) $w['challenge'];
    }

    /** Options for navigator.credentials.create() (the person is signed in and recently confirmed their password). */
    public static function registerOptions(array $user): array
    {
        if (!self::available()) {
            throw new InvalidArgumentException('Passkeys need HTTPS (or localhost) and a host name; they are not available here.');
        }
        if (self::count($user) >= 10) {
            throw new InvalidArgumentException('You already have 10 passkeys. Remove one first.');
        }
        $exclude = array_map(static fn($r) => ['type' => 'public-key', 'id' => $r['credential_id']], Db::all('SELECT credential_id FROM passkeys WHERE user_id = ? AND revoked_at IS NULL', [$user['id']]));
        return [
            'rp' => ['name' => 'SAQF', 'id' => self::rpId()],
            'user' => ['id' => WebAuthn::b64u(self::userHandle($user)), 'name' => (string) $user['username'], 'displayName' => (string) $user['full_name']],
            'challenge' => self::newChallenge('register', (int) $user['id']),
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7]],
            'timeout' => 120000,
            'attestation' => 'none',
            'authenticatorSelection' => ['residentKey' => 'preferred', 'userVerification' => 'required'],
            'excludeCredentials' => $exclude,
        ];
    }

    /** Verifies and stores a new passkey. @return int passkey id */
    public static function registerFinish(array $user, array $payload, string $label): int
    {
        Throttle::check('passkey-reg:' . $user['id'], 10, 900, 'Too many attempts. Please wait a few minutes.');
        $challenge = self::takeChallenge('register', (int) $user['id']);
        try {
            $r = WebAuthn::verifyRegistration($payload, $challenge, self::origin(), (string) self::rpId());
        } catch (InvalidArgumentException $e) {
            Audit::record('security.passkey_refused', 'user', $user['id'], 'A passkey registration was refused: ' . mb_substr($e->getMessage(), 0, 120));
            throw new InvalidArgumentException('That passkey could not be verified. Try again, or use another device.');
        }
        $cid = WebAuthn::b64u($r['credential_id']);
        if (Db::val('SELECT 1 FROM passkeys WHERE credential_id = ?', [$cid])) {
            throw new InvalidArgumentException('That passkey is already registered.');
        }
        $label = trim($label) !== '' ? mb_substr(trim($label), 0, 80) : 'Passkey ' . (self::count($user) + 1);
        $id = Db::insert('passkeys', ['user_id' => $user['id'], 'credential_id' => $cid, 'public_key' => $r['public_key_pem'], 'sign_count' => $r['sign_count'], 'label' => $label, 'aaguid' => bin2hex($r['aaguid']), 'created_at' => Clock::stamp()]);
        Audit::record('security.passkey_added', 'user', $user['id'], "{$user['full_name']} added the passkey \"$label\"");
        return $id;
    }

    public static function remove(array $user, int $id): void
    {
        $row = Db::one('SELECT id, label FROM passkeys WHERE id = ? AND user_id = ? AND revoked_at IS NULL', [$id, $user['id']]);
        if (!$row) {
            throw new InvalidArgumentException('Passkey not found.');
        }
        Db::update('passkeys', ['revoked_at' => Clock::stamp()], 'id = ?', [$id]);
        Audit::record('security.passkey_removed', 'user', $user['id'], "{$user['full_name']} removed the passkey \"{$row['label']}\"");
    }

    /** Options for navigator.credentials.get() for the person waiting at the second step. */
    public static function loginOptions(array $user): array
    {
        if (!self::available() || self::count($user) === 0) {
            throw new InvalidArgumentException('No passkey is available for this account here.');
        }
        $allow = array_map(static fn($r) => ['type' => 'public-key', 'id' => $r['credential_id']], Db::all('SELECT credential_id FROM passkeys WHERE user_id = ? AND revoked_at IS NULL', [$user['id']]));
        return [
            'challenge' => self::newChallenge('login', (int) $user['id']),
            'rpId' => self::rpId(),
            'timeout' => 120000,
            'userVerification' => 'required',
            'allowCredentials' => $allow,
        ];
    }

    /** Verifies an assertion for the person waiting at the second step. @return bool true when the passkey is valid */
    public static function loginFinish(array $user, array $payload): bool
    {
        Throttle::check('passkey-login:' . $user['id'], 20, 900, 'Too many attempts. Please wait a few minutes.');
        $challenge = self::takeChallenge('login', (int) $user['id']);
        $raw = WebAuthn::unb64u((string) ($payload['id'] ?? ''));
        $row = $raw === null ? null : Db::one('SELECT * FROM passkeys WHERE credential_id = ? AND user_id = ? AND revoked_at IS NULL', [WebAuthn::b64u($raw), $user['id']]);
        if (!$row) {
            return false;
        }
        try {
            $r = WebAuthn::verifyAssertion($payload, $challenge, self::origin(), (string) self::rpId(), (string) $row['public_key'], (int) $row['sign_count']);
        } catch (InvalidArgumentException $e) {
            Audit::asSystem(static fn() => Audit::record('security.passkey_refused', 'user', $user['id'], "A passkey sign-in for {$user['username']} was refused: " . mb_substr($e->getMessage(), 0, 120)));
            return false;
        }
        Db::update('passkeys', ['sign_count' => $r['sign_count'], 'last_used_at' => Clock::stamp()], 'id = ?', [$row['id']]);
        return true;
    }
}
