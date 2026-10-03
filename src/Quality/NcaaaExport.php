<?php
declare(strict_types=1);

namespace Saqf\Quality;

use Saqf\Core\Db;
use Saqf\Core\Policy;
use Saqf\Web\Docx;

/**
 * Word documents laid out like the NCAAA course specification and course report templates
 * (sections A–G), filled entirely from SAQF's structured record. In the Arabic document the headings
 * are Arabic, and so is every name and piece of course content whose Arabic wording is known
 * (Registrar catalogue, faculty, the "Arabic wording" page); anything else appears as written. The
 * section structure follows the published NCAAA templates and should be checked against the version
 * the university uses.
 */
final class NcaaaExport
{
    private const L = [
        'spec' => ['Course Specification', 'توصيف المقرر'],
        'report' => ['Course Report', 'تقرير المقرر'],
        'institution' => ['Institution', 'المؤسسة'],
        'college' => ['College', 'الكلية'],
        'department' => ['Department', 'القسم'],
        'programs' => ['Program(s)', 'البرنامج/البرامج'],
        'course' => ['Course title and code', 'اسم المقرر ورمزه'],
        'version' => ['Specification version', 'إصدار التوصيف'],
        'approved_on' => ['Last approved', 'تاريخ آخر اعتماد'],
        'A' => ['A. General information about the course', 'أ. معلومات عامة عن المقرر'],
        'credits' => ['Credit hours', 'الساعات المعتمدة'],
        'type' => ['Course type', 'نوع المقرر'],
        'level' => ['Level / year at which offered', 'المستوى / السنة'],
        'description' => ['Course general description', 'الوصف العام للمقرر'],
        'prereq' => ['Pre-requirements', 'المتطلبات السابقة'],
        'coreq' => ['Co-requisites', 'المتطلبات المتزامنة'],
        'objective' => ['Course main objective(s)', 'الهدف الرئيس للمقرر'],
        'contact' => ['Contact hours (total)', 'ساعات الاتصال (الإجمالي)'],
        'B' => ['B. Course learning outcomes (CLOs), teaching strategies and assessment methods', 'ب. نواتج التعلم للمقرر واستراتيجيات تدريسها وطرق تقييمها'],
        'code' => ['Code', 'الرمز'],
        'clo' => ['Course learning outcome', 'ناتج التعلم'],
        'plos' => ['Aligned PLOs', 'نواتج البرنامج المرتبطة'],
        'methods' => ['Assessment methods', 'طرق التقييم'],
        'strategies' => ['Teaching strategies', 'استراتيجيات التدريس'],
        'C' => ['C. Course content', 'ج. موضوعات المقرر'],
        'topic' => ['List of topics', 'قائمة الموضوعات'],
        'hours' => ['Contact hours', 'ساعات الاتصال'],
        'total' => ['Total', 'المجموع'],
        'D' => ['D. Students assessment activities', 'د. أنشطة تقييم الطلبة'],
        'activity' => ['Assessment activity', 'نشاط التقييم'],
        'week' => ['Timing (week)', 'التوقيت (الأسبوع)'],
        'weight' => ['Weight', 'الوزن'],
        'E' => ['E. Learning resources and facilities', 'هـ. مصادر التعلم والمرافق'],
        'F' => ['F. Assessment of course quality', 'و. تقويم جودة المقرر'],
        'area' => ['Assessment area', 'مجال التقويم'],
        'assessor' => ['Assessor', 'المقيّم'],
        'method' => ['Assessment method', 'طريقة التقويم'],
        'G' => ['G. Specification approval', 'ز. اعتماد التوصيف'],
        'council' => ['Approved by', 'جهة الاعتماد'],
        'reference' => ['Reference', 'المرجع'],
        'date' => ['Date', 'التاريخ'],
        'R_A' => ['A. General information', 'أ. معلومات عامة'],
        'term' => ['Academic term', 'الفصل الدراسي'],
        'coordinator' => ['Course coordinator', 'منسق المقرر'],
        'sections' => ['Sections and instructors', 'الشعب وأعضاء هيئة التدريس'],
        'students' => ['Students (enrolled / with results)', 'عدد الطلبة (المسجلون / من لديهم نتائج)'],
        'R_B' => ['B. Student results', 'ب. نتائج الطلبة'],
        'grades' => ['Grade distribution', 'توزيع التقديرات'],
        'no_grades' => ['The grade distribution is produced when every assessment has results.', 'يُحتسب توزيع التقديرات عند اكتمال نتائج جميع التقييمات.'],
        'assessment' => ['Assessment', 'التقييم'],
        'results' => ['Results', 'النتائج'],
        'mean' => ['Mean', 'المتوسط'],
        'R_C' => ['C. Course learning outcomes assessment results', 'ج. نتائج تقييم نواتج التعلم للمقرر'],
        'target' => ['Target', 'المستهدف'],
        'actual' => ['Actual', 'الفعلي'],
        'comment' => ['Comment', 'تعليق'],
        'met' => ['Met', 'متحقق'],
        'below' => ['Below target', 'دون المستهدف'],
        'provisional' => ['Provisional (results incomplete)', 'مؤقت (النتائج غير مكتملة)'],
        'no_results' => ['No results yet', 'لا توجد نتائج بعد'],
        'by_section' => ['Achievement by section', 'التحقق حسب الشعبة'],
        'section' => ['Section', 'الشعبة'],
        'interpretation' => ['Instructor interpretation of the results', 'تفسير عضو هيئة التدريس للنتائج'],
        'R_D' => ['D. Difficulties and recommendations', 'د. الصعوبات والتوصيات'],
        'difficulties' => ['Difficulties encountered', 'الصعوبات'],
        'recommendations' => ['Recommendations for the next offering', 'توصيات للتدريس القادم'],
        'none' => ['None reported.', 'لا يوجد.'],
        'R_E' => ['E. Course improvement plan', 'هـ. خطة تحسين المقرر'],
        'action' => ['Improvement action', 'إجراء التحسين'],
        'owner' => ['Owner', 'المسؤول'],
        'due' => ['Due', 'الموعد'],
        'status' => ['Status', 'الحالة'],
        'effect' => ['Measured effect', 'الأثر المقاس'],
        'followup' => ['Follow-up of earlier actions', 'متابعة إجراءات سابقة'],
        'R_F' => ['F. Evidence in the course file', 'و. الشواهد في ملف المقرر'],
        'evidence' => ['Evidence', 'الشاهد'],
        'kind' => ['Kind', 'النوع'],
        'added' => ['Added', 'تاريخ الإضافة'],
        'no_evidence' => ['No evidence has been filed yet.', 'لم تُرفع شواهد بعد.'],
        'generated' => ['Generated by SAQF from the structured course record on %s. Values are calculated, not typed; the method and thresholds are institutional policy (achievement method: %s; student threshold %s%%; default target %s%%).', 'أُعد هذا المستند آلياً بواسطة نظام SAQF من السجل المنظم للمقرر بتاريخ %s. القيم محسوبة وليست مُدخلة يدوياً، وطريقة الاحتساب سياسة مؤسسية (طريقة التحقق: %s؛ حد الطالب %s%%؛ المستهدف الافتراضي %s%%).'],
        'frozen' => ['Frozen record of the closed term · SHA-256 %s', 'سجل مُجمّد للفصل المغلق · SHA-256 %s'],
        'required' => ['required', 'إجباري'],
        'elective' => ['elective', 'اختياري'],
        'level_n' => ['level %s', 'المستوى %s'],
        'version_n' => ['Version %s', 'الإصدار %s'],
        'n_students' => ['%s students', '%s طالباً'],
        'F1' => ['Achievement of course learning outcomes', 'تحقق نواتج التعلم للمقرر'],
        'F1a' => ['Course coordinator; Quality', 'منسق المقرر؛ الجودة'],
        'F1m' => ['Direct: calculated each term from assessment results (%s method, institutional policy)', 'مباشر: يُحسب كل فصل من نتائج التقييمات (طريقة %s، سياسة مؤسسية)'],
        'F2' => ['Quality of the course specification', 'جودة توصيف المقرر'],
        'F2a' => ['Head of Department', 'رئيس القسم'],
        'F2m' => ['Continuous rule checks in SAQF and review of every academic change', 'فحوص مستمرة بالقواعد في SAQF ومراجعة كل تغيير أكاديمي'],
        'F3' => ['Effectiveness of improvement actions', 'فاعلية إجراءات التحسين'],
        'F3a' => ['Head of Department; Quality', 'رئيس القسم؛ الجودة'],
        'F3m' => ['Comparison of achievement before and after each action, term by term', 'مقارنة التحقق قبل كل إجراء وبعده، فصلاً بعد فصل'],
        'F4' => ['Consistency between sections', 'الاتساق بين الشعب'],
        'F4a' => ['Course coordinator', 'منسق المقرر'],
        'F4m' => ['Achievement compared per section on the same outcomes and assessments', 'مقارنة التحقق لكل شعبة على المخرجات والتقييمات نفسها'],
    ];

