<?php
declare(strict_types=1);

// Arabic interface dictionary (see Saqf\Web\I18n). 'strings': exact English phrase => Arabic.
// 'patterns': anchored regex => Arabic, where {1} inserts a captured group as is and {t1} inserts
// it translated. Academic terms follow NCAAA Arabic usage (مخرجات التعلم، توصيف المقرر، الشواهد).
// The phrases are kept in several files under lang/ar/ only to keep each file a manageable size.
// Find untranslated text with: php bin/i18n_coverage.php <base-url>

// SAQF 2.6: wording added area by area lives in lang/ar/extra/ (strings-<area>.php, patterns-<area>.php, read in name order).
// New patterns are tried after the older ones, so a pattern added here must not be shadowed by a broader older one;
// check with: php bin/i18n_check.php --show "<a realistic English sentence>".
$extraStrings = [];
$extraPatterns = [];
foreach (glob(__DIR__ . '/ar/extra/strings-*.php') ?: [] as $f) {
    $extraStrings = array_replace($extraStrings, require $f);
}
foreach (glob(__DIR__ . '/ar/extra/patterns-*.php') ?: [] as $f) {
    $extraPatterns += require $f;
}

return [
    'strings' => array_replace(
        require __DIR__ . '/ar/strings-1.php',
        require __DIR__ . '/ar/strings-2.php',
        require __DIR__ . '/ar/strings-3.php',
        require __DIR__ . '/ar/strings-4.php',
        require __DIR__ . '/ar/strings-5.php',
        require __DIR__ . '/ar/strings-6.php',
        require __DIR__ . '/ar/strings-7.php',
        require __DIR__ . '/ar/strings-8.php',
        require __DIR__ . '/ar/strings-9.php',
        $extraStrings,
    ),
    // patterns-2 (the plain-language wording) is tried first; on the same pattern it wins.
    'patterns' => (require __DIR__ . '/ar/patterns-3.php') + (require __DIR__ . '/ar/patterns-2.php') + (require __DIR__ . '/ar/patterns.php') + $extraPatterns,
];
