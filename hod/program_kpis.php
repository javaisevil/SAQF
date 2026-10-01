<?php
require_once '../includes/auth_check.php';
requireRole('hod');
require_once '../db.php';
require_once '../includes/security.php';

$page_title = 'Program KPIs (Section G.6)';

$programs = $pdo->query('SELECT * FROM program_specs ORDER BY program_name')->fetchAll();
$selected_program = intval($_GET['program_id'] ?? ($programs[0]['program_id'] ?? 0));

$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !aqmsVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
    $error = 'The form expired. Refresh the page and try again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && aqmsVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
    $action = $_POST['action'] ?? '';
    $prog_id = intval($_POST['program_id'] ?? 0);
    $code    = trim($_POST['kpi_code'] ?? '');
    $text    = trim($_POST['kpi_text'] ?? '');
    $target  = trim($_POST['target_level'] ?? '');
    $method  = trim($_POST['measurement_method'] ?? '');
    $time    = trim($_POST['measurement_time'] ?? '');
    $years   = intval($_POST['years_to_achieve'] ?? 1);
    $kpi_id = intval($_POST['kpi_id'] ?? 0); 

    if ($action === 'add' || $action === 'edit') {
        $check_sql = "SELECT COUNT(*) FROM program_kpis WHERE kpi_code = ? AND program_id = ?";
        $params = [$code, $prog_id];

        if ($action === 'edit') {
            $check_sql .= " AND id != ?";
            $params[] = $kpi_id;
        }
        
        $stmt_check = $pdo->prepare($check_sql);
        $stmt_check->execute($params);

        if (!$code) {
            $error = 'KPI code is required.';
        } elseif (!$text) {
            $error = 'KPI description is required.';
        } elseif ($stmt_check->fetchColumn() > 0) {
            $error = "The KPI code '$code' is already in use for this program.";
        }  else {
            if ($action === 'add') {
                $pdo->prepare('INSERT INTO program_kpis (program_id, kpi_code, kpi_text, target_level, measurement_method, measurement_time, years_to_achieve)
                VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$prog_id, $code, $text, $target, $method, $time, $years]);
                $msg = 'KPI added successfully.';
            } else {
                $pdo->prepare('UPDATE program_kpis SET kpi_code=?, kpi_text=?, target_level=?, measurement_method=?, measurement_time=?, years_to_achieve=?
                WHERE id=? AND program_id=?'
                )->execute([$code, $text, $target, $method, $time, $years, $kpi_id, $prog_id]);
                $msg = 'KPI updated successfully.';
            }
            
            $selected_program = $prog_id;
        }
    } elseif ($action === 'delete') {
        $kpi_id = intval($_POST['kpi_id']);
        $pdo->prepare('DELETE FROM program_kpis WHERE id = ? AND program_id = ?')->execute([$kpi_id, $prog_id]);
        $msg = 'KPI removed.';
        $selected_program = intval($_POST['program_id']);
    }
}

$kpis = [];
if ($selected_program) {
    $stmt = $pdo->prepare('SELECT * FROM program_kpis WHERE program_id = ? ORDER BY kpi_code');
    $stmt->execute([$selected_program]);
    $kpis = $stmt->fetchAll();
}

include '../includes/header.php';
?>

