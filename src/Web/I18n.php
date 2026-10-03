<?php
declare(strict_types=1);

namespace Saqf\Web;

use Saqf\Core\Config;
use Saqf\Core\Db;
use Saqf\Core\Request;
use Throwable;

/**
 * Interface language: English (default) or Arabic, right to left.
 *
 * Pages are written once, in English. When Arabic is selected, the finished page passes through
 * translate(): every visible text node and the user-facing attributes (placeholder, title,
 * aria-label, alt, confirmation prompts) are looked up in src/Web/lang/ar.php — exact phrases
 * first, then patterns for text with numbers and names ("3 open", "Fall 2026 · week 6") — and the
 * document is switched to dir="rtl". Data (course titles, outcome statements, people's names) is shown
 * in Arabic when its Arabic wording is known (Saqf\Core\Translations: from the Registrar catalogue,
 * from faculty, or entered by Quality); otherwise as entered. Scripts, styles, code, text areas and
 * elements marked translate="no" are never touched.
 *
 * The choice is kept in a cookie and on the person's account (users.locale), so it follows them
 * to another device. bin/i18n_coverage.php lists any English left on each screen.
 */
final class I18n
{
    public const COOKIE = 'saqf_lang';

    private static ?array $strings = null;
    private static ?array $patterns = null;
    /** @var array<string,string>|null Arabic wording of data (course names, outcomes, people) */
    private static ?array $data = null;
    /** @var array<string,int> English phrases with no translation on this page (coverage report) */
    private static array $missing = [];

    public static function lang(): string
    {
        return ($_COOKIE[self::COOKIE] ?? 'en') === 'ar' ? 'ar' : 'en';
    }

    public static function rtl(): bool
    {
        return self::lang() === 'ar';
    }

