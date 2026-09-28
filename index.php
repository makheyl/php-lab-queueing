<?php
session_start();
require 'config.php';
require 'queue_functions.php';
require 'app_header.php';

// There is only one interview window in this lab, so staff never pick or type
// a window number. Kept as a constant (not a UI value) because
// queue.interview_station and the display board's "Window N" still use it —
// if a second window is ever added, this is the one place to change (plus a
// window picker on the name card). Same approach as extraction.php's
// EXTRACTION_STATION.
const INTERVIEW_WINDOW = 1;

// Waiting numbers shown before "+N more".
const WAITING_VISIBLE_LIMIT = 12;

// ---------- small local helpers (index.php-only, not shared queue logic) ----------

function redirect_url($encoder_name) {
    return $_SERVER['PHP_SELF'] . '?encoder_name=' . urlencode($encoder_name);
}

function elapsed_minutes($datetime) {
    if (!$datetime) return null;
    return (int) floor((time() - strtotime($datetime)) / 60);
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
        case 'no_show':
            return "Number $queue_number was marked No Show.";
        case 'cancelled':
            return "Number $queue_number was cancelled.";
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

// ---------- encoder identity: just a name (one interview window) ----------

$encoder_name = '';
if (isset($_GET['encoder_name'])) {
    $encoder_name = trim($_GET['encoder_name']);
    $_SESSION['encoder_name'] = $encoder_name;
} elseif (isset($_SESSION['encoder_name'])) {
    $encoder_name = $_SESSION['encoder_name'];
}

// Only the browser marked as the ticket station on printer_setup.php (the PC
// the XP-80T is plugged into) may issue/print tickets — a click anywhere else
// would send the ticket to that PC's own default printer.
$is_ticket_station = ($_COOKIE['lab_ticket_station'] ?? '') === '1';

// ---------- AJAX endpoints for Back from Payment (same inline-AJAX ----------
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
        $message = "Number $queue_number confirmed. Now " . ordinal($position ?? 1) . " in line for extraction.";
        // The page reloads after a confirm; the flash carries the message across.
        $_SESSION['flash_success'] = $message;
        echo json_encode(['ok' => true, 'message' => $message]);
    } else {
        // Guarded UPDATE affected 0 rows — a double-click, or someone else already
        // confirmed it. Re-read and report the real state, not a generic error.
        $result = build_payment_search_result($conn, $service_date, $queue_number);
        echo json_encode(['ok' => false] + $result);
    }
    exit();
}

// Ticket printing: PRINT NEXT NUMBER and Reprint. JSON rather than
// POST-Redirect-GET so the browser can print the ticket (printTicket() below)
// right after the number is issued, then reload. The reload shows the flash
// message set here.
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && isset($_POST['issue_ticket'])) {
    header('Content-Type: application/json');
    if (!$is_ticket_station) {
        echo json_encode(['ok' => false, 'error' => 'This PC is not set up as the ticket station (see Printer Setup).']);
        exit();
    }
    $post_encoder = trim($_POST['encoder_name'] ?? '');
    $ticket = issue_next_number($conn, $post_encoder, INTERVIEW_WINDOW);
    if (!$ticket) {
        echo json_encode(['ok' => false, 'error' => 'Could not issue a number. Please try again.']);
        exit();
    }
    $queue_number = (int) $ticket['queue_number'];
    $print = get_setting($conn, 'ticket_printing_enabled', '1') === '1';
    if ($print) {
        $_SESSION['flash_success'] = "Ticket #$queue_number is printing";
    } else {
        $_SESSION['flash_notice'] = "Number $queue_number issued. Ticket printing is OFF, so write the number on a slip for the patient.";
    }
    echo json_encode(['ok' => true, 'error' => '', 'id' => (int) $ticket['id'], 'queue_number' => $queue_number, 'print' => $print]);
    exit();
}

