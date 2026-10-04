<?php declare(strict_types=1);

// Arabic patterns, area A1 (faculty workflow): sentences with numbers, dates, names and lists from the course workspace, the closeout
// checklist and board, report facts, evidence, results import, reminders and the scheduler. Anchored regex => Arabic; {1} inserts the
// captured text as is (or its known Arabic wording), {t1} translates it as a phrase. Loaded after the older patterns: a broader older
// pattern wins when it matches first, so every pattern here was tested with bin/i18n_check.php --show.

// Arabic number agreement ("نقطة واحدة / نقطتان / 3 نقاط / 12 نقطة"): the one regex with @N@ becomes four, one per class of count.
$count = static function (string $re, string $one, string $two, string $few, string $many): array {
    return [
        str_replace('@N@', '(1)', $re) => $one,
        str_replace('@N@', '(2)', $re) => $two,
        str_replace('@N@', '([3-9]|10)', $re) => $few,
        str_replace('@N@', '(1[1-9]|[2-9]\\d|\\d{3,})', $re) => $many,
    ];
};
// A date as PHP prints it ("13 Jan 2026"); pages in Arabic print the Arabic month themselves (View::date), hence {t…} for dates.
$date = '(\\d{1,2} [A-Za-z\\x{0600}-\\x{06FF}]+ \\d{4})';
// The kinds of evidence, in lower case as they appear inside the closeout sentences (Evidence::KINDS), and "kind + kind" lists of them.
$kind = 'assessment paper \\/ brief|marking rubric|sample of marked student work|other evidence';
$kinds = '(?:' . $kind . ')(?: \\+ (?:' . $kind . '))*';
$gap = '.+? \\((?:' . $kind . ')(?:, (?:' . $kind . '))*\\)';
// Names of the checklist items (Closeout::forOffering) as they appear in reminders: "A; B; C".
$labels = implode('|', array_map(static fn(string $s): string => preg_quote($s, '/'), [
    'Approved course specification', 'No open problems', 'Grades for every assessment', 'Evidence for every assessment, reviewed',
    'A plan for every missed goal', "The instructor's reading of the results", 'Suggestions for next time',
]));
// Why an evidence file was refused (Evidence::store), as it appears in "Not added: file (reason)" with the final full stop removed.
$refusals = implode('|', [
    'The file is larger than the server allows', 'Choose a file to upload', 'Evidence files must be smaller than \\d+ MB',
    'Choose what kind of evidence this is',
    'Upload a PDF, Word, Excel, PowerPoint, PNG, JPEG or text file \\(macro-enabled Office files are not accepted\\)',
    'The file content does not match its type \\(\\.\\w+\\)', 'Office files with macros are not accepted\\. Save it as a normal \\.\\w+ or PDF',
    "That assessment is not part of this course's specification", 'The evidence store is not writable \\(.*?\\)',
    'The virus scanner is not available, so uploads are paused\\. IT has been alerted; please try again later',
    'The file was refused by the virus scanner\\. IT security has been notified', 'The virus scanner could not check the file \\(.*?\\)',
    'Too many uploads in the last hour\\. Please try again later',
]);
$p = [];

