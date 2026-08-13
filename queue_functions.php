<?php
// queue_functions.php
//
// Shared queue logic for the Laboratory Queueing System. Every screen
// (encoder, interview station, payment desk, extraction station, kiosk)
// goes through these functions instead of writing its own SQL.
//
// Unlike the rest of this codebase (see CLAUDE.md §9), this file uses
// prepared statements throughout, even for values that look safe to
// interpolate. This is deliberate, not an inconsistency to "fix" elsewhere.
//
// Every function takes $conn (an already-connected mysqli instance) as its
// first argument. Callers are expected to have already required config.php.

/**
 * Reads a single settings.value by key, or $default if the key is missing.
 */
function get_setting($conn, $key, $default = null) {
    $stmt = $conn->prepare("SELECT `value` FROM settings WHERE `key` = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? $row['value'] : $default;
}

/**
 * Today's service_date — but the day rolls over at settings.daily_reset_hour
 * instead of midnight, so a 6am lab opening (and anything logged before the
 * next day's reset hour) still counts as "today". Asia/Manila per config.php.
 */
function service_date_now($conn) {
    $reset_hour = (int) get_setting($conn, 'daily_reset_hour', 4);
    $now = new DateTime('now', new DateTimeZone('Asia/Manila'));
    if ((int) $now->format('G') < $reset_hour) {
        $now->modify('-1 day');
    }
    return $now->format('Y-m-d');
}

/**
 * Writes one row to lab_activity_log. Called on every staff-driven state
 * change (add_to_queue is data entry with no staff_name to attribute, so it
 * does not log).
 */
function log_activity($conn, $staff_name, $station, $queue_number, $action) {
    $stmt = $conn->prepare(
        "INSERT INTO lab_activity_log (staff_name, station, queue_number, action) VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param('siis', $staff_name, $station, $queue_number, $action);
    $stmt->execute();
    $stmt->close();
}

/**
 * Adds a queue_number to today's queue. Rejects a duplicate queue_number
 * for the same service_date (OPD does this too). Returns [ok, error].
 */
function add_to_queue($conn, $queue_number) {
    $service_date = service_date_now($conn);

    $stmt = $conn->prepare(
        "INSERT INTO queue (service_date, queue_number, status) VALUES (?, ?, 'waiting')"
    );
    $stmt->bind_param('si', $service_date, $queue_number);
    try {
        $stmt->execute();
        $stmt->close();
        return [true, null];
    } catch (mysqli_sql_exception $e) {
        $stmt->close();
        if ($e->getCode() === 1062) {
            return [false, "Queue number $queue_number is already in today's queue."];
        }
        return [false, $e->getMessage()];
    }
}

/**
 * max(queue_number) + 1 for today, for the encoder's convenience.
 */
function next_suggested_number($conn) {
    $service_date = service_date_now($conn);
    $stmt = $conn->prepare(
        "SELECT COALESCE(MAX(queue_number), 0) + 1 AS next_number FROM queue WHERE service_date = ?"
    );
    $stmt->bind_param('s', $service_date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) $row['next_number'];
}

/**
 * Claims the lowest queue_number currently waiting and calls it to
 * interview. Uses a guarded UPDATE + affected_rows check rather than
 * SELECT ... FOR UPDATE / SKIP LOCKED, since XAMPP's bundled MariaDB version
 * is not guaranteed to support SKIP LOCKED. Retries on a claim race (two
 * stations calling next at the same instant) up to 5 times.
 *
 * Returns the claimed row on success, or null if nobody is waiting (or the
 * race could not be won after 5 attempts).
 *
 * Idempotent against a double-submit: if this station already has an
 * interviewing ticket, that ticket is returned as-is instead of claiming a
 * second one. Without this check, two rapid clicks (or two requests racing
 * before the first's response/reload lands) could each independently find
 * the station "free" and both succeed, leaving one station with two active
 * tickets — the guarded UPDATE alone doesn't prevent this, since it only
 * guards the row being claimed, not how many a station already holds.
 */
function call_next_interview($conn, $station, $staff_name) {
    $service_date = service_date_now($conn);

    $stmt = $conn->prepare(
        "SELECT * FROM queue WHERE service_date = ? AND interview_station = ? AND status = 'interviewing' LIMIT 1"
    );
    $stmt->bind_param('si', $service_date, $station);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($existing) return $existing;

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $stmt = $conn->prepare(
            "SELECT id FROM queue WHERE service_date = ? AND status = 'waiting'
             ORDER BY queue_number ASC LIMIT 1"
        );
        $stmt->bind_param('s', $service_date);
        $stmt->execute();
        $candidate = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$candidate) {
            return null;
        }
        $id = (int) $candidate['id'];

        $stmt = $conn->prepare(
            "UPDATE queue SET status = 'interviewing', interview_called_at = NOW(),
                interview_station = ?, interview_by = ?
             WHERE id = ? AND status = 'waiting'"
        );
        $stmt->bind_param('isi', $station, $staff_name, $id);
        $stmt->execute();
        $claimed = $stmt->affected_rows === 1;
        $stmt->close();

        if ($claimed) {
            $row = find_queue_row($conn, $id);
            log_activity($conn, $staff_name, $station, $row['queue_number'], 'call_for_interview');
            return $row;
        }
        // else: another station claimed this row first — loop and retry
    }
    return null;
}

