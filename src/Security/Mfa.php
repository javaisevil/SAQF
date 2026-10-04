<?php
declare(strict_types=1);

namespace Saqf\Security;

use InvalidArgumentException;
use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Config;
use Saqf\Core\Db;
use Saqf\Core\Mailer;
use Saqf\Core\Policy;
use Saqf\Core\Secrets;

/**
 * Two-step verification for password sign-ins, two ways:
 *  - a code e-mailed to the person's university address (works for everyone with no set-up: 6 digits,
 *    valid 10 minutes, single use, at most 5 tries and 5 e-mails per sign-in), or
 *  - a code from an authenticator app (TOTP, RFC 6238), with ten one-time recovery codes. Required for
 *    administrators, who may not use e-mail codes.
 * Secrets are stored encrypted; codes cannot be replayed. Policy auth.mfa_required decides who must use
 * it (everyone signing in with a password by default). University SSO sign-ins rely on the identity
 * provider's own multi-factor authentication.
 */
final class Mfa
{
    public const RECOVERY_CODES = 10;

    public static function enabled(array $user): bool
    {
        return !empty($user['mfa_enabled_at']) && !empty($user['mfa_secret']);
    }

    /** E-mailed codes: anyone but administrators with an e-mail address, when SAQF can deliver mail (or in demo mode, where the code is shown on screen). */
    public static function emailAllowed(array $user): bool
    {
        return ($user['role'] ?? '') !== 'admin' && filter_var((string) ($user['email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false
            && (Mailer::enabled() || Config::demoMode());
    }

    /** True when the person has a working second step (an authenticator app, or e-mailed codes). */
    public static function hasFactor(array $user): bool
    {
        // A passkey (device PIN or biometric, bound to this site) counts for everyone except administrators,
        // who must still use the authenticator app.
        return self::enabled($user) || self::emailAllowed($user) || (($user['role'] ?? '') !== 'admin' && Passkeys::count($user) > 0);
    }

    /** "o•••••@yu.edu.sa" */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($local, 0, 1) . str_repeat('•', max(3, min(8, mb_strlen($local) - 1))) . '@' . $domain;
    }

    /**
     * E-mails a new 6-digit code for the sign-in waiting in this session.
     * @return string|null null when sent, otherwise why not (wait, or too many codes)
     */
    public static function sendEmailCode(array $user): ?string
    {
        if (!isset($_SESSION['mfa_pending']) || !self::emailAllowed($user)) {
            return 'E-mailed codes are not available for this account.';
        }
        $prev = $_SESSION['mfa_pending']['email'] ?? null;
        if ($prev && (int) $prev['sends'] >= 5) {
            return 'Too many codes were sent. Start the sign-in again in a few minutes.';
        }
        if ($prev && time() - (int) $prev['sent_at'] < 30) {
            return 'A code was just sent. Wait ' . (30 - (time() - (int) $prev['sent_at'])) . ' seconds before asking for another.';
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['mfa_pending']['email'] = [
            'hash' => self::codeHash($code, (int) $user['id']), 'expires' => time() + 600, 'sent_at' => time(),
            'sends' => (int) ($prev['sends'] ?? 0) + 1, 'to' => self::maskEmail((string) $user['email']),
            // Demo mode has no mail server: the page shows the e-mail instead (never in production).
            'demo' => Config::demoMode() && !Mailer::enabled() ? $code : null,
        ];
        if (Mailer::enabled()) {
            $id = Mailer::queue((string) $user['email'], (string) $user['full_name'], 'Your SAQF sign-in code: ' . $code, implode("\n", [
                "Dear {$user['full_name']},", '',
                "Your SAQF sign-in code is:  $code", '',
                'It is valid for 10 minutes and works once. SAQF staff will never ask you for this code.',
                'If you did not try to sign in, someone may know your password: change it now under Account & security and inform IT security.',
            ]), 'security');
            if ($id) {
                Mailer::flush(1, $id);
            }
        }
        Audit::asSystem(static fn() => Audit::record('security.mfa_email_sent', 'user', $user['id'], "Sign-in code e-mailed to {$user['username']}"));
        return null;
    }

    /** Checks an e-mailed code against the sign-in waiting in this session (single use). */
    public static function verifyEmailCode(array $user, string $code): bool
    {
        $p = $_SESSION['mfa_pending']['email'] ?? null;
        $clean = (string) preg_replace('/\D/', '', $code);
        if (!$p || (int) $p['expires'] < time() || strlen($clean) !== 6 || !hash_equals((string) $p['hash'], self::codeHash($clean, (int) $user['id']))) {
            return false;
        }
        unset($_SESSION['mfa_pending']['email']);
        return true;
    }

    private static function codeHash(string $code, int $userId): string
    {
        return hash_hmac('sha256', "mfa-email:$userId:$code", Secrets::appKey());
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
        // An administrator's reset is for a lost phone or a suspected takeover: a passkey planted by an attacker must not
        // survive it. (The person turning off their own authenticator app keeps their own passkeys.)
        if ($by === 'admin') {
            $revoked = Db::exec('UPDATE passkeys SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [Clock::stamp(), $userId]);
            if ($revoked) {
                Audit::record('admin.passkeys_revoked', 'user', $userId, "$revoked passkey(s) of {$u['username']} revoked with the two-step reset", null, null, $reason);
            }
        }
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
