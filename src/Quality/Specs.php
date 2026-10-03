<?php
declare(strict_types=1);

namespace Saqf\Quality;

use DomainException;
use InvalidArgumentException;
use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Db;
use Saqf\Core\Events;
use Saqf\Core\Ledger;
use Saqf\Core\Notify;
use Saqf\Core\Policy;

/**
 * Course specification versions — the stable academic structure (CLOs, PLO mapping,
 * assessment plan) that is approved once and inherited every term.
 *
 * Change-based workflow: editing an approved specification transparently creates a
 * draft revision (copy-on-write). Reviewers see only the difference from the
 * approved baseline, never the whole form again.
 */
final class Specs
{
    public const DOMAINS = ['Knowledge and Understanding', 'Skills', 'Values, Autonomy, and Responsibility'];
    public const ASSESSMENT_KINDS = ['quiz' => 'Quiz', 'assignment' => 'Assignment', 'lab' => 'Lab work', 'midterm' => 'Midterm exam', 'final' => 'Final exam', 'project' => 'Project', 'presentation' => 'Presentation', 'report' => 'Report', 'other' => 'Other'];
    public const SKILL_TAGS = ['Digital', 'Communication', 'Teamwork', 'Ethics', 'Problem solving', 'Leadership'];
    public const RESOURCE_CATEGORIES = ['essential' => 'Essential references', 'supportive' => 'Supportive references', 'electronic' => 'Electronic materials', 'facility' => 'Facilities & equipment'];

    // ------------------------------------------------------------------ reads

    public static function version(int $id): ?array
    {
        return Db::one('SELECT * FROM spec_versions WHERE id = ?', [$id]);
    }

    public static function approved(int $courseId): ?array
    {
        return Db::one('SELECT * FROM spec_versions WHERE course_id = ? AND status = "approved" ORDER BY version_no DESC LIMIT 1', [$courseId]);
    }

    public static function inFlight(int $courseId): ?array
    {
        return Db::one('SELECT * FROM spec_versions WHERE course_id = ? AND status IN ("draft","pending_hod","pending_qa") ORDER BY version_no DESC LIMIT 1', [$courseId]);
    }

    /** The version people should be looking at: the revision in progress, else the approved one. */
    public static function working(int $courseId): ?array
    {
        return self::inFlight($courseId) ?? self::approved($courseId);
    }

    public static function load(int $versionId): array
    {
        $version = self::version($versionId);
        if (!$version) {
            throw new InvalidArgumentException('Specification version not found');
        }
        $courseId = (int) $version['course_id'];
        $course = Catalog::course($courseId);
        $programs = Catalog::programsFor($courseId);
        $clos = Db::all('SELECT * FROM clos WHERE spec_version_id = ? ORDER BY sort_order, code', [$versionId]);
        $maps = Db::all('SELECT cp.clo_id, cp.source, p.id, p.code, p.program_id, p.domain FROM clo_plo cp JOIN plos p ON p.id = cp.plo_id JOIN clos c ON c.id = cp.clo_id WHERE c.spec_version_id = ? ORDER BY p.code', [$versionId]);
        $assessments = Db::all('SELECT * FROM assessments WHERE spec_version_id = ? ORDER BY sort_order, id', [$versionId]);
        $links = Db::all('SELECT ac.assessment_id, ac.clo_id FROM assessment_clo ac JOIN assessments a ON a.id = ac.assessment_id WHERE a.spec_version_id = ?', [$versionId]);

        $cloIndex = [];
        foreach ($clos as $i => $c) {
            $clos[$i]['maps'] = [];
            $clos[$i]['assessments'] = [];
            $cloIndex[(int) $c['id']] = $i;
        }
        foreach ($maps as $m) {
            if (isset($cloIndex[(int) $m['clo_id']])) {
                $clos[$cloIndex[(int) $m['clo_id']]]['maps'][(int) $m['program_id']][] = $m;
            }
        }
        $asIndex = [];
        foreach ($assessments as $i => $a) {
            $assessments[$i]['clos'] = [];
            $asIndex[(int) $a['id']] = $i;
        }
        foreach ($links as $l) {
            if (isset($asIndex[(int) $l['assessment_id']], $cloIndex[(int) $l['clo_id']])) {
                $assessments[$asIndex[(int) $l['assessment_id']]]['clos'][] = (int) $l['clo_id'];
                $clos[$cloIndex[(int) $l['clo_id']]]['assessments'][] = (int) $l['assessment_id'];
            }
        }
        return [
            'version' => $version,
            'course' => $course,
            'programs' => $programs,
            'plos' => Catalog::plosFor($courseId),
            'clos' => $clos,
            'assessments' => $assessments,
            'topics' => Db::all('SELECT * FROM spec_topics WHERE spec_version_id = ? ORDER BY sort_order, id', [$versionId]),
            'resources' => Db::all('SELECT * FROM spec_resources WHERE spec_version_id = ? ORDER BY category, id', [$versionId]),
        ];
    }

