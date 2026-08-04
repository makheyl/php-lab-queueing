<?php
require 'config.php';
require 'queue_functions.php';
header('Content-Type: application/json');

$service_date = service_date_now($conn);

function count_status($conn, $service_date, $status) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM queue WHERE service_date = ? AND status = ?");
    $stmt->bind_param('ss', $service_date, $status);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) $row['cnt'];
}

$stmt = $conn->prepare(
    "SELECT MAX(GREATEST(
        IFNULL(created_at, '1970-01-01'),
        IFNULL(interview_called_at, '1970-01-01'),
        IFNULL(interview_completed_at, '1970-01-01'),
        IFNULL(payment_confirmed_at, '1970-01-01'),
        IFNULL(extraction_eligible_at, '1970-01-01'),
        IFNULL(extraction_called_at, '1970-01-01'),
        IFNULL(extraction_completed_at, '1970-01-01'),
        IFNULL(no_show_at, '1970-01-01')
    )) AS last_update FROM queue WHERE service_date = ?"
);
$stmt->bind_param('s', $service_date);
$stmt->execute();
$last_update = $stmt->get_result()->fetch_assoc()['last_update'];
$stmt->close();

echo json_encode([
    // Included so every poller (encoder/extraction/display) forces a reload the
    // moment service_date rolls over, even on a night with zero count changes —
    // a display board left running overnight must still reset without a manual reload.
    'service_date' => $service_date,
    'waiting' => count_status($conn, $service_date, 'waiting'),
    'interviewing' => count_status($conn, $service_date, 'interviewing'),
    'awaiting_payment' => count_status($conn, $service_date, 'awaiting_payment'),
    'ready_for_extraction' => count_status($conn, $service_date, 'ready_for_extraction'),
    'extracting' => count_status($conn, $service_date, 'extracting'),
    'completed' => count_status($conn, $service_date, 'completed'),
    'last_update' => $last_update,
]);
