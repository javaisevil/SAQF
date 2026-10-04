<?php
declare(strict_types=1);

namespace Saqf\Quality;

use InvalidArgumentException;
use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Db;
use Saqf\Core\Policy;
use Saqf\Security\Authz;

/**
 * Course file closeout: one list of what the course file for an offering still needs, built only from
 * the records SAQF holds and the checklist Quality configured (Quality policies, "closeout.*").
 *
 * Each item is in one of four states:
 *   complete   the records show it is done
 *   missing    something has not been provided yet (owner and next step named)
 *   review     the material is there but a PERSON must decide or write something (an approval, an
 *              exception, the instructor's academic response, a reviewer's acceptance of the evidence)
 *   scheduled  SAQF does it by itself later (sealing the report when the term closes)
 * SAQF never writes the reflection, the recommendations or the improvement plan, and never accepts
 * evidence by itself: those items stay "missing" or "review" until a person acts.
 */
final class Closeout
{
    public const STATES = ['complete' => 'Complete', 'missing' => 'Missing', 'review' => 'Needs a person\'s review', 'scheduled' => 'Done automatically later'];

    /** Policy keys of the checklist, so the page can say whether Quality has set it yet. */
    public const POLICIES = ['closeout.require_spec', 'closeout.require_checks', 'closeout.require_results', 'closeout.require_evidence', 'closeout.evidence_kinds', 'closeout.require_improvement', 'closeout.require_reflection', 'closeout.require_recommendations'];

    /** Rules handled by their own closeout item rather than "no open problems". */
    private const OWN_ITEM_RULES = ['EVIDENCE_REQUESTED', 'CLO_TARGET_MISSED', 'IMPROVEMENT_MISSING', 'IMPROVEMENT_OVERDUE', 'INTERPRETATION_MISSING', 'RESULTS_MISSING_ASSESSMENT', 'RESULTS_OVERDUE', 'OFFERING_NO_SPEC'];

    /** @return list<string> evidence kinds Quality requires for each assessment */
    public static function evidenceKinds(): array
    {
        return array_values(array_filter(explode('+', (string) Policy::get('closeout.evidence_kinds')), static fn($k) => isset(Evidence::KINDS[$k])));
    }

    /**
     * Who set the checklist: null while every closeout setting is still SAQF's default.
     * @return array{at:string,by:?string}|null
     */
    public static function checklistSetBy(): ?array
    {
        $in = implode(',', array_fill(0, count(self::POLICIES), '?'));
        $r = Db::one("SELECT qp.updated_at, u.full_name FROM quality_policies qp LEFT JOIN users u ON u.id = qp.updated_by WHERE qp.policy_key IN ($in) AND qp.updated_at IS NOT NULL ORDER BY qp.updated_at DESC LIMIT 1", self::POLICIES);
        return $r ? ['at' => (string) $r['updated_at'], 'by' => $r['full_name']] : null;
    }

