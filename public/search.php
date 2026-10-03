<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Quality\Search;
use Saqf\Security\Auth;
use Saqf\Web\View as V;

$user = Auth::user();
$q = trim((string) ($_GET['q'] ?? ''));
if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(\Saqf\Web\I18n::json($user ? Search::run($user, $q) : []), JSON_UNESCAPED_UNICODE);
    exit;
}
$user = saqf_page(['faculty', 'hod', 'qa', 'dean', 'leadership', 'admin']);
$rows = Search::run($user, $q);
V::header('Search', $user, ['subtitle' => 'Results are limited to what your role may see']);
?>
<section class="card"><div class="card-b tight"><table><tbody>
<?php foreach ($rows as $r): ?><tr><td style="width:90px"><?= V::pill($r['type'], 'grey') ?></td><td><a class="strong" href="<?= V::h($r['link']) ?>"><?= V::h($r['title']) ?></a><div class="small muted"><?= V::h($r['sub']) ?></div></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td><?= V::empty($q === '' ? 'Type at least two characters' : 'No matches for “' . $q . '”') ?></td></tr><?php endif; ?>
</tbody></table></div></section>
<?php V::footer();
