<?php
// ticket_print.php
//
// Renders ONE queue ticket sized for the XP-80T's 80 mm roll (72 mm printable
// by default — settings.ticket_width_mm). index.php and printer_setup.php load
// this page into an off-screen iframe and call print() on it. The ticket PC's
// Chrome is started with --kiosk-printing, so the job goes straight to the
// default printer (the XP-80T) with no dialog, and the Windows driver cuts
// after each document. See OPERATIONS.md "Ticket printer (XP-80T)".
//
// Read-only on purpose: issuing numbers and logging reprints happen in
// index.php's AJAX handlers, so reloading or previewing this page never
// changes the queue.
//
//   ?id=N              today's ticket with queue.id = N
//   ?id=N&reprint=1    the same ticket, marked REPRINT
//   ?test=1            sample ticket (000) for printer_setup.php
require 'config.php';
require 'queue_functions.php';
header('Cache-Control: no-store');

$is_test = isset($_GET['test']);
$is_reprint = isset($_GET['reprint']);

$header = trim((string) get_setting($conn, 'ticket_header', 'CITY HEALTH OFFICE'));
$subheader = trim((string) get_setting($conn, 'ticket_subheader', 'LABORATORY'));
$footer = trim((string) get_setting($conn, 'ticket_footer', ''));
$show_logo = get_setting($conn, 'ticket_show_logo', '1') === '1';
$show_waiting = get_setting($conn, 'ticket_show_waiting', '1') === '1';
$width_mm = max(48, min(80, (int) get_setting($conn, 'ticket_width_mm', 72)));

function waiting_ahead_text($n) {
    if ($n === 0) return 'No one is waiting ahead of you';
    if ($n === 1) return '1 person waiting ahead of you';
    return $n . ' people waiting ahead of you';
}

// Only today's tickets are printable — an old or unknown id renders a
// non-printable page (data-printable="0"), which the print helper checks
// before calling print(), so it never wastes paper on an error message.
$printable = false;
$number_text = '';
$issued_text = '';
$waiting_text = null;

if ($is_test) {
    $printable = true;
    $number_text = '000';
    $issued_text = date('D, M j, Y · g:i A');
    $waiting_text = $show_waiting ? waiting_ahead_text(3) : null;
} else {
    $ticket = find_queue_row($conn, (int) ($_GET['id'] ?? 0));
    if ($ticket && $ticket['service_date'] === service_date_now($conn)) {
        $printable = true;
        $number_text = (string) $ticket['queue_number'];
        $issued_text = date('D, M j, Y · g:i A', strtotime($ticket['created_at']));
        // "Ahead of you" only means something while still waiting for interview.
        if ($show_waiting && $ticket['status'] === 'waiting') {
            $waiting_text = waiting_ahead_text(count_waiting_ahead($conn, $ticket));
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= $printable ? 'Ticket ' . htmlspecialchars($number_text) : 'Ticket not available' ?></title>
<style>
    /* Zero page margin: nothing is clipped at the roll edges, and Chrome has no
       margin area left to draw its date/URL header and footer into. */
    @page { margin: 0; }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; background: #fff; color: #000; }
    body {
        width: <?= $width_mm ?>mm;
        padding: 3mm 2mm 4mm;
        font-family: Arial, Helvetica, sans-serif;
        text-align: center;
    }
    /* Thermal paper prints black only — the color logo is grayscaled so the
       driver's dithering stays clean. */
    .logo { display: block; width: 18mm; height: 18mm; margin: 0 auto 1.5mm; object-fit: contain; filter: grayscale(1) contrast(1.3); }
    .header { font-size: 13pt; font-weight: 800; line-height: 1.15; }
    .subheader { font-size: 11pt; font-weight: 700; margin-top: 0.5mm; }
    .rule { border: 0; border-top: 1px dashed #000; margin: 2.5mm 0; }
    .label { font-size: 9pt; font-weight: 700; letter-spacing: 0.15em; }
    .number { font-family: 'Arial Black', Arial, sans-serif; font-size: 64pt; font-weight: 900; line-height: 1; margin: 1mm 0 2mm; }
    .meta { font-size: 9pt; line-height: 1.4; }
    .footer { font-size: 9pt; line-height: 1.35; }
    .mark { font-size: 9pt; font-weight: 800; letter-spacing: 0.1em; margin-top: 2.5mm; }
    .unavailable { font-size: 10pt; padding: 6mm 0; }
</style>
</head>
<body data-printable="<?= $printable ? '1' : '0' ?>">
<?php if (!$printable): ?>
    <div class="unavailable">Ticket not available.</div>
<?php else: ?>
    <?php if ($show_logo): ?>
    <img class="logo" src="CHO.png" alt="">
    <?php endif; ?>
    <?php if ($header !== ''): ?>
    <div class="header"><?= htmlspecialchars($header) ?></div>
    <?php endif; ?>
    <?php if ($subheader !== ''): ?>
    <div class="subheader"><?= htmlspecialchars($subheader) ?></div>
    <?php endif; ?>
    <hr class="rule">
    <div class="label">QUEUE NUMBER</div>
    <div class="number"><?= htmlspecialchars($number_text) ?></div>
    <div class="meta"><?= htmlspecialchars($issued_text) ?></div>
    <?php if ($waiting_text !== null): ?>
    <div class="meta"><?= htmlspecialchars($waiting_text) ?></div>
    <?php endif; ?>
    <?php if ($footer !== ''): ?>
    <hr class="rule">
    <div class="footer"><?= nl2br(htmlspecialchars($footer)) ?></div>
    <?php endif; ?>
    <?php if ($is_test): ?>
    <div class="mark">TEST PRINT – NOT A QUEUE NUMBER</div>
    <?php elseif ($is_reprint): ?>
    <div class="mark">*** REPRINT ***</div>
    <?php endif; ?>
<?php endif; ?>
</body>
</html>
