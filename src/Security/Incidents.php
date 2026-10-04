<?php
declare(strict_types=1);

namespace Saqf\Security;

use InvalidArgumentException;
use Saqf\Core\Alerts;
use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Db;

/**
 * Security incident register with a notification clock.
 *
 * Why: the Saudi Personal Data Protection Law is described in the sources we reviewed as requiring a
 * controller to notify the competent authority (SDAIA) within 72 hours of becoming aware of a personal
 * data breach likely to cause harm, and to inform affected people without undue delay. Whether and when
 * that duty applies to a given incident is a decision for the university's data protection officer and
 * legal counsel, not for SAQF. SAQF supplies the register, the clock, the reminders and the audit trail,
 * so that the decision is made in time and can be shown afterwards. It never decides, and never contacts
 * any authority or person by itself.
 */
final class Incidents
{
    public const CATEGORIES = [
        'unauthorized_access' => 'Unauthorised access or account compromise',
        'data_exposure' => 'Personal data exposed or sent to the wrong place',
        'malware' => 'Malware or ransomware',
        'availability' => 'Loss of availability or data',
        'integrity' => 'Records altered without authority',
        'other' => 'Other',
    ];
    public const SEVERITIES = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];
    public const STATUS = ['open' => 'Open', 'contained' => 'Contained', 'reported' => 'Reported', 'closed' => 'Closed'];
    /** Hours allowed to notify the authority, as described by the PDPL sources reviewed (confirm with counsel). */
    public const NOTIFY_HOURS = 72;

    public static function create(array $user, string $title, string $category, string $severity, bool $personalData, string $detectedAt, string $description, ?int $subjects): int
    {
        $title = trim($title);
        $description = trim($description);
        if (mb_strlen($title) < 5 || mb_strlen($description) < 20) {
            throw new InvalidArgumentException('Give the incident a short title and describe what happened (at least 20 characters).');
        }
        if (!isset(self::CATEGORIES[$category]) || !isset(self::SEVERITIES[$severity])) {
            throw new InvalidArgumentException('Choose the kind and the severity of the incident.');
        }
        $ts = strtotime($detectedAt);
        if ($ts === false || $ts > Clock::now()->getTimestamp() + 300) {
            throw new InvalidArgumentException('When it was detected must be a date and time that is not in the future.');
        }
        $id = Db::insert('security_incidents', [
            'title' => mb_substr($title, 0, 200), 'category' => $category, 'severity' => $severity, 'personal_data' => $personalData ? 1 : 0,
            'detected_at' => date('Y-m-d H:i:s', $ts), 'description' => mb_substr($description, 0, 6000), 'subjects_estimate' => $subjects !== null && $subjects >= 0 ? $subjects : null,
            'status' => 'open', 'created_by' => $user['id'], 'created_at' => Clock::stamp(),
        ]);
        self::event($id, 'opened', 'Incident registered by ' . $user['full_name'], $user['id']);
        Audit::record('incident.opened', 'incident', $id, "Security incident #$id registered ({$severity}" . ($personalData ? ', personal data involved' : '') . '): ' . mb_substr($title, 0, 120));
        self::watch();
        return $id;
    }

    /** @param string $action contained | reported | authority_notified | subjects_notified | note | closed */
    public static function update(array $user, int $id, string $action, string $note): void
    {
        $inc = Db::one('SELECT * FROM security_incidents WHERE id = ?', [$id]);
        if (!$inc) {
            throw new InvalidArgumentException('Incident not found.');
        }
        $note = trim($note);
        if ($inc['status'] === 'closed') {
            throw new InvalidArgumentException('A closed incident cannot be changed. Register a new incident if something new happens.');
        }
        $now = Clock::stamp();
        switch ($action) {
            case 'contained':
                Db::update('security_incidents', ['status' => 'contained'], 'id = ?', [$id]);
                break;
            case 'authority_notified':
                if (mb_strlen($note) < 5) {
                    throw new InvalidArgumentException('Record who notified the authority and how (a reference or the channel used).');
                }
                Db::update('security_incidents', ['authority_notified_at' => $now, 'status' => $inc['status'] === 'open' || $inc['status'] === 'contained' ? 'reported' : $inc['status']], 'id = ?', [$id]);
                break;
            case 'subjects_notified':
                if (mb_strlen($note) < 5) {
                    throw new InvalidArgumentException('Record how affected people were informed.');
                }
                Db::update('security_incidents', ['subjects_notified_at' => $now], 'id = ?', [$id]);
                break;
            case 'closed':
                if (mb_strlen($note) < 20) {
                    throw new InvalidArgumentException('Say how the incident ended and what was changed to prevent it (at least 20 characters).');
                }
                if ($inc['personal_data'] && $inc['authority_notified_at'] === null && mb_strlen($note) < 40) {
                    throw new InvalidArgumentException('This incident involved personal data and no notification is recorded. Explain why none was needed (for example the data protection officer decided it was unlikely to cause harm), in at least 40 characters.');
                }
                Db::update('security_incidents', ['status' => 'closed', 'closed_at' => $now, 'closure_note' => mb_substr($note, 0, 4000)], 'id = ?', [$id]);
                break;
            case 'note':
                if ($note === '') {
                    throw new InvalidArgumentException('Write the note.');
                }
                break;
            default:
                throw new InvalidArgumentException('Unknown action.');
        }
        self::event($id, $action, $note !== '' ? $note : null, $user['id']);
        Audit::record('incident.' . $action, 'incident', $id, "Security incident #$id: " . str_replace('_', ' ', $action) . " by {$user['full_name']}", null, null, $note !== '' ? mb_substr($note, 0, 300) : null);
        self::watch();
    }

    public static function event(int $id, string $kind, ?string $note, int $userId): void
    {
        Db::insert('incident_events', ['incident_id' => $id, 'kind' => $kind, 'note' => $note === null ? null : mb_substr($note, 0, 4000), 'user_id' => $userId, 'occurred_at' => Clock::stamp()]);
    }

    /** @return array{applies:bool,deadline:?string,hours_left:?float,state:string} state: not_applicable | done | open | due_soon | overdue */
    public static function clock(array $inc): array
    {
        if (!$inc['personal_data']) {
            return ['applies' => false, 'deadline' => null, 'hours_left' => null, 'state' => 'not_applicable'];
        }
        $deadline = strtotime((string) $inc['detected_at']) + self::NOTIFY_HOURS * 3600;
        if ($inc['authority_notified_at'] !== null) {
            return ['applies' => true, 'deadline' => date('Y-m-d H:i:s', $deadline), 'hours_left' => null, 'state' => 'done'];
        }
        $left = ($deadline - Clock::now()->getTimestamp()) / 3600;
        return ['applies' => true, 'deadline' => date('Y-m-d H:i:s', $deadline), 'hours_left' => round($left, 1), 'state' => $left < 0 ? 'overdue' : ($left <= 24 ? 'due_soon' : 'open')];
    }

    /** @return list<array<string,mixed>> incidents, open ones first */
    public static function all(): array
    {
        return Db::all('SELECT i.*, u.full_name AS reporter FROM security_incidents i JOIN users u ON u.id = i.created_by ORDER BY i.status = "closed", i.detected_at DESC');
    }

    /** @return list<array<string,mixed>> */
    public static function events(int $id): array
    {
        return Db::all('SELECT e.*, u.full_name FROM incident_events e JOIN users u ON u.id = e.user_id WHERE e.incident_id = ? ORDER BY e.id', [$id]);
    }

    /** Raises or clears the IT alerts for open incidents whose notification clock is running. Run on every scheduler tick. */
    public static function watch(): void
    {
        $overdue = [];
        $soon = [];
        foreach (Db::all('SELECT * FROM security_incidents WHERE status <> "closed" AND personal_data = 1 AND authority_notified_at IS NULL') as $i) {
            $c = self::clock($i);
            if ($c['state'] === 'overdue') {
                $overdue[] = '#' . $i['id'] . ' ' . $i['title'];
            } elseif ($c['state'] === 'due_soon') {
                $soon[] = '#' . $i['id'] . ' ' . $i['title'] . ' (' . $c['hours_left'] . ' h left)';
            }
        }
        $overdue
            ? Alerts::raise('incident.overdue', 'critical', 'A personal-data incident is past its notification window', implode('; ', $overdue) . '. The data protection officer must decide now whether the authority has to be notified, and record it in Security incidents.')
            : Alerts::resolve('incident.overdue');
        $soon
            ? Alerts::raise('incident.due_soon', 'warning', 'A personal-data incident is close to its notification deadline', implode('; ', $soon) . '. Ask the data protection officer to decide on notification and record it in Security incidents.')
            : Alerts::resolve('incident.due_soon');
    }
}
