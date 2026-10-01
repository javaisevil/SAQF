<?php
declare(strict_types=1);

namespace Saqf\Core;

use RuntimeException;
use Throwable;

/**
 * Outgoing e-mail. Messages are queued in mail_outbox and delivered by the scheduler (or
 * immediately for time-sensitive mail such as password resets), with retries and backoff.
 *
 *   SAQF_MAIL_TRANSPORT  smtp | log | off   (default: smtp when SAQF_MAIL_HOST is set, otherwise off)
 *   SAQF_MAIL_HOST, SAQF_MAIL_PORT (587), SAQF_MAIL_ENCRYPTION (tls | ssl | none),
 *   SAQF_MAIL_USERNAME, SAQF_MAIL_PASSWORD, SAQF_MAIL_FROM, SAQF_MAIL_FROM_NAME
 *   SAQF_BASE_URL        public address of SAQF, used for links in e-mails (https://saqf.example.edu)
 *
 * People are e-mailed only about notifications that need them (the same action-only rule as
 * the in-app bell), at most one digest per scheduler run, and only if they have not opted out.
 */
final class Mailer
{
    public const MAX_ATTEMPTS = 8;

    public static function transport(): string
    {
        $t = strtolower((string) Config::get('SAQF_MAIL_TRANSPORT', ''));
        if (in_array($t, ['smtp', 'log', 'off'], true)) {
            return $t;
        }
        return Config::get('SAQF_MAIL_HOST') ? 'smtp' : 'off';
    }

    public static function enabled(): bool
    {
        return self::transport() !== 'off';
    }

    public static function baseUrl(): string
    {
        return rtrim((string) Config::get('SAQF_BASE_URL', ''), '/');
    }

    public static function queue(string $email, ?string $name, string $subject, string $body, string $purpose): ?int
    {
        if (!self::enabled() || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $now = Clock::stamp();
        return Db::insert('mail_outbox', [
            'to_email' => mb_substr($email, 0, 160), 'to_name' => $name === null ? null : mb_substr($name, 0, 160),
            'subject' => mb_substr($subject, 0, 200), 'body' => $body, 'purpose' => $purpose,
            'created_at' => $now, 'next_attempt_at' => $now,
        ]);
    }

    /** Delivers due messages. @return int number sent */
    public static function flush(int $limit = 50, ?int $onlyId = null): int
    {
        if (!self::enabled()) {
            return 0;
        }
        $sql = 'SELECT * FROM mail_outbox WHERE sent_at IS NULL AND attempts < ? AND next_attempt_at <= ?' . ($onlyId ? ' AND id = ?' : '') . ' ORDER BY id LIMIT ' . max(1, $limit);
        $sent = 0;
        foreach (Db::all($sql, $onlyId ? [self::MAX_ATTEMPTS, Clock::stamp(), $onlyId] : [self::MAX_ATTEMPTS, Clock::stamp()]) as $m) {
            try {
                self::deliver($m['to_email'], $m['to_name'], $m['subject'], $m['body']);
                Db::update('mail_outbox', ['sent_at' => Clock::stamp(), 'attempts' => (int) $m['attempts'] + 1, 'last_error' => null], 'id = ?', [$m['id']]);
                $sent++;
            } catch (Throwable $e) {
                $attempts = (int) $m['attempts'] + 1;
                $retry = Clock::now()->modify('+' . (2 ** min($attempts, 10)) . ' minutes')->format('Y-m-d H:i:s');
                Db::update('mail_outbox', ['attempts' => $attempts, 'next_attempt_at' => $retry, 'last_error' => mb_substr($e->getMessage(), 0, 400)], 'id = ?', [$m['id']]);
                if ($attempts >= self::MAX_ATTEMPTS) {
                    ErrorLog::record(new RuntimeException("E-mail to {$m['to_email']} abandoned after $attempts attempts: " . $e->getMessage()), 'warning');
                }
            }
        }
        return $sent;
    }

    /** Sends one message now through the configured transport. */
    public static function deliver(string $email, ?string $name, string $subject, string $body): void
    {
        $from = (string) Config::get('SAQF_MAIL_FROM', '');
        $fromName = (string) (Config::get('SAQF_MAIL_FROM_NAME') ?: 'SAQF Academic Quality');
        if (self::transport() === 'log') {
            error_log("SAQF mail (log transport) to <$email> — $subject\n$body");
            return;
        }
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('SAQF_MAIL_FROM must be set to the sender address.');
        }
        $enc = strtolower((string) (Config::get('SAQF_MAIL_ENCRYPTION') ?: 'tls'));
        (new Smtp(
            (string) Config::get('SAQF_MAIL_HOST'),
            (int) (Config::get('SAQF_MAIL_PORT') ?: ($enc === 'ssl' ? 465 : 587)),
            in_array($enc, ['tls', 'ssl', 'none'], true) ? $enc : 'tls',
            (string) Config::get('SAQF_MAIL_USERNAME', ''),
            (string) Config::get('SAQF_MAIL_PASSWORD', '')
        ))->send($from, $fromName, $email, $name, $subject, $body);
    }

    /**
     * One e-mail per person summarising notifications they have not read or been e-mailed about.
     * @return int digests queued
     */
    public static function queueNotificationDigests(): int
    {
        $since = Clock::now()->modify('-14 days')->format('Y-m-d H:i:s');
        $rows = Db::all(
            'SELECT n.*, u.email, u.full_name FROM notifications n JOIN users u ON u.id = n.user_id
             WHERE n.emailed_at IS NULL AND n.read_at IS NULL AND n.created_at >= ? AND u.status <> "disabled" AND u.notify_email = 1 AND u.email IS NOT NULL AND u.email <> ""
             ORDER BY n.user_id, n.id',
            [$since]
        );
        $byUser = [];
        foreach ($rows as $r) {
            $byUser[(int) $r['user_id']][] = $r;
        }
        $base = self::baseUrl();
        $queued = 0;
        foreach ($byUser as $items) {
            $first = $items[0];
            $n = count($items);
            $subject = $n === 1 ? 'SAQF: ' . $first['title'] : "SAQF: $n items need your attention";
            $lines = ["Dear {$first['full_name']},", '', $n === 1 ? 'Something in SAQF needs your attention:' : "$n things in SAQF need your attention:", ''];
            foreach ($items as $i) {
                $lines[] = '• ' . $i['title'];
                if ($i['body']) {
                    $lines[] = '  ' . $i['body'];
                }
                if ($i['link'] && $base !== '') {
                    $lines[] = '  ' . $base . '/' . ltrim((string) $i['link'], '/');
                }
                $lines[] = '';
            }
            $lines[] = $base !== '' ? "Open SAQF: $base/" : 'Sign in to SAQF to act on these items.';
            $lines[] = '';
            $lines[] = 'SAQF only writes when a person needs to act. You can turn these e-mails off under Account & security.';
            if (self::queue((string) $first['email'], (string) $first['full_name'], $subject, implode("\n", $lines), 'digest')) {
                Db::exec('UPDATE notifications SET emailed_at = ? WHERE id IN (' . Db::in(array_column($items, 'id')) . ')', array_merge([Clock::stamp()], array_column($items, 'id')));
                $queued++;
            }
        }
        return $queued;
    }
}