if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && isset($_POST['reprint_ticket'])) {
    header('Content-Type: application/json');
    if (!$is_ticket_station) {
        echo json_encode(['ok' => false, 'error' => 'This PC is not set up as the ticket station (see Printer Setup).']);
        exit();
    }
    if (get_setting($conn, 'ticket_printing_enabled', '1') !== '1') {
        echo json_encode(['ok' => false, 'error' => 'Ticket printing is OFF (see Printer Setup).']);
        exit();
    }
    $post_encoder = trim($_POST['encoder_name'] ?? '');
    $ticket = find_queue_row($conn, (int) ($_POST['id'] ?? 0));
    if (!$ticket || $ticket['service_date'] !== service_date_now($conn)) {
        echo json_encode(['ok' => false, 'error' => 'Only tickets issued today can be reprinted.']);
        exit();
    }
    $queue_number = (int) $ticket['queue_number'];
    log_activity($conn, $post_encoder, INTERVIEW_WINDOW, $queue_number, 'reprint_ticket');
    $_SESSION['flash_success'] = "Ticket #$queue_number reprinted";
    echo json_encode(['ok' => true, 'error' => '', 'id' => (int) $ticket['id'], 'queue_number' => $queue_number]);
    exit();
}

// ---------- POST handlers (POST-Redirect-GET per CLAUDE.md §4) ----------

if (isset($_POST['call_next'])) {
    $post_encoder = trim($_POST['encoder_name']);
    $ticket = call_next_interview($conn, INTERVIEW_WINDOW, $post_encoder);
    if ($ticket) {
        // display.php builds the spoken sentence from type + station.
        announce_call($ticket['queue_number'], 'interview', INTERVIEW_WINDOW);
    } else {
        // Nothing waiting (or another request won the claim race) — flash a one-shot
        // notice so the encoder can tell "nobody's waiting" apart from "it's broken".
        $_SESSION['flash_notice'] = 'No one is waiting to be called.';
    }
    header('Location: ' . redirect_url($post_encoder));
    exit();
}

if (isset($_POST['for_payment'])) {
    $post_encoder = trim($_POST['encoder_name']);
    complete_interview($conn, (int) $_POST['id'], true, $post_encoder);
    header('Location: ' . redirect_url($post_encoder));
    exit();
}

if (isset($_POST['no_charge'])) {
    $post_encoder = trim($_POST['encoder_name']);
    complete_interview($conn, (int) $_POST['id'], false, $post_encoder);
    header('Location: ' . redirect_url($post_encoder));
    exit();
}

if (isset($_POST['recall'])) {
    $id = (int) $_POST['id'];
    $post_encoder = trim($_POST['encoder_name']);
    $ticket = find_queue_row($conn, $id);
    $ok = recall($conn, $id, $post_encoder);
    if ($ok && $ticket) {
        announce_call($ticket['queue_number'], 'interview', INTERVIEW_WINDOW);
    }
    header('Location: ' . redirect_url($post_encoder));
    exit();
}

if (isset($_POST['no_show'])) {
    $post_encoder = trim($_POST['encoder_name']);
    mark_no_show($conn, (int) $_POST['id'], $post_encoder);
    header('Location: ' . redirect_url($post_encoder));
    exit();
}

if (isset($_POST['save_notes'])) {
    $id = (int) $_POST['id'];
    $post_encoder = trim($_POST['encoder_name']);
    $notes = trim($_POST['notes'] ?? '');
    $stmt = $conn->prepare("UPDATE queue SET notes = ? WHERE id = ?");
    $stmt->bind_param('si', $notes, $id);
    $stmt->execute();
    $stmt->close();
    header('Location: ' . redirect_url($post_encoder));
    exit();
}

// ---------- render ----------

