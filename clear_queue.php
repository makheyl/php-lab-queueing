<?php
/*
 * Deletes ALL rows from the `queue` table — every service_date, every
 * status, no filter. This is NOT the same as daily_reset.php, which only
 * archives/closes out the day that just ended and purges old rows by
 * settings.queue_retention_days; this script wipes everything, immediately.
 *
 * Meant to be run from Task Scheduler / cron for a full manual reset, not
 * hit by URL — but it lives in the web root like every other file here, so
 * a browser visit requires typing a confirmation phrase first. CLI runs
 * (Task Scheduler/cron) are NOT prompted, so scheduling this still works
 * unattended.
 *
 * Windows Task Scheduler:
 *   Program:   C:\xampp\php\php.exe
 *   Arguments: C:\xampp\htdocs\PHPLabQueueing\clear_queue.php
 *   Start in:  C:\xampp\htdocs\PHPLabQueueing
 *
 * Linux cron:
 *   0 0 * * * /usr/bin/php /path/to/PHPLabQueueing/clear_queue.php
 */

require 'config.php';

const CONFIRM_PHRASE = 'DELETE ALL';

function do_clear($conn) {
    $count_before = $conn->query('SELECT COUNT(*) AS cnt FROM queue')->fetch_assoc()['cnt'];
    $conn->query('DELETE FROM queue');
    $stmt = $conn->prepare("INSERT INTO lab_activity_log (staff_name, station, queue_number, action) VALUES (?, 0, 0, 'clear_queue_full_wipe')");
    $source = PHP_SAPI === 'cli' ? 'cli' : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $staff = "system ($source)";
    $stmt->bind_param('s', $staff);
    $stmt->execute();
    $stmt->close();
    return (int) $count_before;
}

if (PHP_SAPI === 'cli') {
    $deleted = do_clear($conn);
    echo "Cleared $deleted row(s) from queue.\n";
    exit();
}

// --- HTTP access: require typing the confirmation phrase first ---

if (isset($_POST['confirm_phrase'])) {
    if ($_POST['confirm_phrase'] === CONFIRM_PHRASE) {
        $deleted = do_clear($conn);
        echo '<!doctype html><html><body style="font-family:Segoe UI,sans-serif;text-align:center;padding:60px 20px;">'
           . '<h2>Cleared.</h2><p>' . $deleted . ' row(s) deleted from the queue table.</p></body></html>';
        exit();
    }
    $error = 'Phrase did not match — nothing was deleted.';
}

$row_count = $conn->query('SELECT COUNT(*) AS cnt FROM queue')->fetch_assoc()['cnt'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Clear Queue — Laboratory Queueing</title>
<link rel="stylesheet" href="assets/theme.css">
</head>
<body>
    <div class="app-header">
        <img src="CHO.png" alt="CHO Logo" class="logo-img">
        <div class="title">Laboratory Queueing</div>
        <div class="subtitle">Clear Queue</div>
    </div>
    <div class="page" style="max-width:480px;">
        <div class="alert alert-error">
            This deletes ALL <?= (int) $row_count ?> row(s) currently in the <code>queue</code> table —
            every service date, every status. This is not the daily reset; there is no undo.
        </div>
        <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post" class="toolbar-form" style="flex-direction:column; align-items:stretch;">
            <label for="confirm_phrase">Type <strong><?= htmlspecialchars(CONFIRM_PHRASE) ?></strong> to confirm</label>
            <input class="field" type="text" id="confirm_phrase" name="confirm_phrase" autocomplete="off" autofocus required>
            <button type="submit" class="btn btn-danger">Delete Everything</button>
        </form>
    </div>
</body>
</html>
