<?php
require 'config.php';
session_start();

// Function to update daily statistics
function updateDailyStatistics($conn, $doctor_name, $table_number, $action) {
    $today = date('Y-m-d');
    $doctor_name_esc = $conn->real_escape_string($doctor_name);
    $table_number_esc = (int)$table_number;

    // Check if record exists for today
    $check_sql = "SELECT id FROM daily_statistics WHERE date = '$today' AND table_number = $table_number_esc AND doctor_name = '$doctor_name_esc'";
    $result = $conn->query($check_sql);

    if ($result && $result->num_rows > 0) {
        // Update existing record
        $served_inc = ($action === 'served' ? 1 : 0);
        $pending_inc = ($action === 'pending' ? 1 : 0);
        $cancelled_inc = ($action === 'cancelled' ? 1 : 0);
        // Set total_patients equal to patients_served (count unique patients as served only)
        $update_sql = "UPDATE daily_statistics SET
            patients_served = patients_served + $served_inc,
            patients_pending = patients_pending + $pending_inc,
            patients_cancelled = patients_cancelled + $cancelled_inc,
            total_patients = patients_served + $served_inc,
            last_updated = NOW()
            WHERE date = '$today' AND table_number = $table_number_esc AND doctor_name = '$doctor_name_esc'";
        $conn->query($update_sql);
    } else {
        // Insert new record (total_patients equals number served)
        $served_val = ($action === 'served' ? 1 : 0);
        $pending_val = ($action === 'pending' ? 1 : 0);
        $cancelled_val = ($action === 'cancelled' ? 1 : 0);
        $insert_sql = "INSERT INTO daily_statistics (date, table_number, doctor_name, patients_served, patients_pending, patients_cancelled, total_patients)
            VALUES ('$today', $table_number_esc, '$doctor_name_esc', $served_val, $pending_val, $cancelled_val, $served_val)";
        $conn->query($insert_sql);
    }
}

// Function to get daily statistics for a specific table/doctor
function getDailyStatistics($conn, $doctor_name = null, $table_number = null) {
    $today = date('Y-m-d');
    $sql = "SELECT * FROM daily_statistics WHERE date = '$today'";

    if ($doctor_name) {
        $doctor_name_esc = $conn->real_escape_string($doctor_name);
        $sql .= " AND doctor_name = '$doctor_name_esc'";
    }

    if ($table_number) {
        $table_number_esc = (int)$table_number;
        $sql .= " AND table_number = $table_number_esc";
    }

    $sql .= " ORDER BY table_number ASC, doctor_name ASC";

    return $conn->query($sql);
}

// Doctor picks a number to serve
if (isset($_POST['serve'])) {
    $id = (int)$_POST['serve_id'];
    $doctor_name = $conn->real_escape_string($_POST['doctor_name']);
    $table_number = (int)$_POST['table_number'];
    $conn->query("UPDATE queue SET status='serving', doctor_name='$doctor_name', table_number=$table_number WHERE id=$id");
    // Fetch queue_number and table_number for notification
    $result = $conn->query("SELECT queue_number, table_number FROM queue WHERE id=$id LIMIT 1");
    if ($result && $row = $result->fetch_assoc()) {
        $queue_number = $row['queue_number'];
        $table_number_notify = $row['table_number'];
        file_put_contents('notify.json', json_encode(['queue_number' => $queue_number, 'table_number' => $table_number_notify, 'timestamp' => time()]));
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "?doctor_name=" . urlencode($doctor_name) . "&table_number=" . urlencode($table_number));
    exit();
}