// ---------------------------------------------------------------------------------------------------------------------
// API messages and activity-log summaries (public/api.php)
$p += $count(
    '/^@N@ new result batch\\(es\\) imported from the LMS; achievement recalculated\\.$/u',
    'استُوردت دفعة نتائج جديدة من نظام إدارة التعلم؛ وأُعيد حساب نسب التحقق.',
    'استُوردت دفعتا نتائج جديدتان من نظام إدارة التعلم؛ وأُعيد حساب نسب التحقق.',
    'استُوردت {1} دفعات نتائج جديدة من نظام إدارة التعلم؛ وأُعيد حساب نسب التحقق.',
    'استُوردت {1} دفعة نتائج جديدة من نظام إدارة التعلم؛ وأُعيد حساب نسب التحقق.'
);
$p += [
    '/^([A-Z]{2,6}) ((?!CLO)[A-Z]+\\d+) updated$/u' => 'حُدّث مخرج البرنامج {2} في {1}',
    '/^([A-Z]{2,6}) ((?!CLO)[A-Z]+\\d+) added$/u' => 'أُضيف مخرج البرنامج {2} في {1}',
    '/^([A-Z]{2,6}) ((?!CLO)[A-Z]+\\d+) retired$/u' => 'أُوقف مخرج البرنامج {2} في {1}',
    '/^([A-Z]{2,6}) mission updated$/u' => 'حُدّثت رسالة البرنامج {1}',
    '/^([A-Z]{2,6}) goals updated$/u' => 'حُدّثت أهداف البرنامج {1}',
    '/^Evidence "(.+)" downloaded$/u' => 'نُزّل الشاهد «{1}»',
    '/^Evidence "(.+)" removed from the course file$/u' => 'أُزيل الشاهد «{1}» من ملف المقرر',
];
// "Access denied to <what> (role <role>)" for the actions of this area (the audit entry of a refused request).
foreach ([
    'insight' => 'نمط لاحظه SAQF', 'LMS check' => 'فحص نظام إدارة التعلم', 'improvement action' => 'إجراء التحسين',
    'improvement status' => 'حالة إجراء التحسين', 'exception request' => 'طلب الاستثناء', 'override decision' => 'قرار الاستثناء',
    'override' => 'استثناء قاعدة', 'resolving finding' => 'معالجة مشكلة', 'specification decision' => 'قرار التوصيف',
    'QA decision' => 'قرار الجودة', 'QA sampling' => 'التدقيق بالعينة', 'PLO management' => 'إدارة مخرجات البرنامج',
    'program narrative' => 'الوصف النصي للبرنامج', 'quality policy' => 'سياسة الجودة', 'changing evidence' => 'تعديل الشواهد',
    'removing evidence' => 'إزالة الشواهد', 'uploading results' => 'رفع النتائج', 'adding evidence' => 'إضافة الشواهد',
    'reviewing the course file' => 'مراجعة ملف المقرر',
] as $what => $ar) {
    $p['/^Access denied to ' . preg_quote($what, '/') . ' \\(role (\\w+)\\)$/u'] = 'رُفض الوصول إلى ' . $ar . ' (الدور: {t1})';
}

// ---------------------------------------------------------------------------------------------------------------------
// Closeout board and My courses
$p += [
    '/^Course file closeout board exported to CSV for (.+) \\((\\d+) courses\\)$/u' => 'صُدّرت لوحة إغلاق ملفات المقررات إلى CSV للفصل الدراسي {t1} (عدد المقررات: {2})',
    '/^(\\d+) ready$/u' => '{1} جاهز',
    '/^(\\d+) waiting for a person$/u' => '{1} بانتظار شخص',
    '/^(\\d+) with items missing$/u' => '{1} فيها بنود ناقصة',
    '/^Grades due (.+?) \\(0 days\\)$/u' => 'موعد تسليم الدرجات {t1} (اليوم)',
    '/^(\\d+) of (\\d+) done, (\\d+) missing, (\\d+) waiting for a person$/u' => 'أُنجز {1} من {2}، وناقص {3}، وبانتظار شخص {4}',
    '/^(\\d+) of (\\d+) done$/u' => 'أُنجز {1} من {2}',
    '/^(\\d+) to review$/u' => '{1} للمراجعة',
    '/^Course file: (\\d+) of (\\d+) done$/u' => 'ملف المقرر: أُنجز {1} من {2}',
    '/^next: (.+)$/u' => 'التالي: {t1}',
    '/^Nothing needs you right now, (.+)\\.$/u' => 'لا شيء يحتاج إليك الآن، {1}.',
];
$p += $count(
    '/^Grades due (.+?) \\(@N@ days?\\)$/u',
    'موعد تسليم الدرجات {t1} (غداً)',
    'موعد تسليم الدرجات {t1} (بعد يومين)',
    'موعد تسليم الدرجات {t1} (بعد {2} أيام)',
    'موعد تسليم الدرجات {t1} (بعد {2} يوماً)'
);
$p += $count(
    '/^Grades were due (.+?) \\(@N@ days? ago\\)$/u',
    'كان موعد تسليم الدرجات {t1} (منذ يوم واحد)',
    'كان موعد تسليم الدرجات {t1} (منذ يومين)',
    'كان موعد تسليم الدرجات {t1} (منذ {2} أيام)',
    'كان موعد تسليم الدرجات {t1} (منذ {2} يوماً)'
);

