<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Db;
use Saqf\Core\Policy;
use Saqf\Security\Authz;
use Saqf\Web\View as V;

$user = saqf_page(['faculty', 'hod', 'qa', 'dean', 'leadership']);
[$ps, $pp] = Authz::programScope($user, 'p');
$programs = Db::all(
    "SELECT p.*, d.name AS department, c.name AS college, c.code AS college_code,
            (SELECT COUNT(*) FROM plos WHERE program_id = p.id AND status = 'approved') AS plos,
            (SELECT COUNT(*) FROM study_plan_entries WHERE program_id = p.id AND course_id IS NOT NULL AND course_type = 'required') AS required_courses,
            (SELECT COUNT(DISTINCT spe.course_id) FROM study_plan_entries spe WHERE spe.program_id = p.id AND spe.course_type = 'required' AND EXISTS (SELECT 1 FROM spec_versions sv WHERE sv.course_id = spe.course_id AND sv.status = 'approved')) AS with_spec,
            (SELECT COUNT(*) FROM findings f WHERE f.program_id = p.id AND f.status = 'open' AND f.severity <> 'info') AS issues
     FROM programs p JOIN departments d ON d.id = p.department_id JOIN colleges c ON c.id = d.college_id WHERE $ps ORDER BY c.name, p.level DESC, p.code",
    $pp
);
$target = Policy::get('plo.target_pct');
V::header('Programs', $user, ['subtitle' => count($programs) . ' program(s) in your scope · synced from the Registrar study plans']);
?>
<section class="card"><div class="card-b tight"><div class="table-wrap"><table>
<thead><tr><th>Program</th><th>College / department</th><th>Level</th><th class="num">PLOs</th><th>Required courses with approved spec</th><th>Below-target PLOs (latest term)</th><th>Issues</th></tr></thead><tbody>
<?php foreach ($programs as $p):
    $latest = Db::val('SELECT o.term_id FROM plo_achievement pa JOIN course_offerings o ON o.id = pa.offering_id JOIN terms t ON t.id = o.term_id WHERE pa.program_id = ? AND pa.provisional = 0 ORDER BY t.sequence DESC LIMIT 1', [$p['id']]);
    $below = $latest ? (int) Db::val('SELECT COUNT(*) FROM (SELECT pa.plo_id, AVG(pa.value_pct) v FROM plo_achievement pa JOIN course_offerings o ON o.id = pa.offering_id WHERE pa.program_id = ? AND o.term_id = ? AND pa.provisional = 0 GROUP BY pa.plo_id) x WHERE x.v < ?', [$p['id'], $latest, $target]) : null;
    $pct = $p['required_courses'] ? round($p['with_spec'] / $p['required_courses'] * 100) : 0; ?>
  <tr><td><a class="strong" href="program.php?id=<?= (int) $p['id'] ?>"><?= V::h($p['code']) ?></a> <?= V::h($p['short_name']) ?></td><td class="small"><?= V::h($p['college']) ?><div class="muted"><?= V::h($p['department']) ?></div></td><td><?= V::pill($p['level'], $p['level'] === 'Postgraduate' ? 'violet' : 'grey') ?></td>
    <td class="num"><?= (int) $p['plos'] ?: '<span style="color:var(--red)">0</span>' ?></td>
    <td><div class="bar <?= $pct >= 50 ? 'bar-ok' : 'bar-low' ?>" style="max-width:220px"><b style="width:<?= max(2, $pct) ?>%"></b><span><?= (int) $p['with_spec'] ?> / <?= (int) $p['required_courses'] ?></span></div></td>
    <td><?= $below === null ? '<span class="muted small">no results yet</span>' : ($below ? V::pill($below . ' below target', 'red') : V::pill('all at target', 'green')) ?></td>
    <td><?= (int) $p['issues'] ? V::pill($p['issues'] . ' open', 'amber') : '—' ?></td></tr>
<?php endforeach; ?></tbody></table></div></div></section>
<?php V::footer();