    // ------------------------------------------------------------- revisions

    /** Returns the id of an editable draft, creating one (copy-on-write) when needed. */
    public static function ensureDraft(int $courseId): int
    {
        $inFlight = self::inFlight($courseId);
        if ($inFlight && $inFlight['status'] !== 'draft') {
            throw new DomainException('A revision of this specification is under review. You can edit again once it is approved or returned.');
        }
        if ($inFlight) {
            return (int) $inFlight['id'];
        }
        $approved = self::approved($courseId);
        $next = (int) Db::val('SELECT COALESCE(MAX(version_no),0)+1 FROM spec_versions WHERE course_id = ?', [$courseId]);
        $actor = Audit::actor();
        $id = Db::insert('spec_versions', [
            'course_id' => $courseId,
            'version_no' => $next,
            'status' => 'draft',
            'based_on_id' => $approved['id'] ?? null,
            'objectives' => $approved['objectives'] ?? null,
            'teaching_strategies' => $approved['teaching_strategies'] ?? null,
            'created_by' => $actor['type'] === 'user' ? $actor['id'] : null,
            'created_at' => Clock::stamp(),
        ]);
        $code = Catalog::course($courseId)['code'] ?? '';
        if ($approved) {
            self::copyStructure((int) $approved['id'], $id);
            Audit::record('spec.revision_started', 'spec_version', $id, "$code: draft v$next started from approved v{$approved['version_no']} (copy-on-write; only changes will be reviewed)");
        } else {
            Audit::record('spec.created', 'spec_version', $id, "$code: first specification draft v$next created");
        }
        return $id;
    }

    public static function copyStructure(int $fromId, int $toId): array
    {
        $cloMap = [];
        foreach (Db::all('SELECT * FROM clos WHERE spec_version_id = ? ORDER BY sort_order', [$fromId]) as $c) {
            $old = (int) $c['id'];
            unset($c['id']);
            $c['spec_version_id'] = $toId;
            $cloMap[$old] = Db::insert('clos', $c);
        }
        $maps = 0;
        foreach (Db::all('SELECT cp.* FROM clo_plo cp JOIN clos c ON c.id = cp.clo_id WHERE c.spec_version_id = ?', [$fromId]) as $m) {
            Db::insert('clo_plo', ['clo_id' => $cloMap[(int) $m['clo_id']], 'plo_id' => $m['plo_id'], 'source' => 'inherited']);
            $maps++;
        }
        $asMap = [];
        foreach (Db::all('SELECT * FROM assessments WHERE spec_version_id = ? ORDER BY sort_order', [$fromId]) as $a) {
            $old = (int) $a['id'];
            unset($a['id']);
            $a['spec_version_id'] = $toId;
            $asMap[$old] = Db::insert('assessments', $a);
        }
        $links = 0;
        foreach (Db::all('SELECT ac.* FROM assessment_clo ac JOIN assessments a ON a.id = ac.assessment_id WHERE a.spec_version_id = ?', [$fromId]) as $l) {
            Db::insert('assessment_clo', ['assessment_id' => $asMap[(int) $l['assessment_id']], 'clo_id' => $cloMap[(int) $l['clo_id']]]);
            $links++;
        }
        foreach (Db::all('SELECT * FROM spec_topics WHERE spec_version_id = ?', [$fromId]) as $t) {
            unset($t['id']);
            $t['spec_version_id'] = $toId;
            Db::insert('spec_topics', $t);
        }
        foreach (Db::all('SELECT * FROM spec_resources WHERE spec_version_id = ?', [$fromId]) as $r) {
            unset($r['id']);
            $r['spec_version_id'] = $toId;
            Db::insert('spec_resources', $r);
        }
        return ['clos' => count($cloMap), 'mappings' => $maps, 'assessments' => count($asMap), 'links' => $links];
    }

    public static function discardDraft(int $versionId): void
    {
        $v = self::requireDraft($versionId);
        Db::exec('DELETE FROM spec_versions WHERE id = ?', [$versionId]);
        Findings::closeScope('spec', $versionId, 'Draft discarded');
        Audit::record('spec.discarded', 'spec_version', $versionId, (Catalog::course((int) $v['course_id'])['code'] ?? '') . ": draft v{$v['version_no']} discarded");
        Events::emit('spec.changed', ['course_id' => (int) $v['course_id'], 'version_id' => null]);
    }

    private static function requireDraft(int $versionId): array
    {
        $v = self::version($versionId);
        if (!$v) {
            throw new InvalidArgumentException('Specification not found.');
        }
        if ($v['status'] !== 'draft') {
            throw new DomainException('This version is ' . str_replace('_', ' ', $v['status']) . ' and cannot be edited.');
        }
        return $v;
    }