// ---------------------------------------------------------------------------------------------------------------------
// Course workspace: overview and outcomes
$p += [
    '/^This course record was created on (.+) when the course was assigned\\.$/u' => 'أُنشئ سجل هذا المقرر في {t1} عند إسناد المقرر.',
    '/^Version (\\d+) is with the Head of Department for a decision, so it cannot be edited right now\\. Version (\\d+) stays in use meanwhile\\.$/u' => 'الإصدار {1} لدى رئيس القسم للبتّ فيه، لذا لا يمكن تعديله الآن. ويبقى الإصدار {2} معمولاً به في الأثناء.',
    '/^Version (\\d+) is with Quality for a decision, so it cannot be edited right now\\. Version (\\d+) stays in use meanwhile\\.$/u' => 'الإصدار {1} لدى الجودة للبتّ فيه، لذا لا يمكن تعديله الآن. ويبقى الإصدار {2} معمولاً به في الأثناء.',
    '/^Version (\\d+) is with the Head of Department for a decision, so it cannot be edited right now\\.$/u' => 'الإصدار {1} لدى رئيس القسم للبتّ فيه، لذا لا يمكن تعديله الآن.',
    '/^Version (\\d+) is with Quality for a decision, so it cannot be edited right now\\.$/u' => 'الإصدار {1} لدى الجودة للبتّ فيه، لذا لا يمكن تعديله الآن.',
    '/^This is the approved course specification \\(version (\\d+)\\), carried over to this term automatically\\. Editing anything starts a new draft; the approved version stays in use until your changes are approved\\.$/u' => 'هذا هو توصيف المقرر المعتمد (الإصدار {1})، وقد انتقل إلى هذا الفصل تلقائياً. ويبدأ أي تعديل مسودة جديدة، ويبقى الإصدار المعتمد معمولاً به إلى أن تُعتمد تغييراتك.',
    '/^Returned: (.+)$/u' => 'أُعيد: {1}',
    '/^you teach section (\\S+)$/u' => 'تُدرّس الشعبة {1}',
    '/^you teach section (\\S+, .+)$/u' => 'تُدرّس الشعب {1}',
    '/^similarity ([\\d.]+)$/u' => 'التشابه {1}',
];
// "Jahiziah skills: Digital,Communication" (the tags are stored without spaces).
$skill = 'Digital|Communication|Teamwork|Ethics|Problem solving|Leadership';
for ($n = 1; $n <= 6; $n++) {
    $p['/^Jahiziah skills: (' . $skill . ')' . str_repeat(',(' . $skill . ')', $n - 1) . '$/u'] = 'مهارات جاهزية: ' . implode('، ', array_map(static fn(int $i): string => '{t' . $i . '}', range(1, $n)));
}

