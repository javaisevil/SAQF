<?php

require_once '../includes/auth_check.php';
requireRole('hod');

$courseId = (int)($_GET['id'] ?? 0);
header('Location: course_review_clean.php?id=' . $courseId);
exit();