    /** Applies ?lang=ar|en (then redirects to the clean address) and starts translating Arabic pages. */
    public static function boot(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        $want = $_GET['lang'] ?? null;
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        // export.php uses ?lang= for the language of the downloaded document only.
        if (is_string($want) && in_array($want, ['ar', 'en'], true) && !in_array($script, ['export.php', 'api.php'], true)) {
            self::remember($want);
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
                $q = $_GET;
                unset($q['lang']);
                header('Location: ' . $script . ($q ? '?' . http_build_query($q) : ''));
                exit;
            }
        }
        if (self::rtl()) {
            ob_start([self::class, 'filter']);
        }
    }

    /** Stores the language in the cookie and, for a signed-in person, on their account. */
    public static function remember(string $lang): void
    {
        $lang = $lang === 'ar' ? 'ar' : 'en';
        if (!headers_sent()) {
            setcookie(self::COOKIE, $lang, ['expires' => time() + 31536000, 'path' => '/', 'secure' => Request::isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
        }
        $_COOKIE[self::COOKIE] = $lang;
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['uid'])) {
            try {
                Db::update('users', ['locale' => $lang], 'id = ?', [(int) $_SESSION['uid']]);
            } catch (Throwable $e) {
                // the locale column arrives with the 2026-10 migration; the cookie still works
            }
        }
    }

    /** After sign-in: a language saved on the account wins over the browser's cookie. */
    public static function applyAccount(array $user): void
    {
        $saved = $user['locale'] ?? null;
        if (in_array($saved, ['ar', 'en'], true) && $saved !== self::lang()) {
            self::remember($saved);
        }
    }

    /** The current address with ?lang= set (for the language switch). */
    public static function switchUrl(string $lang): string
    {
        $q = $_GET;
        $q['lang'] = $lang;
        return basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php')) . '?' . http_build_query($q);
    }

    /** The language switch link shown in the top bar and on the sign-in pages. */
    public static function switchLink(string $class = 'langsw'): string
    {
        return self::rtl()
            ? '<a class="' . $class . '" href="' . View::h(self::switchUrl('en')) . '" lang="en" dir="ltr" translate="no">English</a>'
            : '<a class="' . $class . '" href="' . View::h(self::switchUrl('ar')) . '" lang="ar" dir="rtl">العربية</a>';
    }

    /** Output-buffer callback: translates HTML responses only (downloads and JSON pass through). */
    public static function filter(string $buffer, int $phase = 0): string
    {
        foreach (headers_list() as $h) {
            if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) {
                return $buffer;
            }
        }
        if ($buffer === '' || !str_contains($buffer, '<')) {
            return $buffer;
        }
        $out = self::translate($buffer);
        $report = (string) Config::get('SAQF_I18N_REPORT', '');
        if ($report !== '' && self::$missing) {
            $page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
            $lines = '';
            foreach (array_keys(self::$missing) as $m) {
                $lines .= $page . "\t" . str_replace(["\n", "\t"], ' ', $m) . "\n";
            }
            @file_put_contents($report, $lines, FILE_APPEND | LOCK_EX);
            self::$missing = [];
        }
        return $out;
    }

    /** Translates a whole HTML document (or fragment) into Arabic. */
    public static function translate(string $html): string
    {
        $parts = preg_split('/(<!--.*?-->|<[^>]*>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $html;
        }
        $out = '';
        $skip = null;
        foreach ($parts as $i => $p) {
            if ($i % 2 === 1) {
                if ($skip !== null) {
                    if (preg_match('#^</' . $skip . '\b#i', $p)) {
                        $skip = null;
                    }
                    $out .= $p;
                    continue;
                }
                // Never translated: code, text areas, and any element marked translate="no" (English shown on purpose).
                if ((preg_match('#^<(script|style|textarea|pre|code)\b#i', $p, $m) || preg_match('#^<([a-z][a-z0-9]*)\b[^>]*\btranslate="no"#i', $p, $m)) && !str_ends_with($p, '/>')) {
                    $skip = strtolower($m[1]);
                    $out .= $p;
                    continue;
                }
                $out .= self::tag($p);
            } else {
                $out .= $skip !== null ? $p : self::text($p);
            }
        }
        return $out;
    }

    /** Translates one phrase (plain text); null when no translation is known. */
    public static function phrase(string $s): ?string
    {
        self::load();
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
        if ($s === '') {
            return null;
        }
        if (isset(self::$strings[$s])) {
            return self::$strings[$s];
        }
        if (isset(self::$data[$s])) {
            return self::$data[$s];
        }
        foreach (self::$patterns as $re => $to) {
            if (preg_match($re, $s, $m)) {
                return preg_replace_callback('/\{(t?)(\d)\}/', static function (array $x) use ($m): string {
                    $v = $m[(int) $x[2]] ?? '';
                    // {t1}: translate the captured text fully; {1}: swap in known wording (names, titles) only
                    return $x[1] === 't' ? (self::phrase($v) ?? $v) : (self::exact($v) ?? self::shortened($v) ?? $v);
                }, $to);
            }
        }
        // "A · B · C": translate the pieces that are known.
        foreach ([' · ', ' — ', ' | '] as $sep) {
            if (str_contains($s, $sep)) {
                $pieces = explode($sep, $s);
                $done = 0;
                foreach ($pieces as &$piece) {
                    $t = self::phrase($piece);
                    if ($t !== null) {
                        $piece = $t;
                        $done++;
                    }
                }
                unset($piece);
                if ($done > 0) {
                    return implode($sep, $pieces);
                }
            }
        }
        // A list ("Quiz, Midterm exam"): only when every item is known or is a code.
        if (str_contains($s, ', ')) {
            $items = [];
            $known = 0;
            foreach (explode(', ', $s) as $item) {
                $t = self::phrase($item);
                if ($t !== null) {
                    $items[] = $t;
                    $known++;
                } elseif (!preg_match('/[a-z]{3,}/', $item)) {
                    $items[] = $item;
                } else {
                    $items = null;
                    break;
                }
            }
            if ($items !== null && $known > 0) {
                return implode('، ', $items);
            }
        }
        // A course or program code followed by its known title ("SWE 401 Software Quality Assurance").
        if (preg_match('/^([A-Z]{2,5}(?: \d{3}[A-Z]?)?) (\S.*)$/u', $s, $m) && ($t = self::exact($m[2])) !== null) {
            return $m[1] . ' ' . $t;
        }
        // A leading mark or separator ("✓ Verified", "+ Add", "· required course", "— detail") or a trailing one ("Skills ·").
        if (preg_match('/^([✓✗•↻+●]\s*|[·—]\s+)(.+)$/u', $s, $m) && ($t = self::phrase($m[2])) !== null) {
            return $m[1] . $t;
        }
        if (preg_match('/^(.+?)(\s+[·—])$/u', $s, $m) && ($t = self::phrase($m[1])) !== null) {
            return $t . $m[2];
        }
        // Quoted text (“…” or "…").
        if (preg_match('/^([“"])(.+)([”"])$/u', $s, $m) && ($t = self::phrase($m[2]) ?? self::shortened($m[2])) !== null) {
            return '«' . $t . '»';
        }
        // Trailing punctuation ("Reason:", "Saved.") and wrapping brackets.
        if (preg_match('/^(.*?)\s*([:.!?…]+)$/u', $s, $m) && $m[1] !== '' && ($t = self::phrase($m[1])) !== null) {
            return $t . str_replace(['?', ':'], ['؟', ':'], $m[2]);
        }
        if (preg_match('/^\((.+)\)$/u', $s, $m) && ($t = self::phrase($m[1])) !== null) {
            return '(' . $t . ')';
        }
        return null;
    }

    /** Exact wording only (interface phrase or known data), no patterns. */
    public static function exact(string $s): ?string
    {
        self::load();
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
        return self::$strings[$s] ?? self::$data[$s] ?? null;
    }

    /** Arabic for a piece of data (course title, outcome, name) when known; null otherwise. */
    public static function data(?string $s): ?string
    {
        if ($s === null || $s === '') {
            return null;
        }
        self::load();
        return self::$data[trim(preg_replace('/\s+/u', ' ', $s) ?? $s)] ?? null;
    }

    /**
     * Text shortened for display ("Describe software quality models…"): find the full English it
     * was cut from and shorten its Arabic to a similar length.
     */
    private static function shortened(string $s): ?string
    {
        if (!preg_match('/^(.{8,}?)\s*…$/u', $s, $m)) {
            return null;
        }
        $prefix = $m[1];
        foreach ([self::$data, self::$strings] as $map) {
            foreach ($map as $en => $ar) {
                if (str_starts_with((string) $en, $prefix)) {
                    $len = max(12, mb_strlen($prefix));
                    return mb_strlen($ar) > $len + 2 ? rtrim(mb_substr($ar, 0, $len)) . '…' : $ar;
                }
            }
        }
        return null;
    }

    /**
     * Translates the user-facing fields of a JSON reply (toasts after saving, search suggestions),
     * which do not pass through the page filter. @param list<string> $keys
     */
    public static function json(array $payload, array $keys = ['message', 'error', 'cleared', 'opened', 'type', 'title', 'sub']): array
    {
        if (!self::rtl()) {
            return $payload;
        }
        $tr = static function ($v) {
            if (!is_string($v) || !preg_match('/[A-Za-z]/', $v)) {
                return $v;
            }
            return self::phrase($v) ?? $v;
        };
        foreach ($payload as $k => $v) {
            if (is_array($v)) {
                $payload[$k] = in_array((string) $k, $keys, true) && array_is_list($v) ? array_map($tr, $v) : self::json($v, $keys);
            } elseif (in_array((string) $k, $keys, true)) {
                $payload[$k] = $tr($v);
            }
        }
        return $payload;
    }

    private static function text(string $t): string
    {
        if (trim($t) === '' || !preg_match('/[A-Za-z]/', $t)) {
            return $t;
        }
        $plain = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tr = self::phrase($plain) ?? self::shortened(trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain));
        preg_match('/^\s*/u', $t, $lead);
        preg_match('/\s*$/u', $t, $trail);
        if ($tr === null) {
            self::$missing[trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain)] = 1;
            // English data (titles, outcome statements, names) keeps its own direction inside the
            // right-to-left page: a first-strong isolate stops its punctuation jumping to the wrong end.
            return $lead[0] . "\u{2068}" . trim($t) . "\u{2069}" . $trail[0];
        }
        return $lead[0] . htmlspecialchars($tr, ENT_NOQUOTES, 'UTF-8') . $trail[0];
    }

    private static function tag(string $tag): string
    {
        if (stripos($tag, '<html') === 0) {
            return preg_replace('/\blang="[^"]*"/', 'lang="ar" dir="rtl"', $tag, 1) ?? $tag;
        }
        if ($tag[1] === '/' || $tag[1] === '!' || !preg_match('/\b(placeholder|title|aria-label|alt|data-confirm|data-prompt|value)="/', $tag)) {
            return $tag;
        }
        $isButtonValue = (bool) preg_match('/^<input\b[^>]*\btype="(submit|button|reset)"/i', $tag);
        return preg_replace_callback('/\b(placeholder|title|aria-label|alt|data-confirm|data-prompt|value)="([^"]*)"/', static function (array $m) use ($isButtonValue): string {
            if ($m[1] === 'value' && !$isButtonValue) {
                return $m[0];
            }
            $plain = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!preg_match('/[A-Za-z]/', $plain)) {
                return $m[0];
            }
            $tr = self::phrase($plain) ?? self::shortened($plain);
            if ($tr === null) {
                self::$missing[$plain] = 1;
                return $m[0];
            }
            return $m[1] . '="' . htmlspecialchars($tr, ENT_QUOTES, 'UTF-8') . '"';
        }, $tag) ?? $tag;
    }

    private static function load(): void
    {
        if (self::$strings !== null) {
            return;
        }
        $d = require __DIR__ . '/lang/ar.php';
        self::$strings = $d['strings'];
        self::$patterns = $d['patterns'];
        self::$data = \Saqf\Core\Translations::all();
    }

    /** Forgets loaded wording (after Arabic names are saved in the same request, and in tests). */
    public static function flush(): void
    {
        self::$strings = null;
        self::$patterns = null;
        self::$data = null;
        \Saqf\Core\Translations::flush();
    }

    /** @return array{strings:int,patterns:int} dictionary size (shown in docs and tests) */
    public static function size(): array
    {
        self::load();
        return ['strings' => count(self::$strings), 'patterns' => count(self::$patterns)];
    }
}
