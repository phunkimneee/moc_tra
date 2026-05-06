<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/voucher_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: orders.php'); exit(); }

if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    die('Invalid CSRF token');
}

$orderId = (int)($_POST['_confirm_id'] ?? 0);
$nextStatus = trim($_POST['_confirm_action'] ?? '');
$backUrl = $_POST['back_url'] ?? '';

// Lấy trạng thái hiện tại của đơn hàng
$stCurrent = $conn->prepare("SELECT status FROM orders WHERE id = ? LIMIT 1");
$stCurrent->bind_param('i', $orderId);
$stCurrent->execute();
$currentStatus = $stCurrent->get_result()->fetch_assoc()['status'] ?? '';

if (!$orderId || !$currentStatus) {
    header('Location: orders.php');
    exit();
}

// Logic quy trình tuyến tính (Linear Workflow)
$isValidTransition = false;

if ($nextStatus === 'cancelled') {
    // Cho phép hủy nếu đơn chưa giao thành công
    if ($currentStatus !== 'delivered' && $currentStatus !== 'cancelled') {
        $isValidTransition = true;
    }
} else {
    // Quy trình: pending -> processing -> shipping -> delivered
    $workflow = [
        'pending'    => 'processing',
        'processing' => 'shipping',
        'shipping'   => 'delivered'
    ];
    
    if (isset($workflow[$currentStatus]) && $workflow[$currentStatus] === $nextStatus) {
        $isValidTransition = true;
    }
}

if (!$isValidTransition) {
    // Nếu chuyển đổi không hợp lệ, quay lại trang trước với thông báo lỗi
    header('Location: orders.php?error=invalid_transition');
    exit();
}

$st = $conn->prepare("UPDATE orders SET status=?, updated_at=NOW() WHERE id=?");
$st->bind_param('si', $nextStatus, $orderId);
$st->execute();

// Hoàn lại kho nếu admin hủy đơn hàng
if ($nextStatus === 'cancelled' && $currentStatus !== 'cancelled') {
    $stItems = $conn->prepare(
        "SELECT oi.product_id, oi.qty, p.stock as old_stock
         FROM order_items oi
         JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id = ?"
    );
    $stItems->bind_param('i', $orderId);
    $stItems->execute();
    $items = $stItems->get_result()->fetch_all(MYSQLI_ASSOC);

    $stRestore = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
    $logStmt = $conn->prepare("INSERT INTO inventory_history (product_id, admin_id, change_amount, old_stock, new_stock, note, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $adminId = $_SESSION['user_id'] ?? null;
    
    foreach ($items as $item) {
        $stRestore->bind_param('ii', $item['qty'], $item['product_id']);
        $stRestore->execute();

        $changeAmount = (int)$item['qty'];
        $newStock = (int)$item['old_stock'] + $changeAmount;
        $note = "Admin hủy đơn #" . $orderId;
        $logStmt->bind_param('iiiiis', $item['product_id'], $adminId, $changeAmount, $item['old_stock'], $newStock, $note);
        $logStmt->execute();
    }
}

// Bust the cached badge count so the sidebar updates immediately
$_SESSION['_badge_ts'] = 0;

// Tặng voucher tự động khi đơn giao thành công
if ($nextStatus === 'delivered') {
    checkAndIssueVoucher($orderId, $conn);
}

// Gửi email thông báo thay đổi trạng thái
$stOrder = $conn->prepare(
    "SELECT o.full_name, u.email FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ? LIMIT 1"
);
$stOrder->bind_param('i', $orderId);
$stOrder->execute();
$orderRow = $stOrder->get_result()->fetch_assoc();
if ($orderRow && $orderRow['email']) {
    moctra_email_order_status($orderRow['email'], $orderRow['full_name'], $orderId, $nextStatus);
}

if ($backUrl && strpos($backUrl, 'order_detail') !== false) {
    header('Location: order_detail.php?id=' . $orderId . '&updated=1');
} else {
    header('Location: orders.php?updated=1');
}
exit();
