<?php
session_start();
require 'config.php';
require 'queue_functions.php';

// ---------- small local helpers (index.php-only, not shared queue logic) ----------

function redirect_url($encoder_name, $interview_station) {
    return $_SERVER['PHP_SELF'] . '?encoder_name=' . urlencode($encoder_name) . '&interview_station=' . urlencode((string) $interview_station);
}

function elapsed_minutes($datetime) {
    if (!$datetime) return null;
    return (int) floor((time() - strtotime($datetime)) / 60);
}

function count_status($conn, $service_date, $status) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM queue WHERE service_date = ? AND status = ?");
    $stmt->bind_param('ss', $service_date, $status);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) $row['cnt'];
}

function ordinal($n) {
    $n = (int) $n;
    if (!in_array($n % 100, [11, 12, 13], true)) {
        switch ($n % 10) {
            case 1: return $n . 'st';
            case 2: return $n . 'nd';
            case 3: return $n . 'rd';
        }
    }
    return $n . 'th';
}

function badge_for_status($status) {
    $map = [
        'waiting' => ['WAITING', 'badge-pending'],
        'interviewing' => ['INTERVIEWING', 'badge-pending'],
        'awaiting_payment' => ['AWAITING PAYMENT', 'badge-pending'],
        'ready_for_extraction' => ['READY FOR EXTRACTION', 'badge-completed'],
        'extracting' => ['EXTRACTING', 'badge-completed'],
        'completed' => ['COMPLETED', 'badge-completed'],
        'no_show' => ['NO SHOW', 'badge-cancelled'],
        'cancelled' => ['CANCELLED', 'badge-cancelled'],
    ];
    return $map[$status] ?? [strtoupper($status), 'badge-pending'];
}

/**
 * Position (1-based) of a queue row inside today's extraction queue, or null
 * if it isn't in it. Same ordering as get_extraction_queue().
 */
function extraction_position($conn, $service_date, $id) {
    $extraction_queue = get_extraction_queue($conn, $service_date);
    foreach ($extraction_queue as $i => $r) {
        if ((int) $r['id'] === (int) $id) return $i + 1;
    }
    return null;
}

function payment_reason_message($conn, $service_date, $reason, $row, $queue_number) {
    switch ($reason) {
        case 'not_found':
            return "No number $queue_number issued today.";
        case 'wrong_day':
            return "Number $queue_number is from " . date('M j', strtotime($row['service_date'])) . ", not today.";
        case 'not_yet_interviewed':
            return "Number $queue_number has not been interviewed yet.";
        case 'no_charge':
            return "Number $queue_number was marked NO CHARGE and is already queued for extraction.";
        case 'already_confirmed':
            $position = extraction_position($conn, $service_date, $row['id']);
            $pos_text = $position ? ordinal($position) . ' in the extraction queue' : 'already in the extraction queue';
            $time = $row['payment_confirmed_at'] ? date('g:i A', strtotime($row['payment_confirmed_at'])) : 'an earlier time';
            $staff = $row['payment_confirmed_by'] ?: 'another encoder';
            return "Number $queue_number was already confirmed at $time by $staff. Currently $pos_text.";
        default:
            return null;
    }
}

function build_payment_search_result($conn, $service_date, $queue_number) {
    [$row, $confirmable, $reason] = find_for_payment($conn, $queue_number);
    $message = $reason ? payment_reason_message($conn, $service_date, $reason, $row, $queue_number) : null;
    $badge = $row ? badge_for_status($row['status']) : null;
    return [
        'found' => $row !== null,
        'confirmable' => $confirmable,
        'reason' => $reason,
        'message' => $message,
        'row' => $row ? [
            'id' => (int) $row['id'],
            'queue_number' => (int) $row['queue_number'],
            'status' => $row['status'],
            'badge_label' => $badge[0],
            'badge_class' => $badge[1],
            'created_at_display' => $row['created_at'] ? date('g:i A', strtotime($row['created_at'])) : null,
            'interview_completed_at_display' => $row['interview_completed_at'] ? date('g:i A', strtotime($row['interview_completed_at'])) : null,
            'away_minutes' => elapsed_minutes($row['interview_completed_at']),
        ] : null,
    ];
}

// ---------- encoder identity: mirrors doctor.php's doctor_name/table_number pattern ----------

$encoder_name = '';
$interview_station = 0;
if (isset($_GET['encoder_name'])) {
    $encoder_name = trim($_GET['encoder_name']);
    $interview_station = isset($_GET['interview_station']) ? (int) $_GET['interview_station'] : 0;
    $_SESSION['encoder_name'] = $encoder_name;
    $_SESSION['interview_station'] = $interview_station;
} elseif (isset($_SESSION['encoder_name'])) {
    $encoder_name = $_SESSION['encoder_name'];
    $interview_station = isset($_SESSION['interview_station']) ? (int) $_SESSION['interview_station'] : 0;
}

// ---------- AJAX endpoints for the Payment Confirmation panel (same inline-AJAX ----------
// ---------- pattern OPD uses for its field updates: X-Requested-With + JSON) ----------

if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && isset($_POST['payment_search'])) {
    header('Content-Type: application/json');
    $service_date = service_date_now($conn);
    $queue_number = (int) ($_POST['queue_number'] ?? 0);
    echo json_encode(build_payment_search_result($conn, $service_date, $queue_number));
    exit();
}

