<?php
// Clear notify.json by writing an empty object
file_put_contents('notify.json', '{}');
header('Content-Type: application/json');
echo json_encode(['status' => 'cleared']); 