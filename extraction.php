<?php
session_start();
require 'config.php';
require 'queue_functions.php';
require 'app_header.php';

// There is only one extraction station in this clinic — no bay number to
// prompt for or display. Kept as a plain constant (not a UI-facing value)
// because queue.extraction_station / daily_statistics.station still exist
// as columns; if a second station is ever added, this is the one place to
// change.
const EXTRACTION_STATION = 1;

// Numbers shown in "Next in line".
const NEXT_IN_LINE_LIMIT = 8;

// ---------- local helpers ----------

function redirect_url($phlebotomist_name) {
    return $_SERVER['PHP_SELF'] . '?phlebotomist_name=' . urlencode($phlebotomist_name);
}

function elapsed_minutes($datetime) {
    if (!$datetime) return null;
    return (int) floor((time() - strtotime($datetime)) / 60);
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
    $phlebotomist_name = trim($_GET['phlebotomist_name']);
    $_SESSION['phlebotomist_name'] = $phlebotomist_name;
} elseif (isset($_POST['phlebotomist_name'])) {
    $phlebotomist_name = trim($_POST['phlebotomist_name']);
    $_SESSION['phlebotomist_name'] = $phlebotomist_name;
} elseif (isset($_SESSION['phlebotomist_name'])) {
    $phlebotomist_name = $_SESSION['phlebotomist_name'];
}

// ---------- POST handlers (POST-Redirect-GET per CLAUDE.md §4) ----------