    private static function changed(array $v, string $what, array $extra = []): void
    {
        Events::emit('spec.changed', ['course_id' => (int) $v['course_id'], 'version_id' => (int) $v['id'], 'what' => $what] + $extra);
    }

    // --------------------------------------------------------------- edits

    public static function saveClo(int $versionId, ?int $cloId, array $in): int
    {
        $v = self::requireDraft($versionId);
        $statement = trim((string) ($in['statement'] ?? ''));
        if ($statement === '' || mb_strlen($statement) > 600) {
            throw new InvalidArgumentException('Write the outcome statement (up to 600 characters).');
        }
        $domain = (string) ($in['domain'] ?? '');
        if (!in_array($domain, self::DOMAINS, true)) {
            throw new InvalidArgumentException('Choose the learning domain.');
        }
        $target = $in['target_pct'] ?? null;
        $target = ($target === '' || $target === null) ? null : (float) $target;
        if ($target !== null && ($target < 0 || $target > 100)) {
            throw new InvalidArgumentException('A target must be between 0 and 100%.');
        }
        $tags = array_values(array_intersect(self::SKILL_TAGS, (array) ($in['skills'] ?? [])));
        $data = ['statement' => $statement, 'domain' => $domain, 'target_pct' => $target, 'skills_tags' => $tags ? implode(',', $tags) : null];

        if ($cloId) {
            $old = Db::one('SELECT * FROM clos WHERE id = ? AND spec_version_id = ?', [$cloId, $versionId]);
            if (!$old) {
                throw new InvalidArgumentException('Outcome not found in this draft.');
            }
            Db::update('clos', $data, 'id = ?', [$cloId]);
            Audit::record('clo.updated', 'clo', $cloId, "{$old['code']} updated", array_intersect_key($old, $data), $data);
        } else {
            $n = (int) Db::val('SELECT COUNT(*) FROM clos WHERE spec_version_id = ?', [$versionId]) + 1;
            $code = 'CLO' . $n;
            while (Db::val('SELECT 1 FROM clos WHERE spec_version_id = ? AND code = ?', [$versionId, $code])) {
                $code = 'CLO' . (++$n);
            }
            $course = Catalog::course((int) $v['course_id']);
            $cloId = Db::insert('clos', $data + [
                'spec_version_id' => $versionId,
                'code' => $code,
                'lineage_key' => str_replace(' ', '', $course['code']) . ':' . substr(bin2hex(random_bytes(4)), 0, 8),
                'sort_order' => $n,
            ]);
            Audit::record('clo.created', 'clo', $cloId, "$code added to {$course['code']} draft v{$v['version_no']}", null, $data);
        }
        self::changed($v, 'clo');
        return $cloId;
    }

    public static function deleteClo(int $versionId, int $cloId): void
    {
        $v = self::requireDraft($versionId);
        $old = Db::one('SELECT * FROM clos WHERE id = ? AND spec_version_id = ?', [$cloId, $versionId]);
        if (!$old) {
            throw new InvalidArgumentException('Outcome not found in this draft.');
        }
        Db::exec('DELETE FROM clos WHERE id = ?', [$cloId]);
        Audit::record('clo.deleted', 'clo', $cloId, "{$old['code']} removed", $old, null);
        self::changed($v, 'clo');
    }

    /** Error prevention: only PLOs of programs whose study plan contains the course are accepted. */
    public static function setMapping(int $cloId, int $ploId, bool $on, string $source = 'faculty'): void
    {
        $clo = Db::one('SELECT c.*, sv.course_id FROM clos c JOIN spec_versions sv ON sv.id = c.spec_version_id WHERE c.id = ?', [$cloId]);
        if (!$clo) {
            throw new InvalidArgumentException('Outcome not found.');
        }
        $v = self::requireDraft((int) $clo['spec_version_id']);
        $plo = Db::one('SELECT p.*, pr.code AS program_code FROM plos p JOIN programs pr ON pr.id = p.program_id WHERE p.id = ? AND p.status = "approved"', [$ploId]);
        $allowed = array_map('intval', array_column(Catalog::programsFor((int) $clo['course_id']), 'program_id'));
        if (!$plo || !in_array((int) $plo['program_id'], $allowed, true)) {
            throw new InvalidArgumentException('That PLO belongs to a program that does not include this course.');
        }
        $exists = Db::val('SELECT id FROM clo_plo WHERE clo_id = ? AND plo_id = ?', [$cloId, $ploId]);
        if ($on && !$exists) {
            Db::insert('clo_plo', ['clo_id' => $cloId, 'plo_id' => $ploId, 'source' => $source]);
            Audit::record('mapping.added', 'clo', $cloId, "{$clo['code']} → {$plo['program_code']} {$plo['code']}" . ($source === 'suggestion' ? ' (accepted suggestion)' : ''));
        } elseif (!$on && $exists) {
            Db::exec('DELETE FROM clo_plo WHERE id = ?', [$exists]);
            Audit::record('mapping.removed', 'clo', $cloId, "{$clo['code']} ↛ {$plo['program_code']} {$plo['code']}");
        } else {
            return;
        }
        self::changed($v, 'mapping');
    }