// Mark as served
if (isset($_POST['done'])) {
    $id = (int)$_POST['done_id'];
    $doctor_name = $conn->real_escape_string($_POST['doctor_name']);
    $table_number = isset($_POST['table_number']) ? (int)$_POST['table_number'] : '';
    $conn->query("UPDATE queue SET status='served', served_at=NOW() WHERE id=$id");
    // After updating queue status to 'served'
    $queue_number = 0;
    $result = $conn->query("SELECT queue_number FROM queue WHERE id=$id");
    if ($result && $row = $result->fetch_assoc()) {
        $queue_number = $row['queue_number'];
    }
    // Log to doctor_appointments_log
    $conn->query("INSERT INTO doctor_appointments_log (doctor_name, table_number, patient_queue_number, action) VALUES ('$doctor_name', $table_number, $queue_number, 'served')");
    // Update daily statistics
    updateDailyStatistics($conn, $doctor_name, $table_number, 'served');
    header("Location: " . $_SERVER['PHP_SELF'] . "?doctor_name=" . urlencode($doctor_name) . "&table_number=" . urlencode($table_number));
    exit();
}

// Mark as pending
if (isset($_POST['pending'])) {
    $id = (int)$_POST['pending_id'];
    $doctor_name = $conn->real_escape_string($_POST['doctor_name']);
    $table_number = isset($_POST['table_number']) ? $conn->real_escape_string($_POST['table_number']) : '';
    $sql = "UPDATE queue SET status='pending', doctor_name='$doctor_name', table_number='$table_number' WHERE id=$id";
    if (!$conn->query($sql)) {
        error_log('SQL Error: ' . $conn->error);
        echo '<div style=\'color:red;\'>SQL Error: ' . $conn->error . '</div>';
    }
    // Log to doctor_appointments_log
    $queue_number = 0;
    $result = $conn->query("SELECT queue_number FROM queue WHERE id=$id");
    if ($result && $row = $result->fetch_assoc()) {
        $queue_number = $row['queue_number'];
    }
    $conn->query("INSERT INTO doctor_appointments_log (doctor_name, table_number, patient_queue_number, action) VALUES ('$doctor_name', $table_number, $queue_number, 'pending')");
    // Update daily statistics
    updateDailyStatistics($conn, $doctor_name, $table_number, 'pending');
    header("Location: " . $_SERVER['PHP_SELF'] . "?doctor_name=" . urlencode($doctor_name) . "&table_number=" . urlencode($table_number));
    exit();
}

// Add handler for cancel action
if (isset($_POST['cancel'])) {
    $id = (int)$_POST['cancel_id'];
    $doctor_name = $conn->real_escape_string($_POST['doctor_name']);
    $table_number = isset($_POST['table_number']) ? (int)$_POST['table_number'] : '';
    $conn->query("UPDATE queue SET status='cancelled' WHERE id=$id");
    // Log to doctor_appointments_log
    $queue_number = 0;
    $result = $conn->query("SELECT queue_number FROM queue WHERE id=$id");
    if ($result && $row = $result->fetch_assoc()) {
        $queue_number = $row['queue_number'];
    }
    $conn->query("INSERT INTO doctor_appointments_log (doctor_name, table_number, patient_queue_number, action) VALUES ('$doctor_name', $table_number, $queue_number, 'cancelled')");
    // Update daily statistics
    updateDailyStatistics($conn, $doctor_name, $table_number, 'cancelled');
    header("Location: " . $_SERVER['PHP_SELF'] . "?doctor_name=" . urlencode($doctor_name) . "&table_number=" . urlencode($table_number));
    exit();
}

// Transfer a currently serving patient to another table (back to waiting)
if (isset($_POST['transfer'])) {
    $id = (int)$_POST['transfer_id'];
    $doctor_name = $conn->real_escape_string($_POST['doctor_name']);
    $new_table_number = isset($_POST['new_table_number']) ? (int)$_POST['new_table_number'] : 0;
    if ($new_table_number > 0) {
        // Move the patient back to waiting for the new table and clear doctor assignment
        $conn->query("UPDATE queue SET status='waiting', doctor_name='', table_number={$new_table_number} WHERE id={$id}");
        // Fetch queue number for logging
        $queue_number = 0;
        $result = $conn->query("SELECT queue_number FROM queue WHERE id={$id}");
        if ($result && $row = $result->fetch_assoc()) {
            $queue_number = $row['queue_number'];
        }
        // Log transfer with destination table number
        $conn->query("INSERT INTO doctor_appointments_log (doctor_name, table_number, patient_queue_number, action) VALUES ('{$doctor_name}', {$new_table_number}, {$queue_number}, 'transferred')");
    }
    // Preserve current doctor's view
    $table_number_param = isset($_POST['table_number']) ? $_POST['table_number'] : '';
    header("Location: " . $_SERVER['PHP_SELF'] . "?doctor_name=" . urlencode($doctor_name) . "&table_number=" . urlencode($table_number_param));
    exit();
}

