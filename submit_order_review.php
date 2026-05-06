<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();
require_once 'config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: order_history.php'); exit();
}

// 1. Kiểm tra CSRF & Login
if (empty($_SESSION['user_id'])) {
    header('Location: login.php'); exit();
}
if (empty($_POST['_csrf']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['_csrf'])) {
    die('Invalid CSRF token');
}

$orderId = (int)($_POST['order_id'] ?? 0);
$rating  = (int)($_POST['rating'] ?? 5);
$comment = trim($_POST['comment'] ?? '');
$userId  = (int)$_SESSION['user_id'];

if (!$orderId) {
    header('Location: order_history.php'); exit();
}

// 2. Xác minh đơn hàng thuộc về user và đang ở trạng thái 'delivered'
$stOrder = $conn->prepare("SELECT status FROM orders WHERE id = ? AND user_id = ? LIMIT 1");
$stOrder->bind_param('ii', $orderId, $userId);
$stOrder->execute();
$order = $stOrder->get_result()->fetch_assoc();

if (!$order || $order['status'] !== 'delivered') {
    // Nếu đơn hàng không hợp lệ hoặc đã đánh giá rồi
    header('Location: order_history.php?error=invalid_order');
    exit();
}

// 3. Lấy danh sách sản phẩm trong đơn hàng
$stItems = $conn->prepare("SELECT product_id FROM order_items WHERE order_id = ?");
$stItems->bind_param('i', $orderId);
$stItems->execute();
$items = $stItems->get_result()->fetch_all(MYSQLI_ASSOC);

// 4. Lưu đánh giá cho từng sản phẩm
// Chúng ta sẽ dùng order_id làm định danh nguồn gốc
foreach ($items as $item) {
    $pId = $item['product_id'];
    $stRev = $conn->prepare(
        "INSERT INTO product_reviews (product_id, order_id, user_id, rating, comment, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE rating=VALUES(rating), comment=VALUES(comment), created_at=NOW()"
    );
    $stRev->bind_param('iiiis', $pId, $orderId, $userId, $rating, $comment);
    $stRev->execute();
}

// 5. Cập nhật trạng thái đơn hàng sang 'reviewed'
// Chú ý: Vì DB có thể chưa được chạy migration, tôi sẽ dùng lệnh SQL an toàn
$stUpd = $conn->prepare("UPDATE orders SET status = 'reviewed', updated_at = NOW() WHERE id = ?");
$stUpd->bind_param("i", $orderId);
$stUpd->execute();

// 6. Quay lại trang lịch sử
header('Location: order_history.php?status=delivered&reviewed_order=' . $orderId);
exit();


