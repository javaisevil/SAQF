<?php
declare(strict_types=1);

namespace Saqf\Quality;

use Saqf\Core\Db;

/**
 * Process metrics measured from SAQF's own records (no estimates):
 * how many approvals needed a human QA step, return rounds, cycle time,
 * and how much checking happened automatically versus reaching people.
 */
final class Metrics
{
    public static function workflow(?int $collegeId = null, ?int $departmentId = null): array
    {
        $where = 'sv.decided_at IS NOT NULL AND sv.status IN ("approved","superseded")';
        $params = [];
        if ($departmentId) {
            $where .= ' AND c.owner_department_id = ?';
            $params[] = $departmentId;
        } elseif ($collegeId) {
            $where .= ' AND d.college_id = ?';
            $params[] = $collegeId;
        }
        $rows = Db::all("SELECT sv.id, sv.decision_route, sv.submitted_at, sv.decided_at FROM spec_versions sv JOIN courses c ON c.id = sv.course_id JOIN departments d ON d.id = c.owner_department_id WHERE $where", $params);
        $routes = ['auto_minor' => 0, 'auto_green' => 0, 'qa' => 0, 'other' => 0];
        $hours = [];
        $returns = [];
        foreach ($rows as $r) {
            $routes[isset($routes[$r['decision_route']]) ? $r['decision_route'] : 'other']++;
            if ($r['submitted_at']) {
                $hours[] = max(0, (strtotime($r['decided_at']) - strtotime($r['submitted_at'])) / 3600);
            }
            $returns[] = (int) Db::val('SELECT COUNT(*) FROM audit_log WHERE action = "spec.returned" AND object_type = "spec_version" AND object_id = ?', [(string) $r['id']]);
        }
        sort($hours);
        $median = $hours ? $hours[(int) floor((count($hours) - 1) / 2)] : null;
        $n = count($rows);
        return [
            'decided' => $n,
            'routes' => $routes,
            'no_qa_step_pct' => $n ? round(($routes['auto_minor'] + $routes['auto_green']) / $n * 100) : null,
            'avg_return_rounds' => $returns ? round(array_sum($returns) / count($returns), 2) : null,
            'first_pass_pct' => $returns ? round(count(array_filter($returns, static fn($x) => $x === 0)) / count($returns) * 100) : null,
            'median_hours' => $median === null ? null : round($median, 1),
        ];
    }

    public static function automation(?string $since = null): array
    {
        $params = $since ? [$since] : [];
        $cond = $since ? ' WHERE created_at >= ?' : '';
        $checks = (int) Db::val('SELECT COALESCE(SUM(quantity),0) FROM automation_ledger WHERE kind = "check_run"' . ($since ? ' AND created_at >= ?' : ''), $params);
        $auto = (int) Db::val('SELECT COUNT(*) FROM findings WHERE status = "auto_resolved"' . ($since ? ' AND resolved_at >= ?' : ''), $params);
        $toPeople = (int) Db::val('SELECT COUNT(*) FROM findings WHERE owner_role IN ("qa","hod","dean") AND severity <> "info"' . ($since ? ' AND first_detected_at >= ?' : ''), $params);
        $totals = [];
        foreach (Db::all('SELECT kind, SUM(quantity) q FROM automation_ledger' . $cond . ' GROUP BY kind', $params) as $r) {
            $totals[$r['kind']] = (int) $r['q'];
        }
        return ['checks' => $checks, 'auto_resolved' => $auto, 'escalated' => $toPeople, 'ledger' => $totals];
    }
}
