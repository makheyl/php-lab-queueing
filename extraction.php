<?php
session_start();
require 'config.php';
require 'queue_functions.php';

// There is only one extraction station in this clinic — no bay number to
// prompt for or display. Kept as a plain constant (not a UI-facing value)
// because queue.extraction_station / daily_statistics.station still exist
// as columns; if a second station is ever added, this is the one place to
// change.
const EXTRACTION_STATION = 1;

// ---------- local helpers ----------

function redirect_url($phlebotomist_name) {
    return $_SERVER['PHP_SELF'] . '?phlebotomist_name=' . urlencode($phlebotomist_name);
}

function elapsed_minutes($datetime) {
    if (!$datetime) return null;
    return (int) floor((time() - strtotime($datetime)) / 60);
}

function count_where($conn, $service_date, $where_sql, $extra_types = '', $extra_params = []) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM queue WHERE service_date = ? AND $where_sql");
    $stmt->bind_param('s' . $extra_types, $service_date, ...$extra_params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) $row['cnt'];
}

/**
 * Same shape/pattern as OPD's doctor.php updateDailyStatistics(), adapted to
 * staff_name/station and the lab's no_charge_count/for_payment_count/
 * no_show_count columns. Uses prepared statements throughout (queue_functions.php's
 * house style — see CLAUDE.md §9), unlike OPD's original interpolated version.
 */
function update_daily_statistics($conn, $date, $station, $staff_name, $action, $payment_required = null) {
    $stmt = $conn->prepare("SELECT id FROM daily_statistics WHERE date = ? AND station = ? AND staff_name = ?");
    $stmt->bind_param('sis', $date, $station, $staff_name);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $served_inc = $action === 'served' ? 1 : 0;
    $no_show_inc = $action === 'no_show' ? 1 : 0;
    $no_charge_inc = ($action === 'served' && $payment_required === false) ? 1 : 0;
    $for_payment_inc = ($action === 'served' && $payment_required === true) ? 1 : 0;

    if ($existing) {
        $stmt = $conn->prepare(
            "UPDATE daily_statistics SET
                patients_served = patients_served + ?,
                no_show_count = no_show_count + ?,
                no_charge_count = no_charge_count + ?,
                for_payment_count = for_payment_count + ?,
                total_patients = patients_served + ?,
                last_updated = NOW()
             WHERE id = ?"
        );
        $stmt->bind_param('iiiiii', $served_inc, $no_show_inc, $no_charge_inc, $for_payment_inc, $served_inc, $existing['id']);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO daily_statistics
                (date, station, staff_name, patients_served, no_show_count, no_charge_count, for_payment_count, total_patients)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('sisiiiii', $date, $station, $staff_name, $served_inc, $no_show_inc, $no_charge_inc, $for_payment_inc, $served_inc);
        $stmt->execute();
        $stmt->close();
    }
}

// ---------- phlebotomist identity: just a name, no bay number ----------

$phlebotomist_name = '';
if (isset($_GET['phlebotomist_name'])) {
    $phlebotomist_name = $_GET['phlebotomist_name'];
    $_SESSION['phlebotomist_name'] = $phlebotomist_name;
} elseif (isset($_POST['phlebotomist_name'])) {
    $phlebotomist_name = $_POST['phlebotomist_name'];
    $_SESSION['phlebotomist_name'] = $phlebotomist_name;
} elseif (isset($_SESSION['phlebotomist_name'])) {
    $phlebotomist_name = $_SESSION['phlebotomist_name'];
}

// ---------- POST handlers (POST-Redirect-GET per CLAUDE.md §4) ----------

if (isset($_POST['call_next'])) {
    $post_name = $_POST['phlebotomist_name'];
    $ticket = call_next_extraction($conn, EXTRACTION_STATION, $post_name);
    if ($ticket) {
        // Same notify.json shape as index.php's interview calls (type + station),
        // so display.php can pick the right sentence for each.
        file_put_contents('notify.json', json_encode([
            'queue_number' => $ticket['queue_number'],
            'type' => 'extraction',
            'timestamp' => time(),
        ]));
    }
    header('Location: ' . redirect_url($post_name));
    exit();
}

if (isset($_POST['complete'])) {
    $id = (int) $_POST['id'];
    $post_name = $_POST['phlebotomist_name'];

    $ticket = find_queue_row($conn, $id);
    $ok = complete_extraction($conn, $id, $post_name);
    if ($ok && $ticket) {
        $payment_required = $ticket['payment_required'] !== null ? (bool) $ticket['payment_required'] : null;
        update_daily_statistics($conn, $ticket['service_date'], EXTRACTION_STATION, $post_name, 'served', $payment_required);
    }
    header('Location: ' . redirect_url($post_name));
    exit();
}