    /**
     * @param array $o offering row as returned by Authz::offering (course_code, instructor_name, term_status…)
     * @return array{items:list<array{key:string,label:string,state:string,owner:string,detail:string,next:string,tab:?string}>,counts:array<string,int>,ready:bool}
     */
    public static function forOffering(array $o): array
    {
        $oid = (int) $o['id'];
        $coordinator = (string) ($o['instructor_name'] ?? '') !== '' ? $o['instructor_name'] . ' (instructor)' : 'The course instructor';
        $items = [];
        $add = static function (string $key, string $label, string $state, string $owner, string $detail, string $next = '', ?string $tab = null) use (&$items) {
            $items[] = ['key' => $key, 'label' => $label, 'state' => $state, 'owner' => $owner, 'detail' => $detail, 'next' => $next, 'tab' => $tab];
        };
        $spec = $o['spec_version_id'] ? Specs::load((int) $o['spec_version_id']) : null;
        $findings = array_values(array_filter(Findings::forOffering($oid), static fn($f) => $f['status'] === 'open'));

        // 1. Approved specification in use ---------------------------------------------------------
        if (Policy::get('closeout.require_spec')) {
            $v = $spec ? Db::one('SELECT version_no, decided_at FROM spec_versions WHERE id = ?', [$o['spec_version_id']]) : null;
            $pending = Specs::inFlight((int) $o['course_id']);
            if ($v) {
                $add('spec', 'Approved course specification', 'complete', 'Head of Department / Quality', 'Version ' . (int) $v['version_no'] . ' approved ' . date('j M Y', strtotime((string) $v['decided_at'])) . ' is in use.');
            } elseif ($pending && in_array($pending['status'], ['pending_hod', 'pending_qa'], true)) {
                $add('spec', 'Approved course specification', 'review', $pending['status'] === 'pending_hod' ? 'Head of Department' : 'Quality', 'Version ' . (int) $pending['version_no'] . ' is waiting for a decision.', 'Decide on the submitted specification.', null);
            } else {
                $add('spec', 'Approved course specification', 'missing', $coordinator, 'No approved specification yet.', 'Write the outcomes and assessment plan, then submit them.', 'structure');
            }
        }

        // 2. No open problems (other than the ones with their own item below) ------------------------
        if (Policy::get('closeout.require_checks')) {
            $pendingOverride = [];
            foreach (Db::all('SELECT finding_id FROM overrides WHERE status = "requested"') as $r) {
                $pendingOverride[(int) $r['finding_id']] = true;
            }
            $problems = array_values(array_filter($findings, static fn($f) => $f['severity'] !== 'info' && !in_array($f['rule_code'], self::OWN_ITEM_RULES, true)));
            $mine = array_filter($problems, static fn($f) => $f['owner_role'] === 'faculty' && !isset($pendingOverride[(int) $f['id']]));
            $others = array_filter($problems, static fn($f) => $f['owner_role'] !== 'faculty' || isset($pendingOverride[(int) $f['id']]));
            $titles = static fn(array $list) => implode('; ', array_slice(array_map(static fn($f) => (string) $f['title'], $list), 0, 3)) . (count($list) > 3 ? '; …' : '');
            if (!$problems) {
                $add('checks', 'No open problems', 'complete', 'SAQF checks', 'Every automatic check passes, or Quality decided an exception.');
            } elseif ($mine) {
                $add('checks', 'No open problems', 'missing', $coordinator, count($problems) . ' open: ' . $titles($problems), 'Fix them on the Overview tab, or ask Quality for an exception.', 'overview');
            } else {
                $add('checks', 'No open problems', 'review', 'Quality / Head of Department', count($others) . ' waiting for a decision: ' . $titles($others), 'The person named in Problems to sort out decides.', 'overview');
            }
        }

        // 3. Grades for every assessment --------------------------------------------------------------
        $assessments = $spec['assessments'] ?? [];
        $graded = array_map('intval', Db::col('SELECT DISTINCT assessment_id FROM assessment_results WHERE offering_id = ?', [$oid]));
        $ungraded = array_values(array_filter($assessments, static fn($a) => !in_array((int) $a['id'], $graded, true)));
        if (Policy::get('closeout.require_results')) {
            if (!$assessments) {
                $add('results', 'Grades for every assessment', 'missing', $coordinator, 'There is no approved assessment plan to attach grades to yet.', 'Complete the specification first.', 'structure');
            } elseif ($ungraded) {
                $add('results', 'Grades for every assessment', 'missing', $coordinator . ' or the LMS', count($assessments) - count($ungraded) . ' of ' . count($assessments) . ' assessments have grades; still to come: ' . implode(', ', array_column($ungraded, 'name')) . '. Achievement is shown as "so far" until then.', 'Publish the grades in the LMS (SAQF imports them) or upload a gradebook file.', 'results');
            } else {
                $add('results', 'Grades for every assessment', 'complete', $coordinator, 'All ' . count($assessments) . ' assessments have grades; achievement is final.');
            }
        }

        // 4. Evidence for every assessment, accepted by a person ---------------------------------------
        if (Policy::get('closeout.require_evidence')) {
            $kinds = self::evidenceKinds();
            $have = [];
            foreach (Db::all('SELECT assessment_id, kind FROM evidence_files WHERE offering_id = ? AND deleted_at IS NULL AND assessment_id IS NOT NULL', [$oid]) as $e) {
                $have[(int) $e['assessment_id']][$e['kind']] = true;
            }
            $gaps = [];
            foreach ($assessments as $a) {
                $lack = array_values(array_filter($kinds, static fn($k) => empty($have[(int) $a['id']][$k])));
                if ($lack) {
                    $gaps[] = $a['name'] . ' (' . implode(', ', array_map(static fn($k) => mb_strtolower(Evidence::KINDS[$k]), $lack)) . ')';
                }
            }
            $kindText = implode(' + ', array_map(static fn($k) => mb_strtolower(Evidence::KINDS[$k]), $kinds));
            if (!$assessments) {
                $add('evidence', 'Evidence for every assessment, reviewed', 'missing', $coordinator, 'There is no approved assessment plan yet.', 'Complete the specification first.', 'structure');
            } elseif ($gaps) {
                $add('evidence', 'Evidence for every assessment, reviewed', 'missing', $coordinator, (count($assessments) - count($gaps)) . ' of ' . count($assessments) . ' assessments have the required evidence (' . $kindText . '). Missing: ' . implode('; ', array_slice($gaps, 0, 4)) . (count($gaps) > 4 ? '; …' : '') . '.', 'Upload the missing files in the Evidence tab (remove student names from samples).', 'evidence');
            } else {
                $review = self::evidenceReview($oid);
                if ($review && $review['decision'] === 'accepted' && $review['current']) {
                    $add('evidence', 'Evidence for every assessment, reviewed', 'complete', $review['reviewer'], 'Every assessment has ' . $kindText . '. Accepted by ' . $review['reviewer'] . ' on ' . date('j M Y', strtotime($review['reviewed_at'])) . ($review['note'] ? ': "' . $review['note'] . '"' : '.'));
                } elseif ($review && $review['decision'] === 'returned' && $review['current']) {
                    $add('evidence', 'Evidence for every assessment, reviewed', 'missing', $coordinator, 'Returned by ' . $review['reviewer'] . ' on ' . date('j M Y', strtotime($review['reviewed_at'])) . ': "' . $review['note'] . '"', 'Replace or add the files the reviewer asked for.', 'evidence');
                } else {
                    $add('evidence', 'Evidence for every assessment, reviewed', 'review', 'Head of Department or Quality', 'Every assessment has ' . $kindText . '. ' . ($review ? 'The files changed after the last review, so it is due again.' : 'No person has reviewed the set yet.'), 'A reviewer opens the files and accepts the set or returns it with a note.', 'closeout');
                }
            }
        }

        // 5. A plan for every missed goal (the instructor's academic response) --------------------------
        if (Policy::get('closeout.require_improvement')) {
            $drafts = Db::all('SELECT title FROM improvement_actions WHERE origin_offering_id = ? AND status = "draft"', [$oid]);
            $missing = array_filter($findings, static fn($f) => $f['rule_code'] === 'IMPROVEMENT_MISSING');
            $committed = (int) Db::val('SELECT COUNT(*) FROM improvement_actions WHERE origin_offering_id = ? AND status IN ("open","in_progress","completed")', [$oid]);
            if ($drafts) {
                $add('improvement', 'A plan for every missed goal', 'review', $coordinator, count($drafts) . ' improvement record(s) prepared by SAQF with the facts, waiting for the instructor\'s own plan: ' . implode('; ', array_slice(array_column($drafts, 'title'), 0, 3)) . '.', 'Write what will change, who does it and by when (your academic decision).', 'improve');
            } elseif ($missing) {
                $add('improvement', 'A plan for every missed goal', 'missing', $coordinator, count($missing) . ' missed goal(s) without an improvement plan.', 'Open the Improvement tab and write the plan.', 'improve');
            } else {
                $add('improvement', 'A plan for every missed goal', 'complete', $coordinator, $committed ? $committed . ' plan(s) written by the instructor.' : 'No outcome has missed its goal so far.');
            }
        }

        // 6–7. The instructor's own words in the course report ------------------------------------------
        $narr = [];
        foreach (Db::all('SELECT n.section_key, n.content, n.updated_at, u.full_name FROM offering_narratives n LEFT JOIN users u ON u.id = n.updated_by WHERE n.offering_id = ?', [$oid]) as $n) {
            $narr[$n['section_key']] = $n;
        }
        foreach (['reflection' => ['interpretation', 'The instructor\'s reading of the results'], 'recommendations' => ['recommendations', 'Suggestions for next time']] as $key => [$section, $label]) {
            if (!Policy::get('closeout.require_' . $key)) {
                continue;
            }
            $n = $narr[$section] ?? null;
            if ($n && trim((string) $n['content']) !== '') {
                $add($key, $label, 'complete', (string) ($n['full_name'] ?? $coordinator), 'Written by ' . ($n['full_name'] ?? 'the instructor') . ' on ' . date('j M Y', strtotime((string) $n['updated_at'])) . '.');
            } else {
                $add($key, $label, 'missing', $coordinator, 'Not written yet. SAQF does not write this: it is the instructor\'s academic judgement.', 'Write it on the Course report tab.', 'report');
            }
        }

        // 8. The sealed report (automatic) ------------------------------------------------------------
        $snap = Db::one('SELECT created_at, sha256 FROM snapshots WHERE kind = "course_report" AND scope_id = ?', [$oid]);
        if ($snap) {
            $add('report', 'Course report sealed', 'complete', 'SAQF (automatic)', 'Sealed on ' . date('j M Y', strtotime((string) $snap['created_at'])) . ' when the term closed (fingerprint ' . substr((string) $snap['sha256'], 0, 12) . '). Sealing records the content; it is not an approval of the report.');
        } else {
            $add('report', 'Course report sealed', 'scheduled', 'SAQF (automatic)', 'The report stays live until the term closes; SAQF then seals it so it can no longer change. Sealing is not an approval.');
        }

        $counts = array_fill_keys(array_keys(self::STATES), 0);
        foreach ($items as $i) {
            $counts[$i['state']]++;
        }
        return ['items' => $items, 'counts' => $counts, 'ready' => $counts['missing'] === 0 && $counts['review'] === 0];
    }

