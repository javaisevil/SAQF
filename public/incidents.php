<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

use Saqf\Core\Clock;
use Saqf\Core\Csrf;
use Saqf\Core\Session;
use Saqf\Security\Incidents;
use Saqf\Web\View as V;

// Security incident register with a notification clock. The register records and reminds; the decisions
// (is a notification needed, to whom, when) belong to people. Administrators only; every action is audited.
$user = saqf_page(['admin']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    saqf_require_post();
    try {
        if (($_POST['op'] ?? '') === 'open') {
            $subjects = trim((string) ($_POST['subjects'] ?? '')) === '' ? null : (int) $_POST['subjects'];
            $id = Incidents::create($user, (string) ($_POST['title'] ?? ''), (string) ($_POST['category'] ?? ''), (string) ($_POST['severity'] ?? ''), ($_POST['personal_data'] ?? '') === '1', (string) ($_POST['detected_at'] ?? ''), (string) ($_POST['description'] ?? ''), $subjects);
            Session::flash('success', "Incident #$id registered.");
            saqf_redirect('incidents.php?id=' . $id);
        }
        if (($_POST['op'] ?? '') === 'update') {
            $id = (int) ($_POST['id'] ?? 0);
            Incidents::update($user, $id, (string) ($_POST['action'] ?? ''), (string) ($_POST['note'] ?? ''));
            Session::flash('success', 'Recorded in the incident and in the audit log.');
            saqf_redirect('incidents.php?id=' . $id);
        }
    } catch (InvalidArgumentException $e) {
        Session::flash('error', $e->getMessage());
        saqf_redirect('incidents.php' . (isset($_POST['id']) ? '?id=' . (int) $_POST['id'] : ''));
    }
}

