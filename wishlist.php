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
  <title>Danh sách yêu thích — Mộc Trà Thái Nguyên</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Be+Vietnam+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/index.css">
  <link rel="stylesheet" href="css/components.css">
  <link rel="stylesheet" href="css/products.css">
  <style>
    body { background: #f9fafb; }
    .wishlist-wrap { max-width: 1280px; margin: 32px auto 60px; padding: 0 36px; }
    .wishlist-head { display: flex; align-items: end; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 26px; }
    .wishlist-head h1 { font-family: 'Playfair Display', serif; font-size: 30px; color: #111827; }
    .wishlist-head p { margin-top: 6px; color: #6b7280; font-size: 14px; }
    .wishlist-actions { display: flex; gap: 12px; flex-wrap: wrap; }
    .wish-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 12px 18px; border-radius: 999px; font-weight: 700; text-decoration: none; cursor: pointer; }
    .wish-btn.secondary { background: #fff; color: #374151; border: 1.5px solid #d1d5db; }
    .wish-btn.primary { background: linear-gradient(135deg, #166534, #0f5132); color: #fff; border: none; }
    .wishlist-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 20px; }
    .wish-card {
      background: #fff;
      border: 1px solid #f3f4f6;
      border-radius: 20px;
      overflow: hidden;
      box-shadow: 0 12px 32px rgba(15,81,50,.06);
      display: flex;
      flex-direction: column;
    }
    .wish-thumb { display: block; aspect-ratio: 1 / 1; background: linear-gradient(135deg, #f0fdf4, #f8fafc); }
    .wish-thumb img { width: 100%; height: 100%; object-fit: cover; }
    .wish-body { padding: 20px; display: flex; flex-direction: column; gap: 14px; flex: 1; }
    .wish-name { font-size: 18px; font-weight: 700; line-height: 1.45; color: #111827; text-decoration: none; }
    .wish-name:hover { color: #166534; }
    .wish-price { font-size: 22px; font-weight: 700; color: #dc2626; }
    .wish-meta { color: #6b7280; font-size: 13px; line-height: 1.7; min-height: 44px; }
    .wish-actions { display: flex; gap: 10px; margin-top: auto; }
    .wish-actions button, .wish-actions a {
      flex: 1;
      min-height: 44px;
      border-radius: 12px;
      border: none;
      text-decoration: none;
      font: inherit;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }
    .wish-remove { background: #fef2f2; color: #dc2626; }
    .wish-add-cart { background: #166534; color: #fff; }
    .empty-wishlist {
      background: #fff;
      border-radius: 22px;
      border: 1px solid #f3f4f6;
      text-align: center;
      padding: 58px 24px;
      box-shadow: 0 12px 32px rgba(15,81,50,.06);
    }
    .empty-wishlist svg { width: 70px; height: 70px; stroke: #d1d5db; fill: none; stroke-width: 1.7; margin-bottom: 18px; }
    .empty-wishlist h2 { font-family: 'Playfair Display', serif; font-size: 30px; color: #111827; margin-bottom: 10px; }
    .empty-wishlist p { max-width: 560px; margin: 0 auto 22px; color: #6b7280; line-height: 1.7; }
    .wishlist-hidden { display: none !important; }
    /* ── Skeleton shimmer ── */
    .skel { background: linear-gradient(90deg,#f3f4f6 25%,#e5e7eb 50%,#f3f4f6 75%); background-size:200% 100%; animation:skel-shimmer 1.4s ease-in-out infinite; border-radius:6px; }
    @keyframes skel-shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }
    @media (max-width: 980px) {
      .wishlist-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 700px) {
      .wishlist-wrap { padding: 0 18px; }
      .wishlist-grid { grid-template-columns: 1fr; }
    }
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
          <a href="profile.php"><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Hồ sơ của tôi</a>
          <a href="order_history.php"><svg viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="2"/></svg>Đơn hàng của tôi</a>
          <a href="my_vouchers.php"><svg viewBox="0 0 24 24"><path d="M20 12v10H4V12"/><path d="M22 7H2v5h20V7z"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>Kho Voucher</a>
          <div class="divider"></div>
          <a href="logout.php" class="logout"><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>Đăng xuất</a>
        </div>
      </div>
    </div>
  </div>
</nav>

<div class="breadcrumb-bar">
  <div class="breadcrumb-inner">
    <a href="index.php">Trang chủ</a>
    <span class="sep">›</span>
    <span class="current">Danh sách yêu thích</span>
  </div>
</div>

<div class="wishlist-wrap">
  <div class="wishlist-head">
    <div>
      <h1>Danh sách yêu thích</h1>
      <p id="wishlistCountText">Bạn đang có 0 sản phẩm yêu thích.</p>
    </div>
    <div class="wishlist-actions">
      <a href="products.php" class="wish-btn secondary">Tiếp tục khám phá</a>
      <button type="button" class="wish-btn primary" id="clearWishlistBtn">Xóa tất cả</button>
    </div>
  </div>

  <div class="empty-wishlist wishlist-hidden" id="emptyWishlist">
    <svg viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
    <h2>Chưa có sản phẩm yêu thích</h2>
    <p>Khi bạn bấm vào icon tim ở sản phẩm, chúng sẽ xuất hiện tại đây để bạn xem lại hoặc thêm nhanh vào giỏ hàng.</p>
    <a href="products.php" class="wish-btn primary">Xem sản phẩm ngay</a>
  </div>

  <div class="wishlist-grid" id="wishlistGrid">
    <!-- skeleton cards — replaced by JS when wishlist loads -->
    <?php foreach ([1,2,3] as $_): ?>
    <div class="wish-card">
      <div class="skel" style="aspect-ratio:1/1;border-radius:0;"></div>
      <div class="wish-body" style="gap:14px;">
        <div class="skel" style="height:20px;"></div>
        <div class="skel" style="height:26px;width:55%;"></div>
        <div class="skel" style="height:14px;"></div>
        <div class="skel" style="height:44px;border-radius:12px;margin-top:auto;"></div>
      </div>
    </div>
    <?php endforeach; ?>
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
(function () {
  var gridEl = document.getElementById('wishlistGrid');
  var emptyEl = document.getElementById('emptyWishlist');
  var countTextEl = document.getElementById('wishlistCountText');
  var clearBtn = document.getElementById('clearWishlistBtn');

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function getWishlistItems() {
    var store = window.MocTraStore;
    var wishlist = store ? store.readWishlist() : {};
    return Object.keys(wishlist).map(function (key) {
      return wishlist[key];
    });
  }

  function renderWishlist() {
    var store = window.MocTraStore;
    var items = getWishlistItems();
    countTextEl.textContent = 'Bạn đang có ' + items.length + ' sản phẩm yêu thích.';

    if (!items.length) {
      gridEl.classList.add('wishlist-hidden');
      emptyEl.classList.remove('wishlist-hidden');
      return;
    }

    gridEl.classList.remove('wishlist-hidden');
    emptyEl.classList.add('wishlist-hidden');
    gridEl.innerHTML = items.map(function (item) {
      var price = Number(item.price) || 0;
      var href = item.url || ('product_detail.php?id=' + item.id);
      return [
        '<article class="wish-card" data-wishlist-item="' + escapeHtml(item.id) + '">',
          '<a class="wish-thumb" href="' + escapeHtml(href) + '">',
            '<img src="' + escapeHtml(item.image || 'images/logo.png') + '" alt="' + escapeHtml(item.name) + '" onerror="this.src=\'images/logo.png\'">',
          '</a>',
          '<div class="wish-body">',
            '<a class="wish-name" href="' + escapeHtml(href) + '">' + escapeHtml(item.name) + '</a>',
            '<div class="wish-price">' + store.formatMoney(price) + '</div>',
            '<div class="wish-meta">Lưu lại để theo dõi, thêm vào giỏ hoặc quay lại xem chi tiết bất kỳ lúc nào.</div>',
            '<div class="wish-actions">',
              '<button type="button" class="wish-remove" data-remove-wishlist="' + escapeHtml(item.id) + '">Bỏ thích</button>',
              '<button type="button" class="wish-add-cart" data-add-cart'
                + ' data-product-id="' + escapeHtml(item.id) + '"'
                + ' data-product-name="' + escapeHtml(item.name) + '"'
                + ' data-product-price="' + price + '"'
                + ' data-product-image="' + escapeHtml(item.image || 'images/logo.png') + '"'
                + ' data-product-url="' + escapeHtml(href) + '">Thêm giỏ hàng</button>',
            '</div>',
          '</div>',
        '</article>'
      ].join('');
    }).join('');
  }

  document.addEventListener('click', function (event) {
    var removeBtn = event.target.closest('[data-remove-wishlist]');
    if (removeBtn) {
      window.MocTraStore.removeWishlistItem(removeBtn.getAttribute('data-remove-wishlist'));
      renderWishlist();
      return;
    }

    var addCartBtn = event.target.closest('[data-add-cart]');
    if (addCartBtn) {
      var cart = window.MocTraStore.readCart();
      var productId = addCartBtn.getAttribute('data-product-id');
      var current = cart[productId] && cart[productId].qty ? Number(cart[productId].qty) : 0;
      cart[productId] = {
        id: productId,
        name: addCartBtn.getAttribute('data-product-name') || '',
        price: Number(addCartBtn.getAttribute('data-product-price')) || 0,
        image: addCartBtn.getAttribute('data-product-image') || '',
        url: addCartBtn.getAttribute('data-product-url') || '',
        qty: current + 1
      };
      window.MocTraStore.writeCart(cart);
    }
  });

  clearBtn.addEventListener('click', function () {
    window.MocTraStore.writeWishlist({});
    renderWishlist();
  });

  renderWishlist();
})();
</script>
</body>
</html>


