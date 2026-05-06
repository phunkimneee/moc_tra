<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';

header('Content-Type: application/json');

// Đảm bảo bảng tồn tại trước khi thao tác
$conn->query("CREATE TABLE IF NOT EXISTS `inventory_history` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`    INT UNSIGNED NOT NULL,
  `admin_id`      INT UNSIGNED DEFAULT NULL,
  `change_amount` INT          NOT NULL DEFAULT 0,
  `old_stock`     INT          NOT NULL DEFAULT 0,
  `new_stock`     INT          NOT NULL DEFAULT 0,
  `note`          VARCHAR(255) NOT NULL DEFAULT '',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Phương thức không hợp lệ.']);
    exit;
}

// CSRF
if (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Phiên làm việc hết hạn. Vui lòng tải lại trang.']);
    exit;
}

$productId    = (int)($_POST['product_id']    ?? 0);
$changeAmount = (int)($_POST['change_amount'] ?? 0);
$note         = trim($_POST['note']           ?? '');

if ($productId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID sản phẩm không hợp lệ.']);
    exit;
}
if ($changeAmount === 0) {
    echo json_encode(['success' => false, 'message' => 'Số lượng thay đổi không được bằng 0.']);
    exit;
}
if ($note === '') {
    echo json_encode(['success' => false, 'message' => 'Vui lòng nhập lý do thay đổi.']);
    exit;
}
if (mb_strlen($note) > 255) {
    echo json_encode(['success' => false, 'message' => 'Lý do quá dài (tối đa 255 ký tự).']);
    exit;
}

$adminId = (int)$_SESSION['user_id'];

// Lấy tồn kho hiện tại
$st = $conn->prepare("SELECT stock FROM products WHERE id = ?");
if (!$st) {
    echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống: ' . $conn->error]);
    exit;
}
$st->bind_param('i', $productId);
$st->execute();
$row = $st->get_result()->fetch_assoc();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Không tìm thấy sản phẩm.']);
    exit;
}

$oldStock = (int)$row['stock'];
$newStock = $oldStock + $changeAmount;

if ($newStock < 0) {
    echo json_encode([
        'success' => false,
        'message' => "Không thể xuất quá tồn kho hiện có ($oldStock). Số lượng xuất tối đa: $oldStock."
    ]);
    exit;
}

// Cập nhật tồn kho
$upd = $conn->prepare("UPDATE products SET stock = ? WHERE id = ?");
if (!$upd) {
    echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống: ' . $conn->error]);
    exit;
}
$upd->bind_param('ii', $newStock, $productId);
if (!$upd->execute()) {
    echo json_encode(['success' => false, 'message' => 'Lỗi cập nhật cơ sở dữ liệu.']);
    exit;
}

// Ghi log vào inventory_history
$log = $conn->prepare(
    "INSERT INTO inventory_history (product_id, admin_id, change_amount, old_stock, new_stock, note, created_at)
     VALUES (?, ?, ?, ?, ?, ?, NOW())"
);
if ($log) {
    $log->bind_param('iiiiis', $productId, $adminId, $changeAmount, $oldStock, $newStock, $note);
    $log->execute();
}

$sign = $changeAmount > 0 ? "+$changeAmount" : "$changeAmount";
echo json_encode([
    'success'   => true,
    'message'   => "Cập nhật thành công! Tồn kho: $oldStock → $newStock ($sign).",
    'new_stock' => $newStock,
    'old_stock' => $oldStock,
    'change'    => $changeAmount,
]);
