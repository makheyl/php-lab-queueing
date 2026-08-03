<?php
require 'config.php';
$waiting = $conn->query("SELECT * FROM queue WHERE status='waiting' ORDER BY (priority='yes') DESC, (priority='completed') DESC, created_at ASC");
$serving = $conn->query("SELECT * FROM queue WHERE status='serving' ORDER BY created_at ASC");
$pending = $conn->query("SELECT * FROM queue WHERE status='pending' ORDER BY queue_number ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Queue Display</title>
<link rel="stylesheet" href="assets/display.css">
<style>
    .kiosk-col.serving-col { flex: 2.2 1 0; min-width: 420px; max-width: 55vw; }
    .kiosk-col.waiting-col { flex: 0.8 1 0; max-width: 18vw; }
</style>
<script>
let lastNotified = 0;
function pollNotify() {
    fetch('notify.json?rand=' + Math.random())
        .then(res => res.json())
        .then(data => {
            let shouldClear = false;
            if (data && data.timestamp && data.timestamp > lastNotified) {
                lastNotified = data.timestamp;
                const msg = `Queue number ${data.queue_number}, queue number ${data.queue_number}, please proceed to table ${data.table_number}`;
                if ('speechSynthesis' in window) {
                    window.speechSynthesis.cancel();
                    const utter = new SpeechSynthesisUtterance(msg);
                    utter.lang = 'en-US';
                    window.speechSynthesis.speak(utter);
                }
                shouldClear = true;
            } else if (data && Object.keys(data).length > 0) {
                // If notify.json is not empty but not a new notification, clear it to avoid stale data
                shouldClear = true;
            }
            if (shouldClear) {
                fetch('clear_notify.php?rand=' + Math.random());
            }
        })
        .catch(() => {
            // Always attempt to clear notify.json on error, in case of corrupted file
            fetch('clear_notify.php?rand=' + Math.random());
        });
    setTimeout(pollNotify, 50);
}
document.addEventListener('DOMContentLoaded', pollNotify);
// Add smart refresh on DB update
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
    <div class="kiosk-header">
        <div class="logo"><img src="CHO.png" alt="CHO Logo"></div>
        <div class="title">OPD Queueing</div>
    </div>
    <div class="kiosk-body">
        <div class="kiosk-stats">
            <div class="kiosk-stat">
                <span class="label">Total Waiting</span>
                <span class="value"><?php $result = $conn->query("SELECT COUNT(*) as cnt FROM queue WHERE status='waiting'"); $row = $result->fetch_assoc(); echo $row['cnt']; ?></span>
            </div>
            <div class="kiosk-stat">
                <span class="label">Currently Serving</span>
                <span class="value"><?php $result = $conn->query("SELECT COUNT(*) as cnt FROM queue WHERE status='serving'"); $row = $result->fetch_assoc(); echo $row['cnt']; ?></span>
            </div>
            <div class="kiosk-stat">
                <span class="label">Total Served</span>
                <span class="value"><?php $result = $conn->query("SELECT COUNT(*) as cnt FROM queue WHERE status='served'"); $row = $result->fetch_assoc(); echo $row['cnt']; ?></span>
            </div>
        </div>
        <div class="kiosk-columns">
            <div class="kiosk-col waiting-col">
                <h2>Waiting Queue</h2>
                <div class="kiosk-cards">
                    <?php
                    $waiting->data_seek(0);
                    $shown_priority = false;
                    $shown_completed = false;
                    $shown_next = false;
                    while($row = $waiting->fetch_assoc()):
                        if ($row['priority'] === 'yes') {
                            if ($shown_priority) continue;
                            $shown_priority = true;
                        } elseif ($row['priority'] === 'completed') {
                            if ($shown_completed) continue;
                            $shown_completed = true;
                        } elseif (!$shown_next) {
                            $shown_next = true;
                        } else {
                            continue;
                        }
                    ?>
                    <div class="kiosk-card<?php if ($row['priority'] === 'yes') echo ' priority'; if ($row['priority'] === 'completed') echo ' completed'; ?>">
                        <span class="queue-number"><?= htmlspecialchars($row['queue_number']) ?></span>
                        <?php if (!empty($row['table_number'])): ?>
                        <div class="info">
                            <span class="table-num"><span class="table-label">Table</span> <?= htmlspecialchars($row['table_number']) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($row['priority'] === 'yes'): ?>
                            <span class="badge badge-priority">PRIORITY</span>
                        <?php elseif ($row['priority'] === 'completed'): ?>
                            <span class="badge badge-completed">COMPLETED REQS</span>
                        <?php endif; ?>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
            <div class="kiosk-col serving-col">
                <h2>Currently Serving</h2>
                <div class="kiosk-cards serving-cards">
                    <?php
                    // Create arrays for each table
                    $table1_patients = [];
                    $table2_patients = [];
                    $table3_patients = [];
                    $table4_patients = [];

                    $serving->data_seek(0);
                    while($row = $serving->fetch_assoc()) {
                        $table_num = $row['table_number'] ?? 0;
                        if ($table_num == 1) $table1_patients[] = $row;
                        elseif ($table_num == 2) $table2_patients[] = $row;
                        elseif ($table_num == 3) $table3_patients[] = $row;
                        elseif ($table_num == 4) $table4_patients[] = $row;
                        else $table1_patients[] = $row; // Default to table 1 if no table number
                    }
                    ?>

                    <!-- Table 1 -->
                    <div class="table-column">
                        <div class="table-header">Table 1</div>
                        <?php foreach($table1_patients as $row): ?>
                        <div class="kiosk-card serving-card<?php if ($row['priority'] === 'yes') echo ' priority'; if ($row['priority'] === 'completed') echo ' completed'; ?>">
                            <span class="queue-number"><?= htmlspecialchars($row['queue_number']) ?></span>
                            <?php if ($row['priority'] === 'yes'): ?>
                                <span class="badge badge-priority">PRIORITY</span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Table 2 -->
                    <div class="table-column">
                        <div class="table-header">Table 2</div>
                        <?php foreach($table2_patients as $row): ?>
                        <div class="kiosk-card serving-card<?php if ($row['priority'] === 'yes') echo ' priority'; if ($row['priority'] === 'completed') echo ' completed'; ?>">
                            <span class="queue-number"><?= htmlspecialchars($row['queue_number']) ?></span>
                            <?php if ($row['priority'] === 'yes'): ?>
                                <span class="badge badge-priority">PRIORITY</span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Table 3 -->
                    <div class="table-column">
                        <div class="table-header">Table 3</div>
                        <?php foreach($table3_patients as $row): ?>
                        <div class="kiosk-card serving-card<?php if ($row['priority'] === 'yes') echo ' priority'; if ($row['priority'] === 'completed') echo ' completed'; ?>">
                            <span class="queue-number"><?= htmlspecialchars($row['queue_number']) ?></span>
                            <?php if ($row['priority'] === 'yes'): ?>
                                <span class="badge badge-priority">PRIORITY</span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Table 4 -->
                    <div class="table-column">
                        <div class="table-header">Table 4</div>
                        <?php foreach($table4_patients as $row): ?>
                        <div class="kiosk-card serving-card<?php if ($row['priority'] === 'yes') echo ' priority'; if ($row['priority'] === 'completed') echo ' completed'; ?>">
                            <span class="queue-number"><?= htmlspecialchars($row['queue_number']) ?></span>
                            <?php if ($row['priority'] === 'yes'): ?>
                                <span class="badge badge-priority">PRIORITY</span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="kiosk-col pending-col">
                <h2>Pending Patients</h2>
                <div class="kiosk-cards">
                    <?php if ($pending && $pending->num_rows > 0): while($row = $pending->fetch_assoc()): ?>
                    <div class="kiosk-card pending">
                        <span class="queue-number"><?= htmlspecialchars($row['queue_number']) ?></span>
                        <span class="badge badge-pending">PENDING</span>
                        <?php if (!empty($row['table_number'])): ?>
                        <div class="info">
                            <span class="table-num"><span class="table-label">Table</span> <?= htmlspecialchars($row['table_number']) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endwhile; else: ?>
                    <div class="kiosk-empty">No pending patients.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
