<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Audit;
use Saqf\Core\Csrf;
use Saqf\Core\Db;
use Saqf\Core\Session;
use Saqf\Core\Throttle;
use Saqf\Core\Translations;
use Saqf\Web\View as V;

// Arabic wording for university data and course content. Quality can word anything; IT can word
// catalogue data (names of courses, programs, departments) and people's names, not course content.
$user = saqf_page(['qa', 'admin']);
$kinds = $user['role'] === 'qa' ? Translations::KINDS : array_intersect_key(Translations::KINDS, ['catalogue' => 1, 'people' => 1]);
$kind = isset($kinds[$_GET['kind'] ?? '']) ? $_GET['kind'] : (string) array_key_first($kinds);

if (($_GET['export'] ?? '') === 'csv') {
    $onlyMissing = ($_GET['only'] ?? '') === 'missing';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="saqf-arabic-' . $kind . ($onlyMissing ? '-missing' : '') . '.csv"');
    Audit::record('translation.exported', 'translation', $kind, 'Arabic wording exported (' . $kind . ')');
    echo Translations::csv($kind, $onlyMissing);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    saqf_require_post();
    try {
        Throttle::check('translations:' . $user['id'], 120, 3600, 'Too many saves in the last hour. Please try again later.');
        $op = (string) ($_POST['op'] ?? '');
        if ($op === 'save') {
            $saved = 0;
            foreach ((array) ($_POST['ar'] ?? []) as $i => $arabic) {
                $english = (string) ($_POST['en'][$i] ?? '');
                $arabic = trim((string) $arabic);
                if ($english === '' || $arabic === '') {
                    continue;
                }
                if (!preg_match('/\p{Arabic}/u', $arabic)) {
                    throw new InvalidArgumentException('“' . mb_strimwidth($english, 0, 60, '…') . '”: the Arabic box has no Arabic text.');
                }
                if (Translations::set($english, $arabic, $kind, $user['id'])) {
                    $saved++;
                }
            }
            if ($saved) {
                Audit::record('translation.saved', 'translation', $kind, $saved . ' Arabic wording(s) saved (' . $kind . ')');
            }
            Session::flash($saved ? 'success' : 'error', $saved ? ($saved === 1 ? 'Saved 1 Arabic wording.' : "Saved $saved Arabic wordings.") : 'Nothing to save: fill in at least one Arabic box.');
        } elseif ($op === 'remove') {
            $english = (string) ($_POST['english'] ?? '');
            if (Translations::set($english, '', $kind, $user['id'])) {
                Audit::record('translation.removed', 'translation', $kind, 'Arabic wording removed', ['english' => $english], null);
                Session::flash('success', 'Removed. The English is shown until new Arabic is saved.');
            }
        } elseif ($op === 'import') {
            $f = $_FILES['file'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5 * 1024 * 1024) {
                throw new InvalidArgumentException('Upload a CSV file under 5 MB.');
            }
            $r = Translations::importCsv($f['tmp_name'], $user['id'], array_keys($kinds));
            Audit::record('translation.imported', 'translation', $kind, $r['saved'] . ' Arabic wording(s) imported from a file', null, ['saved' => $r['saved'], 'skipped' => $r['skipped'], 'errors' => count($r['errors'])]);
            $_SESSION['translation_import'] = $r;
            Session::flash($r['saved'] ? 'success' : 'error', $r['saved'] === 1 ? 'Imported 1 Arabic wording.' : 'Imported ' . $r['saved'] . ' Arabic wordings.');
        }
    } catch (InvalidArgumentException | RuntimeException $e) {
        Session::flash('error', $e->getMessage());
    }
    saqf_redirect('translations.php?kind=' . urlencode($kind));
}

$import = $_SESSION['translation_import'] ?? null;
unset($_SESSION['translation_import']);
$coverage = array_intersect_key(Translations::coverage(), $kinds);
$missing = Translations::missing($kind, 60);
$q = trim((string) ($_GET['q'] ?? ''));
$done = Db::all('SELECT t.source_text, t.text, t.updated_at, u.full_name FROM translations t LEFT JOIN users u ON u.id = t.updated_by WHERE t.lang = "ar" AND t.kind = ?'
    . ($q !== '' ? ' AND (t.source_text LIKE ? OR t.text LIKE ?)' : '') . ' ORDER BY t.updated_at DESC LIMIT 40', $q !== '' ? [$kind, "%$q%", "%$q%"] : [$kind]);
$help = [
    'content' => 'Learning outcomes, assessments, topics and other course content. Instructors can also type the Arabic when they write an outcome.',
    'catalogue' => 'Names of courses, programs, departments and colleges, and program outcomes. These normally arrive from the Registrar; a correction made here is kept even when the Registrar data is updated.',
    'people' => 'People\'s names as they should appear in Arabic.',
];
V::header('Arabic wording', $user, ['subtitle' => 'The Arabic interface shows these instead of the English; the Arabic Word documents use them too']);
?>
<div class="grid g3" style="margin-bottom:16px">
  <?php foreach ($coverage as $k => $c): $total = $c['done'] + $c['missing']; ?>
    <a class="card kpi <?= $k === $kind ? 'tone-blue' : '' ?>" href="translations.php?kind=<?= $k ?>"><div class="kpi-v"><?= $total ? V::pct($c['done'] / $total * 100) : '—' ?></div><div class="kpi-l"><?= V::h(Translations::KINDS[$k]) ?></div><div class="kpi-d"><?= $c['missing'] ? V::h($c['missing'] === 1 ? '1 still in English' : $c['missing'] . ' still in English') : V::h('All in Arabic') ?></div></a>
  <?php endforeach; ?>
