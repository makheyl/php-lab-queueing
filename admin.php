<?php
require 'config.php';
require 'queue_functions.php';
require 'app_header.php';

// Plain words for lab_activity_log.action codes in the activity log. Codes
// not listed here (e.g. a future action) are shown as-is.
const ACTION_LABELS = [
    'issue_ticket' => 'Number issued',
    'reprint_ticket' => 'Ticket reprinted',
    'call_for_interview' => 'Called to interview',
    'complete_interview' => 'Interview done',
    'confirm_payment' => 'Payment confirmed',
    'call_for_extraction' => 'Called to extraction',
    'complete_extraction' => 'Extraction done',
    'recall' => 'Called again',
    'no_show' => 'No show',
    'reinstate' => 'Put back in line',
    'cancel' => 'Cancelled',
    'manual_correction' => 'Manual correction',
];

// ---------- local helpers ----------

function action_label($action) {
    return ACTION_LABELS[$action] ?? $action;
}

function fetch_durations($conn, $start_col, $end_col, $start_date, $end_date) {
    // $start_col/$end_col are always one of the fixed literals in $METRICS below,
    // never user input — safe to interpolate into the SQL.
    $sql = "SELECT TIMESTAMPDIFF(SECOND, $start_col, $end_col) AS secs FROM queue
            WHERE service_date BETWEEN ? AND ? AND $start_col IS NOT NULL AND $end_col IS NOT NULL
            AND $end_col >= $start_col";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $vals = [];
    while ($row = $result->fetch_assoc()) $vals[] = (int) $row['secs'];
    $stmt->close();
    return $vals;
}

function avg_median($vals) {
    if (empty($vals)) return [null, null];
    $avg = array_sum($vals) / count($vals);
    sort($vals);
    $n = count($vals);
    $mid = intdiv($n, 2);
    $median = ($n % 2 === 0) ? ($vals[$mid - 1] + $vals[$mid]) / 2 : $vals[$mid];
    return [$avg, $median];
}

function format_duration_seconds($secs) {
    if ($secs === null) return '—';
    $secs = (int) round($secs);
    $m = intdiv($secs, 60);
    $s = $secs % 60;
    return "{$m}m {$s}s";
}

function compute_range($base_str, $period) {
    $base = DateTime::createFromFormat('Y-m-d', $base_str) ?: new DateTime();
    $start = clone $base;
    $end = clone $base;
    if ($period === 'week') {
        $weekday = (int) $base->format('N');
        $start->modify('-' . ($weekday - 1) . ' days');
        $end = (clone $start)->modify('+6 days');
    } elseif ($period === 'month') {
        $start->modify('first day of this month');
        $end->modify('last day of this month');
    }
    return [$base, $start, $end];
}

// ---------- inputs: base date and period (mirrors OPD's admin.php) ----------

$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$period = isset($_GET['period']) ? $_GET['period'] : 'day';
[$base, $startDate, $endDate] = compute_range($date, $period);
$startDateStr = $startDate->format('Y-m-d');
$endDateStr = $endDate->format('Y-m-d');

$today = date('Y-m-d');
$is_today_base = $base->format('Y-m-d') === $today;
if ($period === 'day') {
    $periodLabel = ($startDateStr === $today ? 'Today · ' : '') . $startDate->format('l, M j, Y');
} elseif ($period === 'week') {
    $periodLabel = ($is_today_base ? 'This week · ' : 'Week of ') . $startDate->format('M j') . ' – ' . $endDate->format('M j, Y');
} else {
    $periodLabel = ($is_today_base ? 'This month · ' : '') . $base->format('F Y');
}
$periodLabel = htmlspecialchars($periodLabel);

// ---------- CSV export (daily_statistics over the selected range, OPD's naming) ----------

