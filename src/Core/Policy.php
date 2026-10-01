<?php
declare(strict_types=1);

namespace Saqf\Core;

/**
 * Configurable institutional quality policy. Every threshold the rules engine uses
 * lives here (not hard-coded), so another university — or a YU policy change —
 * is a configuration change, not a code change. Changes are audited.
 */
final class Policy
{
    /** key => [default, type, label, help, options] */
    public const DEFAULTS = [
        'achievement.method' => ['threshold', 'enum', 'CLO achievement method', 'threshold = % of students reaching the student threshold on CLO-linked assessments; average = mean CLO score. Confirm the approved methodology with the Deanship of Quality.', 'threshold,average'],
        'achievement.student_threshold_pct' => ['70', 'float', 'Student threshold (%)', 'Score a student must reach on a CLO to count as achieving it (threshold method).', null],
        'clo.default_target_pct' => ['70', 'float', 'Default CLO target (%)', 'Applied automatically when a CLO has no course-specific target.', null],
        'plo.target_pct' => ['70', 'float', 'PLO target (%)', 'Program-level target used for PLO achievement checks.', null],
        'results.min_students' => ['5', 'int', 'Minimum students for a reliable result', 'Below this, achievement is shown but flagged as low-sample.', null],
        'assessment.weight_total_pct' => ['100', 'float', 'Required total assessment weight (%)', 'Assessment weights in a specification must add up to this value.', null],
        'assessment.max_single_weight_pct' => ['60', 'float', 'Maximum weight of a single assessment (%)', 'Policy rule; exceptions (e.g. capstones) need a QA-approved override.', null],
        'mapping.max_plos_per_clo' => ['3', 'int', 'Maximum PLOs per CLO', 'More mappings than this are flagged as possibly excessive.', null],
        'program.min_courses_per_plo' => ['2', 'int', 'Minimum courses contributing to each PLO', 'PLOs supported by fewer courses are flagged as a curriculum dependency risk.', null],
        'gap.recurrence_cycles' => ['2', 'int', 'Cycles before a gap counts as recurring', 'A CLO missing its target this many consecutive offerings escalates to the HoD.', null],
        'improvement.required_on_gap' => ['1', 'bool', 'Improvement action required when a target is missed', 'If on, every missed CLO target needs an improvement action with an owner and deadline.', null],
        'improvement.default_due_days' => ['120', 'int', 'Default improvement deadline (days)', 'Pre-filled deadline for new improvement actions (editable).', null],
        'effect.similar_band_pct' => ['3', 'float', 'Change band treated as "similar" (± points)', 'Used when comparing achievement before and after an improvement action.', null],
        'spec.auto_approve_unchanged' => ['1', 'bool', 'Inherit unchanged specifications without approval', 'An approved specification that did not change is reused next term with no approval step.', null],
        'spec.auto_clear_green' => ['1', 'bool', 'Auto-clear HoD-approved changes with no warnings', 'Changes with no open warnings skip QA after HoD sign-off (QA keeps a sample).', null],
        'qa.sample_rate_pct' => ['20', 'float', 'QA sampling rate for auto-cleared records (%)', 'Share of auto-cleared approvals placed in the QA sample queue.', null],
        'contact.weeks_per_term' => ['15', 'int', 'Teaching weeks per term', 'Used to derive contact hours from credit hours.', null],
        'integration.lms_autosync' => ['1', 'bool', 'Import LMS results automatically', 'When on, new result batches published by the LMS are imported by the scheduler.', null],
        'auth.max_failed_logins' => ['5', 'int', 'Failed logins before lockout', 'Account is locked temporarily after this many consecutive failures.', null],
        'auth.lockout_minutes' => ['15', 'int', 'Lockout duration (minutes)', null, null],
        'auth.ip_max_attempts_15min' => ['30', 'int', 'Max login attempts per IP per 15 min', 'Throttles password-guessing across accounts.', null],
        'session.idle_minutes' => ['30', 'int', 'Session idle timeout (minutes)', null, null],
        'session.absolute_hours' => ['8', 'int', 'Absolute session lifetime (hours)', null, null],
        'auth.min_password_length' => ['10', 'int', 'Minimum password length', null, null],
    ];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            try {
                foreach (Db::all('SELECT policy_key, value FROM quality_policies') as $r) {
                    self::$cache[$r['policy_key']] = $r['value'];
                }
            } catch (\Throwable $e) {
                // Table may not exist yet during installation.
            }
        }
        return self::$cache;
    }

    public static function get(string $key)
    {
        $all = self::all();
        $raw = $all[$key] ?? (self::DEFAULTS[$key][0] ?? null);
        $type = self::DEFAULTS[$key][1] ?? 'string';
        switch ($type) {
            case 'int':
                return (int) $raw;
            case 'float':
                return (float) $raw;
            case 'bool':
                return (bool) (int) $raw;
            default:
                return (string) $raw;
        }
    }

    public static function set(string $key, string $value, ?string $reason = null): void
    {
        if (!isset(self::DEFAULTS[$key])) {
            throw new \InvalidArgumentException('Unknown policy');
        }
        [$default, $type, $label, , $options] = self::DEFAULTS[$key];
        $value = trim($value);
        if ($type === 'int' && filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException("$label must be a whole number.");
        }
        if ($type === 'float' && !is_numeric($value)) {
            throw new \InvalidArgumentException("$label must be a number.");
        }
        if (in_array($type, ['int', 'float'], true) && (float) $value < 0) {
            throw new \InvalidArgumentException("$label cannot be negative.");
        }
        if (str_ends_with($key, '_pct') && (float) $value > 100) {
            throw new \InvalidArgumentException("$label cannot exceed 100.");
        }
        if ($type === 'bool') {
            $value = in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true) ? '1' : '0';
        }
        if ($type === 'enum' && !in_array($value, explode(',', (string) $options), true)) {
            throw new \InvalidArgumentException("$label must be one of: $options.");
        }
        $old = self::all()[$key] ?? $default;
        if ((string) $old === $value) {
            return;
        }
        Db::exec('UPDATE quality_policies SET value = ?, updated_by = ?, updated_at = ? WHERE policy_key = ?', [$value, $_SESSION['uid'] ?? null, Clock::stamp(), $key]);
        self::$cache = null;
        Audit::record('policy.changed', 'policy', $key, "Policy \"$label\" changed from $old to $value", ['value' => $old], ['value' => $value], $reason);
    }

    public static function seedDefaults(): void
    {
        foreach (self::DEFAULTS as $key => [$default, $type, $label, $help, $options]) {
            if (!Db::val('SELECT 1 FROM quality_policies WHERE policy_key = ?', [$key])) {
                Db::insert('quality_policies', ['policy_key' => $key, 'value' => $default, 'value_type' => $type, 'options' => $options, 'label' => $label, 'help' => $help]);
            }
        }
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
