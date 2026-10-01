<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Db;
use Saqf\Quality\Status;
use Saqf\Security\Authz;
use Saqf\Web\View as V;

$user = saqf_page(['hod', 'qa', 'dean', 'leadership']);
$p = Db::one('SELECT u.id, u.full_name, u.title, u.role, u.department_id, d.name AS dept, d.college_id FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.id = ? AND u.role = "faculty"', [(int) ($_GET['id'] ?? 0)]);
if (!$p || ($user['role'] === 'hod' && (int) $p['department_id'] !== $user['department_id']) || ($user['role'] === 'dean' && (int) $p['college_id'] !== $user['scope_college_id'])) {
    Authz::deny('faculty profile');
}
$offs = Db::all('SELECT o.*, c.code, c.title, t.name AS term_name, t.status AS term_status FROM course_offerings o JOIN courses c ON c.id = o.course_id JOIN terms t ON t.id = o.term_id WHERE o.instructor_id = ? ORDER BY t.sequence DESC, c.code', [$p['id']]);
V::header($p['full_name'], $user, ['subtitle' => V::h($p['title'] . ' · ' . $p['dept'])]);
?>
<section class="card"><div class="card-h"><h2>Teaching and quality records</h2></div><div class="card-b tight"><table><tbody>
<?php foreach ($offs as $o): $st = $o['term_status'] === 'closed' ? null : Status::forOffering($o); ?><tr><td><strong><?= V::h($o['code']) ?></strong> <?= V::h($o['title']) ?></td><td><?= V::h($o['term_name']) ?></td><td><?= $st ? V::pill($st['label'], $st['tone']) : V::pill('closed', 'grey') ?></td><td class="num"><a href="workspace.php?id=<?= (int) $o['id'] ?>">Workspace</a></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php V::footer();