if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && isset($_POST['payment_confirm'])) {
    header('Content-Type: application/json');
    $service_date = service_date_now($conn);
    $id = (int) ($_POST['id'] ?? 0);
    $queue_number = (int) ($_POST['queue_number'] ?? 0);
    $staff = trim($_POST['encoder_name'] ?? '');
    $payment_reference = trim($_POST['payment_reference'] ?? '');
    $payment_reference = $payment_reference !== '' ? $payment_reference : null;

    $ok = confirm_payment($conn, $id, $staff, $payment_reference);
    if ($ok) {
        $position = extraction_position($conn, $service_date, $id);
        echo json_encode([
            'ok' => true,
            'message' => "Number $queue_number confirmed. Now " . ordinal($position ?? 1) . " in the extraction queue.",
        ]);
    } else {
        // Guarded UPDATE affected 0 rows — a double-click, or someone else already
        // confirmed it. Re-read and report the real state, not a generic error.
        $result = build_payment_search_result($conn, $service_date, $queue_number);
        echo json_encode(['ok' => false] + $result);
    }
    exit();
}

// Ready for Claiming panel: same inline-AJAX pattern as the two handlers
// above, chosen specifically so adding/claiming a name never reloads the
// page — a full-page-reload here would reset scroll position, which is the
// actual problem this was built to avoid.
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && isset($_POST['add_claimable'])) {
    header('Content-Type: application/json');
    $post_encoder = trim($_POST['encoder_name'] ?? '');
    $surname = trim($_POST['surname'] ?? '');
    $first_name_initials = trim($_POST['first_name_initials'] ?? '');
    if ($surname === '' || $first_name_initials === '') {
        echo json_encode(['ok' => false, 'error' => 'Surname and first name initials are required.']);
        exit();
    }
    $id = add_claimable_result($conn, $surname, $first_name_initials, $post_encoder);
    echo json_encode([
        'ok' => true,
        'row' => ['id' => $id, 'surname' => $surname, 'first_name_initials' => $first_name_initials],
    ]);
    exit();
}

if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && isset($_POST['mark_claimed'])) {
    header('Content-Type: application/json');
    $id = (int) ($_POST['id'] ?? 0);
    $post_encoder = trim($_POST['encoder_name'] ?? '');
    $ok = mark_result_claimed($conn, $id, $post_encoder);
    echo json_encode(['ok' => $ok]);
    exit();
}

// ---------- POST handlers (POST-Redirect-GET per CLAUDE.md §4) ----------

$error_message = '';

if (isset($_POST['add'])) {
    $queue_number = (int) $_POST['queue_number'];
    if ($queue_number <= 0) {
        $error_message = 'Queue number must be a positive integer!';
    } else {
        [$ok, $err] = add_to_queue($conn, $queue_number);
        if (!$ok) {
            $error_message = $err;
        } else {
            header('Location: ' . redirect_url($encoder_name, $interview_station));
            exit();
        }
    }
}

if (isset($_POST['call_next'])) {
    $post_encoder = trim($_POST['encoder_name']);
    $post_station = (int) $_POST['interview_station'];
    $ticket = call_next_interview($conn, $post_station, $post_encoder);
    if ($ticket) {
        // display.php builds the spoken sentence from type + station — see extraction.php
        // for the 'extraction' counterpart of this payload shape.
        file_put_contents('notify.json', json_encode([
            'queue_number' => $ticket['queue_number'],
            'type' => 'interview',
            'station' => $post_station,
            'timestamp' => microtime(true),
        ]));
    } else {
        // Nothing waiting (or another station's request won the claim race) — flash a
        // one-shot notice so the encoder can tell "nobody's waiting" apart from "it's
        // broken," instead of landing back on a page where Add to Queue is the only
        // thing visibly left to do.
        $_SESSION['flash_notice'] = 'No one is waiting to be called.';
    }
    header('Location: ' . redirect_url($post_encoder, $post_station));
    exit();
}

if (isset($_POST['for_payment'])) {
    $id = (int) $_POST['id'];
    $post_encoder = trim($_POST['encoder_name']);
    $post_station = (int) $_POST['interview_station'];
    complete_interview($conn, $id, true, $post_encoder);
    header('Location: ' . redirect_url($post_encoder, $post_station));
    exit();
}

if (isset($_POST['no_charge'])) {
    $id = (int) $_POST['id'];
    $post_encoder = trim($_POST['encoder_name']);
    $post_station = (int) $_POST['interview_station'];
    complete_interview($conn, $id, false, $post_encoder);
    header('Location: ' . redirect_url($post_encoder, $post_station));
    exit();
}

if (isset($_POST['recall'])) {
    $id = (int) $_POST['id'];
    $post_encoder = trim($_POST['encoder_name']);
    $post_station = (int) $_POST['interview_station'];
    $ticket = find_queue_row($conn, $id);
    $ok = recall($conn, $id, $post_encoder);
    if ($ok && $ticket) {
        // Same notify.json shape as call_next above — see extraction.php's
        // recall handler for the 'extraction' counterpart of this payload shape.
        file_put_contents('notify.json', json_encode([
            'queue_number' => $ticket['queue_number'],
            'type' => 'interview',
            'station' => $post_station,
            'timestamp' => microtime(true),
        ]));
    }
    header('Location: ' . redirect_url($post_encoder, $post_station));
    exit();
}

