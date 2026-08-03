<?php
require 'config.php';
header('Content-Type: application/json');

$waiting = $conn->query("SELECT COUNT(*) as cnt FROM queue WHERE status='waiting'");
$serving = $conn->query("SELECT COUNT(*) as cnt FROM queue WHERE status='serving'");
$pending = $conn->query("SELECT COUNT(*) as cnt FROM queue WHERE status='pending'");
$max_updated = $conn->query("SELECT MAX(GREATEST(IFNULL(created_at,0), IFNULL(served_at,0))) as last_update FROM queue");

echo json_encode([
    'waiting' => $waiting->fetch_assoc()['cnt'],
    'serving' => $serving->fetch_assoc()['cnt'],
    'pending' => $pending->fetch_assoc()['cnt'],
    'last_update' => $max_updated->fetch_assoc()['last_update']
]); 