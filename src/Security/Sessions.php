<?php
declare(strict_types=1);

namespace Saqf\Security;

use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Db;
use Saqf\Core\Mailer;
use Saqf\Core\Notify;
use Saqf\Core\Policy;
use Saqf\Core\Request;

/**
 * Registry of signed-in sessions. Each sign-in gets a random token (only its hash is stored), so a
 * person can see where they are signed in and end other sessions, a password change signs every
 * other session out, and an administrator can sign a compromised account out everywhere.
 * Sign-ins from a browser and network not seen for 90 days produce a "new sign-in" notice.
 */
final class Sessions
{
    public static function start(array $user, string $method): void
    {
        $token = bin2hex(random_bytes(32));
        $device = self::device();
        $known = (bool) Db::val('SELECT 1 FROM user_sessions WHERE user_id = ? AND device_hash = ? AND created_at >= ?', [$user['id'], $device, Clock::now()->modify('-90 days')->format('Y-m-d H:i:s')]);
        $first = !Db::val('SELECT 1 FROM user_sessions WHERE user_id = ?', [$user['id']]);
        Db::insert('user_sessions', [
            'token_hash' => hash('sha256', $token), 'user_id' => $user['id'], 'method' => $method, 'ip' => Request::ip(),
            'user_agent' => Request::userAgent(), 'device_hash' => $device, 'created_at' => Clock::stamp(), 'last_seen_at' => Clock::stamp(),
        ]);
        $_SESSION['sid'] = $token;
        if (!$known && !$first && $method !== 'demo' && Policy::get('security.new_device_alert')) {
            self::newDeviceNotice($user);
        }
    }

    /** True while the current session is registered and not ended. Updates last-seen once a minute. */
    public static function valid(int $userId): bool
    {
        $token = (string) ($_SESSION['sid'] ?? '');
        if ($token === '') {
            return false;
        }
        $row = Db::one('SELECT user_id, ended_at, last_seen_at FROM user_sessions WHERE token_hash = ?', [hash('sha256', $token)]);
        if (!$row || (int) $row['user_id'] !== $userId || $row['ended_at'] !== null) {
            return false;
        }
        if (time() - (int) ($_SESSION['sid_seen'] ?? 0) >= 60) {
            Db::update('user_sessions', ['last_seen_at' => Clock::stamp(), 'ip' => Request::ip()], 'token_hash = ?', [hash('sha256', $token)]);
            $_SESSION['sid_seen'] = time();
        }
        return true;
    }

    public static function currentHash(): ?string
    {
        return !empty($_SESSION['sid']) ? hash('sha256', (string) $_SESSION['sid']) : null;
    }

    public static function end(string $tokenHash, string $reason): void
    {
        Db::exec('UPDATE user_sessions SET ended_at = ?, end_reason = ? WHERE token_hash = ? AND ended_at IS NULL', [Clock::stamp(), mb_substr($reason, 0, 60), $tokenHash]);
    }

    /** Ends every session of a person except (optionally) the current one. @return int sessions ended */
    public static function endAll(int $userId, string $reason, bool $keepCurrent = false): int
    {
        $current = $keepCurrent ? self::currentHash() : null;
        return Db::exec('UPDATE user_sessions SET ended_at = ?, end_reason = ? WHERE user_id = ? AND ended_at IS NULL' . ($current ? ' AND token_hash <> ?' : ''), array_merge([Clock::stamp(), mb_substr($reason, 0, 60), $userId], $current ? [$current] : []));
    }

    /** @return list<array> recent sessions, open ones first */
    public static function forUser(int $userId, int $limit = 12): array
    {
        return Db::all('SELECT * FROM user_sessions WHERE user_id = ? ORDER BY ended_at IS NOT NULL, last_seen_at DESC LIMIT ' . max(1, $limit), [$userId]);
    }

    /** "Chrome on Windows" from a user-agent string. */
    public static function describe(string $ua): string
    {
        $browser = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/OPR\//', $ua) ? 'Opera' : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/Safari\//', $ua) ? 'Safari' : 'Browser'))));
        $os = preg_match('/iPhone|iPad/', $ua) ? 'iOS' : (preg_match('/Android/', $ua) ? 'Android' : (preg_match('/Windows/', $ua) ? 'Windows' : (preg_match('/Mac OS X|Macintosh/', $ua) ? 'macOS' : (preg_match('/Linux/', $ua) ? 'Linux' : 'unknown system'))));
        return $browser === 'Browser' && $os === 'unknown system' ? mb_strimwidth($ua ?: 'Unknown client', 0, 40, '…') : "$browser on $os";
    }

    /** Browser family + operating system + network (/24 for IPv4, /48 for IPv6). */
    private static function device(): string
    {
        $ip = Request::ip();
        $net = str_contains($ip, ':') ? implode(':', array_slice(explode(':', $ip), 0, 3)) : implode('.', array_slice(explode('.', $ip), 0, 3));
        return hash('sha256', self::describe(Request::userAgent()) . '|' . $net);
    }

    private static function newDeviceNotice(array $user): void
    {
        $where = self::describe(Request::userAgent()) . ', network ' . Request::ip();
        Notify::user((int) $user['id'], 'security', 'New sign-in to your SAQF account', "Signed in from $where. If this was not you, change your password and tell IT security.", 'account.php', 'new-device:' . $user['id'] . ':' . Clock::stamp());
        Audit::record('security.new_device', 'user', $user['id'], "{$user['full_name']} signed in from a new device or network ($where)");
        if (!empty($user['email']) && Mailer::enabled()) {
            Mailer::queue((string) $user['email'], (string) $user['full_name'], 'New sign-in to your SAQF account', implode("\n", [
                "Dear {$user['full_name']},", '',
                'Your SAQF account was just used from a browser or network it has not seen recently:', '',
                '  ' . $where . ' at ' . Clock::now()->format('j M Y H:i'), '',
                'If this was you, no action is needed. If not, change your password now and inform IT security; you can also sign out every other session under Account & security.',
            ]), 'security');
        }
    }
}