if (isset($_POST['export_csv'])) {
    $export_date = $_POST['export_date'] ?? $date;
    $export_period = $_POST['export_period'] ?? $period;
    [$export_base, $export_start, $export_end] = compute_range($export_date, $export_period);
    $export_start_str = $export_start->format('Y-m-d');
    $export_end_str = $export_end->format('Y-m-d');

    $stmt = $conn->prepare(
        "SELECT date, station, staff_name, patients_served, patients_pending, patients_cancelled,
                total_patients, no_charge_count, for_payment_count, no_show_count
         FROM daily_statistics WHERE date BETWEEN ? AND ? ORDER BY date ASC, station ASC, staff_name ASC"
    );
    $stmt->bind_param('ss', $export_start_str, $export_end_str);
    $stmt->execute();
    $export_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    header('Content-Type: text/csv');
    $filename_suffix = $export_period === 'day' ? $export_start_str
        : ($export_period === 'week' ? ($export_start_str . '_to_' . $export_end_str) : $export_base->format('Y_m'));
    header('Content-Disposition: attachment; filename="report_' . $export_period . '_' . $filename_suffix . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Station', 'Staff Name', 'Patients Served', 'Patients Pending', 'Patients Cancelled', 'Total Patients', 'No Charge', 'For Payment', 'No Show']);
    foreach ($export_rows as $row) {
        fputcsv($output, [
            $row['date'], $row['station'], $row['staff_name'], $row['patients_served'],
            $row['patients_pending'], $row['patients_cancelled'], $row['total_patients'],
            $row['no_charge_count'], $row['for_payment_count'], $row['no_show_count'],
        ]);
    }
    fclose($output);
    exit();
}

// ---------- summary tiles ----------

$stmt = $conn->prepare(
    "SELECT
        COUNT(*) AS issued,
        SUM(status = 'completed') AS completed,
        SUM(status = 'no_show') AS no_show,
        SUM(status = 'cancelled') AS cancelled,
        SUM(payment_required = 1) AS for_payment,
        SUM(payment_required = 0) AS no_charge
     FROM queue WHERE service_date BETWEEN ? AND ?"
);
$stmt->bind_param('ss', $startDateStr, $endDateStr);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();
foreach ($summary as $k => $v) $summary[$k] = (int) $v;

// ---------- timing metrics: average + median, in this order deliberately —  ----------
// "time away paying" and "wait to extraction" are reported separately: one is
// city hall's queue, one is the lab's own, and conflating them would hide
// which is the actual bottleneck.

$METRICS = [
    'wait_to_interview' => ['created_at', 'interview_called_at', 'Wait before interview'],
    'interview_duration' => ['interview_called_at', 'interview_completed_at', 'Interview'],
    'time_away_paying' => ['interview_completed_at', 'payment_confirmed_at', 'Away paying at City Hall'],
    'wait_to_extraction' => ['extraction_eligible_at', 'extraction_called_at', 'Wait for extraction (lab)'],
    'extraction_duration' => ['extraction_called_at', 'extraction_completed_at', 'Extraction'],
    'total_visit_time' => ['created_at', 'extraction_completed_at', 'Whole visit'],
];
$timing = [];
foreach ($METRICS as $key => [$start_col, $end_col, $label]) {
    $vals = fetch_durations($conn, $start_col, $end_col, $startDateStr, $endDateStr);
    [$avg, $median] = avg_median($vals);
    $timing[$key] = ['label' => $label, 'avg' => $avg, 'median' => $median, 'n' => count($vals)];
}

// ---------- hourly volume (by time issued) ----------

$stmt = $conn->prepare(
    "SELECT HOUR(created_at) AS hr, COUNT(*) AS cnt FROM queue
     WHERE service_date BETWEEN ? AND ? GROUP BY HOUR(created_at) ORDER BY hr ASC"
);
$stmt->bind_param('ss', $startDateStr, $endDateStr);
$stmt->execute();
$hourly_volume = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ---------- per-staff throughput (from daily_statistics) ----------
// No per-station breakdown — there's only one extraction station, so it'd
// just repeat the per-staff totals under a meaningless "station 1" label.

$stmt = $conn->prepare(
    "SELECT staff_name, SUM(patients_served) AS served, SUM(no_charge_count) AS no_charge,
            SUM(for_payment_count) AS for_payment, SUM(no_show_count) AS no_show
     FROM daily_statistics WHERE date BETWEEN ? AND ? GROUP BY staff_name ORDER BY staff_name ASC"
);
$stmt->bind_param('ss', $startDateStr, $endDateStr);
$stmt->execute();
$staff_throughput = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ---------- lab_activity_log viewer (filterable) ----------

$log_number = isset($_GET['log_number']) && $_GET['log_number'] !== '' ? (int) $_GET['log_number'] : null;
$log_staff = isset($_GET['log_staff']) ? trim($_GET['log_staff']) : '';
$log_action = isset($_GET['log_action']) ? trim($_GET['log_action']) : '';
$log_start = isset($_GET['log_start']) && $_GET['log_start'] !== '' ? $_GET['log_start'] : $startDateStr;
$log_end = isset($_GET['log_end']) && $_GET['log_end'] !== '' ? $_GET['log_end'] : $endDateStr;

$log_where = 'DATE(log_time) BETWEEN ? AND ?';
$log_types = 'ss';
$log_params = [$log_start, $log_end];
if ($log_number !== null) {
    $log_where .= ' AND queue_number = ?';
    $log_types .= 'i';
    $log_params[] = $log_number;
}
if ($log_staff !== '') {
    $log_where .= ' AND staff_name LIKE ?';
    $log_types .= 's';
    $log_params[] = '%' . $log_staff . '%';
}
if ($log_action !== '') {
    $log_where .= ' AND action = ?';
    $log_types .= 's';
    $log_params[] = $log_action;
}

$stmt = $conn->prepare("SELECT * FROM lab_activity_log WHERE $log_where ORDER BY log_time DESC LIMIT 500");
$stmt->bind_param($log_types, ...$log_params);
$stmt->execute();
$activity_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("SELECT DISTINCT action FROM lab_activity_log ORDER BY action ASC");
$stmt->execute();
$known_actions = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'action');
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Laboratory Queueing — Reports</title>
<link rel="stylesheet" href="assets/theme.css?v=<?= filemtime(__DIR__ . '/assets/theme.css') ?>">
<style>
    .report-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 18px; }
    .period-picker { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
    .period-picker label { font-size: 0.85rem; font-weight: 600; color: var(--text-muted); margin-left: 6px; }
    .period-picker .field { padding: 6px 10px; font-size: 0.88rem; }
    .period-picker .other-periods { font-size: 0.85rem; color: var(--text-muted); }
    .period-picker .other-periods a { color: var(--green-dark); font-weight: 700; }
    .report-actions { display: flex; gap: 8px; }
    .reports-title { text-align: center; color: var(--text); font-size: 1.45rem; margin: 6px 0 4px; }
    .right-now { display: flex; flex-wrap: wrap; justify-content: center; gap: 8px 22px; color: var(--text-muted); font-size: 0.92rem; font-weight: 600; margin-bottom: 8px; }
    .right-now strong { color: var(--text); font-size: 1.1rem; }
    .right-now .right-now-label { text-transform: uppercase; letter-spacing: 0.06em; font-size: 0.78rem; }
    .stats-section { margin-top: 22px; padding: 22px; background: var(--surface-alt); border-radius: var(--radius-lg); border: 1px solid var(--border); }
    .stats-section h3 { color: var(--green-dark); margin-bottom: 12px; font-size: 1.05rem; }
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; margin-top: 14px; }
    .mini-stat { background: var(--surface); padding: 16px; border-radius: var(--radius-sm); border: 1px solid var(--border); text-align: center; }
    .mini-stat h4 { margin: 0 0 8px; color: var(--text-muted); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.03em; }
    .mini-stat .value { font-size: 1.8rem; font-weight: 800; color: var(--text); }
    .two-col { display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 0 22px; }
    .data-table td.label-cell { text-align: left; }
    .data-table .sub { display: block; font-size: 0.76rem; color: var(--text-faint); font-weight: 500; }
    .bar-cell { width: 55%; }
    .bar { height: 12px; border-radius: var(--radius-pill); background: var(--green); min-width: 3px; }
    .log-filters summary { cursor: pointer; font-size: 0.85rem; font-weight: 700; color: var(--green-dark); }