// Add handler for notify action
if (isset($_POST['notify'])) {
    $id = (int)$_POST['notify_id'];
    $queue_number = '';
    $table_number = '';
    $result = $conn->query("SELECT queue_number, table_number FROM queue WHERE id=$id LIMIT 1");
    if ($result && $row = $result->fetch_assoc()) {
        $queue_number = $row['queue_number'];
        $table_number = $row['table_number'];
    }
    file_put_contents('notify.json', json_encode(['queue_number' => $queue_number, 'table_number' => $table_number, 'timestamp' => time()]));
    // Log to doctor_appointments_log
    $doctor_name = isset($_POST['doctor_name']) ? $_POST['doctor_name'] : '';
    if ($doctor_name && $table_number) {
        $conn->query("INSERT INTO doctor_appointments_log (doctor_name, table_number, patient_queue_number, action) VALUES ('$doctor_name', $table_number, $queue_number, 'notified')");
    }
    // Redirect to avoid resubmission on refresh
    $doctor_name = isset($_POST['doctor_name']) ? $_POST['doctor_name'] : '';
    $table_number_param = isset($_POST['table_number']) ? $_POST['table_number'] : '';
    header("Location: " . $_SERVER['PHP_SELF'] . "?doctor_name=" . urlencode($doctor_name) . "&table_number=" . urlencode($table_number_param));
    exit();
}

// Get doctor name and table number from GET or POST, or fallback to session
$doctor_name = '';
$table_number = '';
if (isset($_GET['doctor_name'])) {
    $doctor_name = $_GET['doctor_name'];
    $table_number = isset($_GET['table_number']) ? $_GET['table_number'] : '';
    $_SESSION['doctor_name'] = $doctor_name;
    $_SESSION['table_number'] = $table_number;
} elseif (isset($_POST['doctor_name'])) {
    $doctor_name = $_POST['doctor_name'];
    $table_number = isset($_POST['table_number']) ? $_POST['table_number'] : '';
    $_SESSION['doctor_name'] = $doctor_name;
    $_SESSION['table_number'] = $table_number;
} else {
    if (isset($_SESSION['doctor_name'])) {
        $doctor_name = $_SESSION['doctor_name'];
    }
    if (isset($_SESSION['table_number'])) {
        $table_number = $_SESSION['table_number'];
    }
}

// Always re-query after any POST action and before rendering
$doctor_name_esc = $conn->real_escape_string($doctor_name);
$table_number_esc = $conn->real_escape_string($table_number);