if (isset($_POST['recall'])) {
    $id = (int) $_POST['id'];
    $post_name = $_POST['phlebotomist_name'];

    $ticket = find_queue_row($conn, $id);
    $ok = recall($conn, $id, $post_name);
    if ($ok && $ticket) {
        file_put_contents('notify.json', json_encode([
            'queue_number' => $ticket['queue_number'],
            'type' => 'extraction',
            'timestamp' => time(),
        ]));
    }
    header('Location: ' . redirect_url($post_name));
    exit();
}

if (isset($_POST['no_show'])) {
    $id = (int) $_POST['id'];
    $post_name = $_POST['phlebotomist_name'];

    $ticket = find_queue_row($conn, $id);
    $ok = mark_no_show($conn, $id, $post_name);
    if ($ok && $ticket) {
        update_daily_statistics($conn, $ticket['service_date'], EXTRACTION_STATION, $post_name, 'no_show');
    }
    header('Location: ' . redirect_url($post_name));
    exit();
}

// ---------- render ----------

$has_identity = $phlebotomist_name !== '';
$service_date = service_date_now($conn);

if ($has_identity) {
    $ready_for_extraction_count = count_where($conn, $service_date, "status = 'ready_for_extraction'");
    $now_extracting_count = count_where($conn, $service_date, "status = 'extracting'");
    $completed_count = count_where($conn, $service_date, "status = 'completed'");

    // NOT ordered by queue_number — a patient who left to pay rejoins at the back
    // based on when payment was confirmed. See CLAUDE.md §11.
    $extraction_queue = get_extraction_queue($conn, $service_date);

    $stmt = $conn->prepare(
        "SELECT * FROM queue WHERE service_date = ? AND status = 'extracting' LIMIT 1"
    );
    $stmt->bind_param('s', $service_date);
    $stmt->execute();
    $current_ticket = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT * FROM queue WHERE service_date = ? AND status = 'completed' ORDER BY extraction_completed_at DESC LIMIT 5"
    );
    $stmt->bind_param('s', $service_date);
    $stmt->execute();
    $recently_completed = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Laboratory Queueing — Extraction Station</title>
<link rel="stylesheet" href="assets/theme.css">
<style>
    .current-ticket-card { width: auto; min-width: 220px; padding: 28px 24px; }
    .current-ticket-card .queue-number { font-size: 3rem; }
    .kbd-legend { text-align: center; font-size: 0.78rem; color: var(--text-muted); margin-bottom: 14px; }
    .kbd-legend kbd {
        display: inline-block; min-width: 1.4em; padding: 1px 6px; margin: 0 2px;
        border: 1px solid var(--border); border-radius: 5px; background: var(--surface-alt);
        font-family: var(--font); font-weight: 700; color: var(--text);
    }
</style>
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
        setTimeout(pollQueueStatus, 1000);
    }
    pollQueueStatus();
