<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();

require_once '../config/db.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    http_response_code(401);
    exit;
}

$userId = (int)$_SESSION['user_id'];

// Release session lock so other tabs can read/write session normally
session_write_close();

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

@ini_set('zlib.output_compression', 0);
if (ob_get_level()) ob_end_flush();
ob_implicit_flush(true);

set_time_limit(0);
ignore_user_abort(true);

$stCount = $conn->prepare(
    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
);
$stCount->bind_param('i', $userId);

$lastCount = -1;
$tick      = 0;

while (true) {
    if (connection_aborted()) break;
    if (!$conn->ping())       break;

    $stCount->execute();
    $count = (int)$stCount->get_result()->fetch_row()[0];

    if ($tick === 0 || $count !== $lastCount) {
        echo 'data: ' . json_encode(['unread_count' => $count]) . "\n\n";
        $lastCount = $count;
    }

    // Keepalive comment every ~90 s to prevent proxy/Apache timeout
    if ($tick % 3 === 2) {
        echo ": keepalive\n\n";
    }

    flush();
    $tick++;
    sleep(30);
}
