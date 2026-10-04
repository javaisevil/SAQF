<?php declare(strict_types=1);

// Arabic wording, area A1 (faculty workflow): API messages, closeout board, My courses, the course workspace (results import, report facts, evidence, closeout), Closeout / Evidence / Facts / Scheduler / Gradebook wording.
// Whole sentences only; sentences with numbers, dates and names are patterns in patterns-A1-faculty-workflow.php. Terms follow the existing dictionary (NCAAA usage).

return [
    // ------------------------------------------------------------------ API messages (toasts and errors)
    'Your session has expired. Sign in again.' => 'انتهت جلستك. سجّل الدخول مرة أخرى.',
    'Security token expired. Reload the page.' => 'انتهت صلاحية رمز الأمان. أعد تحميل الصفحة.',
    'SAQF is in maintenance mode.' => 'SAQF في وضع الصيانة.',
    'Outcome updated.' => 'حُدّث المخرج.',
    'Outcome added.' => 'أُضيف المخرج.',
    'Mapping added.' => 'أُضيف الربط.',
    'Mapping removed.' => 'أُزيل الربط.',
    'Assessment updated.' => 'حُدّث التقييم.',
    'Assessment added.' => 'أُضيف التقييم.',
    'Assessment now measures this outcome.' => 'أصبح التقييم يقيس هذا المخرج.',
    'Link removed.' => 'أُزيل الربط.',
    'The LMS has no new published results for this course yet.' => 'لا توجد في نظام إدارة التعلم نتائج منشورة جديدة لهذا المقرر حتى الآن.',
    'Unknown action.' => 'إجراء غير معروف.',
    'QA spot-check of an auto-cleared specification: concern raised' => 'تدقيق الجودة بالعينة لتوصيف اعتُمد تلقائياً: أُثيرت ملاحظة',
    'QA spot-check of an auto-cleared specification: no concerns' => 'تدقيق الجودة بالعينة لتوصيف اعتُمد تلقائياً: لا ملاحظات',
    'Course report interpretation updated' => 'حُدّث قسم «ماذا تعني النتائج» في تقرير المقرر',
    'Course report difficulties updated' => 'حُدّث قسم «صعوبات هذا الفصل» في تقرير المقرر',
    'Course report recommendations updated' => 'حُدّث قسم «مقترحات للمرة القادمة» في تقرير المقرر',

    // ------------------------------------------------------------------ Course file closeout board (Head of Department, Quality, dean)
    'Too many downloads in the last hour. Please try again later.' => 'عمليات تنزيل كثيرة جداً خلال الساعة الماضية. يرجى المحاولة لاحقاً.',
    'Who has what left to do, across the courses you can see' => 'من تبقّى عليه ماذا، عبر المقررات التي يمكنك الاطلاع عليها',
    "Every line is read from SAQF's records: nothing is marked done until the records show it. The checklist itself is Quality's to set (" => 'كل سطر مقروء من سجلات SAQF، ولا يُعلَّم أي بند بأنه منجز حتى تُظهر السجلات ذلك. أما قائمة التحقق نفسها فتضعها الجودة (',
    "); until Quality confirms it, it is SAQF's default." => ')؛ وإلى أن تؤكدها الجودة تبقى القائمة هي الإعداد الافتراضي في SAQF.',
    'No term' => 'لا يوجد فصل دراسي',
    'All courses' => 'كل المقررات',
    'Items missing' => 'بنود ناقصة',
    'Waiting for a person' => 'بانتظار شخص',
    'Ready' => 'جاهز',
    'overdue' => 'متأخر',
    'Nothing to show' => 'لا شيء لعرضه',
    'No course matches this filter in this term.' => 'لا يوجد مقرر يطابق هذه التصفية في هذا الفصل الدراسي.',

    // ------------------------------------------------------------------ My courses (faculty home)
    'SAQF keeps checking your courses and will list anything new here.' => 'يواصل SAQF فحص مقرراتك وسيعرض هنا أي جديد.',
    '1 credit hour' => 'ساعة معتمدة واحدة',

    // ------------------------------------------------------------------ Course workspace: overview and outcomes tabs
    'Explain why this course should be treated as an exception (Quality sees this, and it is kept in the audit log).' => 'اشرح لماذا ينبغي معاملة هذا المقرر بوصفه استثناءً (تطّلع الجودة على ذلك ويُحفظ في سجل التدقيق).',
    'Define the outcomes and assessment plan once — SAQF will reuse them every term.' => 'حدّد المخرجات وخطة التقييم مرة واحدة، وسيعيد SAQF استخدامها في كل فصل دراسي.',
    'No prerequisite' => 'لا يوجد متطلب سابق',
    'No instructor' => 'لا يوجد عضو هيئة تدريس',
    'No instructor yet' => 'لم يُسنَد المقرر إلى عضو هيئة تدريس بعد',
    'no instructor' => 'بلا عضو هيئة تدريس',
    'Start with an observable verb, e.g. “Analyze…”, “Design…”, “Evaluate…”' => 'ابدأ بفعل قابل للملاحظة، مثل: «يحلّل…»، «يصمّم…»، «يقيّم…»',

    // ------------------------------------------------------------------ Course workspace: results import preview
    'Choose at least one file.' => 'اختر ملفاً واحداً على الأقل.',
    'Add up to 12 files at a time.' => 'أضف حتى 12 ملفاً في المرة الواحدة.',
    'Too many uploads in the last hour. Please try again later.' => 'عمليات رفع كثيرة جداً خلال الساعة الماضية. يرجى المحاولة لاحقاً.',
    'Nothing was imported.' => 'لم يُستورد شيء.',
    'The preview expired or had nothing to import. Upload the file again.' => 'انتهت صلاحية المعاينة أو لم يكن فيها ما يُستورد. ارفع الملف مرة أخرى.',
    'Check before importing' => 'تحقّق قبل الاستيراد',
    'Nothing imported yet' => 'لم يُستورد شيء بعد',
    'New' => 'جديد',
    'Replaces' => 'يستبدل',
    'Lowest–highest' => 'الأدنى–الأعلى',
    'The file has more students than are enrolled. Is it the right course?' => 'عدد الطلاب في الملف أكبر من عدد المسجّلين. هل هذا هو المقرر الصحيح؟',
    "No column in the file matches an assessment in the specification, so there is nothing to import. The specification's assessments are:" => 'لا يطابق أي عمود في الملف تقييماً من توصيف المقرر، فلا شيء يُستورد. تقييمات التوصيف هي:',
    '. Rename the columns and upload again.' => '. غيّر أسماء الأعمدة وارفع الملف مرة أخرى.',
    'Import these marks' => 'استيراد هذه الدرجات',
    'Uploaded from a file' => 'رُفعت من ملف',

    // ------------------------------------------------------------------ Course workspace: evidence tab
    'Files (choose several at once)' => 'الملفات (يمكن اختيار عدة ملفات دفعة واحدة)',
    'Not sure about this one: please check.' => 'غير متأكد من هذا الملف: يرجى التحقق منه.',
    'SAQF suggested these from the file names. Please check each one.' => 'اقترح SAQF هذه التصنيفات من أسماء الملفات. يرجى التحقق من كل واحد منها.',
    'Section (for all these files)' => 'الشعبة (لكل هذه الملفات)',
    'e.g. Add a formative checkpoint on test planning in week 9 with rubric feedback…' => 'مثال: إضافة نقطة تقويم تكويني حول تخطيط الاختبارات في الأسبوع 9 مع تغذية راجعة بأداة التصحيح…',
    'Evidence accepted. If a file changes later, the review is due again.' => 'قُبلت الشواهد. وإذا تغيّر أي ملف لاحقاً تصبح المراجعة مستحقة من جديد.',
    'Evidence returned to the instructor with your note.' => 'أُعيدت الشواهد إلى عضو هيئة التدريس مع ملاحظتك.',
    'e.g. Add marked samples for the final exam' => 'مثال: أضف نماذج مصححة للاختبار النهائي',

    // ------------------------------------------------------------------ Course workspace: course report tab ("Facts for your reading")
    'Facts for your reading' => 'حقائق للاطلاع عليها',
    'Worked out by SAQF from your records' => 'حسبها SAQF من سجلاتك',
    'These are the numbers, not their meaning. Why the results look like this, and what to do about it, is for you to say below: SAQF never writes it for you.' => 'هذه هي الأرقام وليست معناها. أما سبب ظهور النتائج بهذا الشكل وما ينبغي فعله حيالها فهو ما تكتبه أنت أدناه: لا يكتبه SAQF نيابة عنك أبداً.',
    'There is no approved specification yet, so there is nothing to measure.' => 'لا يوجد توصيف معتمد بعد، لذا لا شيء يمكن قياسه.',
    'The assessment plan is empty.' => 'خطة التقييم فارغة.',
    'no result yet.' => 'لا توجد نتيجة بعد.',
    'no result yet (no assessment measures it).' => 'لا توجد نتيجة بعد (لا يقيسه أي تقييم).',
    'The course file has no evidence yet.' => 'لا توجد شواهد في ملف المقرر بعد.',

    // ------------------------------------------------------------------ Course file closeout: items, owners, details and next steps (Closeout.php)
    'The course instructor' => 'عضو هيئة التدريس في المقرر',
    'No approved specification yet.' => 'لا يوجد توصيف معتمد بعد.',
    'Write the outcomes and assessment plan, then submit them.' => 'اكتب المخرجات وخطة التقييم ثم قدّمها.',
    'Decide on the submitted specification.' => 'البتّ في التوصيف المقدَّم.',
    'Every automatic check passes, or Quality decided an exception.' => 'تجتاز كل الفحوص التلقائية، أو قررت الجودة استثناءً.',
    'Fix them on the Overview tab, or ask Quality for an exception.' => 'عالجها في تبويب «نظرة عامة»، أو اطلب من الجودة استثناءً.',
    'The person named in Problems to sort out decides.' => 'يقرر الشخص المذكور في «مشكلات تحتاج إلى معالجة».',
    'There is no approved assessment plan to attach grades to yet.' => 'لا توجد خطة تقييم معتمدة تُربط بها الدرجات بعد.',
    'Complete the specification first.' => 'أكمل التوصيف أولاً.',
    'Publish the grades in the LMS (SAQF imports them) or upload a gradebook file.' => 'انشر الدرجات في نظام إدارة التعلم (يستوردها SAQF) أو ارفع ملف دفتر درجات.',
    'There is no approved assessment plan yet.' => 'لا توجد خطة تقييم معتمدة بعد.',
    'Upload the missing files in the Evidence tab (remove student names from samples).' => 'ارفع الملفات الناقصة في تبويب «الشواهد» (واحذف أسماء الطلاب من النماذج).',
    'Replace or add the files the reviewer asked for.' => 'استبدل الملفات التي طلبها المراجع أو أضف غيرها.',
    'A reviewer opens the files and accepts the set or returns it with a note.' => 'يفتح المراجع الملفات ثم يقبل المجموعة أو يعيدها مع ملاحظة.',
    'Write what will change, who does it and by when (your academic decision).' => 'اكتب ما الذي سيتغيّر ومن ينفّذه ومتى (وهذا قرارك الأكاديمي).',
    'Open the Improvement tab and write the plan.' => 'افتح تبويب «التحسين» واكتب الخطة.',
    'No outcome has missed its goal so far.' => 'لم يقصّر أي مخرج عن هدفه حتى الآن.',
    "Not written yet. SAQF does not write this: it is the instructor's academic judgement." => 'لم يُكتب بعد. لا يكتب SAQF هذا: فهو من الحكم الأكاديمي لعضو هيئة التدريس.',
    'Write it on the Course report tab.' => 'اكتبه في تبويب «تقرير المقرر».',
    'The report stays live until the term closes; SAQF then seals it so it can no longer change. Sealing is not an approval.' => 'يبقى التقرير مرتبطاً بالبيانات الحالية إلى أن يُغلق الفصل؛ ثم يختمه SAQF فلا يمكن تغييره بعد ذلك. والختم ليس اعتماداً.',
    // Wording written when the checklist was added; spelled SAQF in Latin letters like the rest of the interface.
    'SAQF (automatic)' => 'SAQF (تلقائياً)',
    'SAQF checks' => 'فحوص SAQF',
    "Checklist: SAQF's default settings, not yet confirmed by Quality. They are not a university requirement until Quality sets them." => 'قائمة التحقق: الإعدادات الافتراضية في SAQF، ولم تؤكدها الجودة بعد. ولا تُعد متطلباً جامعياً حتى تعتمدها الجودة.',
    "Each line says who owns it and the next step. Everything here is read from SAQF's records; nothing is marked done until the records show it." => 'يذكر كل سطر المسؤول عنه والخطوة التالية. كل ما هنا مقروء من سجلات SAQF، ولا يُعلَّم أي بند بأنه منجز حتى تُظهر السجلات ذلك.',
    "SAQF fills in the facts and checks. The instructor writes the reading of the results, the suggestions and each improvement plan; the Head of Department or Quality accepts the evidence and decides on approvals and exceptions. SAQF never does those for them." => 'يملأ SAQF الحقائق ويجري الفحوص. ويكتب عضو هيئة التدريس قراءة النتائج والمقترحات وكل خطة تحسين، ويقبل رئيس القسم أو الجودة الشواهد ويقرران في الاعتمادات والاستثناءات. ولا يقوم SAQF بذلك نيابة عنهم أبداً.',

    // ------------------------------------------------------------------ Course file closeout: reviewing the evidence (errors shown to the reviewer)
    'Only the Head of Department or Quality can review the course file evidence.' => 'لا يراجع شواهد ملف المقرر إلا رئيس القسم أو الجودة.',
    'Choose whether to accept or return the evidence.' => 'اختر هل تقبل الشواهد أم تعيدها.',
    'Say what is missing or needs changing (at least 10 characters), so the instructor knows what to do.' => 'اذكر ما هو ناقص أو يحتاج إلى تعديل (10 أحرف على الأقل) ليعرف عضو هيئة التدريس ما عليه فعله.',
    'There is no evidence in the course file to review yet.' => 'لا توجد شواهد في ملف المقرر لمراجعتها بعد.',

    // ------------------------------------------------------------------ Evidence (kinds written in lower case inside sentences)
    'assessment paper / brief' => 'ورقة التقييم / وصفه',
    'marking rubric' => 'أداة التصحيح (Rubric)',
    'sample of marked student work' => 'نموذج من أعمال طلاب مصححة',
    'other evidence' => 'شاهد آخر',
    'The file is larger than the server allows.' => 'الملف أكبر مما يسمح به الخادم.',
    'Virus scanner unreachable' => 'فاحص الفيروسات لا يستجيب',
    'Malware blocked at upload' => 'حُجب برنامج خبيث عند الرفع',

    // ------------------------------------------------------------------ Gradebook files and imported results
    'The gradebook file could not be read.' => 'تعذّرت قراءة ملف دفتر الدرجات.',
    'The first column must be "student" (the student number or LMS key; SAQF replaces it with a code before storing), followed by one column per assessment name.' => 'يجب أن يكون العمود الأول «student» (الرقم الجامعي أو مفتاح نظام إدارة التعلم؛ يستبدله SAQF برمز قبل الحفظ)، يليه عمود لكل اسم تقييم.',
    'Too many rows.' => 'عدد الصفوف يتجاوز الحد المسموح.',
    'A pseudonym key space is required: student identifiers are never stored as given.' => 'يلزم تحديد نطاق لمفاتيح الأسماء المستعارة: لا تُحفظ معرّفات الطلاب كما وردت أبداً.',
    'These results carry student numbers instead of pseudonymous keys, so nothing was imported. Student identifiers must be pseudonymised before they are stored.' => 'تحمل هذه النتائج أرقاماً جامعية بدلاً من المفاتيح المستعارة، لذا لم يُستورد شيء. يجب جعل معرّفات الطلاب مستعارة قبل حفظها.',

    // ------------------------------------------------------------------ Background scheduler alerts (IT)
    'Student information system sync is failing' => 'مزامنة نظام معلومات الطلاب تتعثر',
    'Institutional catalogue sync is failing' => 'مزامنة الدليل المؤسسي تتعثر',
    'Automatic quality checks are failing' => 'فحوصات الجودة التلقائية تتعثر',
    'E-mail delivery is failing' => 'إرسال البريد الإلكتروني يتعثر',
    'Audit log integrity check failed' => 'فشل فحص سلامة سجل التدقيق',
    'Security self-test failed' => 'فشل الاختبار الذاتي للأمان',
    'Access review overdue' => 'مراجعة الصلاحيات متأخرة',
    'Backup missing, failed or incomplete' => 'النسخة الاحتياطية مفقودة أو فاشلة أو غير مكتملة',
    'Background scheduler has stopped' => 'توقف المُجدوِل الذي يعمل في الخلفية',
];
