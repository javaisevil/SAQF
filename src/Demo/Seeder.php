<?php
declare(strict_types=1);

namespace Saqf\Demo;

use Saqf\Core\Audit;
use Saqf\Core\Clock;
use Saqf\Core\Db;
use Saqf\Quality\Achievement;
use Saqf\Quality\Evidence;
use Saqf\Quality\Improvements;
use Saqf\Quality\Overrides;
use Saqf\Quality\Scheduler;
use Saqf\Quality\SpecImport;
use Saqf\Quality\Specs;
use Saqf\Quality\Workspaces;
use Saqf\Integration\Sync;

/**
 * Replays the demo scenario through the REAL services on a moving clock, so every
 * historical record (approvals, results, gaps, improvement actions, audit trail)
 * is produced by the same engine the live demo uses.
 */
final class Seeder
{
    private array $users = [];
    private $log;

    public function __construct(?callable $log = null)
    {
        $this->log = $log ?? static function (string $m): void {
            echo $m . "\n";
        };
    }

    private function say(string $m): void
    {
        ($this->log)('  · ' . $m);
    }

    private function at(string $when): void
    {
        Clock::set($when);
    }

    public function user(string $username): array
    {
        if (!isset($this->users[$username])) {
            $u = Db::one('SELECT u.*, d.college_id AS dept_college FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE username = ?', [$username]);
            $u['id'] = (int) $u['id'];
            $u['department_id'] = $u['department_id'] === null ? null : (int) $u['department_id'];
            $u['scope_college_id'] = (int) ($u['college_id'] ?? $u['dept_college'] ?? 0) ?: null;
            $this->users[$username] = $u;
        }
        return $this->users[$username];
    }

    private function as(string $username): array
    {
        $u = $this->user($username);
        Audit::actAs('user', $u['id'], $u['full_name'], $u['role']);
        $_SESSION['uid'] = $u['id'];
        $_SESSION['name'] = $u['full_name'];
        $_SESSION['role'] = $u['role'];
        return $u;
    }

    private function system(): void
    {
        Audit::actAs('system', null, 'SAQF automation', null);
        unset($_SESSION['uid']);
    }

    public function createUsers(): void
    {
        $hash = \Saqf\Security\Auth::hash(Story::PASSWORD);
        foreach (Story::USERS as [$username, $name, $title, $role, $dept, $college, $ext]) {
            Db::insert('users', [
                'username' => $username, 'external_id' => $ext, 'password_hash' => $hash, 'full_name' => $name, 'title' => $title,
                'email' => $username . '@demo.saqf.invalid', 'role' => $role,
                'department_id' => $dept ? Db::val('SELECT id FROM departments WHERE code = ?', [$dept]) : null,
                'college_id' => $college ? Db::val('SELECT id FROM colleges WHERE code = ?', [$college]) : null,
                'status' => 'active', 'password_changed_at' => Clock::stamp(), 'created_at' => Clock::stamp(),
            ]);
        }
        Audit::asSystem(static fn() => Audit::record('users.provisioned', 'user', null, count(Story::USERS) . ' fictional demo accounts provisioned (demo mode)'));
        // Arabic wording of the people and the course content, as a university would enter it.
        foreach (Story::ARABIC as $kind => $pairs) {
            \Saqf\Core\Translations::setMany($pairs, $kind);
        }
        // The administrator uses two-step verification (policy), with a fixed demo-only secret.
        \Saqf\Security\Mfa::enrolWithSecret((int) Db::val('SELECT id FROM users WHERE username = "it.admin"'), Story::ADMIN_TOTP_SECRET);
    }

    private function courseId(string $code): int
    {
        return (int) Db::val('SELECT id FROM courses WHERE code = ?', [$code]);
    }

    private function term(string $code): array
    {
        return Db::one('SELECT * FROM terms WHERE code = ?', [$code]);
    }

    private function offering(string $course, string $term): int
    {
        return (int) Db::val('SELECT o.id FROM course_offerings o JOIN terms t ON t.id = o.term_id JOIN courses c ON c.id = o.course_id WHERE c.code = ? AND t.code = ?', [$course, $term]);
    }