</div>
<?php if ($import && $import['errors']): ?>
<section class="card" style="margin-bottom:16px;border-color:#F6DFC3"><div class="card-h"><h2>Rows not imported</h2></div><div class="card-b small"><ul><?php foreach (array_slice($import['errors'], 0, 30) as $e): ?><li><?= V::h($e) ?></li><?php endforeach; ?></ul></div></section>
<?php endif; ?>
<div class="split">
  <div class="stack">
    <section class="card"><div class="card-h"><h2>Still in English</h2><span class="muted small right"><?= V::h($help[$kind]) ?></span></div>
      <?php if (!$missing): ?><div class="allclear"><strong>✓</strong><div><strong>Everything here has Arabic wording.</strong><div class="muted small">New text appears here by itself as it is added.</div></div></div>
      <?php else: ?>
      <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="save">
        <div class="card-b tight"><div class="table-wrap"><table><thead><tr><th style="width:46%">English</th><th>Arabic</th></tr></thead><tbody>
        <?php foreach ($missing as $i => $m): ?>
          <tr><td class="small"><span lang="en" dir="ltr" translate="no"><?= V::h($m['english']) ?></span><div class="tiny muted"><?= V::h($m['where']) ?></div><input type="hidden" name="en[<?= $i ?>]" value="<?= V::h($m['english']) ?>"></td>
            <td><textarea name="ar[<?= $i ?>]" dir="rtl" lang="ar" rows="<?= mb_strlen($m['english']) > 80 ? 3 : 1 ?>"></textarea></td></tr>
        <?php endforeach; ?>
        </tbody></table></div></div>
        <div class="card-b row"><button class="btn btn-primary btn-sm" type="submit">Save the Arabic</button><span class="muted small">Empty boxes are skipped; save as you go.</span></div>
      </form>
      <?php endif; ?>
    </section>
    <section class="card"><div class="card-h"><h2>Already in Arabic</h2><form class="row right" method="get"><input type="hidden" name="kind" value="<?= V::h($kind) ?>"><input type="search" name="q" value="<?= V::h($q) ?>" placeholder="Find (English or Arabic)" style="width:220px"><button class="btn btn-sm">Find</button></form></div>
      <div class="card-b tight"><div class="table-wrap"><table><tbody>
      <?php foreach ($done as $d): ?>
        <tr><td class="small" style="width:42%"><span lang="en" dir="ltr" translate="no"><?= V::h(mb_strimwidth($d['source_text'], 0, 160, '…')) ?></span></td><td class="small" dir="rtl" lang="ar"><?= V::h($d['text']) ?></td>
          <td class="tiny muted nowrap"><?= $d['full_name'] ? V::h($d['full_name']) : V::h($kind === 'catalogue' ? 'Registrar' : 'Imported') ?></td>
          <td class="num"><form method="post" data-confirm="Remove this Arabic wording? The English will show until new Arabic is saved."><?= Csrf::field() ?><input type="hidden" name="op" value="remove"><input type="hidden" name="english" value="<?= V::h($d['source_text']) ?>"><button class="btn btn-sm btn-ghost" title="Remove">✕</button></form></td></tr>
      <?php endforeach; ?>
      <?php if (!$done): ?><tr><td><?= V::empty($q === '' ? 'Nothing yet' : 'No matches') ?></td></tr><?php endif; ?>
      </tbody></table></div></div></section>
  </div>
  <aside class="stack">
    <section class="card"><div class="card-h"><h2>Many at once</h2></div><div class="card-b small">
      <p>Download a spreadsheet, fill in the Arabic column (in Excel or with a translator), then upload it.</p>
      <div class="row"><a class="btn btn-sm" href="translations.php?kind=<?= V::h($kind) ?>&export=csv&only=missing">Download what is missing</a><a class="btn btn-sm btn-ghost" href="translations.php?kind=<?= V::h($kind) ?>&export=csv">Download everything</a></div>
      <form method="post" enctype="multipart/form-data" style="margin-top:12px"><?= Csrf::field() ?><input type="hidden" name="op" value="import">
        <div class="field"><label>Upload the filled-in file (CSV)</label><input type="file" name="file" accept=".csv,text/csv" required></div>
        <button class="btn btn-sm btn-primary" type="submit">Upload</button></form>
      <p class="tiny muted" style="margin-top:8px">Excel: save as “CSV UTF-8”. Rows without Arabic are skipped; nothing in English is changed.</p><p class="tiny muted" translate="no">Columns: <span class="mono"><?= V::h(implode(', ', Translations::CSV_COLUMNS)) ?></span></p>
    </div></section>
    <section class="card"><div class="card-h"><h2>Where Arabic comes from</h2></div><div class="card-b small">
      <p><?= V::source('institution') ?> Course, program and department names from the Registrar, updated by itself.</p>
      <p><?= V::source('faculty') ?> Instructors, when they write a learning outcome.</p>
      <p style="margin:0">This page, for anything still missing or to correct a wording. Every change is recorded in the activity log.</p>
    </div></section>
  </aside>
</div>
<?php V::footer();
