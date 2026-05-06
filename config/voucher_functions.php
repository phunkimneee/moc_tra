<?php
/* ============================================================
 *  Voucher Auto-Gift Functions
 *  Include: require_once __DIR__ . '/voucher_functions.php';
 * ============================================================ */

define('VOUCHER_THRESHOLD',     500000); // đơn ≥ 500,000đ → được tặng
define('VOUCHER_DISCOUNT',       50000); // giảm 50,000đ
define('VOUCHER_VALIDITY_DAYS',     30); // hiệu lực 30 ngày

/**
 * Kiểm tra đơn hàng và tặng voucher nếu đủ điều kiện.
 * Gọi sau khi đơn chuyển sang status = 'delivered'.
 */
function checkAndIssueVoucher(int $orderId, mysqli $conn): bool
{
    $st = $conn->prepare(
        "SELECT user_id, total, is_gifted FROM orders WHERE id = ? AND status = 'delivered' LIMIT 1"
    );
    $st->bind_param('i', $orderId);
    $st->execute();
    $order = $st->get_result()->fetch_assoc();

    if (!$order)                                   return false;
    if ((int)$order['is_gifted'])                  return false; // chống tặng trùng
    if (!(int)$order['user_id'])                   return false; // guest order
    if ((int)$order['total'] < VOUCHER_THRESHOLD)  return false;

    $userId = (int)$order['user_id'];

    // Sinh mã duy nhất, retry nếu trùng
    do {
        $code = 'MOCTRA_' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $chk  = $conn->prepare("SELECT id FROM coupons WHERE code = ? LIMIT 1");
        $chk->bind_param('s', $code);
        $chk->execute();
    } while ($chk->get_result()->num_rows > 0);

    $expiresAt = date('Y-m-d', strtotime('+' . VOUCHER_VALIDITY_DAYS . ' days'));
    $value     = VOUCHER_DISCOUNT;

    // Tạo coupon private
    $stC = $conn->prepare(
        "INSERT INTO coupons
           (code, discount_type, discount_value, min_order, max_uses, expires_at,
            is_active, coupon_role, specific_user_id, condition_type, condition_value)
         VALUES (?, 'fixed', ?, 0, 1, ?, 1, 'private', ?, 'none', 0)"
    );
    $stC->bind_param('sisi', $code, $value, $expiresAt, $userId);
    if (!$stC->execute()) return false;

    // Đánh dấu đơn đã tặng
    $stG = $conn->prepare("UPDATE orders SET is_gifted = 1 WHERE id = ?");
    $stG->bind_param('i', $orderId);
    $stG->execute();

    // Gửi thông báo cho khách
    $amt     = number_format(VOUCHER_DISCOUNT, 0, ',', '.') . 'đ';
    $expDate = date('d/m/Y', strtotime($expiresAt));
    $msg     = "Chúc mừng! Đơn hàng #{$orderId} đã giao thành công. Bạn được tặng Voucher <strong>{$code}</strong> giảm {$amt} cho đơn tiếp theo (HSD: {$expDate}). Vào Kho Voucher để xem chi tiết!";

    sendUserNotification($userId, $msg, $conn);
    return true;
}

/**
 * Ghi thông báo vào bảng notifications.
 */
function sendUserNotification(int $userId, string $message, mysqli $conn, string $type = 'voucher_gifted', int $referenceId = 0): bool
{
    $st = $conn->prepare(
        "INSERT INTO notifications (user_id, type, reference_id, message) VALUES (?, ?, ?, ?)"
    );
    $st->bind_param('isis', $userId, $type, $referenceId, $message);
    return (bool)$st->execute();
}
