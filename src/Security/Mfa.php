<?php
declare(strict_types=1);

namespace Saqf\Security;

use InvalidArgumentException;
use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Db;
use Saqf\Core\Policy;
use Saqf\Core\Secrets;

/**
 * Two-step verification for password sign-ins: an authenticator-app code (TOTP) after the password,
 * with ten one-time recovery codes. Secrets are stored encrypted; codes cannot be replayed.
 * Policy auth.mfa_required decides who must use it (administrators by default). University SSO
 * sign-ins rely on the identity provider's own multi-factor authentication.
 */
final class Mfa
{
    public const RECOVERY_CODES = 10;

    public static function enabled(array $user): bool
    {
        return !empty($user['mfa_enabled_at']) && !empty($user['mfa_secret']);
    }

    public static function required(array $user): bool
    {
        $mode = Policy::get('auth.mfa_required');
        return $mode === 'all' || ($mode === 'admins' && $user['role'] === 'admin');
    }

    /** Starts enrolment: a new secret kept in the session until the first code confirms it. */
    public static function begin(array $user): string
    {
        $_SESSION['mfa_enrol'] = ['uid' => (int) $user['id'], 'secret' => Totp::secret(), 'at' => time()];
        return $_SESSION['mfa_enrol']['secret'];
    }

    public static function pendingSecret(array $user): ?string
    {
        $p = $_SESSION['mfa_enrol'] ?? null;
        return $p && (int) $p['uid'] === (int) $user['id'] && time() - (int) $p['at'] < 900 ? (string) $p['secret'] : null;
    }

    /** Confirms enrolment with the first code. @return list<string> recovery codes (shown once) */
    public static function confirm(array $user, string $code): array
    {
        $secret = self::pendingSecret($user);
        if ($secret === null) {
            throw new InvalidArgumentException('The setup expired. Start again.');
        }
        $step = Totp::verify($secret, $code);
        if ($step === null) {
            throw new InvalidArgumentException('That code is not right. Check the time on your phone and enter the current 6-digit code.');
        }
        unset($_SESSION['mfa_enrol']);
        return self::store((int) $user['id'], $secret, $step, 'set up two-step verification');
    }

    /** Enrols with a known secret (used for the demo administrator). @return list<string> */
    public static function enrolWithSecret(int $userId, string $secret): array
    {
        return self::store($userId, $secret, null, 'two-step verification configured (demo)');
    }

    /** @return int|null matching TOTP step, 0 for a recovery code, null when wrong */
    public static function verify(array $user, string $code): ?int
    {
        $secret = Secrets::decrypt((string) $user['mfa_secret']);
        if ($secret === null) {
            return null;
        }
        $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
        if (strlen($clean) === 6 && ctype_digit($clean)) {
            $step = Totp::verify($secret, $clean, $user['mfa_last_step'] === null ? null : (int) $user['mfa_last_step']);
            if ($step !== null) {
                Db::update('users', ['mfa_last_step' => $step], 'id = ?', [$user['id']]);
                return $step;
            }
            return null;
        }
        if (strlen($clean) === 10) {
            $id = Db::val('SELECT id FROM mfa_recovery_codes WHERE user_id = ? AND code_hash = ? AND used_at IS NULL', [$user['id'], hash('sha256', $clean)]);
            if ($id) {
                Db::update('mfa_recovery_codes', ['used_at' => Clock::stamp()], 'id = ?', [$id]);
                $left = (int) Db::val('SELECT COUNT(*) FROM mfa_recovery_codes WHERE user_id = ? AND used_at IS NULL', [$user['id']]);
                Audit::record('security.mfa_recovery_used', 'user', $user['id'], "{$user['full_name']} signed in with a recovery code ($left left)");
                return 0;
            }
        }
        return null;
    }

    public static function recoveryLeft(int $userId): int
    {
        return (int) Db::val('SELECT COUNT(*) FROM mfa_recovery_codes WHERE user_id = ? AND used_at IS NULL', [$userId]);
    }

    /** @return list<string> */
    public static function newRecoveryCodes(int $userId): array
    {
        Db::exec('DELETE FROM mfa_recovery_codes WHERE user_id = ?', [$userId]);
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $code = strtoupper(substr(Totp::base32(random_bytes(7)), 0, 10));
            $codes[] = substr($code, 0, 5) . '-' . substr($code, 5);
            Db::insert('mfa_recovery_codes', ['user_id' => $userId, 'code_hash' => hash('sha256', $code), 'created_at' => Clock::stamp()]);
        }
        return $codes;
    }

    /** Turns two-step verification off (by the person, or reset by an administrator). */
    public static function disable(int $userId, string $by, ?string $reason = null): void
    {
        $u = Db::one('SELECT * FROM users WHERE id = ?', [$userId]);
        Db::update('users', ['mfa_secret' => null, 'mfa_enabled_at' => null, 'mfa_last_step' => null], 'id = ?', [$userId]);
        Db::exec('DELETE FROM mfa_recovery_codes WHERE user_id = ?', [$userId]);
        Audit::record($by === 'admin' ? 'admin.mfa_reset' : 'security.mfa_disabled', 'user', $userId, "Two-step verification turned off for {$u['username']}" . ($by === 'admin' ? ' by an administrator' : ''), null, null, $reason);
    }

    private static function store(int $userId, string $secret, ?int $step, string $what): array
    {
        Db::update('users', ['mfa_secret' => Secrets::encrypt($secret), 'mfa_enabled_at' => Clock::stamp(), 'mfa_last_step' => $step], 'id = ?', [$userId]);
        $codes = self::newRecoveryCodes($userId);
        $u = Db::one('SELECT username, full_name FROM users WHERE id = ?', [$userId]);
        Audit::record('security.mfa_enabled', 'user', $userId, "{$u['full_name']} $what");
        return $codes;
    }
}