if (isset($_POST['call_next'])) {
    $post_name = $_POST['phlebotomist_name'];
    $ticket = call_next_extraction($conn, EXTRACTION_STATION, $post_name);
    if ($ticket) {
        // display.php picks the "proceed to extraction" sentence from the type.
        announce_call($ticket['queue_number'], 'extraction');
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
        announce_call($ticket['queue_number'], 'extraction');
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

$show_name_card = $phlebotomist_name === '' || isset($_GET['change']);
$service_date = service_date_now($conn);

if ($show_name_card) {
    $recent_names = get_recent_staff_names($conn);
} else {
    // NOT ordered by queue_number — a patient who left to pay rejoins at the back
    // based on when payment was confirmed. See get_extraction_queue().
    $extraction_queue = get_extraction_queue($conn, $service_date);
    $recall_limit = (int) get_setting($conn, 'recall_limit', 3);

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
<title>Laboratory Queueing — Extraction</title>
<link rel="stylesheet" href="assets/theme.css?v=<?= filemtime(__DIR__ . '/assets/theme.css') ?>">
<style>
    .pay-tag { display: inline-block; font-size: 0.8rem; font-weight: 700; padding: 3px 12px; border-radius: var(--radius-pill); background: var(--surface); border: 1px solid var(--border); color: var(--text-muted); }
    .completed-line { font-size: 1.05rem; font-weight: 700; color: var(--text); letter-spacing: 0.02em; }
</style>
</head>
<body>
    <?php render_app_header('extraction', $show_name_card ? '' : $phlebotomist_name, 'extraction.php?change=1'); ?>
    <div class="page">
        <?php if ($show_name_card): ?>
        <div class="name-card">
            <h2>Who's at extraction?</h2>
            <p>Type or pick your name. It's saved with every patient you serve.</p>
            <form method="get">
                <input class="field" type="text" name="phlebotomist_name" list="recentNames" value="<?= htmlspecialchars($phlebotomist_name) ?>" placeholder="Your name" autocomplete="off" required autofocus>
                <button type="submit" class="btn">Start</button>
            </form>
            <datalist id="recentNames">
                <?php foreach ($recent_names as $name): ?>
                <option value="<?= htmlspecialchars($name) ?>">
                <?php endforeach; ?>
            </datalist>
        </div>
        <?php else: ?>

        <div class="work-area">
            <section class="hero-card" aria-label="Now extracting">
                <div class="hero-label">Now Extracting</div>
                <?php if ($current_ticket): $called_mins = elapsed_minutes($current_ticket['extraction_called_at']); $recalls = (int) $current_ticket['recall_count']; ?>
                    <div class="hero-number"><?= htmlspecialchars($current_ticket['queue_number']) ?></div>
                    <div class="hero-meta">
                        Called <?= $called_mins !== null && $called_mins > 0 ? $called_mins . ' min ago' : 'just now' ?><?= $recalls > 0 ? ' · called again ' . $recalls . '×' : '' ?>
                    </div>
                    <span class="pay-tag"><?= !empty($current_ticket['payment_required']) ? 'PAID' : 'NO CHARGE' ?></span>

                    <div class="hero-actions">
                        <form method="post">
                            <input type="hidden" name="id" value="<?= (int) $current_ticket['id'] ?>">
                            <input type="hidden" name="phlebotomist_name" value="<?= htmlspecialchars($phlebotomist_name) ?>">
                            <button type="submit" name="complete" class="btn btn-big" data-key="C"><span>COMPLETE<kbd>C</kbd></span><small>Extraction done</small></button>
                        </form>
                    </div>

                    <div class="hero-more">
                        <form method="post">
                            <input type="hidden" name="id" value="<?= (int) $current_ticket['id'] ?>">
                            <input type="hidden" name="phlebotomist_name" value="<?= htmlspecialchars($phlebotomist_name) ?>">
                            <button type="submit" name="recall" class="btn btn-outline btn-sm" data-key="R" <?= $recalls >= $recall_limit ? 'disabled title="Called ' . $recalls . ' times already. Mark as No Show if they are not here."' : '' ?>>Call again<kbd>R</kbd></button>
                        </form>
                        <button type="button" class="btn btn-outline btn-sm" data-key="S" data-open-dialog="noShowDialog">No show<kbd>S</kbd></button>
                    </div>

                    <div id="noShowDialog" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="noShowTitle">
                        <div class="modal-box dialog-box">
                            <h3 id="noShowTitle">Mark #<?= htmlspecialchars($current_ticket['queue_number']) ?> as No Show?</h3>
                            <p>Use this when the patient didn't come after being called.</p>
                            <form method="post" class="dialog-actions">
                                <input type="hidden" name="id" value="<?= (int) $current_ticket['id'] ?>">
                                <input type="hidden" name="phlebotomist_name" value="<?= htmlspecialchars($phlebotomist_name) ?>">
                                <button type="button" class="btn btn-outline" data-close-dialog>Cancel</button>
                                <button type="submit" name="no_show" class="btn btn-warning">Yes, No Show</button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="hero-empty">No patient in the extraction room.</div>
                    <form method="post" id="callNextForm">
                        <input type="hidden" name="phlebotomist_name" value="<?= htmlspecialchars($phlebotomist_name) ?>">
                        <?php if (empty($extraction_queue)): ?>
                        <button type="submit" name="call_next" class="btn btn-big" disabled><span>CALL NEXT</span><small>No one ready yet</small></button>
                        <?php else: ?>
                        <button type="submit" name="call_next" class="btn btn-big" data-key="N">
                            <span>CALL NEXT<kbd>N</kbd></span>
                            <small><?= count($extraction_queue) ?> ready · next is #<?= (int) $extraction_queue[0]['queue_number'] ?></small>
                        </button>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </section>

            <aside>
                <section class="side-panel" aria-label="Next in line">
                    <h3>Next in Line <span class="count"><?= count($extraction_queue) ?></span></h3>
                    <?php if (empty($extraction_queue)): ?>
                    <div class="empty-note">No one waiting for extraction.</div>
                    <?php else: ?>
                    <div class="chip-list">
                        <?php foreach (array_slice($extraction_queue, 0, NEXT_IN_LINE_LIMIT) as $i => $row): ?>
                        <span class="chip<?= $i === 0 ? ' is-next' : '' ?>"><?= (int) $row['queue_number'] ?><small><?= !empty($row['payment_required']) ? 'paid' : 'no charge' ?></small></span>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($extraction_queue) > NEXT_IN_LINE_LIMIT): ?>
                    <p class="hint">+<?= count($extraction_queue) - NEXT_IN_LINE_LIMIT ?> more waiting</p>
                    <?php endif; ?>
                    <p class="hint">In calling order: patients back from payment join at the end.</p>
                    <?php endif; ?>
                </section>

                <section class="side-panel" aria-label="Just completed">
                    <h3>Just Completed</h3>
                    <?php if (empty($recently_completed)): ?>
                    <div class="empty-note">No completions yet today.</div>
                    <?php else: ?>
                    <div class="completed-line"><?= implode(' · ', array_map(function ($r) { return (int) $r['queue_number']; }, $recently_completed)) ?></div>
                    <p class="hint">Last done at <?= date('g:i A', strtotime($recently_completed[0]['extraction_completed_at'])) ?></p>
                    <?php endif; ?>
                </section>
            </aside>
        </div>

        <?php endif; ?>
    </div>

    <script src="assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>"></script>
    <?php if (!$show_name_card): ?>
    <script>
    LabUI.startPolling({ interval: 1000 });

    // Focus the most likely next action after every reload, so Enter and the
    // keyboard letters work without a mouse click first.
    (function() {
        const target = document.querySelector('button[name=complete]')
            || document.querySelector('button[name=call_next]:not([disabled])');
        if (target) target.focus();
    })();
    </script>
    <?php endif; ?>
</body>
</html>