// ---------------------------------------------------------------------------------------------------------------------
// Course workspace: results import preview and grade batches
$p += [
    '/^in the file \\(the course has (\\d+) enrolled\\)\\. Student numbers were already replaced by codes: neither this page nor the database shows them\\.$/u' => 'في الملف (عدد المسجّلين في المقرر: {1}). استُبدلت الأرقام الجامعية برموز بالفعل: فلا تعرضها هذه الصفحة ولا قاعدة البيانات.',
    '/^"(.+?)": every mark is between 0 and 1\\. Marks must be percentages from 0 to 100; check the file is not in fractions\\.$/u' => '«{1}»: كل الدرجات بين 0 و1. يجب أن تكون الدرجات نسباً مئوية من 0 إلى 100؛ تأكد أن الملف ليس بالكسور.',
    '/^"(.+?)": (\\d+) of (\\d+) marks are 0\\. Zeros count as real marks; leave a cell empty for a student who did not take it\\.$/u' => '«{1}»: عدد الدرجات التي تساوي 0 هو {2} من {3}. تُحتسب الأصفار درجات حقيقية؛ اترك الخانة فارغة للطالب الذي لم يؤدِّ التقييم.',
    '/^"(.+?)": (\\d+) student\\(s\\) already have a mark here; the new mark replaces it\\.$/u' => '«{1}»: عدد الطلاب الذين لديهم درجة هنا مسبقاً هو {2}؛ وستحل الدرجة الجديدة محلها.',
    '/^Not in the course specification, so left out: (.+)\\.$/u' => 'غير واردة في توصيف المقرر، لذا استُبعدت: {1}.',
    '/^These marks will be filed under section (.+)\\.$/u' => 'ستُسجَّل هذه الدرجات في الشعبة {1}.',
    '/^Sections found in the file: (.+)\\.$/u' => 'الشعب الموجودة في الملف: {1}.',
    '/^Uploaded by (.+)$/u' => 'رُفعت بواسطة {1}',
    '/^Coming from the gradebook: (.+) \\(expected (.+)\\)\\. SAQF imports it as soon as it is published\\.$/u' => 'قادمة من دفتر الدرجات: {t1} (المتوقع {t2}). يستوردها SAQF فور نشرها.',
    '/^(\\d+) KB$/u' => '{1} كيلوبايت',
    '/^(\\d+(?:\\.\\d+)?) MB$/u' => '{1} ميغابايت',
    '/^Up to 12 files at a time \\(together under (\\d+)M\\): PDF, Word, Excel, PowerPoint, images or text, each up to (\\d+) MB\\. SAQF suggests what each file is from its name; you decide\\. Files are checked for safety before they are stored\\. Please remove student names from samples\\.$/u' => 'حتى 12 ملفاً في المرة الواحدة (بمجموع لا يتجاوز {1} ميغابايت): PDF أو Word أو Excel أو PowerPoint أو صور أو نص، وبحد أقصى {2} ميغابايت للملف الواحد. يقترح SAQF نوع كل ملف من اسمه، والقرار لك. تُفحص الملفات للسلامة قبل حفظها. يرجى إزالة أسماء الطلاب من النماذج.',
    '/^Up to 12 files at a time \\(together under (\\S+)\\): PDF, Word, Excel, PowerPoint, images or text, each up to (\\d+) MB\\. SAQF suggests what each file is from its name; you decide\\. Files are checked for safety before they are stored\\. Please remove student names from samples\\.$/u' => 'حتى 12 ملفاً في المرة الواحدة (بمجموع لا يتجاوز {1}): PDF أو Word أو Excel أو PowerPoint أو صور أو نص، وبحد أقصى {2} ميغابايت للملف الواحد. يقترح SAQF نوع كل ملف من اسمه، والقرار لك. تُفحص الملفات للسلامة قبل حفظها. يرجى إزالة أسماء الطلاب من النماذج.',
];
$p += $count(
    '/^@N@ files? added to the course file\\. Any matching evidence request cleared automatically\\.$/u',
    'أُضيف ملف واحد إلى ملف المقرر، وزال أي طلب مطابق تلقائياً.',
    'أُضيف ملفان إلى ملف المقرر، وزال أي طلب مطابق تلقائياً.',
    'أُضيفت {1} ملفات إلى ملف المقرر، وزال أي طلب مطابق تلقائياً.',
    'أُضيف {1} ملفاً إلى ملف المقرر، وزال أي طلب مطابق تلقائياً.'
);
// Several files refused at once: "Not added: a.pdf (reason); b.docx (reason)."
$p += [
    '/^Not added: (.+)\\.$/u' => 'لم يُضَف: {t1}.',
    '/^(.{1,80}? \\((?:' . $refusals . ')\\)); (.+)$/u' => '{t1}؛ {t2}',
    '/^(.{1,80}?) \\(((?:' . $refusals . '))\\)$/u' => '{1} ({t2})',
];