    /**
     * Course file package (ZIP) for reviewers: the course report and approved specification (Word), the
     * evidence index, the grade-batch provenance, the closeout checklist, a README with provenance and
     * SHA-256 checksums of every file. It holds no student identities (results appear only as
     * aggregates) and NOT the evidence files themselves: each of those is downloaded on its own, audited.
     * The caller has authorised the offering; the export is audited with the package checksum.
     * @return array{name:string,bytes:string,sha256:string}
     */
    public static function package(array $o, array $user): array
    {
        $oid = (int) $o['id'];
        $files = [];
        $files['course-report.docx'] = NcaaaExport::courseReport($oid, 'en')->bytes();
        $specVersion = $o['spec_version_id'] ? Db::one('SELECT id, version_no, decided_at, decision_route FROM spec_versions WHERE id = ?', [$o['spec_version_id']]) : null;
        if ($specVersion) {
            $files['specification-v' . (int) $specVersion['version_no'] . '.docx'] = NcaaaExport::specification((int) $specVersion['id'], 'en')->bytes();
        }
        $evidence = Db::all('SELECT e.*, a.name AS assessment_name, u.full_name AS uploader FROM evidence_files e LEFT JOIN assessments a ON a.id = e.assessment_id LEFT JOIN users u ON u.id = e.uploaded_by WHERE e.offering_id = ? AND e.deleted_at IS NULL ORDER BY e.id', [$oid]);
        $files['evidence-index.csv'] = self::csv(['evidence_id', 'title', 'kind', 'assessment', 'section', 'original_file_name', 'size_bytes', 'uploaded_at', 'uploaded_by', 'virus_scan', 'sha256'], array_map(static fn($e) => [
            (int) $e['id'], $e['title'], Evidence::KINDS[$e['kind']] ?? $e['kind'], $e['assessment_name'] ?? 'General', $e['section_code'] ?? 'All sections', $e['original_name'], (int) $e['size_bytes'], $e['uploaded_at'], $e['uploader'] ?? 'SAQF', $e['scan_status'] === 'clean' ? 'scanned clean' : 'not scanned', $e['sha256'],
        ], $evidence));
        $batches = Db::all('SELECT source, external_ref, imported_at, rows_count, assessments, checksum FROM result_batches WHERE offering_id = ? ORDER BY imported_at, id', [$oid]);
        $files['grade-batches.csv'] = self::csv(['source', 'reference', 'imported_at', 'rows', 'assessments', 'sha256_of_batch'], array_map(static fn($b) => [
            $b['source'] === 'upload' ? 'gradebook file uploaded by staff' : 'LMS feed', $b['external_ref'], $b['imported_at'], (int) $b['rows_count'], $b['assessments'], $b['checksum'],
        ], $batches));
        $c = self::forOffering($o);
        $files['closeout-checklist.csv'] = self::csv(['item', 'status', 'owner', 'detail', 'next_step'], array_map(static fn($i) => [$i['label'], self::STATES[$i['state']], $i['owner'], $i['detail'], $i['next']], $c['items']));

        $snap = Db::one('SELECT created_at, sha256 FROM snapshots WHERE kind = "course_report" AND scope_id = ?', [$oid]);
        $set = self::checklistSetBy();
        $demo = \Saqf\Core\Config::demoMode();
        $lines = [
            'SAQF course file package',
            str_repeat('=', 24),
            $demo ? 'DEMO DATA: fictional people, simulated SIS/LMS feeds and synthetic pseudonymous results. Not institutional records.' : 'Production installation.',
            '',
            'Course:        ' . $o['course_code'] . ' ' . $o['course_title'],
            'Term:          ' . $o['term_name'],
            'Instructor:    ' . ($o['instructor_name'] ?? '—'),
            'Generated:     ' . gmdate('Y-m-d H:i') . ' UTC by ' . $user['full_name'] . ' (' . $user['role'] . '), SAQF ' . SAQF_VERSION . (\Saqf\Core\Clock::offset() !== 0 ? ' (demo clock: ' . \Saqf\Core\Clock::stamp() . ')' : ''),
            '',
            'Provenance',
            '- Course report: ' . ($snap ? 'sealed when the term closed on ' . $snap['created_at'] . ' (snapshot SHA-256 ' . $snap['sha256'] . '); the Word file is generated from SAQF\'s records.' : 'NOT sealed: the term is still open, so the report was generated from live data at the time above and may still change.'),
            '- Specification: ' . ($specVersion ? 'version ' . (int) $specVersion['version_no'] . ', approved in SAQF\'s workflow on ' . $specVersion['decided_at'] . ' (' . ($specVersion['decision_route'] ?? 'route not recorded') . ').' : 'no approved specification yet.'),
            '- Grades: ' . count($batches) . ' batch(es), listed with source, import date and checksum in grade-batches.csv. Student identities are keyed pseudonyms inside SAQF and do not appear in this package.',
            '- Evidence: ' . count($evidence) . ' file(s) listed in evidence-index.csv with SHA-256 checksums. The files themselves are NOT in this package; each is downloaded from SAQF separately and every download is recorded.',
            '- Data sources: SIS ' . \Saqf\Integration\Integrations::sisKind() . ', LMS ' . \Saqf\Integration\Integrations::lmsKind() . ($demo ? ' (simulated in the demo).' : '.'),
            '- Checklist: ' . ($set ? 'set by Quality (last change ' . $set['at'] . ($set['by'] ? ' by ' . $set['by'] : '') . ').' : 'SAQF default settings, not yet confirmed by Quality.') . ' Status at generation: ' . $c['counts']['complete'] . ' complete, ' . $c['counts']['missing'] . ' missing, ' . $c['counts']['review'] . ' needing a person\'s review.',
            '',
            'This package is a working course file. It is not an approval of any NCAAA report, and sealing a report records its content, not an approval.',
            '',
            'Checksums of the other files are in SHA256SUMS.txt (check with: sha256sum -c SHA256SUMS.txt).',
        ];
        $files['README.txt'] = implode("\n", $lines) . "\n";
        $sums = '';
        foreach ($files as $name => $data) {
            $sums .= hash('sha256', $data) . '  ' . $name . "\n";
        }
        $files['SHA256SUMS.txt'] = $sums;
        $zip = new \Saqf\Web\Zip();
        foreach ($files as $name => $data) {
            $zip->add($name, $data);
        }
        $bytes = $zip->bytes();
        $sha = hash('sha256', $bytes);
        Audit::record('closeout.package_exported', 'offering', $oid, "Course file package for {$o['course_code']} {$o['term_name']} downloaded (" . count($evidence) . ' evidence files indexed, not included)', null, ['sha256' => $sha, 'files' => array_keys($files)]);
        return ['name' => preg_replace('/[^A-Za-z0-9\-]+/', '-', $o['course_code'] . ' course file ' . $o['term_name']) . ($demo ? '-DEMO' : '') . '.zip', 'bytes' => $bytes, 'sha256' => $sha];
    }

