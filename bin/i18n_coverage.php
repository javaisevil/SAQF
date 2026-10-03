<?php
declare(strict_types=1);

/**
 * Arabic interface coverage: signs in as each demo role (demo mode only), crawls the screens in
 * Arabic and lists the English text still visible, most frequent first.
 *
 *   php bin/i18n_coverage.php [base-url] [--max=60] [--out=storage/i18n-missing.tsv]
 *
 * Names, course titles and other data are shown as entered, so some English is expected; the list
 * is for finding interface text that still needs a translation in src/Web/lang/ar.php.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

$base = 'http://127.0.0.1:8080';
$max = 60;
$out = null;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--max=')) {
        $max = max(1, (int) substr($a, 6));
    } elseif (str_starts_with($a, '--out=')) {
        $out = substr($a, 6);
    } else {
        $base = rtrim($a, '/');
    }
}

final class Browser
{
    private array $jar = [];

    public function __construct(private string $base)
    {
    }

    /** @return array{0:int,1:string,2:?string} status, body, redirect location */
    public function request(string $path, ?array $post = null): array
    {
        $headers = ['User-Agent: SAQF-i18n-coverage'];
        if ($this->jar) {
            $headers[] = 'Cookie: ' . implode('; ', array_map(static fn($k, $v) => "$k=$v", array_keys($this->jar), $this->jar));
        }
        $opts = ['method' => $post === null ? 'GET' : 'POST', 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 30, 'header' => $headers];
        if ($post !== null) {
            $opts['header'][] = 'Content-Type: application/x-www-form-urlencoded';
            $opts['content'] = http_build_query($post);
        }
        $body = @file_get_contents($this->base . '/' . ltrim($path, '/'), false, stream_context_create(['http' => $opts]));
        $status = 0;
        $location = null;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
                $status = (int) $m[1];
            } elseif (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $h, $m)) {
                $this->jar[trim($m[1])] = trim($m[2]);
            } elseif (preg_match('/^Location:\s*(.+)$/i', $h, $m)) {
                $location = trim($m[1]);
            }
        }
        return [$status, (string) $body, $location];
    }

    public function get(string $path, int $hops = 5): array
    {
        [$s, $b, $loc] = $this->request($path);
        while ($loc && $hops-- > 0) {
            [$s, $b, $loc] = $this->request(preg_replace('#^https?://[^/]+/#', '', $loc));
        }
        return [$s, $b];
    }
}

/** @return list<string> visible text with Latin letters (data and interface alike) */
function english(string $html): array
{
    $html = str_replace(["\u{2068}", "\u{2069}"], '', $html);
    $html = preg_replace('#<(script|style|code|pre|textarea)\b.*?</\1>#is', ' ', $html) ?? $html;
    $html = preg_replace('#<([a-z][a-z0-9]*)\b[^>]*\btranslate="no"[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
    $found = [];
    foreach (preg_split('/<[^>]*>/', $html) ?: [] as $t) {
        $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        if ($t !== '' && preg_match('/[A-Za-z]{2,}/', $t)) {
            $found[] = $t;
        }
    }
    preg_match_all('/\b(?:placeholder|title|aria-label|data-confirm)="([^"]*[A-Za-z]{2,}[^"]*)"/', $html, $m);
    foreach ($m[1] as $a) {
        $found[] = html_entity_decode($a, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return $found;
}

$counts = [];
$where = [];
$pages = 0;
$textNodes = 0;
$arabicNodes = 0;
$visit = static function (string $who, string $path, string $html) use (&$counts, &$where, &$pages, &$textNodes, &$arabicNodes): void {
    $pages++;
    $clean = preg_replace('#<(script|style|code|pre|textarea)\b.*?</\1>#is', ' ', $html) ?? $html;
    $clean = preg_replace('#<([a-z][a-z0-9]*)\b[^>]*\btranslate="no"[^>]*>.*?</\1>#is', ' ', $clean) ?? $clean;
    foreach (preg_split('/<[^>]*>/', $clean) ?: [] as $t) {
        $t = trim(html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (preg_match('/\p{Arabic}/u', $t)) {
            $textNodes++;
            $arabicNodes++;
        } elseif (preg_match('/[A-Za-z]{2,}/', $t)) {
            $textNodes++;
        }
    }
    foreach (english($html) as $t) {
        $counts[$t] = ($counts[$t] ?? 0) + 1;
        $where[$t] ??= "$who: $path";
    }
};

$public = new Browser($base);
$public->get('login.php?lang=ar');
foreach (['login.php', 'forgot.php', 'tour.php'] as $p) {
    [, $html] = $public->get($p);
    $visit('-', $p, $html);
}

foreach (\Saqf\Demo\Story::ROLE_ACCOUNTS as $who) {
    $b = new Browser($base);
    $b->get('login.php?lang=ar');
    [, $login] = $b->get('login.php');
    if (!preg_match('/name="_csrf" value="([^"]+)"/', $login, $m)) {
        fwrite(STDERR, "No demo sign-in on $base (is demo mode on?)\n");
        exit(1);
    }
    [$s, , $loc] = $b->request('demo.php', ['_csrf' => $m[1], 'as' => $who, 'next' => 'index.php']);
    $queue = [ltrim((string) preg_replace('#^https?://[^/]+/#', '', (string) $loc), '/') ?: 'index.php'];
    $seen = [];
    $shapes = [];
    while ($queue && count($seen) < $max) {
        $path = array_shift($queue);
        if (isset($seen[$path])) {
            continue;
        }
        $seen[$path] = true;
        [$status, $html] = $b->get($path);
        if ($status !== 200 || !str_contains($html, '<html')) {
            continue;
        }
        $visit($who, $path, $html);
        preg_match_all('/href="([a-z_]+\.php(?:\?[^"#]*)?)"/', $html, $links);
        foreach ($links[1] as $l) {
            $l = html_entity_decode($l, ENT_QUOTES);
            if (preg_match('/^(logout|export|evidence|demo|sso|api|health|tour|login|mfa|reset|forgot)\.php/', $l) || str_contains($l, 'lang=')) {
                continue;
            }
            // one page per "shape" (same script and parameter names, different ids) except tabs
            $shape = preg_replace('/=\d+/', '=N', $l);
            if (isset($shapes[$shape]) && !str_contains($l, 'tab=')) {
                continue;
            }
            $shapes[$shape] = true;
            $queue[] = $l;
        }
    }
}

arsort($counts);
$lines = [];
foreach ($counts as $t => $n) {
    $lines[] = $n . "\t" . $t . "\t" . $where[$t];
}
$pct = $textNodes ? round(100 * $arabicNodes / $textNodes, 1) : 0;
$summary = "Arabic coverage: $pages screens, $arabicNodes of $textNodes text items in Arabic ($pct%); " . count($counts) . ' distinct English strings remain (names, codes and course data included).';
if ($out) {
    file_put_contents($out, implode("\n", $lines) . "\n");
    echo $summary, "\nList written to $out\n";
} else {
    echo implode("\n", array_slice($lines, 0, 80)), "\n\n", $summary, "\n";
}
