<?php
declare(strict_types=1);

namespace Saqf\Core;

use Throwable;

/**
 * Arabic wording for university data and course content (the interface itself is translated in
 * src/Web/lang). An English text is stored once with its Arabic version; the Arabic interface then
 * shows the Arabic wherever that text appears — course and program names, program and course
 * outcomes, assessment names, topics, department names and people's names — and the Arabic Word
 * documents use it too.
 *
 * Kinds: catalogue (from the Registrar: synced, never overwrites a person's correction),
 * content (course content written by faculty or entered here), people (names).
 */
final class Translations
{
    public const KINDS = ['content' => 'Course content', 'catalogue' => 'Catalogue', 'people' => 'People'];
    public const CSV_COLUMNS = ['english', 'arabic', 'kind'];

    private static ?array $cache = null;

    public static function key(string $text): string
    {
        return sha1(self::clean($text));
    }

    public static function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Every English → Arabic pair (cached for the request). */
    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            try {
                foreach (Db::all('SELECT source_text, text FROM translations WHERE lang = "ar"') as $r) {
                    self::$cache[self::clean($r['source_text'])] = $r['text'];
                }
            } catch (Throwable $e) {
                // the table arrives with the 2026-10-03 migration
            }
        }
        return self::$cache;
    }

    public static function get(?string $english): ?string
    {
        if ($english === null || trim($english) === '') {
            return null;
        }
        return self::all()[self::clean($english)] ?? null;
    }

    /** Arabic when the Arabic version exists and is wanted, otherwise the English as entered. */
    public static function pick(?string $english, string $lang): string
    {
        return $lang === 'ar' ? (self::get($english) ?? (string) $english) : (string) $english;
    }

    /**
     * Stores (or clears, when $arabic is empty) the Arabic for an English text.
     * Catalogue rows synced from the Registrar never overwrite a correction made by a person.
     */
    public static function set(string $english, ?string $arabic, string $kind = 'content', ?int $by = null): bool
    {
        $english = self::clean($english);
        $arabic = self::clean((string) $arabic);
        if ($english === '' || mb_strlen($english) > 2000) {
            return false;
        }
        $kind = isset(self::KINDS[$kind]) ? $kind : 'content';
        $hash = self::key($english);
        $old = Db::one('SELECT * FROM translations WHERE lang = "ar" AND source_hash = ?', [$hash]);
        if ($arabic === '') {
            if ($old && $by !== null) {
                Db::exec('DELETE FROM translations WHERE id = ?', [$old['id']]);
                self::$cache = null;
                return true;
            }
            return false;
        }
        if (mb_strlen($arabic) > 4000) {
            throw new \InvalidArgumentException('The Arabic text is too long.');
        }
        if ($old && $by === null && $old['updated_by'] !== null) {
            return false; // a person's correction wins over the automatic source
        }
        if ($old && $old['text'] === $arabic) {
            return false;
        }
        if ($old) {
            Db::update('translations', ['text' => $arabic, 'kind' => $old['kind'] === 'catalogue' && $kind === 'content' ? 'catalogue' : $kind, 'updated_by' => $by, 'updated_at' => Clock::stamp()], 'id = ?', [$old['id']]);
        } else {
            Db::insert('translations', ['lang' => 'ar', 'source_hash' => $hash, 'source_text' => $english, 'text' => $arabic, 'kind' => $kind, 'updated_by' => $by, 'updated_at' => Clock::stamp()]);
        }
        self::$cache = null;
        return true;
    }

    /** Several pairs at once (no audit entry per row). @param array<string,string> $pairs @return int rows stored */
    public static function setMany(array $pairs, string $kind, ?int $by = null): int
    {
        $n = 0;
        foreach ($pairs as $en => $ar) {
            $n += self::set((string) $en, (string) $ar, $kind, $by) ? 1 : 0;
        }
        return $n;
    }

    /**
     * Arabic names from a Registrar catalogue snapshot: an arabic.json section (keyed by codes, or by
     * the English for plan groups, elective slots and conditions) and/or inline fields (name_ar,
     * short_name_ar, title_ar, text_ar, group_ar, condition_ar). @return int rows stored
     */
    public static function fromCatalogue(array $snap): int
    {
        $ar = $snap['arabic'] ?? [];
        $pairs = [];
        $put = static function (?string $en, ?string $arText) use (&$pairs): void {
            if ($en !== null && $en !== '' && $arText !== null && $arText !== '') {
                $pairs[$en] = $arText;
            }
        };
        if (!empty($snap['institution'])) {
            $put($snap['institution']['name'] ?? null, $snap['institution']['name_ar'] ?? null);
        }
        foreach ($snap['colleges'] ?? [] as $c) {
            $put($c['name'], $c['name_ar'] ?? null);
        }
        foreach ($snap['departments'] ?? [] as $d) {
            $put($d['name'], $d['name_ar'] ?? ($ar['departments'][$d['code']] ?? null));
        }
        foreach ($snap['descriptions'] ?? [] as $code => $d) {
            $put($d['text'] ?? null, $d['text_ar'] ?? ($ar['descriptions'][$code] ?? null));
        }
        foreach ($snap['programs'] ?? [] as $p) {
            $pa = $ar['programs'][$p['code']] ?? [];
            $put($p['name'], $p['name_ar'] ?? ($pa['name'] ?? null));
            $put($p['short_name'], $p['short_name_ar'] ?? ($pa['short_name'] ?? null));
            foreach (['plo_source', 'accreditation_note'] as $field) {
                $put($p[$field] ?? null, $p[$field . '_ar'] ?? ($ar['notes'][$p[$field] ?? ''] ?? null));
            }
            foreach ($p['plos'] ?? [] as $plo) {
                $put($plo['text'], $plo['text_ar'] ?? ($ar['plos'][$p['code']][$plo['code']] ?? null));
            }
            foreach ($p['courses'] ?? [] as $c) {
                $slot = !empty($c['slot']) || empty($c['code']);
                $put($c['title'] ?? null, $c['title_ar'] ?? ($slot ? ($ar['slots'][$c['title'] ?? ''] ?? null) : ($ar['courses'][$c['code']] ?? null)));
                $put($c['group'] ?? null, $c['group_ar'] ?? ($ar['groups'][$c['group'] ?? ''] ?? null));
            }
            foreach ($p['elective_rules'] ?? [] as $r) {
                $put($r['group'] ?? null, $r['group_ar'] ?? ($ar['groups'][$r['group'] ?? ''] ?? null));
                $put($r['condition'] ?? null, $r['condition_ar'] ?? ($ar['conditions'][$r['condition'] ?? ''] ?? null));
            }
        }
        return self::setMany($pairs, 'catalogue');
    }

    /**
     * English texts in use that have no Arabic yet and are not already shown in Arabic by the interface
     * wording (what the "Arabic wording" page lists).
     * @return list<array{english:string,kind:string,where:string}>
     */
    public static function missing(string $kind = '', int $limit = 300): array
    {
        $have = self::all();
        $queries = [
            'content' => [
                ['SELECT DISTINCT c.statement t FROM clos c JOIN spec_versions sv ON sv.id = c.spec_version_id WHERE sv.status IN ("approved","draft","pending_hod","pending_qa")', 'Learning outcome'],
                ['SELECT DISTINCT a.name t FROM assessments a JOIN spec_versions sv ON sv.id = a.spec_version_id WHERE sv.status IN ("approved","draft","pending_hod","pending_qa")', 'Assessment'],
                ['SELECT DISTINCT st.topic t FROM spec_topics st JOIN spec_versions sv ON sv.id = st.spec_version_id WHERE sv.status IN ("approved","draft")', 'Topic'],
                ['SELECT DISTINCT objectives t FROM spec_versions WHERE status IN ("approved","draft") AND objectives IS NOT NULL', 'Course objective'],
                ['SELECT DISTINCT teaching_strategies t FROM spec_versions WHERE status IN ("approved","draft") AND teaching_strategies IS NOT NULL', 'Teaching strategies'],
                ['SELECT DISTINCT title t FROM evidence_files WHERE deleted_at IS NULL', 'Evidence title'],
                ['SELECT DISTINCT title t FROM improvement_actions', 'Improvement action'],
            ],
            'catalogue' => [
                ['SELECT DISTINCT c.title t FROM courses c JOIN study_plan_entries s ON s.course_id = c.id', 'Course title'],
                ['SELECT DISTINCT slot_title t FROM study_plan_entries WHERE slot_title IS NOT NULL', 'Elective slot'],
                ['SELECT DISTINCT requirement_group t FROM study_plan_entries', 'Study plan group'],
                ['SELECT DISTINCT group_name t FROM elective_rules', 'Study plan group'],
                ['SELECT DISTINCT condition_text t FROM elective_rules WHERE condition_text IS NOT NULL', 'Elective condition'],
                ['SELECT DISTINCT plo_source t FROM programs WHERE plo_source IS NOT NULL', 'Program note'],
                ['SELECT DISTINCT accreditation_note t FROM programs WHERE accreditation_note IS NOT NULL', 'Program note'],
                ['SELECT DISTINCT name t FROM programs', 'Program name'],
                ['SELECT DISTINCT short_name t FROM programs', 'Program short name'],
                ['SELECT DISTINCT statement t FROM plos', 'Program learning outcome'],
                ['SELECT DISTINCT name t FROM departments', 'Department'],
                ['SELECT DISTINCT name t FROM colleges', 'College'],
            ],
            'people' => [
                ['SELECT DISTINCT full_name t FROM users WHERE status <> "disabled"', 'Person'],
            ],
        ];
        $out = [];
        foreach ($queries as $k => $list) {
            if ($kind !== '' && $kind !== $k) {
                continue;
            }
            foreach ($list as [$sql, $where]) {
                try {
                    $rows = Db::col($sql);
                } catch (Throwable $e) {
                    continue;
                }
                foreach ($rows as $t) {
                    $t = self::clean((string) $t);
                    if ($t === '' || isset($have[$t]) || !preg_match('/[A-Za-z]/', $t) || isset($out[$t])) {
                        continue;
                    }
                    // Already shown in Arabic by the interface wording (e.g. "Improve SWE 401 CLO3 achievement").
                    $shown = \Saqf\Web\I18n::phrase($t);
                    if ($shown !== null && preg_match('/\p{Arabic}/u', $shown)) {
                        continue;
                    }
                    $out[$t] = ['english' => $t, 'kind' => $k, 'where' => $where];
                }
            }
        }
        return array_slice(array_values($out), 0, $limit);
    }

    /** @return array{content:array{done:int,missing:int},catalogue:array{done:int,missing:int},people:array{done:int,missing:int}} */
    public static function coverage(): array
    {
        $out = [];
        foreach (array_keys(self::KINDS) as $k) {
            $done = (int) Db::val('SELECT COUNT(*) FROM translations WHERE lang = "ar" AND kind = ?', [$k]);
            $out[$k] = ['done' => $done, 'missing' => count(self::missing($k, 100000))];
        }
        return $out;
    }

    /** CSV of English texts and their Arabic (empty Arabic = still to translate). */
    public static function csv(string $kind = '', bool $onlyMissing = false): string
    {
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, self::CSV_COLUMNS);
        foreach (self::missing($kind, 100000) as $m) {
            fputcsv($fh, [$m['english'], '', $m['kind']]);
        }
        if (!$onlyMissing) {
            $sql = 'SELECT source_text, text, kind FROM translations WHERE lang = "ar"' . ($kind !== '' ? ' AND kind = ?' : '') . ' ORDER BY kind, source_text';
            foreach (Db::all($sql, $kind !== '' ? [$kind] : []) as $r) {
                fputcsv($fh, [$r['source_text'], $r['text'], $r['kind']]);
            }
        }
        rewind($fh);
        return (string) stream_get_contents($fh);
    }

    /**
     * Imports a CSV (english, arabic, kind). Rows with an empty Arabic cell are skipped; rows of a kind
     * the person may not word (IT: course content) are refused.
     * @param list<string>|null $allowedKinds
     * @return array{saved:int,skipped:int,errors:list<string>}
     */
    public static function importCsv(string $path, int $by, ?array $allowedKinds = null): array
    {
        $fh = fopen($path, 'r');
        if (!$fh) {
            throw new \InvalidArgumentException('The file could not be read.');
        }
        $first = fgets($fh);
        if ($first === false) {
            throw new \InvalidArgumentException('The file is empty.');
        }
        $header = array_map(static fn($h) => strtolower(trim((string) $h)), str_getcsv(preg_replace('/^\xEF\xBB\xBF/', '', $first) ?? $first));
        $col = array_flip($header);
        if (!isset($col['english'], $col['arabic'])) {
            throw new \InvalidArgumentException('The first row must name the columns: english, arabic (and optionally kind).');
        }
        $saved = 0;
        $skipped = 0;
        $errors = [];
        $line = 1;
        while (($row = fgetcsv($fh)) !== false) {
            $line++;
            $en = self::clean((string) ($row[$col['english']] ?? ''));
            $arText = self::clean((string) ($row[$col['arabic']] ?? ''));
            $kind = isset($col['kind']) ? strtolower(trim((string) ($row[$col['kind']] ?? ''))) : 'content';
            if ($en === '' || $arText === '') {
                $skipped++;
                continue;
            }
            if (!preg_match('/\p{Arabic}/u', $arText)) {
                $errors[] = "Line $line: the Arabic column has no Arabic text.";
                continue;
            }
            $kind = isset(self::KINDS[$kind]) ? $kind : 'content';
            if ($allowedKinds !== null && !in_array($kind, $allowedKinds, true)) {
                $errors[] = "Line $line: you cannot change " . strtolower(self::KINDS[$kind]) . ' wording.';
                continue;
            }
            try {
                $saved += self::set($en, $arText, $kind, $by) ? 1 : 0;
            } catch (\InvalidArgumentException $e) {
                $errors[] = "Line $line: " . $e->getMessage();
            }
            if ($line > 20000) {
                $errors[] = 'Stopped after 20,000 rows.';
                break;
            }
        }
        fclose($fh);
        return compact('saved', 'skipped', 'errors');
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
