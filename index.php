<?php
session_start();
session_unset();
require 'config.php';

// Add to queue (operator)
if (isset($_POST['add'])) {
    $queue_number = (int)$_POST['queue_number'];
    if ($queue_number <= 0) {
        $error_message = 'Queue number must be a positive integer!';
    } else {
        $priority = isset($_POST['priority']) ? $_POST['priority'] : 'no';
        // Check for duplicate queue number (waiting or serving)
        $dup_check = $conn->query("SELECT id FROM queue WHERE queue_number = $queue_number AND status IN ('waiting', 'serving', 'pending') LIMIT 1");
        if ($dup_check && $dup_check->num_rows > 0) {
            $error_message = 'Queue number already exists!';
        } else {
            $conn->query("INSERT INTO queue (queue_number, priority, status, created_at) VALUES ($queue_number, '$priority', 'waiting', NOW())");
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }
}

// Doctor picks a number to serve (this section can be implemented on a separate doctor page if needed)
if (isset($_POST['serve'])) {
    $id = (int)$_POST['serve_id'];
    $doctor_name = $conn->real_escape_string($_POST['doctor_name']);
    $table_number = (int)$_POST['table_number'];
    $conn->query("UPDATE queue SET status='serving', doctor_name='$doctor_name', table_number=$table_number WHERE id=$id");
}

// Mark as served
if (isset($_POST['done'])) {
    $id = (int)$_POST['done_id'];
    $conn->query("UPDATE queue SET status='served', served_at=NOW() WHERE id=$id");
}

// Confirm pending patient arrival
if (isset($_POST['confirm_pending'])) {
    $id = (int)$_POST['confirm_id'];
    // Fetch doctor_name and table_number from the pending patient
    $result = $conn->query("SELECT doctor_name, table_number FROM queue WHERE id=$id LIMIT 1");
    $doctor_name = '';
    $table_number = '';
    if ($result && $row = $result->fetch_assoc()) {
        $doctor_name = $conn->real_escape_string($row['doctor_name']);
        $table_number = $conn->real_escape_string($row['table_number']);
    }
    // Set confirmed_at to track first confirmation time for fairness
    $conn->query("UPDATE queue SET status='waiting', priority='completed', doctor_name='$doctor_name', table_number='$table_number', confirmed_at=IF(confirmed_at IS NULL, NOW(), confirmed_at) WHERE id=$id");
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Get waiting numbers (priority first)
$waiting = $conn->query("SELECT * FROM queue WHERE status='waiting' ORDER BY (priority='yes') DESC, (priority='completed') DESC, created_at ASC");
// Get serving numbers
$serving = $conn->query("SELECT * FROM queue WHERE status='serving' ORDER BY created_at ASC");

// Get pending numbers
$pending = $conn->query("SELECT * FROM queue WHERE status='pending' ORDER BY queue_number ASC");
$cancelled = $conn->query("SELECT * FROM queue WHERE status='cancelled' ORDER BY queue_number ASC");
// Get came back numbers
$came_back = $conn->query("SELECT * FROM queue WHERE status='waiting' AND priority='came_back' ORDER BY created_at ASC");

if (isset($_POST['requeue_cancelled'])) {
    $id = (int)$_POST['confirm_cancel_id'];
    $conn->query("UPDATE queue SET status='waiting', priority='came_back' WHERE id=$id");
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}
if (isset($_POST['remove_cancelled'])) {
    $id = (int)$_POST['confirm_cancel_id'];
    $conn->query("DELETE FROM queue WHERE id=$id");
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Add PHP handler for updating priority
if (isset($_POST['update_priority_id']) && isset($_POST['update_priority_value'])) {
    $id = (int)$_POST['update_priority_id'];
    $new_priority = $_POST['update_priority_value'];
    if (in_array($new_priority, ['no', 'yes', 'infectious', 'follow_up'])) {
        $conn->query("UPDATE queue SET priority='" . $conn->real_escape_string($new_priority) . "' WHERE id=$id");
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}
// Add PHP handler for updating queue number
if (isset($_POST['update_queue_id']) && isset($_POST['update_queue_number'])) {
    $id = (int)$_POST['update_queue_id'];
    $new_number = (int)$_POST['update_queue_number'];
    $error = '';
    if ($new_number > 0) {
        $dup_check = $conn->query("SELECT id FROM queue WHERE queue_number = $new_number AND id != $id LIMIT 1");
        if ($dup_check && $dup_check->num_rows > 0) {
            $error = 'duplicate';
        } else {
            $conn->query("UPDATE queue SET queue_number=$new_number WHERE id=$id");
        }
    } else {
        $error = 'invalid';
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $error]);
        exit();
    } else {
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}
// Add PHP handler for updating table number
if (isset($_POST['update_table_id']) && isset($_POST['update_table_number'])) {
    $id = (int)$_POST['update_table_id'];
    $new_table = (int)$_POST['update_table_number'];
    $error = '';
    if ($new_table >= 0) {
        $conn->query("UPDATE queue SET table_number=$new_table WHERE id=$id");
    } else {
        $error = 'invalid';
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $error]);
        exit();
    } else {
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Add PHP handler for removing from waiting queue
if (isset($_POST['remove_waiting'])) {
    $id = (int)$_POST['remove_waiting_id'];
    $conn->query("DELETE FROM queue WHERE id=$id");
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>OPD Queueing — Operator</title>
<link rel="stylesheet" href="assets/theme.css">
<script>
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
        setTimeout(pollQueueStatus, 3000);
    }
    pollQueueStatus();
</script>
</head>
<body>
    <div class="app-header">
        <img src="CHO.png" alt="CHO Logo" class="logo-img">
        <div class="title">OPD Queueing</div>
        <div class="subtitle">Operator Console</div>
    </div>
    <div class="page">
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>

        <div class="stat-cards">
            <div class="stat-card">
                <span class="stat-label">Total Waiting</span>
                <span class="stat-value"><?php $result = $conn->query("SELECT COUNT(*) as cnt FROM queue WHERE status='waiting'"); $row = $result->fetch_assoc(); echo $row['cnt']; ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Currently Serving</span>
                <span class="stat-value"><?php $result = $conn->query("SELECT COUNT(*) as cnt FROM queue WHERE status='serving'"); $row = $result->fetch_assoc(); echo $row['cnt']; ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Served Today</span>
                <span class="stat-value"><?php $result = $conn->query("SELECT COUNT(*) as cnt FROM queue WHERE status='served' AND DATE(served_at) = CURDATE()"); $row = $result->fetch_assoc(); echo $row['cnt']; ?></span>
            </div>
        </div>

        <form method="post" class="toolbar-form">
            <label for="queue_number">Queue Number</label>
            <input class="field" type="number" id="queue_number" name="queue_number" required>
            <label for="priority">Priority</label>
            <select class="field" id="priority" name="priority">
                <option value="no">No</option>
                <option value="yes">Yes (Priority)</option>
                <option value="infectious">Infectious</option>
                <option value="follow_up">Follow-up</option>
            </select>
            <button type="submit" name="add" class="btn">Add to Queue</button>
        </form>

        <div class="main-columns">
            <div class="queue-section waiting-col">
                <h2 class="section-title accent-green">Waiting Queue</h2>
                <button id="viewWaitingListBtn" class="btn btn-outline">View Full List</button>

                <!-- Modal for Waiting List -->
                <div id="waitingListModal" class="modal-overlay">
                  <div class="modal-box">
                    <button id="closeWaitingListModal" class="modal-close" aria-label="Close">&times;</button>
                    <h2 class="section-title accent-green" style="margin-bottom:16px;">Waiting Queue List</h2>
                    <table class="data-table">
                        <tr><th>#</th><th>Queue Number</th><th>Priority</th><th>Table Number</th><th>Action</th></tr>
                        <?php $i=1; $waiting->data_seek(0); while($row = $waiting->fetch_assoc()): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td>
                                <form method="post" class="queue-number-update-form inline-field" data-row-id="<?= $row['id'] ?>">
                                    <input type="hidden" name="update_queue_id" value="<?= $row['id'] ?>">
                                    <input class="field" type="number" name="update_queue_number" value="<?= htmlspecialchars($row['queue_number']) ?>" min="1">
                                    <span class="queue-number-update-success update-ok">✔</span>
                                    <span class="queue-number-update-error update-err">✖</span>
                                </form>
                                <span class="queue-priority-badge" data-row-id="<?= $row['id'] ?>">
                                <?php if ($row['priority'] === 'yes'): ?>
                                    <span class="badge badge-priority">PRIORITY</span>
                                <?php elseif ($row['priority'] === 'infectious'): ?>
                                    <span class="badge badge-infectious">INFECTIOUS</span>
                                <?php elseif ($row['priority'] === 'completed'): ?>
                                    <span class="badge badge-completed">C</span>
                                    <?php if (!empty($row['table_number'])): ?>
                                    <span class="info"><span class="table-label">TB</span> <?= htmlspecialchars($row['table_number']) ?></span>
                                    <?php endif; ?>
                                <?php elseif ($row['priority'] === 'came_back'): ?>
                                    <span class="badge badge-cameback">CAME BACK</span>
                                <?php endif; ?>
                                </span>
                                <?php if ($row['priority'] !== 'completed' && !empty($row['table_number'])): ?>
                                    <span class="info"><span class="table-label">Table</span> <?= htmlspecialchars($row['table_number']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" class="priority-update-form" data-row-id="<?= $row['id'] ?>">
                                    <input type="hidden" name="update_priority_id" value="<?= $row['id'] ?>">
                                    <select name="update_priority_value" class="field">
                                        <option value="no" <?= $row['priority'] === 'no' ? 'selected' : '' ?>>No</option>
                                        <option value="yes" <?= $row['priority'] === 'yes' ? 'selected' : '' ?>>Priority</option>
                                        <option value="infectious" <?= $row['priority'] === 'infectious' ? 'selected' : '' ?>>Infectious</option>
                                        <option value="follow_up" <?= $row['priority'] === 'follow_up' ? 'selected' : '' ?>>Follow-up</option>
                                    </select>
                                    <span class="priority-update-success update-ok">✔</span>
                                </form>
                            </td>
                            <td>
                                <form method="post" class="table-number-update-form inline-field" data-row-id="<?= $row['id'] ?>">
                                    <input type="hidden" name="update_table_id" value="<?= $row['id'] ?>">
                                    <input class="field" type="number" name="update_table_number" value="<?= htmlspecialchars($row['table_number']) ?>" min="0">
                                    <span class="table-number-update-success update-ok">✔</span>
                                    <span class="table-number-update-error update-err">✖</span>
                                </form>
                            </td>
                            <td>
                                <form method="post">
                                    <input type="hidden" name="remove_waiting_id" value="<?= $row['id'] ?>">
                                    <button type="submit" name="remove_waiting" class="btn btn-danger btn-sm">Remove</button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </table>
                  </div>
                </div>
                <!-- End Modal -->
            </div>

            <div class="queue-section serving-col">
                <h2 class="section-title accent-green">Currently Serving</h2>
                <table class="data-table">
                    <tr><th>Queue Number</th><th>Priority</th><th>Doctor</th><th>Table</th><th>Action</th></tr>
                    <?php $serving->data_seek(0); while($row = $serving->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['queue_number']) ?></td>
                        <td><?= htmlspecialchars(ucfirst($row['priority'])) ?></td>
                        <td><?= htmlspecialchars($row['doctor_name']) ?></td>
                        <td><?= htmlspecialchars($row['table_number']) ?></td>
                        <td>
                            <form method="post">
                                <input type="hidden" name="done_id" value="<?= $row['id'] ?>">
                                <button type="submit" name="done" class="btn btn-sm">Mark as Served</button>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </table>
            </div>

            <div class="queue-section pending-col">
                <h2 class="section-title accent-orange">Pending Confirmation</h2>
                <div class="queue-grid">
                    <?php if ($pending && $pending->num_rows > 0): while($row = $pending->fetch_assoc()): ?>
                    <div class="queue-card pending<?php if ($row['priority'] === 'yes') echo ' priority'; if ($row['priority'] === 'infectious') echo ' infectious'; if ($row['priority'] === 'completed') echo ' completed'; ?>">
                        <span class="badge badge-pending">PENDING</span>
                        <span class="queue-number"><?= htmlspecialchars($row['queue_number']) ?></span>
                        <?php if (!empty($row['doctor_name']) || !empty($row['table_number'])): ?>
                        <div class="info">
                            <?= htmlspecialchars($row['doctor_name']) ?><?= ($row['doctor_name'] && $row['table_number']) ? ' - ' : '' ?><?= htmlspecialchars($row['table_number']) ?>
                        </div>
                        <?php endif; ?>
                        <form method="post" class="card-form">
                            <input type="hidden" name="confirm_id" value="<?= $row['id'] ?>">
                            <button type="submit" name="confirm_pending" class="btn btn-warning btn-sm">Confirm Arrival</button>
                        </form>
                    </div>
                    <?php endwhile; else: ?>
                    <div class="empty-note">No pending patients.</div>
                    <?php endif; ?>
                    <?php if ($cancelled && $cancelled->num_rows > 0): while($row = $cancelled->fetch_assoc()): ?>
                    <div class="queue-card cancelled<?php if ($row['priority'] === 'yes') echo ' priority'; if ($row['priority'] === 'infectious') echo ' infectious'; if ($row['priority'] === 'completed') echo ' completed'; ?>">
                        <span class="badge badge-cancelled">CANCELLED</span>
                        <span class="queue-number"><?= htmlspecialchars($row['queue_number']) ?></span>
                        <?php if (!empty($row['doctor_name']) || !empty($row['table_number'])): ?>
                        <div class="info">
                            <?= htmlspecialchars($row['doctor_name']) ?><?= ($row['doctor_name'] && $row['table_number']) ? ' - ' : '' ?><?= htmlspecialchars($row['table_number']) ?>
                        </div>
                        <?php endif; ?>
                        <form method="post" class="card-form" style="gap:8px;">
                            <input type="hidden" name="confirm_cancel_id" value="<?= $row['id'] ?>">
                            <button type="submit" name="requeue_cancelled" class="btn btn-sm">Requeue</button>
                            <button type="submit" name="remove_cancelled" class="btn btn-danger btn-sm">Remove Number</button>
                        </form>
                    </div>
                    <?php endwhile; endif; ?>
                </div>
            </div>
        </div>

        <div class="queue-section" style="margin-top:22px;">
            <h2 class="section-title accent-pink">Came Back Patients</h2>
            <div class="queue-grid">
                <?php if ($came_back && $came_back->num_rows > 0): while($row = $came_back->fetch_assoc()): ?>
                <div class="queue-card cameback<?php if ($row['priority'] === 'yes') echo ' priority'; if ($row['priority'] === 'infectious') echo ' infectious'; if ($row['priority'] === 'completed') echo ' completed'; ?>">
                    <span class="queue-number"><?= htmlspecialchars($row['queue_number']) ?></span>
                    <span class="badge badge-cameback">CAME BACK</span>
                    <?php if (!empty($row['doctor_name']) || !empty($row['table_number'])): ?>
                    <div class="info">
                        <?= htmlspecialchars($row['doctor_name']) ?><?= ($row['doctor_name'] && $row['table_number']) ? ' - ' : '' ?><?= htmlspecialchars($row['table_number']) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endwhile; else: ?>
                <div class="empty-note">No came back patients.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Modal open/close logic for Waiting List
        const viewBtn = document.getElementById('viewWaitingListBtn');
        const modal = document.getElementById('waitingListModal');
        const closeBtn = document.getElementById('closeWaitingListModal');
        if (viewBtn && modal && closeBtn) {
            viewBtn.onclick = () => { modal.style.display = 'flex'; };
            closeBtn.onclick = () => { modal.style.display = 'none'; };
            window.onclick = function(event) {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            };
        }
    </script>
    <script>
    document.querySelectorAll('.priority-update-form select').forEach(function(select) {
        select.addEventListener('change', function(e) {
            var form = select.closest('form');
            var formData = new FormData(form);
            fetch('', {
                method: 'POST',
                body: formData
            }).then(function(response) {
                if (response.ok) {
                    var newPriority = form.querySelector('select').value;
                    var badgeSpan = document.querySelector('.queue-priority-badge[data-row-id="' + form.dataset.rowId + '"]');
                    if (badgeSpan) {
                        let badgeHtml = '';
                        if (newPriority === 'yes') {
                            badgeHtml = '<span class="badge badge-priority">PRIORITY</span>';
                        } else if (newPriority === 'infectious') {
                            badgeHtml = '<span class="badge badge-infectious">INFECTIOUS</span>';
                        } else {
                            badgeHtml = '';
                        }
                        badgeSpan.innerHTML = badgeHtml;
                    }
                    var success = form.querySelector('.priority-update-success');
                    if (success) {
                        success.style.display = 'inline';
                        setTimeout(function() { success.style.display = 'none'; }, 1200);
                    }
                }
            });
        });
    });
    </script>
    <script>
    document.querySelectorAll('.queue-number-update-form input[name="update_queue_number"]').forEach(function(input) {
        input.addEventListener('change', function(e) {
            var form = input.closest('form');
            var formData = new FormData(form);
            fetch('', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function(response) { return response.json(); }).then(function(data) {
                var success = form.querySelector('.queue-number-update-success');
                var error = form.querySelector('.queue-number-update-error');
                if (data && data.error === '') {
                    if (success) {
                        success.style.display = 'inline';
                        setTimeout(function() { success.style.display = 'none'; }, 1200);
                    }
                    if (error) error.style.display = 'none';
                } else {
                    if (error) {
                        error.style.display = 'inline';
                        setTimeout(function() { error.style.display = 'none'; }, 2000);
                    }
                    if (success) success.style.display = 'none';
                }
            });
        });
    });
    </script>
    <script>
    document.querySelectorAll('.table-number-update-form input[name="update_table_number"]').forEach(function(input) {
        input.addEventListener('change', function(e) {
            var form = input.closest('form');
            var formData = new FormData(form);
            fetch('', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function(response) { return response.json(); }).then(function(data) {
                var success = form.querySelector('.table-number-update-success');
                var error = form.querySelector('.table-number-update-error');
                if (data && data.error === '') {
                    if (success) {
                        success.style.display = 'inline';
                        setTimeout(function() { success.style.display = 'none'; }, 1200);
                    }
                    if (error) error.style.display = 'none';
                } else {
                    if (error) {
                        error.style.display = 'inline';
                        setTimeout(function() { error.style.display = 'none'; }, 2000);
                    }
                    if (success) success.style.display = 'none';
                }
            });
        });
    });
    </script>
</body>
</html>
