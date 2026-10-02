<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Db;
use Saqf\Web\View as V;

$user = saqf_page(['qa', 'admin', 'leadership', 'dean', 'hod']);
$canEdit = $user['role'] === 'qa';
$rows = Db::all('SELECT qp.*, u.full_name FROM quality_policies qp LEFT JOIN users u ON u.id = qp.updated_by ORDER BY policy_key');
$groups = [];
foreach ($rows as $r) {
    $groups[strtok($r['policy_key'], '.')][] = $r;
}
$titles = ['achievement' => 'Achievement calculation', 'clo' => 'Outcome targets', 'plo' => 'Program outcomes', 'results' => 'Evidence', 'assessment' => 'Assessment rules', 'mapping' => 'Mapping rules', 'program' => 'Curriculum coverage', 'gap' => 'Gap detection', 'improvement' => 'Improvement loop', 'effect' => 'Effectiveness', 'spec' => 'Approval routing', 'qa' => 'QA sampling', 'contact' => 'Derived values', 'integration' => 'Integrations', 'auth' => 'Sign-in security', 'session' => 'Sessions'];
V::header('Quality policies', $user, ['subtitle' => $canEdit ? 'Configurable institutional policy — changes take effect immediately and are audited' : 'Read-only view — Quality Assurance owns these settings']);
?>
<div class="alert alert-info">Defaults are a starting configuration, not YU's approved methodology. Confirm the achievement method and thresholds with the Deanship of Quality before relying on them.</div>
<?php foreach ($groups as $g => $list): ?>
<section class="card"><div class="card-h"><h2><?= V::h($titles[$g] ?? ucfirst($g)) ?></h2></div><div class="card-b tight"><table><tbody>
  <?php foreach ($list as $p): ?>
  <tr><td style="width:40%"><strong><?= V::h($p['label']) ?></strong><div class="tiny muted"><?= V::h($p['help']) ?></div><div class="tiny mono muted"><?= V::h($p['policy_key']) ?></div></td>
    <td><?php if ($canEdit): ?><form data-api="policy_set" class="row"><input type="hidden" name="key" value="<?= V::h($p['policy_key']) ?>">
      <?php if ($p['value_type'] === 'bool'): ?><select name="v" style="width:auto"><option value="1" <?= $p['value'] === '1' ? 'selected' : '' ?>>On</option><option value="0" <?= $p['value'] === '0' ? 'selected' : '' ?>>Off</option></select>
      <?php elseif ($p['value_type'] === 'enum'): ?><select name="v" style="width:auto"><?php foreach (explode(',', (string) $p['options']) as $opt): ?><option <?= $p['value'] === $opt ? 'selected' : '' ?>><?= V::h($opt) ?></option><?php endforeach; ?></select>
      <?php else: ?><input type="text" name="v" value="<?= V::h($p['value']) ?>" style="width:110px"><?php endif; ?>
      <input type="text" name="reason" placeholder="Reason (audited)" style="width:220px"><button class="btn btn-sm" type="submit">Save</button></form>
    <?php else: ?><strong><?= V::h($p['value']) ?></strong><?php endif; ?></td>
    <td class="small muted" style="width:200px"><?= $p['updated_at'] ? 'Changed ' . V::h(V::date($p['updated_at'])) . ' by ' . V::h($p['full_name']) : 'Default' ?></td></tr>
  <?php endforeach; ?></tbody></table></div></section>
<?php endforeach;
V::footer();
