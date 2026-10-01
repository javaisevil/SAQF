<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';

$user = \Saqf\Security\Auth::user();
if (!$user) {
    saqf_redirect('login.php');
}
$home = ['faculty' => 'faculty.php', 'hod' => 'department.php', 'qa' => 'quality.php', 'dean' => 'college.php', 'leadership' => 'institution.php', 'admin' => 'admin.php'];
saqf_redirect($home[$user['role']] ?? 'login.php');