// ---------------------------------------------------------------------------------------------------------------------
// Course report: "Facts for your reading" (Facts.php). A fact about an outcome is cut at " · " by the page filter, and the part
// before it starts with the outcome code, which an older pattern (code: text) already handles by translating the rest: so the
// pieces are written without the code.
$p += [
    '/^All (\\d+) assessments have grades\\.$/u' => 'جميع التقييمات ({1}) لها درجات.',
    '/^(\\d+)% of students met it \\(goal (\\d+)%\\)$/u' => 'حققه {1}% من الطلاب (الهدف {2}%)',
    '/^(\\d+)% of students met it so far \\(goal (\\d+)%\\)$/u' => 'حققه {1}% من الطلاب حتى الآن (الهدف {2}%)',
    '/^the average mark is (\\d+)% \\(goal (\\d+)%\\)$/u' => 'متوسط الدرجات {1}% (الهدف {2}%)',
    '/^the average mark so far is (\\d+)% \\(goal (\\d+)%\\)$/u' => 'متوسط الدرجات حتى الآن {1}% (الهدف {2}%)',
    '/^(\\d+ students?); (.+)$/u' => '{t1}؛ {t2}',
    '/^the same as (.+) \\((\\d+)%\\)\\.$/u' => 'مثل {t1} ({2}%).',
    '/^Last term\'s action "(.+)": (\\d+)% before, (\\d+)% now\\. Other things may also have played a part\\.$/u' => 'إجراء الفصل الماضي «{t1}»: كانت النسبة {2}% قبله و{3}% الآن. وقد يكون لعوامل أخرى دور في ذلك أيضاً.',
];
$p += $count(
    '/^((?:the average mark|\\d+% of students met it).*\\(goal \\d+%\\)), @N@ points? short$/u',
    '{t1}، وتنقصه نقطة واحدة',
    '{t1}، وتنقصه نقطتان',
    '{t1}، وتنقصه {2} نقاط',
    '{t1}، وتنقصه {2} نقطة'
);
$p += $count(
    '/^@N@ points? up from (.+) \\((\\d+)%\\)\\.$/u',
    'ارتفع بمقدار نقطة واحدة عن {t2} ({3}%).',
    'ارتفع بمقدار نقطتين عن {t2} ({3}%).',
    'ارتفع بمقدار {1} نقاط عن {t2} ({3}%).',
    'ارتفع بمقدار {1} نقطة عن {t2} ({3}%).'
);
$p += $count(
    '/^@N@ points? down from (.+) \\((\\d+)%\\)\\.$/u',
    'انخفض بمقدار نقطة واحدة عن {t2} ({3}%).',
    'انخفض بمقدار نقطتين عن {t2} ({3}%).',
    'انخفض بمقدار {1} نقاط عن {t2} ({3}%).',
    'انخفض بمقدار {1} نقطة عن {t2} ({3}%).'
);
$p += $count(
    '/^section (\\S+) is @N@ points behind section (\\S+)\\.$/u',
    'الشعبة {1} متأخرة عن الشعبة {3} بمقدار نقطة واحدة.',
    'الشعبة {1} متأخرة عن الشعبة {3} بمقدار نقطتين.',
    'الشعبة {1} متأخرة عن الشعبة {3} بمقدار {2} نقاط.',
    'الشعبة {1} متأخرة عن الشعبة {3} بمقدار {2} نقطة.'
);
$p += $count(
    '/^@N@ improvement records? (?:is|are) prepared and waiting for your plan\\.$/u',
    'سجل تحسين واحد أُعدّ وهو بانتظار خطتك.',
    'سجلا تحسين أُعدّا وهما بانتظار خطتك.',
    '{1} سجلات تحسين أُعدّت وهي بانتظار خطتك.',
    '{1} سجلاً للتحسين أُعدّت وهي بانتظار خطتك.'
);
$p += $count(
    '/^@N@ improvement plans? (?:is|are) in place from this term\\.$/u',
    'وُضعت خطة تحسين واحدة في هذا الفصل.',
    'وُضعت خطتا تحسين في هذا الفصل.',
    'وُضعت {1} خطط تحسين في هذا الفصل.',
    'وُضعت {1} خطة تحسين في هذا الفصل.'
);
$p += $count(
    '/^@N@ files? in the course file\\.$/u',
    'ملف واحد في ملف المقرر.',
    'ملفان في ملف المقرر.',
    '{1} ملفات في ملف المقرر.',
    '{1} ملفاً في ملف المقرر.'
);

