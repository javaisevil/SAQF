<?php
declare(strict_types=1);

namespace Saqf\Security;

use Saqf\Core\Alerts;
use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Config;
use Saqf\Core\Db;
use Saqf\Core\Mailer;
use Saqf\Integration\Http;

/**
 * Audit-chain witnesses. The hash chain (Audit) shows that rows were edited or deleted, but someone
 * with database administrator rights could rewrite the whole chain consistently. A witness is a
 * checkpoint (last entry id, number of entries, hash of the last entry) that is also SENT OUTSIDE the
 * server by e-mail and/or the alert webhook. Anyone holding a witness can later prove that history up to
 * that point is unchanged (`php bin/verify_audit.php --witness "<line>"`, or Administration → Activity
 * log); a rewritten database cannot rewrite messages IT has already received.
 *
 * What it does not do: it does not stop tampering, and it only protects history up to the checkpoint.
 * Its value depends on IT actually keeping the messages (a mailbox rule or a channel with retention).
 *
 * Line format:  SAQF-WITNESS/1 <instance> <UTC time> id=<last id> entries=<count> sha256=<hash>
 */
final class Witness
{
    private const PATTERN = '/^SAQF-WITNESS\/1 (\S+) (\S+) id=(\d+) entries=(\d+) sha256=([0-9a-f]{64})$/';

    /** Short, stable name of this installation inside witness lines (reveals no host name). */
    public static function instance(): string
    {
        $seed = (string) Db::val('SELECT value FROM system_settings WHERE setting_key = "installed_at"') . '|' . (string) Config::get('SAQF_DB_NAME', 'saqf');
        return substr(hash('sha256', $seed), 0, 10);
    }

    /** @return array{last_id:int,entries:int,hash:string}|null current head of the chain */
    public static function head(): ?array
    {
        $r = Db::one('SELECT id, hash FROM audit_log ORDER BY id DESC LIMIT 1');
        if (!$r) {
            return null;
        }
        return ['last_id' => (int) $r['id'], 'entries' => (int) Db::val('SELECT COUNT(*) FROM audit_log'), 'hash' => (string) $r['hash']];
    }

    /**
     * Takes a witness: records it, sends it to IT outside the server when e-mail or a webhook is
     * configured, and audits it. @return array{id:int,line:string,sent_to:string}|null
     */
    public static function take(string $by = 'scheduler'): ?array
    {
        $h = self::head();
        if ($h === null) {
            return null;
        }
        $line = sprintf('SAQF-WITNESS/1 %s %s id=%d entries=%d sha256=%s', self::instance(), gmdate('Y-m-d\TH:i:s\Z'), $h['last_id'], $h['entries'], $h['hash']);
        $sent = [];
        $subject = 'SAQF audit-chain witness ' . gmdate('Y-m-d');
        $body = "Keep this message (a mailbox rule or a channel with retention works).\n\n$line\n\n"
            . "It proves that SAQF's activity log up to entry {$h['last_id']} was unchanged at the time above.\n"
            . "Verify later with:  php bin/verify_audit.php --witness \"$line\"\n"
            . "or Administration → Activity log → Verify a witness.\n";
        $mailed = false;
        foreach (self::recipients() as [$email, $name]) {
            $mailed = Mailer::queue($email, $name, $subject, $body, 'audit_witness') !== null || $mailed;
        }
        if ($mailed) {
            $sent[] = 'e-mail';
        }
        $hook = (string) Config::get('SAQF_ALERT_WEBHOOK', '');
        if ($hook !== '') {
            try {
                Http::request('POST', $hook, ['Content-Type' => 'application/json'], (string) json_encode(['text' => "SAQF audit-chain witness\n$line"]), 10);
                $sent[] = 'webhook';
            } catch (\Throwable $e) {
                // The witness is still stored locally; the missing external copy is reported below.
            }
        }
        $id = Db::insert('audit_witnesses', ['taken_at' => Clock::stamp(), 'last_id' => $h['last_id'], 'entries' => $h['entries'], 'head_hash' => $h['hash'], 'line' => $line, 'sent_to' => implode(', ', $sent), 'taken_by' => mb_substr($by, 0, 80)]);
        Audit::asSystem(static fn() => Audit::record('audit.witnessed', 'audit_log', $id, 'Audit chain witnessed at entry ' . $h['last_id'] . ' (' . ($sent ? 'sent by ' . implode(' and ', $sent) : 'NOT sent outside the server: no e-mail or webhook is configured') . ')'));
        return ['id' => $id, 'line' => $line, 'sent_to' => implode(', ', $sent)];
    }