// Get waiting numbers (priority first) - exclude came back patients
if (!empty($doctor_name) && !empty($table_number)) {
    $waiting = $conn->query("SELECT * FROM queue WHERE status='waiting' AND priority != 'came_back' AND (table_number='' OR table_number IS NULL OR table_number='$table_number_esc')
        ORDER BY
            (priority='yes') DESC,
            (priority='completed') DESC,
            CASE WHEN priority='completed' AND confirmed_at IS NOT NULL THEN confirmed_at ELSE created_at END ASC,
            id ASC");
} else {
    $waiting = $conn->query("SELECT * FROM queue WHERE status='waiting' AND priority != 'came_back' AND (table_number='' OR table_number IS NULL)
        ORDER BY
            (priority='yes') DESC,
            (priority='completed') DESC,
            CASE WHEN priority='completed' AND confirmed_at IS NOT NULL THEN confirmed_at ELSE created_at END ASC,
            id ASC");
}

// Get serving numbers for this doctor
$serving = $conn->query("SELECT * FROM queue WHERE status='serving' AND doctor_name='" . $doctor_name_esc . "' ORDER BY created_at ASC");

// Get pending numbers for this doctor/table only
$pending = $conn->query("SELECT * FROM queue WHERE status='pending' AND table_number='$table_number_esc' ORDER BY queue_number ASC");

// Get came back numbers for this doctor/table only
$came_back = $conn->query("SELECT * FROM queue WHERE status='waiting' AND priority='came_back' AND table_number='$table_number_esc' ORDER BY created_at ASC");

// After any POST action, re-query the $came_back result set before rendering
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Re-query came_back after any update
    $came_back = $conn->query("SELECT * FROM queue WHERE status='waiting' AND priority='came_back' AND table_number='$table_number_esc' ORDER BY created_at ASC");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>OPD Queueing — Doctor Panel</title>
<link rel="stylesheet" href="assets/theme.css">
<style>
    .queue-card.doctor-card { width: 190px; min-height: 150px; cursor: pointer; }
    .transfer-box { display: none; gap: 6px; align-items: center; justify-content: center; margin-top: 4px; flex-wrap: wrap; width: 100%; }
    .transfer-box input { width: 130px; }
</style>
<script>
    // Track click counts for each card
    const clickCounts = {};
    function showForm(id) {
        const form = document.getElementById('form-' + id);
        if (form.style.display === 'flex') {
            form.style.display = 'none';
        } else {
            document.querySelectorAll('.queue-form').forEach(f => f.style.display = 'none');
            form.style.display = 'flex';
        }
    }
    function hideForm(id) {
        document.getElementById('form-' + id).style.display = 'none';
    }

    // Toggle transfer input visibility for a serving card
    function toggleTransfer(id) {
        const box = document.getElementById('transfer-box-' + id);
        if (!box) return;
        if (box.style.display === 'flex') {
            box.style.display = 'none';
        } else {
            box.style.display = 'flex';
            const input = box.querySelector('input[name="new_table_number"]');
            if (input) input.focus();
        }
    }

    // --- Auto-refresh on DB update ---
    let lastStatus = null;
    async function pollQueueStatus() {
        try {
            const res = await fetch('queue_status.php');
            if (!res.ok) return;
            const data = await res.json();
            if (lastStatus === null) {
                lastStatus = JSON.stringify(data);
            } else if (JSON.stringify(data) !== lastStatus) {
                location.reload();
            }
        } catch (e) {}
        setTimeout(pollQueueStatus, 1000);
    }
    pollQueueStatus();

    let lastNotified = 0;
    function pollNotify() {
        fetch('notify.json?rand=' + Math.random())
            .then(res => res.json())
            .then(data => {
                console.log('Fetched notify.json:', data, 'lastNotified:', lastNotified);
                if (data && data.timestamp && data.timestamp > lastNotified) {
                    console.log('Triggering voice notification!');
                    lastNotified = data.timestamp;
                    const msg = `Queue number ${data.queue_number}, queue number ${data.queue_number}, please proceed to table ${data.table_number}`;
                    if ('speechSynthesis' in window) {
                        window.speechSynthesis.cancel();
                        const utter = new SpeechSynthesisUtterance(msg);
                        utter.lang = 'en-US';
                        window.speechSynthesis.speak(utter);
                    } else {
                        console.log('speechSynthesis not supported in this browser.');
                    }
                    fetch('clear_notify.php?rand=' + Math.random());
                }
            })
            .catch((err) => { console.log('Error fetching or parsing notify.json:', err); });
        setTimeout(pollNotify, 50);
    }
    document.addEventListener('DOMContentLoaded', pollNotify);
</script>
</head>
<body>
    <div class="app-header">
        <img src="CHO.png" alt="CHO Logo" class="logo-img">
        <div class="title">OPD Queueing</div>
        <div class="subtitle">Doctor Panel</div>
    </div>
    <div class="page page-wide">
        <form class="toolbar-form" method="get">
            <label for="doctor_name">Your Name</label>
            <input class="field" type="text" id="doctor_name" name="doctor_name" value="<?= htmlspecialchars($doctor_name) ?>" required>
            <label for="table_number">Table Number</label>
            <input class="field" type="number" id="table_number" name="table_number" value="<?= htmlspecialchars($table_number) ?>" required>
            <button type="submit" class="btn">Set</button>
        </form>

        <?php if ($doctor_name && $table_number): ?>
        <?php
        // Get daily statistics for all tables
        $today = date('Y-m-d');
        $daily_stats = getDailyStatistics($conn);
        $table_served_counts = [];

        if ($daily_stats) {
            while ($row = $daily_stats->fetch_assoc()) {
                $table_served_counts[$row['table_number']] = $row['patients_served'];
            }
        }

        // Get current doctor's statistics
        $current_doctor_stats = getDailyStatistics($conn, $doctor_name, $table_number);
        $current_served = 0;
        $current_pending = 0;
        $current_cancelled = 0;

        if ($current_doctor_stats && $current_doctor_stats->num_rows > 0) {
            $stats = $current_doctor_stats->fetch_assoc();
            $current_served = $stats['patients_served'];
            $current_pending = $stats['patients_pending'];
            $current_cancelled = $stats['patients_cancelled'];
        }

        function renderTableBadges($table_served_counts) {
            $out = '';
            foreach ($table_served_counts as $tnum => $cnt) {
                $out .= '<span class="badge badge-completed" style="background:#e3f2fd;color:#1976d2;">T'.htmlspecialchars($tnum).': '.$cnt.'</span>';
            }
            return $out;
        }
        ?>
        <div class="stat-cards">
            <div class="stat-card">
                <span class="stat-label">Waiting</span>
                <span class="stat-value"><?php $result = $conn->query("SELECT COUNT(*) as cnt FROM queue WHERE status='waiting'"); $row = $result->fetch_assoc(); echo $row['cnt']; ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Serving (You)</span>
                <span class="stat-value"><?php $result = $conn->query("SELECT COUNT(*) as cnt FROM queue WHERE status='serving' AND doctor_name='".$conn->real_escape_string($doctor_name)."'"); $row = $result->fetch_assoc(); echo $row['cnt']; ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Served Today</span>
                <span class="stat-value"><?= $current_served ?></span>
                <div class="stat-extra"><?php echo renderTableBadges($table_served_counts); ?></div>
            </div>
            <div class="stat-card">
                <span class="stat-label">Cancelled Today</span>
                <span class="stat-value"><?= $current_cancelled ?></span>
            </div>
        </div>

        <div class="main-columns">
        <div class="queue-section">
            <h3 class="section-title accent-green">Waiting Queue</h3>
            <div class="queue-grid">
                <?php
                $waiting->data_seek(0);
                $shown_priority = false;
                $shown_infectious = false;
                $shown_completed = false;
                $shown_next = false;
                while($row = $waiting->fetch_assoc()):
                    if ($row['priority'] === 'yes') {
                        if ($shown_priority) continue;
                        $shown_priority = true;
                    } elseif ($row['priority'] === 'infectious') {
                        if ($shown_infectious) continue;
                        $shown_infectious = true;
                    } elseif ($row['priority'] === 'completed') {
                        if ($shown_completed) continue;
                        $shown_completed = true;
                    } elseif (!$shown_next) {
                        // Show the next normal waiting number
                        $shown_next = true;
                    } else {
                        // Hide other normal waiting numbers
                        continue;
                    }
                ?>
                <div class="queue-card doctor-card clickable<?php if ($row['priority'] === 'yes' || $row['priority'] === 'infectious') echo ' priority'; if ($row['priority'] === 'completed') echo ' completed'; if ($row['priority'] === 'came_back') echo ' cameback'; ?>" onclick="showForm(<?= $row['id'] ?>)">
                    <span class="queue-number"><?= htmlspecialchars($row['queue_number']) ?></span>
                    <?php if ($row['priority'] === 'yes'): ?>
                        <span class="badge badge-priority">PRIORITY</span>
                    <?php elseif ($row['priority'] === 'infectious'): ?>
                        <span class="badge badge-infectious">INFECTIOUS</span>
                    <?php elseif ($row['priority'] === 'completed'): ?>
                        <span class="badge badge-completed">COMPLETED</span>
                        <?php if (!empty($row['doctor_name']) || !empty($row['table_number'])): ?>
                        <div class="info">
                            <?= htmlspecialchars($row['doctor_name']) ?><?= ($row['doctor_name'] && $row['table_number']) ? ' - ' : '' ?><?= htmlspecialchars($row['table_number']) ?>
                        </div>
                        <?php endif; ?>
                    <?php elseif ($row['priority'] === 'came_back'): ?>
                        <span class="badge badge-cameback">CAME BACK</span>
                    <?php endif; ?>
                    <?php if ($row['priority'] !== 'completed' && (!empty($row['doctor_name']) || !empty($row['table_number']))): ?>
                    <div class="info">
                        <?= htmlspecialchars($row['doctor_name']) ?><?= ($row['doctor_name'] && $row['table_number']) ? ' - ' : '' ?><?= htmlspecialchars($row['table_number']) ?>
                    </div>
                    <?php endif; ?>
                    <form class="queue-form card-form" id="form-<?= $row['id'] ?>" method="post" style="display:none;" onsubmit="hideForm(<?= $row['id'] ?>)">
                        <input type="hidden" name="serve_id" value="<?= $row['id'] ?>">
                        <input type="hidden" name="doctor_name" value="<?= htmlspecialchars($doctor_name) ?>">
                        <input type="hidden" name="table_number" value="<?= htmlspecialchars($table_number) ?>">
                        <button type="submit" name="serve" class="btn">Serve</button>
                    </form>
                </div>
                <?php endwhile; ?>
            </div>
        </div>
        <div class="queue-section">
            <h3 class="section-title accent-green">Currently Serving (You)</h3>
            <div class="queue-grid">
                <?php $serving->data_seek(0); while($row = $serving->fetch_assoc()): ?>
                <div class="queue-card doctor-card<?php if ($row['priority'] === 'yes' || $row['priority'] === 'infectious') echo ' priority'; if ($row['priority'] === 'completed') echo ' completed'; ?>">
                    <form method="post" class="notify-btn-topright" style="margin:0;">
                        <input type="hidden" name="notify_id" value="<?= $row['id'] ?>">
                        <input type="hidden" name="doctor_name" value="<?= htmlspecialchars($doctor_name) ?>">
                        <input type="hidden" name="table_number" value="<?= htmlspecialchars($table_number) ?>">
                        <button type="submit" name="notify" class="btn btn-info btn-xs">Notify</button>
                    </form>
                    <span class="queue-number"><?= htmlspecialchars($row['queue_number']) ?></span>
                    <?php if ($row['priority'] === 'yes'): ?>
                        <span class="badge badge-priority">PRIORITY</span>
                    <?php elseif ($row['priority'] === 'infectious'): ?>
                        <span class="badge badge-infectious">INFECTIOUS</span>
                    <?php elseif ($row['priority'] === 'completed'): ?>
                        <span class="badge badge-completed">COMPLETED</span>
                    <?php endif; ?>
                    <?php if (!empty($row['doctor_name']) || !empty($row['table_number'])): ?>
                    <div class="info">
                        <?= htmlspecialchars($row['doctor_name']) ?><?= ($row['doctor_name'] && $row['table_number']) ? ' - ' : '' ?><?= htmlspecialchars($row['table_number']) ?>
                    </div>
                    <?php endif; ?>
                    <div class="card-actions">
                        <form method="post" style="display:inline">
                            <input type="hidden" name="done_id" value="<?= $row['id'] ?>">
                            <input type="hidden" name="doctor_name" value="<?= htmlspecialchars($doctor_name) ?>">
                            <input type="hidden" name="table_number" value="<?= htmlspecialchars($table_number) ?>">
                            <button type="submit" name="done" class="btn btn-xs">Served</button>
                        </form>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="pending_id" value="<?= $row['id'] ?>">
                            <input type="hidden" name="doctor_name" value="<?= htmlspecialchars($doctor_name) ?>">
                            <input type="hidden" name="table_number" value="<?= htmlspecialchars($table_number) ?>">
                            <button type="submit" name="pending" class="btn btn-warning btn-xs">Pending</button>
                        </form>
                        <button type="button" onclick="toggleTransfer(<?= $row['id'] ?>)" class="btn btn-info btn-xs">Transfer</button>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="cancel_id" value="<?= $row['id'] ?>">
                            <input type="hidden" name="doctor_name" value="<?= htmlspecialchars($doctor_name) ?>">
                            <input type="hidden" name="table_number" value="<?= htmlspecialchars($table_number) ?>">
                            <button type="submit" name="cancel" class="btn btn-danger btn-xs">Cancel</button>
                        </form>
                    </div>
                    <form method="post" id="transfer-box-<?= $row['id'] ?>" class="transfer-box">
                        <input type="hidden" name="transfer_id" value="<?= $row['id'] ?>">
                        <input type="hidden" name="doctor_name" value="<?= htmlspecialchars($doctor_name) ?>">
                        <input type="hidden" name="table_number" value="<?= htmlspecialchars($table_number) ?>">
                        <input class="field" type="number" name="new_table_number" min="1" placeholder="Table #">
                        <button type="submit" name="transfer" class="btn btn-info btn-xs">Confirm Transfer</button>
                    </form>
                </div>
                <?php endwhile; ?>
            </div>
        </div>
        <div class="queue-section">
            <h3 class="section-title accent-orange">Pending Patients</h3>
            <div class="queue-grid">
                <?php while($row = $pending->fetch_assoc()): ?>
                <div class="queue-card doctor-card pending">
                    <span class="badge badge-pending">YET TO BE CONFIRMED</span>
                    <span class="queue-number"><?= htmlspecialchars($row['queue_number']) ?></span>
                    <?php if (!empty($row['doctor_name']) || !empty($row['table_number'])): ?>
                    <div class="info">
                        <?= htmlspecialchars($row['doctor_name']) ?><?= ($row['doctor_name'] && $row['table_number']) ? ' - ' : '' ?><?= htmlspecialchars($row['table_number']) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endwhile; ?>
            </div>
        </div>
        <div class="queue-section">
            <h3 class="section-title accent-pink">Came Back Patients</h3>
            <div class="queue-grid">
                <?php if ($came_back && $came_back->num_rows > 0): while($row = $came_back->fetch_assoc()): ?>
                <div class="queue-card doctor-card clickable cameback" onclick="showForm(<?= $row['id'] ?>)">
                    <span class="queue-number"><?= htmlspecialchars($row['queue_number']) ?></span>
                    <span class="badge badge-cameback">CAME BACK</span>
                    <?php if (!empty($row['doctor_name']) || !empty($row['table_number'])): ?>
                    <div class="info">
                        <?= htmlspecialchars($row['doctor_name']) ?><?= ($row['doctor_name'] && $row['table_number']) ? ' - ' : '' ?><?= htmlspecialchars($row['table_number']) ?>
                    </div>
                    <?php endif; ?>
                    <form class="queue-form card-form" id="form-<?= $row['id'] ?>" method="post" style="display:none;" onsubmit="hideForm(<?= $row['id'] ?>)">
                        <input type="hidden" name="serve_id" value="<?= $row['id'] ?>">
                        <input type="hidden" name="doctor_name" value="<?= htmlspecialchars($doctor_name) ?>">
                        <input type="hidden" name="table_number" value="<?= htmlspecialchars($table_number) ?>">
                        <button type="submit" name="serve" class="btn">Serve</button>
                    </form>
                </div>
                <?php endwhile; else: ?>
                <div class="empty-note">No came back patients.</div>
                <?php endif; ?>
            </div>
        </div>
        </div> <!-- end .main-columns -->
        <?php endif; ?>
    </div>
</body>
</html>
