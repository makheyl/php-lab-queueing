<?php
// config.php
date_default_timezone_set('Asia/Manila');

// Every screen requires this file first, so this is the one place to stop a
// PHP warning/notice or an uncaught fatal from producing a blank white page
// or leaking a raw stack trace to whoever's staffing the screen — an operator
// console and a 12-hour unattended kiosk board both need to fail visibly and
// calmly, not silently and blankly. Screens are otherwise fully independent
// (separate Apache requests) — this only protects each one from itself.
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_error_handler(function ($severity, $message, $file, $line) {
    error_log("PHP warning: $message in $file:$line");
    return true; // logged, not fatal — let the page keep rendering
});

register_shutdown_function(function () {
    $error = error_get_last();
    $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!$error || !in_array($error['type'], $fatal_types, true)) {
        return;
    }
    error_log('PHP fatal: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
    if (headers_sent()) {
        return; // output already started — nothing safe left to do
    }
    http_response_code(500);
    $is_json = false;
    foreach (headers_list() as $header) {
        if (stripos($header, 'Content-Type: application/json') === 0) {
            $is_json = true;
            break;
        }
    }
    if ($is_json) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'A server error occurred. Please try again.']);
    } else {
        echo '<!doctype html><html><body style="font-family:Segoe UI,sans-serif;text-align:center;padding:80px 20px;color:#232e3d;">'
           . '<h2>Something went wrong.</h2><p>Please refresh this page. If it keeps happening, notify IT.</p></body></html>';
    }
});

$host = 'localhost';
$user = 'root'; // change as needed
$pass = '';
$db = 'labqueue'; // change as needed

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}
?>
