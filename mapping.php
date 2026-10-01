<?php
require_once __DIR__ . '/includes/auth_check.php';
requireRole('faculty');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/security.php';

$page_title = 'CLO-PLO Mapping';

$course_id = intval($_GET['course_id'] ?? 0);

if (!$course_id) {
    $stmt = $pdo->prepare('SELECT course_id FROM course_specs WHERE faculty_id = ? ORDER BY updated_at DESC LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    $course_id = $row['course_id'] ?? 0;
}

if (!$course_id) {
    header('Location: ' . BASE_URL . '/faculty/dashboard.php');
    exit();
}

$course = $pdo->prepare('SELECT cs.*, ps.program_id FROM course_specs cs LEFT JOIN program_specs ps ON cs.program_id = ps.program_id WHERE cs.course_id = ? AND cs.faculty_id = ?');
$course->execute([$course_id, $_SESSION['user_id']]);
$course = $course->fetch();

if (!$course) {
    header('Location: ' . BASE_URL . '/faculty/dashboard.php');
    exit();
}

$editable = in_array($course['status'], ['draft', 'returned_by_hod', 'returned_by_qa'], true);

$clo_stmt = $pdo->prepare('SELECT * FROM course_learning_outcomes WHERE course_id = ? ORDER BY clo_code');
$clo_stmt->execute([$course_id]);
$clos = $clo_stmt->fetchAll();

$plo_stmt = $pdo->prepare('SELECT * FROM program_learning_outcomes WHERE program_id = ? ORDER BY plo_code');
$plo_stmt->execute([$course['program_id']]);
$plos = $plo_stmt->fetchAll();

$clo_ids = array_column($clos, 'clo_id');
$existing = [];
if ($clo_ids) {
    $in  = implode(',', array_fill(0, count($clo_ids), '?'));
    $map = $pdo->prepare("SELECT * FROM clo_plo_mapping WHERE clo_id IN ($in)");
    $map->execute($clo_ids);
    foreach ($map->fetchAll() as $row) {
        $existing[$row['clo_id']][$row['plo_id']] = true;
    }
}

$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$editable) {
    $error = 'This course is read-only while it is under review or approved.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $editable && !aqmsVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
    $error = 'The form expired. Refresh the page and try again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $editable && aqmsVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
    try {
        $submitted = $_POST['mapping'] ?? [];
        if (!is_array($submitted)) {
            throw new InvalidArgumentException('The submitted mapping is invalid.');
        }

        $allowedClos = array_fill_keys(array_map('intval', $clo_ids), true);
        $allowedPlos = array_fill_keys(array_map('intval', array_column($plos, 'plo_id')), true);
        foreach ($submitted as $cloId => $ploList) {
            if (!isset($allowedClos[(int)$cloId]) || !is_array($ploList)) {
                throw new InvalidArgumentException('The submitted mapping contains an outcome outside this course.');
            }
            foreach ($ploList as $ploId) {
                if (!isset($allowedPlos[(int)$ploId])) {
                    throw new InvalidArgumentException('The submitted mapping contains an outcome outside this program.');
                }
            }
        }

        $pdo->beginTransaction();

        if ($clo_ids) {
            $in  = implode(',', array_fill(0, count($clo_ids), '?'));
            $pdo->prepare("DELETE FROM clo_plo_mapping WHERE clo_id IN ($in)")->execute($clo_ids);
        }

        $ins = $pdo->prepare('INSERT IGNORE INTO clo_plo_mapping (clo_id, plo_id) VALUES (?, ?)');
        foreach ($submitted as $clo_id => $plo_list) {
            foreach ($plo_list as $plo_id) {
                $ins->execute([intval($clo_id), intval($plo_id)]);
            }
        }

        $pdo->commit();
        $msg = 'Mapping saved.';

        if ($clo_ids) {
            $map2 = $pdo->prepare("SELECT * FROM clo_plo_mapping WHERE clo_id IN ($in)");
            $map2->execute($clo_ids);
            $existing = [];
            foreach ($map2->fetchAll() as $row) {
                $existing[$row['clo_id']][$row['plo_id']] = true;
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Error saving mapping.';
    }
}

include __DIR__ . '/includes/header.php';
?>

<?php if ($msg): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2>CLO–PLO Mapping: <?php echo htmlspecialchars($course['course_code'] . ' — ' . $course['course_title']); ?></h2>
    </div>

    <?php if (empty($clos)): ?>
        <div class="alert alert-warning">No CLOs found. <a href="faculty/course_edit.php?id=<?php echo $course_id; ?>&step=2">Add CLOs first.</a></div>
    <?php elseif (empty($plos)): ?>
        <div class="alert alert-warning">No PLOs found for this program. Ask the HoD to add PLOs.</div>
    <?php else: ?>

    <?php if (!$editable): ?>
        <div class="alert alert-info">This course is read-only while it is under review or approved.</div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(aqmsCsrfToken()); ?>">
        <div style="overflow-x:auto;">
        <table class="matrix-table">
            <thead>
                <tr>
                    <th>CLO</th>
                    <th>Description</th>
                    <th>Category</th>
                    <?php foreach ($plos as $plo): ?>
                        <th title="<?php echo htmlspecialchars($plo['description']); ?>">
                            <?php echo htmlspecialchars($plo['plo_code']); ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($clos as $clo): ?>
                <tr>
                    <td><?php echo htmlspecialchars($clo['clo_code']); ?></td>
                    <td><?php echo htmlspecialchars($clo['description']); ?></td>
                    <td><?php echo htmlspecialchars($clo['category']); ?></td>
                    <?php foreach ($plos as $plo): ?>
                        <td>
                            <input type="checkbox"
                                   name="mapping[<?php echo $clo['clo_id']; ?>][]"
                                   value="<?php echo $plo['plo_id']; ?>"
                                   <?php echo isset($existing[$clo['clo_id']][$plo['plo_id']]) ? 'checked' : ''; ?>
                                   <?php echo !$editable ? 'disabled' : ''; ?>>
                        </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div style="margin-top:16px; display:flex; gap:10px;">
            <?php if ($editable): ?><button type="submit" class="btn btn-primary">Save Mapping</button><?php endif; ?>
            <a href="<?php echo BASE_URL; ?>/faculty/dashboard.php" class="btn btn-outline">Back to Dashboard</a>
        </div>
    </form>

    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