$all = Incidents::all();
$selected = null;
foreach ($all as $i) {
    if ((int) ($_GET['id'] ?? 0) === (int) $i['id']) {
        $selected = $i;
    }
}
$tone = ['not_applicable' => 'grey', 'done' => 'green', 'open' => 'blue', 'due_soon' => 'amber', 'overdue' => 'red'];
$label = static function (array $c): string {
    switch ($c['state']) {
        case 'not_applicable':
            return 'No personal data: no notification clock';
        case 'done':
            return 'Authority notification recorded';
        case 'overdue':
            return 'Past the ' . Incidents::NOTIFY_HOURS . '-hour window (' . abs((float) $c['hours_left']) . ' h ago)';
        default:
            return $c['hours_left'] . ' h left of ' . Incidents::NOTIFY_HOURS;
    }
};
V::header('Security incidents', $user, ['subtitle' => 'Register, notification clock and audit trail. The decisions stay with people.']);
?>
<div class="alert alert-info">This register supports the university's incident process; it does not replace it. For incidents involving personal data, the Saudi Personal Data Protection Law is described in the sources we reviewed as requiring notification of the competent authority (SDAIA) within <?= (int) Incidents::NOTIFY_HOURS ?> hours of becoming aware of a breach likely to cause harm. Whether that applies to a given incident is for the data protection officer or legal counsel to decide. SAQF never contacts an authority or any person by itself.</div>
<div class="split">
  <div class="stack">
    <section class="card"><div class="card-h"><?= V::icon('alert') ?><h2>Incidents</h2><span class="right muted small"><?= V::h(V::count(count(array_filter($all, static fn($i) => $i['status'] !== 'closed')), 'open', 'open')) ?></span></div>
      <div class="card-b tight"><div class="table-wrap"><table>
        <thead><tr><th>Incident</th><th>Severity</th><th>Status</th><th>Notification clock</th></tr></thead><tbody>
        <?php foreach ($all as $i): $c = Incidents::clock($i); ?>
          <tr><td><a href="incidents.php?id=<?= (int) $i['id'] ?>"><strong>#<?= (int) $i['id'] ?> <?= V::h($i['title']) ?></strong></a><div class="tiny muted"><?= V::h(Incidents::CATEGORIES[$i['category']] ?? $i['category']) ?> · detected <?= V::h(V::date($i['detected_at'], 'j M Y, H:i')) ?></div></td>
            <td><?= V::pill(Incidents::SEVERITIES[$i['severity']] ?? $i['severity'], ['low' => 'grey', 'medium' => 'blue', 'high' => 'amber', 'critical' => 'red'][$i['severity']] ?? 'grey') ?></td>
            <td><?= V::pill(Incidents::STATUS[$i['status']] ?? $i['status'], $i['status'] === 'closed' ? 'green' : 'blue') ?></td>
            <td class="small"><?= V::pill($label($c), $tone[$c['state']]) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$all): ?><tr><td colspan="4"><?= V::empty('No incidents registered', 'When something happens, register it here so the clock, the decisions and the timeline are kept.') ?></td></tr><?php endif; ?>
        </tbody></table></div></div></section>

    <?php if ($selected): $c = Incidents::clock($selected); ?>
    <section class="card"><div class="card-h"><h2><?= V::h('#' . (int) $selected['id'] . ' ' . $selected['title']) ?></h2><?= V::pill(Incidents::STATUS[$selected['status']] ?? $selected['status'], $selected['status'] === 'closed' ? 'green' : 'blue') ?></div>
      <div class="card-b small">
        <p><?= nl2br(V::h($selected['description'])) ?></p>
        <table class="facts"><tbody>
          <tr><td class="muted">Kind</td><td><?= V::h(Incidents::CATEGORIES[$selected['category']] ?? $selected['category']) ?> · <?= V::h(Incidents::SEVERITIES[$selected['severity']] ?? $selected['severity']) ?></td></tr>
          <tr><td class="muted">Detected</td><td><?= V::h(V::date($selected['detected_at'], 'j M Y, H:i')) ?> · registered by <?= V::h($selected['reporter']) ?></td></tr>
          <tr><td class="muted">Personal data</td><td><?= $selected['personal_data'] ? V::h('Involved' . ($selected['subjects_estimate'] !== null ? ' · about ' . (int) $selected['subjects_estimate'] . ' people' : '')) : V::h('Not involved') ?></td></tr>
          <?php if ($c['applies']): ?><tr><td class="muted">Notification window</td><td><?= V::pill($label($c), $tone[$c['state']]) ?> <span class="tiny muted"><?= V::h('until ' . V::date($c['deadline'], 'j M Y, H:i')) ?></span></td></tr><?php endif; ?>
          <?php if ($selected['authority_notified_at']): ?><tr><td class="muted">Authority notified</td><td><?= V::h(V::date($selected['authority_notified_at'], 'j M Y, H:i')) ?></td></tr><?php endif; ?>
          <?php if ($selected['subjects_notified_at']): ?><tr><td class="muted">People informed</td><td><?= V::h(V::date($selected['subjects_notified_at'], 'j M Y, H:i')) ?></td></tr><?php endif; ?>
          <?php if ($selected['closure_note']): ?><tr><td class="muted">How it ended</td><td><?= V::h($selected['closure_note']) ?></td></tr><?php endif; ?>
        </tbody></table>
        <h3 style="margin-top:14px">Timeline</h3>
        <ul class="timeline"><?php foreach (Incidents::events((int) $selected['id']) as $e): ?><li class="usr"><div class="when"><?= V::h(V::date($e['occurred_at'], 'j M Y H:i')) ?> · <span><?= V::h($e['full_name']) ?></span></div><div><strong><?= V::h(ucfirst(str_replace('_', ' ', $e['kind']))) ?></strong><?= $e['note'] ? ': ' . V::h($e['note']) : '' ?></div></li><?php endforeach; ?></ul>
        <?php if ($selected['status'] !== 'closed'): ?>
        <form method="post" class="fieldset" style="margin-top:12px"><?= Csrf::field() ?><input type="hidden" name="op" value="update"><input type="hidden" name="id" value="<?= (int) $selected['id'] ?>">
          <div class="field"><label for="inc-action">Record</label><select id="inc-action" name="action">
            <option value="note">A note</option><option value="contained">It is contained</option>
            <?php if ($selected['personal_data']): ?><option value="authority_notified">The authority was notified</option><option value="subjects_notified">Affected people were informed</option><?php endif; ?>
            <option value="closed">Close the incident</option></select></div>
          <div class="field"><label for="inc-note">Details (who, how, reference; required for notifications and closing)</label><textarea id="inc-note" name="note" rows="3" maxlength="4000"></textarea></div>
          <button class="btn btn-sm btn-primary" type="submit">Save to the timeline</button></form>
        <?php endif; ?>
      </div></section>
    <?php endif; ?>
  </div>
  <aside class="stack">
    <section class="card"><div class="card-h"><h2>Register an incident</h2></div><div class="card-b small">
      <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="open">
        <div class="field"><label for="in-title">Short title</label><input id="in-title" type="text" name="title" required minlength="5" maxlength="200"></div>
        <div class="grid g2"><div class="field"><label for="in-cat">Kind</label><select id="in-cat" name="category"><?php foreach (Incidents::CATEGORIES as $k => $l): ?><option value="<?= V::h($k) ?>"><?= V::h($l) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label for="in-sev">Severity</label><select id="in-sev" name="severity"><?php foreach (Incidents::SEVERITIES as $k => $l): ?><option value="<?= V::h($k) ?>" <?= $k === 'medium' ? 'selected' : '' ?>><?= V::h($l) ?></option><?php endforeach; ?></select></div></div>
        <div class="field"><label for="in-when">When was it detected?</label><input id="in-when" type="datetime-local" name="detected_at" required value="<?= V::h(Clock::now()->format('Y-m-d\TH:i')) ?>"></div>
        <div class="field"><label class="inline"><input type="checkbox" name="personal_data" value="1"> Personal data may be involved (starts the notification clock)</label></div>
        <div class="field"><label for="in-n">About how many people? (optional)</label><input id="in-n" type="number" name="subjects" min="0"></div>
        <div class="field"><label for="in-desc">What happened</label><textarea id="in-desc" name="description" rows="4" required minlength="20" maxlength="6000" placeholder="Facts only: what was seen, where, by whom, what was done first. No passwords or personal data."></textarea></div>
        <button class="btn btn-primary btn-sm" type="submit">Register the incident</button>
        <p class="tiny muted" style="margin-top:8px">Do not paste personal data into this register. Refer to records by id.</p>
      </form></div></section>
  </aside>
</div>
<?php V::footer();
