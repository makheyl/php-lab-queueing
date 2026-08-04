<?php
require 'config.php';
require 'queue_functions.php';

$service_date = service_date_now($conn);
$flash_duration_seconds = (int) get_setting($conn, 'flash_duration_seconds', 10);
$announcement = get_setting($conn, 'announcement', '');

function count_status($conn, $service_date, $status) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM queue WHERE service_date = ? AND status = ?");
    $stmt->bind_param('ss', $service_date, $status);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) $row['cnt'];
}

$waiting_count = count_status($conn, $service_date, 'waiting');
$in_progress_count = count_status($conn, $service_date, 'interviewing') + count_status($conn, $service_date, 'extracting');
$completed_count = count_status($conn, $service_date, 'completed');

// Most recently called interview ticket — the single number this panel shows.
$stmt = $conn->prepare(
    "SELECT * FROM queue WHERE service_date = ? AND status = 'interviewing' ORDER BY interview_called_at DESC LIMIT 1"
);
$stmt->bind_param('s', $service_date);
$stmt->execute();
$current_interview = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

// There is only one extraction station — the single number this panel shows.
$stmt = $conn->prepare(
    "SELECT * FROM queue WHERE service_date = ? AND status = 'extracting' ORDER BY extraction_called_at DESC LIMIT 1"
);
$stmt->bind_param('s', $service_date);
$stmt->execute();
$current_extraction = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

// NOT ordered by queue_number — true extraction queue order, same as the
// extraction station's own queue. See CLAUDE.md.
$next_up = array_slice(get_extraction_queue($conn, $service_date), 0, 3);

$marquee_seconds = $announcement !== '' ? max(15, (int) round(strlen($announcement) * 0.3)) : 15;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Laboratory Queue Display</title>
<link rel="stylesheet" href="assets/display.css">
<script>
    const FLASH_DURATION_MS = <?= $flash_duration_seconds * 1000 ?>;
    const flashTimers = {};

    function setFlash(el) {
        if (!el) return;
        const key = el.id;
        if (flashTimers[key]) clearTimeout(flashTimers[key]);
        el.classList.add('lab-flashing');
        flashTimers[key] = setTimeout(function() {
            el.classList.remove('lab-flashing');
            delete flashTimers[key];
        }, FLASH_DURATION_MS);
    }

    let lastNotified = 0;
    function pollNotify() {
        fetch('notify.json?rand=' + Math.random())
            .then(function(res) { return res.json(); })
            .then(function(data) {
                let shouldClear = false;
                if (data && data.timestamp && data.timestamp > lastNotified) {
                    lastNotified = data.timestamp;
                    shouldClear = true;

                    const msg = data.type === 'extraction'
                        ? `Number ${data.queue_number}, number ${data.queue_number}, please proceed to extraction`
                        : `Number ${data.queue_number}, number ${data.queue_number}, please proceed to window ${data.station}`;
                    if ('speechSynthesis' in window) {
                        window.speechSynthesis.cancel();
                        const utter = new SpeechSynthesisUtterance(msg);
                        utter.lang = 'en-US';
                        window.speechSynthesis.speak(utter);
                    }

                    if (data.type === 'extraction') {
                        const numEl = document.getElementById('extractionNumber');
                        if (numEl) {
                            numEl.textContent = data.queue_number;
                            setFlash(numEl);
                        }
                    } else {
                        const numEl = document.getElementById('interviewNumber');
                        const stationEl = document.getElementById('interviewStation');
                        if (numEl) {
                            numEl.textContent = data.queue_number;
                            setFlash(numEl);
                        }
                        if (stationEl && data.station) {
                            stationEl.textContent = 'Station ' + data.station;
                        }
                    }
                } else if (data && Object.keys(data).length > 0) {
                    shouldClear = true;
                }
                if (shouldClear) fetch('clear_notify.php?rand=' + Math.random());
            })
            .catch(function() {
                fetch('clear_notify.php?rand=' + Math.random());
            });
        setTimeout(pollNotify, 250);
    }
    document.addEventListener('DOMContentLoaded', pollNotify);

    // Smart refresh on DB update — also catches service_date rollover (see
    // queue_status.php's 'service_date' field), so an idle overnight board
    // still resets without a manual reload.
    let lastStatus = null;
    let reloadPending = false;
    // A queue-status change lands mid-announcement more often than not (call_next
    // writes notify.json and the queue table in the same request), so a bare
    // reload here cuts the SpeechSynthesisUtterance off. Wait for speech to finish.
    function reloadWhenIdle() {
        if ('speechSynthesis' in window && window.speechSynthesis.speaking) {
            setTimeout(reloadWhenIdle, 500);
            return;
        }
        location.reload();
    }
    async function pollQueueStatus() {
        try {
            const res = await fetch('queue_status.php');
            if (!res.ok) return;
            const data = await res.json();
            if (lastStatus === null) {
                lastStatus = JSON.stringify(data);
            } else if (JSON.stringify(data) !== lastStatus && !reloadPending) {
                reloadPending = true;
                reloadWhenIdle();
            }
        } catch (e) {}
        setTimeout(pollQueueStatus, 3000);
    }
    pollQueueStatus();

    function updateClock() {
        const now = new Date();
        const clockEl = document.getElementById('kioskClock');
        const dateEl = document.getElementById('kioskDate');
        if (clockEl) clockEl.textContent = now.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true });
        if (dateEl) dateEl.textContent = now.toLocaleDateString('en-PH', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    }
    updateClock();
    setInterval(updateClock, 1000);
