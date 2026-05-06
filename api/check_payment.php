<?php
/**
 * api/check_payment.php — Polling endpoint
 * Trả về JSON { confirmed: true/false } cho front-end kiểm tra
 * trạng thái xác nhận thanh toán.
 */
header('Content-Type: application/json; charset=utf-8');

session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['confirmed' => false]);
    exit;
}

require_once __DIR__ . '/../config/db.php';

$orderId = (int)($_GET['order_id'] ?? 0);
if ($orderId <= 0) {
    echo json_encode(['confirmed' => false]);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$st = $conn->prepare(
    "SELECT status, paid_at FROM orders WHERE id = ? AND user_id = ? LIMIT 1"
);
$st->bind_param('ii', $orderId, $userId);
$st->execute();
$row = $st->get_result()->fetch_assoc();

$confirmed = $row && in_array($row['status'], ['processing','shipping','delivered','reviewed'], true);
echo json_encode(['confirmed' => $confirmed]);
