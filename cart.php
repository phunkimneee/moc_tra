<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }
if ($_SESSION['role'] === 'admin') { header('Location: admin/dashboard.php'); exit(); }

$username    = htmlspecialchars($_SESSION['username'] ?? '');
$userInitial = strtoupper(substr($username, 0, 1));
$cats        = $conn->query("SELECT * FROM categories ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
  <title>Giỏ hàng — Mộc Trà Thái Nguyên</title>
  <!-- Font Awesome 6 -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Be+Vietnam+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/index.css">
  <link rel="stylesheet" href="css/products.css">
  <link rel="stylesheet" href="css/components.css">
  <style>
    /* Global Icon Styles */
    i.fa-solid, i.fa-regular, i.fa-brands {
        color: #2d5a27;
        transition: color 0.3s ease, transform 0.3s ease;
        margin-right: 8px;
    }
    .text-danger { color: #dc3545; font-weight: bold; margin-left: 2px; }

    body { background: #f9fafb; }
    .cart-wrap { max-width: 1320px; margin: 32px auto 60px; padding: 0 36px; }
    .cart-head { display: flex; align-items: end; justify-content: space-between; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
    .cart-head h1 { font-family: 'Playfair Display', serif; font-size: 30px; color: #111827; }
    .cart-head p { color: #6b7280; font-size: 14px; margin-top: 6px; }
    .cart-layout { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 24px; align-items: start; }
    .cart-panel, .summary-panel, .empty-cart { background: #fff; border: 1px solid #f3f4f6; border-radius: 18px; box-shadow: 0 10px 32px rgba(15, 81, 50, 0.06); }
    .cart-panel { overflow: hidden; }
    .cart-table-head, .cart-row { display: grid; grid-template-columns: minmax(0, 1.7fr) 140px 150px 140px 74px; gap: 12px; align-items: center; }
    .cart-table-head { padding: 18px 24px; background: #f9fafb; border-bottom: 1px solid #f3f4f6; font-size: 12px; color: #6b7280; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
    .cart-items { display: flex; flex-direction: column; }
    .cart-row { padding: 20px 24px; border-bottom: 1px solid #f3f4f6; }
    .cart-row:last-child { border-bottom: none; }
    .cart-product { display: flex; align-items: center; gap: 16px; min-width: 0; }
    .cart-product img { width: 88px; height: 88px; object-fit: cover; border-radius: 14px; background: #f0fdf4; flex-shrink: 0; }
    .cart-product-info { min-width: 0; }
    .cart-product-name { font-size: 16px; font-weight: 700; color: #111827; text-decoration: none; line-height: 1.4; display: block; margin-bottom: 6px; }
    .cart-product-name:hover { color: #166534; }
    .cart-product-sub { font-size: 13px; color: #6b7280; }
    .cart-price, .cart-total { font-size: 15px; font-weight: 700; color: #111827; }
    .cart-total { color: #dc2626; }
    .cart-qty { display: inline-flex; align-items: center; border: 1.5px solid #e5e7eb; border-radius: 999px; overflow: hidden; }
    .cart-qty button { width: 38px; height: 38px; border: none; background: #fff; color: #374151; cursor: pointer; font-size: 18px; }
    .cart-qty button:hover { background: #f0fdf4; color: #166534; }
    .cart-qty input { width: 52px; height: 38px; border: none; border-left: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb; text-align: center; font: inherit; font-weight: 700; color: #111827; outline: none; }
    .cart-remove { width: 42px; height: 42px; border: none; border-radius: 50%; background: #fef2f2; color: #dc2626; cursor: pointer; transition: transform .18s, background .18s; }
    .cart-remove:hover { transform: scale(1.06); background: #fee2e2; }
    .cart-foot { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 18px 24px; background: #fcfcfc; }
    .btn-ghost, .btn-primary-cart { display: inline-flex; align-items: center; justify-content: center; gap: 8px; border-radius: 999px; padding: 12px 18px; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
    .btn-ghost { border: 1.5px solid #d1d5db; color: #374151; background: #fff; }
    .btn-ghost:hover { border-color: #166534; color: #166534; }
    .btn-primary-cart { border: none; color: #fff; background: linear-gradient(135deg, #166534, #0f5132); box-shadow: 0 10px 22px rgba(15, 81, 50, .2); }
    .btn-primary-cart:hover { transform: translateY(-1px); }
    .summary-panel { padding: 24px; position: sticky; top: 118px; }
    .summary-panel h2 { font-family: 'Playfair Display', serif; font-size: 23px; color: #111827; margin-bottom: 18px; }
    .summary-list { display: flex; flex-direction: column; gap: 12px; }
    .summary-line { display: flex; align-items: center; justify-content: space-between; gap: 12px; color: #6b7280; font-size: 14px; }
    .summary-line.total { padding-top: 14px; margin-top: 2px; border-top: 1px solid #f3f4f6; color: #111827; font-size: 18px; font-weight: 700; }
    .summary-line.total strong { color: #dc2626; }
    .summary-note { margin-top: 16px; padding: 14px 16px; border-radius: 14px; background: #f0fdf4; color: #166534; font-size: 13px; line-height: 1.6; }
    .summary-panel .btn-primary-cart { width: 100%; margin-top: 18px; padding: 14px 18px; }
    .empty-cart { padding: 48px 30px; text-align: center; }
    .empty-cart svg { width: 68px; height: 68px; margin-bottom: 14px; stroke: #9ca3af; fill: none; stroke-width: 1.8; }
    .empty-cart h2 { font-family: 'Playfair Display', serif; font-size: 28px; color: #111827; margin-bottom: 10px; }
    .empty-cart p { max-width: 520px; margin: 0 auto 22px; color: #6b7280; line-height: 1.7; }
    .cart-hidden { display: none !important; }
    @media (max-width: 1100px) {
      .cart-layout { grid-template-columns: 1fr; }
      .summary-panel { position: static; }
    }
    @media (max-width: 860px) {
      .cart-wrap { padding: 0 18px; }
      .cart-table-head { display: none; }
      .cart-row { grid-template-columns: 1fr; gap: 14px; }
      .cart-foot { flex-direction: column; align-items: stretch; }
    }
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
    /* ── Skeleton shimmer ── */
    .skel { background: linear-gradient(90deg,#f3f4f6 25%,#e5e7eb 50%,#f3f4f6 75%); background-size:200% 100%; animation:skel-shimmer 1.4s ease-in-out infinite; border-radius:6px; }
    @keyframes skel-shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }
  </style>
</head>
<body>

<nav class="navbar" id="navbar">
  <div class="navbar-inner">
    <a href="index.php" class="brand">
      <img src="images/logo.png" alt="Logo" onerror="this.style.display='none'">
    </a>
    <div class="nav-menu">
      <a href="index.php" class="nav-link">Trang chủ</a>
      <div class="nav-dropdown">
        <button id="catBtn" class="nav-link" style="display:flex;align-items:center;gap:4px;">
          Danh mục
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div id="catMenu" class="nav-dropdown-menu">
          <div class="dd-col" style="flex:1">
            <h4>Loại trà</h4>
            <?php foreach ($cats as $c): ?>
            <a href="products.php?category=<?= htmlspecialchars($c['slug']) ?>">
              <span class="dd-dot" style="font-size:15px;width:auto;height:auto;"><i class="<?= htmlspecialchars($c['icon']) ?>"></i></span>
              <?= htmlspecialchars($c['name']) ?>
            </a>
            <?php endforeach; ?>
          </div>
          <div class="dd-col" style="flex:1">
            <h4>Dạng sản phẩm</h4>
            <a href="products.php?type=la">Trà lá rời</a>
            <a href="products.php?type=tui_loc">Trà túi lọc</a>
            <a href="products.php?type=tui_tam_giac">Trà túi tam giác</a>
            <a href="products.php?type=bot">Bột trà nghiền</a>
          </div>
          <div class="dd-col" style="flex:1">
            <h4>Xuất xứ</h4>
            <a href="products.php?q=Việt+Nam">🇻🇳 Việt Nam</a>
            <a href="products.php?q=Nhật+Bản">🇯🇵 Nhật Bản</a>
            <a href="products.php?q=Trung+Quốc">🇨🇳 Trung Quốc</a>
            <a href="products.php?q=Đài+Loan">🇹🇼 Đài Loan</a>
          </div>
        </div>
      </div>
      <a href="products.php" class="nav-link">Sản phẩm</a>
      <a href="index.php#about" class="nav-link">Giới thiệu</a>
      <a href="reviews.php" class="nav-link">Đánh giá</a>
      <a href="contact.php" class="nav-link">Liên hệ</a>
    </div>
    <div class="nav-right">
      <a href="wishlist.php" class="nav-icon-btn" title="Yêu thích" data-nav-wishlist>
        <svg viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
      </a>
      <a href="cart.php" class="nav-icon-btn" title="Giỏ hàng" data-nav-cart>
        <svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
        <span class="badge" data-cart-badge>0</span>
      </a>
      <div class="user-dropdown">
        <div class="user-btn" id="userBtn">
          <div class="user-avatar"><?= $userInitial ?></div>
          <span><?= $username ?></span>
          <svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <div class="user-menu" id="userMenu">
          <div class="user-menu-header">
            <div class="uname"><?= $username ?></div>
            <div class="urole">Khách hàng</div>
          </div>
          <a href="profile.php">
            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Hồ sơ của tôi
          </a>
          <a href="order_history.php">
            <svg viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="2"/></svg>
            Đơn hàng của tôi
          </a>
          <a href="my_vouchers.php">
            <svg viewBox="0 0 24 24"><path d="M20 12v10H4V12"/><path d="M22 7H2v5h20V7z"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>
            Kho Voucher
          </a>
          <div class="divider"></div>
          <a href="logout.php" class="logout">
            <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Đăng xuất
          </a>
        </div>
      </div>
    </div>
  </div>
</nav>

<div class="breadcrumb-bar">
  <div class="breadcrumb-inner">
    <a href="index.php">Trang chủ</a>
    <span class="sep">›</span>
    <span class="current">Giỏ hàng</span>
  </div>
</div>

<div class="cart-wrap">
  <div class="ck-steps">
    <div class="ck-step active"><div class="ck-step-circle">1</div><span class="ck-step-label">Giỏ hàng</span></div>
    <div class="ck-step-line"></div>
    <div class="ck-step pending"><div class="ck-step-circle">2</div><span class="ck-step-label">Thanh toán</span></div>
    <div class="ck-step-line"></div>
    <div class="ck-step pending"><div class="ck-step-circle">3</div><span class="ck-step-label">Xác nhận</span></div>
  </div>
  <div class="cart-head">
    <div>
      <h1>Giỏ hàng của bạn</h1>
      <p id="cartCountText">Đang có 0 sản phẩm trong giỏ hàng.</p>
    </div>
    <a href="products.php" class="btn-ghost">Tiếp tục mua sắm</a>
  </div>

  <?php if (!empty($_GET['out_of_stock'])): ?>
  <div style="background:#fef2f2;border-left:4px solid #dc2626;border-radius:8px;padding:12px 16px;margin-bottom:18px;color:#991b1b;font-size:14px;">
    <strong>Sản phẩm hết hàng:</strong> <?= htmlspecialchars($_GET['out_of_stock']) ?> — Vui lòng xóa khỏi giỏ hoặc giảm số lượng.
  </div>
  <?php endif; ?>

  <div class="empty-cart cart-hidden" id="emptyCart">
    <svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
    <h2>Giỏ hàng đang trống</h2>
    <p>Bạn chưa thêm sản phẩm nào cả. Chọn vài món trà thật thơm rồi quay lại đây, mình sẽ giữ sẵn để bạn tiếp tục thanh toán.</p>
    <a href="products.php" class="btn-primary-cart">Khám phá sản phẩm</a>
  </div>

  <div class="cart-layout" id="cartLayout">
    <div class="cart-panel">
      <div class="cart-table-head">
        <div>Sản phẩm</div>
        <div>Đơn giá</div>
        <div>Số lượng</div>
        <div>Thành tiền</div>
        <div></div>
      </div>
      <div class="cart-items" id="cartItems">
        <!-- skeleton rows — replaced by JS when cart loads -->
        <?php foreach ([1,2] as $_): ?>
        <div class="cart-row">
          <div class="cart-product">
            <div class="skel" style="width:88px;height:88px;border-radius:14px;flex-shrink:0;"></div>
            <div class="cart-product-info" style="gap:8px;display:flex;flex-direction:column;">
              <div class="skel" style="height:15px;width:65%;"></div>
              <div class="skel" style="height:12px;width:35%;"></div>
            </div>
          </div>
          <div class="skel" style="height:18px;width:75%;"></div>
          <div class="skel" style="height:36px;width:120px;border-radius:8px;"></div>
          <div class="skel" style="height:18px;width:75%;"></div>
          <div style="display:flex;justify-content:center;"><div class="skel" style="width:30px;height:30px;"></div></div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="cart-foot">
        <button type="button" class="btn-ghost" id="clearCartBtn">Xóa toàn bộ giỏ hàng</button>
        <a href="products.php" class="btn-ghost">Thêm sản phẩm khác</a>
      </div>
    </div>

    <aside class="summary-panel">
      <h2>Tóm tắt đơn hàng</h2>
      <div class="summary-list">
        <div class="summary-line"><span>Tạm tính</span><strong id="summarySubtotal">0đ</strong></div>
        <div class="summary-line"><span>Phí vận chuyển</span><strong id="summaryShipping">22.000đ</strong></div>
        <div class="summary-line"><span>Ưu đãi freeship</span><strong id="summaryFreeShipHint">Mua thêm 300.000đ để được miễn phí</strong></div>
        <div class="summary-line total"><span>Tổng cộng</span><strong id="summaryTotal">0đ</strong></div>
      </div>
      <div class="summary-note">
        Giỏ hàng này đang chạy theo flow frontend hiện tại. Khi bạn bấm thanh toán, hệ thống sẽ đồng bộ giỏ hàng sang phiên làm việc để tiếp tục dùng màn checkout cũ.
      </div>
      <form method="POST" action="checkout.php" id="checkoutSyncForm">
        <input type="hidden" name="cart_payload" id="cartPayloadInput">
        <button type="submit" class="btn-primary-cart" id="checkoutBtn">Tiến hành thanh toán</button>
      </form>
    </aside>
  </div>
</div>

<script>
(function () {
  var catBtn = document.getElementById('catBtn');
  var catMenu = document.getElementById('catMenu');
  var userBtn = document.getElementById('userBtn');
  var userMenu = document.getElementById('userMenu');

  function closeAll() {
    catMenu && catMenu.classList.remove('open');
    userMenu && userMenu.classList.remove('open');
    catBtn && catBtn.classList.remove('open');
    userBtn && userBtn.classList.remove('open');
  }

  catBtn && catBtn.addEventListener('click', function (e) {
    e.stopPropagation();
    var open = catMenu.classList.contains('open');
    closeAll();
    if (!open) {
      catMenu.classList.add('open');
      catBtn.classList.add('open');
    }
  });

  userBtn && userBtn.addEventListener('click', function (e) {
    e.stopPropagation();
    var open = userMenu.classList.contains('open');
    closeAll();
    if (!open) {
      userMenu.classList.add('open');
      userBtn.classList.add('open');
    }
  });

  document.addEventListener('click', closeAll);
})();
</script>
<script src="js/moctra-functions.js"></script>
<script>
var SHIP_FEE = <?= SHIP_FEE ?>;
var FREE_SHIP_THRESHOLD = <?= FREE_SHIP_THRESHOLD ?>;
</script>
<script>
(function () {
  var cartItemsEl = document.getElementById('cartItems');
  var cartLayoutEl = document.getElementById('cartLayout');
  var emptyCartEl = document.getElementById('emptyCart');
  var cartCountTextEl = document.getElementById('cartCountText');
  var subtotalEl = document.getElementById('summarySubtotal');
  var shippingEl = document.getElementById('summaryShipping');
  var freeShipEl = document.getElementById('summaryFreeShipHint');
  var totalEl = document.getElementById('summaryTotal');
  var payloadInput = document.getElementById('cartPayloadInput');
  var checkoutForm = document.getElementById('checkoutSyncForm');
  var clearCartBtn = document.getElementById('clearCartBtn');

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function getCartEntries() {
    var store = window.MocTraStore;
    var cart = store ? store.readCart() : {};
    return Object.keys(cart).map(function (key) {
      return cart[key];
    });
  }

  function renderCart() {
    var store = window.MocTraStore;
    var items = getCartEntries();
    var totalQty = 0;
    var subtotal = 0;

    items.forEach(function (item) {
      totalQty += Number(item.qty) || 0;
      subtotal += (Number(item.price) || 0) * (Number(item.qty) || 0);
    });

    if (!items.length) {
      cartLayoutEl.classList.add('cart-hidden');
      emptyCartEl.classList.remove('cart-hidden');
      cartCountTextEl.textContent = 'Đang có 0 sản phẩm trong giỏ hàng.';
      payloadInput.value = JSON.stringify({});
      return;
    }

    cartLayoutEl.classList.remove('cart-hidden');
    emptyCartEl.classList.add('cart-hidden');
    cartCountTextEl.textContent = 'Đang có ' + totalQty + ' sản phẩm trong giỏ hàng.';

    cartItemsEl.innerHTML = items.map(function (item) {
      var price = Number(item.price) || 0;
      var qty = Number(item.qty) || 0;
      var lineTotal = price * qty;
      return [
        '<div class="cart-row" data-cart-item="' + escapeHtml(item.id) + '">',
          '<div class="cart-product">',
            '<img src="' + escapeHtml(item.image || 'images/logo.png') + '" alt="' + escapeHtml(item.name) + '" onerror="this.onerror=null;this.src=\'images/logo.png\'">',
            '<div class="cart-product-info">',
              '<a class="cart-product-name" href="' + escapeHtml(item.url || ('product_detail.php?id=' + item.id)) + '">' + escapeHtml(item.name) + '</a>',
              '<div class="cart-product-sub">Mã sản phẩm #' + escapeHtml(item.id) + '</div>',
            '</div>',
          '</div>',
          '<div class="cart-price">' + store.formatMoney(price) + '</div>',
          '<div><div class="cart-qty"><button type="button" data-cart-decrease="' + escapeHtml(item.id) + '">−</button><input type="number" min="1" value="' + qty + '" data-cart-qty="' + escapeHtml(item.id) + '"><button type="button" data-cart-increase="' + escapeHtml(item.id) + '">+</button></div></div>',
          '<div class="cart-total">' + store.formatMoney(lineTotal) + '</div>',
          '<div><button type="button" class="cart-remove" title="Xóa" data-cart-remove="' + escapeHtml(item.id) + '">×</button></div>',
        '</div>'
      ].join('');
    }).join('');

    var shipping = subtotal >= FREE_SHIP_THRESHOLD ? 0 : SHIP_FEE;
    subtotalEl.textContent = store.formatMoney(subtotal);
    shippingEl.textContent = shipping === 0 ? 'Miễn phí' : store.formatMoney(shipping);
    freeShipEl.textContent = shipping === 0
      ? 'Bạn đã được miễn phí vận chuyển'
      : 'Mua thêm ' + store.formatMoney(FREE_SHIP_THRESHOLD - subtotal) + ' để được miễn phí';
    totalEl.textContent = store.formatMoney(subtotal + shipping);
    payloadInput.value = JSON.stringify(store.readCart());
  }

  document.addEventListener('click', function (event) {
    var store = window.MocTraStore;
    var decreaseBtn = event.target.closest('[data-cart-decrease]');
    if (decreaseBtn) {
      var id = decreaseBtn.getAttribute('data-cart-decrease');
      var current = store.readCart()[id];
      if (current) store.setCartItemQty(id, Math.max(1, Number(current.qty || 1) - 1));
      renderCart();
      return;
    }

    var increaseBtn = event.target.closest('[data-cart-increase]');
    if (increaseBtn) {
      var id2 = increaseBtn.getAttribute('data-cart-increase');
      var current2 = store.readCart()[id2];
      if (current2) store.setCartItemQty(id2, Number(current2.qty || 0) + 1);
      renderCart();
      return;
    }

    var removeBtn = event.target.closest('[data-cart-remove]');
    if (removeBtn) {
      var id3 = removeBtn.getAttribute('data-cart-remove');
      store.removeCartItem(id3);
      renderCart();
    }
  });

  document.addEventListener('change', function (event) {
    var qtyInput = event.target.closest('[data-cart-qty]');
    if (!qtyInput) return;
    var store = window.MocTraStore;
    var id = qtyInput.getAttribute('data-cart-qty');
    var nextQty = parseInt(qtyInput.value, 10);
    store.setCartItemQty(id, Number.isFinite(nextQty) && nextQty > 0 ? nextQty : 1);
    renderCart();
  });

  clearCartBtn.addEventListener('click', function () {
    window.MocTraStore.writeCart({});
    renderCart();
  });

  checkoutForm.addEventListener('submit', function (event) {
    if (!Object.keys(window.MocTraStore.readCart()).length) {
      event.preventDefault();
    }
  });

  renderCart();
})();
</script>
</body>
</html>


