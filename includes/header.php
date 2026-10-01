<?php
$role = $_SESSION['role'] ?? '';
$name = $_SESSION['name'] ?? $_SESSION['username'] ?? '';
$b = BASE_URL;
$current = $_SERVER['SCRIPT_NAME'];
function navActive($current, $needle) {
    return strpos($current, $needle) !== false ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AQMS — <?php echo htmlspecialchars($page_title ?? 'Dashboard'); ?></title>
    <link rel="stylesheet" href="<?php echo $b; ?>/assets/style.css">
    <link rel="stylesheet" href="<?php echo $b; ?>/assets/aqms_form_fix.css">
    <link rel="stylesheet" href="<?php echo $b; ?>/assets/course_template_overrides.css">
    <script defer src="<?php echo $b; ?>/assets/course_remaining_fixes.js"></script>
    <link rel="icon" type="image/png" href="<?php echo $b; ?>/assets/favicon.png">
</head>
<body>
<div class="layout">
    <aside class="sidebar" id="appSidebar">
        <div class="sidebar-brand">
            <img src="<?php echo $b; ?>/assets/yu-logo.png" alt="Al Yamamah University" onerror="this.style.display='none'; document.getElementById('brand-fallback').style.display='block';">
            <div id="brand-fallback" style="display:none;"><span class="brand-text">YU AQMS</span><span class="brand-sub">Al Yamamah University</span></div>
        </div>
        <nav class="sidebar-nav">
            <?php if ($role === 'faculty'): ?>
                <div class="nav-section">Faculty</div>
                <a href="<?php echo $b; ?>/faculty/dashboard.php" class="<?php echo navActive($current, 'faculty/dashboard'); ?>"><span class="label">My Courses</span></a>
                <a href="<?php echo $b; ?>/faculty/course_create.php" class="<?php echo navActive($current, 'course_create'); ?>"><span class="label">New Course Spec</span></a>
                <a href="<?php echo $b; ?>/mapping.php" class="<?php echo navActive($current, 'mapping.php'); ?>"><span class="label">CLO-PLO Mapping</span></a>
            <?php elseif ($role === 'hod'): ?>
                <div class="nav-section">Head of Department</div>
                <a href="<?php echo $b; ?>/hod/dashboard.php" class="<?php echo navActive($current, 'hod/dashboard'); ?>"><span class="label">Review Queue</span></a>
                <a href="<?php echo $b; ?>/hod/course_assign.php" class="<?php echo navActive($current, 'course_assign'); ?>"><span class="label">Assign Course Spec</span></a>
                <a href="<?php echo $b; ?>/hod/plos.php" class="<?php echo navActive($current, 'hod/plos'); ?>"><span class="label">Manage PLOs</span></a>
                <a href="<?php echo $b; ?>/hod/program_kpis.php" class="<?php echo navActive($current, 'program_kpis'); ?>"><span class="label">Program KPIs</span></a>
                <a href="<?php echo $b; ?>/program/edit.php" class="<?php echo navActive($current, 'program/edit'); ?>"><span class="label">Program Specification</span></a>
            <?php elseif ($role === 'dean'): ?>
                <div class="nav-section">Department Dean</div>
                <a href="<?php echo $b; ?>/dean/dashboard.php" class="<?php echo navActive($current, 'dean/dashboard'); ?>"><span class="label">Department Oversight</span></a>
                <a href="<?php echo $b; ?>/program/edit.php" class="<?php echo navActive($current, 'program/edit'); ?>"><span class="label">Program Specification</span></a>
            <?php elseif ($role === 'qa'): ?>
                <div class="nav-section">Quality Assurance</div>
                <a href="<?php echo $b; ?>/qa/dashboard.php" class="<?php echo navActive($current, 'qa/dashboard'); ?>"><span class="label">QA Queue</span></a>
                <a href="<?php echo $b; ?>/program/edit.php" class="<?php echo navActive($current, 'program/edit'); ?>"><span class="label">Program Specification</span></a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-footer">
            <div class="user-info"><span class="role-badge role-<?php echo $role; ?>"><?php echo strtoupper($role); ?></span><span><?php echo htmlspecialchars($name); ?></span></div>
            <a href="<?php echo $b; ?>/logout.php" class="logout-link">Sign Out</a>
        </div>
    </aside>
    <main class="main-content">
        <div class="topbar"><div style="display:flex; align-items:center; gap:12px;"><button type="button" class="sidebar-toggle no-print" id="sidebarToggle" aria-label="Toggle sidebar">☰</button><h1 class="page-title"><span class="topbar-accent"></span><?php echo htmlspecialchars($page_title ?? 'Dashboard'); ?></h1></div></div>
        <div class="content-body">