    public static function saveAssessment(int $versionId, ?int $assessmentId, array $in): int
    {
        $v = self::requireDraft($versionId);
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Give the assessment a name (up to 120 characters).');
        }
        $kind = (string) ($in['kind'] ?? 'other');
        if (!isset(self::ASSESSMENT_KINDS[$kind])) {
            $kind = 'other';
        }
        $weight = (float) ($in['weight_pct'] ?? 0);
        if ($weight < 0 || $weight > 100) {
            throw new InvalidArgumentException('Weight must be between 0 and 100%.');
        }
        $week = ($in['week'] ?? '') === '' ? null : (int) $in['week'];
        if ($week !== null && ($week < 1 || $week > 18)) {
            throw new InvalidArgumentException('Week must be between 1 and 18.');
        }
        $dup = Db::val('SELECT id FROM assessments WHERE spec_version_id = ? AND name = ? AND id <> ?', [$versionId, $name, $assessmentId ?? 0]);
        if ($dup) {
            throw new InvalidArgumentException('Another assessment already has that name.');
        }
        $data = ['name' => $name, 'kind' => $kind, 'weight_pct' => $weight, 'week' => $week];
        if ($assessmentId) {
            $old = Db::one('SELECT * FROM assessments WHERE id = ? AND spec_version_id = ?', [$assessmentId, $versionId]);
            if (!$old) {
                throw new InvalidArgumentException('Assessment not found in this draft.');
            }
            Db::update('assessments', $data, 'id = ?', [$assessmentId]);
            Audit::record('assessment.updated', 'assessment', $assessmentId, "Assessment \"{$old['name']}\" updated", array_intersect_key($old, $data), $data);
        } else {
            $course = Catalog::course((int) $v['course_id']);
            $assessmentId = Db::insert('assessments', $data + [
                'spec_version_id' => $versionId,
                'lineage_key' => str_replace(' ', '', $course['code']) . ':A' . substr(bin2hex(random_bytes(4)), 0, 7),
                'sort_order' => (int) Db::val('SELECT COUNT(*) FROM assessments WHERE spec_version_id = ?', [$versionId]) + 1,
            ]);
            Audit::record('assessment.created', 'assessment', $assessmentId, "Assessment \"$name\" ($weight%) added", null, $data);
        }
        self::changed($v, 'assessment');
        return $assessmentId;
    }

    public static function deleteAssessment(int $versionId, int $assessmentId): void
    {
        $v = self::requireDraft($versionId);
        $old = Db::one('SELECT * FROM assessments WHERE id = ? AND spec_version_id = ?', [$assessmentId, $versionId]);
        if (!$old) {
            throw new InvalidArgumentException('Assessment not found in this draft.');
        }
        Db::exec('DELETE FROM assessments WHERE id = ?', [$assessmentId]);
        Audit::record('assessment.deleted', 'assessment', $assessmentId, "Assessment \"{$old['name']}\" removed", $old, null);
        self::changed($v, 'assessment');
    }

    public static function setAssessmentClo(int $assessmentId, int $cloId, bool $on): void
    {
        $a = Db::one('SELECT * FROM assessments WHERE id = ?', [$assessmentId]);
        $c = Db::one('SELECT * FROM clos WHERE id = ?', [$cloId]);
        if (!$a || !$c || (int) $a['spec_version_id'] !== (int) $c['spec_version_id']) {
            throw new InvalidArgumentException('That outcome is not part of this specification.');
        }
        $v = self::requireDraft((int) $a['spec_version_id']);
        $exists = Db::val('SELECT id FROM assessment_clo WHERE assessment_id = ? AND clo_id = ?', [$assessmentId, $cloId]);
        if ($on && !$exists) {
            Db::insert('assessment_clo', ['assessment_id' => $assessmentId, 'clo_id' => $cloId]);
            Audit::record('assessment.linked', 'assessment', $assessmentId, "\"{$a['name']}\" now measures {$c['code']}");
        } elseif (!$on && $exists) {
            Db::exec('DELETE FROM assessment_clo WHERE id = ?', [$exists]);
            Audit::record('assessment.unlinked', 'assessment', $assessmentId, "\"{$a['name']}\" no longer measures {$c['code']}");
        } else {
            return;
        }
        self::changed($v, 'link');
    }

    public static function saveNarrative(int $versionId, string $objectives, string $strategies): void
    {
        $v = self::requireDraft($versionId);
        $data = ['objectives' => mb_substr(trim($objectives), 0, 4000), 'teaching_strategies' => mb_substr(trim($strategies), 0, 4000)];
        Db::update('spec_versions', $data, 'id = ?', [$versionId]);
        Audit::record('spec.narrative_updated', 'spec_version', $versionId, 'Objectives / teaching strategies updated', ['objectives' => $v['objectives'], 'teaching_strategies' => $v['teaching_strategies']], $data);
        self::changed($v, 'narrative');
    }

    public static function saveTopics(int $versionId, array $topics): void
    {
        $v = self::requireDraft($versionId);
        Db::exec('DELETE FROM spec_topics WHERE spec_version_id = ?', [$versionId]);
        $i = 0;
        foreach ($topics as $t) {
            $text = trim((string) ($t['topic'] ?? ''));
            if ($text === '') {
                continue;
            }
            $hours = ($t['hours'] ?? '') === '' ? null : max(0, min(200, (float) $t['hours']));
            Db::insert('spec_topics', ['spec_version_id' => $versionId, 'topic' => mb_substr($text, 0, 300), 'contact_hours' => $hours, 'sort_order' => ++$i]);
        }
        Audit::record('spec.topics_updated', 'spec_version', $versionId, "Course content updated ($i topics)");
        self::changed($v, 'topics');
    }

    public static function saveResources(int $versionId, array $resources): void
    {
        $v = self::requireDraft($versionId);
        Db::exec('DELETE FROM spec_resources WHERE spec_version_id = ?', [$versionId]);
        $n = 0;
        foreach ($resources as $r) {
            $text = trim((string) ($r['text'] ?? ''));
            $cat = (string) ($r['category'] ?? 'essential');
            if ($text === '' || !isset(self::RESOURCE_CATEGORIES[$cat])) {
                continue;
            }
            Db::insert('spec_resources', ['spec_version_id' => $versionId, 'category' => $cat, 'reference_text' => mb_substr($text, 0, 400)]);
            $n++;
        }
        Audit::record('spec.resources_updated', 'spec_version', $versionId, "Learning resources updated ($n items)");
        self::changed($v, 'resources');
    }

    // ------------------------------------------------------------------ diff

    /** Differences between a version and its approved baseline. Academic changes need approval. */
    public static function diff(int $versionId): array
    {
        $v = self::version($versionId);
        if (!$v) {
            return [];
        }
        $new = self::load($versionId);
        if (!$v['based_on_id']) {
            return [['type' => 'new', 'academic' => true, 'label' => 'First specification for this course', 'before' => null, 'after' => count($new['clos']) . ' CLOs, ' . count($new['assessments']) . ' assessments']];
        }
        $old = self::load((int) $v['based_on_id']);
        $out = [];
        $oldClos = [];
        foreach ($old['clos'] as $c) {
            $oldClos[$c['lineage_key']] = $c;
        }
        $newClos = [];
        foreach ($new['clos'] as $c) {
            $newClos[$c['lineage_key']] = $c;
        }
        $mapCodes = static function (array $clo): array {
            $codes = [];
            foreach ($clo['maps'] as $list) {
                foreach ($list as $m) {
                    $codes[] = $m['code'] . '@' . $m['program_id'];
                }
            }
            sort($codes);
            return $codes;
        };
        $progCode = [];
        foreach ($new['programs'] as $p) {
            $progCode[(int) $p['program_id']] = $p['code'];
        }
        $pretty = static function (array $codes) use ($progCode): string {
            return $codes ? implode(', ', array_map(static function ($c) use ($progCode) {
                [$plo, $pid] = explode('@', $c);
                return ($progCode[(int) $pid] ?? '?') . ' ' . $plo;
            }, $codes)) : 'none';
        };
        foreach ($newClos as $key => $c) {
            if (!isset($oldClos[$key])) {
                $out[] = ['type' => 'clo_added', 'academic' => true, 'label' => "{$c['code']} added", 'before' => null, 'after' => $c['statement']];
                continue;
            }
            $o = $oldClos[$key];
            if (Text::normalize($o['statement']) !== Text::normalize($c['statement'])) {
                $out[] = ['type' => 'clo_changed', 'academic' => true, 'label' => "{$c['code']} statement changed", 'before' => $o['statement'], 'after' => $c['statement']];
            }
            if ($o['domain'] !== $c['domain']) {
                $out[] = ['type' => 'clo_domain', 'academic' => true, 'label' => "{$c['code']} domain changed", 'before' => $o['domain'], 'after' => $c['domain']];
            }
            if ((string) $o['target_pct'] !== (string) $c['target_pct']) {
                $out[] = ['type' => 'clo_target', 'academic' => true, 'label' => "{$c['code']} target changed", 'before' => $o['target_pct'] === null ? 'default' : $o['target_pct'] . '%', 'after' => $c['target_pct'] === null ? 'default' : $c['target_pct'] . '%'];
            }
            if ($mapCodes($o) !== $mapCodes($c)) {
                $out[] = ['type' => 'mapping', 'academic' => true, 'label' => "{$c['code']} PLO mapping changed", 'before' => $pretty($mapCodes($o)), 'after' => $pretty($mapCodes($c))];
            }
            if ((string) $o['skills_tags'] !== (string) $c['skills_tags']) {
                $out[] = ['type' => 'skills', 'academic' => false, 'label' => "{$c['code']} skill tags changed", 'before' => $o['skills_tags'] ?: 'none', 'after' => $c['skills_tags'] ?: 'none'];
            }
        }
        foreach ($oldClos as $key => $o) {
            if (!isset($newClos[$key])) {
                $out[] = ['type' => 'clo_removed', 'academic' => true, 'label' => "{$o['code']} removed", 'before' => $o['statement'], 'after' => null];
            }
        }
        $oldA = [];
        foreach ($old['assessments'] as $a) {
            $oldA[$a['lineage_key']] = $a;
        }
        $cloCodeOld = array_column($old['clos'], 'code', 'id');
        $cloCodeNew = array_column($new['clos'], 'code', 'id');
        $newA = [];
        foreach ($new['assessments'] as $a) {
            $newA[$a['lineage_key']] = $a;
            $links = array_map(static fn($id) => $cloCodeNew[$id] ?? '?', $a['clos']);
            sort($links);
            if (!isset($oldA[$a['lineage_key']])) {
                $out[] = ['type' => 'assessment_added', 'academic' => true, 'label' => "Assessment \"{$a['name']}\" added", 'before' => null, 'after' => Rules::fmt((float) $a['weight_pct']) . '% · measures ' . ($links ? implode(', ', $links) : 'no CLO')];
                continue;
            }
            $o = $oldA[$a['lineage_key']];
            if ((float) $o['weight_pct'] !== (float) $a['weight_pct']) {
                $out[] = ['type' => 'weight', 'academic' => true, 'label' => "\"{$a['name']}\" weight changed", 'before' => Rules::fmt((float) $o['weight_pct']) . '%', 'after' => Rules::fmt((float) $a['weight_pct']) . '%'];
            }
            if ($o['name'] !== $a['name']) {
                $out[] = ['type' => 'assessment_renamed', 'academic' => false, 'label' => 'Assessment renamed', 'before' => $o['name'], 'after' => $a['name']];
            }
            $oldLinks = array_map(static fn($id) => $cloCodeOld[$id] ?? '?', $o['clos']);
            sort($oldLinks);
            if ($oldLinks !== $links) {
                $out[] = ['type' => 'links', 'academic' => true, 'label' => "\"{$a['name']}\" CLO coverage changed", 'before' => implode(', ', $oldLinks) ?: 'none', 'after' => implode(', ', $links) ?: 'none'];
            }
        }
        foreach ($oldA as $key => $o) {
            if (!isset($newA[$key])) {
                $out[] = ['type' => 'assessment_removed', 'academic' => true, 'label' => "Assessment \"{$o['name']}\" removed", 'before' => Rules::fmt((float) $o['weight_pct']) . '%', 'after' => null];
            }
        }
        if (trim((string) $old['version']['objectives']) !== trim((string) $new['version']['objectives'])) {
            $out[] = ['type' => 'objectives', 'academic' => false, 'label' => 'Main objective edited', 'before' => $old['version']['objectives'], 'after' => $new['version']['objectives']];
        }
        if (trim((string) $old['version']['teaching_strategies']) !== trim((string) $new['version']['teaching_strategies'])) {
            $out[] = ['type' => 'strategies', 'academic' => false, 'label' => 'Teaching strategies edited', 'before' => $old['version']['teaching_strategies'], 'after' => $new['version']['teaching_strategies']];
        }
        if (array_column($old['topics'], 'topic') !== array_column($new['topics'], 'topic')) {
            $out[] = ['type' => 'topics', 'academic' => false, 'label' => 'Course content (topics) edited', 'before' => count($old['topics']) . ' topics', 'after' => count($new['topics']) . ' topics'];
        }
        if (array_column($old['resources'], 'reference_text') !== array_column($new['resources'], 'reference_text')) {
            $out[] = ['type' => 'resources', 'academic' => false, 'label' => 'Learning resources edited', 'before' => count($old['resources']) . ' items', 'after' => count($new['resources']) . ' items'];
        }
        return $out;
    }

    // -------------------------------------------------------------- workflow

    /**
     * Submits a draft. Deterministic blockers stop it here ("red is never sent").
     * @return array{ok:bool,route?:string,message:string,blockers?:array}
     */
    public static function submit(int $versionId, array $user): array
    {
        $v = self::requireDraft($versionId);
        Engine::evaluateSpec($versionId);
        $blockers = Db::all('SELECT title, detail FROM findings WHERE scope_type = "spec" AND scope_id = ? AND status = "open" AND severity = "blocker"', [$versionId]);
        if ($blockers) {
            return ['ok' => false, 'message' => count($blockers) === 1 ? 'Fix the 1 item marked Must fix before sending.' : 'Fix the ' . count($blockers) . ' items marked Must fix before sending.', 'blockers' => $blockers];
        }
        $diff = self::diff($versionId);
        $course = Catalog::course((int) $v['course_id']);
        if (!$diff) {
            self::discardDraft($versionId);
            return ['ok' => true, 'route' => 'no_change', 'message' => 'Nothing changed from the approved specification, so there was nothing to approve. The draft was discarded.'];
        }
        $academic = array_values(array_filter($diff, static fn($d) => $d['academic']));
        Db::update('spec_versions', ['submitted_by' => $user['id'], 'submitted_at' => Clock::stamp(), 'change_summary' => $diff], 'id = ?', [$versionId]);
        if (!$academic && Policy::get('spec.auto_approve_unchanged')) {
            self::approve($versionId, 'auto_minor', null, 'Only non-academic sections changed (content, resources, wording); approved automatically by policy.');
            return ['ok' => true, 'route' => 'auto_minor', 'message' => 'Only non-academic sections changed, so SAQF approved the revision automatically (policy). It is recorded in the audit log.'];
        }
        Db::update('spec_versions', ['status' => 'pending_hod'], 'id = ?', [$versionId]);
        Audit::record('spec.submitted', 'spec_version', $versionId, "{$course['code']} v{$v['version_no']} submitted to HoD: " . count($academic) . ' academic change(s)', null, ['changes' => count($diff)]);
        Notify::role('hod', (int) $course['owner_department_id'], null, 'decision', "{$course['code']}: " . (count($academic) === 1 ? '1 change needs your approval' : count($academic) . ' changes need your approval'), 'SAQF has already checked everything else; look at the changes and what they affect.', 'approvals.php?version=' . $versionId, 'spec-submit:' . $versionId . ':' . Clock::stamp());
        Ledger::add('routed', 1, null, (int) $v['course_id'], 'Specification change routed to HoD');
        Events::emit('spec.submitted', ['version_id' => $versionId, 'course_id' => (int) $v['course_id']]);
        return ['ok' => true, 'route' => 'hod', 'message' => count($diff) === 1 ? 'Sent. Your Head of Department sees only the 1 change and what it affects, not the whole specification.' : 'Sent. Your Head of Department sees only the ' . count($diff) . ' changes and what they affect, not the whole specification.'];
    }

    public static function hodDecide(int $versionId, array $user, string $decision, string $note): string
    {
        $v = self::version($versionId);
        if (!$v || $v['status'] !== 'pending_hod') {
            throw new DomainException('This revision is not waiting for a Head of Department decision.');
        }
        $course = Catalog::course((int) $v['course_id']);
        if ($decision === 'return') {
            if (mb_strlen(trim($note)) < 5) {
                throw new InvalidArgumentException('Explain what should change when returning a revision.');
            }
            Db::update('spec_versions', ['status' => 'draft', 'decided_by' => $user['id'], 'decided_at' => Clock::stamp(), 'decision_note' => $note, 'decision_route' => 'returned_hod'], 'id = ?', [$versionId]);
            Audit::record('spec.returned', 'spec_version', $versionId, "{$course['code']} v{$v['version_no']} returned by HoD", null, null, $note);
            if ($v['submitted_by']) {
                Notify::user((int) $v['submitted_by'], 'decision', "{$course['code']}: your Head of Department sent the changes back", mb_strimwidth($note, 0, 300, '…'), self::workspaceLink((int) $v['course_id']), 'spec-return:' . $versionId . ':' . Clock::stamp());
            }
            return 'Returned to the instructor with your comment.';
        }
        $warnings = (int) Db::val('SELECT COUNT(*) FROM findings WHERE scope_type = "spec" AND scope_id = ? AND status = "open" AND severity = "warning"', [$versionId]);
        Audit::record('spec.hod_approved', 'spec_version', $versionId, "{$course['code']} v{$v['version_no']} approved by HoD", null, null, $note ?: null);
        if ($warnings > 0 || !Policy::get('spec.auto_clear_green')) {
            Db::update('spec_versions', ['status' => 'pending_qa', 'decision_note' => $note ?: null], 'id = ?', [$versionId]);
            Notify::role('qa', null, null, 'decision', "{$course['code']}: changes need a Quality decision", 'The Head of Department approved them, but some checks still show warnings.', 'approvals.php?version=' . $versionId, 'spec-qa:' . $versionId);
            Ledger::add('routed', 1, null, (int) $v['course_id'], 'Amber revision routed to QA');
            return "Approved. $warnings warning(s) remain, so SAQF routed it to Quality Assurance for a decision.";
        }
        $sampled = (abs(crc32('spec' . $versionId)) % 100) < Policy::get('qa.sample_rate_pct');
        self::approve($versionId, 'auto_green', (int) $user['id'], $note ?: 'Approved by HoD; all deterministic checks passed.', $sampled);
        return 'Approved. All checks passed, so it was cleared without a QA step' . ($sampled ? ' (placed in the QA sample for spot-checking).' : '.');
    }

    public static function qaDecide(int $versionId, array $user, string $decision, string $note): string
    {
        $v = self::version($versionId);
        if (!$v || $v['status'] !== 'pending_qa') {
            throw new DomainException('This revision is not waiting for a QA decision.');
        }
        $course = Catalog::course((int) $v['course_id']);
        if ($decision === 'return') {
            if (mb_strlen(trim($note)) < 5) {
                throw new InvalidArgumentException('Explain what should change when returning a revision.');
            }
            Db::update('spec_versions', ['status' => 'draft', 'decided_by' => $user['id'], 'decided_at' => Clock::stamp(), 'decision_note' => $note, 'decision_route' => 'returned_qa'], 'id = ?', [$versionId]);
            Audit::record('spec.returned', 'spec_version', $versionId, "{$course['code']} v{$v['version_no']} returned by QA", null, null, $note);
            if ($v['submitted_by']) {
                Notify::user((int) $v['submitted_by'], 'decision', "{$course['code']}: Quality sent the changes back", mb_strimwidth($note, 0, 300, '…'), self::workspaceLink((int) $v['course_id']), 'spec-return:' . $versionId . ':' . Clock::stamp());
            }
            return 'Returned to the instructor with your comment.';
        }
        self::approve($versionId, 'qa', (int) $user['id'], $note ?: 'Approved by Quality Assurance.');
        return 'Approved and activated.';
    }

    /** Activates a version: supersedes the previous one and moves eligible offerings onto it. */
    public static function approve(int $versionId, string $route, ?int $deciderId, string $note, bool $sampled = false): void
    {
        $v = self::version($versionId);
        $course = Catalog::course((int) $v['course_id']);
        $previous = self::approved((int) $v['course_id']);
        Db::tx(static function () use ($v, $versionId, $route, $deciderId, $note, $sampled, $previous) {
            if ($previous) {
                Db::update('spec_versions', ['status' => 'superseded'], 'id = ?', [$previous['id']]);
                Findings::closeScope('spec', (int) $previous['id'], 'Superseded by v' . $v['version_no']);
            }
            Db::update('spec_versions', ['status' => 'approved', 'decided_by' => $deciderId, 'decided_at' => Clock::stamp(), 'decision_route' => $route, 'decision_note' => $note, 'qa_sampled' => $sampled ? 1 : 0], 'id = ?', [$versionId]);
            // Offerings without results move to the new structure; offerings with results keep
            // the version their evidence was produced against (evidence integrity).
            Db::exec(
                'UPDATE course_offerings o JOIN terms t ON t.id = o.term_id SET o.spec_version_id = ?
                 WHERE o.course_id = ? AND t.status <> "closed" AND (o.spec_version_id IS NULL OR o.spec_version_id = ?)
                 AND NOT EXISTS (SELECT 1 FROM assessment_results r WHERE r.offering_id = o.id)',
                [$versionId, $v['course_id'], $previous['id'] ?? 0]
            );
        });
        $labels = ['auto_minor' => 'auto-approved (non-academic change)', 'auto_green' => 'approved by HoD and auto-cleared (all checks green)', 'qa' => 'approved by Quality Assurance', 'seed' => 'baseline imported', 'import' => 'imported as the approved baseline', 'hod' => 'approved by HoD'];
        Audit::record('spec.approved', 'spec_version', $versionId, "{$course['code']} v{$v['version_no']} " . ($labels[$route] ?? $route), null, ['route' => $route, 'qa_sampled' => $sampled]);
        if ($v['submitted_by'] && !in_array($route, ['seed', 'import'], true)) {
            Notify::user((int) $v['submitted_by'], 'info', "{$course['code']}: your specification changes were approved", \Saqf\Web\View::route($route), self::workspaceLink((int) $v['course_id']), 'spec-approved:' . $versionId);
        }
        Events::emit('spec.approved', ['version_id' => $versionId, 'course_id' => (int) $v['course_id']]);
    }

    private static function workspaceLink(int $courseId): string
    {
        $o = Db::val('SELECT o.id FROM course_offerings o JOIN terms t ON t.id = o.term_id WHERE o.course_id = ? ORDER BY t.sequence DESC LIMIT 1', [$courseId]);
        return $o ? 'workspace.php?id=' . $o : 'index.php';
    }
}
