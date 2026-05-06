<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: order_history.php'); exit();
}

// CSRF check
if (empty($_POST['_csrf']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['_csrf'])) {
    header('Location: order_history.php'); exit();
}

$user_id  = (int)$_SESSION['user_id'];
$order_id = (int)($_POST['order_id'] ?? 0);

if ($order_id) {
    $conn->begin_transaction();
    try {
        /* Xác nhận đơn tồn tại, thuộc user này và đang chờ xác nhận */
        $stCheck = $conn->prepare(
            "SELECT id FROM orders WHERE id=? AND user_id=? AND status='pending' LIMIT 1"
        );
        $stCheck->bind_param('ii', $order_id, $user_id);
        $stCheck->execute();

        if ($stCheck->get_result()->num_rows > 0) {
            /* Lấy thông tin stock hiện tại để ghi log */
            $stItems = $conn->prepare(
                "SELECT oi.product_id, oi.qty, p.stock as old_stock
                 FROM order_items oi
                 JOIN products p ON p.id = oi.product_id
                 WHERE oi.order_id = ?"
            );
            $stItems->bind_param('i', $order_id);
            $stItems->execute();
            $items = $stItems->get_result()->fetch_all(MYSQLI_ASSOC);

            /* Hoàn lại stock cho từng sản phẩm trong đơn */
            $stRestore = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
            $logStmt = $conn->prepare("INSERT INTO inventory_history (product_id, change_amount, old_stock, new_stock, note, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            
            foreach ($items as $item) {
                $stRestore->bind_param('ii', $item['qty'], $item['product_id']);
                $stRestore->execute();

                // Ghi lại lịch sử kho
                $changeAmount = (int)$item['qty'];
                $newStock = (int)$item['old_stock'] + $changeAmount;
                $note = "Khách hủy đơn #" . $order_id;
                $logStmt->bind_param('iiiis', $item['product_id'], $changeAmount, $item['old_stock'], $newStock, $note);
                $logStmt->execute();
            }

            /* Hủy đơn hàng */
            $stCancel = $conn->prepare(
                "UPDATE orders SET status='cancelled', updated_at=NOW()
                 WHERE id=? AND user_id=? AND status='pending'"
            );
            $stCancel->bind_param('ii', $order_id, $user_id);
            $stCancel->execute();
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Cancel order failed: ' . $e->getMessage());
    }
}

header("Location: order_history.php?order_id=$order_id");
exit();


