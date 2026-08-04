<?php
/*
 * Test script to verify queue_functions.php behavior.
 * Run this after executing database_setup.sql.
 *
 * Uses a handful of scratch queue_numbers under today's service_date. Run
 * against a dev/empty database — if any of the scratch numbers below are
 * already in use for today, the script aborts before touching anything.
 */

require 'config.php';
require 'queue_functions.php';

$SCRATCH_NUMBERS = [1, 2, 3, 4, 5, 6];
$SCRATCH_WRONG_DAY_NUMBER = 999;
$CONCURRENCY_NUMBERS = range(101, 110); // 10-row pool for the concurrent call_next_extraction test
$ALL_SCRATCH_NUMBERS = array_merge($SCRATCH_NUMBERS, $CONCURRENCY_NUMBERS);

$pass_count = 0;
$fail_count = 0;

function check($label, $condition) {
    global $pass_count, $fail_count;
    if ($condition) {
        echo "✓ $label\n";
        $pass_count++;
    } else {
        echo "✗ $label\n";
        $fail_count++;
    }
}

echo "Testing queue logic...\n\n";

$service_date = service_date_now($conn);
$yesterday = (new DateTime($service_date))->modify('-1 day')->format('Y-m-d');
$test_started_at = date('Y-m-d H:i:s');

// Builds "?,?,?..." for N placeholders — used for the dynamic IN (...) lists below.
function placeholders($n) {
    return implode(',', array_fill(0, $n, '?'));
}

// Pre-flight: refuse to run if any scratch number is already in use today,
// so cleanup at the end can safely assume every matching row is ours.
$scratch_ints = array_map('intval', $ALL_SCRATCH_NUMBERS);
$stmt = $conn->prepare(
    "SELECT queue_number FROM queue WHERE service_date = ? AND queue_number IN (" . placeholders(count($scratch_ints)) . ")"
);
$stmt->bind_param('s' . str_repeat('i', count($scratch_ints)), $service_date, ...$scratch_ints);
$stmt->execute();
$existing = $stmt->get_result();
$stmt->close();
if ($existing && $existing->num_rows > 0) {
    echo "✗ Scratch queue_numbers " . implode(',', $ALL_SCRATCH_NUMBERS) . " are already in use for $service_date.\n";
    echo "  Run this against an empty/dev database, or change the scratch numbers.\n";
    exit(1);
}

function cleanup($conn, $service_date, $yesterday, $scratch_numbers, $wrong_day_number, $test_started_at) {
    $scratch_ints = array_map('intval', $scratch_numbers);
    $stmt = $conn->prepare(
        "DELETE FROM queue WHERE service_date = ? AND queue_number IN (" . placeholders(count($scratch_ints)) . ")"
    );
    $stmt->bind_param('s' . str_repeat('i', count($scratch_ints)), $service_date, ...$scratch_ints);
    $stmt->execute();
    $stmt->close();

    $wrong_day_int = (int) $wrong_day_number;
    $stmt = $conn->prepare('DELETE FROM queue WHERE service_date = ? AND queue_number = ?');
    $stmt->bind_param('si', $yesterday, $wrong_day_int);
    $stmt->execute();
    $stmt->close();

    $all_ints = array_map('intval', array_merge($scratch_numbers, [$wrong_day_number]));
    $stmt = $conn->prepare(
        "DELETE FROM lab_activity_log WHERE queue_number IN (" . placeholders(count($all_ints)) . ") AND log_time >= ?"
    );
    $params = array_merge($all_ints, [$test_started_at]);
    $stmt->bind_param(str_repeat('i', count($all_ints)) . 's', ...$params);
    $stmt->execute();
    $stmt->close();
}

