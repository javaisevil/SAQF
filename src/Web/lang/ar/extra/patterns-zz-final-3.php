<?php
declare(strict_types=1);

// Patterns for connection, evidence, specification and exception wording (SAQF 2.6).

return [
    '/^These assessments are not in the course specification: (.+)\\. Results must use the specification\'s assessment names\\.$/u' => 'هذه التقييمات ليست في توصيف المقرر: {1}. يجب أن تستخدم النتائج أسماء التقييمات الواردة في التوصيف.',
    '/^Evidence files must be smaller than (\\d+) MB\\.$/u' => 'يجب أن تكون ملفات الشواهد أصغر من {1} ميغابايت.',
    '/^The file content does not match its type \\(\\.(\\w+)\\)\\.$/u' => 'محتوى الملف لا يطابق نوعه (.{1}).',
    '/^Office files with macros are not accepted\\. Save it as a normal \\.(\\w+) or PDF\\.$/u' => 'لا تُقبل ملفات Office التي تحتوي على وحدات ماكرو. احفظه ملفاً عادياً بصيغة .{1} أو PDF.',
    '/^The virus scanner could not check the file \\((.+)\\)\\.$/u' => 'تعذّر على ماسح الفيروسات فحص الملف ({1}).',
    '/^The evidence store is not writable \\((.+)\\)\\.$/u' => 'مخزن الشواهد غير قابل للكتابة ({1}).',
    '/^IT: set (.+)\\.$/u' => 'تقنية المعلومات: اضبطوا {1}.',
    '/^Rate limit reached: (.+)$/u' => 'بلغ الحد الأقصى للطلبات: {1}',
    '/^Refused to contact (\\S+): the address is not acceptable \\((.+)\\)\\.$/u' => 'رُفض الاتصال بـ {1}: العنوان غير مقبول ({2}).',
    '/^Could not reach (\\S+): (.+)$/u' => 'تعذّر الوصول إلى {1}: {2}',
    '/^(\\S+) answered HTTP (\\d+)\\.?$/u' => 'ردّ {1} بالرمز HTTP {2}.',
    '/^(\\S+) refused the credentials \\(HTTP (\\d+)\\)\\. Check the token or client settings for (SIS|LMS)\\.$/u' => 'رفض {1} بيانات الاعتماد (HTTP {2}). تحققوا من إعدادات الرمز أو العميل (النظام: {t3}).',
    '/^(\\S+) did not return JSON\\.$/u' => 'لم يُرجع {1} بيانات JSON.',
    '/^(.+) is not set\\.$/u' => '{1} غير مضبوط.',
    '/^Fix the (\\d+) items marked Must fix before sending\\.$/u' => 'أصلحوا البنود الـ {1} الموسومة «يجب إصلاحه» قبل الإرسال.',
    '/^(\\d+) changes need your approval$/u' => '{1} تغييرات تحتاج إلى موافقتك',
    '/^Sent\\. Your Head of Department sees only the (\\d+) changes and what they affect, not the whole specification\\.$/u' => 'أُرسل. يرى رئيس القسم التغييرات الـ {1} وما تؤثر فيه فقط، لا التوصيف كاملاً.',
    '/^Superseded by v(\\d+)$/u' => 'حلّ محله الإصدار {1}',
    '/^Baseline imported: (.+)$/u' => 'استُورد التوصيف الأساسي: {1}',
    '/^Results arrived for (.+); the course file should hold the assessment and a sample of marked work for each\\.$/u' => 'وصلت نتائج {1}؛ ينبغي أن يحتوي ملف المقرر على ورقة التقييم وعينة من الأعمال المصححة لكل منها.',
    '/^Exception asked for: (.+)$/u' => 'طُلب استثناء: {1}',
    '/^Due soon: (.+)$/u' => 'يحل موعده قريباً: {1}',
    '/^Policy exception requested: (.+)$/u' => 'طُلب استثناء من السياسة: {1}',
    '/^Override approved by QA: (.+)$/u' => 'وافقت الجودة على الاستثناء: {1}',
    '/^Exception not approved: (.+)$/u' => 'لم يُوافَق على الاستثناء: {1}',
    '/^Overridden by QA: (.+)$/u' => 'تجاوزته الجودة: {1}',
    '/^Follow-up measurement: (.+)$/u' => 'قياس المتابعة: {1}',
];
