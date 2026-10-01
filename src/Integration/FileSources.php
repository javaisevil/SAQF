<?php
declare(strict_types=1);

namespace Saqf\Integration;

use InvalidArgumentException;
use RuntimeException;
use Saqf\Core\Config;
use Saqf\Core\ErrorLog;

/**
 * SIS connector for scheduled exports dropped into a folder (SFTP / shared drive / ETL job).
 * Works with any SIS (Banner, PeopleSoft, in-house) that can export two CSV files:
 *   terms.csv        code,name,academic_year,sequence,starts_on,ends_on,grades_due_on
 *   assignments.csv  term,course,instructor_id,instructor_name,instructor_email,department,sections,enrolled
 * A sis.json in the demo format (data/demo/sis.json) is accepted instead of the two CSVs.
 */
final class FileSisSource implements SisSource
{
    private string $dir;
    private ?array $data = null;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? (string) (Config::get('SAQF_SIS_DIR') ?: SAQF_ROOT . '/storage/inbox/sis'), '/');
    }

    public function label(): string
    {
        return 'SIS export folder (' . $this->dir . ': terms.csv, assignments.csv)';
    }

    public function terms(): array
    {
        return $this->data()['terms'];
    }

    public function assignments(string $termCode): array
    {
        return $this->data()['assignments'][$termCode] ?? [];
    }

    public function check(): array
    {
        if (!is_dir($this->dir)) {
            return ['ok' => false, 'message' => "Folder {$this->dir} does not exist."];
        }
        try {
            $d = $this->data();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        $n = array_sum(array_map('count', $d['assignments']));
        return ['ok' => (bool) $d['terms'], 'message' => count($d['terms']) . " term(s), $n teaching assignment(s)" . ($d['terms'] ? '' : ' — terms.csv is missing or empty')];
    }

    private function data(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }
        $json = $this->dir . '/sis.json';
        if (is_file($json)) {
            $d = json_decode((string) file_get_contents($json), true, 512, JSON_THROW_ON_ERROR);
            $assignments = [];
            foreach ($d['assignments'] ?? [] as $term => $rows) {
                foreach ($rows as $r) {
                    $assignments[$term][] = self::assignment($r + ['term' => $term]);
                }
            }
            return $this->data = ['terms' => array_map([self::class, 'term'], $d['terms'] ?? []), 'assignments' => $assignments];
        }
        $terms = [];
        if (is_file($this->dir . '/terms.csv')) {
            foreach (Csv::rows($this->dir . '/terms.csv', ['code', 'name', 'academic_year', 'sequence', 'starts_on', 'ends_on', 'grades_due_on']) as $r) {
                $terms[] = self::term($r);
            }
        }
        $assignments = [];
        if (is_file($this->dir . '/assignments.csv')) {
            foreach (Csv::rows($this->dir . '/assignments.csv', ['term', 'course']) as $r) {
                $a = self::assignment($r);
                $assignments[$a['term']][] = $a;
            }
        }
        return $this->data = ['terms' => $terms, 'assignments' => $assignments];
    }

    public static function term(array $r): array
    {
        foreach (['starts_on', 'ends_on', 'grades_due_on'] as $f) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($r[$f] ?? ''))) {
                throw new RuntimeException("SIS term {$r['code']}: $f must be a date (YYYY-MM-DD).");
            }
        }
        return [
            'code' => trim((string) $r['code']), 'name' => trim((string) $r['name']), 'academic_year' => trim((string) $r['academic_year']),
            'sequence' => (int) $r['sequence'], 'starts_on' => $r['starts_on'], 'ends_on' => $r['ends_on'], 'grades_due_on' => $r['grades_due_on'],
        ];
    }

    public static function assignment(array $r): array
    {
        $instructor = trim((string) ($r['instructor_id'] ?? $r['instructor'] ?? ''));
        return [
            'term' => trim((string) ($r['term'] ?? '')),
            'course' => self::courseCode((string) $r['course']),
            'instructor' => $instructor === '' ? null : $instructor,
            'instructor_name' => trim((string) ($r['instructor_name'] ?? '')) ?: null,
            'instructor_email' => trim((string) ($r['instructor_email'] ?? '')) ?: null,
            'department' => trim((string) ($r['department'] ?? '')) ?: null,
            'sections' => max(1, (int) ($r['sections'] ?? 1)),
            'enrolled' => max(0, (int) ($r['enrolled'] ?? 0)),
        ];
    }

    /** "swe401", "SWE  401" and "SWE 401" all become "SWE 401" (the catalogue format). */
    public static function courseCode(string $code): string
    {
        $code = strtoupper(trim($code));
        return (string) preg_replace('/^([A-Z]+)\s*(\d.*)$/', '$1 $2', preg_replace('/\s+/', ' ', $code));
    }
}

