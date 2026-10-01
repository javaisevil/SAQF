<?php
// Stand-in for Blackboard Learn's public REST API (php -S router) used by tests/production_test.php.
header('Content-Type: application/json');
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$offset = (int) ($_GET['offset'] ?? 0);
function reply(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}
if ($path === '/learn/api/public/v1/oauth2/token') {
    $ok = ($_SERVER['REQUEST_METHOD'] === 'POST') && ($_SERVER['HTTP_AUTHORIZATION'] ?? '') === 'Basic ' . base64_encode('bb-key:bb-secret')
        && ($_POST['grant_type'] ?? '') === 'client_credentials';
    $ok ? reply(200, ['access_token' => 'bb-token', 'token_type' => 'bearer', 'expires_in' => 3600]) : reply(401, ['error' => 'invalid_client', 'error_description' => 'Invalid client credentials']);
}
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer bb-token') {
    reply(401, ['status' => 401, 'message' => 'Bearer token is invalid']);
}
$cols = '/learn/api/public/v2/courses/_77_1/gradebook/columns';
switch (true) {
    case $path === '/learn/api/public/v1/system/version':
        reply(200, ['learn' => ['major' => 3900, 'minor' => 0, 'patch' => 0]]);
    case $path === '/learn/api/public/v3/courses/externalId:2026-1-SWE401':
        reply(200, ['id' => '_77_1', 'externalId' => '2026-1-SWE401']);
    case str_starts_with($path, '/learn/api/public/v3/courses/'):
        reply(404, ['status' => 404, 'message' => 'Course not found']);
    case $path === $cols && $offset === 0:
        reply(200, ['results' => [
            ['id' => '_1_1', 'name' => 'Midterm exam', 'score' => ['possible' => 50]],
            ['id' => '_2_1', 'name' => 'Total', 'externalGrade' => true, 'score' => ['possible' => 100]],
        ], 'paging' => ['nextPage' => $cols . '?offset=2&limit=200']]);
    case $path === $cols:
        reply(200, ['results' => [
            ['id' => '_3_1', 'name' => 'Quiz', 'score' => ['possible' => 20]],
            ['id' => '_4_1', 'name' => 'Participation', 'score' => ['possible' => 10]],
        ]]);
    case $path === "$cols/_1_1/users":
        reply(200, ['results' => [['userId' => '_501_1', 'score' => 40, 'status' => 'Graded'], ['userId' => '_502_1', 'score' => 25, 'status' => 'Graded'], ['userId' => '_503_1', 'status' => 'NeedsGrading']]]);
    case $path === "$cols/_3_1/users":
        reply(200, ['results' => [['userId' => '_501_1', 'score' => 18, 'status' => 'Graded'], ['userId' => '_502_1', 'score' => 10, 'status' => 'Graded']]]);
    case $path === "$cols/_4_1/users":
        reply(200, ['results' => [['userId' => '_501_1', 'score' => 10, 'status' => 'Graded']]]);
    default:
        reply(404, ['status' => 404, 'message' => 'Not found']);
}
