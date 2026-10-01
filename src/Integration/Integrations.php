<?php
declare(strict_types=1);

namespace Saqf\Integration;

use InvalidArgumentException;
use Saqf\Core\Config;

/**
 * Registry so the rest of SAQF never instantiates a concrete adapter directly.
 * The connector for each university system comes from configuration:
 *   SAQF_SIS_SOURCE = file | rest | demo | none     (default: demo in demo mode, otherwise file)
 *   SAQF_LMS_SOURCE = moodle | blackboard | file | demo | none
 * Each connector reads its own settings; see docs/INTEGRATIONS.md.
 */
final class Integrations
{
    public const SIS_KINDS = ['file', 'rest', 'demo', 'none'];
    public const LMS_KINDS = ['moodle', 'blackboard', 'file', 'demo', 'none'];

    private static ?InstitutionSource $institution = null;
    private static ?SisSource $sis = null;
    private static ?LmsSource $lms = null;

    public static function institution(): InstitutionSource
    {
        return self::$institution ??= new CatalogFileSource();
    }

    public static function sis(): SisSource
    {
        if (self::$sis === null) {
            switch (self::sisKind()) {
                case 'demo':
                    self::$sis = new SeededSisSource();
                    break;
                case 'rest':
                    self::$sis = new RestSisSource();
                    break;
                case 'none':
                    self::$sis = new NullSisSource();
                    break;
                default:
                    self::$sis = new FileSisSource();
            }
        }
        return self::$sis;
    }

    public static function lms(): LmsSource
    {
        if (self::$lms === null) {
            switch (self::lmsKind()) {
                case 'demo':
                    self::$lms = new SeededLmsSource();
                    break;
                case 'moodle':
                    self::$lms = new MoodleLmsSource();
                    break;
                case 'blackboard':
                    self::$lms = new BlackboardLmsSource();
                    break;
                case 'none':
                    self::$lms = new NullLmsSource();
                    break;
                default:
                    self::$lms = new FileLmsSource();
            }
        }
        return self::$lms;
    }

    public static function sisKind(): string
    {
        return self::kind('SAQF_SIS_SOURCE', self::SIS_KINDS);
    }

    public static function lmsKind(): string
    {
        return self::kind('SAQF_LMS_SOURCE', self::LMS_KINDS);
    }

    /** True when SIS data comes from the demo simulator (the demo story drives the semester cycle). */
    public static function simulated(): bool
    {
        return self::sis() instanceof SeededSisSource;
    }

    /** Course key used by LMS connectors: SAQF_LMS_COURSE_KEY with {term}, {code}, {code_nospace}. */
    public static function lmsCourseKey(string $termCode, string $courseCode): string
    {
        $pattern = (string) (Config::get('SAQF_LMS_COURSE_KEY') ?: '{term}-{code_nospace}');
        return strtr($pattern, ['{term}' => $termCode, '{code}' => $courseCode, '{code_nospace}' => str_replace(' ', '', $courseCode)]);
    }

    public static function use(?InstitutionSource $i = null, ?SisSource $s = null, ?LmsSource $l = null): void
    {
        self::$institution = $i ?? self::$institution;
        self::$sis = $s ?? self::$sis;
        self::$lms = $l ?? self::$lms;
    }

    public static function reset(): void
    {
        self::$institution = self::$sis = self::$lms = null;
    }

    private static function kind(string $key, array $allowed): string
    {
        $v = strtolower(trim((string) Config::get($key, '')));
        if ($v === '') {
            return Config::demoMode() ? 'demo' : 'file';
        }
        if (!in_array($v, $allowed, true)) {
            throw new InvalidArgumentException("$key must be one of: " . implode(', ', $allowed));
        }
        return $v;
    }
}
