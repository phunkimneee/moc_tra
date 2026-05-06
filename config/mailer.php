<?php
/*
 * Moctra Mailer — simple email helper.
 * Dev mode: logs emails to logs/email_YYYY-MM-DD.log instead of sending.
 * Prod mode: set MAIL_DEV=false and configure SMTP via php.ini or swap this
 * function body for PHPMailer when available.
 */

$isProd = (getenv('APP_ENV') === 'production');
define('MAIL_DEV',      $isProd ? false : true); // Tự động false nếu trên môi trường production
define('MAIL_FROM',     'noreply@moctra.local');
define('MAIL_FROM_NAME','Mộc Trà Thái Nguyên');
define('MAIL_LOG_DIR',  dirname(__DIR__) . '/logs');

function moctra_send_email(string $to, string $subject, string $htmlBody): bool
{
    $logDir = MAIL_LOG_DIR;
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    $logFile = $logDir . '/email_' . date('Y-m-d') . '.log';
    $entry   = sprintf(
        "[%s] TO: %s | SUBJECT: %s\n%s\n%s\n",
        date('Y-m-d H:i:s'),
        $to,
        $subject,
        $htmlBody,
        str_repeat('-', 80)
    );
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);

    if (MAIL_DEV) {
        return true; // dev mode: only log, don't send
    }

    $from    = MAIL_FROM_NAME . ' <' . MAIL_FROM . '>';
    $headers = implode("\r\n", [
        'From: ' . $from,
        'Reply-To: ' . MAIL_FROM,
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'X-Mailer: MoctraMailer/1.0',
    ]);

    return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $htmlBody, $headers);
}

function moctra_email_order_confirm(string $to, string $name, int $orderId, int $total, array $items): bool
{
    $itemRows = '';
    foreach ($items as $item) {
        $itemRows .= '<tr>
            <td style="padding:8px 12px;border-bottom:1px solid #f3f4f6">' . htmlspecialchars($item['product_name']) . '</td>
            <td style="padding:8px 12px;border-bottom:1px solid #f3f4f6;text-align:center">' . (int)$item['qty'] . '</td>
            <td style="padding:8px 12px;border-bottom:1px solid #f3f4f6;text-align:right">' . number_format((int)$item['price'], 0, ',', '.') . 'đ</td>
        </tr>';
    }

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;background:#f9fafb;padding:24px">
<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)">
  <div style="background:#166534;padding:24px 28px">
    <h1 style="color:#fff;margin:0;font-size:20px">Mộc Trà Thái Nguyên</h1>
    <p style="color:rgba(255,255,255,.8);margin:4px 0 0;font-size:14px">Xác nhận đơn hàng</p>
  </div>
  <div style="padding:24px 28px">
    <p style="color:#111827;font-size:15px">Xin chào <strong>' . htmlspecialchars($name) . '</strong>,</p>
    <p style="color:#374151;font-size:14px;line-height:1.6">Đơn hàng <strong>#' . $orderId . '</strong> của bạn đã được tiếp nhận thành công. Chúng tôi sẽ xử lý và liên hệ với bạn sớm nhất.</p>
    <table style="width:100%;border-collapse:collapse;margin:16px 0">
      <thead><tr style="background:#f0fdf4">
        <th style="padding:10px 12px;text-align:left;font-size:13px;color:#166534">Sản phẩm</th>
        <th style="padding:10px 12px;text-align:center;font-size:13px;color:#166534">SL</th>
        <th style="padding:10px 12px;text-align:right;font-size:13px;color:#166534">Thành tiền</th>
      </tr></thead>
      <tbody>' . $itemRows . '</tbody>
    </table>
    <p style="text-align:right;font-size:16px;font-weight:700;color:#dc2626">Tổng cộng: ' . number_format($total, 0, ',', '.') . 'đ</p>
    <p style="color:#6b7280;font-size:13px;margin-top:24px">Cảm ơn bạn đã tin tưởng mua sắm tại Mộc Trà!</p>
  </div>
  <div style="background:#f9fafb;padding:14px 28px;font-size:12px;color:#9ca3af;border-top:1px solid #f3f4f6">
    © 2025 Mộc Trà Thái Nguyên. Bảo lưu mọi quyền.
  </div>
</div>
</body></html>';

    return moctra_send_email($to, 'Xác nhận đơn hàng #' . $orderId . ' — Mộc Trà', $html);
}

function moctra_email_order_status(string $to, string $name, int $orderId, string $status): bool
{
    $labels = [
        'processing' => ['Đơn hàng đang được xử lý', 'Đơn hàng của bạn đang được chuẩn bị và đóng gói.', '#3b82f6'],
        'shipping'   => ['Đơn hàng đang được giao',  'Đơn hàng của bạn đã được bàn giao cho đơn vị vận chuyển.', '#8b5cf6'],
        'delivered'  => ['Đơn hàng đã giao thành công', 'Đơn hàng của bạn đã được giao. Cảm ơn bạn đã mua sắm!', '#10b981'],
        'cancelled'  => ['Đơn hàng đã bị hủy', 'Đơn hàng của bạn đã bị hủy. Liên hệ hỗ trợ nếu cần.', '#ef4444'],
    ];
    if (!isset($labels[$status])) return false;

    [$title, $body, $color] = $labels[$status];

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;background:#f9fafb;padding:24px">
<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)">
  <div style="background:' . $color . ';padding:24px 28px">
    <h1 style="color:#fff;margin:0;font-size:18px">' . $title . '</h1>
    <p style="color:rgba(255,255,255,.8);margin:4px 0 0;font-size:13px">Đơn hàng #' . $orderId . '</p>
  </div>
  <div style="padding:24px 28px">
    <p style="color:#111827;font-size:15px">Xin chào <strong>' . htmlspecialchars($name) . '</strong>,</p>
    <p style="color:#374151;font-size:14px;line-height:1.6">' . $body . '</p>
    <p style="color:#6b7280;font-size:13px;margin-top:24px">Cảm ơn bạn đã tin tưởng mua sắm tại Mộc Trà!</p>
  </div>
  <div style="background:#f9fafb;padding:14px 28px;font-size:12px;color:#9ca3af;border-top:1px solid #f3f4f6">
    © 2025 Mộc Trà Thái Nguyên. Bảo lưu mọi quyền.
  </div>
</div>
</body></html>';

    return moctra_send_email($to, $title . ' — Đơn #' . $orderId, $html);
}
