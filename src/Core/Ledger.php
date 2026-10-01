<?php
declare(strict_types=1);

namespace Saqf\Core;

/**
 * Automation ledger: factual counts of work SAQF did in the background
 * (fields populated, records inherited, checks run, calculations, routing).
 * Used for "automation transparency" — never converted into invented time savings.
 */
final class Ledger
{
    public const LABELS = [
        'field_populated' => 'fields populated from institutional data',
        'record_inherited' => 'records inherited from the approved baseline',
        'check_run' => 'deterministic quality checks run',
        'auto_resolved' => 'issues cleared automatically',
        'calculation' => 'achievement values calculated',
        'routed' => 'items routed to the right person',
        'evidence_linked' => 'evidence links assembled',
        'data_corrected' => 'values corrected from the authoritative source',
    ];

    public static function add(string $kind, int $quantity, ?int $offeringId = null, ?int $courseId = null, ?string $detail = null): void
    {
        if ($quantity <= 0) {
            return;
        }
        Db::insert('automation_ledger', [
            'offering_id' => $offeringId,
            'course_id' => $courseId,
            'kind' => $kind,
            'quantity' => $quantity,
            'detail' => $detail === null ? null : mb_substr($detail, 0, 300),
            'created_at' => Clock::stamp(),
        ]);
    }

    /** @return array<string,int> */
    public static function totals(?int $offeringId = null, ?string $since = null): array
    {
        $where = [];
        $params = [];
        if ($offeringId !== null) {
            $where[] = 'offering_id = ?';
            $params[] = $offeringId;
        }
        if ($since !== null) {
            $where[] = 'created_at >= ?';
            $params[] = $since;
        }
        $sql = 'SELECT kind, SUM(quantity) q FROM automation_ledger' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' GROUP BY kind';
        $out = [];
        foreach (Db::all($sql, $params) as $r) {
            $out[$r['kind']] = (int) $r['q'];
        }
        return $out;
    }
}