    /** Author a full first specification through the normal editing services. */
    private function authorSpec(string $course, ?array $override = null): int
    {
        $def = $override ?? Story::SPECS[$course];
        $this->as($def['author']);
        $courseId = $this->courseId($course);
        $vid = Specs::ensureDraft($courseId);
        $cloIds = [];
        foreach ($def['clos'] as [$statement, $domain, $maps]) {
            $cloId = Specs::saveClo($vid, null, ['statement' => $statement, 'domain' => $domain, 'target_pct' => '']);
            $cloIds[] = $cloId;
            foreach ($maps as $program => $codes) {
                foreach ($codes as $code) {
                    $plo = Db::val('SELECT pl.id FROM plos pl JOIN programs p ON p.id = pl.program_id WHERE p.code = ? AND pl.code = ?', [$program, $code]);
                    Specs::setMapping($cloId, (int) $plo, true);
                }
            }
        }
        foreach ($def['assessments'] as [$name, $kind, $weight, $week, $clos]) {
            $aid = Specs::saveAssessment($vid, null, ['name' => $name, 'kind' => $kind, 'weight_pct' => $weight, 'week' => $week]);
            foreach ($clos as $i) {
                Specs::setAssessmentClo($aid, $cloIds[$i - 1], true);
            }
        }
        Specs::saveNarrative($vid, $def['objectives'], $def['strategies']);
        Specs::saveTopics($vid, array_map(static fn($t) => ['topic' => $t[0], 'hours' => $t[1]], $def['topics']));
        Specs::saveResources($vid, array_map(static fn($r) => ['category' => $r[0], 'text' => $r[1]], $def['resources']));
        return $vid;
    }

    private function submit(int $vid, string $author): array
    {
        $r = Specs::submit($vid, $this->as($author));
        if (!$r['ok']) {
            throw new \RuntimeException('Seed submission blocked: ' . json_encode($r['blockers'] ?? $r['message']));
        }
        return $r;
    }