/**
 * Ends the interview and decides the ticket's path:
 * - payment_required = false -> ready_for_extraction, extraction_eligible_at set now
 * - payment_required = true  -> awaiting_payment, extraction_eligible_at stays NULL
 *   until confirm_payment() runs.
 */
function complete_interview($conn, $id, $payment_required, $staff_name) {
    $row = find_queue_row($conn, $id);
    if (!$row || $row['status'] !== 'interviewing') return false;

    $payment_required_int = $payment_required ? 1 : 0;

    if ($payment_required) {
        $stmt = $conn->prepare(
            "UPDATE queue SET status = 'awaiting_payment', payment_required = ?,
                interview_completed_at = NOW()
             WHERE id = ? AND status = 'interviewing'"
        );
        $stmt->bind_param('ii', $payment_required_int, $id);
    } else {
        $stmt = $conn->prepare(
            "UPDATE queue SET status = 'ready_for_extraction', payment_required = ?,
                interview_completed_at = NOW(), extraction_eligible_at = NOW(6)
             WHERE id = ? AND status = 'interviewing'"
        );
        $stmt->bind_param('ii', $payment_required_int, $id);
    }
    $stmt->execute();
    $ok = $stmt->affected_rows === 1;
    $stmt->close();

    if ($ok) {
        log_activity($conn, $staff_name, (int) $row['interview_station'], $row['queue_number'], 'complete_interview');
    }
    return $ok;
}

/**
 * Looks up today's queue_number and reports whether it can be confirmed for
 * payment. Reason is one of:
 *   'not_found', 'wrong_day', 'not_yet_interviewed', 'no_charge', 'already_confirmed'
 * or null when confirmable. The returned row (when non-null) carries
 * payment_confirmed_at/payment_confirmed_by so the UI can show them for the
 * 'already_confirmed' case.
 */
function find_for_payment($conn, $queue_number) {
    $stmt = $conn->prepare(
        "SELECT * FROM queue WHERE queue_number = ? ORDER BY service_date DESC LIMIT 1"
    );
    $stmt->bind_param('i', $queue_number);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return [null, false, 'not_found'];
    }
    if ($row['service_date'] !== service_date_now($conn)) {
        return [$row, false, 'wrong_day'];
    }
    if (in_array($row['status'], ['waiting', 'interviewing'], true)) {
        return [$row, false, 'not_yet_interviewed'];
    }
    if (!$row['payment_required']) {
        return [$row, false, 'no_charge'];
    }
    if ($row['status'] !== 'awaiting_payment') {
        return [$row, false, 'already_confirmed'];
    }
    return [$row, true, null];
}

/**
 * Confirms payment for a returning patient. Guarded on status =
 * 'awaiting_payment' so a double-confirm is a no-op (see find_for_payment's
 * 'already_confirmed' reason).
 *
 * extraction_eligible_at is stamped NOW(6) here, not at interview time — that
 * is what puts this returning patient BEHIND the no-charge patients who
 * became eligible while they were away paying. Do not backdate this.
 */
function confirm_payment($conn, $id, $staff_name, $payment_reference = null) {
    $row = find_queue_row($conn, $id);
    if (!$row || $row['status'] !== 'awaiting_payment') return false;

    $stmt = $conn->prepare(
        "UPDATE queue SET status = 'ready_for_extraction', payment_confirmed_at = NOW(),
            payment_confirmed_by = ?, payment_reference = ?, extraction_eligible_at = NOW(6)
         WHERE id = ? AND status = 'awaiting_payment'"
    );
    $stmt->bind_param('ssi', $staff_name, $payment_reference, $id);
    $stmt->execute();
    $ok = $stmt->affected_rows === 1;
    $stmt->close();

    if ($ok) {
        log_activity($conn, $staff_name, 0, $row['queue_number'], 'confirm_payment');
    }
    return $ok;
}