if (isset($_POST['no_show'])) {
    $id = (int) $_POST['id'];
    $post_encoder = trim($_POST['encoder_name']);
    $post_station = (int) $_POST['interview_station'];
    mark_no_show($conn, $id, $post_encoder);
    header('Location: ' . redirect_url($post_encoder, $post_station));
    exit();
}

if (isset($_POST['save_notes'])) {
    $id = (int) $_POST['id'];
    $post_encoder = trim($_POST['encoder_name']);
    $post_station = (int) $_POST['interview_station'];
    $notes = trim($_POST['notes'] ?? '');
    $stmt = $conn->prepare("UPDATE queue SET notes = ? WHERE id = ?");
    $stmt->bind_param('si', $notes, $id);
    $stmt->execute();
    $stmt->close();
    header('Location: ' . redirect_url($post_encoder, $post_station));
    exit();
}

// ---------- render ----------

$notice_message = '';
if (isset($_SESSION['flash_notice'])) {
    $notice_message = $_SESSION['flash_notice'];
    unset($_SESSION['flash_notice']);
}

$has_identity = $encoder_name !== '' && $interview_station > 0;
$service_date = service_date_now($conn);

if ($has_identity) {
    $waiting_count = count_status($conn, $service_date, 'waiting');
    $interviewing_count = count_status($conn, $service_date, 'interviewing');
    $awaiting_payment_count = count_status($conn, $service_date, 'awaiting_payment');
    $ready_for_extraction_count = count_status($conn, $service_date, 'ready_for_extraction');
    $completed_count = count_status($conn, $service_date, 'completed');

    $next_number = next_suggested_number($conn);
    $waiting_list = get_interview_queue($conn, $service_date);
    $awaiting_payment_list = get_awaiting_payment($conn, $service_date);

    $stmt = $conn->prepare(
        "SELECT * FROM queue WHERE service_date = ? AND interview_station = ? AND status = 'interviewing' LIMIT 1"
    );
    $stmt->bind_param('si', $service_date, $interview_station);
    $stmt->execute();
    $current_ticket = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    $claimable_results = get_claimable_results($conn);
}

$WAITING_VISIBLE_LIMIT = 4;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Laboratory Queueing — Encoder Console</title>
<link rel="stylesheet" href="assets/theme.css?v=<?= filemtime(__DIR__ . '/assets/theme.css') ?>">
<style>
    .alert-notice { background: var(--yellow-light); color: var(--yellow); }
    .app-header .identity-bar { margin-left: auto; }
    .identity-form { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .identity-form label { color: rgba(255,255,255,0.85); font-size: 0.8rem; font-weight: 600; }
    .identity-form .field { padding: 6px 10px; font-size: 0.85rem; }
    .identity-form input[name="interview_station"] { width: 70px; }
    .current-ticket-card { width: auto; min-width: 260px; max-width: 360px; padding: 28px 24px; }
    .current-ticket-card .queue-number { font-size: 3rem; }
    .current-ticket-card .notes-form { width: 100%; margin-top: 14px; display: flex; flex-direction: column; gap: 6px; }
    .current-ticket-card .notes-form .field { width: 100%; }
    #paymentResultBox { min-height: 0; }
    .payment-result-card { width: 100%; max-width: 560px; flex-direction: row; justify-content: space-between; align-items: center; text-align: left; padding: 20px 24px; gap: 16px; }
    .payment-result-card .queue-number { font-size: 2.4rem; }
    .payment-result-card .badge { margin-top: 6px; }
    .payment-confirm-form { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }
    @media (max-width: 900px) {
        .app-header .identity-bar { margin-left: 0; width: 100%; }
    }
    .kbd-legend { text-align: center; font-size: 0.78rem; color: var(--text-muted); margin-bottom: 14px; }
    .kbd-legend kbd {
        display: inline-block; min-width: 1.4em; padding: 1px 6px; margin: 0 2px;
        border: 1px solid var(--border); border-radius: 5px; background: var(--surface-alt);
        font-family: var(--font); font-weight: 700; color: var(--text);
    }
    .waiting-col .queue-grid .extra-card { display: none; }
    .waiting-col .queue-grid.show-all .extra-card { display: flex; }
    #waitingSeeMoreBtn { margin-top: 12px; }
    /* Wider/taller than the default .modal-box (280px/40vh) so the existing
       2-column name grid and search bar have room, instead of squeezing a
       grid built for that layout into a narrow single-column-ish box. */
    .modal-box.claim-list-box { max-width: 640px; max-height: 78vh; }
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
        setTimeout(pollQueueStatus, 3000);
    }
    pollQueueStatus();
