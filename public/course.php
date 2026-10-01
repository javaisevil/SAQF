<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Db;
use Saqf\Quality\Catalog;
use Saqf\Security\Authz;
use Saqf\Web\View as V;

$user = saqf_page(['faculty', 'hod', 'qa', 'dean', 'leadership']);
$id = (int) ($_GET['id'] ?? 0);
if (!Authz::canViewCourse($user, $id)) {
    Authz::deny('course #' . $id);
}
$c = Catalog::course($id);
$offerings = Db::all('SELECT o.id, t.name, u.full_name, o.status FROM course_offerings o JOIN terms t ON t.id = o.term_id LEFT JOIN users u ON u.id = o.instructor_id WHERE o.course_id = ? ORDER BY t.sequence DESC', [$id]);
V::header($c['code'] . ' — ' . $c['title'], $user, ['subtitle' => V::h($c['department_name']) . ' · ' . V::source('institution')]);
?>
<div class="grid g2">
<section class="card"><div class="card-h"><h2>Where this course sits</h2></div><div class="card-b tight"><table><thead><tr><th>Program</th><th>Type</th><th>Group</th><th>Level</th></tr></thead><tbody>
<?php foreach (Catalog::programsFor($id) as $p): ?><tr><td><a href="program.php?id=<?= (int) $p['program_id'] ?>"><?= V::h($p['code']) ?></a> <?= V::h($p['short_name']) ?></td><td><?= V::pill($p['course_type'], $p['course_type'] === 'required' ? 'blue' : 'grey') ?></td><td class="small"><?= V::h($p['requirement_group']) ?></td><td><?= $p['level_no'] ? (int) $p['level_no'] : '—' ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<section class="card"><div class="card-h"><h2>Prerequisites (per program)</h2></div><div class="card-b small">
<?php foreach (Catalog::requisitesFor($id) as $r): ?><div><?= V::h($r['program_code']) ?>: <?= V::h($r['code'] . ' ' . $r['title']) ?> <?= $r['kind'] === 'corequisite' ? '(co-requisite)' : '' ?></div><?php endforeach; ?>
<p class="muted" style="margin-top:8px">Required by: <?= V::h(implode(', ', array_column(Catalog::dependents($id), 'code')) ?: 'no other course') ?></p></div></section>
<section class="card"><div class="card-h"><h2>Offerings</h2></div><div class="card-b tight"><table><tbody><?php foreach ($offerings as $o): ?><tr><td><?= V::h($o['name']) ?></td><td class="small"><?= V::h($o['full_name'] ?? '—') ?></td><td><?= V::pill(str_replace('_', ' ', $o['status']), 'grey') ?></td><td class="num"><a href="workspace.php?id=<?= (int) $o['id'] ?>">Workspace</a></td></tr><?php endforeach; ?><?= $offerings ? '' : '<tr><td class="muted">Not offered in SAQF yet.</td></tr>' ?></tbody></table></div></section>
<section class="card"><div class="card-h"><h2>Catalog description</h2></div><div class="card-b small"><?= $c['description'] ? V::h($c['description']) . '<div class="tiny muted">Source: ' . V::h($c['description_source']) . '</div>' : '<span class="muted">Not in the synced snapshot.</span>' ?></div></section>
</div>
<?php V::footer();
