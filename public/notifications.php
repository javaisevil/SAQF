<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Db;
use Saqf\Web\View as V;

$user = saqf_page(['faculty', 'hod', 'qa', 'dean', 'leadership', 'admin']);
$rows = Db::all('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100', [$user['id']]);
V::header('Notifications', $user, ['subtitle' => 'Only things that need you; work SAQF finishes by itself never sends a notification']);
?>
<section class="card"><div class="card-h"><h2>Recent</h2><button class="btn btn-sm right" data-act="notifications_read">Mark all read</button></div>
<?php foreach ($rows as $n): ?><div class="check <?= $n['read_at'] ? 'sev-info' : 'sev-warning' ?>"><div class="check-ico"><?= $n['read_at'] ? '✓' : '•' ?></div><div style="flex:1"><a class="check-title" href="<?= V::h($n['link'] ?: '#') ?>"><?= V::h($n['title']) ?></a><div class="check-detail"><?= V::h($n['body']) ?></div><div class="tiny muted"><?= V::h(V::date($n['created_at'], 'j M Y H:i')) ?></div></div></div><?php endforeach; ?>
<?php if (!$rows): ?><?= V::empty('No notifications', 'You will be notified only when something needs your decision or input.') ?><?php endif; ?>
</section>
<?php V::footer();