    /** @return list<array{0:string,1:?string}> administrators' addresses plus SAQF_WITNESS_EMAIL (comma separated) */
    private static function recipients(): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', (string) Config::get('SAQF_WITNESS_EMAIL', '')))) as $e) {
            $out[strtolower($e)] = [$e, null];
        }
        foreach (Db::all('SELECT email, full_name FROM users WHERE role = "admin" AND status = "active" AND email IS NOT NULL AND email <> ""') as $u) {
            $out[strtolower((string) $u['email'])] = [(string) $u['email'], (string) $u['full_name']];
        }
        return array_values($out);
    }

    /** Checks one witness line (stored or provided by IT) against the chain as it is now. @return array{ok:bool,message:string} */
    public static function verifyLine(string $line): array
    {
        if (!preg_match(self::PATTERN, trim($line), $m)) {
            return ['ok' => false, 'message' => 'That is not a SAQF witness line (expected: SAQF-WITNESS/1 <instance> <time> id=… entries=… sha256=…).'];
        }
        [, $instance, $time, $id, $entries, $hash] = $m;
        $row = Db::one('SELECT hash FROM audit_log WHERE id = ?', [(int) $id]);
        if (!$row) {
            return ['ok' => false, 'message' => "Entry #$id, witnessed at $time, no longer exists: the activity log has been truncated or replaced."];
        }
        if (!hash_equals($hash, (string) $row['hash'])) {
            return ['ok' => false, 'message' => "Entry #$id has a different hash than the one witnessed at $time: history up to that point was rewritten."];
        }
        $count = (int) Db::val('SELECT COUNT(*) FROM audit_log WHERE id <= ?', [(int) $id]);
        if ($count !== (int) $entries) {
            return ['ok' => false, 'message' => "Witnessed at $time, entries up to #$id were $entries, now $count: entries were removed or inserted."];
        }
        $other = $instance !== self::instance() ? ' (note: this witness names another installation, ' . $instance . ')' : '';
        return ['ok' => true, 'message' => "Entries up to #$id are unchanged since $time$other."];
    }

    /**
     * Checks every stored witness. @return array{checked:int,failed:list<array{id:int,taken_at:string,message:string}>}
     * (The stored copies live in the same database; the copies IT received by e-mail or webhook are the
     * ones an attacker cannot reach, and can be checked with verifyLine().)
     */
    public static function verifyStored(): array
    {
        $failed = [];
        $rows = Db::all('SELECT id, taken_at, line FROM audit_witnesses ORDER BY id');
        foreach ($rows as $r) {
            $v = self::verifyLine((string) $r['line']);
            if (!$v['ok']) {
                $failed[] = ['id' => (int) $r['id'], 'taken_at' => (string) $r['taken_at'], 'message' => $v['message']];
            }
        }
        return ['checked' => count($rows), 'failed' => $failed];
    }

    /** Nightly: verify stored witnesses (critical alert when history differs), then take a new one. */
    public static function nightly(): void
    {
        $v = self::verifyStored();
        $v['failed']
            ? Alerts::raise('audit.witness', 'critical', 'Audit history differs from a witnessed checkpoint', $v['failed'][0]['message'] . ' Compare with the witness messages IT received, restore the audit_log table from the last good backup and investigate database access.')
            : Alerts::resolve('audit.witness');
        self::take('scheduler');
    }

    /** @return list<array<string,mixed>> latest witnesses, newest first */
    public static function recent(int $n = 8): array
    {
        return Db::all('SELECT * FROM audit_witnesses ORDER BY id DESC LIMIT ' . max(1, min(50, $n)));
    }

    /** True when the last witness left the server (e-mail or webhook), per what was recorded. */
    public static function external(): ?bool
    {
        $r = Db::one('SELECT sent_to FROM audit_witnesses ORDER BY id DESC LIMIT 1');
        return $r === null ? null : $r['sent_to'] !== '';
    }
}