$notice_message = '';
if (isset($_SESSION['flash_notice'])) {
    $notice_message = $_SESSION['flash_notice'];
    unset($_SESSION['flash_notice']);
}
$success_message = '';
if (isset($_SESSION['flash_success'])) {
    $success_message = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

$has_identity = $encoder_name !== '';
$show_name_card = !$has_identity || isset($_GET['change']);
$service_date = service_date_now($conn);

if ($show_name_card) {
    $recent_names = get_recent_staff_names($conn);
} else {
    $waiting_list = get_interview_queue($conn, $service_date);
    $awaiting_payment_list = get_awaiting_payment($conn, $service_date);
    $next_number = next_suggested_number($conn, $service_date);
    $recall_limit = (int) get_setting($conn, 'recall_limit', 3);

    $window = INTERVIEW_WINDOW;
    $stmt = $conn->prepare(
        "SELECT * FROM queue WHERE service_date = ? AND interview_station = ? AND status = 'interviewing' LIMIT 1"
    );
    $stmt->bind_param('si', $service_date, $window);
    $stmt->execute();
    $current_ticket = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    $ticket_printing_enabled = get_setting($conn, 'ticket_printing_enabled', '1') === '1';
    $last_issued = get_last_issued($conn, $service_date);
    $can_reprint = $is_ticket_station && $ticket_printing_enabled;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Laboratory Queueing — Front Desk</title>
<link rel="stylesheet" href="assets/theme.css?v=<?= filemtime(__DIR__ . '/assets/theme.css') ?>">
<style>
    .station-note { margin: 0 0 16px; font-size: 0.85rem; color: var(--text-muted); }
    .station-note a { color: var(--green-dark); font-weight: 700; }
    #findBox .alert { margin: 10px 0 0; text-align: left; }
    .find-result { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 10px; padding: 10px 12px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-sm); font-weight: 700; }
</style>
</head>
<body>
    <?php render_app_header('front_desk', $show_name_card ? '' : $encoder_name, 'index.php?change=1'); ?>
    <div class="page">
        <?php if ($success_message !== ''): ?>
        <!-- Pop-up confirmation (top-right) instead of a banner: printing a
             ticket should feel like a quick receipt, not a document job. -->
        <div class="toast-stack" aria-live="polite">
            <div class="toast" role="status" data-autohide="5000">
                <span class="toast-icon">&#10003;</span>
                <div><span class="toast-title"><?= htmlspecialchars($success_message) ?></span></div>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($notice_message !== ''): ?>
            <div class="alert alert-notice" data-autohide><?= htmlspecialchars($notice_message) ?></div>
        <?php endif; ?>

        <?php if ($show_name_card): ?>
        <div class="name-card">
            <h2>Who's at the front desk?</h2>
            <p>Type or pick your name. It's saved with every patient you serve.</p>
            <form method="get">
                <input class="field" type="text" name="encoder_name" list="recentNames" value="<?= htmlspecialchars($encoder_name) ?>" placeholder="Your name" autocomplete="off" required autofocus>
                <button type="submit" class="btn">Start</button>
            </form>
            <datalist id="recentNames">
                <?php foreach ($recent_names as $name): ?>
                <option value="<?= htmlspecialchars($name) ?>">
                <?php endforeach; ?>
            </datalist>
        </div>
        <?php else: ?>

        <?php if ($is_ticket_station): ?>
        <div class="ticket-bar no-print">
            <button type="button" id="issueTicketBtn" class="btn btn-ticket" data-key="T"><?= $ticket_printing_enabled ? 'PRINT NEXT NUMBER' : 'ISSUE NEXT NUMBER (printing off)' ?><kbd>T</kbd></button>
            <span class="next-hint">Next number: <strong><?= (int) $next_number ?></strong></span>
            <?php if ($can_reprint && $last_issued): ?>
            <button type="button" class="btn btn-outline btn-sm reprint-btn" data-id="<?= (int) $last_issued['id'] ?>">Reprint #<?= (int) $last_issued['queue_number'] ?></button>
            <?php endif; ?>
        </div>
        <div id="ticketError" class="alert alert-error no-print" style="display:none;"></div>
        <?php else: ?>
        <p class="station-note no-print">Queue numbers are printed at the ticket station PC. <a href="printer_setup.php">Printer setup</a></p>
        <?php endif; ?>

        <div class="work-area">
            <section class="hero-card" aria-label="Now serving">
                <div class="hero-label">Now Serving</div>
                <?php if ($current_ticket): $called_mins = elapsed_minutes($current_ticket['interview_called_at']); $recalls = (int) $current_ticket['recall_count']; ?>
                    <div class="hero-number"><?= htmlspecialchars($current_ticket['queue_number']) ?></div>
                    <div class="hero-meta">
                        Called <?= $called_mins !== null && $called_mins > 0 ? $called_mins . ' min ago' : 'just now' ?><?= $recalls > 0 ? ' · called again ' . $recalls . '×' : '' ?>
                    </div>

                    <div class="hero-actions">
                        <form method="post">
                            <input type="hidden" name="id" value="<?= (int) $current_ticket['id'] ?>">
                            <input type="hidden" name="encoder_name" value="<?= htmlspecialchars($encoder_name) ?>">
                            <button type="submit" name="for_payment" class="btn btn-warning btn-big" data-key="P"><span>FOR PAYMENT<kbd>P</kbd></span><small>Pays at City Hall first</small></button>
                        </form>
                        <form method="post">
                            <input type="hidden" name="id" value="<?= (int) $current_ticket['id'] ?>">
                            <input type="hidden" name="encoder_name" value="<?= htmlspecialchars($encoder_name) ?>">
                            <button type="submit" name="no_charge" class="btn btn-big" data-key="C"><span>NO CHARGE<kbd>C</kbd></span><small>Goes straight to extraction</small></button>
                        </form>
                    </div>

                    <div class="hero-more">
                        <form method="post">
                            <input type="hidden" name="id" value="<?= (int) $current_ticket['id'] ?>">
                            <input type="hidden" name="encoder_name" value="<?= htmlspecialchars($encoder_name) ?>">
                            <button type="submit" name="recall" class="btn btn-outline btn-sm" data-key="R" <?= $recalls >= $recall_limit ? 'disabled title="Called ' . $recalls . ' times already. Mark as No Show if they are not here."' : '' ?>>Call again<kbd>R</kbd></button>
                        </form>
                        <button type="button" class="btn btn-outline btn-sm" data-key="S" data-open-dialog="noShowDialog">No show<kbd>S</kbd></button>
                        <button type="button" class="btn btn-outline btn-sm" id="noteToggle"><?= ($current_ticket['notes'] ?? '') !== '' ? 'Edit note' : 'Add note' ?></button>
                    </div>

                    <?php if (($current_ticket['notes'] ?? '') !== ''): ?>
                    <div class="hero-note" id="noteText">Note: <?= htmlspecialchars($current_ticket['notes']) ?></div>
                    <?php endif; ?>
                    <form method="post" class="note-form" id="noteForm" hidden>
                        <input type="hidden" name="id" value="<?= (int) $current_ticket['id'] ?>">
                        <input type="hidden" name="encoder_name" value="<?= htmlspecialchars($encoder_name) ?>">
                        <input class="field" type="text" name="notes" placeholder="Note for this patient" value="<?= htmlspecialchars($current_ticket['notes'] ?? '') ?>" autocomplete="off">
                        <button type="submit" name="save_notes" class="btn btn-sm">Save</button>
                    </form>

                    <div id="noShowDialog" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="noShowTitle">
                        <div class="modal-box dialog-box">
                            <h3 id="noShowTitle">Mark #<?= htmlspecialchars($current_ticket['queue_number']) ?> as No Show?</h3>
                            <p>Use this when the patient didn't come after being called.</p>
                            <form method="post" class="dialog-actions">
                                <input type="hidden" name="id" value="<?= (int) $current_ticket['id'] ?>">
                                <input type="hidden" name="encoder_name" value="<?= htmlspecialchars($encoder_name) ?>">
                                <button type="button" class="btn btn-outline" data-close-dialog>Cancel</button>
                                <button type="submit" name="no_show" class="btn btn-warning">Yes, No Show</button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="hero-empty">No patient at the window.</div>
                    <form method="post" id="callNextForm">
                        <input type="hidden" name="encoder_name" value="<?= htmlspecialchars($encoder_name) ?>">
                        <?php if (empty($waiting_list)): ?>
                        <button type="submit" name="call_next" class="btn btn-big" disabled><span>CALL NEXT</span><small>No one waiting</small></button>
                        <?php else: $first_mins = elapsed_minutes($waiting_list[0]['created_at']); ?>
                        <button type="submit" name="call_next" class="btn btn-big" data-key="N">
                            <span>CALL NEXT<kbd>N</kbd></span>
                            <small><?= count($waiting_list) ?> waiting · next is #<?= (int) $waiting_list[0]['queue_number'] ?><?= $first_mins !== null ? ' (' . $first_mins . ' min)' : '' ?></small>
                        </button>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </section>

            <aside>
                <section class="side-panel" aria-label="Waiting">
                    <h3>Waiting <span class="count"><?= count($waiting_list) ?></span></h3>
                    <?php if (empty($waiting_list)): ?>
                    <div class="empty-note">No one waiting.</div>
                    <?php else: ?>
                    <div class="chip-list" id="waitingChips">
                        <?php foreach ($waiting_list as $i => $row):
                            $mins = elapsed_minutes($row['created_at']);
                            $tint = $mins !== null && $mins >= 30 ? ' priority' : ($mins !== null && $mins >= 15 ? ' pending' : '');
                            $extra = $i >= WAITING_VISIBLE_LIMIT ? ' extra' : '';
                            $mins_text = $mins !== null ? $mins . 'm' : '';
                        ?>
                        <?php if ($can_reprint): ?>
                        <button type="button" class="chip waiting-chip<?= $tint . $extra ?>" data-id="<?= (int) $row['id'] ?>" data-number="<?= (int) $row['queue_number'] ?>" data-mins="<?= (int) $mins ?>"><?= (int) $row['queue_number'] ?><small><?= $mins_text ?></small></button>
                        <?php else: ?>
                        <span class="chip<?= $tint . $extra ?>"><?= (int) $row['queue_number'] ?><small><?= $mins_text ?></small></span>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($waiting_list) > WAITING_VISIBLE_LIMIT): ?>
                    <button type="button" class="link-button" id="waitingMoreBtn">+<?= count($waiting_list) - WAITING_VISIBLE_LIMIT ?> more</button>
                    <?php endif; ?>
                    <?php if ($can_reprint): ?>
                    <p class="hint">Tap a number to reprint that patient's ticket.</p>
                    <?php endif; ?>
                    <?php endif; ?>
                </section>

                <section class="side-panel" aria-label="Back from payment">
                    <h3>Back from Payment <span class="count"><?= count($awaiting_payment_list) ?></span></h3>
                    <?php if (empty($awaiting_payment_list)): ?>
                    <div class="empty-note">No one is away paying.</div>
                    <?php else: ?>
                    <div class="chip-list">
                        <?php foreach ($awaiting_payment_list as $row): $mins = elapsed_minutes($row['interview_completed_at']); ?>
                        <button type="button" class="chip pay-chip<?= $mins !== null && $mins >= 45 ? ' priority' : ' pending' ?>" data-id="<?= (int) $row['id'] ?>" data-number="<?= (int) $row['queue_number'] ?>" data-mins="<?= (int) $mins ?>"><?= (int) $row['queue_number'] ?><small><?= $mins !== null ? $mins . 'm away' : '' ?></small></button>
                        <?php endforeach; ?>
                    </div>
                    <p class="hint">Tap the patient's number when they come back with their receipt.</p>
                    <?php endif; ?>
                    <button type="button" class="link-button" id="findToggle">Can't find the number?</button>
                    <div id="findBox" hidden>
                        <div class="inline-row">
                            <input class="field" type="number" id="paymentSearchInput" placeholder="Type the queue number" min="1" autocomplete="off">
                        </div>
                        <div id="paymentResultBox"></div>
                    </div>
                </section>
            </aside>
        </div>

        <div id="payDialog" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="payDialogTitle">
            <div class="modal-box dialog-box">
                <h3 id="payDialogTitle">Confirm payment</h3>
                <div class="dialog-number" id="payDialogNumber"></div>
                <p id="payDialogMeta"></p>
                <form id="payDialogForm">
                    <input class="field" type="text" name="payment_reference" placeholder="OR number (optional)" autocomplete="off">
                    <div class="dialog-actions">
                        <button type="button" class="btn btn-outline" data-close-dialog>Cancel</button>
                        <button type="submit" class="btn">Confirm Payment</button>
                    </div>
                </form>
                <div id="payDialogError" class="alert alert-error" hidden></div>
            </div>
        </div>

        <?php if ($can_reprint): ?>
        <div id="waitingDialog" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="waitingDialogTitle">
            <div class="modal-box dialog-box">
                <h3 id="waitingDialogTitle">Waiting ticket</h3>
                <div class="dialog-number" id="waitingDialogNumber"></div>
                <p id="waitingDialogMeta"></p>
                <div class="dialog-actions">
                    <button type="button" class="btn btn-outline" data-close-dialog>Close</button>
                    <button type="button" class="btn reprint-btn" id="waitingDialogReprint">Reprint ticket</button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>

    <script src="assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>"></script>
    <?php if (!$show_name_card): ?>
    <script>
    // Set while a ticket is being issued/printed (see printTicket() below).
    // Issuing a number is itself a queue change, so the poller must not reload
    // the page mid-print; printTicket() reloads when it's done.
    let ticketPrintBusy = false;
    LabUI.startPolling({ interval: 3000, holdWhile: function() { return ticketPrintBusy; } });

    const currentEncoderName = <?= json_encode($encoder_name) ?>;

    // Set by printTicket() when Chrome showed its print window instead of
    // printing silently (see the note there).
    (function() {
        let dialogShown = false;
        try {
            dialogShown = sessionStorage.getItem('labPrintDialogShown') === '1';
            sessionStorage.removeItem('labPrintDialogShown');
        } catch (e) {}
        if (dialogShown) {
            LabUI.toast('Print window appeared',
                ' To print tickets instantly with no window, open the Front Desk from the "Lab Queue – Front Desk" desktop shortcut (see Printer).',
                'warning', 15000);
        }
    })();

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : s;
        return d.innerHTML;
    }

    function postAction(fields) {
        const body = new URLSearchParams();
        Object.keys(fields).forEach(function(k) { body.set(k, fields[k]); });
        body.set('encoder_name', currentEncoderName);
        return fetch('', { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r) { return r.json(); });
    }

    // --- Ticket printing (XP-80T on the ticket station PC) ---
    // PRINT NEXT NUMBER issues the number server-side (issue_ticket), then
    // printTicket() loads ticket_print.php into an off-screen iframe and prints
    // it. The ticket station's Chrome runs with --kiosk-printing, so print()
    // goes straight to the default printer (the XP-80T) with no dialog — see
    // printer_setup.php's checklist.
    (function() {
        const issueBtn = document.getElementById('issueTicketBtn');
        const errorBox = document.getElementById('ticketError');
        const issueBtnHtml = issueBtn ? issueBtn.innerHTML : '';

        function printTicket(id, reprint) {
            // Off-screen rather than display:none — Chrome prints a
            // display:none iframe as a blank page.
            let frame = document.getElementById('ticketPrintFrame');
            if (!frame) {
                frame = document.createElement('iframe');
                frame.id = 'ticketPrintFrame';
                frame.setAttribute('aria-hidden', 'true');
                frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:80mm;height:200mm;border:0;';
                document.body.appendChild(frame);
            }
            let finished = false;
            function done() {
                if (finished) return;
                finished = true;
                location.reload();
            }
            frame.onload = function() {
                const doc = frame.contentDocument;
                // A new iframe's initial empty document can fire its own load
                // event — wait for ticket_print.php itself.
                if (doc && doc.URL === 'about:blank') return;
                // ticket_print.php marks a stale/unknown ticket non-printable.
                if (!doc || !doc.body || doc.body.dataset.printable !== '1') {
                    done();
                    return;
                }
                frame.contentWindow.addEventListener('afterprint', function() { setTimeout(done, 300); });
                frame.contentWindow.focus();
                // With --kiosk-printing, print() hands the ticket straight to the
                // XP-80T and returns at once. If it blocked for a while, Chrome
                // showed its print window instead — remember that so the page can
                // explain how to get silent printing after it reloads.
                const started = Date.now();
                frame.contentWindow.print();
                if (Date.now() - started > 1500) {
                    try { sessionStorage.setItem('labPrintDialogShown', '1'); } catch (e) {}
                }
                setTimeout(done, 2000);
            };
            // Safety net: never leave the console stuck if the ticket page fails to load.
            setTimeout(done, 10000);
            frame.src = 'ticket_print.php?id=' + encodeURIComponent(id) + (reprint ? '&reprint=1' : '') + '&t=' + Date.now();
        }

        function showTicketError(msg) {
            ticketPrintBusy = false;
            LabUI.closeDialog(document.querySelector('.modal-overlay.open'));
            if (errorBox) {
                errorBox.textContent = msg;
                errorBox.style.display = 'block';
            }
            if (issueBtn) {
                issueBtn.disabled = false;
                issueBtn.innerHTML = issueBtnHtml;
            }
        }

        if (issueBtn) {
            issueBtn.addEventListener('click', function() {
                // One click = one number: a double-click or a held-down T key
                // lands here again while busy and is ignored.
                if (ticketPrintBusy) return;
                ticketPrintBusy = true;
                issueBtn.disabled = true;
                issueBtn.textContent = <?= json_encode(($ticket_printing_enabled ?? true) ? 'Printing…' : 'Issuing…') ?>;
                if (errorBox) errorBox.style.display = 'none';
                postAction({ issue_ticket: '1' })
                    .then(function(res) {
                        if (!res.ok) {
                            showTicketError(res.error || 'Could not issue a number. Please try again.');
                        } else if (res.print) {
                            printTicket(res.id, false);
                        } else {
                            location.reload();
                        }
                    })
                    .catch(function() {
                        showTicketError('Network error. The number may or may not have been issued, so check the waiting list before trying again.');
                    });
            });
        }

        // Reprint #N (ticket bar) and Reprint ticket (waiting number pop-up).
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.reprint-btn');
            if (!btn || ticketPrintBusy) return;
            ticketPrintBusy = true;
            btn.disabled = true;
            if (errorBox) errorBox.style.display = 'none';
            postAction({ reprint_ticket: '1', id: btn.dataset.id })
                .then(function(res) {
                    if (res.ok) {
                        printTicket(res.id, true);
                    } else {
                        btn.disabled = false;
                        showTicketError(res.error || 'Could not reprint this ticket.');
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                    showTicketError('Network error. Please try the reprint again.');
                });
        });
    })();

    // --- Waiting numbers: "+N more", and tap a number to reprint its ticket ---
    (function() {
        const chips = document.getElementById('waitingChips');
        const moreBtn = document.getElementById('waitingMoreBtn');
        if (chips && moreBtn) {
            const moreLabel = moreBtn.textContent;
            moreBtn.addEventListener('click', function() {
                const expanded = chips.classList.toggle('show-all');
                moreBtn.textContent = expanded ? 'Show fewer' : moreLabel;
            });
        }
        const reprintBtn = document.getElementById('waitingDialogReprint');
        document.querySelectorAll('.waiting-chip').forEach(function(chip) {
            chip.addEventListener('click', function() {
                document.getElementById('waitingDialogNumber').textContent = chip.dataset.number;
                document.getElementById('waitingDialogMeta').textContent = 'Waiting ' + chip.dataset.mins + ' min';
                reprintBtn.dataset.id = chip.dataset.id;
                reprintBtn.disabled = false;
                LabUI.openDialog('waitingDialog');
            });
        });
    })();

    // --- Back from Payment: tap a number (or find it), then Confirm ---
    (function() {
        const dialogNumber = document.getElementById('payDialogNumber');
        const dialogMeta = document.getElementById('payDialogMeta');
        const form = document.getElementById('payDialogForm');
        const errorBox = document.getElementById('payDialogError');
        let current = null;

        function openPayDialog(id, number, awayMinutes) {
            current = { id: id, number: number };
            dialogNumber.textContent = number;
            dialogMeta.textContent = awayMinutes !== null && awayMinutes !== '' ? 'Away ' + awayMinutes + ' min' : '';
            form.reset();
            errorBox.hidden = true;
            form.querySelector('button[type=submit]').disabled = false;
            LabUI.openDialog('payDialog');
        }

        document.querySelectorAll('.pay-chip').forEach(function(chip) {
            chip.addEventListener('click', function() {
                openPayDialog(chip.dataset.id, chip.dataset.number, chip.dataset.mins);
            });
        });

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            if (!current) return;
            const btn = form.querySelector('button[type=submit]');
            btn.disabled = true;
            postAction({
                payment_confirm: '1',
                id: current.id,
                queue_number: current.number,
                payment_reference: form.querySelector('input[name=payment_reference]').value,
            }).then(function(res) {
                if (res.ok) {
                    location.reload(); // the success message comes back as a flash
                } else {
                    errorBox.textContent = res.message || 'This number could not be confirmed.';
                    errorBox.hidden = false;
                    btn.disabled = false;
                }
            }).catch(function() {
                errorBox.textContent = 'Network error. Please try again.';
                errorBox.hidden = false;
                btn.disabled = false;
            });
        });

        // "Can't find the number?" — the old payment search, now tucked away.
        const findToggle = document.getElementById('findToggle');
        const findBox = document.getElementById('findBox');
        const input = document.getElementById('paymentSearchInput');
        const resultBox = document.getElementById('paymentResultBox');
        let debounceTimer = null;

        findToggle.addEventListener('click', function() {
            findBox.hidden = !findBox.hidden;
            if (!findBox.hidden) input.focus();
        });

        function renderResult(data, n) {
            if (!data.found) {
                resultBox.innerHTML = '<div class="alert alert-error">' + escapeHtml(data.message || ('No number ' + n + ' issued today.')) + '</div>';
                return;
            }
            const row = data.row;
            if (data.confirmable) {
                resultBox.innerHTML = '<div class="find-result"><span>#' + row.queue_number
                    + (row.away_minutes !== null ? ' · away ' + row.away_minutes + ' min' : '') + '</span>'
                    + '<button type="button" class="btn btn-sm" id="findConfirmBtn">Confirm payment</button></div>';
                document.getElementById('findConfirmBtn').addEventListener('click', function() {
                    openPayDialog(row.id, row.queue_number, row.away_minutes);
                });
            } else {
                resultBox.innerHTML = '<div class="alert alert-notice">' + escapeHtml(data.message) + '</div>';
            }
        }

        function doSearch() {
            const n = parseInt(input.value, 10);
            if (!n || n <= 0) { resultBox.innerHTML = ''; return; }
            postAction({ payment_search: '1', queue_number: n })
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
    })();

    // --- Add / edit note (hidden until asked for) ---
    (function() {
        const toggle = document.getElementById('noteToggle');
        const form = document.getElementById('noteForm');
        if (!toggle || !form) return;
        toggle.addEventListener('click', function() {
            form.hidden = false;
            const noteText = document.getElementById('noteText');
            if (noteText) noteText.hidden = true;
            toggle.hidden = true;
            form.querySelector('input[name=notes]').focus();
        });
    })();

    // Focus the most likely next action after every reload, so Enter and the
    // keyboard letters work without a mouse click first.
    (function() {
        const target = document.querySelector('button[name=for_payment]')
            || document.getElementById('issueTicketBtn')
            || document.querySelector('button[name=call_next]:not([disabled])');
        if (target) target.focus();
    })();
    </script>
    <?php endif; ?>
</body>
</html>