/**
 * Claims the next ticket for extraction, ordered by extraction_eligible_at
 * ASC (queue_number ASC only as a tie-breaker) — NOT by queue_number. A
 * ticket that detoured through payment becomes eligible later than tickets
 * that went straight through, and must be called in that order. See
 * CLAUDE.md for the full explanation. Same guarded-UPDATE claim pattern as
 * call_next_interview. Also idempotent against a double-submit the same way
 * call_next_interview() is — see that function's docblock.
 */
function call_next_extraction($conn, $station, $staff_name) {
    $service_date = service_date_now($conn);

    $stmt = $conn->prepare(
        "SELECT * FROM queue WHERE service_date = ? AND extraction_station = ? AND status = 'extracting' LIMIT 1"
    );
    $stmt->bind_param('si', $service_date, $station);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($existing) return $existing;

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $stmt = $conn->prepare(
            "SELECT id FROM queue WHERE service_date = ? AND status = 'ready_for_extraction'
             ORDER BY extraction_eligible_at ASC, queue_number ASC LIMIT 1"
        );
        $stmt->bind_param('s', $service_date);
        $stmt->execute();
        $candidate = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$candidate) {
            return null;
        }
        $id = (int) $candidate['id'];

        $stmt = $conn->prepare(
            "UPDATE queue SET status = 'extracting', extraction_called_at = NOW(),
                extraction_station = ?, extraction_by = ?
             WHERE id = ? AND status = 'ready_for_extraction'"
        );
        $stmt->bind_param('isi', $station, $staff_name, $id);
        $stmt->execute();
        $claimed = $stmt->affected_rows === 1;
        $stmt->close();

        if ($claimed) {
            $row = find_queue_row($conn, $id);
            log_activity($conn, $staff_name, $station, $row['queue_number'], 'call_for_extraction');
            return $row;
        }
        // else: another station claimed this row first — loop and retry
    }
    return null;
}

