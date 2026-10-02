<?php
declare(strict_types=1);

/**
 * Generates the SYNTHETIC demo feeds:
 *   data/demo/sis.json          — terms + teaching assignments (simulated Registrar feed)
 *   data/demo/lms/<term>/*.json — pseudonymous assessment results (simulated LMS gradebook)
 *
 * Scores are produced by a simple ability + noise model, with assessment difficulty tuned
 * by search so each CLO lands on the scenario's intended achievement. Deterministic (seeded).
 * No real student data is used or needed.
 *
 *   php bin/build_demo_data.php
 */

require __DIR__ . '/../src/bootstrap.php';

use Saqf\Demo\Story;

const THRESHOLD = 70.0;

function gauss(): float
{
    $u = max(1e-9, mt_rand() / mt_getrandmax());
    $v = mt_rand() / mt_getrandmax();
    return sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
}

/** @return array<string,array<string,float>> assessment name => [student => score] */
function generate(string $term, string $course, int $n, array $spec, array $targets, ?array $only): array
{
    $assessments = [];
    foreach ($spec['assessments'] as $i => [$name, $kind, $weight, $week, $clos]) {
        if ($only === null || in_array($name, $only, true)) {
            $assessments[$i] = ['name' => $name, 'weight' => (float) $weight, 'clos' => $clos];
        }
    }
    // CLO => list of available linked assessments
    $cloLinks = [];
    foreach ($spec['clos'] as $c => $_) {
        $cloLinks[$c + 1] = array_keys(array_filter($assessments, static fn($a) => in_array($c + 1, $a['clos'], true)));
    }
    $goal = [];
    foreach ($targets as $c => $t) {
        if ($t !== null && !empty($cloLinks[$c + 1])) {
            $goal[$c + 1] = (int) round($t / 100 * $n);
        }
    }
    $students = [];
    for ($s = 1; $s <= $n; $s++) {
        $students[] = 'S' . strtoupper(substr(sha1("$term|$course|$s"), 0, 7));
    }

    $GLOBALS['lastBest'] = 99;
    for ($attempt = 0; $attempt < 60; $attempt++) {
        mt_srand(crc32("$term|$course|$attempt"));
        $ability = [];
        $noise = [];
        foreach ($students as $sid) {
            $ability[$sid] = gauss();
            foreach ($assessments as $i => $_) {
                $noise[$sid][$i] = gauss();
            }
        }
        $d = array_fill_keys(array_keys($assessments), 0.0);
        $score = static function (string $sid, int $i) use (&$d, $ability, $noise): float {
            return max(15.0, min(100.0, round(71 + 12 * $ability[$sid] + 8 * $noise[$sid][$i] - $d[$i], 1)));
        };
        $counts = static function () use (&$d, $students, $assessments, $cloLinks, $score, $goal): array {
            $out = [];
            foreach ($goal as $c => $_) {
                $k = 0;
                foreach ($students as $sid) {
                    $num = 0.0;
                    $den = 0.0;
                    foreach ($cloLinks[$c] as $i) {
                        $num += $score($sid, $i) * $assessments[$i]['weight'];
                        $den += $assessments[$i]['weight'];
                    }
                    if ($den > 0 && $num / $den >= THRESHOLD) {
                        $k++;
                    }
                }
                $out[$c] = $k;
            }
            return $out;
        };
        $error = static function () use ($counts, $goal): int {
            $e = 0;
            foreach ($counts() as $c => $k) {
                $e += abs($k - $goal[$c]);
            }
            return $e;
        };
        $best = $error();
        for ($round = 0; $round < 40 && $best > 0; $round++) {
            $improved = false;
            foreach (array_keys($assessments) as $i) {
                $orig = $d[$i];
                $bestVal = $orig;
                for ($x = -24.0; $x <= 30.0; $x += 0.5) {
                    $d[$i] = $x;
                    $e = $error();
                    if ($e < $best) {
                        $best = $e;
                        $bestVal = $x;
                        $improved = true;
                    }
                }
                $d[$i] = $bestVal;
                if ($best === 0) {
                    break;
                }
            }
            if (!$improved) {
                break;
            }
        }
        // Stochastic polish when coordinate search stalls one or two students away.
        for ($k = 0; $k < 400 && $best > 0; $k++) {
            $saved = $d;
            foreach (array_keys($d) as $i) {
                $d[$i] += (mt_rand(-8, 8)) / 4;
            }
            $e = $error();
            if ($e <= $best) {
                $best = $e;
            } else {
                $d = $saved;
            }
        }
        $GLOBALS['lastBest'] = min($GLOBALS['lastBest'] ?? 99, $best);
        if ($best === 0) {
            $out = [];
            foreach ($assessments as $i => $a) {
                foreach ($students as $sid) {
                    $out[$a['name']][$sid] = $score($sid, $i);
                }
            }
            $achieved = $counts();
            $msg = [];
            foreach ($achieved as $c => $k) {
                $msg[] = "CLO$c " . round($k / $n * 100, 1) . '%';
            }
            echo str_pad("$term $course", 16) . ' n=' . $n . '  ' . implode('  ', $msg) . "\n";
            return $out;
        }
    }
    throw new RuntimeException("Could not hit targets for $term $course (best error " . ($GLOBALS["lastBest"] ?? "?") . ")");
}

