<?php
require 'config.php';
require 'queue_functions.php';

const CLAIM_SCROLL_THRESHOLD = 6; // 2 columns x 3 rows — fits without scrolling
const CLAIM_SECONDS_PER_ROW = 2.5; // reading pace for the vertical auto-scroll

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
$next_up = array_slice(get_extraction_queue($conn, $service_date), 0, 4);

// Not scoped to service_date — see get_claimable_results()'s docblock.
// Short lists render as a single static grid. Longer lists render as a
// continuous CSS auto-scroll (see .claim-scroll-track below): the ROW list
// (not the raw item list) is duplicated back-to-back and the track animates
// translateY(0 -> -50%) on an infinite linear loop. Duplicating whole rows,
// rather than merging the raw item array into one continuous grid, matters
// when the count is odd — a continuous grid's auto-flow would let the last
// item of copy 1 share a row with the first item of copy 2, shifting every
// row after that in copy 2 out of alignment with copy 1, so -50% would land
// mid-list instead of on a duplicate of the top and the loop would visibly
// jump. Chunking into fixed 2-item rows first, then duplicating THOSE, keeps
// every row an atomic unit that can't be split across the copy boundary, so
// the two halves stay pixel-identical regardless of odd/even count. Same
// marquee technique as the footer announcement further down, just vertical.
$claimable_results = get_claimable_results($conn);
$claim_is_scrolling = count($claimable_results) > CLAIM_SCROLL_THRESHOLD;
if ($claim_is_scrolling) {
    $claim_rows = array_chunk($claimable_results, 2);
    $claim_scroll_seconds = max(10, count($claim_rows) * CLAIM_SECONDS_PER_ROW);
    $claim_scroll_rows_doubled = array_merge($claim_rows, $claim_rows);
}