    public function run(): void
    {
        // ------------------------------------------------------------ Fall 2025
        $this->at('2025-08-20 08:00:00');
        $this->system();
        $s = Workspaces::activateTerm((int) $this->term('2025-1')['id']);
        $this->say("Fall 2025 activated from SIS: {$s['created']} workspaces created");

        $hodCed = 'hod.ced';
        $plan = [
            ['CIS 443', '2025-08-25 10:00:00', $hodCed],
            ['SWE 302', '2025-08-25 14:00:00', $hodCed],
            ['CNE 307', '2025-08-26 09:30:00', $hodCed],
        ];
        foreach ($plan as [$course, $when, $hod]) {
            $this->at($when);
            $vid = $this->authorSpec($course);
            $this->submit($vid, Story::SPECS[$course]['author']);
            $this->at(date('Y-m-d H:i:s', strtotime($when . ' +1 day')));
            Specs::hodDecide($vid, $this->as($hod), 'approve', '');
            $this->say("$course v1 approved");
        }

        // Business school: existing approved specifications arrive through the bulk import (QA).
        $this->at('2025-08-26 11:00:00');
        $this->as('qa.director');
        $file = $this->importFile(Story::IMPORTED_SPECS);
        $r = SpecImport::run($file, 'baseline', 'Specifications approved by the College of Business council (2024-2025), migrated into SAQF');
        @unlink($file);
        if ($r['errors']) {
            throw new \RuntimeException('Seed specification import failed: ' . implode(' | ', $r['errors']));
        }
        $this->say('Specification import: ' . implode(', ', $r['imported']) . ' imported as approved baselines');

        // SWE 401: returned once by the HoD for an academic reason, then approved.
        $this->at('2025-08-26 13:00:00');
        $def = Story::SPECS['SWE 401'];
        $def['clos'][3][0] = 'Write a quality report.';
        $vid = $this->authorSpec('SWE 401', $def);
        $this->submit($vid, 'f.omar');
        $this->at('2025-08-27 09:15:00');
        Specs::hodDecide($vid, $this->as($hodCed), 'return', 'CLO4 is too generic: state what the report justifies and for whom, so it can be assessed against SO3/SO4.');
        $this->at('2025-08-27 16:40:00');
        $this->as('f.omar');
        $clo4 = (int) Db::val('SELECT id FROM clos WHERE spec_version_id = ? AND code = "CLO4"', [$vid]);
        Specs::saveClo($vid, $clo4, ['statement' => Story::SPECS['SWE 401']['clos'][3][0], 'domain' => 'Values, Autonomy, and Responsibility', 'target_pct' => '']);
        $this->submit($vid, 'f.omar');
        $this->at('2025-08-28 10:00:00');
        Specs::hodDecide($vid, $this->as($hodCed), 'approve', 'Revised CLO4 is assessable.');
        $this->say('SWE 401 v1 returned once, then approved');

        // CIS 491: capstone weighting above policy → amber → QA decides.
        $this->at('2025-08-28 11:00:00');
        $vid = $this->authorSpec('CIS 491');
        $this->submit($vid, 'f.omar');
        $this->at('2025-08-29 09:00:00');
        Specs::hodDecide($vid, $this->as($hodCed), 'approve', 'Capstone structure follows the graduation project handbook.');
        $this->at('2025-08-31 10:30:00');
        Specs::qaDecide($vid, $this->as('qa.director'), 'approve', 'Approved for this cycle. The 70% report weighting exceeds the single-assessment policy; submit a formal exception with justification before the next offering.');
        $this->say('CIS 491 v1 approved through QA (amber)');

        // Results arrive from the LMS after grades are due → achievement → gaps → drafts.
        $this->at('2025-12-26 07:00:00');
        $this->system();
        Scheduler::tick(true);
        $this->say('Fall 2025 results imported by the scheduler; achievement calculated');

        $this->at('2025-12-27 10:00:00');
        $o401 = $this->offering('SWE 401', '2025-1');
        $this->evidence($o401, 'f.omar', 'Final exam', 'assessment', 'Final exam paper and model answers', ['Section A: quality models (ISO/IEC 25010) - 20 marks', 'Section B: review and inspection of a design document - 30 marks', 'Section C: write a test plan for the case study system - 50 marks (CLO3)', 'Model answers and marking scheme attached on page 2.']);
        $this->evidence($o401, 'f.omar', 'QA plan project', 'rubric', 'QA plan project rubric', ['Criterion 1: test strategy derived from requirements (CLO3) - 30%', 'Criterion 2: coverage and risk-based prioritisation (CLO3) - 30%', 'Criterion 3: justification to stakeholders (CLO4) - 25%', 'Criterion 4: presentation and teamwork - 15%']);

        $this->at('2026-01-04 10:00:00');
        $omar = $this->as('f.omar');
        $this->narrative($o401, 'f.omar', 'CLO3 (test and QA planning) was the weakest outcome. Most teams produced test cases but could not derive a coverage-driven test plan from requirements; the project rubric showed the gap clearly while the final exam questions on planning were often skipped.');
        $ia = (int) Db::val('SELECT id FROM improvement_actions WHERE origin_offering_id = ?', [$o401]);
        Improvements::commit($ia, $omar, 'Introduce three weekly test-design labs (weeks 9–11) with rubric-based formative feedback, and add a graded test-plan checkpoint in week 10 before the final project submission.', $omar['id'], '2026-05-10');
        $this->say('SWE 401 improvement action committed');

        // ------------------------------------------------------------ Spring 2026
        $this->at('2026-01-09 08:00:00');
        $this->system();
        $s = Workspaces::activateTerm((int) $this->term('2025-2')['id']);
        $this->say("Spring 2026 activated (Fall 2025 closed and frozen): {$s['created']} workspaces, {$s['inherited']} inherited");

        $this->at('2026-01-12 10:00:00');
        $vid = $this->authorSpec('SWE 412');
        $this->submit($vid, 'f.omar');
        $this->at('2026-01-13 09:00:00');
        Specs::hodDecide($vid, $this->as($hodCed), 'approve', '');
        $this->say('SWE 412 v1 approved');

        // A non-academic change is approved automatically (change-based workflow).
        $this->at('2026-03-02 12:00:00');
        $this->as('f.sara');
        $cid = $this->courseId('CIS 443');
        $vid = Specs::ensureDraft($cid);
        $res = Story::SPECS['CIS 443']['resources'];
        $res[] = ['electronic', 'Well-Architected Framework whitepapers (cost and security pillars)'];
        Specs::saveResources($vid, array_map(static fn($r) => ['category' => $r[0], 'text' => $r[1]], $res));
        $r = $this->submit($vid, 'f.sara');
        $this->say('CIS 443 v2 (resources only): ' . $r['route']);

        $this->at('2026-05-08 15:00:00');
        Improvements::setStatus($ia, $this->as('f.omar'), 'completed', 'Three test-design labs delivered (weeks 9–11) with rubric feedback; the week-10 test-plan checkpoint was graded for all 10 teams.');

        $this->at('2026-05-22 07:00:00');
        $this->system();
        Scheduler::tick(true);
        $this->say('Spring 2026 results imported; effectiveness of earlier actions evaluated');

        $this->at('2026-06-01 11:00:00');
        $omar = $this->as('f.omar');
        $o401b = $this->offering('SWE 401', '2025-2');
        $this->narrative($o401b, 'f.omar', 'CLO3 improved after the test-design labs (more teams produced requirement-based test plans) but is still below target. Students now struggle mainly with risk-based prioritisation and estimating test effort.');
        $ia2 = (int) Db::val('SELECT id FROM improvement_actions WHERE origin_offering_id = ? AND status = "draft"', [$o401b]);
        Improvements::commit($ia2, $omar, 'Add a risk-based test prioritisation case study and peer review of test plans; align the final-exam planning question with the project rubric so CLO3 is assessed consistently.', $omar['id'], '2026-12-10');

        $this->at('2026-06-02 10:00:00');
        $sara = $this->as('f.sara');
        $o302 = $this->offering('SWE 302', '2025-2');
        $this->narrative($o302, 'f.sara', 'Design-project architectures were often not traceable to quality requirements (CLO2). Teams documented components well but rarely justified decisions against performance or security scenarios.');
        $ia3 = (int) Db::val('SELECT id FROM improvement_actions WHERE origin_offering_id = ? AND status = "draft"', [$o302]);
        Improvements::commit($ia3, $sara, 'Introduce quality-attribute scenario templates in the design studio and a mid-project architecture review against those scenarios.', $sara['id'], '2026-11-30');

        $this->at('2026-06-03 09:00:00');
        $noura = $this->as('f.noura');
        $o327 = $this->offering('MIS 327', '2025-2');
        $this->narrative($o327, 'f.noura', 'SQL proficiency (CLO3) dropped this term: many students had less prior exposure to querying, and the SQL labs were compressed into fewer weeks after the mid-semester break.');
        $ia4 = (int) Db::val('SELECT id FROM improvement_actions WHERE origin_offering_id = ? AND status = "draft"', [$o327]);
        Improvements::commit($ia4, $noura, 'Spread SQL labs across eight weeks and add an auto-graded SQL practice set with weekly feedback.', $noura['id'], '2026-12-15');
        $this->say('Spring 2026 gaps interpreted and actions committed');

        // ------------------------------------------------------------ Fall 2026
        $this->at('2026-08-20 08:00:00');
        $this->system();
        $s = Workspaces::activateTerm((int) $this->term('2026-1')['id']);
        $this->say("Fall 2026 activated (Spring 2026 closed): {$s['created']} workspaces, {$s['inherited']} inherited");

        $this->at('2026-09-02 09:00:00');
        Improvements::setStatus($ia2, $this->as('f.omar'), 'in_progress');
        Improvements::setStatus($ia3, $this->as('f.sara'), 'in_progress');
        Improvements::setStatus($ia4, $this->as('f.noura'), 'in_progress');

        // IT: an overnight LMS outage earlier in the term — alerted after two failed runs, cleared on recovery.
        $this->at('2026-09-21 02:10:00');
        $this->system();
        \Saqf\Core\Alerts::raise('connector.lms', 'warning', 'LMS results import is failing', '29 failure(s) in the last scheduler run, 2 runs in a row. LMS API request timed out after 20 s. Details: System administration → Error log.');
        $this->at('2026-09-21 03:05:00');
        \Saqf\Core\Alerts::resolve('connector.lms');
        $this->say('IT alert history (LMS outage raised and cleared automatically)');

        $this->at('2026-09-25 13:00:00');
        $omar = $this->as('f.omar');
        $f = (int) Db::val('SELECT id FROM findings WHERE rule_code = "ASSESSMENT_SINGLE_WEIGHT" AND status = "open" AND course_id = ?', [$this->courseId('CIS 491')]);
        if ($f) {
            Overrides::request($f, $omar, 'The proposal and design report is the capstone\'s integrating deliverable and is assessed through three staged milestones (proposal, requirements, design) by a panel of two examiners, as set out in the YU graduation project handbook. Splitting it would duplicate the milestone rubric.');
            $this->say('CIS 491 policy exception requested');
        }

        // Dr. Omar starts a SWE 412 revision with four deterministic errors (fixed live in the demo).
        $this->at('2026-09-28 18:20:00');
        $this->as('f.omar');
        $cid = $this->courseId('SWE 412');
        $vid = Specs::ensureDraft($cid);
        Specs::saveClo($vid, null, ['statement' => 'Understand security risks in mobile applications.', 'domain' => 'Knowledge and Understanding', 'target_pct' => '']);
        $lab = (int) Db::val('SELECT id FROM assessments WHERE spec_version_id = ? AND name = "Lab assignments"', [$vid]);
        Specs::saveAssessment($vid, $lab, ['name' => 'Lab assignments', 'kind' => 'lab', 'weight_pct' => 25, 'week' => 10]);
        $this->say('SWE 412 revision v2 started by Dr. Omar (4 deterministic issues)');

        $this->at('2026-09-30 13:00:00');
        $this->system();
        Scheduler::tick(true);
        $this->say('Fall 2026 in-term results imported (provisional achievement)');

        // Evidence: the quiz is filed; SAQF still asks for the midterm (the live demo uploads it).
        $this->at('2026-09-30 16:00:00');
        $this->evidence($this->offering('SWE 401', '2026-1'), 'f.omar', 'Quiz', 'assessment', 'Quiz 1 paper (both sections)', ['Ten short questions on software quality models and ISO/IEC 25010 characteristics (CLO1).', 'Same paper for sections 01 and 02; marked with the shared answer key.']);
        $this->say('Course-file evidence filed (exam papers, rubric)');

        // Older notifications are treated as already read in the demo.
        Db::exec('UPDATE notifications SET read_at = created_at WHERE created_at < "2026-09-15"');
        // Everyone in the cast has been using SAQF this term (their last recorded action), so no account looks dormant.
        Db::exec('UPDATE users u SET last_login_at = GREATEST(COALESCE((SELECT MAX(a.occurred_at) FROM audit_log a WHERE a.actor_type = "user" AND a.actor_id = u.id), "2000-01-01"), "2026-09-29 08:00:00" + INTERVAL (u.id * 47) MINUTE)');
        Clock::set(null);
        Audit::actAs('system', null, 'SAQF automation', null);
        unset($_SESSION['uid'], $_SESSION['name'], $_SESSION['role']);
        Db::exec('DELETE FROM system_settings WHERE setting_key IN ("scheduler.last_tick","scheduler.last_daily")');
    }

