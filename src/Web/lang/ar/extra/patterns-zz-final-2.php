<?php
declare(strict_types=1);

// Patterns for security wording with numbers and names (SAQF 2.6).

return [
    '/^For security, sessions last at most (\\d+) hours\\. Please sign in again\\.$/u' => 'لأمانك، تدوم الجلسات {1} ساعات على الأكثر. يُرجى تسجيل الدخول من جديد.',
    '/^Incident registered by (.+)$/u' => 'سجّل {1} الحادثة',
    '/^Incident registered by (.+), personal data involved$/u' => 'سجّل {1} الحادثة، وتتضمن بيانات شخصية',
    '/^Avoid keyboard or alphabet sequences such as "(.+)"\\.?$/u' => 'تجنّب تسلسلات لوحة المفاتيح أو الأبجدية مثل "{1}".',
    '/^That password is too easy to guess: it is built on a common word \\("(.+)"\\)\\. Use a passphrase of several unrelated words\\.$/u' => 'كلمة المرور هذه سهلة التخمين: فهي مبنية على كلمة شائعة ("{1}"). استخدم عبارة مرور من عدة كلمات لا علاقة بينها.',
    '/^A passkey registration was refused: (.+)$/u' => 'رُفض تسجيل مفتاح المرور: {t1}',
    '/^No signing key matches the ID token \\(kid (.+)\\)\\.?$/u' => 'لا يوجد مفتاح توقيع يطابق رمز الهوية (kid {1}).',
    '/^The university identity provider refused the sign-in \\((.+)\\)\\.?$/u' => 'رفض مزوّد الهوية في الجامعة الدخول ({1}).',
    '/^The university identity provider is not reachable right now \\((.+)\\)\\.?$/u' => 'تعذّر الوصول إلى مزوّد الهوية في الجامعة الآن ({1}).',
    '/^Could not load the identity provider signing keys \\((.+)\\)\\.?$/u' => 'تعذّر تحميل مفاتيح التوقيع من مزوّد الهوية ({1}).',
    '/^Audit chain witnessed at entry (\\d+)(.*)$/u' => 'أُشهد على سلسلة التدقيق عند القيد {1}{2}',
    '/^That is not a SAQF witness line \\(expected: (.+)\\)\\.?$/u' => 'هذا ليس سطر إشهاد من SAQF (المتوقع: {1}).',
    '/^LAST BACKUP FAILED at (.+)$/u' => 'فشلت آخر نسخة احتياطية في {1}',
    '/^(\\d+) active account\\(s\\) unused for more than (\\d+) days?(.*)$/u' => '{1} حساب(ات) نشطة لم تُستخدم لأكثر من {2} يوماً{3}',
    '/^No active account unused for more than (\\d+) days?(.*)$/u' => 'لا يوجد حساب نشط لم يُستخدم لأكثر من {1} يوماً{2}',
];