/**
 * LMS connector for gradebook exports dropped into a folder, one sub-folder per term and course:
 *   <SAQF_LMS_DIR>/<term>/<COURSE>/<any name>.csv   e.g. storage/inbox/lms/2026-1/SWE401/midterm.csv
 * Each file uses the gradebook format (student,<assessment>,…; scores in %). A changed file is
 * re-imported; student identifiers are pseudonymised before they reach the database.
 */
final class FileLmsSource implements LmsSource
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? (string) (Config::get('SAQF_LMS_DIR') ?: SAQF_ROOT . '/storage/inbox/lms'), '/');
    }

    public function label(): string
    {
        return 'LMS gradebook export folder (' . $this->dir . '/<term>/<course>/*.csv)';
    }

    public function batches(string $termCode, string $courseCode): array
    {
        $courseDir = $this->courseDir($termCode, $courseCode);
        if ($courseDir === null) {
            return [];
        }
        $out = [];
        $files = glob($courseDir . '/*.csv') ?: [];
        sort($files);
        foreach ($files as $file) {
            if (time() - (int) filemtime($file) < 30) {
                continue; // still being written by the export job
            }
            try {
                $results = Gradebook::parseCsv($file, Config::bool('SAQF_LMS_PSEUDONYMIZE', true) ? 'lms' : null);
            } catch (InvalidArgumentException $e) {
                ErrorLog::record(new RuntimeException('Gradebook export ' . basename($file) . " ($termCode $courseCode) skipped: " . $e->getMessage()), 'warning');
                continue;
            }
            $key = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $termCode . $courseCode));
            $out[] = [
                'ref' => 'FILE-' . substr($key, 0, 24) . '-' . substr(sha1(basename($file) . ':' . sha1_file($file)), 0, 20),
                'published_at' => date('Y-m-d H:i:s', (int) filemtime($file)),
                'label' => 'Gradebook export ' . basename($file),
                'results' => $results,
            ];
        }
        return $out;
    }

    public function pending(): array
    {
        return [];
    }

    public function check(): array
    {
        if (!is_dir($this->dir)) {
            return ['ok' => false, 'message' => "Folder {$this->dir} does not exist."];
        }
        $n = count(glob($this->dir . '/*/*/*.csv') ?: []);
        return ['ok' => true, 'message' => "$n gradebook export file(s) present"];
    }

    private function courseDir(string $termCode, string $courseCode): ?string
    {
        $want = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $courseCode));
        foreach (glob($this->dir . '/' . basename($termCode) . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            if (strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', basename($d))) === $want) {
                return $d;
            }
        }
        return null;
    }
}

/** CSV reader for connector feeds: header row required, column names case-insensitive. */
final class Csv
{
    /** @return list<array<string,string>> */
    public static function rows(string $path, array $required = []): array
    {
        $fh = @fopen($path, 'r');
        if (!$fh) {
            throw new RuntimeException('Cannot read ' . basename($path) . '.');
        }
        try {
            $header = fgetcsv($fh);
            if (!$header) {
                return [];
            }
            $header[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $header = array_map(static fn($h) => strtolower(trim((string) $h)), $header);
            $missing = array_diff($required, $header);
            if ($missing) {
                throw new RuntimeException(basename($path) . ' is missing column(s): ' . implode(', ', $missing) . '.');
            }
            $rows = [];
            while (($row = fgetcsv($fh)) !== false) {
                if (count($row) === 1 && trim((string) $row[0]) === '') {
                    continue;
                }
                $assoc = [];
                foreach ($header as $i => $h) {
                    $assoc[$h] = trim((string) ($row[$i] ?? ''));
                }
                $rows[] = $assoc;
                if (count($rows) > 100000) {
                    throw new RuntimeException(basename($path) . ' has too many rows.');
                }
            }
            return $rows;
        } finally {
            fclose($fh);
        }
    }
}
