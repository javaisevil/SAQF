<?php
declare(strict_types=1);

// Arabic interface dictionary (see Saqf\Web\I18n). 'strings': exact English phrase => Arabic.
// 'patterns': anchored regex => Arabic, where {1} inserts a captured group as is and {t1} inserts
// it translated. Academic terms follow NCAAA Arabic usage (مخرجات التعلم، توصيف المقرر، الشواهد).
// The phrases are kept in several files under lang/ar/ only to keep each file a manageable size.
// Find untranslated text with: php bin/i18n_coverage.php <base-url>

return [
    'strings' => array_replace(
        require __DIR__ . '/ar/strings-1.php',
        require __DIR__ . '/ar/strings-2.php',
        require __DIR__ . '/ar/strings-3.php',
        require __DIR__ . '/ar/strings-4.php',
        require __DIR__ . '/ar/strings-5.php',
        require __DIR__ . '/ar/strings-6.php',
    ),
    // patterns-2 (the plain-language wording) is tried first; on the same pattern it wins.
    'patterns' => (require __DIR__ . '/ar/patterns-2.php') + (require __DIR__ . '/ar/patterns.php'),
];
