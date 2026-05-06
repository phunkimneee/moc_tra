<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();
require_once 'config/db.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    echo json_encode(['ok' => false, 'msg' => 'Vui lòng đăng nhập trước.']);
    exit();
}

$code     = strtoupper(trim($_GET['code'] ?? ''));
$subtotal = max(0, (int)($_GET['subtotal'] ?? 0));

if (!$code) {
    echo json_encode(['ok' => false, 'msg' => 'Vui lòng nhập mã giảm giá.']);
    exit();
}

$conn->query("CREATE TABLE IF NOT EXISTS coupons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    discount_type ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
    discount_value INT UNSIGNED NOT NULL DEFAULT 0,
    min_order INT UNSIGNED NOT NULL DEFAULT 0,
    max_uses INT NOT NULL DEFAULT 0,
    used_count INT NOT NULL DEFAULT 0,
    expires_at DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$st = $conn->prepare("SELECT * FROM coupons WHERE code=? AND is_active=1 LIMIT 1");
$st->bind_param('s', $code);
$st->execute();
$coupon = $st->get_result()->fetch_assoc();

/* Helper: clear session coupon khi validation thất bại */
function rejectCoupon(string $msg): void {
    unset($_SESSION['coupon']);
    echo json_encode(['ok' => false, 'msg' => $msg]);
    exit();
}

if (!$coupon)                                                    rejectCoupon('Mã giảm giá không hợp lệ hoặc đã bị vô hiệu.');
if ($coupon['expires_at'] && $coupon['expires_at'] < date('Y-m-d')) rejectCoupon('Mã giảm giá đã hết hạn.');
if ($coupon['max_uses'] > 0 && $coupon['used_count'] >= $coupon['max_uses']) rejectCoupon('Mã giảm giá đã hết lượt sử dụng.');
if ($coupon['min_order'] > 0 && $subtotal < $coupon['min_order']) {
    $min = number_format($coupon['min_order'], 0, ',', '.') . 'đ';
    rejectCoupon("Đơn tối thiểu {$min} để dùng mã này.");
}

/* ── Kiểm tra phân quyền mã ── */
$currentUserId = (int)$_SESSION['user_id'];
$couponRole    = $coupon['coupon_role'] ?? 'public';

if ($couponRole === 'private') {
    $allowedUser = (int)($coupon['specific_user_id'] ?? 0);
    if ($allowedUser && $allowedUser !== $currentUserId) {
        rejectCoupon('Mã giảm giá này không dành cho tài khoản của bạn.');
    }
}

/* ── Kiểm tra điều kiện sử dụng ── */
$condType  = $coupon['condition_type'] ?? 'none';
$condValue = (int)($coupon['condition_value'] ?? 0);

if ($condType === 'min_spent') {
    $stSpent = $conn->prepare("SELECT COALESCE(SUM(total),0) FROM orders WHERE user_id=? AND status='completed'");
    $stSpent->bind_param('i', $currentUserId);
    $stSpent->execute();
    $totalSpent = (int)$stSpent->get_result()->fetch_row()[0];
    if ($totalSpent < $condValue) {
        $need = number_format($condValue, 0, ',', '.') . 'đ';
        rejectCoupon("Bạn cần chi tiêu tổng cộng {$need} (đơn hoàn thành) để dùng mã này.");
    }
} elseif ($condType === 'new_member') {
    $stOrders = $conn->prepare("SELECT COUNT(*) FROM orders WHERE user_id=? AND status='completed'");
    $stOrders->bind_param('i', $currentUserId);
    $stOrders->execute();
    $orderCount = (int)$stOrders->get_result()->fetch_row()[0];
    if ($orderCount > 0) {
        rejectCoupon('Mã này chỉ dành cho thành viên chưa từng mua hàng tại Mộc Trà.');
    }
}

$discount = $coupon['discount_type'] === 'percent'
    ? (int)round($subtotal * $coupon['discount_value'] / 100)
    : (int)$coupon['discount_value'];
$discount = min($discount, $subtotal);

/* Lưu vào session để checkout.php pre-fill */
$_SESSION['coupon'] = [
    'id'       => (int)$coupon['id'],
    'code'     => $code,
    'discount' => $discount,
    'type'     => $coupon['discount_type'],
    'value'    => (int)$coupon['discount_value'],
];

echo json_encode([
    'ok'       => true,
    'code'     => $code,
    'discount' => $discount,
    'msg'      => $coupon['discount_type'] === 'percent'
        ? "Giảm {$coupon['discount_value']}% — tiết kiệm " . number_format($discount, 0, ',', '.') . 'đ'
        : 'Giảm ' . number_format($discount, 0, ',', '.') . 'đ',
]);


