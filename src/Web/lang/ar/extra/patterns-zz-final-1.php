<?php
declare(strict_types=1);

// Patterns for sentences with names, numbers and dates (SAQF 2.6).

return [
    '/^Weight \\(%\\): (.+)$/u' => 'الوزن (%): {t1}',
    '/^Sign-in code e-mailed to (.+)$/u' => 'أُرسل رمز الدخول بالبريد إلى {1}',
    '/^(.+) signed in \\(password and authenticator code\\)$/u' => 'سجّل {1} الدخول (كلمة المرور ورمز تطبيق المصادقة)',
    '/^(.+) signed in \\(password and e-mailed code\\)$/u' => 'سجّل {1} الدخول (كلمة المرور ورمز مُرسل بالبريد)',
    '/^(.+) signed in \\(password and passkey\\)$/u' => 'سجّل {1} الدخول (كلمة المرور ومفتاح المرور)',
    '/^Access denied to page (\\S+) \\(role (.+)\\)$/u' => 'رُفض الوصول إلى الصفحة {1} (الدور: {t2})',
    '/^Account (\\S+) linked to university sign-in$/u' => 'رُبط الحساب {1} بالدخول بحساب الجامعة',
    '/^Policy "(.+)" changed from (.+) to (.+)$/u' => 'تغيّرت السياسة «{t1}» من {2} إلى {3}',
    '/^University sign-in refused: (.+)$/u' => 'رُفض الدخول بحساب الجامعة: {t1}',
    '/^The university sign-in did not complete \\((.+)\\)\\.$/u' => 'لم يكتمل الدخول بحساب الجامعة ({t1}).',
    '/^Your university account \\((\\S+)\\) is not set up in SAQF yet\\. Ask the SAQF administrator to add you\\.$/u' => 'حسابك الجامعي ({1}) غير مُعدّ في SAQF بعد. اطلب من مسؤول SAQF إضافتك.',
    '/^E-mail me when something needs my action \\((\\S+)\\)$/u' => 'راسلوني بالبريد عندما يحتاج أمر إلى إجراء مني ({1})',
    '/^E-mail me when something needs my action$/u' => 'راسلوني بالبريد عندما يحتاج أمر إلى إجراء مني',
    '/^(\\d+) registered$/u' => 'المسجّل: {1}',
    '/^Reason for retiring (\\S+)\\?$/u' => 'سبب إلغاء {1}؟',
    '/^Coordinator: (.+)$/u' => 'المنسّق: {1}',
    '/^(.+): one academic task needs your input$/u' => '{1}: مهمة أكاديمية واحدة تحتاج إلى مدخلاتك',
    '/^(.+): (\\d+) academic tasks need your input$/u' => '{1}: {2} مهام أكاديمية تحتاج إلى مدخلاتك',
    '/^: Incident registered by (.+)$/u' => ': سجّل الحادثةَ {1}',
    '/^([A-Z]{2,5}) — (.+) \\((Bachelor|Master)\\)$/u' => '{1} — {t2} ({t3})',
    '/^No program outcomes for (.+): SAQF flags these programs until the Head of Department enters them\\.$/u' => 'لا توجد مخرجات برامج لـ {1}: يُنبّه SAQF على هذه البرامج إلى أن يُدخلها رئيس القسم.',
    '/^Specification import: (\\d+) course\\(s\\) imported as approved baselines, (\\d+) skipped$/u' => 'استيراد التوصيفات: استُورد {1} مقرر(ات) كتوصيفات معتمدة أساسية، وتُخطّي {2}',
    '/^Specification import: (\\d+) course\\(s\\) imported as drafts for the instructors, (\\d+) skipped$/u' => 'استيراد التوصيفات: استُورد {1} مقرر(ات) كمسودات لأعضاء هيئة التدريس، وتُخطّي {2}',
    '/^A CSV file with one row per student: the student number \\(SAQF replaces it with a code before storing\\), optionally the section, then one column per assessment with marks from 0 to 100\\. Rows without a section count as section (\\S+)\\.$/u' => 'ملف CSV بصف لكل طالب: رقم الطالب (يستبدله SAQF برمز قبل التخزين)، ثم الشعبة اختيارياً، ثم عمود لكل تقييم بدرجات من 0 إلى 100. وتُعدّ الصفوف التي بلا شعبة من الشعبة {1}.',
    '/^Activity after the checkpoint(.*)$/ui' => 'نشاط بعد نقطة التحقق{1}',
    '/^Added (.+) · not used yet$/u' => 'أُضيف {t1} · لم يُستخدم بعد',
    '/^Changed (.+) by$/u' => 'غُيّر {t1} بواسطة',
    '/^Password sign-ins also need a code from your authenticator app\\. Turned on (.+) · (\\d+) recovery code\\(s\\) left\\.$/u' => 'يتطلب الدخول بكلمة المرور رمزاً من تطبيق المصادقة أيضاً. فُعّل في {t1} · المتبقي من رموز الاسترداد: {2}.',
];
