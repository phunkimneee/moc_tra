<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();
require_once 'config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cart_payload'])) {
    $payload = json_decode((string)$_POST['cart_payload'], true);
    $sessionCart = [];
    if (is_array($payload)) {
        foreach ($payload as $pid => $item) {
            $productId = (int)$pid;
            $qty = (int)($item['qty'] ?? 0);
            if ($productId > 0 && $qty > 0) {
                $sessionCart[$productId] = ['qty' => $qty];
            }
        }
    }
    $_SESSION['cart'] = $sessionCart;
}

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }
if ($_SESSION['role'] === 'admin') { header('Location: admin/dashboard.php'); exit(); }
if (empty($_SESSION['cart']))      { header('Location: cart.php'); exit(); }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$user_id     = (int)$_SESSION['user_id'];
$username    = htmlspecialchars($_SESSION['username'] ?? '');
$userInitial = strtoupper(substr($username, 0, 1));
$cats        = $conn->query("SELECT * FROM categories ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);

/* ── Lấy thông tin user từ DB ── */
$st = $conn->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
$st->bind_param('i', $user_id);
$st->execute();
$user = $st->get_result()->fetch_assoc();

/* ── Build cart items ── */
$cart = $_SESSION['cart'];
$ids  = implode(',', array_map('intval', array_keys($cart)));
if ($ids === '') { header('Location: cart.php'); exit(); }
$rows = $conn->query("SELECT id,name,price,image FROM products WHERE id IN ($ids)")->fetch_all(MYSQLI_ASSOC);
$db    = [];
foreach ($rows as $r) $db[$r['id']] = $r;

$items = [];
$total = 0;
foreach ($cart as $pid => $item) {
    if (!isset($db[$pid])) continue;
    $item['name']     = $db[$pid]['name'];
    $item['price']    = $db[$pid]['price'];
    $item['image']    = $db[$pid]['image'];
    $item['subtotal'] = $item['price'] * $item['qty'];
    $total           += $item['subtotal'];
    $items[$pid]      = $item;
}
$ship        = $total >= FREE_SHIP_THRESHOLD ? 0 : SHIP_FEE;
$grand_total = $total + $ship;

$sessionCoupon = $_SESSION['coupon'] ?? null;

/* ── Kiểm tra mã riêng tư dành cho user hiện tại ── */
$privateCoupon = null;
$stPriv = $conn->prepare("
    SELECT code, discount_type, discount_value, expires_at
    FROM coupons
    WHERE coupon_role = 'private'
      AND specific_user_id = ?
      AND is_active = 1
      AND (expires_at IS NULL OR expires_at >= CURDATE())
      AND (max_uses = 0 OR used_count < max_uses)
    ORDER BY id DESC LIMIT 1
");
$stPriv->bind_param('i', $user_id);
$stPriv->execute();
$privateCoupon = $stPriv->get_result()->fetch_assoc();

function fmt(int $n): string { return number_format($n,0,',','.') . 'đ'; }
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
  <title>Thanh toán — Mộc Trà Thái Nguyên</title>
  <!-- Font Awesome 6 -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Be+Vietnam+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/index.css">
  <link rel="stylesheet" href="css/products.css">
  <link rel="stylesheet" href="css/components.css">
<style>
/* Global Icon Styles - Backup */
i.fa-solid, i.fa-regular, i.fa-brands {
    color: #2d5a27;
    transition: color 0.3s ease, transform 0.3s ease;
    margin-right: 8px;
}
.text-danger { color: #dc3545 !important; font-weight: bold; margin-left: 2px; }

body { background:#f9fafb; }
.checkout-wrap { max-width:1060px; margin:32px auto 60px; padding:0 36px; }
.checkout-wrap h1 { font-family:'Playfair Display',serif; font-size:26px; font-weight:700; color:#111827; margin-bottom:24px; }

.checkout-layout { display:grid; grid-template-columns:1fr 360px; gap:24px; align-items:start; }

/* Form */
.checkout-form-box { background:#fff; border-radius:14px; border:1px solid #f3f4f6; padding:28px 32px; margin-bottom:20px; }
.box-title { font-size:16px; font-weight:700; color:#111827; margin-bottom:20px; padding-bottom:12px; border-bottom:1px solid #f3f4f6; display:flex; align-items:center; gap:8px; }
.box-title span { width:26px; height:26px; background:#166534; color:#fff; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:700; }

.form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px; }
.form-row.full { grid-template-columns:1fr; }
.form-group { display:flex; flex-direction:column; gap:5px; }
.form-group label { font-size:13px; font-weight:600; color:#374151; }
.form-group input, .form-group textarea, .form-group select {
  padding:10px 14px; border:1.5px solid #e5e7eb; border-radius:8px;
  font-size:14px; font-family:inherit; color:#111827; outline:none;
  transition:border-color .2s;
}
.form-group input:focus, .form-group textarea:focus { border-color:#166534; }
.form-group textarea { resize:vertical; min-height:70px; }

/* Payment methods: inherited from components.css */
.payment-opts { display:flex; flex-direction:column; gap:10px; }

/* Summary */
.checkout-summary { background:#fff; border-radius:14px; border:1px solid #f3f4f6; padding:24px; position:sticky; top:110px; }
.checkout-summary h3 { font-family:'Playfair Display',serif; font-size:18px; font-weight:700; margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid #f3f4f6; }
.order-item { display:flex; align-items:center; gap:10px; margin-bottom:12px; }
.order-item img { width:54px; height:54px; border-radius:8px; object-fit:cover; background:#f0fdf4; }
.order-item-info { flex:1; }
.order-item-name { font-size:13.5px; font-weight:600; color:#111827; line-height:1.3; }
.order-item-qty  { font-size:12px; color:#9ca3af; margin-top:2px; }
.order-item-price{ font-size:13.5px; font-weight:700; color:#dc2626; white-space:nowrap; }
.summary-line { display:flex; justify-content:space-between; font-size:13.5px; color:#6b7280; margin-bottom:10px; }
.summary-line.total { font-size:16px; font-weight:700; color:#111827; padding-top:12px; border-top:2px solid #f3f4f6; margin-top:8px; }
.summary-line.total .val { color:#dc2626; }
.btn-order { display:block; width:100%; margin-top:20px; padding:15px; background:linear-gradient(135deg,#166534,#0f5132); color:#fff; border:none; border-radius:9px; font-size:16px; font-weight:700; font-family:inherit; cursor:pointer; text-align:center; transition:transform .2s,box-shadow .2s; box-shadow:0 4px 14px rgba(22,101,52,.28); }
.btn-order:hover { transform:translateY(-2px); box-shadow:0 8px 20px rgba(22,101,52,.38); }
/* Coupon */
.coupon-row { display:flex; gap:8px; margin:14px 0 6px; }
.coupon-input { flex:1; padding:9px 12px; border:1.5px solid #e5e7eb; border-radius:8px; font-size:13px; font-family:inherit; outline:none; transition:border-color .2s; text-transform:uppercase; }
.coupon-input:focus { border-color:#166534; }
.coupon-btn { padding:9px 14px; background:#166534; color:#fff; border:none; border-radius:8px; font-weight:700; cursor:pointer; font-size:13px; white-space:nowrap; transition:background .2s; }
.coupon-btn:hover { background:#0f5132; }
.coupon-msg { font-size:12.5px; margin-bottom:6px; min-height:18px; }
.coupon-ok  { color:#166534; }
.coupon-err { color:#dc2626; }
.summary-line.discount { color:#166534; font-weight:600; }
/* ── Payment Info Panels ── */
.pay-panel { display:none; margin-top:14px; border-radius:12px; padding:18px; }
.pay-panel.show { display:block; animation:panelIn .22s ease; }
@keyframes panelIn { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:translateY(0)} }
.pay-panel-momo { background:#fff0f6; border:1.5px solid #f9a8d4; }
.pay-panel-bank { background:#f0f9ff; border:1.5px solid #bae6fd; }
.pay-panel-head { display:flex; align-items:center; gap:10px; margin-bottom:14px; }
.pay-panel-icon { width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.pay-panel-icon.ic-momo { background:#ae2070; }
.pay-panel-icon.ic-bank { background:#1a6b3c; }
.pay-panel-icon svg { width:20px; height:20px; stroke:#fff; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
.pay-panel-title { font-size:14px; font-weight:700; color:#111827; }
.pay-panel-sub { font-size:12px; color:#6b7280; margin-top:1px; }
.qr-zone { text-align:center; margin-bottom:14px; }
.qr-svg { display:block; margin:0 auto 6px; width:128px; height:128px; image-rendering:pixelated; border:6px solid white; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,.12); }
.qr-caption { font-size:11px; color:#9ca3af; }
.pay-detail { display:flex; flex-direction:column; gap:5px; }
.pay-detail-row { display:flex; justify-content:space-between; align-items:center; gap:8px; padding:7px 10px; background:rgba(255,255,255,.75); border-radius:7px; font-size:13px; }
.pay-detail-label { color:#6b7280; }
.pay-detail-val { font-weight:700; color:#111827; }
.pay-detail-val.amount { color:#dc2626; font-size:14px; }
.pay-note { margin-top:10px; padding:9px 12px; border-radius:8px; background:rgba(0,0,0,.05); font-size:12px; color:#6b7280; line-height:1.65; }
.pay-badge { display:inline-block; font-size:10px; font-weight:700; padding:1px 7px; border-radius:99px; vertical-align:middle; margin-left:4px; background:#fef9c3; color:#854d0e; }

/* ── Checkout Steps Bar ── */
.ck-steps { display:flex; align-items:center; justify-content:center; margin:0 0 28px; }
.ck-step { display:flex; align-items:center; gap:8px; }
.ck-step-circle { width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:700; flex-shrink:0; }
.ck-step.done    .ck-step-circle { background:#166534; color:#fff; }
.ck-step.active  .ck-step-circle { background:#166534; color:#fff; box-shadow:0 0 0 4px rgba(22,101,52,.18); }
.ck-step.pending .ck-step-circle { background:#e5e7eb; color:#9ca3af; }
.ck-step-label { font-size:13px; font-weight:600; }
.ck-step.done    .ck-step-label { color:#166534; }
.ck-step.active  .ck-step-label { color:#111827; }
.ck-step.pending .ck-step-label { color:#9ca3af; }
.ck-step-line { flex:1 1 50px; min-width:30px; height:2px; background:#e5e7eb; margin:0 8px; }
.ck-step-line.done { background:#166534; }
/* ── Field Error ── */
.ck-field-err { font-size:12px; color:#dc2626; margin-top:4px; }
/* ── Coupon enhancements ── */
.coupon-select-row { margin:14px 0 8px; }
.coupon-select { width:100%; padding:9px 12px; border:1.5px solid #e5e7eb; border-radius:8px; font-size:13px; font-family:inherit; color:#111827; background:#fff; outline:none; transition:border-color .2s; cursor:pointer; }
.coupon-select:focus { border-color:#166534; }
.coupon-select option:disabled { color:#9ca3af; font-style:italic; }
.coupon-or { display:flex; align-items:center; gap:8px; margin:8px 0; color:#9ca3af; font-size:12px; }
.coupon-or::before,.coupon-or::after { content:''; flex:1; height:1px; background:#e5e7eb; }
.private-coupon-notice { background:linear-gradient(135deg,#fef3c7,#fffbeb); border:1.5px solid #fcd34d; border-radius:10px; padding:12px 14px; margin-bottom:10px; display:flex; align-items:flex-start; gap:10px; }
.private-coupon-notice svg { width:18px; height:18px; flex-shrink:0; stroke:#d97706; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; margin-top:1px; }
.private-coupon-notice-body { flex:1; }
.private-coupon-notice-title { font-size:12.5px; font-weight:700; color:#92400e; margin-bottom:3px; }
.private-coupon-notice-desc { font-size:12px; color:#b45309; line-height:1.5; }
.private-coupon-notice-btn { display:inline-block; margin-top:7px; padding:5px 12px; background:#d97706; color:#fff; border:none; border-radius:6px; font-size:12px; font-weight:700; cursor:pointer; font-family:inherit; transition:background .2s; }
.private-coupon-notice-btn:hover { background:#b45309; }
</style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar" id="navbar">
  <div class="navbar-inner">
    <a href="index.php" class="brand"><img src="images/logo.png" alt="Logo" onerror="this.style.display='none'"><span class="brand-name">Mộc Trà</span></a>
    <div class="nav-menu">
      <a href="index.php" class="nav-link">Trang chủ</a>
      <a href="products.php" class="nav-link">Sản phẩm</a>
      <a href="cart.php" class="nav-link">Giỏ hàng</a>
    </div>
    <div class="nav-right">
      <a href="cart.php" class="nav-icon-btn" data-nav-cart><svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg><span class="badge badge-cart" data-cart-badge><?= count($items) ?: '' ?></span></a>
      <div class="user-dropdown"><div class="user-btn" id="userBtn"><div class="user-avatar"><?= $userInitial ?></div><span><?= $username ?></span><svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg></div>
      <div class="user-menu" id="userMenu"><div class="user-menu-header"><div class="uname"><?= $username ?></div></div><a href="order_history.php"><svg viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="2"/></svg>Đơn hàng của tôi</a><div class="divider"></div><a href="logout.php" class="logout"><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>Đăng xuất</a></div></div>
    </div>
  </div>
</nav>
<div class="breadcrumb-bar"><div class="breadcrumb-inner"><a href="index.php">Trang chủ</a><span class="sep">›</span><a href="cart.php">Giỏ hàng</a><span class="sep">›</span><span class="current">Thanh toán</span></div></div>

<div class="checkout-wrap">
  <h1>Thanh toán</h1>
  <div class="ck-steps">
    <div class="ck-step done"><div class="ck-step-circle">✓</div><span class="ck-step-label">Giỏ hàng</span></div>
    <div class="ck-step-line done"></div>
    <div class="ck-step active"><div class="ck-step-circle">2</div><span class="ck-step-label">Thông tin</span></div>
    <div class="ck-step-line"></div>
    <div class="ck-step pending"><div class="ck-step-circle">3</div><span class="ck-step-label">Xác nhận</span></div>
  </div>
  <form method="POST" action="place_order.php" id="checkoutForm">
  <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
  <div class="checkout-layout">

    <div>
      <!-- Thông tin giao hàng -->
      <div class="checkout-form-box">
        <div class="box-title"><span>1</span> Thông tin giao hàng</div>
        <div class="form-row">
          <div class="form-group">
            <label>Họ và tên <span class="text-danger">*</span></label>
            <input type="text" name="fullname" id="ck-fullname" required value="<?= htmlspecialchars($user['username'] ?? '') ?>" placeholder="Nguyễn Văn A">
            <div class="ck-field-err" id="err-ck-fullname"></div>
          </div>
          <div class="form-group">
            <label>Số điện thoại <span class="text-danger">*</span></label>
            <input type="tel" name="phone" id="ck-phone" required value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="0912345678">
            <div class="ck-field-err" id="err-ck-phone"></div>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" id="ck-email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="email@gmail.com">
            <div class="ck-field-err" id="err-ck-email"></div>
          </div>
        </div>
        <div class="form-row full">
          <div class="form-group">
            <label>Địa chỉ giao hàng <span class="text-danger">*</span></label>
            <input type="text" name="address" id="ck-address" required value="<?= htmlspecialchars($user['address'] ?? '') ?>" placeholder="Số nhà, đường, phường/xã, quận/huyện, tỉnh/thành">
            <div class="ck-field-err" id="err-ck-address"></div>
          </div>
        </div>
        <div class="form-row full">
          <div class="form-group">
            <label>Ghi chú đơn hàng</label>
            <textarea name="note" placeholder="Ghi chú cho người giao hàng (không bắt buộc)"></textarea>
          </div>
        </div>
      </div>

      <!-- Phương thức thanh toán -->
      <div class="checkout-form-box">
        <div class="box-title"><span>2</span> Phương thức thanh toán</div>
        <div class="payment-opts" id="payOpts">
          <label class="pay-opt-shad selected" onclick="selectPay(this,'cod')">
            <input type="radio" name="payment_method" value="cod" checked>
            <div class="pay-ico-shad">
              <svg viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v3h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
            </div>
            <div class="pay-lbl-shad">
              <div class="pay-name">Thanh toán khi nhận hàng (COD)</div>
              <div class="pay-desc">Trả tiền mặt khi nhận được hàng</div>
            </div>
            <div class="pay-radio-shad"></div>
          </label>
          <label class="pay-opt-shad" onclick="selectPay(this,'momo')">
            <input type="radio" name="payment_method" value="momo">
            <div class="pay-ico-shad momo">
              <svg viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="20" rx="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>
            </div>
            <div class="pay-lbl-shad">
              <div class="pay-name">Ví MoMo</div>
              <div class="pay-desc">Quét QR hoặc nhập số điện thoại MoMo</div>
            </div>
            <div class="pay-radio-shad"></div>
          </label>
          <label class="pay-opt-shad" onclick="selectPay(this,'bank')">
            <input type="radio" name="payment_method" value="bank">
            <div class="pay-ico-shad card">
              <svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
            </div>
            <div class="pay-lbl-shad">
              <div class="pay-name">Chuyển khoản ngân hàng (Pay2S)</div>
              <div class="pay-desc">ACB Sandbox — xác nhận tự động qua Pay2S</div>
            </div>
            <div class="pay-radio-shad"></div>
          </label>
        </div>

        <!-- ── MoMo Payment Panel ── -->
        <div class="pay-panel pay-panel-momo" id="panelMomo">
          <div class="pay-panel-head">
            <div class="pay-panel-icon ic-momo">
              <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M8 12a4 4 0 0 1 8 0"/><line x1="12" y1="8" x2="12" y2="8.5"/></svg>
            </div>
            <div>
              <div class="pay-panel-title">Thanh toán qua MoMo <span class="pay-badge">DEMO</span></div>
              <div class="pay-panel-sub">Quét mã QR hoặc chuyển thủ công đến số bên dưới</div>
            </div>
          </div>
          <div class="qr-zone">
            <svg class="qr-svg" viewBox="0 0 29 29" xmlns="http://www.w3.org/2000/svg">
              <rect width="29" height="29" fill="white"/>
              <!-- TL finder --><rect x="0" y="0" width="7" height="7" fill="#000"/><rect x="1" y="1" width="5" height="5" fill="white"/><rect x="2" y="2" width="3" height="3" fill="#000"/>
              <!-- TR finder --><rect x="22" y="0" width="7" height="7" fill="#000"/><rect x="23" y="1" width="5" height="5" fill="white"/><rect x="24" y="2" width="3" height="3" fill="#000"/>
              <!-- BL finder --><rect x="0" y="22" width="7" height="7" fill="#000"/><rect x="1" y="23" width="5" height="5" fill="white"/><rect x="2" y="24" width="3" height="3" fill="#000"/>
              <!-- Timing --><rect x="8" y="6" width="1" height="1" fill="#000"/><rect x="10" y="6" width="1" height="1" fill="#000"/><rect x="12" y="6" width="1" height="1" fill="#000"/><rect x="14" y="6" width="1" height="1" fill="#000"/><rect x="6" y="8" width="1" height="1" fill="#000"/><rect x="6" y="10" width="1" height="1" fill="#000"/><rect x="6" y="12" width="1" height="1" fill="#000"/><rect x="6" y="14" width="1" height="1" fill="#000"/>
              <!-- Data --><rect x="8" y="8" width="1" height="1" fill="#000"/><rect x="10" y="8" width="1" height="1" fill="#000"/><rect x="14" y="8" width="1" height="1" fill="#000"/><rect x="18" y="8" width="1" height="1" fill="#000"/><rect x="20" y="8" width="1" height="1" fill="#000"/>
              <rect x="9" y="9" width="1" height="1" fill="#000"/><rect x="13" y="9" width="1" height="1" fill="#000"/><rect x="17" y="9" width="1" height="1" fill="#000"/><rect x="21" y="9" width="1" height="1" fill="#000"/>
              <rect x="8" y="10" width="1" height="1" fill="#000"/><rect x="12" y="10" width="1" height="1" fill="#000"/><rect x="16" y="10" width="1" height="1" fill="#000"/><rect x="20" y="10" width="1" height="1" fill="#000"/>
              <rect x="11" y="11" width="1" height="1" fill="#000"/><rect x="15" y="11" width="1" height="1" fill="#000"/><rect x="19" y="11" width="1" height="1" fill="#000"/>
              <rect x="8" y="12" width="1" height="1" fill="#000"/><rect x="10" y="12" width="1" height="1" fill="#000"/><rect x="14" y="12" width="1" height="1" fill="#000"/><rect x="18" y="12" width="1" height="1" fill="#000"/>
              <rect x="9" y="13" width="1" height="1" fill="#000"/><rect x="13" y="13" width="1" height="1" fill="#000"/><rect x="17" y="13" width="1" height="1" fill="#000"/><rect x="21" y="13" width="1" height="1" fill="#000"/>
              <rect x="8" y="14" width="1" height="1" fill="#000"/><rect x="12" y="14" width="1" height="1" fill="#000"/><rect x="16" y="14" width="1" height="1" fill="#000"/><rect x="20" y="14" width="1" height="1" fill="#000"/>
              <rect x="22" y="8" width="1" height="1" fill="#000"/><rect x="24" y="8" width="1" height="1" fill="#000"/><rect x="26" y="8" width="1" height="1" fill="#000"/>
              <rect x="23" y="9" width="1" height="1" fill="#000"/><rect x="25" y="9" width="1" height="1" fill="#000"/>
              <rect x="22" y="10" width="1" height="1" fill="#000"/><rect x="26" y="10" width="1" height="1" fill="#000"/><rect x="28" y="10" width="1" height="1" fill="#000"/>
              <rect x="23" y="11" width="1" height="1" fill="#000"/><rect x="27" y="11" width="1" height="1" fill="#000"/>
              <rect x="22" y="12" width="1" height="1" fill="#000"/><rect x="24" y="12" width="1" height="1" fill="#000"/><rect x="28" y="12" width="1" height="1" fill="#000"/>
              <rect x="8" y="22" width="1" height="1" fill="#000"/><rect x="10" y="22" width="1" height="1" fill="#000"/><rect x="12" y="22" width="1" height="1" fill="#000"/>
              <rect x="9" y="23" width="1" height="1" fill="#000"/><rect x="13" y="23" width="1" height="1" fill="#000"/>
              <rect x="8" y="24" width="1" height="1" fill="#000"/><rect x="11" y="24" width="1" height="1" fill="#000"/>
              <rect x="10" y="25" width="1" height="1" fill="#000"/><rect x="12" y="25" width="1" height="1" fill="#000"/>
              <rect x="9" y="26" width="1" height="1" fill="#000"/>
              <rect x="16" y="22" width="1" height="1" fill="#000"/><rect x="18" y="22" width="1" height="1" fill="#000"/><rect x="20" y="22" width="1" height="1" fill="#000"/>
              <rect x="17" y="23" width="1" height="1" fill="#000"/><rect x="21" y="23" width="1" height="1" fill="#000"/>
              <rect x="16" y="24" width="1" height="1" fill="#000"/><rect x="19" y="24" width="1" height="1" fill="#000"/>
              <rect x="18" y="25" width="1" height="1" fill="#000"/><rect x="20" y="25" width="1" height="1" fill="#000"/>
              <rect x="17" y="26" width="1" height="1" fill="#000"/><rect x="21" y="26" width="1" height="1" fill="#000"/>
              <rect x="24" y="22" width="1" height="1" fill="#000"/><rect x="26" y="22" width="1" height="1" fill="#000"/>
              <rect x="25" y="23" width="1" height="1" fill="#000"/><rect x="27" y="23" width="1" height="1" fill="#000"/>
              <rect x="24" y="24" width="1" height="1" fill="#000"/><rect x="28" y="24" width="1" height="1" fill="#000"/>
              <rect x="25" y="25" width="1" height="1" fill="#000"/>
              <rect x="26" y="26" width="1" height="1" fill="#000"/><rect x="28" y="26" width="1" height="1" fill="#000"/>
            </svg>
            <div class="qr-caption">Mã QR minh họa — không dùng thật</div>
          </div>
          <div class="pay-detail">
            <div class="pay-detail-row"><span class="pay-detail-label">Số điện thoại MoMo</span><span class="pay-detail-val">0987 654 321</span></div>
            <div class="pay-detail-row"><span class="pay-detail-label">Tên tài khoản</span><span class="pay-detail-val">NGUYEN MOC TRA</span></div>
            <div class="pay-detail-row"><span class="pay-detail-label">Số tiền cần chuyển</span><span class="pay-detail-val amount" id="momoAmt">—</span></div>
          </div>
          <div class="pay-note">📱 Nội dung chuyển khoản ghi: <strong>MOCTRA [họ tên của bạn]</strong>. Đơn hàng sẽ được xác nhận sau khi nhận được thanh toán.</div>
        </div>

        <!-- ── Bank Transfer Panel (Pay2S Sandbox) ── -->
        <div class="pay-panel pay-panel-bank" id="panelBank">
          <div class="pay-panel-head">
            <div class="pay-panel-icon ic-bank">
              <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M3 11l9-7 9 7"/><line x1="12" y1="11" x2="12" y2="21"/><line x1="7" y1="11" x2="7" y2="21"/><line x1="17" y1="11" x2="17" y2="21"/></svg>
            </div>
            <div>
              <div class="pay-panel-title">Chuyển khoản Pay2S <span class="pay-badge">SANDBOX</span></div>
              <div class="pay-panel-sub">ACB — xác nhận tự động ngay sau khi chuyển khoản</div>
            </div>
          </div>
          <div class="pay-detail">
            <div class="pay-detail-row"><span class="pay-detail-label">Ngân hàng</span><span class="pay-detail-val">ACB (Sandbox)</span></div>
            <div class="pay-detail-row"><span class="pay-detail-label">Số tài khoản</span><span class="pay-detail-val">999 999 999</span></div>
            <div class="pay-detail-row"><span class="pay-detail-label">Chủ tài khoản</span><span class="pay-detail-val">MOCTRA THAI NGUYEN</span></div>
            <div class="pay-detail-row"><span class="pay-detail-label">Số tiền cần chuyển</span><span class="pay-detail-val amount" id="bankAmt">—</span></div>
          </div>
          <div class="pay-note">🔗 Sau khi đặt hàng, hệ thống sẽ tạo link Pay2S riêng cho đơn hàng của bạn. Nội dung chuyển khoản (<strong>MOCTRA_[số đơn]</strong>) được điền sẵn tự động — đơn hàng xác nhận ngay lập tức khi khớp.</div>
        </div>

      </div>
    </div>

    <!-- Summary -->
    <div class="checkout-summary">
      <h3>Đơn hàng của bạn</h3>
      <?php foreach ($items as $pid => $item): ?>
      <div class="order-item">
        <img src="images/<?= htmlspecialchars($item['image'] ?? 'logo.png') ?>"
             alt="<?= htmlspecialchars($item['name']) ?>"
             onerror="this.onerror=null;this.src='images/logo.png'">
        <div class="order-item-info">
          <div class="order-item-name"><?= htmlspecialchars($item['name']) ?></div>
          <div class="order-item-qty">x<?= $item['qty'] ?></div>
        </div>
        <div class="order-item-price"><?= fmt($item['subtotal']) ?></div>
      </div>
      <?php endforeach; ?>
      <div style="height:1px;background:#f3f4f6;margin:14px 0"></div>

      <?php if ($privateCoupon && (!$sessionCoupon || $sessionCoupon['code'] !== $privateCoupon['code'])): ?>
      <!-- Private coupon notice -->
      <div class="private-coupon-notice" id="privateCouponNotice">
        <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
        <div class="private-coupon-notice-body">
          <div class="private-coupon-notice-title">Bạn có mã ưu đãi riêng!</div>
          <div class="private-coupon-notice-desc">
            Mã <strong><?= htmlspecialchars($privateCoupon['code']) ?></strong> —
            <?php if ($privateCoupon['discount_type'] === 'percent'): ?>
              Giảm <?= $privateCoupon['discount_value'] ?>%
            <?php else: ?>
              Giảm <?= fmt((int)$privateCoupon['discount_value']) ?>
            <?php endif; ?>
            <?php if ($privateCoupon['expires_at']): ?>
              · HSD: <?= date('d/m/Y', strtotime($privateCoupon['expires_at'])) ?>
            <?php endif; ?>
          </div>
          <button type="button" class="private-coupon-notice-btn"
                  onclick="applyPrivateCoupon('<?= htmlspecialchars($privateCoupon['code']) ?>')">
            Áp dụng ngay
          </button>
        </div>
      </div>
      <?php endif; ?>

      <!-- Coupon select dropdown -->
      <div class="coupon-select-row">
        <select id="couponSelect" class="coupon-select">
          <option value="">-- Chọn mã giảm giá --</option>
        </select>
      </div>
      <div class="coupon-or">hoặc nhập thủ công</div>

      <!-- Manual coupon input -->
      <div class="coupon-row">
        <input type="text" id="couponInput" class="coupon-input" placeholder="Nhập mã giảm giá..."
               value="<?= htmlspecialchars($sessionCoupon['code'] ?? '') ?>">
        <button type="button" class="coupon-btn" id="couponBtn">Áp dụng</button>
      </div>
      <div id="couponMsg" class="coupon-msg"></div>
      <input type="hidden" name="coupon_code" id="couponCode" value="">
      <div style="height:1px;background:#f3f4f6;margin:10px 0"></div>

      <div class="summary-line"><span>Tạm tính</span><span><?= fmt($total) ?></span></div>
      <div class="summary-line discount" id="lineDiscount" style="display:none">
        <span>Giảm giá</span><span id="discountVal">-0đ</span>
      </div>
      <div class="summary-line"><span>Phí ship</span><span style="color:<?= $ship ? '#374151' : '#166534' ?>"><?= $ship ? fmt($ship) : 'Miễn phí' ?></span></div>
      <div class="summary-line total"><span>Tổng cộng</span><span class="val" id="grandTotalVal"><?= fmt($grand_total) ?></span></div>
      <button type="submit" class="btn-order" id="btnOrder">Đặt hàng ngay →</button>
      <div style="font-size:12px;color:#9ca3af;text-align:center;margin-top:10px;">Bằng cách đặt hàng, bạn đồng ý với điều khoản dịch vụ</div>
    </div>

  </div>
  </form>
</div>

<script>
var _subtotal  = <?= $total ?>;
var _ship      = <?= $ship ?>;
var _discount  = 0;

function fmtVnd(n) {
  return n.toLocaleString('vi-VN') + 'đ';
}
function updateGrandTotal() {
  var grand = Math.max(0, _subtotal + _ship - _discount);
  document.getElementById('grandTotalVal').textContent = fmtVnd(grand);
}

function applyCouponCode(code) {
  var msgEl = document.getElementById('couponMsg');
  if (!code) { msgEl.innerHTML = '<span class="coupon-err">Vui lòng nhập mã.</span>'; return; }
  msgEl.textContent = 'Đang kiểm tra...';
  fetch('apply_coupon.php?code=' + encodeURIComponent(code) + '&subtotal=' + _subtotal)
    .then(function(r){ return r.json(); })
    .then(function(d) {
      if (d.ok) {
        _discount = d.discount;
        document.getElementById('couponCode').value = d.code;
        document.getElementById('couponInput').value = d.code;
        document.getElementById('discountVal').textContent = '-' + fmtVnd(d.discount);
        document.getElementById('lineDiscount').style.display = '';
        msgEl.innerHTML = '<span class="coupon-ok">' + d.msg + '</span>';
        updateGrandTotal();
        /* Hide private notice if accepted */
        var notice = document.getElementById('privateCouponNotice');
        if (notice) notice.style.display = 'none';
      } else {
        _discount = 0;
        document.getElementById('couponCode').value = '';
        document.getElementById('lineDiscount').style.display = 'none';
        msgEl.innerHTML = '<span class="coupon-err">' + d.msg + '</span>';
        updateGrandTotal();
      }
    })
    .catch(function(){ document.getElementById('couponMsg').innerHTML = '<span class="coupon-err">Lỗi kết nối. Thử lại sau.</span>'; });
}

document.getElementById('couponBtn').addEventListener('click', function () {
  applyCouponCode(document.getElementById('couponInput').value.trim());
});

document.getElementById('couponInput').addEventListener('keydown', function(e){
  if (e.key === 'Enter') { e.preventDefault(); applyCouponCode(this.value.trim()); }
});

function applyPrivateCoupon(code) {
  document.getElementById('couponInput').value = code;
  applyCouponCode(code);
}

/* Load coupons (private first, then public) into dropdown */
function loadPublicCoupons() {
  var sel = document.getElementById('couponSelect');
  if (!sel) return;
  fetch('api/public_coupons.php?subtotal=' + _subtotal)
    .then(function(r){ return r.json(); })
    .then(function(list) {
      var privItems = list.filter(function(i){ return i.role === 'private'; });
      var pubItems  = list.filter(function(i){ return i.role !== 'private'; });

      function addOpt(parent, item) {
        var opt = document.createElement('option');
        opt.value = item.code;
        opt.textContent = item.label;
        if (!item.eligible) { opt.disabled = true; opt.textContent += ' (chưa đủ điều kiện)'; }
        parent.appendChild(opt);
      }

      if (privItems.length && pubItems.length) {
        var grpPriv = document.createElement('optgroup');
        grpPriv.label = '★ Ưu đãi riêng của bạn';
        privItems.forEach(function(i){ addOpt(grpPriv, i); });
        sel.appendChild(grpPriv);
        var grpPub = document.createElement('optgroup');
        grpPub.label = 'Mã khuyến mãi công khai';
        pubItems.forEach(function(i){ addOpt(grpPub, i); });
        sel.appendChild(grpPub);
      } else {
        list.forEach(function(i){ addOpt(sel, i); });
      }
    })
    .catch(function(){});
}

document.getElementById('couponSelect').addEventListener('change', function () {
  if (!this.value) return;
  document.getElementById('couponInput').value = this.value;
  applyCouponCode(this.value);
  this.value = '';
});

/* Auto-apply coupon from session if input is pre-filled */
(function () {
  loadPublicCoupons();
  var inp = document.getElementById('couponInput');
  if (inp && inp.value.trim()) {
    applyCouponCode(inp.value.trim());
  }
})();

function selectPay(el, val) {
  document.querySelectorAll('.pay-opt-shad').forEach(function(o){ o.classList.remove('selected'); });
  el.classList.add('selected');
  el.querySelector('input').checked = true;

  document.querySelectorAll('.pay-panel').forEach(function(p){ p.classList.remove('show'); });

  var amt = document.getElementById('grandTotalVal')
              ? document.getElementById('grandTotalVal').textContent
              : '';
  if (val === 'momo') {
    var panel = document.getElementById('panelMomo');
    if (panel) {
      document.getElementById('momoAmt').textContent = amt;
      panel.classList.add('show');
    }
  } else if (val === 'bank') {
    var panel = document.getElementById('panelBank');
    if (panel) {
      document.getElementById('bankAmt').textContent = amt;
      panel.classList.add('show');
    }
  }
}
</script>
<script src="js/moctra-functions.js"></script>
<script>
(function () {
  'use strict';

  var rules = [
    {
      id: 'ck-fullname',
      check: function (v) { return v.trim().length >= 2; },
      msg: 'Vui lòng nhập họ và tên (tối thiểu 2 ký tự).'
    },
    {
      id: 'ck-phone',
      check: function (v) { return /^(0|\+84)[0-9]{9}$/.test(v.trim()); },
      msg: 'Số điện thoại không hợp lệ (VD: 0912345678).'
    },
    {
      id: 'ck-address',
      check: function (v) { return v.trim().length >= 5; },
      msg: 'Vui lòng nhập địa chỉ giao hàng.'
    }
  ];

  function setErr(id, msg) {
    var inp = document.getElementById(id);
    var err = document.getElementById('err-' + id);
    if (inp) inp.style.borderColor = '#dc2626';
    if (err) err.textContent = msg;
  }
  function clearErr(id) {
    var inp = document.getElementById(id);
    var err = document.getElementById('err-' + id);
    if (inp) inp.style.borderColor = '';
    if (err) err.textContent = '';
  }
  function validEmail(v) {
    return v === '' || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
  }

  /* Blur-time validation */
  rules.forEach(function (r) {
    var inp = document.getElementById(r.id);
    if (!inp) return;
    inp.addEventListener('blur', function () {
      r.check(inp.value) ? clearErr(r.id) : setErr(r.id, r.msg);
    });
    inp.addEventListener('input', function () {
      if (r.check(inp.value)) clearErr(r.id);
    });
  });

  var emailInp = document.getElementById('ck-email');
  if (emailInp) {
    emailInp.addEventListener('blur', function () {
      validEmail(emailInp.value.trim()) ? clearErr('ck-email') : setErr('ck-email', 'Email không hợp lệ.');
    });
    emailInp.addEventListener('input', function () {
      if (validEmail(emailInp.value.trim())) clearErr('ck-email');
    });
  }

  /* Submit intercept */
  var form = document.getElementById('checkoutForm');
  var btn  = document.getElementById('btnOrder');

  form && form.addEventListener('submit', function (e) {
    var ok = true;

    rules.forEach(function (r) {
      var inp = document.getElementById(r.id);
      if (!inp) return;
      if (!r.check(inp.value)) { setErr(r.id, r.msg); ok = false; }
      else clearErr(r.id);
    });

    if (emailInp && !validEmail(emailInp.value.trim())) {
      setErr('ck-email', 'Email không hợp lệ.'); ok = false;
    }

    if (!ok) {
      e.preventDefault();
      var firstInvalid = form.querySelector('input[style*="dc2626"]');
      if (firstInvalid) firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }

    if (btn) { btn.disabled = true; btn.textContent = 'Đang xử lý...'; }
  });
})();
</script>
</body>
</html>