    /** CSV text; values that a spreadsheet would treat as a formula are prefixed with an apostrophe. */
    private static function csv(array $header, array $rows): string
    {
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Arabic correctly
        fputcsv($out, $header);
        foreach ($rows as $r) {
            fputcsv($out, array_map(static fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v, $r));
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }

    /** Fingerprint of the current evidence set (ids and checksums): any change makes a review stale. */
    public static function evidenceFingerprint(int $offeringId): string
    {
        $rows = Db::all('SELECT id, sha256 FROM evidence_files WHERE offering_id = ? AND deleted_at IS NULL ORDER BY id', [$offeringId]);
        return hash('sha256', json_encode(array_map(static fn($r) => [(int) $r['id'], (string) $r['sha256']], $rows)));
    }

    /** @return array{decision:string,note:?string,reviewer:string,reviewed_at:string,current:bool}|null latest review of the evidence set */
    public static function evidenceReview(int $offeringId): ?array
    {
        $r = Db::one('SELECT cr.*, u.full_name FROM closeout_reviews cr JOIN users u ON u.id = cr.reviewed_by WHERE cr.offering_id = ? AND cr.item_key = "evidence" ORDER BY cr.id DESC LIMIT 1', [$offeringId]);
        if (!$r) {
            return null;
        }
        return ['decision' => (string) $r['decision'], 'note' => $r['note'], 'reviewer' => (string) $r['full_name'], 'reviewed_at' => (string) $r['reviewed_at'], 'current' => hash_equals((string) $r['fingerprint'], self::evidenceFingerprint($offeringId))];
    }

    /** Who may review a course file: the Head of the owning department or Quality, never the instructor. */
    public static function canReview(array $user, array $o): bool
    {
        return $user['role'] === 'qa' || Authz::canDecideCourse($user, (int) $o['course_id']);
    }

    /** Records a person's acceptance (or return, with a note) of the evidence set. Audited. */
    public static function reviewEvidence(array $user, array $o, string $decision, string $note): void
    {
        if (!self::canReview($user, $o)) {
            throw new InvalidArgumentException('Only the Head of Department or Quality can review the course file evidence.');
        }
        if (!in_array($decision, ['accepted', 'returned'], true)) {
            throw new InvalidArgumentException('Choose whether to accept or return the evidence.');
        }
        $note = trim($note);
        if ($decision === 'returned' && mb_strlen($note) < 10) {
            throw new InvalidArgumentException('Say what is missing or needs changing (at least 10 characters), so the instructor knows what to do.');
        }
        if (!Db::val('SELECT 1 FROM evidence_files WHERE offering_id = ? AND deleted_at IS NULL', [(int) $o['id']])) {
            throw new InvalidArgumentException('There is no evidence in the course file to review yet.');
        }
        Db::insert('closeout_reviews', [
            'offering_id' => (int) $o['id'], 'item_key' => 'evidence', 'fingerprint' => self::evidenceFingerprint((int) $o['id']),
            'decision' => $decision, 'note' => $note !== '' ? mb_substr($note, 0, 500) : null, 'reviewed_by' => $user['id'], 'reviewed_at' => Clock::stamp(),
        ]);
        Audit::record('closeout.evidence_' . $decision, 'offering', (int) $o['id'], "{$o['course_code']} course file evidence " . ($decision === 'accepted' ? 'accepted' : 'returned to the instructor') . " by {$user['full_name']}", null, null, $note !== '' ? $note : null);
    }
}
