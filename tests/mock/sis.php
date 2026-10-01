<?php
// Stand-in for a university SIS integration API (php -S router) used by tests/production_test.php.
header('Content-Type: application/json');
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer sis-token') {
    http_response_code(401);
    echo json_encode(['message' => 'Unauthorized']);
    exit;
}
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/api/terms') {
    echo json_encode(['terms' => [
        ['code' => '2026-1', 'name' => 'Fall 2026', 'academic_year' => '2026-2027', 'sequence' => 3, 'starts_on' => '2026-08-23', 'ends_on' => '2026-12-20', 'grades_due_on' => '2026-12-30'],
        ['code' => '2026-2', 'name' => 'Spring 2027', 'academic_year' => '2026-2027', 'sequence' => 4, 'starts_on' => '2027-01-10', 'ends_on' => '2027-05-20', 'grades_due_on' => '2027-05-30'],
    ]]);
} elseif ($path === '/api/terms/2026-2/assignments') {
    echo json_encode([
        ['course' => 'swe 401', 'instructor_id' => 'YU-F1034', 'sections' => '2', 'enrolled' => '41'],
        ['course' => 'CIS491', 'instructor_id' => 'YU-F9100', 'instructor_name' => 'Dr. Reem Al-Fahad', 'instructor_email' => 'reem@yu.example'],
    ]);
} else {
    http_response_code(404);
    echo json_encode(['message' => 'Not found']);
}