</script>
</head>
<body>
    <div class="app-header">
        <img src="CHO.png" alt="CHO Logo" class="logo-img">
        <div class="title">Laboratory Queueing</div>
        <div class="subtitle">Extraction Station</div>
    </div>
    <div class="page page-wide">
        <form class="toolbar-form" method="get">
            <label for="phlebotomist_name">Your Name</label>
            <input class="field" type="text" id="phlebotomist_name" name="phlebotomist_name" value="<?= htmlspecialchars($phlebotomist_name) ?>" required>
            <button type="submit" class="btn">Set</button>
        </form>

        <?php if ($has_identity): ?>
        <div class="kbd-legend no-print">
            Keyboard: <kbd>N</kbd> Call Next &nbsp; <kbd>C</kbd> Complete &nbsp; <kbd>R</kbd> Recall &nbsp; <kbd>S</kbd> No Show
        </div>
        <div class="stat-cards">
            <div class="stat-card">
                <span class="stat-label">Ready for Extraction</span>
                <span class="stat-value"><?= $ready_for_extraction_count ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Now Extracting</span>
                <span class="stat-value"><?= $now_extracting_count ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Completed Today</span>
                <span class="stat-value"><?= $completed_count ?></span>
            </div>
        </div>

        <div class="main-columns">
            <div class="queue-section">
                <h2 class="section-title accent-green">Extraction Queue (<?= count($extraction_queue) ?>)</h2>
                <div class="queue-grid">
                    <?php if (empty($extraction_queue)): ?>
                    <div class="empty-note">No one waiting for extraction.</div>
                    <?php else: foreach (array_slice($extraction_queue, 0, 3) as $row): $mins = elapsed_minutes($row['extraction_eligible_at']); ?>
                    <div class="queue-card">
                        <span class="queue-number"><?= htmlspecialchars($row['queue_number']) ?></span>
                        <?php if (!empty($row['payment_required'])): ?>
                        <span class="badge badge-completed">PAID</span>
                        <?php else: ?>
                        <span class="badge badge-pending">NO CHARGE</span>
                        <?php endif; ?>
                        <div class="info"><?= $mins !== null ? $mins . ' min waiting' : '' ?></div>
                    </div>
                    <?php endforeach;
                    if (count($extraction_queue) > 3): ?>
                    <div class="empty-note">+<?= count($extraction_queue) - 3 ?> more waiting</div>
                    <?php endif; endif; ?>
                </div>
            </div>

            <div class="queue-section">
                <h2 class="section-title accent-green">Now Extracting</h2>
                <?php if ($current_ticket): $mins = elapsed_minutes($current_ticket['extraction_called_at']); ?>
                    <div class="queue-card current-ticket-card">
                        <span class="queue-number"><?= htmlspecialchars($current_ticket['queue_number']) ?></span>
                        <div class="info"><?= $mins !== null ? $mins . ' min' : '' ?></div>
                        <?php if ((int) $current_ticket['recall_count'] > 0): ?>
                        <div class="info">Recalled <?= (int) $current_ticket['recall_count'] ?>x</div>
                        <?php endif; ?>
                    </div>
                    <div class="card-actions" style="margin-top:14px;">
                        <form method="post" style="display:inline">
                            <input type="hidden" name="id" value="<?= $current_ticket['id'] ?>">
                            <input type="hidden" name="phlebotomist_name" value="<?= htmlspecialchars($phlebotomist_name) ?>">
                            <button type="submit" name="complete" class="btn">COMPLETE</button>
                        </form>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="id" value="<?= $current_ticket['id'] ?>">
                            <input type="hidden" name="phlebotomist_name" value="<?= htmlspecialchars($phlebotomist_name) ?>">
                            <button type="submit" name="recall" class="btn btn-info">RECALL</button>
                        </form>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="id" value="<?= $current_ticket['id'] ?>">
                            <input type="hidden" name="phlebotomist_name" value="<?= htmlspecialchars($phlebotomist_name) ?>">
                            <button type="submit" name="no_show" class="btn btn-neutral">NO SHOW</button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="empty-note">No one currently in extraction.</div>
                <?php endif; ?>

                <form method="post" id="callNextForm" style="margin-top:18px;">
                    <input type="hidden" name="phlebotomist_name" value="<?= htmlspecialchars($phlebotomist_name) ?>">
                    <button type="submit" name="call_next" class="btn" <?= $current_ticket ? 'disabled' : '' ?>>CALL NEXT</button>
                </form>
            </div>
        </div>

        <div class="queue-section" style="margin-top:22px;">
            <h2 class="section-title accent-green">Recently Completed</h2>
            <div class="queue-grid">
                <?php if (empty($recently_completed)): ?>
                <div class="empty-note">No completions yet today.</div>
                <?php else: foreach ($recently_completed as $row): ?>
                <div class="queue-card completed">
                    <span class="queue-number"><?= htmlspecialchars($row['queue_number']) ?></span>
                    <div class="info"><?= $row['extraction_completed_at'] ? date('g:i A', strtotime($row['extraction_completed_at'])) : '' ?></div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        const callNextForm = document.getElementById('callNextForm');
        if (callNextForm) {
            callNextForm.addEventListener('submit', function() {
                const btn = this.querySelector('button[type=submit]');
                if (btn) {
                    btn.blur();
                }
            });
        }

        // Keyboard shortcuts (see the legend above the stat cards), ignored
        // while typing in a field. A no-op if the matching button isn't
        // present/enabled — e.g. N does nothing once a ticket is already active.
        document.addEventListener('keydown', function(e) {
            if (e.altKey || e.ctrlKey || e.metaKey) return;
            const tag = (document.activeElement && document.activeElement.tagName || '').toLowerCase();
            if (tag === 'input' || tag === 'textarea' || tag === 'select') return;

            const shortcuts = {
                N: '#callNextForm button[type=submit]',
                C: 'button[name=complete]',
                R: 'button[name=recall]',
                S: 'button[name=no_show]',
            };
            const selector = shortcuts[e.key.toUpperCase()];
            if (!selector) return;
            const btn = document.querySelector(selector);
            if (btn && !btn.disabled) {
                e.preventDefault();
                btn.click();
            }
        });

        // Focus management: land on the most likely next action after every
        // reload, so shortcuts work immediately without a mouse click first.
        (function() {
            const completeBtn = document.querySelector('button[name=complete]');
            const target = completeBtn || (callNextForm ? callNextForm.querySelector('button[type=submit]') : null);
            if (target) target.focus();
        })();
    </script>
</body>
</html>