// ---------------------------------------------------------------------------------------------------------------------
// Course file closeout (Closeout.php): checklist lines, the reviewer's decision, reminders, the package
$p += [
    '/^Checklist set by Quality \\(last change (.+?) by (.+)\\)\\.$/u' => 'قائمة التحقق من وضع الجودة (آخر تغيير في {t1} بواسطة {2}).',
    '/^Checklist set by Quality \\(last change (.+)\\)\\.$/u' => 'قائمة التحقق من وضع الجودة (آخر تغيير في {t1}).',
    '/^Last review: accepted by (.+?) on (\\d{1,2} \\S+ \\d{4}) \\(files changed since\\)$/u' => 'آخر مراجعة: قبِل {1} الشواهد في {t2} (تغيّرت الملفات منذ ذلك الحين)',
    '/^Last review: returned by (.+?) on (\\d{1,2} \\S+ \\d{4}) \\(files changed since\\)$/u' => 'آخر مراجعة: أعاد {1} الشواهد في {t2} (تغيّرت الملفات منذ ذلك الحين)',
    '/^Last review: accepted by (.+?) on (\\d{1,2} \\S+ \\d{4})$/u' => 'آخر مراجعة: قبِل {1} الشواهد في {t2}',
    '/^Last review: returned by (.+?) on (\\d{1,2} \\S+ \\d{4})$/u' => 'آخر مراجعة: أعاد {1} الشواهد في {t2}',
    '/^Version (\\d+) approved ' . $date . ' is in use\\.$/u' => 'الإصدار {1} المعتمد في {t2} هو المعمول به.',
    '/^Version (\\d+) is waiting for a decision\\.$/u' => 'الإصدار {1} بانتظار القرار.',
    '/^All (\\d+) assessments have grades; achievement is final\\.$/u' => 'جميع التقييمات ({1}) لها درجات؛ والتحقق نهائي.',
    '/^(\\d+) of (\\d+) assessments have the required evidence \\((' . $kinds . ')\\)\\. Missing: (' . $gap . '(?:; ' . $gap . ')*); …\\.$/u' => 'التقييمات التي لديها الشواهد المطلوبة ({t3}): {1} من {2}. الناقص: {t4}؛ …',
    '/^(\\d+) of (\\d+) assessments have the required evidence \\((' . $kinds . ')\\)\\. Missing: (' . $gap . '(?:; ' . $gap . ')*)\\.$/u' => 'التقييمات التي لديها الشواهد المطلوبة ({t3}): {1} من {2}. الناقص: {t4}.',
    '/^(' . $gap . '); (.+)$/u' => '{t1}؛ {t2}',
    '/^(.+?) \\(((?:' . $kind . ')(?:, (?:' . $kind . '))*)\\)$/u' => '{t1} ({t2})',
    '/^(' . $kind . ') \\+ (.+)$/u' => '{t1} + {t2}',
    '/^Every assessment has (' . $kinds . ')\\. Accepted by (.+?) on ' . $date . ': "(.*)"$/u' => 'لكل تقييم: {t1}. قبل {2} الشواهد في {t3}: «{4}»',
    '/^Every assessment has (' . $kinds . ')\\. Accepted by (.+?) on ' . $date . '\\.$/u' => 'لكل تقييم: {t1}. قبل {2} الشواهد في {t3}.',
    '/^Returned by (.+?) on ' . $date . ': "(.*)"$/u' => 'أعاد {1} الشواهد في {t2}: «{3}»',
    '/^Every assessment has (' . $kinds . ')\\. The files changed after the last review, so it is due again\\.$/u' => 'لكل تقييم: {t1}. تغيّرت الملفات بعد آخر مراجعة، لذا فالمراجعة مستحقة من جديد.',
    '/^Every assessment has (' . $kinds . ')\\. No person has reviewed the set yet\\.$/u' => 'لكل تقييم: {t1}. لم يراجع أي شخص المجموعة بعد.',
    '/^(\\d+) missed goal\\(s\\) without an improvement plan\\.$/u' => 'أهداف لم تتحقق وليست لها خطة تحسين: {1}.',
    '/^(\\d+) plan\\(s\\) written by the instructor\\.$/u' => 'خطط التحسين التي كتبها عضو هيئة التدريس: {1}.',
    '/^Sealed on ' . $date . ' when the term closed \\(fingerprint ([0-9a-f]{12})\\)\\. Sealing records the content; it is not an approval of the report\\.$/u' => 'خُتم في {t1} عند إغلاق الفصل (البصمة {2}). الختم يسجّل المحتوى؛ وهو ليس اعتماداً للتقرير.',
    '/^(.+?) course file evidence accepted by (.+)$/u' => 'قبل {2} شواهد ملف مقرر {1}',
    '/^(.+?) course file evidence returned to the instructor by (.+)$/u' => 'أعاد {2} شواهد ملف مقرر {1} إلى عضو هيئة التدريس',
    '/^Course file package for (.+?) ((?:Fall|Spring|Summer) \\d{4}) downloaded \\((\\d+) evidence files indexed, not included\\)$/u' => 'نُزّلت حزمة ملف مقرر {1} للفصل {t2} (عدد ملفات الشواهد المفهرسة: {3}، وهي غير مضمّنة في الحزمة)',
    '/^(' . $labels . '); (.+)$/u' => '{t1}؛ {t2}',
    '/^(.+?) course file is overdue: (\\d+) item\\(s\\) missing$/u' => 'ملف مقرر {1} متأخر؛ عدد البنود الناقصة: {2}',
    '/^(.+?): 1 course file item still missing \\((.+)\\)$/u' => '{1}: بند واحد ناقص في ملف المقرر ({t2})',
    '/^(.+?): 2 course file items still missing \\((.+)\\)$/u' => '{1}: بندان ناقصان في ملف المقرر ({t2})',
    '/^(.+?): (\\d+) course file items still missing \\((.+)\\)$/u' => '{1}: {2} بنود ناقصة في ملف المقرر ({t3})',
];
$p += $count(
    '/^grades were due @N@ days? ago$/u',
    'حلّ موعد الدرجات منذ يوم واحد',
    'حلّ موعد الدرجات منذ يومين',
    'حلّ موعد الدرجات منذ {1} أيام',
    'حلّ موعد الدرجات منذ {1} يوماً'
);
$p += $count(
    '/^grades are due in @N@ days?$/u',
    'موعد الدرجات غداً',
    'موعد الدرجات بعد يومين',
    'موعد الدرجات بعد {1} أيام',
    'موعد الدرجات بعد {1} يوماً'
);
$p['/^grades are due today$/u'] = 'موعد الدرجات اليوم';
// "still to come: A, B, C": each assessment name is translated on its own (the Arabic name of one that has none yet stays as it is).
for ($n = 1; $n <= 8; $n++) {
    $names = implode(', ', array_fill(0, $n, '([^,]+?)'));
    $ar = implode('، ', array_map(static fn(int $i): string => '{t' . $i . '}', range(3, $n + 2)));
    $p['/^(\\d+) of (\\d+) assessments have grades\\. Still to come: ' . $names . '\\. The figures below are "so far"\\.$/u'] = 'التقييمات التي لها درجات: {1} من {2}. المتبقي: ' . $ar . '. والأرقام أدناه «حتى الآن».';
    $p['/^(\\d+) of (\\d+) assessments have grades; still to come: ' . $names . '\\. Achievement is shown as "so far" until then\\.$/u'] = 'التقييمات التي لها درجات: {1} من {2}؛ والمتبقي: ' . $ar . '. يظهر التحقق بوصفه «حتى الآن» إلى أن تصل الدرجات المتبقية.';
}
// Lists of up to three titles ("A; B; C" or "A; B; C; …") in the open-problems and drafted-improvement lines.
$t = '([^;]+?)';
foreach ([[1, false], [2, false], [3, false], [3, true]] as [$n, $more]) {
    $titles = implode('; ', array_fill(0, $n, $t)) . ($more ? '; …' : '');
    $ar = implode('؛ ', array_map(static fn(int $i): string => '{t' . $i . '}', range(2, $n + 1))) . ($more ? '؛ …' : '');
    $p['/^(\\d+) open: ' . $titles . '$/u'] = 'مشكلات مفتوحة ({1}): ' . $ar;
    $p['/^(\\d+) waiting for a decision: ' . $titles . '$/u'] = 'بانتظار القرار ({1}): ' . $ar;
}
foreach ([1, 2, 3] as $n) {
    $titles = implode('; ', array_fill(0, $n, $t));
    $ar = implode('؛ ', array_map(static fn(int $i): string => '{t' . $i . '}', range(2, $n + 1)));
    $p['/^(\\d+) improvement record\\(s\\) prepared by SAQF with the facts, waiting for the instructor\'s own plan: ' . $titles . '\\.$/u'] = 'سجلات تحسين أعدّها SAQF مع الحقائق وهي بانتظار خطة عضو هيئة التدريس نفسه ({1}): ' . $ar . '.';
}