</script>
</head>
<body>
    <div class="app-header">
        <img src="CHO.png" alt="CHO Logo" class="logo-img">
        <div class="title">Laboratory Queueing</div>
        <div class="subtitle">Encoder Console</div>
        <div class="identity-bar">
            <form method="get" class="identity-form">
                <label for="encoder_name">Encoder</label>
                <input class="field" type="text" id="encoder_name" name="encoder_name" value="<?= htmlspecialchars($encoder_name) ?>" required>
                <label for="interview_station">Station</label>
                <input class="field" type="number" id="interview_station" name="interview_station" value="<?= $interview_station > 0 ? htmlspecialchars((string) $interview_station) : '' ?>" min="1" required>
                <button type="submit" class="btn btn-sm"><?= $has_identity ? 'Change' : 'Set' ?></button>
            </form>
        </div>
    </div>
    <div class="page">
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>
        <?php if (!empty($notice_message)): ?>
            <div class="alert alert-notice"><?= htmlspecialchars($notice_message) ?></div>
        <?php endif; ?>

        <?php if (!$has_identity): ?>
            <div class="empty-note">Set your name and station above to start the encoder console.</div>
        <?php else: ?>

        <div class="kbd-legend no-print">
            Keyboard: <kbd>N</kbd> Call Next &nbsp; <kbd>P</kbd> For Payment &nbsp; <kbd>C</kbd> No Charge &nbsp; <kbd>R</kbd> Recall &nbsp; <kbd>S</kbd> No Show
        </div>

        <div class="stat-cards">
            <div class="stat-card">
                <span class="stat-label">Waiting</span>
                <span class="stat-value"><?= $waiting_count ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Interviewing</span>
                <span class="stat-value"><?= $interviewing_count ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Awaiting Payment</span>
                <span class="stat-value"><?= $awaiting_payment_count ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Ready for Extraction</span>
                <span class="stat-value"><?= $ready_for_extraction_count ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Completed Today</span>
                <span class="stat-value"><?= $completed_count ?></span>
            </div>
        </div>

        <form method="post" class="toolbar-form">
            <label for="queue_number">Queue Number</label>
            <input class="field" type="number" id="queue_number" name="queue_number" value="<?= htmlspecialchars((string) $next_number) ?>" min="1" required>
            <button type="submit" name="add" class="btn">Add to Queue</button>
        </form>

        <div class="main-columns">
            <div class="queue-section waiting-col">
                <h2 class="section-title accent-green">Waiting for Interview (<?= count($waiting_list) ?>)</h2>
                <button id="viewWaitingListBtn" class="btn btn-outline">View Full List</button>

                <div id="waitingListModal" class="modal-overlay">
                  <div class="modal-box">
                    <button id="closeWaitingListModal" class="modal-close" aria-label="Close">&times;</button>
                    <h2 class="section-title accent-green" style="margin-bottom:16px;">Waiting for Interview — Full List</h2>
                    <table class="data-table">
                        <tr><th>#</th><th>Queue Number</th><th>Waiting</th></tr>
                        <?php if (empty($waiting_list)): ?>
                        <tr><td colspan="3" class="no-logs">No one waiting.</td></tr>
                        <?php else: $i = 1; foreach ($waiting_list as $row): $mins = elapsed_minutes($row['created_at']); ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><?= htmlspecialchars($row['queue_number']) ?></td>
                            <td><?= $mins !== null ? $mins . ' min' : '—' ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </table>
                  </div>
                </div>

                <div class="queue-grid">
                    <?php if (empty($waiting_list)): ?>
                    <div class="empty-note">No one waiting.</div>
                    <?php else: foreach ($waiting_list as $i => $row): $mins = elapsed_minutes($row['created_at']);
                        $tint = '';
                        if ($mins !== null && $mins >= 30) $tint = ' priority';
                        elseif ($mins !== null && $mins >= 15) $tint = ' pending';
                        $extra = $i >= $WAITING_VISIBLE_LIMIT ? ' extra-card' : '';
                    ?>
                    <div class="queue-card<?= $tint . $extra ?>">
                        <span class="queue-number"><?= htmlspecialchars($row['queue_number']) ?></span>
                        <div class="info"><?= $mins !== null ? $mins . ' min waiting' : '' ?></div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
                <?php if (count($waiting_list) > $WAITING_VISIBLE_LIMIT): ?>
                <button type="button" id="waitingSeeMoreBtn" class="btn btn-outline btn-sm">See More (<?= count($waiting_list) - $WAITING_VISIBLE_LIMIT ?>)</button>
                <?php endif; ?>
            </div>

            <div class="queue-section">
                <h2 class="section-title accent-green">Now Interviewing</h2>
                <?php if ($current_ticket): ?>
                    <div class="queue-card current-ticket-card">
                        <span class="queue-number"><?= htmlspecialchars($current_ticket['queue_number']) ?></span>
                        <?php if ((int) $current_ticket['recall_count'] > 0): ?>
                        <div class="info">Recalled <?= (int) $current_ticket['recall_count'] ?>x</div>
                        <?php endif; ?>

                        <div class="card-actions" style="margin-top:14px;">
                            <form method="post" style="display:inline">
                                <input type="hidden" name="id" value="<?= $current_ticket['id'] ?>">
                                <input type="hidden" name="encoder_name" value="<?= htmlspecialchars($encoder_name) ?>">
                                <input type="hidden" name="interview_station" value="<?= $interview_station ?>">
                                <button type="submit" name="for_payment" class="btn btn-warning">FOR PAYMENT</button>
                            </form>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="id" value="<?= $current_ticket['id'] ?>">
                                <input type="hidden" name="encoder_name" value="<?= htmlspecialchars($encoder_name) ?>">
                                <input type="hidden" name="interview_station" value="<?= $interview_station ?>">
                                <button type="submit" name="no_charge" class="btn">NO CHARGE</button>
                            </form>
                        </div>
                        <div class="card-actions">
                            <form method="post" style="display:inline">
                                <input type="hidden" name="id" value="<?= $current_ticket['id'] ?>">
                                <input type="hidden" name="encoder_name" value="<?= htmlspecialchars($encoder_name) ?>">
                                <input type="hidden" name="interview_station" value="<?= $interview_station ?>">
                                <button type="submit" name="recall" class="btn btn-info btn-sm">Recall</button>
                            </form>
                            <form method="post" style="display:inline" id="noShowForm">
                                <input type="hidden" name="id" value="<?= $current_ticket['id'] ?>">
                                <input type="hidden" name="encoder_name" value="<?= htmlspecialchars($encoder_name) ?>">
                                <input type="hidden" name="interview_station" value="<?= $interview_station ?>">
                                <button type="submit" name="no_show" class="btn btn-neutral btn-sm">No Show</button>
                            </form>
                        </div>

                        <div id="noShowModal" class="modal-overlay">
                            <div class="modal-box confirm-box">
                                <h3>No Show?</h3>
                                <p>Queue #<?= htmlspecialchars($current_ticket['queue_number']) ?></p>
                                <div class="confirm-actions">
                                    <button type="button" id="noShowCancelBtn" class="btn btn-outline">No</button>
                                    <button type="button" id="noShowConfirmBtn" class="btn btn-warning">Yes, No Show</button>
                                </div>
                            </div>
                        </div>
                        <form method="post" class="notes-form">
                            <input type="hidden" name="id" value="<?= $current_ticket['id'] ?>">
                            <input type="hidden" name="encoder_name" value="<?= htmlspecialchars($encoder_name) ?>">
                            <input type="hidden" name="interview_station" value="<?= $interview_station ?>">
                            <input class="field" type="text" name="notes" placeholder="Notes..." value="<?= htmlspecialchars($current_ticket['notes'] ?? '') ?>">
                            <button type="submit" name="save_notes" class="btn btn-sm">Save Notes</button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="empty-note">No one currently being interviewed at this station.</div>
                <?php endif; ?>

                <form method="post" id="callNextForm" style="margin-top:18px;">
                    <input type="hidden" name="encoder_name" value="<?= htmlspecialchars($encoder_name) ?>">
                    <input type="hidden" name="interview_station" value="<?= $interview_station ?>">
                    <button type="submit" name="call_next" class="btn" <?= $current_ticket ? 'disabled' : '' ?>>CALL NEXT</button>
                </form>
            </div>

            <div class="queue-section">
                <h2 class="section-title accent-orange">Awaiting Payment</h2>
                <div class="queue-grid">
                    <?php if (empty($awaiting_payment_list)): ?>
                    <div class="empty-note">No one awaiting payment.</div>
                    <?php else: foreach ($awaiting_payment_list as $row): $mins = elapsed_minutes($row['interview_completed_at']);
                        $tint = ($mins !== null && $mins >= 45) ? 'priority' : 'pending';
                    ?>
                    <div class="queue-card <?= $tint ?>">
                        <span class="queue-number"><?= htmlspecialchars($row['queue_number']) ?></span>
                        <div class="info"><?= $mins !== null ? $mins . ' min away' : '' ?></div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <div class="main-columns" style="margin-top:22px;">
            <div class="queue-section">
                <h2 class="section-title accent-orange">Payment Confirmation</h2>
                <form id="paymentSearchForm" class="toolbar-form">
                    <label for="paymentSearchInput">Queue Number</label>
                    <input class="field" type="number" id="paymentSearchInput" name="queue_number" style="font-size:1.3rem;font-weight:700;width:150px;text-align:center;" autofocus autocomplete="off" min="1">
                    <button type="submit" class="btn">Find</button>
                </form>
                <div id="paymentResultBox" style="width:100%;display:flex;justify-content:center;margin:6px 0 22px;"></div>

                <div class="queue-grid grid-cols-2">
                    <?php if (empty($awaiting_payment_list)): ?>
                    <div class="empty-note">No one awaiting payment.</div>
                    <?php else: foreach ($awaiting_payment_list as $row): ?>
                    <div class="queue-card pending clickable awaiting-card" data-number="<?= (int) $row['queue_number'] ?>">
                        <span class="queue-number"><?= htmlspecialchars($row['queue_number']) ?></span>
                        <?php $mins = elapsed_minutes($row['interview_completed_at']); ?>
                        <div class="info"><?= $mins !== null ? $mins . ' min away' : '' ?></div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <div class="queue-section">
                <h2 class="section-title accent-orange">Ready for Claiming</h2>
                <form id="addClaimForm" class="toolbar-form">
                    <label for="claim_surname">Surname</label>
                    <input class="field" type="text" id="claim_surname" name="surname" autocomplete="off">
                    <label for="claim_initials">First Name Initials</label>
                    <input class="field" type="text" id="claim_initials" name="first_name_initials" placeholder="e.g. J.M." autocomplete="off" style="width:110px;">
                    <button type="submit" name="add_claimable" class="btn">Add</button>
                </form>
                <div id="claimFormError" class="alert alert-error" style="display:none;width:100%;margin-top:-8px;"></div>

                <div class="toolbar-form" style="margin-top:10px;">
                    <label for="claimSearchInput">Search</label>
                    <input class="field" type="text" id="claimSearchInput" placeholder="Surname or initials..." autocomplete="off" style="width:220px;">
                    <button type="button" id="claimSearchClear" class="btn btn-outline btn-sm" style="display:none;">&times; Clear</button>
                </div>
                <div id="claimSearchResults" class="queue-grid grid-cols-2" style="display:none;margin-top:6px;"></div>

                <button type="button" id="openClaimListBtn" class="btn btn-outline" style="margin-top:14px;">Show Ready for Claiming List</button>

                <div id="claimListModal" class="modal-overlay">
                  <div class="modal-box claim-list-box">
                    <button id="closeClaimListModal" class="modal-close" aria-label="Close">&times;</button>
                    <h2 class="section-title accent-orange" style="margin-bottom:16px;">Ready for Claiming — Full List</h2>
                    <div class="queue-grid grid-cols-2" id="claimGrid">
                        <?php if (empty($claimable_results)): ?>
                        <div class="empty-note">No results ready for claiming.</div>
                        <?php else: foreach ($claimable_results as $row): ?>
                        <div class="queue-card" data-claim-id="<?= (int) $row['id'] ?>">
                            <span class="claim-name"><?= htmlspecialchars($row['surname']) ?>, <?= htmlspecialchars($row['first_name_initials']) ?></span>
                            <form class="card-form claim-claimed-form">
                                <button type="submit" name="mark_claimed" class="btn btn-sm">Claimed</button>
                            </form>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>
                  </div>
                </div>
            </div>
        </div>

        <?php endif; ?>
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
                if (event.target === modal) modal.style.display = 'none';
            };
        }

        // "See More" for the waiting grid: cards beyond the initial cap render
        // in the DOM already (server-side), just hidden via CSS, so this is a
        // plain toggle rather than a fetch/reload.
        const waitingSeeMoreBtn = document.getElementById('waitingSeeMoreBtn');
        const waitingGrid = document.querySelector('.waiting-col .queue-grid');
        if (waitingSeeMoreBtn && waitingGrid) {
            const hiddenCount = waitingGrid.querySelectorAll('.extra-card').length;
            waitingSeeMoreBtn.addEventListener('click', function() {
                const expanded = waitingGrid.classList.toggle('show-all');
                waitingSeeMoreBtn.textContent = expanded ? 'See Less' : 'See More (' + hiddenCount + ')';
            });
        }

        // Keep the button state driven by the server render. Disabling it here
        // can leave the UI stuck after a queue reset or a slow redirect.
        const callNextForm = document.getElementById('callNextForm');
        if (callNextForm) {
            callNextForm.addEventListener('submit', function() {
                const btn = this.querySelector('button[type=submit]');
                if (btn) {
                    btn.blur();
                }
            });
        }

        // Themed No Show confirmation — replaces the native confirm() dialog
        // (browser-chrome popups don't take app CSS) with the same
        // modal-overlay/modal-box pattern used by the waiting-list modal.
        const noShowForm = document.getElementById('noShowForm');
        const noShowModal = document.getElementById('noShowModal');
        const noShowConfirmBtn = document.getElementById('noShowConfirmBtn');
        const noShowCancelBtn = document.getElementById('noShowCancelBtn');
        if (noShowForm && noShowModal && noShowConfirmBtn && noShowCancelBtn) {
            noShowForm.addEventListener('submit', function(e) {
                e.preventDefault();
                noShowModal.style.display = 'flex';
            });
            noShowConfirmBtn.addEventListener('click', function() {
                noShowModal.style.display = 'none';
                noShowForm.submit();
            });
            noShowCancelBtn.addEventListener('click', function() {
                noShowModal.style.display = 'none';
            });
            window.addEventListener('click', function(e) {
                if (e.target === noShowModal) noShowModal.style.display = 'none';
            });
        }

        // Keyboard shortcuts (see the legend above the stat cards). Ignored
        // while typing in a field, and a no-op if the matching button isn't
        // present/enabled — e.g. N does nothing once a ticket is already active.
        document.addEventListener('keydown', function(e) {
            if (e.altKey || e.ctrlKey || e.metaKey) return;
            const tag = (document.activeElement && document.activeElement.tagName || '').toLowerCase();
            if (tag === 'input' || tag === 'textarea' || tag === 'select') return;

            const shortcuts = {
                N: '#callNextForm button[type=submit]',
                P: 'button[name=for_payment]',
                C: 'button[name=no_charge]',
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

        // Focus management: land on whatever the encoder will most likely do
        // next, so keyboard shortcuts and fast typing work immediately after
        // every reload without a mouse click first.
        (function() {
            const currentTicketCard = document.querySelector('.current-ticket-card');
            if (currentTicketCard) {
                const firstDecisionBtn = document.querySelector('button[name=for_payment]');
                if (firstDecisionBtn) firstDecisionBtn.focus();
            } else {
                const qnInput = document.getElementById('queue_number');
                if (qnInput) qnInput.focus();
            }
        })();
    </script>

    <script>
    // --- Payment Confirmation panel: inline AJAX search + confirm ---
    (function() {
        const input = document.getElementById('paymentSearchInput');
        const resultBox = document.getElementById('paymentResultBox');
        const form = document.getElementById('paymentSearchForm');
        if (!input || !resultBox || !form) return;

        const currentEncoderName = <?= json_encode($encoder_name) ?>;
        let debounceTimer = null;

        function escapeHtml(s) {
            const d = document.createElement('div');
            d.textContent = s == null ? '' : s;
            return d.innerHTML;
        }

        function renderResult(data, queueNumber) {
            if (!data.found) {
                resultBox.innerHTML = '<div class="alert alert-error">' + escapeHtml(data.message || ('No number ' + queueNumber + ' issued today.')) + '</div>';
                return;
            }
            const row = data.row;
            let html = '<div class="queue-card payment-result-card">';
            html += '<div><span class="queue-number">' + row.queue_number + '</span>';
            html += '<div class="info">Added: ' + (row.created_at_display || '—') + ' &middot; Interviewed: ' + (row.interview_completed_at_display || '—') + '</div>';
            if (row.away_minutes !== null) html += '<div class="info">Away ' + row.away_minutes + ' min</div>';
            html += '<span class="badge ' + row.badge_class + '">' + escapeHtml(row.badge_label) + '</span></div>';
            if (data.confirmable) {
                html += '<form id="confirmPaymentForm" class="payment-confirm-form">'
                      + '<input class="field" type="text" name="payment_reference" placeholder="OR / Reference No.">'
                      + '<button type="submit" class="btn" style="min-width:190px;">CONFIRM PAYMENT</button>'
                      + '</form>';
            } else {
                html += '<div class="alert alert-error" style="margin:0;max-width:260px;">' + escapeHtml(data.message) + '</div>';
            }
            html += '</div>';
            resultBox.innerHTML = html;

            const confirmForm = document.getElementById('confirmPaymentForm');
            if (confirmForm) {
                confirmForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const btn = this.querySelector('button[type=submit]');
                    btn.disabled = true;
                    const body = new URLSearchParams();
                    body.set('payment_confirm', '1');
                    body.set('id', row.id);
                    body.set('queue_number', row.queue_number);
                    body.set('encoder_name', currentEncoderName);
                    body.set('payment_reference', this.querySelector('input[name=payment_reference]').value);
                    fetch('', { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function(r) { return r.json(); })
                        .then(function(res) {
                            if (res.ok) {
                                resultBox.innerHTML = '<div class="alert" style="background:var(--green-light);color:var(--green-dark);">' + escapeHtml(res.message) + '</div>';
                                input.value = '';
                                input.focus();
                            } else {
                                renderResult(res, row.queue_number);
                            }
                        });
                });
            }
        }

        function doSearch() {
            const n = parseInt(input.value, 10);
            if (!n || n <= 0) { resultBox.innerHTML = ''; return; }
            const body = new URLSearchParams();
            body.set('payment_search', '1');
            body.set('queue_number', n);
            fetch('', { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); })
                .then(function(data) { renderResult(data, n); });
        }

        input.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(doSearch, 300);
        });
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(debounceTimer);
                doSearch();
            }
        });
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            clearTimeout(debounceTimer);
            doSearch();
        });

        document.querySelectorAll('.awaiting-card').forEach(function(card) {
            card.addEventListener('click', function() {
                input.value = card.dataset.number;
                doSearch();
            });
        });
    })();
    </script>

    <script>
    // --- Ready for Claiming panel: inline AJAX add + claimed (same pattern ---
    // --- as the Payment Confirmation panel above) — never a full page reload, ---
    // --- so submitting a name can't reset the encoder's scroll position. ---
    (function() {
        const form = document.getElementById('addClaimForm');
        const grid = document.getElementById('claimGrid');
        const surnameInput = document.getElementById('claim_surname');
        const initialsInput = document.getElementById('claim_initials');
        const errorBox = document.getElementById('claimFormError');
        const searchInput = document.getElementById('claimSearchInput');
        const searchClearBtn = document.getElementById('claimSearchClear');
        const searchResults = document.getElementById('claimSearchResults');
        if (!form || !grid || !surnameInput || !initialsInput || !errorBox) return;

        const currentEncoderName = <?= json_encode($encoder_name) ?>;
        const currentStation = <?= (int) $interview_station ?>;

        function escapeHtml(s) {
            const d = document.createElement('div');
            d.textContent = s == null ? '' : s;
            return d.innerHTML;
        }

        function claimCardHtml(id, nameText) {
            return '<div class="queue-card" data-claim-id="' + id + '">'
                + '<span class="claim-name">' + escapeHtml(nameText) + '</span>'
                + '<form class="card-form claim-claimed-form"><button type="submit" name="mark_claimed" class="btn btn-sm">Claimed</button></form>'
                + '</div>';
        }

        function resetGridEmptyState() {
            if (!grid.querySelector('[data-claim-id]')) {
                grid.innerHTML = '<div class="empty-note">No results ready for claiming.</div>';
            }
        }

        // Search stays on the page permanently and drives a small inline
        // preview under the search bar — NOT the modal, and NOT a second data
        // source. It reads live from #claimGrid (the same authoritative list
        // the modal shows in full), so it's always in sync with adds/claims
        // from either place. Empty query = no preview, matching "search
        // shouldn't require opening the list first" without also turning the
        // preview into a second full-list view.
        function renderSearchResults() {
            if (!searchInput || !searchClearBtn || !searchResults) return;
            const query = searchInput.value.trim().toLowerCase();
            searchClearBtn.style.display = query ? 'inline-flex' : 'none';

            if (query === '') {
                searchResults.style.display = 'none';
                searchResults.innerHTML = '';
                return;
            }

            const matches = [];
            grid.querySelectorAll('[data-claim-id]').forEach(function(card) {
                const nameEl = card.querySelector('.claim-name');
                const text = nameEl ? nameEl.textContent : '';
                if (text.toLowerCase().indexOf(query) !== -1) {
                    matches.push({ id: card.dataset.claimId, text: text });
                }
            });

            searchResults.style.display = '';
            searchResults.innerHTML = matches.length
                ? matches.map(function(m) { return claimCardHtml(m.id, m.text); }).join('')
                : '<div class="empty-note">No matching name.</div>';
        }

        if (searchInput && searchClearBtn && searchResults) {
            searchInput.addEventListener('input', renderSearchResults);
            searchClearBtn.addEventListener('click', function() {
                searchInput.value = '';
                renderSearchResults();
                searchInput.focus({ preventScroll: true });
            });
        }

        // "Show Ready for Claiming List" — full, unfiltered browse view, same
        // .modal-overlay/.modal-box pattern as the Waiting List and No Show
        // modals above, independent of search (search no longer lives inside it).
        const openListBtn = document.getElementById('openClaimListBtn');
        const listModal = document.getElementById('claimListModal');
        const closeListBtn = document.getElementById('closeClaimListModal');
        if (openListBtn && listModal && closeListBtn) {
            function openClaimListModal() {
                listModal.style.display = 'flex';
            }
            function closeClaimListModal() {
                listModal.style.display = 'none';
            }
            openListBtn.addEventListener('click', openClaimListModal);
            closeListBtn.addEventListener('click', closeClaimListModal);
            // Matches noShowModal's existing click-outside-closes pattern
            // above (addEventListener, not the waiting-list modal's plain
            // window.onclick=, so this can't silently overwrite/be overwritten
            // by the other modals' own outside-click handlers).
            window.addEventListener('click', function(e) {
                if (e.target === listModal) closeClaimListModal();
            });
            // None of the existing modals close on Escape.
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && listModal.style.display === 'flex') {
                    closeClaimListModal();
                }
            });
        }

        function showError(msg) {
            errorBox.textContent = msg;
            errorBox.style.display = 'block';
        }
        function clearError() {
            errorBox.style.display = 'none';
            errorBox.textContent = '';
        }

        function addCard(id, surname, initials) {
            const emptyNote = grid.querySelector('.empty-note');
            if (emptyNote) emptyNote.remove();
            grid.insertAdjacentHTML('beforeend', claimCardHtml(id, surname + ', ' + initials));
        }

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const surname = surnameInput.value.trim();
            const initials = initialsInput.value.trim();
            clearError();
            if (surname === '') {
                showError('Surname is required.');
                surnameInput.focus({ preventScroll: true });
                return;
            }
            if (initials === '') {
                showError('First name initials are required.');
                initialsInput.focus({ preventScroll: true });
                return;
            }
            const btn = form.querySelector('button[type=submit]');
            btn.disabled = true;
            const body = new URLSearchParams();
            body.set('add_claimable', '1');
            body.set('encoder_name', currentEncoderName);
            body.set('interview_station', currentStation);
            body.set('surname', surname);
            body.set('first_name_initials', initials);
            fetch('', { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    btn.disabled = false;
                    if (res.ok) {
                        addCard(res.row.id, res.row.surname, res.row.first_name_initials);
                        renderSearchResults();
                        surnameInput.value = '';
                        initialsInput.value = '';
                        surnameInput.focus({ preventScroll: true });
                    } else {
                        showError(res.error || 'Could not add name.');
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                    showError('Network error — please try again.');
                });
        });

        function submitMarkClaimed(id, btn) {
            btn.disabled = true;
            const body = new URLSearchParams();
            body.set('mark_claimed', '1');
            body.set('id', id);
            body.set('encoder_name', currentEncoderName);
            return fetch('', { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); })
                .catch(function() { return { ok: false }; })
                .then(function(res) {
                    if (!res.ok) btn.disabled = false;
                    return res;
                });
        }

        // Claiming from the modal's full list: remove the card there, then
        // refresh the search preview in case it's showing that same name.
        grid.addEventListener('submit', function(e) {
            const claimForm = e.target.closest('.claim-claimed-form');
            if (!claimForm) return;
            e.preventDefault();
            const card = claimForm.closest('[data-claim-id]');
            const id = card ? card.dataset.claimId : null;
            if (!id) return;
            submitMarkClaimed(id, claimForm.querySelector('button[type=submit]')).then(function(res) {
                if (res.ok) {
                    card.remove();
                    resetGridEmptyState();
                    renderSearchResults();
                }
            });
        });

        // Claiming from the search preview: #claimGrid (the modal's list) is
        // the authoritative source, so update it there first, then re-render
        // the preview from that updated source rather than hand-editing both.
        if (searchResults) {
            searchResults.addEventListener('submit', function(e) {
                const claimForm = e.target.closest('.claim-claimed-form');
                if (!claimForm) return;
                e.preventDefault();
                const card = claimForm.closest('[data-claim-id]');
                const id = card ? card.dataset.claimId : null;
                if (!id) return;
                submitMarkClaimed(id, claimForm.querySelector('button[type=submit]')).then(function(res) {
                    if (res.ok) {
                        const gridCard = grid.querySelector('[data-claim-id="' + id + '"]');
                        if (gridCard) gridCard.remove();
                        resetGridEmptyState();
                        renderSearchResults();
                    }
                });
            });
        }
    })();
    </script>
</body>
</html>
