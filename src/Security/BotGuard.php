<?php
declare(strict_types=1);

namespace Saqf\Security;

use Saqf\Core\Clock;
use Saqf\Core\Config;
use Saqf\Core\Db;
use Saqf\Core\Request;
use Saqf\Core\Secrets;

/**
 * "I'm not a robot" check for the public forms (sign-in, forgotten password), self-hosted so it works on
 * a closed campus network and sends nothing to a third party (no tracking, no cookies, nothing to load).
 *
 * The server hands the form a signed puzzle; the browser must find a number whose SHA-256 hash, together
 * with the puzzle, starts with a given count of zero bits (proof of work, the approach of ALTCHA and
 * Friendly Captcha). A person's browser solves it in a fraction of a second while they type; a script
 * trying thousands of passwords must pay for every attempt, and each puzzle works once only. After
 * repeated failures from a network the puzzles become 16 times harder. A hidden "trap" field that people
 * never see catches simple form-filling bots.
 *
 *   SAQF_BOT_CHECK = on (default) | off
 */
final class BotGuard
{
    public const TRAP = 'website';
    public const BASE_BITS = 15;
    public const HARD_BITS = 19;
    private const LIFETIME = 900;

    public static function enabled(): bool
    {
        return Config::bool('SAQF_BOT_CHECK', true);
    }

    /** Puzzle difficulty for this network: harder after 3 failed attempts in 15 minutes. */
    public static function bits(): int
    {
        $since = Clock::now()->modify('-15 minutes')->format('Y-m-d H:i:s');
        $failed = (int) Db::val('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at >= ?', [Request::ip(), $since]);
        return $failed >= 3 ? self::HARD_BITS : self::BASE_BITS;
    }

    /** A signed, single-use puzzle for one form: "<form>.<expires>.<bits>.<salt>.<signature>". */
    public static function challenge(string $form): string
    {
        $payload = preg_replace('/[^a-z]/', '', $form) . '.' . (time() + self::LIFETIME) . '.' . self::bits() . '.' . bin2hex(random_bytes(12));
        return $payload . '.' . self::sign($payload);
    }

    /**
     * Checks a submitted form. @return string|null null when it passes, otherwise the reason
     * (bot_trap, bot_missing, bot_invalid, bot_expired, bot_unsolved, bot_reused) for the security log.
     */
    public static function verify(string $form, array $post): ?string
    {
        if (!self::enabled()) {
            return null;
        }
        if (trim((string) ($post[self::TRAP] ?? '')) !== '') {
            return 'bot_trap';
        }
        $challenge = (string) ($post['bot_challenge'] ?? '');
        $nonce = (string) ($post['bot_nonce'] ?? '');
        if ($challenge === '' || $nonce === '') {
            return 'bot_missing';
        }
        $parts = explode('.', $challenge);
        if (count($parts) !== 5 || !ctype_digit($nonce) || strlen($nonce) > 12) {
            return 'bot_invalid';
        }
        [$f, $expires, $bits, $salt, $sig] = $parts;
        if (!hash_equals(self::sign("$f.$expires.$bits.$salt"), $sig) || $f !== $form || !ctype_digit($bits)) {
            return 'bot_invalid';
        }
        if ((int) $expires < time()) {
            return 'bot_expired';
        }
        if ((int) $bits < self::bits() || !self::solves($salt, $nonce, (int) $bits)) {
            return 'bot_unsolved';
        }
        $used = Db::exec('INSERT IGNORE INTO bot_challenges_used (challenge_hash, expires_at) VALUES (?, ?)', [hash('sha256', $challenge), date('Y-m-d H:i:s', (int) $expires)]);
        return $used === 1 ? null : 'bot_reused';
    }

    /** True when sha256("<salt>:<nonce>") starts with $bits zero bits. */
    public static function solves(string $salt, string $nonce, int $bits): bool
    {
        $hash = hash('sha256', $salt . ':' . $nonce, true);
        $full = intdiv($bits, 8);
        for ($i = 0; $i < $full; $i++) {
            if ($hash[$i] !== "\0") {
                return false;
            }
        }
        $rest = $bits % 8;
        return $rest === 0 || (ord($hash[$full]) >> (8 - $rest)) === 0;
    }

    /** The widget: a checkbox-style box the page script ticks once the browser has solved the puzzle. */
    public static function field(string $form): string
    {
        if (!self::enabled()) {
            return '';
        }
        $c = htmlspecialchars(self::challenge($form), ENT_QUOTES, 'UTF-8');
        return '<div class="botcheck" data-botcheck>'
            . '<input type="hidden" name="bot_challenge" value="' . $c . '"><input type="hidden" name="bot_nonce" value="">'
            . '<button type="button" class="bc-box" aria-describedby="bc-status"><span class="bc-tick" aria-hidden="true"></span></button>'
            . '<div class="bc-text" id="bc-status" role="status" aria-live="polite">'
            . '<span class="bc-s bc-nojs">Turn on JavaScript to confirm you are not a robot.</span>'
            . '<span class="bc-s bc-idle">I\'m not a robot</span>'
            . '<span class="bc-s bc-working">Checking that you are human…</span>'
            . '<span class="bc-s bc-done">Verified: you are human</span>'
            . '<span class="bc-s bc-fail">The check could not finish. Reload the page and try again.</span></div>'
            . '<div class="bc-brand"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/></svg><span>Human check</span><small>private · no tracking</small></div>'
            . '</div>'
            . '<div class="hp" aria-hidden="true"><label>Leave this field empty <input type="text" name="' . self::TRAP . '" tabindex="-1" autocomplete="off"></label></div>';
    }

    /** Housekeeping: used puzzles are forgotten once they have expired anyway (scheduler, daily). */
    public static function prune(): int
    {
        return Db::exec('DELETE FROM bot_challenges_used WHERE expires_at < ?', [date('Y-m-d H:i:s')]);
    }

    private static function sign(string $payload): string
    {
        return substr(hash_hmac('sha256', 'saqf:botcheck:' . $payload, Secrets::appKey()), 0, 32);
    }
}
