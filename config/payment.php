<?php
/* ── Pay2S Sandbox Configuration ─────────────────────────────
   Để dùng production thay BASE_URL bằng URL thật của Pay2S
   và cập nhật ACCOUNT_NO / ACCOUNT_NAME tương ứng.
─────────────────────────────────────────────────────────────── */
define('PAY2S_BASE_URL',     'https://sandbox.pay2s.vn/client/account-bank/ACB/999999999');
define('PAY2S_BANK_CODE',    'ACB');
define('PAY2S_ACCOUNT_NO',   '999999999');
define('PAY2S_ACCOUNT_NAME', 'MOCTRA THAI NGUYEN');
define('PAY2S_ORDER_PREFIX', 'MOCTRA_');

/**
 * Tạo link thanh toán Pay2S cho một đơn hàng.
 *
 * @param int $orderId  ID đơn hàng trong bảng orders
 * @param int $amount   Tổng tiền (VNĐ, integer)
 * @return string       URL đầy đủ dẫn đến trang thanh toán Pay2S
 */
function pay2s_url(int $orderId, int $amount): string
{
    $memo = PAY2S_ORDER_PREFIX . $orderId;
    return PAY2S_BASE_URL
        . '?amount=' . $amount
        . '&memo='   . urlencode($memo);
}

/**
 * Sinh mã đơn hàng theo quy tắc: ORD + YYYYMMDD + - + ID 4 chữ số.
 * Ví dụ: ORD20250501-0022
 */
function generate_order_code(int $orderId): string
{
    return 'ORD' . date('Ymd') . '-' . str_pad($orderId, 4, '0', STR_PAD_LEFT);
}

/**
 * Trả về URL ảnh QR từ api.qrserver.com (miễn phí, không cần API key).
 *
 * @param string $data  Nội dung QR (thường là order_code)
 * @param int    $size  Kích thước pixel (mặc định 200)
 */
function order_qr_url(string $data, int $size = 200): string
{
    return 'https://api.qrserver.com/v1/create-qr-code/'
        . '?size=' . $size . 'x' . $size
        . '&data=' . urlencode($data)
        . '&ecc=M&margin=4';
}
