<?php
/**
 * Pay2S Webhook Callback — Mộc Trà
 * ─────────────────────────────────────────────────────────────
 * Đăng ký URL này trong dashboard Pay2S:
 *   https://aptly-rubbing-bullpen.ngrok-free.dev/moctra/payment_callback.php
 *
 * ── Test bằng curl ───────────────────────────────────────────
 * Với MOCTRA_ prefix (khuyến nghị):
 *   curl -s -X POST \
 *     https://aptly-rubbing-bullpen.ngrok-free.dev/moctra/payment_callback.php \
 *     -H "Content-Type: application/json" \
 *     -H "ngrok-skip-browser-warning: any" \
 *     -d '{"content":"MOCTRA_22","amount":150000}'
 *
 * Với order_code format (fallback):
 *   -d '{"content":"ORD20250501-0022","amount":150000}'
 *
 * ── Bypass trang cảnh báo ngrok ──────────────────────────────
 * Cách 1 — Thêm header vào mỗi request:
 *   -H "ngrok-skip-browser-warning: any"
 *
 * Cách 2 — Khởi động ngrok với header tự động:
 *   ngrok http 80 --request-header-add "ngrok-skip-browser-warning: any"
 *
 * ── Định dạng memo phải khớp (theo thứ tự ưu tiên) ──────────
 * 1. MOCTRA_{orderId}    → vd: MOCTRA_22
 * 2. order_code trong DB → vd: ORD20250501-0022
 *
 * ── Log file ─────────────────────────────────────────────────
 * logs/payment_callback.log  (tự tạo, xem để debug)
 */

header('Content-Type: application/json; charset=utf-8');
header('ngrok-skip-browser-warning: any'); /* bypass cảnh báo khi test qua browser */

/* ── File-based logger (dễ debug hơn error_log trên XAMPP) ── */
$_cbLogFile = __DIR__ . '/logs/payment_callback.log';
if (!is_dir(__DIR__ . '/logs')) {
    @mkdir(__DIR__ . '/logs', 0755, true);
}

