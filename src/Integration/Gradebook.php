<?php
declare(strict_types=1);

namespace Saqf\Integration;

use InvalidArgumentException;
use Saqf\Core\Secrets;

/**
 * Gradebook CSV format shared by the manual upload and the LMS export drop folder:
 *   student,[section,]<assessment name>,<assessment name>,…   (one row per student, scores in %)
 * The optional "section" column tags each student with their course section. Validated strictly;
 * nothing in the file is trusted.
 */
final class Gradebook
{
    public const MAX_ROWS = 5000;

    /**
     * @param string|null $pseudonymize system name: student identifiers are replaced by keyed
     *                                  pseudonyms (real LMS exports carry student numbers)
     * @return array<string,array<string,float>> assessment name => [student key => score %]
     */
    public static function parseCsv(string $path, ?string $pseudonymize = null): array
    {
        return self::parse($path, $pseudonymize)['results'];
    }

    /** @return array{results:array<string,array<string,float>>,sections:array<string,string>} */
    public static function parse(string $path, ?string $pseudonymize = null): array
    {
        $fh = @fopen($path, 'r');
        if (!$fh) {
            throw new InvalidArgumentException('The gradebook file could not be read.');
        }
        try {
            $header = fgetcsv($fh);
            if ($header && isset($header[0])) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]); // Excel UTF-8 BOM
            }
            if (!$header || mb_strtolower(trim((string) $header[0])) !== 'student') {
                throw new InvalidArgumentException('The first column must be "student" (a pseudonymous student key), followed by one column per assessment name.');
            }
            $hasSection = isset($header[1]) && mb_strtolower(trim((string) $header[1])) === 'section';
            $first = $hasSection ? 2 : 1;
            $results = [];
            $sections = [];
            $line = 1;
            while (($row = fgetcsv($fh)) !== false) {
                $line++;
                if (count($row) < 2 || trim((string) $row[0]) === '') {
                    continue;
                }
                $student = trim((string) $row[0]);
                if ($pseudonymize !== null) {
                    $student = Secrets::pseudonym($pseudonymize, $student);
                }
                if ($hasSection && ($code = \Saqf\Quality\Sections::code($row[1] ?? '')) !== null) {
                    $sections[$student] = $code;
                }
                foreach (array_slice($header, $first) as $i => $name) {
                    $v = trim((string) ($row[$i + $first] ?? ''));
                    if ($v === '') {
                        continue;
                    }
                    if (!is_numeric($v) || (float) $v < 0 || (float) $v > 100) {
                        throw new InvalidArgumentException("Line $line: score for \"$name\" must be a percentage between 0 and 100.");
                    }
                    $results[trim((string) $name)][$student] = (float) $v;
                }
                if ($line > self::MAX_ROWS) {
                    throw new InvalidArgumentException('Too many rows.');
                }
            }
            return ['results' => $results, 'sections' => $sections];
        } finally {
            fclose($fh);
        }
    }
}
