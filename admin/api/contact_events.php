<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(401);
    exit;
}

require_once '../../config/db.php';

// Giải phóng session lock — cho phép các tab admin khác đọc/ghi session bình thường
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

$lastCount = -1;
$tick      = 0;

while (true) {
    if (connection_aborted()) break;
    if (!$conn->ping())       break;

    $res   = $conn->query("SELECT COUNT(*) FROM contacts WHERE status='new'");
    $count = $res ? (int)$res->fetch_row()[0] : 0;

    if ($tick === 0 || $count !== $lastCount) {
        echo 'data: ' . json_encode(['new_count' => $count]) . "\n\n";
        $lastCount = $count;
    }

    // Comment keepalive mỗi ~30 giây để tránh proxy timeout
    if ($tick % 6 === 5) {
        echo ": keepalive\n\n";
    }

    flush();
    $tick++;
    sleep(5);
}