/**
 * Places students in sections. The demo deliberately gives section 02 more of the students who
 * struggled on the midterm, so the per-section breakdown and the SECTION_GAP rule have something to
 * show; the overall course figures are unchanged.
 * @return array<string,string> student => section code
 */
function sectionMap(array $results, array $sections): array
{
    $mid = $results['Midterm exam'] ?? current($results);
    arsort($mid);
    $students = array_keys($mid);
    $first = (int) $sections[0][2];
    $out = [];
    $taken = 0;
    foreach ($students as $rank => $sid) {
        // ranks 0,1,2 → 01,01,02 in the top half; 01,02,02 below: section 02 skews weaker.
        $top = $rank < count($students) / 2;
        $wantFirst = $top ? ($rank % 3 !== 2) : ($rank % 3 === 0);
        $remainingFirst = $first - $taken;
        $remaining = count($students) - $rank;
        if ($remainingFirst <= 0) {
            $wantFirst = false;
        } elseif ($remainingFirst >= $remaining) {
            $wantFirst = true;
        }
        $out[$sid] = $wantFirst ? $sections[0][0] : $sections[1][0];
        $taken += $wantFirst ? 1 : 0;
    }
    return $out;
}

$sis = ['terms' => Story::TERMS, 'assignments' => [], 'pending_assignments' => Story::PENDING_ASSIGNMENTS];
$external = [];
foreach (Story::USERS as $u) {
    $external[$u[0]] = $u[6];
}
$lmsDir = SAQF_ROOT . '/data/demo/lms';
foreach (glob($lmsDir . '/*/*.json') ?: [] as $f) {
    unlink($f);
}
foreach (Story::OFFERINGS as $termCode => $offerings) {
    $term = current(array_filter(Story::TERMS, static fn($t) => $t['code'] === $termCode));
    foreach ($offerings as $course => $row) {
        [$instructor, $n, $targets] = $row;
        $batches = $row[3] ?? null;
        $sections = $row[4] ?? null;
        if ($sections) {
            // One SIS row per section; the first section's instructor coordinates the course.
            foreach ($sections as $i => [$code, $who, $count]) {
                $sis['assignments'][$termCode][] = ['course' => $course, 'instructor' => $external[$who], 'section' => $code, 'coordinator' => $i === 0, 'enrolled' => $count];
            }
        } else {
            $sis['assignments'][$termCode][] = ['course' => $course, 'instructor' => $external[$instructor], 'sections' => $n > 28 ? 2 : 1, 'enrolled' => $n];
        }
        if (!$targets || !isset(Story::SPECS[$course])) {
            continue;
        }
        $spec = Story::SPECS[$course];
        $files = [];
        if ($batches === null) {
            // Historical term: the full gradebook was exported after grades were due.
            if (count(array_filter($targets, static fn($t) => $t !== null)) === 0) {
                continue;
            }
            $results = generate($termCode, $course, $n, $spec, $targets, null);
            $files[] = ['ref' => "LMS-$termCode-" . str_replace(' ', '', $course) . '-FINAL', 'course' => $course, 'label' => 'Final gradebook export', 'published_at' => date('Y-m-d H:i:s', strtotime($term['grades_due_on'] . ' 09:00 -3 days')), 'results' => $results];
        } else {
            $allNames = [];
            foreach ($batches as $b) {
                $allNames = array_merge($allNames, $b[2]);
            }
            $results = generate($termCode, $course, $n, $spec, $targets, $allNames);
            foreach ($batches as $k => [$label, $published, $names]) {
                $files[] = ['ref' => "LMS-$termCode-" . str_replace(' ', '', $course) . '-B' . ($k + 1), 'course' => $course, 'label' => $label, 'published_at' => $published, 'results' => array_intersect_key($results, array_flip($names))];
            }
        }
        if ($sections && $files) {
            $map = sectionMap($results, $sections);
            foreach ($files as $k => $f) {
                $files[$k]['sections'] = array_intersect_key($map, array_flip(array_keys(current($f['results']) ?: [])));
            }
        }
        @mkdir("$lmsDir/$termCode", 0775, true);
        file_put_contents("$lmsDir/$termCode/" . str_replace(' ', '', $course) . '.json', json_encode($files, JSON_PRETTY_PRINT));
    }
}
@mkdir(SAQF_ROOT . '/data/demo', 0775, true);
file_put_contents(SAQF_ROOT . '/data/demo/sis.json', json_encode($sis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Demo feeds written to data/demo\n";