    public static function t(string $key, string $lang): string
    {
        return self::L[$key][$lang === 'ar' ? 1 : 0] ?? $key;
    }

    /** A name or piece of course content: its Arabic wording in the Arabic document when known. */
    public static function w(?string $text, string $lang): string
    {
        $text = (string) $text;
        if ($lang !== 'ar' || $text === '' || !preg_match('/[A-Za-z]/', $text)) {
            return $text;
        }
        return \Saqf\Web\I18n::phrase($text) ?? $text;
    }

    private static function date(?string $d, string $lang): string
    {
        if (!$d) {
            return '—';
        }
        $out = date('j M Y', strtotime($d));
        return $lang === 'ar' ? strtr($out, \Saqf\Web\View::MONTHS_AR) : $out;
    }

    /** NCAAA-style course specification for an approved (or in-progress) specification version. */
    public static function specification(int $versionId, string $lang = 'en'): Docx
    {
        $t = static fn(string $k) => self::t($k, $lang);
        $w = static fn(?string $x) => self::w($x, $lang);
        $s = Specs::load($versionId);
        $v = $s['version'];
        $c = $s['course'];
        $courseId = (int) $v['course_id'];
        $dept = Db::one('SELECT d.name AS department, col.name AS college FROM departments d JOIN colleges col ON col.id = d.college_id WHERE d.id = ?', [$c['owner_department_id']]);
        $doc = new Docx($t('spec') . ' — ' . $c['code'], $lang === 'ar');
        $doc->heading($t('spec'), 0);
        $doc->paragraph($c['code'] . ' — ' . $w($c['title']), ['bold' => true]);
        $doc->fields([
            $t('institution') => $w('Al Yamamah University'),
            $t('college') => $w((string) ($dept['college'] ?? '')),
            $t('department') => $w((string) ($dept['department'] ?? '')),
            $t('programs') => implode(' · ', array_map(static fn($p) => $p['code'] . ' (' . self::t($p['course_type'] === 'required' ? 'required' : 'elective', $lang) . ')', $s['programs'])),
            $t('version') => sprintf($t('version_n'), $v['version_no']) . ' · ' . $w(['draft' => 'Draft', 'pending_hod' => 'With the Head of Department', 'pending_qa' => 'With Quality', 'approved' => 'Approved', 'superseded' => 'Older version'][$v['status']] ?? (string) $v['status']),
            $t('approved_on') => self::date($v['decided_at'], $lang),
        ]);

        $doc->heading($t('A'), 1);
        $req = Catalog::requisitesFor($courseId);
        $doc->fields([
            $t('credits') => rtrim(rtrim((string) $c['credits'], '0'), '.'),
            $t('type') => implode(' · ', array_map(static fn($p) => $p['code'] . ': ' . self::t($p['course_type'] === 'required' ? 'required' : 'elective', $lang), $s['programs'])),
            $t('level') => implode(' · ', array_map(static fn($p) => $p['code'] . ': ' . ($p['level_no'] ? sprintf(self::t('level_n', $lang), $p['level_no']) : self::w((string) $p['requirement_group'], $lang)), $s['programs'])),
            $t('description') => $w((string) ($c['description'] ?? '—')),
            $t('prereq') => implode(', ', array_unique(array_column(array_filter($req, static fn($r) => $r['kind'] !== 'corequisite'), 'code'))) ?: '—',
            $t('coreq') => implode(', ', array_unique(array_column(array_filter($req, static fn($r) => $r['kind'] === 'corequisite'), 'code'))) ?: '—',
            $t('objective') => $w((string) ($v['objectives'] ?: '—')),
            $t('contact') => rtrim(rtrim(number_format(Catalog::contactHours((float) $c['credits']), 1), '0'), '.'),
        ]);

        $doc->heading($t('B'), 1);
        $names = array_map($w, array_column($s['assessments'], 'name', 'id'));
        foreach (Specs::DOMAINS as $i => $domain) {
            $rows = [];
            foreach ($s['clos'] as $clo) {
                if ($clo['domain'] !== $domain) {
                    continue;
                }
                $plos = [];
                foreach ($clo['maps'] as $list) {
                    foreach ($list as $m) {
                        $plos[] = Db::val('SELECT code FROM programs WHERE id = ?', [$m['program_id']]) . ' ' . $m['code'];
                    }
                }
                $rows[] = [($i + 1) . '.' . (count($rows) + 1) . ' ' . $clo['code'], $w($clo['statement']), implode(', ', $plos), implode($lang === 'ar' ? '، ' : ', ', array_map(static fn($id) => $names[$id] ?? '', $clo['assessments']))];
            }
            if ($rows) {
                $doc->heading(($i + 1) . '.0 ' . $w($domain), 3);
                $doc->table([$t('code'), $t('clo'), $t('plos'), $t('methods')], $rows, [12, 48, 18, 22]);
            }
        }
        if ($v['teaching_strategies']) {
            $doc->paragraph($t('strategies') . ': ' . $w($v['teaching_strategies']));
        }

        $doc->heading($t('C'), 1);
        $rows = [];
        $sum = 0.0;
        foreach ($s['topics'] as $n => $tp) {
            $rows[] = [(string) ($n + 1), $w($tp['topic']), $tp['contact_hours'] === null ? '' : rtrim(rtrim((string) $tp['contact_hours'], '0'), '.')];
            $sum += (float) $tp['contact_hours'];
        }
        $rows[] = ['', $t('total'), rtrim(rtrim(number_format($sum, 1), '0'), '.')];
        $doc->table(['#', $t('topic'), $t('hours')], $rows, [8, 72, 20]);

        $doc->heading($t('D'), 1);
        $rows = [];
        $sum = 0.0;
        foreach ($s['assessments'] as $n => $a) {
            $rows[] = [(string) ($n + 1), $w($a['name']), (string) ($a['week'] ?? ''), rtrim(rtrim((string) $a['weight_pct'], '0'), '.') . '%'];
            $sum += (float) $a['weight_pct'];
        }
        $rows[] = ['', $t('total'), '', rtrim(rtrim(number_format($sum, 2), '0'), '.') . '%'];
        $doc->table(['#', $t('activity'), $t('week'), $t('weight')], $rows, [8, 52, 15, 25]);

        $doc->heading($t('E'), 1);
        $rows = [];
        foreach (Specs::RESOURCE_CATEGORIES as $k => $label) {
            $items = array_column(array_filter($s['resources'], static fn($r) => $r['category'] === $k), 'reference_text');
            if ($items) {
                $rows[] = [$w($label), implode("\n", array_map($w, $items))];
            }
        }
        $rows ? $doc->table([], $rows, [30, 70], true) : $doc->paragraph('—');

        $doc->heading($t('F'), 1);
        $doc->table([$t('area'), $t('assessor'), $t('method')], [
            [$t('F1'), $t('F1a'), sprintf($t('F1m'), Policy::get('achievement.method'))],
            [$t('F2'), $t('F2a'), $t('F2m')],
            [$t('F3'), $t('F3a'), $t('F3m')],
            [$t('F4'), $t('F4a'), $t('F4m')],
        ], [30, 25, 45]);

        $doc->heading($t('G'), 1);
        $decider = $v['decided_by'] ? Db::val('SELECT full_name FROM users WHERE id = ?', [$v['decided_by']]) : null;
        $doc->fields([
            $t('council') => $decider ? $w($decider) . ' (' . $w(\Saqf\Web\View::route($v['decision_route'])) . ')' : ($v['decision_route'] ? $w(\Saqf\Web\View::route($v['decision_route'])) : '—'),
            $t('reference') => 'SAQF ' . $c['code'] . ' · ' . sprintf($t('version_n'), $v['version_no']),
            $t('date') => self::date($v['decided_at'], $lang),
        ]);
        $doc->paragraph(sprintf($t('generated'), \Saqf\Core\Clock::now()->format('j M Y H:i'), Policy::get('achievement.method'), Policy::get('achievement.student_threshold_pct'), Policy::get('clo.default_target_pct')), ['small' => true, 'color' => '6B7280']);
        return $doc;
    }