</style>
</head>
<body>
    <?php render_app_header('reports'); ?>
    <div class="page page-wide">
        <div class="report-toolbar no-print">
            <div class="period-picker">
                <a class="btn btn-sm <?= $period === 'day' && $startDateStr === $today ? '' : 'btn-outline' ?>" href="?period=day&amp;date=<?= $today ?>">Today</a>
                <a class="btn btn-sm <?= $period === 'week' && $is_today_base ? '' : 'btn-outline' ?>" href="?period=week&amp;date=<?= $today ?>">This Week</a>
                <a class="btn btn-sm <?= $period === 'month' && $is_today_base ? '' : 'btn-outline' ?>" href="?period=month&amp;date=<?= $today ?>">This Month</a>
                <form method="get" id="otherDayForm">
                    <input type="hidden" name="period" value="day">
                    <label for="otherDay">Other day</label>
                    <input class="field" type="date" id="otherDay" name="date" value="<?= htmlspecialchars($base->format('Y-m-d')) ?>" max="<?= $today ?>">
                </form>
                <?php if (!$is_today_base): ?>
                <span class="other-periods">
                    See the whole
                    <a href="?period=week&amp;date=<?= htmlspecialchars($base->format('Y-m-d')) ?>">week</a> or
                    <a href="?period=month&amp;date=<?= htmlspecialchars($base->format('Y-m-d')) ?>">month</a>
                </span>
                <?php endif; ?>
            </div>
            <div class="report-actions">
                <form method="post">
                    <input type="hidden" name="export_date" value="<?= htmlspecialchars($date) ?>">
                    <input type="hidden" name="export_period" value="<?= htmlspecialchars($period) ?>">
                    <button type="submit" name="export_csv" class="btn btn-sm">Export CSV</button>
                </form>
                <button type="button" class="btn btn-outline btn-sm" onclick="window.print()">Print</button>
            </div>
        </div>

        <!-- Right now: read-only, updates from queue_status.php's poll, no reload -->
        <div class="right-now no-print">
            <span class="right-now-label">Right now</span>
            <span>Waiting <strong id="liveWaiting">—</strong></span>
            <span>Interviewing <strong id="liveInterviewing">—</strong></span>
            <span>Back from payment <strong id="liveAwaitingPayment">—</strong></span>
            <span>Ready for extraction <strong id="liveReadyForExtraction">—</strong></span>
        </div>

        <h1 class="reports-title"><?= $periodLabel ?></h1>

        <div class="stats-section">
            <div class="stats-grid" style="margin-top:0;">
                <div class="mini-stat"><h4>Tickets issued</h4><div class="value"><?= $summary['issued'] ?></div></div>
                <div class="mini-stat"><h4>Completed</h4><div class="value"><?= $summary['completed'] ?></div></div>
                <div class="mini-stat"><h4>No-show</h4><div class="value"><?= $summary['no_show'] ?></div></div>
                <div class="mini-stat"><h4>Cancelled</h4><div class="value"><?= $summary['cancelled'] ?></div></div>
                <div class="mini-stat"><h4>Paid</h4><div class="value"><?= $summary['for_payment'] ?></div></div>
                <div class="mini-stat"><h4>No charge</h4><div class="value"><?= $summary['no_charge'] ?></div></div>
            </div>
        </div>

        <div class="two-col">
            <div class="stats-section">
                <h3>Average Times</h3>
                <table class="data-table" style="margin-top:14px;">
                    <tr><th style="text-align:left;">Step</th><th>Average</th></tr>
                    <?php foreach ($timing as $row): ?>
                    <tr>
                        <td class="label-cell"><?= htmlspecialchars($row['label']) ?></td>
                        <td>
                            <?= format_duration_seconds($row['avg']) ?>
                            <?php if ($row['n'] > 0): ?>
                            <span class="sub">median <?= format_duration_seconds($row['median']) ?> · <?= $row['n'] ?> patient<?= $row['n'] === 1 ? '' : 's' ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <div class="stats-section">
                <h3>Busiest Hours</h3>
                <?php if (empty($hourly_volume)): ?>
                <p style="text-align:center; color:var(--text-faint);">No numbers issued in this range.</p>
                <?php else: $max_hourly = max(array_map(function ($r) { return (int) $r['cnt']; }, $hourly_volume)); ?>
                <table class="data-table" style="margin-top:14px;">
                    <tr><th>Hour</th><th>Tickets</th><th class="bar-cell"></th></tr>
                    <?php foreach ($hourly_volume as $row): ?>
                    <tr>
                        <td><?= date('g A', mktime((int) $row['hr'], 0, 0)) ?></td>
                        <td><?= (int) $row['cnt'] ?></td>
                        <td class="bar-cell"><div class="bar" style="width:<?= $max_hourly > 0 ? round((int) $row['cnt'] / $max_hourly * 100) : 0 ?>%;"></div></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="stats-section">
            <h3>Patients Served by Staff (extraction)</h3>
            <?php if (empty($staff_throughput)): ?>
            <p style="text-align:center; color:var(--text-faint);">No completed extractions in this range.</p>
            <?php else: ?>
            <table class="data-table" style="margin-top:14px;">
                <tr><th>Staff</th><th>Served</th><th>No Charge</th><th>Paid</th><th>No Show</th></tr>
                <?php foreach ($staff_throughput as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['staff_name']) ?></td>
                    <td><?= (int) $row['served'] ?></td>
                    <td><?= (int) $row['no_charge'] ?></td>
                    <td><?= (int) $row['for_payment'] ?></td>
                    <td><?= (int) $row['no_show'] ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>

        <?php
        // Keep the log open after a log search; open "More filters" only when
        // the From/To dates were actually changed from the report period.
        $log_filtered = $log_number !== null || $log_staff !== '' || $log_action !== '' || isset($_GET['log_start']);
        $custom_log_range = $log_start !== $startDateStr || $log_end !== $endDateStr;
        ?>
        <div style="text-align:center; margin-top:22px;" class="no-print">
            <button class="btn btn-outline" type="button" id="logToggle" onclick="toggleDetails()"><?= $log_filtered ? 'Hide activity log' : 'Show activity log' ?></button>
        </div>
        <div id="detailed-report" style="display:<?= $log_filtered ? 'block' : 'none' ?>; margin-top:12px;">
            <div class="stats-section">
                <h3>Activity Log</h3>
                <form method="get" class="toolbar-form no-print" style="margin-bottom:0;">
                    <input type="hidden" name="date" value="<?= htmlspecialchars($date) ?>">
                    <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">
                    <label for="log_number">Number</label>
                    <input class="field" type="number" id="log_number" name="log_number" value="<?= htmlspecialchars((string) ($log_number ?? '')) ?>" style="width:100px;">
                    <label for="log_staff">Staff</label>
                    <input class="field" type="text" id="log_staff" name="log_staff" value="<?= htmlspecialchars($log_staff) ?>" style="width:140px;">
                    <label for="log_action">Action</label>
                    <select class="field" id="log_action" name="log_action">
                        <option value="">All</option>
                        <?php foreach ($known_actions as $a): ?>
                        <option value="<?= htmlspecialchars($a) ?>" <?= $log_action === $a ? 'selected' : '' ?>><?= htmlspecialchars(action_label($a)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn">Search Log</button>
                    <details class="log-filters" <?= $custom_log_range ? 'open' : '' ?>>
                        <summary>More filters</summary>
                        <label for="log_start">From</label>
                        <input class="field" type="date" id="log_start" name="log_start" value="<?= htmlspecialchars($log_start) ?>">
                        <label for="log_end">To</label>
                        <input class="field" type="date" id="log_end" name="log_end" value="<?= htmlspecialchars($log_end) ?>">
                    </details>
                </form>
                <table class="data-table" style="margin-top:14px;">
                    <tr><th>Time</th><th>Staff</th><th>Number</th><th>What happened</th></tr>
                    <?php if (count($activity_rows) > 0): ?>
                        <?php foreach ($activity_rows as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars(date('M j, g:i:s A', strtotime($row['log_time']))) ?></td>
                            <td><?= htmlspecialchars($row['staff_name']) ?></td>
                            <td><?= htmlspecialchars($row['queue_number']) ?></td>
                            <td><?= htmlspecialchars(action_label($row['action'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row"><td colspan="4">Showing <?= count($activity_rows) ?> row(s) (capped at 500)</td></tr>
                    <?php else: ?>
                        <tr><td colspan="4" class="no-logs">No activity found for this filter.</td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <script>
    function toggleDetails() {
        var el = document.getElementById('detailed-report');
        var open = el.style.display === 'none' || el.style.display === '';
        el.style.display = open ? 'block' : 'none';
        document.getElementById('logToggle').textContent = open ? 'Hide activity log' : 'Show activity log';
    }

    // "Other day" applies as soon as a date is picked — no Filter button.
    document.getElementById('otherDay').addEventListener('change', function() {
        if (this.value) this.form.submit();
    });

    // Right now: updates in place from the same poll every other screen uses,
    // never reloads the admin page out from under a report someone is reading.
    async function pollLiveMonitor() {
        try {
            const res = await fetch('queue_status.php');
            if (res.ok) {
                const data = await res.json();
                const map = {
                    liveWaiting: data.waiting,
                    liveInterviewing: data.interviewing,
                    liveAwaitingPayment: data.awaiting_payment,
                    liveReadyForExtraction: data.ready_for_extraction,
                };
                for (const id in map) {
                    const el = document.getElementById(id);
                    if (el) el.textContent = map[id];
                }
            }
        } catch (e) {}
        setTimeout(pollLiveMonitor, 3000);
    }
    pollLiveMonitor();
    </script>
</body>
</html>