    /** Files a generated PDF as course evidence through the real evidence service. */
    private function evidence(int $offeringId, string $username, string $assessment, string $kind, string $title, array $lines): void
    {
        $u = $this->as($username);
        $o = \Saqf\Security\Authz::offering($u, $offeringId);
        $aid = (int) Db::val('SELECT id FROM assessments WHERE spec_version_id = ? AND name = ?', [$o['spec_version_id'], $assessment]);
        $tmp = (string) tempnam(sys_get_temp_dir(), 'saqf-ev');
        file_put_contents($tmp, SamplePdf::make($o['course_code'] . ' - ' . $title, $lines));
        $name = strtolower(str_replace(' ', '-', $o['course_code'] . '-' . $title)) . '.pdf';
        Evidence::store($o, ['tmp_name' => $tmp, 'name' => $name, 'error' => UPLOAD_ERR_OK], $kind, $title, $aid ?: null, null, $u, false);
        @unlink($tmp);
    }

    /** Writes Story specifications in the import format (exercises the real import path). */
    private function importFile(array $courses): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'saqf-specs');
        $fh = fopen($file, 'w');
        fputcsv($fh, SpecImport::COLUMNS);
        foreach ($courses as $course) {
            $def = Story::SPECS[$course];
            fputcsv($fh, [$course, 'objectives', '', $def['objectives'], '', '', '', '', '', '']);
            fputcsv($fh, [$course, 'strategies', '', $def['strategies'], '', '', '', '', '', '']);
            foreach ($def['clos'] as $i => [$statement, $domain, $maps]) {
                $refs = [];
                foreach ($maps as $program => $codes) {
                    foreach ($codes as $c) {
                        $refs[] = "$program:$c";
                    }
                }
                fputcsv($fh, [$course, 'clo', 'CLO' . ($i + 1), $statement, $domain, '', '', '', '', implode(';', $refs)]);
            }
            foreach ($def['assessments'] as [$name, $kind, $weight, $week, $clos]) {
                fputcsv($fh, [$course, 'assessment', '', $name, $kind, (string) $weight, (string) $week, '', '', implode(';', array_map(static fn($i) => 'CLO' . $i, $clos))]);
            }
            foreach ($def['topics'] as [$topic, $hours]) {
                fputcsv($fh, [$course, 'topic', '', $topic, '', '', '', (string) $hours, '', '']);
            }
            foreach ($def['resources'] as [$cat, $text]) {
                fputcsv($fh, [$course, 'resource', '', $text, $cat, '', '', '', '', '']);
            }
        }
        fclose($fh);
        return $file;
    }

    private function narrative(int $offeringId, string $username, string $text): void
    {
        $u = $this->as($username);
        Db::exec('INSERT INTO offering_narratives (offering_id, section_key, content, updated_by, updated_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE content = VALUES(content), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)', [$offeringId, 'interpretation', $text, $u['id'], Clock::stamp()]);
        Audit::record('report.interpretation', 'offering', $offeringId, 'Instructor interpretation added to the course report');
        \Saqf\Quality\Engine::evaluateOffering($offeringId);
    }
}