function complete_extraction($conn, $id, $staff_name) {
    $row = find_queue_row($conn, $id);
    if (!$row || $row['status'] !== 'extracting') return false;

    $stmt = $conn->prepare(
        "UPDATE queue SET status = 'completed', extraction_completed_at = NOW()
         WHERE id = ? AND status = 'extracting'"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $ok = $stmt->affected_rows === 1;
    $stmt->close();

    if ($ok) {
        log_activity($conn, $staff_name, (int) $row['extraction_station'], $row['queue_number'], 'complete_extraction');
    }
    return $ok;
}

/**
 * Patient didn't respond when called for interview or extraction. Does not
 * change status — a no-show is only declared via mark_no_show() once a
 * caller decides the recall_limit has been hit.
 */
function recall($conn, $id, $staff_name) {
    $row = find_queue_row($conn, $id);
    if (!$row) return false;
    if (!in_array($row['status'], ['interviewing', 'extracting'], true)) return false;

    $limit = (int) get_setting($conn, 'recall_limit', 3);
    if ((int) $row['recall_count'] >= $limit) return false;

    $stmt = $conn->prepare("UPDATE queue SET recall_count = recall_count + 1 WHERE id = ? AND status = ?");
    $stmt->bind_param('is', $id, $row['status']);
    $stmt->execute();
    $ok = $stmt->affected_rows === 1;
    $stmt->close();

    if ($ok) {
        $station = $row['status'] === 'interviewing' ? (int) $row['interview_station'] : (int) $row['extraction_station'];
        log_activity($conn, $staff_name, $station, $row['queue_number'], 'recall');
    }
    return $ok;
}

function mark_no_show($conn, $id, $staff_name) {
    $row = find_queue_row($conn, $id);
    if (!$row) return false;
    if (!in_array($row['status'], ['waiting', 'interviewing', 'ready_for_extraction', 'extracting'], true)) {
        return false;
    }

    $from_status = $row['status'];
    $stmt = $conn->prepare("UPDATE queue SET status = 'no_show', no_show_at = NOW() WHERE id = ? AND status = ?");
    $stmt->bind_param('is', $id, $from_status);
    $stmt->execute();
    $ok = $stmt->affected_rows === 1;
    $stmt->close();

    if ($ok) {
        $station = $from_status === 'interviewing' ? (int) $row['interview_station']
            : ($from_status === 'extracting' ? (int) $row['extraction_station'] : 0);
        log_activity($conn, $staff_name, $station, $row['queue_number'], 'no_show');
    }
    return $ok;
}

/**
 * Brings a no_show ticket back into the extraction queue. Always sets a
 * FRESH extraction_eligible_at (NOW(6)), so the patient goes to the back of
 * the extraction queue rather than reclaiming their old position.
 */
function reinstate($conn, $id, $staff_name) {
    $row = find_queue_row($conn, $id);
    if (!$row || $row['status'] !== 'no_show') return false;

    $stmt = $conn->prepare(
        "UPDATE queue SET status = 'ready_for_extraction', extraction_eligible_at = NOW(6)
         WHERE id = ? AND status = 'no_show'"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $ok = $stmt->affected_rows === 1;
    $stmt->close();

    if ($ok) {
        log_activity($conn, $staff_name, 0, $row['queue_number'], 'reinstate');
    }
    return $ok;
}

function cancel_ticket($conn, $id, $staff_name) {
    $row = find_queue_row($conn, $id);
    if (!$row) return false;
    if (in_array($row['status'], ['completed', 'cancelled', 'no_show'], true)) return false;

    $from_status = $row['status'];
    $stmt = $conn->prepare("UPDATE queue SET status = 'cancelled' WHERE id = ? AND status = ?");
    $stmt->bind_param('is', $id, $from_status);
    $stmt->execute();
    $ok = $stmt->affected_rows === 1;
    $stmt->close();

    if ($ok) {
        log_activity($conn, $staff_name, 0, $row['queue_number'], 'cancel');
    }
    return $ok;
}

function find_queue_row($conn, $id) {
    $stmt = $conn->prepare("SELECT * FROM queue WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function get_interview_queue($conn, $service_date = null) {
    $service_date = $service_date ?: service_date_now($conn);
    $stmt = $conn->prepare(
        "SELECT * FROM queue WHERE service_date = ? AND status = 'waiting' ORDER BY queue_number ASC"
    );
    $stmt->bind_param('s', $service_date);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Same ordering rule as call_next_extraction: extraction_eligible_at ASC,
 * queue_number ASC only as a tie-breaker. NOT ordered by queue_number.
 */
function get_extraction_queue($conn, $service_date = null) {
    $service_date = $service_date ?: service_date_now($conn);
    $stmt = $conn->prepare(
        "SELECT * FROM queue WHERE service_date = ? AND status = 'ready_for_extraction'
         ORDER BY extraction_eligible_at ASC, queue_number ASC"
    );
    $stmt->bind_param('s', $service_date);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function get_awaiting_payment($conn, $service_date = null) {
    $service_date = $service_date ?: service_date_now($conn);
    $stmt = $conn->prepare(
        "SELECT * FROM queue WHERE service_date = ? AND status = 'awaiting_payment'
         ORDER BY interview_completed_at ASC"
    );
    $stmt->bind_param('s', $service_date);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Records a "ready for claiming" entry: a patient whose results from a prior
 * visit are ready for pickup. Unlike everything else in this file, this is
 * standalone data entry with no queue_number/ticket behind it — service_date
 * is stamped for reporting only, it is NOT used to scope get_claimable_results()
 * (see that function's docblock).
 */
function add_claimable_result($conn, $surname, $first_name_initials, $staff_name) {
    $service_date = service_date_now($conn);
    $stmt = $conn->prepare(
        "INSERT INTO claimable_results (service_date, surname, first_name_initials, added_by) VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param('ssss', $service_date, $surname, $first_name_initials, $staff_name);
    $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();
    return $id;
}

/**
 * Unclaimed "ready for claiming" entries, oldest first. NOT scoped to
 * service_date — entries persist across day rollovers until explicitly
 * claimed via mark_result_claimed(), unlike every other list in this file.
 */
function get_claimable_results($conn) {
    $stmt = $conn->prepare(
        "SELECT * FROM claimable_results WHERE claimed_at IS NULL ORDER BY created_at ASC"
    );
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Guarded on claimed_at IS NULL so a double-submit (two clicks/tabs) is a
 * no-op on the second call, same pattern as the rest of this file's
 * guarded UPDATEs.
 */
function mark_result_claimed($conn, $id, $staff_name) {
    $stmt = $conn->prepare(
        "UPDATE claimable_results SET claimed_at = NOW(), claimed_by = ? WHERE id = ? AND claimed_at IS NULL"
    );
    $stmt->bind_param('si', $staff_name, $id);
    $stmt->execute();
    $ok = $stmt->affected_rows === 1;
    $stmt->close();
    return $ok;
}

function get_now_serving($conn, $service_date = null) {
    $service_date = $service_date ?: service_date_now($conn);
    $stmt = $conn->prepare(
        "SELECT *,
            CASE status WHEN 'interviewing' THEN interview_called_at WHEN 'extracting' THEN extraction_called_at END AS called_at,
            CASE status WHEN 'interviewing' THEN interview_station WHEN 'extracting' THEN extraction_station END AS station
         FROM queue
         WHERE service_date = ? AND status IN ('interviewing', 'extracting')
         ORDER BY called_at DESC"
    );
    $stmt->bind_param('s', $service_date);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}
?>