// ---------------------------------------------------------------------------------------------------------------------
// Evidence (Evidence.php): refusals, scanner alerts and activity-log summaries
$p += [
    '/^"(.+)" for (.+?) was refused: (.+)$/u' => 'رُفض «{1}» الخاص بمقرر {2}: {3}',
    '/^Upload "(.+)" refused: the virus scanner reported (.+)$/u' => 'رُفض رفع «{1}»: أبلغ فاحص الفيروسات بما يلي: {2}',
    '/^Evidence uploads are refused until clamd at (.+?) answers \\((.*)\\)\\.$/u' => 'يُرفض رفع الشواهد إلى أن تستجيب خدمة clamd على {1} ({2}).',
];
// The same refusals as whole sentences (with a full stop) and inside "Not added: file (reason)" (without it).
foreach ([
    'Evidence files must be smaller than (\\d+) MB' => 'يجب أن يكون حجم ملفات الشواهد أقل من {1} ميغابايت',
    'The evidence store is not writable \\((.*)\\)' => 'مخزن الشواهد غير قابل للكتابة ({1})',
    'The file content does not match its type \\(\\.(\\w+)\\)' => "محتوى الملف لا يطابق نوعه (\u{200E}.{1})",
    'Office files with macros are not accepted\\. Save it as a normal \\.(\\w+) or PDF' => "لا تُقبل ملفات Office التي تحتوي على وحدات ماكرو. احفظه ملفاً عادياً بصيغة \u{200E}.{1} أو PDF",
    'The virus scanner could not check the file \\((.*)\\)' => 'تعذّر على فاحص الفيروسات فحص الملف ({1})',
] as $en => $ar) {
    $p['/^' . $en . '\\.$/u'] = $ar . '.';
    $p['/^' . $en . '$/u'] = $ar;
}

