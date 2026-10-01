<?php
// Stand-in for a Moodle site's web services (php -S router) used by tests/production_test.php.
header('Content-Type: application/json');
$p = $_POST + $_GET;
if (($p['wstoken'] ?? '') !== 'moodle-test-token') {
    echo json_encode(['exception' => 'moodle_exception', 'errorcode' => 'invalidtoken', 'message' => 'Invalid token - token not found']);
    exit;
}
switch ($p['wsfunction'] ?? '') {
    case 'core_webservice_get_site_info':
        echo json_encode(['sitename' => 'YU Moodle (test)', 'release' => '4.3', 'functions' => [
            ['name' => 'core_webservice_get_site_info'], ['name' => 'core_course_get_courses_by_field'], ['name' => 'gradereport_user_get_grade_items']]]);
        break;
    case 'core_course_get_courses_by_field':
        $hit = ($p['field'] ?? '') === 'idnumber' && ($p['value'] ?? '') === '2026-1-SWE401';
        echo json_encode(['courses' => $hit ? [['id' => 501, 'idnumber' => '2026-1-SWE401']] : [], 'warnings' => []]);
        break;
    case 'gradereport_user_get_grade_items':
        if ((int) ($p['courseid'] ?? 0) !== 501) {
            echo json_encode(['exception' => 'moodle_exception', 'message' => 'Course not found']);
            break;
        }
        // userid => [midterm raw /20, quiz raw /10, attendance %]
        $students = [101 => [18, 9, 80], 102 => [12, 6, 55], 103 => [20, 10, 90], 104 => [15, 7.5, 72], 105 => [16, 8, 64]];
        $out = [];
        foreach ($students as $uid => [$mid, $quiz, $att]) {
            $out[] = ['userid' => $uid, 'gradeitems' => [
                ['itemname' => 'Midterm exam', 'itemtype' => 'mod', 'graderaw' => $mid, 'grademin' => 0, 'grademax' => 20, 'gradeishidden' => false],
                ['itemname' => 'Quiz', 'itemtype' => 'mod', 'graderaw' => $quiz, 'grademin' => 0, 'grademax' => 10],
                ['itemname' => 'Attendance', 'itemtype' => 'manual', 'graderaw' => $att, 'grademin' => 0, 'grademax' => 100],
                ['itemname' => 'Final exam', 'itemtype' => 'mod', 'graderaw' => null, 'grademin' => 0, 'grademax' => 100],
                ['itemname' => 'Hidden bonus', 'itemtype' => 'manual', 'graderaw' => 5, 'grademin' => 0, 'grademax' => 5, 'gradeishidden' => true],
                ['itemname' => null, 'itemtype' => 'course', 'graderaw' => 50, 'grademin' => 0, 'grademax' => 100],
            ]];
        }
        echo json_encode(['usergrades' => $out, 'warnings' => []]);
        break;
    default:
        echo json_encode(['exception' => 'moodle_exception', 'errorcode' => 'nofunction', 'message' => 'Unknown function']);
}