$marquee_seconds = $announcement !== '' ? max(15, (int) round(strlen($announcement) * 0.3)) : 15;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Laboratory Queue Display</title>
<link rel="stylesheet" href="assets/display.css?v=<?= filemtime(__DIR__ . '/assets/display.css') ?>">
<script>
    const FLASH_DURATION_MS = <?= $flash_duration_seconds * 1000 ?>;

    // ---------- number flash (survives the in-place board refresh) ----------
    const flashUntil = {};
    const flashTimers = {};
    function flashFor(el, ms) {
        const key = el.id;
        if (flashTimers[key]) clearTimeout(flashTimers[key]);
        el.classList.add('lab-flashing');
        flashTimers[key] = setTimeout(function() {
            const current = document.getElementById(key);
            if (current) current.classList.remove('lab-flashing');
            delete flashTimers[key];
            delete flashUntil[key];
        }, ms);
    }
    function setFlash(el) {
        if (!el) return;
        flashUntil[el.id] = Date.now() + FLASH_DURATION_MS;
        flashFor(el, FLASH_DURATION_MS);
    }
    function restoreFlashes() {
        Object.keys(flashUntil).forEach(function(key) {
            const left = flashUntil[key] - Date.now();
            const el = document.getElementById(key);
            if (el && left > 0) flashFor(el, left);
        });
    }

    // ---------- voice announcements ----------
    // notify.json holds the last few calls as a list (see announce_call() in
    // queue_functions.php). This board speaks every call newer than the last
    // one it handled, one after another, and never deletes anything, so calls
    // made seconds apart are all announced and every open board hears them.
    //
    // Browsers only allow speech after someone has clicked the page (unless
    // the board was opened from the Display Board shortcut, whose
    // --autoplay-policy flag lifts that rule). If speech is blocked, a big
    // "tap to turn on voice" prompt appears. One tap lasts all day, because
    // the board refreshes in place instead of reloading.
    const hasSpeech = 'speechSynthesis' in window;
    const speechQueue = [];
    let lastCallId = null;   // null until notify.json has been read once
    let speaking = false;
    let soundBlocked = false;
    let voice = null;

    function pickVoice() {
        if (!hasSpeech) return;
        const voices = window.speechSynthesis.getVoices();
        voice = voices.find(function(v) { return /^en[-_]PH/i.test(v.lang); })
            || voices.find(function(v) { return /^en[-_]US/i.test(v.lang); })
            || voices.find(function(v) { return /^en/i.test(v.lang); })
            || null;
    }
    if (hasSpeech) {
        pickVoice();
        window.speechSynthesis.addEventListener('voiceschanged', pickVoice);
    }

    function sentenceFor(call) {
        return call.type === 'extraction'
            ? 'Number ' + call.queue_number + ', number ' + call.queue_number + ', please proceed to extraction'
            : 'Number ' + call.queue_number + ', number ' + call.queue_number + ', please proceed to window ' + call.station;
    }

    function makeUtterance(text) {
        const utter = new SpeechSynthesisUtterance(text);
        utter.lang = voice ? voice.lang : 'en-US';
        if (voice) utter.voice = voice;
        utter.rate = 0.9;
        return utter;
    }

    // Update the big number on screen for this call (the board's own refresh
    // catches up the rest a moment later).
    function showCall(call) {
        if (call.type === 'extraction') {
            const numEl = document.getElementById('extractionNumber');
            if (numEl) {
                numEl.textContent = call.queue_number;
                setFlash(numEl);
            }
        } else {
            const numEl = document.getElementById('interviewNumber');
            const stationEl = document.getElementById('interviewStation');
            if (numEl) {
                numEl.textContent = call.queue_number;
                setFlash(numEl);
            }
            if (stationEl && call.station) {
                // "Window", matching the page's own render and the voice call.
                stationEl.textContent = 'Window ' + call.station;
            }
        }
    }

    function speakNext() {
        if (speaking || soundBlocked || speechQueue.length === 0) return;
        const call = speechQueue.shift();
        showCall(call);
        if (!hasSpeech) {
            speakNext();
            return;
        }
        speaking = true;
        let finished = false;
        // Safety net: some voices never fire 'end'; don't let one call jam the queue.
        const guard = setTimeout(function() { finish(); }, 15000);
        function finish() {
            if (finished) return;
            finished = true;
            clearTimeout(guard);
            speaking = false;
            setTimeout(speakNext, 700); // short pause between announcements
        }
        const utter = makeUtterance(sentenceFor(call));
        utter.onend = finish;
        utter.onerror = function(e) {
            if (e.error === 'not-allowed') {
                // Blocked until someone taps the board: keep this call for after the tap.
                finished = true;
                clearTimeout(guard);
                speaking = false;
                speechQueue.unshift(call);
                showSoundPrompt();
                return;
            }
            finish();
        };
        window.speechSynthesis.speak(utter);
    }

    function showSoundPrompt() {
        soundBlocked = true;
        if (document.getElementById('soundPrompt')) return;
        const prompt = document.createElement('div');
        prompt.id = 'soundPrompt';
        prompt.className = 'sound-prompt';
        prompt.setAttribute('role', 'button');
        prompt.innerHTML = '<div class="sound-prompt-box"><div class="sound-prompt-icon">&#128266;</div>'
            + '<div class="sound-prompt-title">Tap anywhere to turn on voice announcements</div>'
            + '<div class="sound-prompt-text">The browser keeps the sound off until someone taps this screen once.</div></div>';
        prompt.addEventListener('click', function() {
            prompt.remove();
            soundBlocked = false;
            // Only the latest few calls are still worth announcing after a pause.
            while (speechQueue.length > 3) speechQueue.shift();
            window.speechSynthesis.speak(makeUtterance('Voice announcements are on'));
            setTimeout(speakNext, 2200);
        });
        document.body.appendChild(prompt);
    }

    // At load, a silent test line tells us whether the browser will allow speech.
    function checkSoundAllowed() {
        if (!hasSpeech) return;
        const test = makeUtterance('ready');
        test.volume = 0;
        test.onerror = function(e) {
            if (e.error === 'not-allowed') showSoundPrompt();
        };
        window.speechSynthesis.speak(test);
    }

    function pollNotify() {
        fetch('notify.json?rand=' + Math.random(), { cache: 'no-store' })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                const calls = data && Array.isArray(data.calls) ? data.calls : [];
                if (lastCallId === null) {
                    // First read: start after whatever is already there (no replaying old calls).
                    lastCallId = calls.length ? calls[calls.length - 1].id : 0;
                    return;
                }
                calls.forEach(function(call) {
                    if (call.id > lastCallId) {
                        speechQueue.push(call);
                        lastCallId = call.id;
                    }
                });
                speakNext();
            })
            .catch(function() {}) // e.g. read mid-write: just try again next round
            .then(function() { setTimeout(pollNotify, 250); });
    }
    document.addEventListener('DOMContentLoaded', function() {
        checkSoundAllowed();
        pollNotify();
    });

    // ---------- board refresh on DB update ----------
    // Also catches the service_date rollover (see queue_status.php's
    // 'service_date' field), so an idle overnight board still resets. The
    // board's content is fetched and swapped in place — a full page reload
    // would switch the browser's sound permission back off.
    let lastStatus = null;
    let refreshing = false;
    let refreshAgain = false; // another change arrived while a refresh was in flight
    async function refreshBoard() {
        // Don't swap the numbers out from under an announcement.
        if (speaking || speechQueue.length > 0) {
            setTimeout(refreshBoard, 500);
            return;
        }
        try {
            const res = await fetch('display.php', { cache: 'no-store' });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
            const fresh = doc.querySelector('.kiosk-body');
            const current = document.querySelector('.kiosk-body');
            if (fresh && current) {
                current.innerHTML = fresh.innerHTML;
                restoreFlashes();
                updateClock();
            }
            if (refreshAgain) {
                refreshAgain = false;
                refreshBoard();
            } else {
                refreshing = false;
            }
        } catch (e) {
            setTimeout(refreshBoard, 3000); // server hiccup: try again shortly
        }
    }
    async function pollQueueStatus() {
        try {
            const res = await fetch('queue_status.php', { cache: 'no-store' });
            // A failed request (e.g. a 500 during a MySQL hiccup) just skips this
            // round — it must not stop the loop, or an unattended board freezes.
            if (res.ok) {
                const text = JSON.stringify(await res.json());
                if (lastStatus === null) {
                    lastStatus = text;
                } else if (text !== lastStatus) {
                    lastStatus = text;
                    if (!refreshing) {
                        refreshing = true;
                        refreshBoard();
                    } else {
                        refreshAgain = true;
                    }
                }
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
                <div class="split-panels">
                    <div class="split-panel">
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
                    <div class="split-panel claim-panel">
                        <h2>READY FOR CLAIMING</h2>
                        <?php if (empty($claimable_results)): ?>
                        <div class="kiosk-empty">No results ready.</div>
                        <?php elseif (!$claim_is_scrolling): ?>
                        <div class="claim-cards">
                            <?php foreach ($claimable_results as $row): ?>
                            <div class="claim-card">
                                <span class="claim-name"><?= htmlspecialchars($row['surname']) ?>, <?= htmlspecialchars($row['first_name_initials']) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="claim-scroll-viewport">
                            <div class="claim-scroll-track" style="animation-duration:<?= $claim_scroll_seconds ?>s;">
                                <?php foreach ($claim_scroll_rows_doubled as $claim_row): ?>
                                <div class="claim-row">
                                    <?php foreach ($claim_row as $row): ?>
                                    <div class="claim-card">
                                        <span class="claim-name"><?= htmlspecialchars($row['surname']) ?>, <?= htmlspecialchars($row['first_name_initials']) ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
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
