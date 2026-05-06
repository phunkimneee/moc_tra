<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Phương thức không hợp lệ.']);
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? '';
if ($csrfToken === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'Lỗi bảo mật (CSRF).']);
    exit;
}

$id    = (int)($_POST['id'] ?? 0);
$stock = (int)($_POST['stock'] ?? 0);

if ($id <= 0 || $stock < 0) {
    echo json_encode(['success' => false, 'message' => 'Dữ liệu không hợp lệ.']);
    exit;
}

$admin_id = $_SESSION['user_id'] ?? null;

try {
    // Lấy thông tin tồn kho hiện tại
    $st = $conn->prepare("SELECT stock FROM products WHERE id = ?");
    $st->bind_param('i', $id);
    $st->execute();
    $res = $st->get_result()->fetch_assoc();

    if (!$res) {
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy sản phẩm.']);
        exit;
    }

    $old_stock = (int)$res['stock'];
    $change_amount = $stock - $old_stock;

    if ($change_amount != 0) {
        // Cập nhật tồn kho
        $upd = $conn->prepare("UPDATE products SET stock = ?, updated_at = NOW() WHERE id = ?");
        $upd->bind_param('ii', $stock, $id);
        
        if ($upd->execute()) {
            // Ghi lại lịch sử thay đổi (Log)
            $note = "Cập nhật nhanh từ danh sách";
            $log = $conn->prepare("INSERT INTO inventory_history (product_id, admin_id, change_amount, old_stock, new_stock, note, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $log->bind_param('iiiiis', $id, $admin_id, $change_amount, $old_stock, $stock, $note);
            $log->execute();
            
            echo json_encode(['success' => true, 'message' => 'Cập nhật thành công.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Lỗi khi cập nhật cơ sở dữ liệu.']);
        }
    } else {
        echo json_encode(['success' => true, 'message' => 'Không có thay đổi nào.']);
    }
} catch (Throwable $e) {
    error_log('API Update Stock Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống. Vui lòng thử lại sau.']);
}
