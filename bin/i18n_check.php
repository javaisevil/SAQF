<?php
declare(strict_types=1);

/**
 * Arabic coverage by SOURCE, without a server or a database: lists the English wording in PHP files (page text,
 * flash messages, exception messages shown to people, labels) that the Arabic dictionary does not translate yet.
 * bin/i18n_coverage.php finds what a crawl SEES; this finds what the code CAN say, including messages that only
 * appear after an action (a refused upload, a validation error, a notification).
 *
 *   php bin/i18n_check.php --extract <file.php|dir> …      missing wording found in the source (file:line, text)
 *   php bin/i18n_check.php --phrases <file.txt>            one English phrase per line: which lack Arabic
 *   php bin/i18n_check.php --show "<English phrase>"       the Arabic SAQF would show (or MISSING)
 * Exit code 0 = nothing missing, 1 = something is missing.
 * Sentences built from variables ("$n results imported") are seen only in pieces: add a pattern
 * (src/Web/lang/ar/patterns-*.php) and test it with --show using a realistic example.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Saqf\Web\I18n;

$mode = $argv[1] ?? '';
$args = array_slice($argv, 2);
if (!in_array($mode, ['--extract', '--phrases', '--show'], true) || !$args) {
    fwrite(STDERR, "usage: php bin/i18n_check.php --extract <file|dir>… | --phrases <file.txt> | --show \"<English phrase>\"\n");
    exit(2);
}

/** Text nodes and user-facing attribute values of an HTML fragment. @return list<string> */
function html_texts(string $html): array
{
    $out = [];
    foreach (preg_split('/(<!--.*?-->|<[^>]*>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $i => $part) {
        if ($i % 2 === 0) {
            $t = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            // PHP echo tags inside the fragment split sentences: only whole, plain-text nodes count.
            if ($t !== '' && !str_contains($t, '<?') && !str_contains($t, '?>')) {
                $out[] = $t;
            }
        } elseif (preg_match_all('/\b(?:placeholder|title|aria-label|alt|data-confirm|data-prompt)="([^"<>]*)"/', $part, $m)) {
            foreach ($m[1] as $v) {
                $out[] = trim(html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }
    }
    return $out;
}

/** Does this look like wording a person reads (and not SQL, a path, an identifier, a CSS class or code)? */
function readable(string $s): bool
{
    $s = trim($s);
    if (mb_strlen($s) < 3 || !preg_match('/[A-Za-z]{2}/', $s) || preg_match('/[\x{0600}-\x{06FF}]/u', $s)) {
        return false;
    }
    if (preg_match('/^(SELECT|INSERT|UPDATE|DELETE|FROM|WHERE|CREATE|ALTER|DROP|SET|JOIN|LEFT|ORDER|GROUP|SAQF-|Content-|Cache-|X-|Location:|Bearer|Basic)\b/', $s)) {
        return false;
    }
    if (!str_contains($s, ' ') && !preg_match('/^[A-Z][a-z]{2,}[.!?:]?$/', $s)) {
        return false; // identifiers, keys, file names, class names, single technical words
    }
    if (preg_match('/^[a-z0-9_.\/#:\-\[\]=?&%{}<>|*]+$/i', $s) && !str_contains($s, ' ')) {
        return false;
    }
    if (preg_match('/^(https?:|\/|\.\/|[a-z_]+\.php|[a-z0-9_.-]+\/)/i', $s) || preg_match('/\b(function|return|namespace|static|\$[a-z_]+\s*=)\b/', $s)) {
        return false;
    }
    if (!preg_match('/^[A-Z“"\'(\x{2713}\x{2717}\x{2022}\x{2192}\x{00B7}+\d]/u', $s) && !preg_match('/\s[a-z]{2,}\s/', $s)) {
        return false;
    }
    return true;
}

function files(array $targets): array
{
    $out = [];
    foreach ($targets as $t) {
        if (is_dir($t)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($t, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && str_ends_with($f->getFilename(), '.php') && !str_contains($f->getPathname(), '/lang/')) {
                    $out[] = $f->getPathname();
                }
            }
        } elseif (is_file($t)) {
            $out[] = $t;
        }
    }
    sort($out);
    return $out;
}

if ($mode === '--show') {
    $ar = I18n::phrase($args[0]);
    echo $ar === null ? "MISSING\n" : $ar . "\n";
    exit($ar === null ? 1 : 0);
}

if ($mode === '--phrases') {
    $missing = 0;
    foreach (file($args[0], FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '' && I18n::phrase($line) === null) {
            echo "MISSING\t$line\n";
            $missing++;
        }
    }
    fwrite(STDERR, $missing ? "$missing phrase(s) without Arabic.\n" : "Every phrase has Arabic.\n");
    exit($missing ? 1 : 0);
}

// --extract
$seen = [];
$missing = 0;
foreach (files($args) as $file) {
    $rel = ltrim(str_replace(dirname(__DIR__), '', $file), '/');
    foreach (token_get_all((string) file_get_contents($file)) as $tok) {
        if (!is_array($tok)) {
            continue;
        }
        [$id, $text, $line] = $tok;
        $candidates = [];
        if ($id === T_INLINE_HTML) {
            $candidates = html_texts($text);
        } elseif ($id === T_CONSTANT_ENCAPSED_STRING) {
            $v = stripcslashes(substr($text, 1, -1));
            $candidates = str_contains($v, '<') ? html_texts($v) : [$v];
        }
        foreach ($candidates as $c) {
            $c = trim((string) preg_replace('/\s+/u', ' ', $c));
            if (!readable($c) || isset($seen[$c]) || I18n::phrase($c) !== null) {
                continue;
            }
            $seen[$c] = true;
            $missing++;
            echo "$rel:$line\t$c\n";
        }
    }
}
fwrite(STDERR, $missing ? "$missing wording(s) in the source without Arabic.\n" : "Every wording found in the source has Arabic.\n");
exit($missing ? 1 : 0);