<?php if ($msg): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2>Program KPIs — NCAAA Section G.6</h2>
        <form method="GET" style="display:flex; gap:8px; align-items:center;">
            <select name="program_id" onchange="this.form.submit()" style="width:auto;">
                <?php foreach ($programs as $p): ?>
                    <option value="<?php echo $p['program_id']; ?>"
                        <?php echo $p['program_id'] == $selected_program ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($p['program_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div style="overflow-x:auto;">
    <table>
        <thead>
            <tr>
                <th style="width:40px;">#</th>
                <th style="width:90px;">Code</th>
                <th>KPI Description</th>
                <th>Target</th>
                <th>Method</th>
                <th>Timing</th>
                <th style="width:60px;">Years</th>
                <th style="width:60px;"></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($kpis)): ?>
                <tr><td colspan="8" class="empty-state">No KPIs added yet for this program.</td></tr>
            <?php else: ?>
                <?php foreach ($kpis as $i => $kpi): ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td><strong><?php echo htmlspecialchars($kpi['kpi_code']); ?></strong></td>
                    <td><?php echo htmlspecialchars($kpi['kpi_text']); ?></td>
                    <td><?php echo htmlspecialchars($kpi['target_level']); ?></td>
                    <td><?php echo htmlspecialchars($kpi['measurement_method']); ?></td>
                    <td><?php echo htmlspecialchars($kpi['measurement_time']); ?></td>
                    <td><?php echo htmlspecialchars($kpi['years_to_achieve']); ?></td>
                    <td> 
                        <div style="display:flex; gap:5px;">
                            <button type="button" class="btn btn-sm btn-edit"
                                onclick='openEditModal(<?php echo htmlspecialchars(json_encode($kpi)); ?>)'>
                                Edit
                            </button>

                        <form method="POST" onsubmit="return confirm('Remove this KPI?')" style="margin:0;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(aqmsCsrfToken()); ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="kpi_id" value="<?php echo $kpi['id']; ?>">
                            <input type="hidden" name="program_id" value="<?php echo $selected_program; ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Add a KPI</h2></div>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(aqmsCsrfToken()); ?>">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="program_id" value="<?php echo $selected_program; ?>">
        <div class="form-row">
            <div class="form-group" style="max-width:140px;">
                <label>KPI Code</label>
                <input type="text" name="kpi_code" required placeholder="KPI-01" value="<?php echo htmlspecialchars($_POST['kpi_code'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>KPI Description </label>
                <input type="text" name="kpi_text" required placeholder="e.g. Graduate employment rate within 6 months" value="<?php echo htmlspecialchars($_POST['kpi_text'] ?? ''); ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Target Level</label>
                <input type="text" name="target_level" placeholder="e.g. 80%" value="<?php echo htmlspecialchars($_POST['target_level'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Measurement Method</label>
                <input type="text" name="measurement_method" placeholder="e.g. Alumni survey" value="<?php echo htmlspecialchars($_POST['measurement_method'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Measurement Time</label>
                <input type="text" name="measurement_time" placeholder="e.g. End of academic year" value="<?php echo htmlspecialchars($_POST['measurement_time'] ?? ''); ?>">
            </div>
            <div class="form-group" style="max-width:120px;">
                <label>Years</label>
                <input type="number" name="years_to_achieve" value="<?php echo htmlspecialchars($_POST['years_to_achieve'] ?? 1); ?>" min="1" max="10">
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Add KPI</button>
    </form>
</div>

<div id="editKpiModal" class="modal">
    <div class="modal-content" style="width: 600px; max-width: 90%;">
        <div class="card-header modal-header">
            <h2>Edit KPI</h2>
            <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(aqmsCsrfToken()); ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="kpi_id" id="edit_kpi_id">
            <input type="hidden" name="program_id" value="<?php echo $selected_program; ?>">

            <div class="form-row">
                <div class="form-group" style="max-width:140px;">
                    <label>KPI Code</label>
                    <input type="text" name="kpi_code" id="edit_kpi_code" required>
                </div>
            <div class="form-group">
                    <label>KPI Description</label>
                    <input type="text" name="kpi_text" id="edit_kpi_text" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Target Level</label>
                    <input type="text" name="target_level" id="edit_target_level">
                </div>
                <div class="form-group">
                    <label>Measurement Method</label>
                    <input type="text" name="measurement_method" id="edit_measurement_method">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Measurement Time</label>
                    <input type="text" name="measurement_time" id="edit_measurement_time">
                </div>
                <div class="form-group" style="max-width: 120px;">
                    <label>Years</label>
                    <input type="number" name="years_to_achieve" id="edit_years_to_achieve" min="1" max="10">
                </div>
            </div>

            <div style="display:flex; gap:10px; margin-top:10px;">
                <button type="submit" class="btn btn-primary btn-full">Save changes</button>
                <button type="button" onclick="closeModal()" class="btn btn-full btn-secondary">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openEditModal(kpi) {
        document.getElementById('edit_kpi_id').value = kpi.id;

        if (document.getElementById('edit_kpi_code')) {
            document.getElementById('edit_kpi_code').value = kpi.kpi_code;
        }

        document.getElementById('edit_kpi_text').value = kpi.kpi_text;
        document.getElementById('edit_target_level').value = kpi.target_level;
        document.getElementById('edit_measurement_method').value = kpi.measurement_method;
        document.getElementById('edit_measurement_time').value = kpi.measurement_time;
        document.getElementById('edit_years_to_achieve').value = kpi.years_to_achieve;

        document.getElementById('editKpiModal').classList.add('modal-open');


    }
    
    function closeModal() {
        document.getElementById('editKpiModal').classList.remove('modal-open');
    }

    window.onclick = function (event) {
        let modal = document.getElementById('editKpiModal');
        if (event.target == modal) {
            closeModal();
        }
    }

</script>

<?php include '../includes/footer.php'; ?>
