<?php
// SIMULATED university API (php -S router) used by tests/mapping_test.php and docs/mappings/*.simulated.json.
// It is deliberately NOT shaped like SAQF's own contract: different envelopes, field names, nested objects, d/m/Y
// dates, split names, coded assessment columns, and three different paging styles. All people and marks are
// synthetic. This is not Edugate and not any real learning system; it only proves that a mapping file, with no
// code, can connect SAQF to an API of any shape.
header('Content-Type: application/json');
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $q);
$method = $_SERVER['REQUEST_METHOD'];
$send = static function ($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
};

if ($path === '/oauth/token' && $method === 'POST') {
    parse_str((string) file_get_contents('php://input'), $f);
    if (($f['grant_type'] ?? '') === 'client_credentials' && ($f['client_id'] ?? '') === 'saqf-sim' && ($f['client_secret'] ?? '') === 'sim-secret') {
        $send(['access_token' => 'oauth-sim-token', 'token_type' => 'Bearer', 'expires_in' => 3600]);
    }
    $send(['error' => 'invalid_client'], 401);
}
if ($method !== 'GET') {
    $send(['error' => 'read only'], 405);
}
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$key = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!in_array($auth, ['Bearer uni-token', 'Bearer oauth-sim-token'], true) && $key !== 'uni-api-key') {
    $send(['error' => 'unauthorized'], 401);
}

if ($path === '/html') {
    header('Content-Type: text/html');
    echo '<html><body>Maintenance page</body></html>';
    exit;
}
if ($path === '/registry/v2/big') { // an answer far larger than SAQF will read
    echo '{"payload":{"rows":[{"semester_code":"';
    for ($i = 0; $i < 34; $i++) {
        echo str_repeat('a', 1048576);
        flush();
    }
    echo '"}]}}';
    exit;
}
if ($path === '/health') {
    $send(['ok' => true]);
}

// ---- registry: terms, paged by page / per_page ------------------------------------------------------------
if ($path === '/registry/v2/semesters') {
    $all = [
        ['semester_code' => '2026-1', 'title_en' => 'Fall 2026', 'acad_year' => '2026-2027', 'ordinal' => 3, 'begin' => '23/08/2026', 'finish' => '20/12/2026', 'grade_deadline' => '30/12/2026'],
        ['semester_code' => '2026-2', 'title_en' => 'Spring 2027', 'acad_year' => '2026-2027', 'ordinal' => 4, 'begin' => '10/01/2027', 'finish' => '20/05/2027', 'grade_deadline' => '30/05/2027'],
        ['semester_code' => '2026-3', 'title_en' => 'Summer 2027', 'acad_year' => '2026-2027', 'ordinal' => 5, 'begin' => '06/06/2027', 'finish' => '30/07/2027', 'grade_deadline' => '05/08/2027'],
    ];
    $per = max(1, min(50, (int) ($q['per_page'] ?? 2)));
    $page = max(1, (int) ($q['page'] ?? 1));
    $send(['status' => 'ok', 'payload' => ['rows' => array_slice($all, ($page - 1) * $per, $per), 'pages' => (int) ceil(count($all) / $per)]]);
}

// ---- registry: teaching assignments, paged by an opaque cursor --------------------------------------------
if (preg_match('#^/registry/v2/semesters/([^/]+)/teaching$#', $path, $m)) {
    if ($m[1] !== '2026-2') {
        $send(['status' => 'ok', 'payload' => ['rows' => [], 'next_cursor' => null]]);
    }
    $rows = [
        ['offering' => ['course_no' => 'swe401', 'section_no' => '1'], 'staff' => ['id' => 'YU-F1034', 'first' => 'Omar', 'last' => 'Al-Harbi', 'mail' => 'omar.sim@yu.example'], 'dept_code' => 'SWE', 'enrolled_count' => '21', 'is_coordinator' => 'Y'],
        ['offering' => ['course_no' => 'swe401', 'section_no' => '2'], 'staff' => ['id' => 'YU-F2210', 'first' => 'Hessa', 'last' => 'Al-Qahtani', 'mail' => 'hessa.sim@yu.example'], 'dept_code' => 'SWE', 'enrolled_count' => '20', 'is_coordinator' => 'N'],
        ['offering' => ['course_no' => 'CIS 491', 'section_no' => '1'], 'staff' => ['id' => 'YU-F9001', 'first' => 'Hala', 'last' => 'Al-Mutairi', 'mail' => 'hala.sim@yu.example'], 'dept_code' => 'CIS', 'enrolled_count' => '19', 'is_coordinator' => 'Y'],
    ];
    $from = (int) ($q['after'] ?? 0);
    $chunk = array_slice($rows, $from, 2);
    $send(['status' => 'ok', 'payload' => ['rows' => $chunk, 'next_cursor' => $from + 2 < count($rows) ? (string) ($from + 2) : null]]);
}

// ---- LMS: marks as rows, paged by a "next" link (relative) -------------------------------------------------
if (preg_match('#^/lms/v1/courses/([^/]+)/marks$#', $path, $m)) {
    $course = $m[1];
    if ($course === 'LOOP-1') { // a broken API that keeps sending the same page
        $send(['data' => ['marks' => [['learner_no' => '209900001', 'column' => 'MT', 'points' => 10, 'points_possible' => 50]]], 'links' => ['next' => '/lms/v1/courses/LOOP-1/marks']]);
    }
    if ($course === 'OTHER-1') { // a next link that leaves the configured host
        $send(['data' => ['marks' => [['learner_no' => '209900001', 'column' => 'MT', 'points' => 10, 'points_possible' => 50]]], 'links' => ['next' => 'https://evil.example.invalid/steal']]);
    }
    if ($course !== '2026-1-SWE401') {
        $send(['error' => 'no such course'], 404);
    }
    // 12 students (synthetic numbers 2099 00001…), two assessments (coded "MT" and "FN"), section 1 or 2.
    $rows = [];
    for ($i = 1; $i <= 12; $i++) {
        $id = '2099' . str_pad((string) $i, 5, '0', STR_PAD_LEFT);
        $group = $i <= 6 ? '1' : '2';
        $rows[] = ['learner_no' => $id, 'column' => 'MT', 'points' => 20 + $i * 2, 'points_possible' => 50, 'group' => $group];
        $rows[] = ['learner_no' => $id, 'column' => 'FN', 'points' => $i === 12 ? null : 40 + $i, 'points_possible' => 100, 'group' => $group];
        $rows[] = ['learner_no' => $id, 'column' => 'TOTAL', 'points' => 70, 'points_possible' => 100, 'group' => $group];
    }
    $limit = max(1, min(100, (int) ($q['limit'] ?? 10)));
    $offset = max(0, (int) ($q['offset'] ?? 0));
    $chunk = array_slice($rows, $offset, $limit);
    $next = $offset + $limit < count($rows) ? "/lms/v1/courses/$course/marks?offset=" . ($offset + $limit) . "&limit=$limit" : null;
    $send(['data' => ['marks' => $chunk], 'links' => ['next' => $next]]);
}
$send(['error' => 'not found'], 404);
