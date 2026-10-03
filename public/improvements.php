<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Clock;
use Saqf\Core\Db;
use Saqf\Quality\Improvements;
use Saqf\Security\Authz;
use Saqf\Web\View as V;

$user = saqf_page(['faculty', 'hod', 'qa', 'dean', 'leadership']);
if (!empty($_GET['id'])) {
    $ia = Improvements::find((int) $_GET['id']);
    if (!$ia) {
        Authz::deny('improvement action', 404);
    }
    Authz::offering($user, (int) $ia['origin_offering_id']);
    saqf_redirect('workspace.php?id=' . (int) $ia['origin_offering_id'] . '&tab=improve#ia-' . (int) $ia['id']);
}
$status = in_array($_GET['status'] ?? '', ['draft', 'open', 'in_progress', 'completed', 'all'], true) ? $_GET['status'] : 'active';
[$cs, $cp] = Authz::courseScope($user, 'c');
$where = [$cs];
$params = $cp;
if ($user['role'] === 'faculty') {
    $where = ['(ia.owner_id = ? OR oo.instructor_id = ?)']; // coordinator or action owner
    $params = [$user['id'], $user['id']];
}
if ($status === 'active') {
    $where[] = 'ia.status IN ("draft","open","in_progress")';
} elseif ($status !== 'all') {
    $where[] = 'ia.status = ?';
    $params[] = $status;
}
$rows = Db::all(
    'SELECT ia.*, c.code, c.title AS course_title, u.full_name AS owner_name, t.name AS origin_term, ft.name AS followup_term
     FROM improvement_actions ia JOIN courses c ON c.id = ia.course_id JOIN course_offerings oo ON oo.id = ia.origin_offering_id JOIN terms t ON t.id = oo.term_id
     LEFT JOIN users u ON u.id = ia.owner_id LEFT JOIN course_offerings fo ON fo.id = ia.followup_offering_id LEFT JOIN terms ft ON ft.id = fo.term_id
     WHERE ' . implode(' AND ', $where) . ' ORDER BY FIELD(ia.status,"draft","open","in_progress","completed","cancelled"), ia.due_on',
    $params
);
$tones = ['draft' => 'red', 'open' => 'blue', 'in_progress' => 'blue', 'completed' => 'green', 'cancelled' => 'grey'];
V::header('Improvements', $user, ['subtitle' => 'When an outcome misses its goal, SAQF opens an improvement with an owner and a deadline, then checks next term whether results went up']);
?>
<nav class="tabs"><?php foreach (['active' => 'Current', 'draft' => 'Waiting for the instructor', 'completed' => 'Done', 'all' => 'All'] as $k => $l): ?><a class="<?= $status === $k ? 'on' : '' ?>" href="?status=<?= $k ?>"><?= $l ?></a><?php endforeach; ?></nav>
<section class="card"><div class="card-b tight"><div class="table-wrap"><table>
<thead><tr><th>Course</th><th>What will change</th><th>Who</th><th>By</th><th>Status</th><th>Did it help?</th></tr></thead><tbody>
<?php foreach ($rows as $ia): $overdue = in_array($ia['status'], ['open', 'in_progress'], true) && $ia['due_on'] && $ia['due_on'] < Clock::today(); ?>
  <tr><td><strong><?= V::h($ia['code']) ?></strong><div class="tiny muted"><?= V::h($ia['origin_term']) ?></div></td>
    <td><a href="improvements.php?id=<?= (int) $ia['id'] ?>"><?= V::h($ia['title']) ?></a><div class="tiny muted"><?= $ia['action_text'] ? V::h(mb_strimwidth((string) $ia['action_text'], 0, 120, '…')) : V::h('Not written yet') ?></div></td>
    <td class="small"><?= V::h($ia['owner_name'] ?? '—') ?></td><td class="small nowrap"><?= V::h(V::date($ia['due_on'])) ?><?= $overdue ? ' ' . V::pill('late', 'red') : '' ?></td>
    <td><?= V::pill(Improvements::STATUS[$ia['status']] ?? $ia['status'], $tones[$ia['status']] ?? 'grey') ?></td>
    <td class="small"><?php if ($ia['followup_pct'] !== null): ?><?= V::pct($ia['baseline_pct']) ?> → <strong><?= V::pct($ia['followup_pct']) ?></strong> <?= V::pill(['improved' => 'Went up', 'declined' => 'Went down', 'similar' => 'About the same'][$ia['effect']] ?? ucfirst((string) $ia['effect']), ['improved' => 'green', 'declined' => 'red', 'similar' => 'amber'][$ia['effect']] ?? 'grey') ?><div class="tiny muted"><?= V::h($ia['followup_term']) ?></div><?php else: ?><span class="muted"><?= V::h('now ' . V::pct($ia['baseline_pct'])) ?> · <?= V::h('results next term') ?></span><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6"><?= V::empty('No improvements here') ?></td></tr><?php endif; ?>
</tbody></table></div></div></section>
<?php V::footer();