function cbLog(string $msg): void
{
    global $_cbLogFile;
    @file_put_contents(
        $_cbLogFile,
        '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function cbJson(bool $ok, string $msg, int $code = 200): never
{
    http_response_code($code);
    cbLog("RESPONSE {$code}: " . ($ok ? 'OK' : 'ERR') . " — {$msg}");
    echo json_encode(['success' => $ok, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit();
}

/* ── Chỉ chấp nhận POST ── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $method = $_SERVER['REQUEST_METHOD'] ?? '?';
    cbLog("REJECTED non-POST: {$method}");
    cbJson(false, 'Method not allowed', 405);
}

/* ── Parse body (JSON hoặc form-data) ── */
$rawBody = (string)file_get_contents('php://input');
$data    = json_decode($rawBody ?: '{}', true);
if (!is_array($data) || empty($data)) {
    $data = $_POST ?: [];
}

cbLog('─── NEW REQUEST ───────────────────────');
cbLog('IP: ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
cbLog('RAW: ' . ($rawBody ?: json_encode($_POST, JSON_UNESCAPED_UNICODE)));

/* ── Trích xuất fields (tương thích nhiều phiên bản Pay2S API) ── */
$memo    = trim((string)(
    $data['content']         ??
    $data['memo']            ??
    $data['description']     ??
    $data['transferContent'] ??
    ''
));
$rawAmt  = $data['amount'] ?? $data['transferAmount'] ?? 0;
$amount  = (int)round((float)$rawAmt);
$transId = trim((string)($data['transactionId'] ?? $data['id'] ?? $data['referenceCode'] ?? ''));

cbLog("PARSED  memo=[{$memo}]  amount={$amount}  transId=[{$transId}]");

require_once __DIR__ . '/config/db.php';

/* ═══════════════════════════════════════════════════════════════
   Khớp đơn hàng — 2 phương thức theo thứ tự ưu tiên
   ═══════════════════════════════════════════════════════════════ */
$orderId = 0;
$order   = null;

/* ── Phương thức 1: MOCTRA_{orderId} ── */
if (preg_match('/^MOCTRA_(\d+)$/i', $memo, $m)) {
    $orderId = (int)$m[1];
    cbLog("Pattern MOCTRA_ khớp → orderId={$orderId}");

    $st = $conn->prepare("SELECT * FROM orders WHERE id=? LIMIT 1");
    $st->bind_param('i', $orderId);
    $st->execute();
    $order = $st->get_result()->fetch_assoc();

    if (!$order) {
        cbLog("Không tìm thấy đơn id={$orderId} trong DB.");
    }

} else {
    /* ── Phương thức 2: order_code trong DB (vd: ORD20250501-0022) ── */
    cbLog("Pattern MOCTRA_ KHÔNG khớp với memo=[{$memo}]");
    cbLog("  → Kiểm tra các pattern hợp lệ: MOCTRA_<số_nguyên>  (vd: MOCTRA_22)");
    cbLog("  → Thử fallback: tìm order_code='{$memo}' trong bảng orders…");

    $hasCol = (bool)$conn->query(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders'
         AND COLUMN_NAME='order_code' LIMIT 1"
    )->num_rows;

    if ($hasCol && $memo !== '') {
        $st = $conn->prepare("SELECT * FROM orders WHERE order_code=? LIMIT 1");
        $st->bind_param('s', $memo);
        $st->execute();
        $order = $st->get_result()->fetch_assoc();

        if ($order) {
            $orderId = (int)$order['id'];
            cbLog("order_code lookup khớp → orderId={$orderId}");
        } else {
            cbLog("order_code lookup: không tìm thấy với memo=[{$memo}]");
            cbLog("  → Kiểm tra Dashboard Pay2S: nội dung chuyển khoản phải là");
            cbLog("    MOCTRA_{orderId}  HOẶC  order_code của đơn (vd: ORD20250501-0022)");
        }
    } else {
        if (!$hasCol) {
            cbLog("Cột order_code chưa tồn tại — chạy database/migrate_order_code.sql trước.");
        } else {
            cbLog("memo rỗng — không thể lookup.");
        }
    }
}

if (!$order) {
    cbLog("ABORT: không tìm được đơn cho memo=[{$memo}]");
    cbJson(false, 'Order not found', 404);
}

/* ── Idempotency ── */
if (
    $order['paid_at'] !== null ||
    in_array($order['status'], ['processing', 'shipping', 'delivered', 'reviewed'], true)
) {
    cbLog("Đơn #{$orderId} đã được xác nhận trước đó (status={$order['status']}) — bỏ qua.");
    cbJson(true, 'Already confirmed');
}

if ($order['status'] === 'cancelled') {
    cbLog("Đơn #{$orderId} đã bị hủy — từ chối.");
    cbJson(false, 'Order is cancelled', 422);
}

/* ── Kiểm tra số tiền (chống gian lận) ── */
$expected = (int)$order['total'];
if ($amount !== $expected) {
    cbLog("Amount mismatch orderId={$orderId}: expected={$expected}, received={$amount}");
    cbJson(false, "Amount mismatch: expected {$expected}, got {$amount}", 422);
}

/* ── Cập nhật đơn + thêm notification (atomic) ── */
$conn->begin_transaction();
try {
    $upd = $conn->prepare(
        "UPDATE orders
         SET status='processing', paid_at=NOW(), updated_at=NOW()
         WHERE id=? AND paid_at IS NULL AND status='pending'"
    );
    $upd->bind_param('i', $orderId);
    $upd->execute();

    if ($upd->affected_rows === 0) {
        /* Race condition: request song song đã xử lý trước */
        $conn->rollback();
        cbLog("Race condition orderId={$orderId} — rollback.");
        cbJson(true, 'Already processed');
    }

    $uid      = (int)$order['user_id'];
    $notifMsg = "Thanh toán đơn hàng #{$orderId} đã được xác nhận qua Pay2S. "
              . 'Đơn hàng đang được chuẩn bị!';
    $ins = $conn->prepare(
        "INSERT INTO notifications (user_id, type, reference_id, message, is_read, created_at)
         VALUES (?, 'payment_confirmed', ?, ?, 0, NOW())"
    );
    $ins->bind_param('iis', $uid, $orderId, $notifMsg);
    $ins->execute();

    $conn->commit();
    cbLog("SUCCESS orderId={$orderId} transId=[{$transId}]");

    echo json_encode([
        'success'  => true,
        'message'  => 'Payment confirmed',
        'order_id' => $orderId,
    ], JSON_UNESCAPED_UNICODE);
    exit();

} catch (Throwable $e) {
    $conn->rollback();
    cbLog("DB exception orderId={$orderId}: " . $e->getMessage());
    cbJson(false, 'Internal error', 500);
}
