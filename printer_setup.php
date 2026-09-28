<?php
// printer_setup.php
//
// Setup screen for the XP-80T ticket printer. Tickets print from the browser
// of the ticket station PC (the PC the printer is plugged into by USB), not
// from PHP, because the server can't reach a USB printer on another PC. This
// page:
//   - marks/unmarks THIS browser as the ticket station (a long-lived cookie
//     that index.php checks before showing PRINT NEXT NUMBER),
//   - edits the ticket_* settings,
//   - previews the ticket and runs a Test Print through the same
//     ticket_print.php + off-screen iframe path real tickets use,
//   - shows the one-time Windows/Chrome setup checklist.
// No login, same as every other screen (CLAUDE.md §7).
session_start();
require 'config.php';
require 'queue_functions.php';
require 'app_header.php';

const TICKET_STATION_COOKIE = 'lab_ticket_station'; // index.php reads the same name

// Cookie scoped to this app's folder, e.g. /PHPLabQueueing/
function app_base_path() {
    return rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'])), '/') . '/';
}

// ---------- POST handlers (POST-Redirect-GET per CLAUDE.md §4) ----------

if (isset($_POST['mark_station'])) {
    setcookie(TICKET_STATION_COOKIE, '1', [
        'expires' => time() + 10 * 365 * 24 * 60 * 60,
        'path' => app_base_path(),
        'samesite' => 'Lax',
    ]);
    $_SESSION['setup_flash'] = 'This PC is now the ticket station. PRINT NEXT NUMBER will appear on its Front Desk screen.';
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

if (isset($_POST['unmark_station'])) {
    setcookie(TICKET_STATION_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => app_base_path(),
        'samesite' => 'Lax',
    ]);
    $_SESSION['setup_flash'] = 'This PC is no longer the ticket station.';
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

if (isset($_POST['save_settings'])) {
    $width = filter_var($_POST['ticket_width_mm'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 48, 'max_range' => 80]]);
    if ($width === false) {
        $_SESSION['setup_error'] = 'Print width must be a whole number from 48 to 80 (mm).';
    } else {
        // settings.value is varchar(255)
        $clean = function ($key) {
            $value = str_replace("\r\n", "\n", trim($_POST[$key] ?? ''));
            return mb_substr($value, 0, 255);
        };
        set_setting($conn, 'ticket_printing_enabled', isset($_POST['ticket_printing_enabled']) ? '1' : '0');
        set_setting($conn, 'ticket_header', $clean('ticket_header'));
        set_setting($conn, 'ticket_subheader', $clean('ticket_subheader'));
        set_setting($conn, 'ticket_footer', $clean('ticket_footer'));
        set_setting($conn, 'ticket_show_logo', isset($_POST['ticket_show_logo']) ? '1' : '0');
        set_setting($conn, 'ticket_show_waiting', isset($_POST['ticket_show_waiting']) ? '1' : '0');
        set_setting($conn, 'ticket_width_mm', (string) $width);
        $_SESSION['setup_flash'] = 'Ticket settings saved.';
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// ---------- render ----------

$flash = $_SESSION['setup_flash'] ?? '';
$error = $_SESSION['setup_error'] ?? '';
unset($_SESSION['setup_flash'], $_SESSION['setup_error']);

$is_ticket_station = ($_COOKIE[TICKET_STATION_COOKIE] ?? '') === '1';

$printing_enabled = get_setting($conn, 'ticket_printing_enabled', '1') === '1';
$header = get_setting($conn, 'ticket_header', 'CITY HEALTH OFFICE');
$subheader = get_setting($conn, 'ticket_subheader', 'LABORATORY');
$footer = get_setting($conn, 'ticket_footer', '');
$show_logo = get_setting($conn, 'ticket_show_logo', '1') === '1';
$show_waiting = get_setting($conn, 'ticket_show_waiting', '1') === '1';
$width_mm = max(48, min(80, (int) get_setting($conn, 'ticket_width_mm', 72)));

// The URL the ticket PC's Chrome shortcut should open. Viewed on the server
// itself, HTTP_HOST is "localhost" — the ticket PC needs the server's LAN IP.
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$host_is_local = preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/i', $host) === 1;
$console_url = 'http://' . $host . app_base_path() . 'index.php';
$chrome_target = '"C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk-printing --user-data-dir="C:\LabQueueChrome" --app=' . $console_url;
$edge_target = '"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe" --kiosk-printing --user-data-dir="C:\LabQueueEdge" --app=' . $console_url;

// Display board: full screen, and allowed to speak without anyone tapping it.
$display_url = 'http://' . $host . app_base_path() . 'display.php';
$display_target = '"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe" --kiosk --autoplay-policy=no-user-gesture-required --user-data-dir="C:\LabQueueDisplay" ' . $display_url;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Laboratory Queueing — Printer Setup</title>
<link rel="stylesheet" href="assets/theme.css?v=<?= filemtime(__DIR__ . '/assets/theme.css') ?>">
<style>
    .setup-title { text-align: center; color: var(--green-dark); font-size: 1.6rem; margin-bottom: 8px; }
    .setup-lead { text-align: center; color: var(--text-muted); font-size: 0.92rem; margin: 0 auto 8px; max-width: 760px; line-height: 1.5; }
    .setup-section { margin-top: 22px; padding: 22px; background: var(--surface-alt); border: 1px solid var(--border); border-radius: var(--radius-lg); }
    .setup-section h3 { color: var(--green-dark); margin-bottom: 12px; font-size: 1.05rem; }
    .setup-section p { margin: 0 0 12px; color: var(--text-muted); font-size: 0.92rem; line-height: 1.5; }
    .station-status { font-weight: 700; font-size: 1rem; margin-bottom: 12px; }
    .station-status.is-station { color: var(--green-dark); }
    .station-status.not-station { color: var(--orange-dark); }
    .setup-columns { display: flex; gap: 22px; flex-wrap: wrap; align-items: flex-start; }
    .setup-columns > .setup-section { flex: 1 1 380px; margin-top: 22px; }
    .settings-form { display: grid; grid-template-columns: max-content 1fr; gap: 12px 14px; align-items: center; }
    .settings-form label { font-size: 0.9rem; font-weight: 600; color: var(--text-muted); }
    .settings-form .field { width: 100%; }
    .settings-form textarea.field { resize: vertical; min-height: 70px; }
    .settings-form .check-row { grid-column: 1 / -1; display: flex; align-items: center; gap: 8px; font-size: 0.92rem; font-weight: 600; color: var(--text); }
    .settings-form .form-actions { grid-column: 1 / -1; display: flex; justify-content: flex-end; }
    .settings-form .hint { grid-column: 2; margin-top: -6px; font-size: 0.78rem; color: var(--text-faint); }
    .preview-wrap { display: flex; flex-direction: column; align-items: center; gap: 14px; }
    .preview-frame { background: #fff; border: 1.5px dashed var(--border); border-radius: var(--radius-sm); height: 120mm; }
    #testPrintResult { font-size: 0.88rem; text-align: center; max-width: 420px; }
    .checklist { margin: 0; padding-left: 20px; color: var(--text); font-size: 0.92rem; line-height: 1.6; }
    .checklist li { margin-bottom: 10px; }
    .checklist code, .shortcut-target { font-family: Consolas, 'Courier New', monospace; font-size: 0.82rem; }
    .shortcut-target { display: block; margin: 6px 0; padding: 10px 12px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-sm); word-break: break-all; user-select: all; }
    details.setup-section > summary { cursor: pointer; color: var(--green-dark); font-weight: 700; font-size: 1.05rem; }
    details.setup-section[open] > summary { margin-bottom: 12px; }
    @media (max-width: 900px) {
        .settings-form { grid-template-columns: 1fr; }
        .settings-form .hint { grid-column: 1; }
    }
</style>
</head>
<body>
    <?php render_app_header('printer'); ?>
    <div class="page page-narrow">
        <h1 class="setup-title">Ticket Printer (XP-80T)</h1>
        <p class="setup-lead">
            PRINT NEXT NUMBER on the Front Desk screen issues the next queue number for today and prints it on the
            XP-80T. The printer is plugged into the ticket station PC by USB and prints through that PC's browser.
        </p>

        <?php if ($flash !== ''): ?>
            <div class="alert alert-success"><?= htmlspecialchars($flash) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="setup-section">
            <h3>This PC</h3>
            <?php if ($is_ticket_station): ?>
                <div class="station-status is-station">This browser is the ticket station.</div>
                <p>PRINT NEXT NUMBER and Reprint appear on this browser's Front Desk screen. Every other PC hides them.</p>
                <form method="post">
                    <button type="submit" name="unmark_station" class="btn btn-outline btn-sm">Stop using this PC as the ticket station</button>
                </form>
            <?php else: ?>
                <div class="station-status not-station">This browser is not the ticket station.</div>
                <p>
                    Do this only on the PC the XP-80T is plugged into, in the Chrome window opened from the
                    "Lab Queue – Front Desk" shortcut (see the checklist below). Other PCs cannot issue numbers,
                    so a click there can never print a ticket on the wrong printer.
                </p>
                <form method="post">
                    <button type="submit" name="mark_station" class="btn">Use this PC as the ticket station</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="setup-columns">
            <div class="setup-section">
                <h3>Ticket Settings</h3>
                <form method="post" class="settings-form">
                    <label class="check-row">
                        <input type="checkbox" name="ticket_printing_enabled" value="1" <?= $printing_enabled ? 'checked' : '' ?>>
                        Ticket printing is ON
                    </label>
                    <span class="hint" style="grid-column:1 / -1;margin-top:-8px;">Turn OFF if the printer is broken or out of paper. Numbers are still issued and shown on screen, and staff write them on a slip.</span>

                    <label for="ticket_header">Header</label>
                    <input class="field" type="text" id="ticket_header" name="ticket_header" maxlength="255" value="<?= htmlspecialchars($header) ?>">

                    <label for="ticket_subheader">Sub-header</label>
                    <input class="field" type="text" id="ticket_subheader" name="ticket_subheader" maxlength="255" value="<?= htmlspecialchars($subheader) ?>">

                    <label for="ticket_footer">Footer</label>
                    <textarea class="field" id="ticket_footer" name="ticket_footer" maxlength="255" rows="3"><?= htmlspecialchars($footer) ?></textarea>

                    <label for="ticket_width_mm">Print width (mm)</label>
                    <input class="field" type="number" id="ticket_width_mm" name="ticket_width_mm" min="48" max="80" step="1" value="<?= $width_mm ?>" style="max-width:110px;">
                    <span class="hint">72 fits the XP-80T's printable area. Lower it if the right edge is cut off.</span>

                    <label class="check-row">
                        <input type="checkbox" name="ticket_show_logo" value="1" <?= $show_logo ? 'checked' : '' ?>>
                        Print the CHO logo
                    </label>
                    <label class="check-row">
                        <input type="checkbox" name="ticket_show_waiting" value="1" <?= $show_waiting ? 'checked' : '' ?>>
                        Print how many people are waiting ahead
                    </label>

                    <div class="form-actions">
                        <button type="submit" name="save_settings" class="btn">Save Settings</button>
                    </div>
                </form>
            </div>

            <div class="setup-section">
                <h3>Preview &amp; Test Print</h3>
                <div class="preview-wrap">
                    <iframe id="ticketPreview" class="preview-frame" src="ticket_print.php?test=1" title="Ticket preview" style="width:calc(<?= $width_mm ?>mm + 4px);"></iframe>
                    <?php if ($is_ticket_station): ?>
                        <button type="button" id="testPrintBtn" class="btn">Test Print</button>
                        <div id="testPrintResult"></div>
                    <?php else: ?>
                        <p style="text-align:center;margin:0;">Test Print is available on the ticket station PC only.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Open until this PC is marked as the ticket station; after that it's reference material. -->
        <details class="setup-section" <?= $is_ticket_station ? '' : 'open' ?>>
            <summary>One-time Setup on the Ticket Station PC</summary>
            <p>Nothing is installed on the XAMPP server. Everything below happens on the PC the XP-80T is plugged into.</p>
            <ol class="checklist">
                <li>
                    <strong>Printer.</strong> Plug the XP-80T into this PC by USB and switch it on with its 24 V adapter.
                    Load an 80 mm thermal roll with the coated side facing the print head and close the cover until it clicks.
                    To check the printer itself, hold FEED while switching it on. It prints a self-test page.
                </li>
                <li>
                    <strong>Driver.</strong> Install the official Xprinter 80 mm-series Windows driver, choosing the XP-80 model
                    and the USB port it detects (USB001, USB002…). Name the printer <code>XP-80T</code>. In
                    <em>Printing Preferences</em>, set the paper size to the 80 mm roll (e.g. <code>80(72.1) x 3276 mm</code>,
                    not A4/297 mm) so each ticket is only as long as its content, and set the cutter to cut after each document.
                    Printer properties &rarr; <em>Print Test Page</em> should print a narrow receipt and cut it.
                </li>
                <li>
                    <strong>Default printer.</strong> Settings &rarr; Bluetooth &amp; devices &rarr; Printers &amp; scanners:
                    turn <strong>OFF</strong> "Let Windows manage my default printer", then open XP-80T and choose
                    <em>Set as default</em>. Otherwise Windows switches the default to whichever printer was used last.
                </li>
                <li>
                    <strong>Chrome shortcut.</strong> Create a desktop shortcut named "Lab Queue – Front Desk" with this target:
                    <span class="shortcut-target"><?= htmlspecialchars($chrome_target) ?></span>
                    <?php if ($host_is_local): ?>
                        <em>You're viewing this page on the server itself. On the ticket PC, replace
                        <code><?= htmlspecialchars($host) ?></code> with the server's LAN IP address.</em><br>
                    <?php endif; ?>
                    <code>--kiosk-printing</code> prints straight to the default printer with no dialog.
                    <code>--user-data-dir</code> keeps this app in its own Chrome profile, so the flag always applies and
                    everyday browsing still shows the normal print dialog. Microsoft Edge works the same way:
                    <span class="shortcut-target"><?= htmlspecialchars($edge_target) ?></span>
                    Give the XAMPP server a fixed IP address so the shortcut keeps working.
                </li>
                <li>
                    <strong>Mark and test.</strong> Open the Front Desk with the shortcut, click <em>Printer</em> in the top
                    menu, click <em>Use this PC as the ticket station</em>, then <em>Test Print</em>. A ticket should print and
                    cut with no dialog. If a print dialog appears, close every window of that Chrome profile and reopen it from
                    the shortcut.
                </li>
            </ol>
        </details>

        <details class="setup-section">
            <summary>Display Board PC (waiting-area TV) — voice announcements</summary>
            <p>
                Browsers keep sound off until someone taps the page. Open the display board from this shortcut
                and it runs full screen and announces every number with no tap needed. Make sure the PC's
                speakers are on and the volume is up.
            </p>
            <span class="shortcut-target"><?= htmlspecialchars($display_target) ?></span>
            <?php if ($host_is_local): ?>
                <p><em>On a separate TV PC, replace <code><?= htmlspecialchars($host) ?></code> with the server's LAN IP address.</em></p>
            <?php endif; ?>
            <p>
                If the board is opened any other way, it shows "Tap anywhere to turn on voice announcements" —
                one tap turns the voice on for the rest of the day. To leave full screen, press Alt+F4.
            </p>
        </details>
    </div>

    <script>
    // Size the preview iframe to the ticket's real height.
    (function() {
        const preview = document.getElementById('ticketPreview');
        if (!preview) return;
        preview.addEventListener('load', function() {
            const doc = preview.contentDocument;
            // body, not documentElement: the root is at least as tall as the
            // iframe itself, so measuring it could never shrink the frame.
            if (doc && doc.body) preview.style.height = (doc.body.scrollHeight + 4) + 'px';
        });
    })();

    // Test Print: same off-screen iframe + print() path as index.php's
    // printTicket(), minus the page reload.
    (function() {
        const btn = document.getElementById('testPrintBtn');
        const result = document.getElementById('testPrintResult');
        if (!btn || !result) return;
        btn.addEventListener('click', function() {
            btn.disabled = true;
            result.textContent = 'Printing…';
            let frame = document.getElementById('ticketPrintFrame');
            if (!frame) {
                // Off-screen rather than display:none — Chrome prints a
                // display:none iframe as a blank page.
                frame = document.createElement('iframe');
                frame.id = 'ticketPrintFrame';
                frame.setAttribute('aria-hidden', 'true');
                frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:80mm;height:200mm;border:0;';
                document.body.appendChild(frame);
            }
            frame.onload = function() {
                // A new iframe's initial empty document can fire its own load
                // event — wait for ticket_print.php itself.
                if (frame.contentDocument && frame.contentDocument.URL === 'about:blank') return;
                frame.contentWindow.focus();
                frame.contentWindow.print();
                result.textContent = 'Test ticket sent to the printer. If a print dialog appeared instead, this window was not opened from the "Lab Queue – Front Desk" shortcut.';
                btn.disabled = false;
            };
            frame.src = 'ticket_print.php?test=1&t=' + Date.now();
        });
    })();
    </script>
</body>
</html>
