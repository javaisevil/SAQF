<?php
declare(strict_types=1);

namespace Saqf\Security;

use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Db;
use Saqf\Core\Policy;
use Saqf\Core\Request;

/**
 * "Don't ask again on this browser": after a successful two-step sign-in a person may trust the browser
 * for auth.trusted_device_days days, so the next password sign-in there skips the code. The browser keeps
 * a random token in an HttpOnly cookie; SAQF keeps only its hash. Trust is tied to the browser family and
 * system, ends on expiry, when the person forgets the browser, when the password changes or when an
 * administrator resets their sign-in. Administrators are always asked for a code.
 */
final class TrustedDevices
{
    public const COOKIE = 'saqf_trusted';

    public static function allowed(array $user): bool
    {
        return (int) Policy::get('auth.trusted_device_days') > 0 && $user['role'] !== 'admin';
    }

    /** Remembers this browser for the person and sets the cookie. */
    public static function trust(array $user): void
    {
        if (!self::allowed($user)) {
            return;
        }
        $days = (int) Policy::get('auth.trusted_device_days');
        $token = bin2hex(random_bytes(32));
        $expires = Clock::now()->modify("+$days days");
        Db::insert('trusted_devices', [
            'user_id' => $user['id'], 'token_hash' => self::hash($token), 'description' => mb_substr(Sessions::describe(Request::userAgent()), 0, 80),
            'ip' => Request::ip(), 'created_at' => Clock::stamp(), 'expires_at' => $expires->format('Y-m-d H:i:s'),
        ]);
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie(self::COOKIE, $token, ['expires' => time() + $days * 86400, 'path' => '/', 'secure' => Request::isHttps(), 'httponly' => true, 'samesite' => 'Strict']);
        }
        Audit::record('security.device_trusted', 'user', $user['id'], "{$user['full_name']} trusted this browser for $days days (" . Sessions::describe(Request::userAgent()) . ')');
    }

    /** True when the browser presents a valid, unexpired trust token for this person. */
    public static function recognises(array $user, ?string $token = null): bool
    {
        $token ??= (string) ($_COOKIE[self::COOKIE] ?? '');
        if (!self::allowed($user) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }
        $row = Db::one('SELECT * FROM trusted_devices WHERE token_hash = ? AND user_id = ? AND revoked_at IS NULL AND expires_at > ?', [self::hash($token), $user['id'], Clock::stamp()]);
        if (!$row || $row['description'] !== mb_substr(Sessions::describe(Request::userAgent()), 0, 80)) {
            return false;
        }
        Db::update('trusted_devices', ['last_used_at' => Clock::stamp()], 'id = ?', [$row['id']]);
        return true;
    }

    /** @return list<array> the person's trusted browsers that still work */
    public static function forUser(int $userId): array
    {
        return Db::all('SELECT * FROM trusted_devices WHERE user_id = ? AND revoked_at IS NULL AND expires_at > ? ORDER BY created_at DESC', [$userId, Clock::stamp()]);
    }

    public static function forget(int $userId, int $id): bool
    {
        return Db::exec('UPDATE trusted_devices SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL', [Clock::stamp(), $id, $userId]) === 1;
    }

    /** @return int browsers forgotten */
    public static function forgetAll(int $userId): int
    {
        return Db::exec('UPDATE trusted_devices SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [Clock::stamp(), $userId]);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', 'saqf:trusted:' . $token);
    }
}
