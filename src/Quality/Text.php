<?php
declare(strict_types=1);

namespace Saqf\Quality;

/** Small, dependency-free text utilities used by rules and assisted suggestions. */
final class Text
{
    public const VAGUE_VERBS = ['understand', 'know', 'learn', 'be familiar with', 'be aware of', 'appreciate', 'grasp', 'comprehend'];

    public const MEASURABLE_VERBS = [
        'adapt', 'analyze', 'analyse', 'apply', 'appraise', 'argue', 'assemble', 'assess', 'audit', 'build', 'calculate', 'categorize', 'choose', 'classify',
        'collaborate', 'communicate', 'compare', 'compose', 'compute', 'conduct', 'configure', 'construct', 'contrast', 'create', 'critique', 'debug', 'deduce', 'defend', 'define',
        'demonstrate', 'deploy', 'derive', 'describe', 'design', 'determine', 'develop', 'diagnose', 'differentiate', 'discuss', 'distinguish', 'document', 'draft', 'estimate',
        'evaluate', 'examine', 'explain', 'express', 'formulate', 'function', 'generate', 'identify', 'illustrate', 'implement', 'integrate', 'interpret', 'investigate', 'judge',
        'justify', 'lead', 'list', 'maintain', 'manage', 'measure', 'model', 'monitor', 'negotiate', 'operate', 'optimize', 'organize', 'outline', 'perform', 'plan', 'predict',
        'prepare', 'present', 'prioritize', 'produce', 'program', 'propose', 'prototype', 'recognize', 'recommend', 'report', 'research', 'secure', 'select', 'simulate', 'solve',
        'specify', 'summarize', 'synthesize', 'test', 'translate', 'troubleshoot', 'use', 'validate', 'verify', 'work', 'write',
    ];

    private const STOP = ['a', 'an', 'the', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'with', 'by', 'as', 'at', 'from', 'that', 'this', 'their', 'its', 'be', 'is', 'are',
        'using', 'use', 'into', 'through', 'within', 'including', 'such', 'other', 'relevant', 'appropriate', 'given', 'set', 'students', 'student', 'ability', 'able', 'will',
        'graduates', 'demonstrate', 'effectively', 'variety', 'context', 'contexts', 'both', 'well', 'based', 'program', 'programs', 'course', 'various', 'different'];

    public static function firstVerb(string $statement): string
    {
        $s = mb_strtolower(trim($statement));
        $s = preg_replace('/^(students|learners|graduates)\s+(will|should|can)\s+(be\s+able\s+to\s+)?/u', '', $s) ?? $s;
        $s = preg_replace('/^(be\s+able\s+to|able\s+to)\s+/u', '', $s) ?? $s;
        return $s;
    }

    /** @return 'measurable'|'vague'|'unknown' */
    public static function verbQuality(string $statement): string
    {
        $s = self::firstVerb($statement);
        foreach (self::VAGUE_VERBS as $v) {
            if (preg_match('/^' . preg_quote($v, '/') . '\b/u', $s)) {
                return 'vague';
            }
        }
        foreach (self::MEASURABLE_VERBS as $v) {
            if (preg_match('/^' . preg_quote($v, '/') . '(s|es|d|ed|ing)?\b/u', $s)) {
                return 'measurable';
            }
        }
        return 'unknown';
    }

    public static function normalize(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s) ?? $s;
        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    /** Light stemming so "designing/designs/design" match. */
    public static function tokens(string $s): array
    {
        $out = [];
        foreach (explode(' ', self::normalize($s)) as $w) {
            if ($w === '' || mb_strlen($w) < 3 || in_array($w, self::STOP, true)) {
                continue;
            }
            $w = preg_replace('/(ing|ed|es|s|ion|ions|ment|ments)$/u', '', $w) ?? $w;
            if (mb_strlen($w) >= 3 && !in_array($w, self::STOP, true)) {
                $out[] = $w;
            }
        }
        return $out;
    }

    /**
     * TF-IDF cosine similarity between one text and a set of candidate texts.
     * @param array<string|int,string> $candidates
     * @return array<string|int, array{score:float, shared:list<string>}>
     */
    public static function similarity(string $text, array $candidates): array
    {
        $docs = array_map([self::class, 'tokens'], $candidates);
        $query = self::tokens($text);
        $n = count($docs) + 1;
        $df = [];
        foreach (array_merge($docs, [$query]) as $d) {
            foreach (array_unique($d) as $t) {
                $df[$t] = ($df[$t] ?? 0) + 1;
            }
        }
        $vec = static function (array $tokens) use ($df, $n): array {
            $v = [];
            foreach (array_count_values($tokens) as $t => $tf) {
                $v[$t] = $tf * (log(($n + 1) / (($df[$t] ?? 0) + 1)) + 1);
            }
            return $v;
        };
        $q = $vec($query);
        $qn = sqrt(array_sum(array_map(static fn($x) => $x * $x, $q))) ?: 1.0;
        $out = [];
        foreach ($docs as $k => $d) {
            $v = $vec($d);
            $dot = 0.0;
            $shared = [];
            foreach ($q as $t => $w) {
                if (isset($v[$t])) {
                    $dot += $w * $v[$t];
                    $shared[] = $t;
                }
            }
            $vn = sqrt(array_sum(array_map(static fn($x) => $x * $x, $v))) ?: 1.0;
            $out[$k] = ['score' => round($dot / ($qn * $vn), 3), 'shared' => $shared];
        }
        return $out;
    }
}
