<?php
declare(strict_types=1);

namespace Saqf\Quality;

use Saqf\Core\Db;
use Saqf\Security\Authz;

/** Global search across courses, programs, CLOs, PLOs, people and issues — always role-scoped. */
final class Search
{
    public static function run(array $user, string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $out = [];
        if ($user['role'] === 'admin') {
            foreach (Db::all('SELECT id, full_name, username, role FROM users WHERE full_name LIKE ? OR username LIKE ? LIMIT 10', [$like, $like]) as $u) {
                $out[] = ['type' => 'Person', 'title' => $u['full_name'], 'sub' => $u['username'] . ' · ' . $u['role'], 'link' => 'admin.php?tab=users&q=' . urlencode($u['username'])];
            }
            return $out;
        }
        [$cs, $cp] = Authz::courseScope($user, 'c');
        foreach (Db::all("SELECT c.id, c.code, c.title, (SELECT o.id FROM course_offerings o JOIN terms t ON t.id = o.term_id WHERE o.course_id = c.id" . ($user['role'] === 'faculty' ? ' AND o.instructor_id = ' . (int) $user['id'] : '') . " ORDER BY t.sequence DESC LIMIT 1) AS oid FROM courses c WHERE (c.code LIKE ? OR c.title LIKE ?) AND $cs ORDER BY c.code LIMIT 12", array_merge([$like, $like], $cp)) as $c) {
            $out[] = ['type' => 'Course', 'title' => $c['code'] . ' — ' . $c['title'], 'sub' => $c['oid'] ? 'Open latest workspace' : 'Catalog record', 'link' => $c['oid'] ? 'workspace.php?id=' . $c['oid'] : 'course.php?id=' . $c['id']];
        }
        [$ps, $pp] = Authz::programScope($user, 'p');
        foreach (Db::all("SELECT p.id, p.code, p.name FROM programs p WHERE (p.code LIKE ? OR p.name LIKE ?) AND $ps LIMIT 6", array_merge([$like, $like], $pp)) as $p) {
            $out[] = ['type' => 'Program', 'title' => $p['code'] . ' — ' . $p['name'], 'sub' => 'Program intelligence', 'link' => 'program.php?id=' . $p['id']];
        }
        foreach (Db::all("SELECT pl.code, pl.statement, p.id, p.code AS pcode FROM plos pl JOIN programs p ON p.id = pl.program_id WHERE pl.statement LIKE ? AND pl.status = 'approved' AND $ps LIMIT 6", array_merge([$like], $pp)) as $pl) {
            $out[] = ['type' => 'PLO', 'title' => $pl['pcode'] . ' ' . $pl['code'], 'sub' => mb_strimwidth($pl['statement'], 0, 110, '…'), 'link' => 'program.php?id=' . $pl['id'] . '#plos'];
        }
        foreach (Db::all("SELECT cl.code, cl.statement, c.code AS ccode, (SELECT o.id FROM course_offerings o JOIN terms t ON t.id = o.term_id WHERE o.course_id = c.id ORDER BY t.sequence DESC LIMIT 1) AS oid
                          FROM clos cl JOIN spec_versions sv ON sv.id = cl.spec_version_id AND sv.status IN ('approved','draft','pending_hod','pending_qa') JOIN courses c ON c.id = sv.course_id
                          WHERE cl.statement LIKE ? AND $cs LIMIT 8", array_merge([$like], $cp)) as $cl) {
            $out[] = ['type' => 'CLO', 'title' => $cl['ccode'] . ' ' . $cl['code'], 'sub' => mb_strimwidth($cl['statement'], 0, 110, '…'), 'link' => $cl['oid'] ? 'workspace.php?id=' . $cl['oid'] . '&tab=structure' : '#'];
        }
        if ($user['role'] !== 'faculty') {
            foreach (Db::all("SELECT u.id, u.full_name, u.title, d.name AS dept FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.role = 'faculty' AND u.full_name LIKE ? LIMIT 6", [$like]) as $u) {
                $out[] = ['type' => 'Faculty', 'title' => $u['full_name'], 'sub' => trim(($u['title'] ?? '') . ' · ' . ($u['dept'] ?? ''), ' ·'), 'link' => 'people.php?id=' . $u['id']];
            }
            $scopeSql = '1=1';
            $sp = [];
            if ($user['role'] === 'hod') {
                $scopeSql = 'f.department_id = ?';
                $sp = [$user['department_id']];
            } elseif ($user['role'] === 'dean') {
                $scopeSql = 'f.college_id = ?';
                $sp = [$user['scope_college_id']];
            }
            foreach (Db::all("SELECT f.id, f.title, f.rule_code FROM findings f WHERE f.status = 'open' AND f.title LIKE ? AND $scopeSql LIMIT 8", array_merge([$like], $sp)) as $f) {
                $out[] = ['type' => 'Issue', 'title' => $f['title'], 'sub' => $f['rule_code'], 'link' => 'exceptions.php?finding=' . $f['id']];
            }
        }
        return $out;
    }
}
