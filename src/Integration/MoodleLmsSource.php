<?php
declare(strict_types=1);

namespace Saqf\Integration;

use RuntimeException;
use Saqf\Core\Clock;
use Saqf\Core\Config;
use Saqf\Core\Secrets;

/**
 * Moodle connector (Web Services REST). Needs a web-service token for a service that allows:
 *   core_webservice_get_site_info, core_course_get_courses_by_field, gradereport_user_get_grade_items
 * Settings: SAQF_MOODLE_URL, SAQF_MOODLE_TOKEN, SAQF_MOODLE_COURSE_FIELD (idnumber | shortname),
 * SAQF_LMS_COURSE_KEY (pattern for the Moodle course id number, default {term}-{code_nospace}).
 * Grade items are converted to percentages using their own min/max; students are pseudonymised.
 */
final class MoodleLmsSource implements LmsSource
{
    public const FUNCTIONS = ['core_webservice_get_site_info', 'core_course_get_courses_by_field', 'gradereport_user_get_grade_items'];

    private string $base;
    private string $token;
    private string $field;

    public function __construct(?string $base = null, ?string $token = null)
    {
        $this->base = rtrim($base ?? (string) Config::get('SAQF_MOODLE_URL', ''), '/');
        $this->token = $token ?? (string) Config::get('SAQF_MOODLE_TOKEN', '');
        $this->field = in_array(Config::get('SAQF_MOODLE_COURSE_FIELD'), ['idnumber', 'shortname'], true) ? (string) Config::get('SAQF_MOODLE_COURSE_FIELD') : 'idnumber';
    }

    public function label(): string
    {
        return 'Moodle (' . ($this->base !== '' ? Http::host($this->base) : 'SAQF_MOODLE_URL not set') . ', courses matched by ' . $this->field . ')';
    }

    public function batches(string $termCode, string $courseCode, ?string $section = null): array
    {
        $key = Integrations::lmsCourseKey($termCode, $courseCode, $section);
        $courses = $this->call('core_course_get_courses_by_field', ['field' => $this->field, 'value' => $key])['courses'] ?? [];
        if (!$courses) {
            return [];
        }
        $courseId = (int) $courses[0]['id'];
        $data = $this->call('gradereport_user_get_grade_items', ['courseid' => $courseId]);
        $results = [];
        foreach ($data['usergrades'] ?? [] as $ug) {
            $student = Secrets::pseudonym('moodle', (string) $ug['userid']);
            foreach ($ug['gradeitems'] ?? [] as $item) {
                if (in_array($item['itemtype'] ?? '', ['course', 'category'], true) || !empty($item['gradeishidden']) || !empty($item['gradehiddenbydate']) || !empty($item['hidden'])) {
                    continue;
                }
                $raw = $item['graderaw'] ?? null;
                $min = (float) ($item['grademin'] ?? 0);
                $max = (float) ($item['grademax'] ?? 0);
                $name = trim((string) ($item['itemname'] ?? ''));
                if ($raw === null || $name === '' || $max <= $min) {
                    continue;
                }
                $results[$name][$student] = round(max(0.0, min(100.0, ((float) $raw - $min) / ($max - $min) * 100)), 2);
            }
        }
        if (!$results) {
            return [];
        }
        ksort($results);
        return [[
            'ref' => 'MOODLE-' . $courseId . '-' . substr(sha1(json_encode($results)), 0, 20),
            'published_at' => Clock::stamp(),
            'label' => 'Moodle gradebook (' . $key . ')',
            'results' => $results,
        ]];
    }

    public function pending(): array
    {
        return [];
    }

    public function check(): array
    {
        try {
            $info = $this->call('core_webservice_get_site_info');
            $allowed = array_column($info['functions'] ?? [], 'name');
            $missing = array_diff(self::FUNCTIONS, $allowed);
            return $missing
                ? ['ok' => false, 'message' => 'Connected to ' . ($info['sitename'] ?? 'Moodle') . ', but the token cannot call: ' . implode(', ', $missing)]
                : ['ok' => true, 'message' => 'Connected to ' . ($info['sitename'] ?? 'Moodle') . ' (Moodle ' . ($info['release'] ?? '?') . ')'];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private function call(string $function, array $params = []): array
    {
        if ($this->base === '' || $this->token === '') {
            throw new RuntimeException('SAQF_MOODLE_URL and SAQF_MOODLE_TOKEN must be configured.');
        }
        $body = http_build_query(['wstoken' => $this->token, 'wsfunction' => $function, 'moodlewsrestformat' => 'json'] + $params);
        $data = Http::json('POST', $this->base . '/webservice/rest/server.php', ['Content-Type' => 'application/x-www-form-urlencoded'], $body);
        if (isset($data['exception'])) {
            throw new RuntimeException('Moodle refused ' . $function . ': ' . mb_substr((string) ($data['message'] ?? $data['errorcode'] ?? 'error'), 0, 200));
        }
        return $data;
    }
}
