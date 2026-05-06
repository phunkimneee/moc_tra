<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

require_once 'config/db.php';
require_once 'config/payment.php';

$orderId = (int)($_GET['order_id'] ?? 0);
$order   = null;

$userId = (int)$_SESSION['user_id'];

if ($orderId > 0) {
    $st = $conn->prepare(
        "SELECT * FROM orders WHERE id = ? AND user_id = ? LIMIT 1"
    );
    $st->bind_param('ii', $orderId, $userId);
    $st->execute();
    $order = $st->get_result()->fetch_assoc();
}

$isBank    = $order && $order['payment_method'] === 'bank';
$amount    = $order ? (int)$order['total'] : 0;
$pay2sUrl  = $orderId > 0 ? pay2s_url($orderId, $amount) : '#';
$memoCode  = PAY2S_ORDER_PREFIX . $orderId;
$orderCode = $order ? ($order['order_code'] ?? generate_order_code($orderId)) : '';
$qrUrl     = $orderCode ? order_qr_url($orderCode, 180) : '';

$redirectUrl = 'order_history.php' . ($orderId > 0 ? '?order_id=' . $orderId . '&new=1' : '');

function fmtVnd(int $n): string {
    return number_format($n, 0, ',', '.') . 'đ';
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $isBank ? 'Thanh toán đơn hàng' : 'Đặt hàng thành công' ?> — Mộc Trà</title>
  <?php if (!$isBank): ?>
  <meta http-equiv="refresh" content="2.5;url=<?= htmlspecialchars($redirectUrl, ENT_QUOTES) ?>">
  <?php endif; ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Be+Vietnam+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --green-900: #052e16;
      --green-800: #0f5132;
      --green-700: #166534;
      --green-100: #dcfce7;
      --green-50:  #f0fdf4;
      --blue-50:   #eff6ff;
      --blue-100:  #dbeafe;
      --blue-600:  #2563eb;
      --blue-700:  #1d4ed8;
      --gray-100:  #f3f4f6;
      --gray-200:  #e5e7eb;
      --gray-400:  #9ca3af;
      --gray-500:  #6b7280;
      --gray-700:  #374151;
      --gray-900:  #111827;
      --red-sale:  #dc2626;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      font-family: 'Be Vietnam Pro', sans-serif;
      color: var(--gray-900);
      background:
        radial-gradient(circle at top left, rgba(220,252,231,.9), transparent 30%),
        linear-gradient(135deg, #f6fff8, #f9fafb 55%, #eefbf2);
    }

    /* ── COD success card (original style) ── */
    .success-card {
      width: min(100%, 560px);
      background: rgba(255,255,255,.94);
      border: 1px solid rgba(255,255,255,.8);
      border-radius: 28px;
      padding: 38px 34px;
      text-align: center;
      box-shadow: 0 28px 60px rgba(15,81,50,.12);
      backdrop-filter: blur(8px);
    }
    .success-icon {
      width: 84px; height: 84px;
      margin: 0 auto 20px;
      border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      background: linear-gradient(135deg, #16a34a, #166534);
      box-shadow: 0 14px 30px rgba(22,101,52,.26);
      animation: pop .45s ease;
    }
    .success-icon svg { width:40px; height:40px; stroke:#fff; fill:none; stroke-width:2.5; stroke-linecap:round; stroke-linejoin:round; }
    .success-card h1 { font-family:'Playfair Display',serif; font-size:36px; line-height:1.15; margin-bottom:10px; }
    .success-card p  { color:var(--gray-500); font-size:15px; line-height:1.7; }
    .order-code {
      margin: 18px auto 0; display:inline-flex; align-items:center; justify-content:center;
      padding:10px 16px; border-radius:999px;
      background:var(--green-50); color:var(--green-800); font-weight:700;
    }
    .success-actions { margin-top:26px; display:flex; justify-content:center; gap:12px; flex-wrap:wrap; }
    .btn {
      display:inline-flex; align-items:center; justify-content:center;
      min-width:180px; padding:12px 18px; border-radius:999px;
      text-decoration:none; font-weight:700; font-family:inherit;
      transition:transform .18s ease, box-shadow .18s ease;
    }
    .btn-primary { color:#fff; background:linear-gradient(135deg,#166534,#0f5132); box-shadow:0 10px 24px rgba(15,81,50,.2); }
    .btn-secondary { color:var(--green-800); background:var(--green-50); }
    .btn:hover { transform:translateY(-1px); }
    .redirect-note { margin-top:16px; font-size:13px; color:var(--gray-500); }

    /* ── Bank payment card ── */
    .pay-card {
      width: min(100%, 580px);
      background: rgba(255,255,255,.96);
      border: 1px solid var(--gray-200);
      border-radius: 24px;
      padding: 32px 32px 28px;
      box-shadow: 0 24px 56px rgba(15,81,50,.1);
    }
    .pay-card-head {
      text-align: center;
      margin-bottom: 28px;
    }
    .pay-card-head .step-badge {
      display: inline-flex; align-items: center; gap: 6px;
      background: var(--blue-50); color: var(--blue-700);
      border: 1.5px solid var(--blue-100);
      padding: 4px 12px; border-radius: 99px;
      font-size: 12px; font-weight: 700; margin-bottom: 14px;
    }
    .pay-card-head h1 {
      font-family: 'Playfair Display', serif;
      font-size: 26px; font-weight: 700; margin-bottom: 6px;
    }
    .pay-card-head p { font-size: 14px; color: var(--gray-500); }

    .order-meta {
      display: grid; grid-template-columns: 1fr 1fr;
      gap: 10px; margin-bottom: 20px;
    }
    .meta-box {
      background: var(--gray-100);
      border-radius: 10px; padding: 12px 14px;
    }
    .meta-box .label { font-size: 11px; font-weight: 600; color: var(--gray-500); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 4px; }
    .meta-box .value { font-size: 15px; font-weight: 700; color: var(--gray-900); }
    .meta-box .value.amount { color: var(--red-sale); font-size: 17px; }
    .meta-box .value.code { font-family: monospace; color: var(--blue-700); letter-spacing: .5px; }

    .pay-instructions {
      background: var(--blue-50);
      border: 1.5px solid var(--blue-100);
      border-radius: 12px;
      padding: 16px 18px;
      margin-bottom: 20px;
    }
    .pay-instructions .inst-title {
      font-size: 13px; font-weight: 700; color: var(--blue-700); margin-bottom: 10px;
      display: flex; align-items: center; gap: 6px;
    }
    .pay-instructions ol {
      margin: 0; padding-left: 18px;
      display: flex; flex-direction: column; gap: 6px;
    }
    .pay-instructions li { font-size: 13px; color: var(--gray-700); line-height: 1.55; }
    .pay-instructions li strong { color: var(--gray-900); }

    .btn-pay2s {
      display: flex; align-items: center; justify-content: center; gap: 10px;
      width: 100%; padding: 16px 24px;
      background: linear-gradient(135deg, #166534, #0f5132);
      color: #fff; border: none; border-radius: 12px;
      font-size: 16px; font-weight: 700; font-family: inherit;
      text-decoration: none; cursor: pointer;
      box-shadow: 0 6px 18px rgba(22,101,52,.3);
      transition: transform .18s, box-shadow .18s;
      margin-bottom: 12px;
    }
    .btn-pay2s:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(22,101,52,.38); }
    .btn-pay2s svg { width: 20px; height: 20px; stroke: #fff; fill: none; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }

    .btn-history {
      display: flex; align-items: center; justify-content: center; gap: 8px;
      width: 100%; padding: 13px 24px;
      background: var(--gray-100); color: var(--gray-700);
      border: 1.5px solid var(--gray-200); border-radius: 12px;
      font-size: 14px; font-weight: 600; font-family: inherit;
      text-decoration: none; cursor: pointer;
      transition: background .15s, border-color .15s;
    }
    .btn-history:hover { background: var(--gray-200); border-color: var(--gray-300); }

    /* Status indicator */
    .pay-status {
      display: flex; align-items: center; justify-content: center; gap: 8px;
      margin-top: 16px;
      font-size: 13px; color: var(--gray-500);
    }
    .pay-status .dot {
      width: 8px; height: 8px; border-radius: 50%;
      background: var(--gray-400);
      animation: pulse 1.4s ease-in-out infinite;
    }
    .pay-status.confirmed .dot { background: #16a34a; animation: none; }
    .pay-status.confirmed    { color: #166534; font-weight: 600; }

    @keyframes pulse {
      0%, 100% { opacity: 1; }
      50%       { opacity: .3; }
    }
    @keyframes pop {
      0%   { transform: scale(.82); opacity: .4; }
      100% { transform: scale(1);   opacity: 1; }
    }
  </style>
  <script>
    try { localStorage.removeItem('moctra_cart'); } catch(e) {}
  </script>
</head>
<body>

<?php if ($isBank): ?>
<!-- ══ BANK TRANSFER — Pay2S Payment Panel ══ -->
<div class="pay-card">
  <div class="pay-card-head">
    <div class="step-badge">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      Bước cuối — Hoàn tất thanh toán
    </div>
    <h1>Đặt hàng thành công!</h1>
    <p>Đơn <strong>#<?= $orderId ?></strong> đã được ghi nhận. Vui lòng chuyển khoản để hoàn tất.</p>
  </div>

  <div class="order-meta">
    <div class="meta-box">
      <div class="label">Số tiền cần chuyển</div>
      <div class="value amount"><?= fmtVnd($amount) ?></div>
    </div>
    <div class="meta-box">
      <div class="label">Nội dung chuyển khoản</div>
      <div class="value code"><?= htmlspecialchars($memoCode) ?></div>
    </div>
  </div>

  <?php if ($qrUrl): ?>
  <div style="display:flex;align-items:center;gap:20px;background:var(--gray-100);border-radius:12px;padding:16px 18px;margin-bottom:20px;">
    <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR đơn hàng" width="110" height="110" style="border-radius:8px;border:4px solid #fff;box-shadow:0 4px 12px rgba(0,0,0,.1);flex-shrink:0;">
    <div>
      <div style="font-size:11px;font-weight:700;color:var(--gray-500);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">Mã đơn hàng</div>
      <div style="font-size:17px;font-weight:700;color:var(--gray-900);font-family:monospace;letter-spacing:.5px;margin-bottom:6px;"><?= htmlspecialchars($orderCode) ?></div>
      <div style="font-size:12px;color:var(--gray-500);">Quét QR để lưu thông tin đơn hàng.</div>
    </div>
  </div>
  <?php endif; ?>

  <div class="pay-instructions">
    <div class="inst-title">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      Hướng dẫn thanh toán qua Pay2S Sandbox
    </div>
    <ol>
      <li>Nhấn nút <strong>Thanh toán ngay</strong> bên dưới để mở trang Pay2S.</li>
      <li>Nhập số tiền: <strong><?= fmtVnd($amount) ?></strong></li>
      <li>Nội dung chuyển khoản: <strong><?= htmlspecialchars($memoCode) ?></strong> (quan trọng — phải khớp chính xác).</li>
      <li>Xác nhận giao dịch. Hệ thống sẽ tự động cập nhật đơn hàng trong vài giây.</li>
    </ol>
  </div>

  <a href="<?= htmlspecialchars($pay2sUrl) ?>" target="_blank" class="btn-pay2s" id="btnPay2s">
    <svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
    Thanh toán ngay qua Pay2S
    <svg viewBox="0 0 24 24" style="margin-left:auto"><line x1="7" y1="17" x2="17" y2="7"/><polyline points="7 7 17 7 17 17"/></svg>
  </a>

  <a href="<?= htmlspecialchars($redirectUrl) ?>" class="btn-history">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="2"/></svg>
    Xem lịch sử đơn hàng
  </a>

  <div class="pay-status" id="payStatus">
    <span class="dot"></span>
    <span id="payStatusMsg">Đang chờ xác nhận thanh toán…</span>
  </div>
</div>

<script>
/* Polling: kiểm tra mỗi 5 giây xem đơn đã được xác nhận chưa */
(function () {
  var orderId = <?= $orderId ?>;
  var redirectUrl = <?= json_encode($redirectUrl) ?>;
  var maxTries = 60; /* tối đa 5 phút */
  var tries = 0;

  var statusEl = document.getElementById('payStatus');
  var msgEl    = document.getElementById('payStatusMsg');

  var iv = setInterval(function () {
    tries++;
    if (tries > maxTries) {
      clearInterval(iv);
      msgEl.textContent = 'Hết thời gian chờ. Vào "Đơn hàng của tôi" để kiểm tra trạng thái.';
      return;
    }

    fetch('api/check_payment.php?order_id=' + orderId)
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.confirmed) {
          clearInterval(iv);
          statusEl.classList.add('confirmed');
          msgEl.textContent = '✅ Thanh toán xác nhận! Đang chuyển trang…';
          setTimeout(function () { window.location.href = redirectUrl; }, 1800);
        }
      })
      .catch(function () {});
  }, 5000);
})();
</script>

<?php else: ?>
<!-- ══ COD / MOMO — Original Success Card ══ -->
<div class="success-card">
  <div class="success-icon" style="animation:pop .45s ease">
    <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
  </div>
  <h1>Đặt hàng thành công!</h1>
  <p>Đơn hàng của bạn đã được ghi nhận. Mình đang chuyển bạn sang trang theo dõi đơn hàng.</p>
  <?php if ($orderCode): ?>
    <div class="order-code"><?= htmlspecialchars($orderCode) ?></div>
    <?php if ($qrUrl): ?>
    <div style="margin-top:16px;display:flex;justify-content:center;">
      <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR đơn hàng" width="120" height="120" style="border-radius:10px;border:3px solid #fff;box-shadow:0 6px 18px rgba(15,81,50,.12);">
    </div>
    <?php endif; ?>
  <?php endif; ?>
  <div class="success-actions">
    <a href="<?= htmlspecialchars($redirectUrl, ENT_QUOTES) ?>" class="btn btn-primary">Theo dõi đơn hàng</a>
    <a href="products.php" class="btn btn-secondary">Tiếp tục mua sắm</a>
  </div>
  <div class="redirect-note">Trang sẽ tự chuyển sau vài giây.</div>
</div>
<?php endif; ?>

</body>
</html>
