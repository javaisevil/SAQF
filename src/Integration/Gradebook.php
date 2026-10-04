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
 *
 * Student identifiers are ALWAYS replaced by keyed pseudonyms while the file is read, so a student
 * number never leaves this class. The manual upload and the export folder use the same key space
 * (SYSTEM), so a student's midterm uploaded by hand and final exported by the LMS still match.
 */
final class Gradebook
{
    public const MAX_ROWS = 5000;
    /** Key space shared by every gradebook file (manual upload and LMS export folder). */
    public const SYSTEM = 'lms';

    /** @return array<string,array<string,float>> assessment name => [student key => score %] */
    public static function parseCsv(string $path, string $system = self::SYSTEM): array
    {
        return self::parse($path, $system)['results'];
    }

    /**
     * @param string $system key space for the pseudonyms (never empty: there is no "keep raw IDs" mode)
     * @return array{results:array<string,array<string,float>>,sections:array<string,string>}
     */
    public static function parse(string $path, string $system = self::SYSTEM): array
    {
        if ($system === '') {
            throw new InvalidArgumentException('A pseudonym key space is required: student identifiers are never stored as given.');
        }
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
                throw new InvalidArgumentException('The first column must be "student" (the student number or LMS key; SAQF replaces it with a code before storing), followed by one column per assessment name.');
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
                $student = Secrets::pseudonym($system, trim((string) $row[0]));
                if ($hasSection && ($code = \Saqf\Quality\Sections::code($row[1] ?? '')) !== null) {
                    $sections[$student] = $code;
                }
                foreach (array_slice($header, $first) as $i => $name) {
                    $v = trim((string) ($row[$i + $first] ?? ''));
                    if ($v === '') {
                        continue;
                    }
                    if (!is_numeric($v) || (float) $v < 0 || (float) $v > 100) {
                        // The message names the line and the assessment, never the student.
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
