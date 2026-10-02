<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Csrf;
use Saqf\Core\Db;
use Saqf\Core\Session;
use Saqf\Core\Throttle;
use Saqf\Quality\SpecImport;
use Saqf\Web\View as V;

// Bulk import of existing course specifications: Quality (any course) or a Head of Department
// (courses owned by their department). Administrators cannot import academic content.
$user = saqf_page(['qa', 'hod']);
$scope = $user['role'] === 'hod' ? array_map('intval', Db::col('SELECT id FROM courses WHERE owner_department_id = ?', [$user['department_id']])) : null;

if (($_GET['template'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="saqf-specifications-template.csv"');
    echo SpecImport::template();
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    saqf_require_post();
    try {
        Throttle::check('spec-import:' . $user['id'], 20, 3600, 'Too many imports in the last hour. Please try again later.');
        $f = $_FILES['specs'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5 * 1024 * 1024) {
            throw new InvalidArgumentException('Upload a CSV file under 5 MB.');
        }
        $r = SpecImport::run($f['tmp_name'], (string) ($_POST['mode'] ?? ''), trim((string) ($_POST['reason'] ?? '')), $scope);
        $_SESSION['spec_import'] = $r;
        Session::flash($r['imported'] ? 'success' : 'error', count($r['imported']) . ' course specification(s) imported' . ($r['mode'] === 'baseline' ? ' as approved baselines' : ' as drafts for the instructors') . ($r['skipped'] ? '; ' . $r['skipped'] . ' course(s) skipped — see the list below.' : '.'));
    } catch (InvalidArgumentException | RuntimeException $e) {
        Session::flash('error', $e->getMessage());
    }
    saqf_redirect('spec_import.php');
}

$last = $_SESSION['spec_import'] ?? null;
unset($_SESSION['spec_import']);
$recent = Db::all('SELECT * FROM audit_log WHERE action = "spec.imported" ORDER BY id DESC LIMIT 8');
V::header('Import specifications', $user, ['subtitle' => 'Bring the university\'s existing approved course specifications into SAQF in one step']);
?>
<?php if ($last && ($last['imported'] || $last['errors'])): ?>
<section class="card" style="margin-bottom:16px"><div class="card-h"><h2>Import result</h2></div><div class="card-b small">
  <?php if ($last['imported']): ?><p><strong>Imported:</strong> <?= V::h(implode(', ', $last['imported'])) ?>. The rules engine has already checked them; any weakness is now a finding for the instructor.</p><?php endif; ?>
  <?php if ($last['errors']): ?><p><strong>Not imported</strong> (nothing was written for these courses):</p><ul><?php foreach ($last['errors'] as $e): ?><li><?= V::h($e) ?></li><?php endforeach; ?></ul><?php endif; ?>
</div></section>
<?php endif; ?>
<div class="split">
  <section class="card"><div class="card-h"><h2>Upload a specifications file</h2><a class="btn btn-sm right" href="spec_import.php?template=csv">Download the template</a></div><div class="card-b">
    <form method="post" enctype="multipart/form-data"><?= Csrf::field() ?>
      <div class="field"><label>Specifications file (CSV, UTF-8 — Excel: Save as “CSV UTF-8”)</label><input type="file" name="specs" accept=".csv,text/csv" required></div>
      <div class="field"><label>Import as</label>
        <label class="inline" style="font-weight:400"><input type="radio" name="mode" value="baseline" checked> Approved baselines — these specifications were already approved before SAQF</label><br>
        <label class="inline" style="font-weight:400"><input type="radio" name="mode" value="draft"> Drafts — each course coordinator reviews and submits through the normal workflow</label></div>
      <div class="field"><label>Reason / approval reference (audited)</label><input type="text" name="reason" required minlength="5" placeholder="e.g. Department council approval 2025-14"></div>
      <button class="btn btn-primary" type="submit">Import</button>
    </form></div></section>
  <aside class="stack">
    <section class="card"><div class="card-h"><h2>File format</h2></div><div class="card-b small">
      <p>One row per item: <span class="mono"><?= V::h(implode(',', SpecImport::COLUMNS)) ?></span></p>
      <table><tbody>
        <tr><td class="mono">clo</td><td>code (CLO1…), statement, domain (Knowledge and Understanding / Skills / Values…), optional target %, PLO codes (<span class="mono">SO1;SO2</span> or <span class="mono">SWE:SO1</span>)</td></tr>
        <tr><td class="mono">assessment</td><td>name, type (quiz, midterm, project…), weight %, week, CLO codes measured</td></tr>
        <tr><td class="mono">topic</td><td>topic, contact hours</td></tr>
        <tr><td class="mono">resource</td><td>reference, category (essential, supportive, electronic, facility)</td></tr>
        <tr><td class="mono">objectives · strategies</td><td>text</td></tr>
      </tbody></table>
      <p class="tiny muted" style="margin-top:8px">Every course is checked completely before anything is written; a course with an error is skipped with line numbers. PLOs must belong to a program whose study plan contains the course. <?= $scope !== null ? 'You can import courses owned by your department.' : 'Quality can import any course.' ?></p></div></section>
    <?php if ($recent): ?><section class="card"><div class="card-h"><h2>Recent imports</h2></div><div class="card-b small"><?php foreach ($recent as $r): ?><div style="margin-bottom:8px"><?= V::h(V::date($r['occurred_at'], 'j M Y H:i')) ?> · <?= V::h($r['actor_name']) ?><br><span class="muted"><?= V::h($r['summary']) ?></span></div><?php endforeach; ?></div></section><?php endif; ?>
  </aside>
</div>
<?php V::footer();