// ---------------------------------------------------------------------------------------------------------------------
// Gradebook files, imported results and the background scheduler
$p += [
    '/^Line (\\d+): score for "(.+)" must be a percentage between 0 and 100\\.$/u' => 'السطر {1}: يجب أن تكون درجة «{2}» نسبة مئوية بين 0 و100.',
    '/^These assessments are not in the course specification: (.+)\\. Results must use the specification\'s assessment names\\.$/u' => 'هذه التقييمات غير واردة في توصيف المقرر: {1}. يجب أن تستخدم النتائج أسماء التقييمات الواردة في التوصيف.',
    '/^(\\d+) result rows imported from UPLOAD \\((.+)\\)$/u' => 'استُورد {1} سطر نتائج من ملف مرفوع ({t2})',
    '/^(.+?): LMS gradebook columns not in the course specification were not imported: (.+)$/u' => '{1}: لم تُستورد أعمدة دفتر درجات نظام إدارة التعلم غير الواردة في توصيف المقرر: {2}',
    '/^(.+?): columns in the uploaded file that are not in the course specification were not imported: (.+)$/u' => '{1}: لم تُستورد أعمدة الملف المرفوع غير الواردة في توصيف المقرر: {2}',
    '/^(.+) closed without activation \\(the SIS calendar has moved on to (.+)\\)$/u' => 'أُغلق {t1} دون تفعيل (انتقل تقويم نظام معلومات الطلاب إلى {t2})',
    '/^((?:Chain link broken at entry #\\d+ |Entry #\\d+ content does not match its hash ).+) Restore the audit_log table from the last good backup and investigate database access\\.$/u' => '{t1} استعد جدول audit_log من آخر نسخة احتياطية سليمة وافحص من يصل إلى قاعدة البيانات.',
    '/^(\\d+) of (\\d+) account\\(s\\) have not been confirmed by an administrator in the last (\\d+) days\\. Open Administration → Access review\\.$/u' => 'الحسابات التي لم يؤكد مسؤول صلاحياتها خلال آخر {3} يوماً: {1} من {2}. افتح الإدارة ← مراجعة الصلاحيات.',
    '/^The scheduler last ran at (.+)\\. Check the app container \\(it runs bin\\/tick\\.php every 5 minutes\\) or the server cron job\\. Pages keep triggering it as a safety net meanwhile\\.$/u' => 'آخر تشغيل للمُجدوِل كان في {1}. تحقق من حاوية التطبيق (وهي تشغّل bin/tick.php كل 5 دقائق) أو من مهمة cron على الخادم. وفي الأثناء تواصل الصفحات تشغيله كشبكة أمان.',
    '/^(\\d+) e-mail\\(s\\) could not be sent: (.+)$/u' => 'تعذّر إرسال {1} من رسائل البريد الإلكتروني: {2}',
];
// "Password policy; Session binding. Open Administration → Security center and press "Run security self-test" for details."
foreach ([1, 2, 3] as $n) {
    $p['/^' . implode('; ', array_fill(0, $n, '([^;]+?)')) . '\\. Open Administration → Security center and press "Run security self-test" for details\\.$/u']
        = implode('؛ ', array_map(static fn(int $i): string => '{t' . $i . '}', range(1, $n))) . '. افتح الإدارة ← مركز الأمان واضغط «تشغيل الاختبار الذاتي للأمان» لمعرفة التفاصيل.';
}

// ---------------------------------------------------------------------------------------------------------------------
// Needs a one-line change in an older file before it can apply (see the report): an older, broader pattern matches these first.
//  patterns-2.php  '^Written (.+)$'  -> exclude "Written by "   for the closeout line "Written by Dr. X on 2 Jun 2026."
//  patterns-3.php  '^by (.+)$'       -> exclude a date after "by"  for the deadline "by 12 Dec 2026" on My courses
$p += [
    '/^Written by (.+?) on ' . $date . '\\.$/u' => 'كتبه {1} في {t2}.',
    '/^by (\\d{1,2} [A-Za-z\\x{0600}-\\x{06FF}]+ \\d{4})$/u' => 'قبل {t1}',
];

return $p;