    /** NCAAA-style course report for one offering (term). */
    public static function courseReport(int $offeringId, string $lang = 'en'): Docx
    {
        $t = static fn(string $k) => self::t($k, $lang);
        $w = static fn(?string $x) => self::w($x, $lang);
        $r = Reports::courseReport($offeringId);
        $o = $r['offering'];
        $doc = new Docx($t('report') . ' — ' . $o['code'] . ' ' . $w($o['term_name']), $lang === 'ar');
        $doc->heading($t('report'), 0);
        $doc->paragraph($o['code'] . ' — ' . $w($o['title']) . ' · ' . $w($o['term_name']), ['bold' => true]);

        $doc->heading($t('R_A'), 1);
        $sectionText = implode("\n", array_map(static fn($s) => self::t('section', $lang) . ' ' . $s['section_code'] . ': ' . self::w($s['instructor_name'] ?? '—', $lang) . ' (' . sprintf(self::t('n_students', $lang), $s['enrolled']) . ')', $r['sections'])) ?: $w($o['instructor'] ?? '—');
        $doc->fields([
            $t('institution') => $w('Al Yamamah University'),
            $t('college') => $w((string) $o['college']),
            $t('department') => $w((string) $o['department']),
            $t('programs') => implode(' · ', array_map(static fn($p) => $p['code'] . ' (' . self::t($p['course_type'] === 'required' ? 'required' : 'elective', $lang) . ')', $r['programs'])),
            $t('term') => $w((string) $o['term_name']),
            $t('coordinator') => $w((string) ($o['instructor'] ?? '—')),
            $t('sections') => $sectionText,
            $t('students') => (int) $o['enrolled'] . ' / ' . (int) $r['assessed_students'],
        ]);

        $doc->heading($t('R_B'), 1);
        if ($r['grades']) {
            $doc->heading($t('grades'), 3);
            $doc->table(array_keys($r['grades']), [array_map(static fn($n) => (string) $n, array_values($r['grades']))]);
        } else {
            $doc->paragraph($t('no_grades'), ['italic' => true]);
        }
        $doc->table([$t('assessment'), $t('weight'), $t('results'), $t('mean')], array_map(static fn($a) => [self::w($a['name'], $lang), self::num($a['weight']) . '%', (string) $a['n'], $a['mean'] === null ? '—' : Rules::whole((float) $a['mean']) . '%'], $r['assessments']), [46, 18, 18, 18]);

        $doc->heading($t('R_C'), 1);
        $rows = [];
        foreach ($r['clos'] as $c) {
            $comment = $c['value'] === null ? $t('no_results') : ($c['provisional'] ? $t('provisional') : ($c['met'] ? $t('met') : $t('below')));
            $rows[] = [$c['code'], $w($c['statement']), implode(', ', $c['plos']), implode($lang === 'ar' ? '، ' : ', ', array_map($w, $c['assessments'])), Rules::whole((float) $c['target']) . '%', $c['value'] === null ? '—' : Rules::whole((float) $c['value']) . '%' . ($c['students'] ? ' (' . sprintf($t('n_students'), $c['students']) . ')' : ''), $comment];
        }
        $doc->table([$t('code'), $t('clo'), $t('plos'), $t('methods'), $t('target'), $t('actual'), $t('comment')], $rows, [8, 28, 11, 15, 10, 13, 15]);
        if ($r['by_section']) {
            $doc->heading($t('by_section'), 3);
            $header = [$t('code')];
            foreach ($r['by_section'] as $code => $s) {
                $header[] = $t('section') . ' ' . $code . ' (' . $w($s['instructor'] ?? '—') . ')';
            }
            $rows = [];
            foreach ($r['clos'] as $c) {
                $row = [$c['code']];
                foreach ($r['by_section'] as $s) {
                    $v = $s['clos'][$c['id']] ?? null;
                    $row[] = $v === null ? '—' : Rules::whole((float) $v['value']) . '% (' . sprintf($t('n_students'), $v['n']) . ')';
                }
                $rows[] = $row;
            }
            $doc->table($header, $rows);
        }
        $doc->heading($t('interpretation'), 3);
        $doc->paragraph(isset($r['narrative']['interpretation']) ? $w($r['narrative']['interpretation']) : $t('none'));

        $doc->heading($t('R_D'), 1);
        $doc->heading($t('difficulties'), 3);
        $doc->paragraph(isset($r['narrative']['difficulties']) ? $w($r['narrative']['difficulties']) : $t('none'));
        $doc->heading($t('recommendations'), 3);
        $doc->paragraph(isset($r['narrative']['recommendations']) ? $w($r['narrative']['recommendations']) : $t('none'));

        $doc->heading($t('R_E'), 1);
        $rows = array_map(static fn($a) => [self::w($a['title'], $lang), self::w((string) ($a['action_text'] ?? '—'), $lang), self::w((string) ($a['owner_name'] ?? '—'), $lang), self::date($a['due_on'], $lang), self::w((string) (Improvements::STATUS[$a['status']] ?? $a['status']), $lang)], $r['actions']);
        $rows ? $doc->table([$t('clo'), $t('action'), $t('owner'), $t('due'), $t('status')], $rows, [20, 40, 15, 12, 13]) : $doc->paragraph($t('none'));
        if ($r['followups']) {
            $doc->heading($t('followup'), 3);
            $doc->table([$t('action'), $t('effect')], array_map(static fn($a) => [self::w($a['title'], $lang) . ' (' . self::w($a['origin_term'], $lang) . ')', self::w(Improvements::EFFECT[$a['effect']] ?? (string) $a['effect'], $lang) . ($a['followup_pct'] !== null ? ': ' . Rules::whole((float) $a['baseline_pct']) . '% → ' . Rules::whole((float) $a['followup_pct']) . '%' : '')], $r['followups']), [60, 40]);
        }

        $doc->heading($t('R_F'), 1);
        $rows = array_map(static fn($e) => [self::w($e['title'], $lang), self::w(Evidence::KINDS[$e['kind']] ?? $e['kind'], $lang), self::w((string) ($e['assessment_name'] ?? '—'), $lang), self::date($e['uploaded_at'], $lang)], $r['evidence_files']);
        $rows ? $doc->table([$t('evidence'), $t('kind'), $t('assessment'), $t('added')], $rows, [40, 25, 20, 15]) : $doc->paragraph($t('no_evidence'));

        $doc->paragraph(sprintf($t('generated'), \Saqf\Core\Clock::now()->format('j M Y H:i'), $r['method']['achievement'], $r['method']['student_threshold'], $r['method']['default_target']), ['small' => true, 'color' => '6B7280']);
        $snap = Db::one('SELECT sha256 FROM snapshots WHERE kind = "course_report" AND scope_id = ?', [$offeringId]);
        if ($snap) {
            $doc->paragraph(sprintf($t('frozen'), $snap['sha256']), ['small' => true, 'color' => '6B7280']);
        }
        return $doc;
    }

    private static function num($v): string
    {
        $s = number_format((float) $v, 1, '.', '');
        return rtrim(rtrim($s, '0'), '.');
    }
}