</script>
</head>
<body class="lab-board">
    <div class="kiosk-header">
        <div class="logo"><img src="CHO.png" alt="CHO Logo"></div>
        <div class="title">Laboratory Queueing</div>
    </div>
    <div class="kiosk-body">
        <div class="kiosk-stats">
            <div class="kiosk-stat">
                <span class="label">Waiting</span>
                <span class="value"><?= $waiting_count ?></span>
            </div>
            <div class="kiosk-stat">
                <span class="label">In Progress</span>
                <span class="value"><?= $in_progress_count ?></span>
            </div>
            <div class="kiosk-stat">
                <span class="label">Completed Today</span>
                <span class="value"><?= $completed_count ?></span>
            </div>
        </div>

        <div class="kiosk-columns">
            <div class="kiosk-col waiting-col lab-interview-col">
                <h2>FOR INTERVIEW</h2>
                <div class="interview-display">
                    <?php if ($current_interview): ?>
                    <div class="interview-number" id="interviewNumber"><?= htmlspecialchars($current_interview['queue_number']) ?></div>
                    <div class="interview-station" id="interviewStation">Window <?= htmlspecialchars($current_interview['interview_station']) ?></div>
                    <?php else: ?>
                    <div class="kiosk-empty">Please wait.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="kiosk-col serving-col lab-serving-col">
                <h2>NOW SERVING — EXTRACTION</h2>
                <div class="kiosk-cards">
                    <?php if ($current_extraction): ?>
                    <div class="kiosk-card serving-card">
                        <span class="queue-number" id="extractionNumber"><?= htmlspecialchars($current_extraction['queue_number']) ?></span>
                    </div>
                    <?php else: ?>
                    <div class="kiosk-empty">Please wait.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="kiosk-col pending-col">
                <h2>NEXT FOR EXTRACTION</h2>
                <div class="kiosk-cards next-up-cards">
                    <?php if (empty($next_up)): ?>
                    <div class="kiosk-empty">Please wait.</div>
                    <?php else: foreach ($next_up as $i => $row): ?>
                    <div class="kiosk-card next-up-card<?= $i === 0 ? ' is-next' : '' ?>">
                        <?php if ($i === 0): ?><span class="badge badge-next">NEXT</span><?php endif; ?>
                        <span class="queue-number"><?= htmlspecialchars($row['queue_number']) ?></span>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <div class="kiosk-footer">
            <div class="kiosk-clock" id="kioskClock"></div>
            <div class="kiosk-date" id="kioskDate"></div>
            <?php if ($announcement !== ''): ?>
            <div class="kiosk-announcement-wrap">
                <div class="kiosk-announcement-track" style="animation-duration:<?= $marquee_seconds ?>s;"><?= htmlspecialchars($announcement) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
