<?php
declare(strict_types=1);

namespace Saqf\Security;

use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Db;
use Saqf\Core\Mailer;
use Saqf\Core\Request;
use Saqf\Core\Session;

/**
 * Self-service password reset and account invitations by e-mail. Tokens are 256-bit random,
 * single use, short-lived and stored only as SHA-256 hashes. Requests always get the same answer,
 * so the form cannot be used to discover which accounts exist.
 */
final class PasswordReset
{
    public const RESET_MINUTES = 30;
    public const INVITE_HOURS = 72;

    public static function available(): bool
    {
        return Mailer::enabled() && Mailer::baseUrl() !== '' && Auth::passwordLoginMode() !== 'off';
    }

    /** Handles the "forgot password" form. Silent by design; throttled per network and account. */
    public static function request(string $identifier): void
    {
        if (!self::available()) {
            return;
        }
        $identifier = mb_strtolower(trim($identifier));
        $ip = Request::ip();
        $window = Clock::now()->modify('-15 minutes')->format('Y-m-d H:i:s');
        if ($identifier === '' || (int) Db::val('SELECT COUNT(*) FROM password_resets WHERE ip = ? AND created_at >= ?', [$ip, $window]) >= 5) {
            return;
        }
        $user = Db::one('SELECT * FROM users WHERE username = ? OR LOWER(email) = ? LIMIT 1', [$identifier, $identifier]);
        if (!$user || $user['status'] === 'disabled' || !$user['email'] || !Auth::passwordLoginAllowed($user['role'])) {
            return;
        }
        $hour = Clock::now()->modify('-1 hour')->format('Y-m-d H:i:s');
        if ((int) Db::val('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at >= ?', [$user['id'], $hour]) >= 3) {
            return;
        }
        $token = self::issue((int) $user['id'], self::RESET_MINUTES);
        $link = self::link($token);
        $id = Mailer::queue((string) $user['email'], (string) $user['full_name'], 'SAQF password reset', implode("\n", [
            "Dear {$user['full_name']},", '',
            'Someone (hopefully you) asked to reset your SAQF password. Use this link within ' . self::RESET_MINUTES . ' minutes:', '',
            $link, '',
            'If you did not ask for this, ignore this e-mail — your password has not changed. The request came from network address ' . $ip . '.',
        ]), 'password_reset');
        Audit::asSystem(static fn() => Audit::record('auth.reset_requested', 'user', $user['id'], "Password reset link e-mailed to {$user['username']} (requested from $ip)"));
        if ($id) {
            Mailer::flush(1, $id);
        }
    }

    /** Invitation for a new account: a link to choose a first password. Queued (sent by the caller or scheduler). */
    public static function invite(int $userId): void
    {
        $user = Db::one('SELECT * FROM users WHERE id = ?', [$userId]);
        if (!$user || !$user['email']) {
            return;
        }
        $token = self::issue($userId, self::INVITE_HOURS * 60);
        Mailer::queue((string) $user['email'], (string) $user['full_name'], 'Your SAQF account', implode("\n", [
            "Dear {$user['full_name']},", '',
            'An account has been created for you in SAQF, the academic quality system (' . (Auth::ROLES[$user['role']] ?? $user['role']) . ').', '',
            "Your username is: {$user['username']}",
            'Choose your password with this link (valid for ' . self::INVITE_HOURS . ' hours):', '',
            self::link($token), '',
            'SAQF only contacts you when something needs your academic judgement.',
        ]), 'invitation');
    }

    public static function issue(int $userId, int $minutes): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = Clock::now();
        Db::insert('password_resets', [
            'user_id' => $userId, 'token_hash' => hash('sha256', $token), 'ip' => Request::ip(),
            'created_at' => $now->format('Y-m-d H:i:s'), 'expires_at' => $now->modify("+$minutes minutes")->format('Y-m-d H:i:s'),
        ]);
        return $token;
    }

    /** @return array|null the user the token belongs to, if the token is valid */
    public static function find(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_\-]{40,60}$/', $token)) {
            return null;
        }
        return Db::one(
            'SELECT u.*, r.id AS reset_id FROM password_resets r JOIN users u ON u.id = r.user_id
             WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > ? AND u.status <> "disabled"',
            [hash('sha256', $token), Clock::stamp()]
        );
    }

    /** @return string|null error message, or null when the password was set */
    public static function complete(string $token, string $password, string $confirm): ?string
    {
        $user = self::find($token);
        if (!$user || !Auth::passwordLoginAllowed($user['role'])) {
            return 'This link is invalid or has expired. Request a new one.';
        }
        if ($password !== $confirm) {
            return 'The passwords do not match.';
        }
        if ($problem = Auth::passwordProblem($password, (string) $user['username'], $user)) {
            return $problem;
        }
        Db::tx(static function () use ($user, $password) {
            Db::update('users', ['password_hash' => Auth::hash($password), 'password_changed_at' => Clock::stamp(), 'must_change_password' => 0,
                'status' => 'active', 'locked_until' => null, 'failed_logins' => 0], 'id = ?', [$user['id']]);
            Db::exec('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [Clock::stamp(), $user['id']]);
            Sessions::endAll((int) $user['id'], 'password reset');
            Audit::actAs('user', (int) $user['id'], (string) $user['full_name'], (string) $user['role']);
            Audit::record('auth.password_reset', 'user', $user['id'], "{$user['full_name']} set a new password with an e-mailed link");
        });
        Session::regenerate();
        return null;
    }

    /** Links always use the configured SAQF_BASE_URL — never the request's Host header (reset-link poisoning). */
    private static function link(string $token): string
    {
        return Mailer::baseUrl() . '/reset.php?token=' . $token;
    }
}