try {
    // --- Test 1: add 5 tickets, interview them (1 -> payment, 2-5 -> no charge) ---
    $ids = [];
    foreach ($SCRATCH_NUMBERS as $n) {
        if ($n === 6) continue; // ticket 6 is added later, in test 3
        [$ok, $err] = add_to_queue($conn, $n);
        check("add_to_queue($n) succeeds", $ok);
    }

    // queue_number 1 -> awaiting_payment
    $row = call_next_interview($conn, 1, 'Tester');
    check('call_next_interview picks queue_number 1 first', $row && (int) $row['queue_number'] === 1);
    $ids[1] = $row['id'];
    check('complete_interview(payment_required=true) on ticket 1', complete_interview($conn, $ids[1], true, 'Tester'));

    // queue_numbers 2-5 -> ready_for_extraction, in order
    foreach ([2, 3, 4, 5] as $n) {
        $row = call_next_interview($conn, 1, 'Tester');
        check("call_next_interview picks queue_number $n", $row && (int) $row['queue_number'] === $n);
        $ids[$n] = $row['id'];
        check("complete_interview(payment_required=false) on ticket $n", complete_interview($conn, $ids[$n], false, 'Tester'));
    }

    $extraction_queue = get_extraction_queue($conn, $service_date);
    $extraction_numbers = array_values(array_filter(
        array_map(fn($r) => (int) $r['queue_number'], $extraction_queue),
        fn($n) => in_array($n, $SCRATCH_NUMBERS, true)
    ));
    check('get_extraction_queue() returns [2,3,4,5], ticket 1 absent', $extraction_numbers === [2, 3, 4, 5]);

    // --- Test 2: confirm_payment(1) moves it to the back ---
    check('confirm_payment(1) succeeds', confirm_payment($conn, $ids[1], 'Tester', 'REF-001'));
    $extraction_queue = get_extraction_queue($conn, $service_date);
    $extraction_numbers = array_values(array_filter(
        array_map(fn($r) => (int) $r['queue_number'], $extraction_queue),
        fn($n) => in_array($n, $SCRATCH_NUMBERS, true)
    ));
    check('after confirm_payment(1), extraction queue is [2,3,4,5,1]', $extraction_numbers === [2, 3, 4, 5, 1]);

    // --- Test 3: a ticket awaiting payment never appears in the extraction queue ---
    [$ok] = add_to_queue($conn, 6);
    check('add_to_queue(6) succeeds', $ok);
    $row = call_next_interview($conn, 1, 'Tester');
    check('call_next_interview picks queue_number 6', $row && (int) $row['queue_number'] === 6);
    $ids[6] = $row['id'];
    check('complete_interview(payment_required=true) on ticket 6', complete_interview($conn, $ids[6], true, 'Tester'));
    $extraction_queue = get_extraction_queue($conn, $service_date);
    $extraction_numbers = array_map(fn($r) => (int) $r['queue_number'], $extraction_queue);
    check('ticket 6 (awaiting_payment) is absent from the extraction queue', !in_array(6, $extraction_numbers, true));

    // --- Test 4: confirm_payment on a no-charge ticket is rejected with reason 'no_charge' ---
    [$row2, $confirmable, $reason] = find_for_payment($conn, 2);
    check("find_for_payment(2) reason is 'no_charge'", $reason === 'no_charge' && $confirmable === false);
    check('confirm_payment on the no-charge ticket 2 fails', confirm_payment($conn, $ids[2], 'Tester') === false);

    // --- Test 5: confirm_payment twice -> second call reports 'already_confirmed' with the original time ---
    [$row1_before] = find_for_payment($conn, 1);
    $original_confirmed_at = $row1_before['payment_confirmed_at'];
    $original_confirmed_by = $row1_before['payment_confirmed_by'];

    $second_confirm_ok = confirm_payment($conn, $ids[1], 'SecondStaff', 'REF-002');
    check('second confirm_payment(1) call is rejected', $second_confirm_ok === false);

    [$row1_after, $confirmable1, $reason1] = find_for_payment($conn, 1);
    check("find_for_payment(1) reason is 'already_confirmed'", $reason1 === 'already_confirmed' && $confirmable1 === false);
    check('payment_confirmed_at is unchanged after the second call', $row1_after['payment_confirmed_at'] === $original_confirmed_at);
    check('payment_confirmed_by is unchanged after the second call', $row1_after['payment_confirmed_by'] === $original_confirmed_by);

    // --- Test 6: find_for_payment on a number from a previous service_date -> 'wrong_day' ---
    $wrong_day_number_int = (int) $SCRATCH_WRONG_DAY_NUMBER;
    $stmt = $conn->prepare("INSERT INTO queue (service_date, queue_number, status) VALUES (?, ?, 'waiting')");
    $stmt->bind_param('si', $yesterday, $wrong_day_number_int);
    $stmt->execute();
    $stmt->close();
    [$row_wrong_day, $confirmable_wrong_day, $reason_wrong_day] = find_for_payment($conn, $SCRATCH_WRONG_DAY_NUMBER);
    check("find_for_payment on a previous-day ticket returns 'wrong_day'", $reason_wrong_day === 'wrong_day' && $confirmable_wrong_day === false);

    // --- Test 7: two rapid call_next_extraction() calls never return the same row ---
    $called_a = call_next_extraction($conn, 1, 'Tester');
    $called_b = call_next_extraction($conn, 2, 'Tester');
    check('two rapid call_next_extraction() calls both return a ticket', $called_a && $called_b);
    check('two rapid call_next_extraction() calls return different rows', $called_a && $called_b && $called_a['id'] !== $called_b['id']);

    // --- Test 8: reinstate() after no_show puts the ticket at the back of the queue ---
    $remaining_before = get_extraction_queue($conn, $service_date);
    $remaining_scratch = array_values(array_filter($remaining_before, fn($r) => in_array((int) $r['queue_number'], $SCRATCH_NUMBERS, true)));
    check('at least one scratch ticket is still ready_for_extraction for the reinstate test', count($remaining_scratch) > 0);

    $reinstate_target = $remaining_scratch[0];
    check('mark_no_show on the reinstate target succeeds', mark_no_show($conn, $reinstate_target['id'], 'Tester'));
    check('reinstate() on the no_show ticket succeeds', reinstate($conn, $reinstate_target['id'], 'Tester'));

    $extraction_queue_after_reinstate = get_extraction_queue($conn, $service_date);
    $scratch_numbers_after_reinstate = array_values(array_filter(
        array_map(fn($r) => (int) $r['queue_number'], $extraction_queue_after_reinstate),
        fn($n) => in_array($n, $SCRATCH_NUMBERS, true)
    ));
    $last = end($scratch_numbers_after_reinstate);
    check('reinstated ticket is at the back of the extraction queue', $last === (int) $reinstate_target['queue_number']);

    // --- Test 9: add_to_queue rejects a duplicate number for the same day ---
    [$dup_ok, $dup_err] = add_to_queue($conn, 1);
    check('add_to_queue rejects a duplicate queue_number for the same day', $dup_ok === false && !empty($dup_err));

    // --- Test 10: 10 concurrent call_next_extraction() calls against a 10-row pool ---
    // Real OS-level concurrency, not just rapid sequential calls: each worker is a
    // separate php.exe process with its own mysqli connection, all launched before
    // any of them are waited on, so they actually race for the same 10 rows. This
    // is what exercises call_next_extraction()'s guarded-UPDATE retry loop under
    // real contention — sequential calls in one process never hit that path.
    // Drain any tickets earlier tests left sitting in ready_for_extraction (e.g.
    // ticket 3/4/5/reinstated-6 never got called) so the pool below contains
    // EXACTLY the 10 rows this test inserts — otherwise older, earlier-eligible
    // leftovers would win the race first and this test would be non-deterministic.
    // Each iteration uses a fresh station number: call_next_extraction() is
    // idempotent per-station (see its docblock), so reusing one station here
    // would just keep re-returning the first ticket it drained forever.
    $drain_station = 900;
    while (call_next_extraction($conn, $drain_station++, 'PreDrain')) {
        // discard — this only touches rows this script itself created (see the
        // file-level assumption that this runs against a dev/empty database)
    }

    $concurrency_ints = array_map('intval', $CONCURRENCY_NUMBERS);
    $expected_ids = [];
    foreach ($concurrency_ints as $n) {
        $stmt = $conn->prepare(
            "INSERT INTO queue (service_date, queue_number, status, payment_required, extraction_eligible_at)
             VALUES (?, ?, 'ready_for_extraction', 0, NOW(6))"
        );
        $stmt->bind_param('si', $service_date, $n);
        $stmt->execute();
        $expected_ids[] = $conn->insert_id;
        $stmt->close();
    }

    $config_path = addslashes(__DIR__ . '/config.php');
    $qf_path = addslashes(__DIR__ . '/queue_functions.php');

    // Each worker uses its OWN station number. This has to be distinct
    // per worker: call_next_interview/call_next_extraction are deliberately
    // idempotent per-station (see their docblocks) to close a real double-submit
    // gap, so 10 workers hitting the SAME station would correctly collapse to
    // one shared claim — that's a different behavior than what this test is
    // checking. call_next_extraction() still takes a station parameter for
    // this reason even though extraction.php itself only ever passes one
    // fixed station now (there's a single real extraction station) — this
    // test is exercising the shared function's general contention handling,
    // not simulating extraction.php's current UI.
    $descriptorspec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $procs = [];
    $pipes_by_worker = [];
    for ($i = 0; $i < 10; $i++) {
        // 501-510: must not collide with any station number used earlier in this
        // file (e.g. Test 7 leaves stations 1 and 2 with active, never-completed
        // extracting tickets — reusing those numbers here would make the
        // per-station idempotency guard correctly, but confusingly, hand a
        // worker that stale ticket instead of racing for the fresh pool).
        $station = 500 + $i + 1;
        $worker_code = "require '$config_path'; require '$qf_path'; "
            . "\$r = call_next_extraction(\$conn, $station, 'ConcurrencyWorker'); echo \$r ? \$r['id'] : 'NULL';";
        $proc = proc_open([PHP_BINARY, '-r', $worker_code], $descriptorspec, $pipes);
        $procs[$i] = $proc;
        $pipes_by_worker[$i] = $pipes;
    }

    $claimed_ids = [];
    for ($i = 0; $i < 10; $i++) {
        $out = trim(stream_get_contents($pipes_by_worker[$i][1]));
        $err = trim(stream_get_contents($pipes_by_worker[$i][2]));
        fclose($pipes_by_worker[$i][1]);
        fclose($pipes_by_worker[$i][2]);
        proc_close($procs[$i]);
        if ($err !== '') echo "  (worker $i stderr: $err)\n";
        $claimed_ids[] = $out;
    }

    $claimed_no_nulls = array_filter($claimed_ids, fn($v) => $v !== 'NULL' && $v !== '');
    check('all 10 concurrent workers claimed a ticket (none returned null)', count($claimed_no_nulls) === 10);
    check('all 10 claims are distinct (no duplicate claim under contention)', count(array_unique($claimed_no_nulls)) === 10);
    sort($expected_ids);
    $claimed_sorted = array_values($claimed_no_nulls);
    sort($claimed_sorted);
    check('the 10 claimed ids exactly match the 10 inserted rows (none skipped)', $claimed_sorted == array_map('strval', $expected_ids));
} finally {
    cleanup($conn, $service_date, $yesterday, $ALL_SCRATCH_NUMBERS, $SCRATCH_WRONG_DAY_NUMBER, $test_started_at);
    echo "\n(scratch rows cleaned up)\n";
}

echo "\n$pass_count passed, $fail_count failed.\n";
if ($fail_count > 0) {
    exit(1);
}
?>
