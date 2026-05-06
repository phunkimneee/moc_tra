<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();
require_once 'config/db.php';

// Cho phép guest xem trang chủ (public page)
// Admin cũng được xem giao diện shop thực tế từ nút "Xem trang shop"
$isLoggedIn  = isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'customer';
$isAdmin     = isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'admin';
$username    = $isLoggedIn ? htmlspecialchars($_SESSION['username'] ?? '') : '';
$userInitial = $isLoggedIn && $username ? strtoupper(substr($username, 0, 1)) : '';

/* ── CSRF token (needed for notification AJAX) ── */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $isLoggedIn ? $_SESSION['csrf_token'] : '';

/* ── Notification table (auto-create + migrate ENUM) ── */
if ($isLoggedIn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `notifications` (
      `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `user_id`      INT UNSIGNED NOT NULL,
      `type`         ENUM('review_reply','contact_reply','voucher_gifted') NOT NULL,
      `reference_id` INT UNSIGNED NOT NULL DEFAULT 0,
      `message`      VARCHAR(1000) NOT NULL DEFAULT '',
      `is_read`      TINYINT(1)   NOT NULL DEFAULT 0,
      `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_user_read` (`user_id`, `is_read`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->query("ALTER TABLE `notifications`
      MODIFY COLUMN `type`         ENUM('review_reply','contact_reply','voucher_gifted') NOT NULL,
      MODIFY COLUMN `reference_id` INT UNSIGNED NOT NULL DEFAULT 0,
      MODIFY COLUMN `message`      VARCHAR(1000) NOT NULL DEFAULT ''");
}

/* ── Lấy danh mục cho dropdown navbar ── */
$cats = $conn->query("SELECT * FROM categories ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);

/* ── Helper format giá ── */
function fmt($n) {
    return number_format((int)$n, 0, ',', '.') . 'đ';
}

/* ── Lấy sản phẩm với filter an toàn (không nhận raw SQL) ── */
function fetchProducts(mysqli $conn, string $filter = 'all', int $limit = 4): array {
    $sql = match($filter) {
        'flash'    => "SELECT id,name,image,price,price_old,is_featured,is_new FROM products WHERE is_featured=1 AND price_old IS NOT NULL ORDER BY created_at DESC LIMIT ?",
        'featured' => "SELECT id,name,image,price,price_old,is_featured,is_new FROM products WHERE is_featured=1 ORDER BY created_at DESC LIMIT ?",
        'new'      => "SELECT id,name,image,price,price_old,is_featured,is_new FROM products WHERE is_new=1 ORDER BY created_at DESC LIMIT ?",
        default    => "SELECT id,name,image,price,price_old,is_featured,is_new FROM products ORDER BY created_at DESC LIMIT ?",
    };
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$flashProducts = fetchProducts($conn, 'flash', 4);
$featured      = fetchProducts($conn, 'featured', 3);
$newProds      = fetchProducts($conn, 'new', 4);

/* fallback: nếu không đủ sản phẩm thì lấy tất cả */
if (empty($flashProducts)) $flashProducts = fetchProducts($conn, 'all', 4);
if (empty($newProds))      $newProds      = fetchProducts($conn, 'all', 4);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php if ($isLoggedIn): ?><meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>"><?php endif; ?>
  <title>Mộc Trà Thái Nguyên</title>
  <!-- Font Awesome 6 -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Be+Vietnam+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/index.css">
  <link rel="stylesheet" href="css/components.css">
  <style>
    /* Global Icon Styles */
    i.fa-solid, i.fa-regular, i.fa-brands {
        color: #2d5a27;
        transition: color 0.3s ease, transform 0.3s ease;
        margin-right: 8px;
    }
    .text-danger { color: #dc3545; font-weight: bold; margin-left: 2px; }
    
    /* Bug 2 Fix: Product card alignment */
    .product-grid, .featured-grid, .flash-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
      gap: 24px;
    }
    .pcard {
      display: flex;
      flex-direction: column;
      height: 100%;
    }
    .pcard-body {
      flex: 1;
      display: flex;
      flex-direction: column;
    }
    .pcard-meta, .pcard-price, .pcard-btns {
      margin-top: auto;
    }

    /* ── Notification Bell ── */
    .notif-wrap {
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
      width: 50px; height: 50px;
      flex-shrink: 0;
    }
    .notif-btn { position: relative; }
    .notif-badge {
      pointer-events: none;
    }
    .notif-dropdown {
      position: absolute; top: calc(100% + 10px); right: 0;
      width: 340px; background: #fff;
      border-radius: 14px;
      box-shadow: 0 8px 40px rgba(0,0,0,.14);
      border: 1px solid #f3f4f6;
      z-index: 999; display: none; overflow: hidden;
    }
    .notif-dropdown.open { display: block; }
    .notif-dd-hd {
      display: flex; align-items: center; justify-content: space-between;
      padding: 14px 16px 10px;
      border-bottom: 1px solid #f3f4f6;
      font-weight: 700; font-size: 14px; color: #111827;
    }
    .notif-mark-all {
      background: none; border: none; font-size: 12px;
      color: #166534; cursor: pointer; font-family: inherit; padding: 0;
    }
    .notif-mark-all:hover { text-decoration: underline; }
    .notif-list { max-height: 380px; overflow-y: auto; }
    .notif-item {
      display: flex; gap: 12px; padding: 12px 16px;
      cursor: pointer; transition: background .15s;
      border-bottom: 1px solid #f9fafb;
    }
    .notif-item:last-child { border-bottom: none; }
    .notif-item:hover { background: #f9fafb; }
    .notif-item.unread { background: #f0fdf4; }
    .notif-item.unread:hover { background: #dcfce7; }
    .notif-icon {
      width: 36px; height: 36px; border-radius: 50%;
      background: #dcfce7; display: flex; align-items: center;
      justify-content: center; flex-shrink: 0; font-size: 16px;
    }
    .notif-item-body { flex: 1; min-width: 0; }
    .notif-item-title {
      font-size: 13px; font-weight: 600; color: #111827;
      margin-bottom: 3px; white-space: nowrap;
      overflow: hidden; text-overflow: ellipsis;
    }
    .notif-item-preview {
      font-size: 12px; color: #6b7280;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .notif-item-time { font-size: 11px; color: #9ca3af; margin-top: 4px; }
    .notif-unread-dot {
      width: 8px; height: 8px; background: #22c55e;
      border-radius: 50%; flex-shrink: 0; align-self: center;
    }
    .notif-empty {
      padding: 32px 16px; text-align: center;
      color: #9ca3af; font-size: 13px;
    }

    /* ── Notification Modal ── */
    .notif-modal-overlay {
      position: fixed; inset: 0;
      background: rgba(0,0,0,.45); z-index: 1100;
      display: none; align-items: center; justify-content: center; padding: 20px;
    }
    .notif-modal-overlay.open { display: flex; }
    .notif-modal {
      background: #fff; border-radius: 18px;
      width: 100%; max-width: 520px;
      box-shadow: 0 20px 60px rgba(0,0,0,.2);
      overflow: hidden; max-height: 90vh;
      display: flex; flex-direction: column;
    }
    .notif-modal-hd {
      display: flex; align-items: center; justify-content: space-between;
      padding: 20px 24px 16px; border-bottom: 1px solid #f3f4f6;
    }
    .notif-modal-hd h3 { font-size: 16px; font-weight: 700; color: #111827; margin: 0; }
    .notif-modal-close {
      background: #f3f4f6; border: none;
      width: 32px; height: 32px; border-radius: 50%;
      font-size: 20px; cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      color: #6b7280; line-height: 1;
    }
    .notif-modal-close:hover { background: #e5e7eb; }
    .notif-modal-body { padding: 20px 24px; overflow-y: auto; flex: 1; }
    .notif-modal-label {
      font-size: 11px; font-weight: 700;
      text-transform: uppercase; letter-spacing: .5px;
      color: #9ca3af; margin-bottom: 8px;
    }
    .notif-modal-text {
      font-size: 14px; color: #374151; line-height: 1.65;
      background: #f9fafb; border-radius: 10px;
      padding: 14px 16px; margin-bottom: 18px; white-space: pre-wrap;
    }
    .notif-admin-reply {
      background: #f0fdf4; border-left: 3px solid #22c55e;
      border-radius: 10px; padding: 14px 16px;
      font-size: 14px; color: #166534; line-height: 1.65;
      margin-bottom: 18px; white-space: pre-wrap;
    }
    .notif-modal-reply { padding: 16px 24px 20px; border-top: 1px solid #f3f4f6; }
    .notif-modal-reply textarea {
      width: 100%; padding: 10px 14px;
      border: 1.5px solid #e5e7eb; border-radius: 10px;
      font-size: 13px; font-family: inherit;
      resize: vertical; min-height: 80px; outline: none;
      transition: border-color .2s; box-sizing: border-box;
    }
    .notif-modal-reply textarea:focus { border-color: #166534; }
    .btn-send-reply {
      margin-top: 10px; width: 100%; padding: 11px;
      background: linear-gradient(135deg,#166534,#0f5132);
      color: #fff; border: none; border-radius: 10px;
      font-size: 14px; font-weight: 700; font-family: inherit;
      cursor: pointer; transition: opacity .2s;
    }
    .btn-send-reply:hover { opacity: .88; }
    .btn-send-reply:disabled { opacity: .5; cursor: not-allowed; }
  </style>
</head>
<body>

<!-- ══ NAVBAR ══ -->
<nav class="navbar" id="navbar">
  <div class="navbar-inner">
    <div class="brand" style="cursor:default;pointer-events:none;">
      <img src="images/logo.png" alt="Logo" onerror="this.style.display='none'">
    </div>

    <div class="nav-menu">
      <a href="#top" class="nav-link active" onclick="smoothTo('top',event)">Trang chủ</a>

      <!-- Danh mục dropdown — link thật sang products.php -->
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

      <a href="#products-section" class="nav-link" id="navProducts" onclick="smoothTo('products-section',event)">Sản phẩm</a>
      <a href="#about" class="nav-link" onclick="smoothTo('about',event)">Giới thiệu</a>
      <a href="reviews.php" class="nav-link">Đánh giá</a>
      <a href="contact.php" class="nav-link">Liên hệ</a>
    </div>

    <!-- Search -->
    <div class="nav-search-wrapper">
      <div class="nav-search">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <form action="products.php" method="GET" style="display:flex; flex:1; margin:0; gap:8px;" onsubmit="event.preventDefault(); var v=this.q.value.trim(); if(v) window.location.href='products.php?q='+encodeURIComponent(v);">
          <input type="text" name="q" placeholder="Tìm kiếm sản phẩm..." style="flex:1;" autocomplete="off">
          <button type="submit" class="nav-search-btn">Tìm</button>
        </form>
      </div>
      <div id="search-suggestions" class="search-suggestions"></div>
    </div>
    <div class="search-overlay"></div>

    <div class="nav-right">
      <!-- Yêu thích (chỉ hiện khi đã login) -->
      <?php if ($isLoggedIn): ?>
      <a href="wishlist.php" class="nav-icon-btn" title="Yêu thích" data-nav-wishlist>
        <svg viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
      </a>
      <?php endif; ?>

      <!-- Giỏ hàng (ẩn đối với admin) -->
      <?php if (empty($isAdmin)): ?>
      <a href="cart.php" class="nav-icon-btn" title="Giỏ hàng" data-nav-cart>
        <svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
        <span class="badge" data-cart-badge>0</span>
      </a>
      <?php endif; ?>

      <!-- Bell icon (chỉ hiện khi đã login) -->
      <?php if ($isLoggedIn): ?>
      <div class="notif-wrap" id="notifWrap">
        <a href="#" class="nav-icon-btn notif-btn" id="notifBtn" title="Thông báo" onclick="event.preventDefault()">
          <svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          <span class="badge notif-badge" id="notifBadge" style="display:none">0</span>
        </a>
        <div class="notif-dropdown" id="notifDropdown">
          <div class="notif-dd-hd">
            <span>Thông báo</span>
            <button class="notif-mark-all" id="notifMarkAll">Đánh dấu tất cả đã đọc</button>
          </div>
          <div class="notif-list" id="notifList">
            <div class="notif-empty">Đang tải...</div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($isLoggedIn): ?>
      <!-- Đã đăng nhập: hiện user dropdown -->
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

      <?php elseif ($isAdmin): ?>
      <!-- Admin đang xem shop: hiện badge nhận biết + nút về admin -->
   <a href="admin/dashboard.php" title="Về trang quản trị"
   style="display: inline-flex; align-items: center; justify-content: center; gap: 6px; width: auto; height: auto; background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; border-radius: 6px; padding: 8px 16px; font-size: 14px; font-weight: 700; white-space: nowrap; text-decoration: none;">
   ⚙ Admin
    
  </a>


      <?php else: ?>
      <!-- Chưa đăng nhập: hiện nút Đăng nhập -->
      <a href="login.php" class="btn-login-nav">Đăng nhập</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<!-- ══ HERO ══ -->
<section class="hero" id="top">
  <div class="hero-inner">
    <div class="hero-text">
      <div class="hero-badge">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
        Trà xanh Thái Nguyên chính gốc
      </div>
      <h1 class="hero-title">Hương vị <span>thiên nhiên</span><br>trong từng ngụm trà</h1>
      <p class="hero-desc">Sản phẩm được thu hái từ vùng chè Tân Cương nổi tiếng, đảm bảo chất lượng và hương thơm tự nhiên tuyệt hảo. Giao hàng toàn quốc, cam kết 100% chính gốc.</p>
      <div class="hero-btns">
        <a href="products.php" class="btn-primary">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
          Mua ngay
        </a>
        <a href="#about" class="btn-outline-white" onclick="smoothTo('about',event)">Tìm hiểu thêm</a>
      </div>
      <div class="hero-stats">
        <div class="stat-item"><div class="stat-num">500+</div><div class="stat-lbl">Sản phẩm</div></div>
        <div class="stat-item"><div class="stat-num">10K+</div><div class="stat-lbl">Khách hàng</div></div>
        <div class="stat-item"><div class="stat-num">4.9★</div><div class="stat-lbl">Đánh giá</div></div>
      </div>
    </div>
    <div class="hero-img">
      <img src="images/logo.png" alt="Trà xanh" onerror="this.onerror=null;this.src='images/logo.png'">
    </div>
  </div>

  <div style="position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,0.25);border-top:1px solid rgba(255,255,255,0.10);padding:18px 0;">
    <div style="max-width:1400px;margin:0 auto;padding:0 36px;display:flex;align-items:center;justify-content:space-between;gap:24px;flex-wrap:wrap;">
      <div style="display:flex;align-items:center;gap:10px;">
        <img src="images/logo.png" alt="Logo" style="height:36px;opacity:0.85;" onerror="this.style.display='none'">
        <div>
          <div style="color:rgba(255,255,255,0.9);font-size:13px;font-weight:700;">Mộc Trà Thái Nguyên</div>
          <div style="color:rgba(255,255,255,0.45);font-size:11.5px;">Thương hiệu trà uy tín từ vùng đất Tân Cương</div>
        </div>
      </div>
      <div style="display:flex;gap:28px;">
        <div style="text-align:center;">
          <div style="color:var(--gold-light);font-size:18px;font-weight:700;font-family:'Playfair Display',serif;">10+</div>
          <div style="color:rgba(255,255,255,0.45);font-size:11px;">Năm kinh nghiệm</div>
        </div>
        <div style="text-align:center;">
          <div style="color:var(--gold-light);font-size:18px;font-weight:700;font-family:'Playfair Display',serif;">100%</div>
          <div style="color:rgba(255,255,255,0.45);font-size:11px;">Tự nhiên</div>
        </div>
        <div style="text-align:center;">
          <div style="color:var(--gold-light);font-size:18px;font-weight:700;font-family:'Playfair Display',serif;">50+</div>
          <div style="color:rgba(255,255,255,0.45);font-size:11px;">Loại trà</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ══ GIỚI THIỆU ══ -->
<section id="about">
  <div class="about-inner">
    <div class="about-text">
      <div class="label">✦ Về chúng tôi</div>
      <h2>Thương hiệu trà <span>uy tín</span><br>từ vùng đất Thái Nguyên</h2>
      <p>Mộc Trà Thái Nguyên ra đời từ tình yêu với những cây chè xanh mướt trên vùng đất Tân Cương — nơi có thổ nhưỡng và khí hậu lý tưởng để tạo nên những búp chè thơm ngon bậc nhất Việt Nam.</p>
      <p>Chúng tôi cam kết mang đến sản phẩm trà sạch, không hóa chất, được thu hái và chế biến theo phương pháp truyền thống kết hợp công nghệ hiện đại.</p>
      <ul class="about-points">
        <li>Thu hái thủ công từ vùng chè Tân Cương, Thái Nguyên</li>
        <li>Không sử dụng hương liệu nhân tạo hay phẩm màu</li>
        <li>Quy trình chế biến khép kín, đạt tiêu chuẩn VSATTP</li>
        <li>Hơn 10 năm kinh nghiệm trong ngành trà Việt Nam</li>
      </ul>
      <a href="products.php" class="btn-primary" style="display:inline-flex;">Khám phá sản phẩm</a>
    </div>
    <div>
      <div class="slideshow" id="slideshow">
        <div class="slide active">
          <div class="slide-placeholder">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.5)" stroke-width="1.5" stroke-linecap="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            <span style="font-size:15px;font-weight:600;color:rgba(255,255,255,0.85);margin-top:12px;">Vùng chè Tân Cương</span>
            <span style="font-size:12px;color:rgba(255,255,255,0.5);margin-top:4px;">Thái Nguyên, Việt Nam</span>
          </div>
        </div>
        <div class="slide">
          <div class="slide-placeholder">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.5)" stroke-width="1.5" stroke-linecap="round"><path d="M12 2a10 10 0 1 0 0 20A10 10 0 0 0 12 2z"/><path d="M12 8v4l3 3"/></svg>
            <span style="font-size:15px;font-weight:600;color:rgba(255,255,255,0.85);margin-top:12px;">Thu hái thủ công</span>
            <span style="font-size:12px;color:rgba(255,255,255,0.5);margin-top:4px;">Từng búp chè tươi ngon nhất</span>
          </div>
        </div>
        <div class="slide">
          <div class="slide-placeholder">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.5)" stroke-width="1.5" stroke-linecap="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <span style="font-size:15px;font-weight:600;color:rgba(255,255,255,0.85);margin-top:12px;">Chế biến đạt chuẩn</span>
            <span style="font-size:12px;color:rgba(255,255,255,0.5);margin-top:4px;">VSATTP — Không hóa chất</span>
          </div>
        </div>
        <div class="slide-dots" id="slideDots"></div>
      </div>
    </div>
  </div>
</section>

<!-- ══ TRUST BAR ══ -->
<div class="trust-bar">
  <div class="trust-inner">

    <div class="trust-item">
      <div class="trust-icon">
        <svg viewBox="0 0 24 24">
          <rect x="1" y="4" width="14" height="11" rx="2"/>
          <path d="M15 8h3.5L22 12v4h-7V8z"/>
          <circle cx="5.5" cy="17.5" r="2"/>
          <circle cx="18.5" cy="17.5" r="2"/>
          <path d="M15 17.5H7.5"/>
        </svg>
      </div>
      <div class="trust-text">
        <div class="trust-title">Giao hàng toàn quốc</div>
        <div class="trust-sub">Miễn phí từ 300.000đ</div>
      </div>
    </div>

    <div class="trust-item">
      <div class="trust-icon">
        <svg viewBox="0 0 24 24">
          <path d="M12 3L4.5 6.5v5C4.5 15.9 7.7 20.3 12 22c4.3-1.7 7.5-6.1 7.5-10.5v-5L12 3z"/>
          <polyline points="9 12 11 14 15 10"/>
        </svg>
      </div>
      <div class="trust-text">
        <div class="trust-title">Hàng chính gốc 100%</div>
        <div class="trust-sub">Cam kết hoàn tiền</div>
      </div>
    </div>

    <div class="trust-item">
      <div class="trust-icon">
        <svg viewBox="0 0 24 24">
          <polyline points="1 4 1 10 7 10"/>
          <path d="M3.51 15a9 9 0 1 0 .49-4.5"/>
        </svg>
      </div>
      <div class="trust-text">
        <div class="trust-title">Đổi trả 30 ngày</div>
        <div class="trust-sub">Không cần lý do</div>
      </div>
    </div>

    <div class="trust-item">
      <div class="trust-icon">
        <svg viewBox="0 0 24 24">
          <path d="M3 18v-6a9 9 0 0 1 18 0v6"/>
          <path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3z"/>
          <path d="M3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/>
        </svg>
      </div>
      <div class="trust-text">
        <div class="trust-title">Hỗ trợ 24/7</div>
        <div class="trust-sub">Tư vấn miễn phí</div>
      </div>
    </div>

  </div>
</div>

<!-- ══ MAIN CONTENT / SẢN PHẨM ══ -->
<div id="products-section">
<div class="main-content" id="products">

  <!-- FLASH SALE -->
  <div class="flash-sale-wrap section-gap">
    <div class="flash-hd">
      <div class="flash-title">
        <h2>⚡ Flash Sale</h2>
        <span class="flash-badge">HOT</span>
      </div>
      <div class="countdown">
        <div class="cd-box" id="cd-h">02</div>
        <span class="cd-sep">:</span>
        <div class="cd-box" id="cd-m">45</div>
        <span class="cd-sep">:</span>
        <div class="cd-box" id="cd-s">30</div>
      </div>
    </div>
    <div class="product-grid g-4">
      <?php if (empty($flashProducts)): ?>
        <p style="color:rgba(255,255,255,0.5);padding:20px 0;">Chưa có sản phẩm Flash Sale.</p>
      <?php else: ?>
      <?php foreach ($flashProducts as $p): ?>
      <a class="pcard" href="product_detail.php?id=<?= $p['id'] ?>" style="text-decoration:none;color:inherit;">
        <button class="pcard-wish" data-action="wishlist" data-product-id="<?= $p['id'] ?>" data-product-name="<?= htmlspecialchars($p['name']) ?>" data-product-price="<?= (int)$p['price'] ?>" data-product-image="images/<?= htmlspecialchars($p['image'] ?? 'logo.png') ?>" data-product-url="product_detail.php?id=<?= $p['id'] ?>" onclick="event.preventDefault();event.stopPropagation()">
          <svg viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
        </button>
        <div class="pcard-img">
          <img src="images/<?= htmlspecialchars($p['image'] ?? 'logo.png') ?>"
               alt="<?= htmlspecialchars($p['name']) ?>"
               onerror="this.onerror=null;this.src='images/logo.png'">
          <span class="pcard-tag tag-sale">Sale</span>
        </div>
        <div class="pcard-body">
          <div class="pname"><?= htmlspecialchars($p['name']) ?></div>
          <div class="price-row">
            <span class="price-new"><?= fmt($p['price']) ?></span>
            <?php if ($p['price_old']): ?>
            <span class="price-old"><?= fmt($p['price_old']) ?></span>
            <span class="price-pct">-<?= round((1 - $p['price'] / max(1,$p['price_old'])) * 100) ?>%</span>
            <?php endif; ?>
          </div>
          <button class="btn-add" data-action="cart" data-product-id="<?= $p['id'] ?>" data-product-name="<?= htmlspecialchars($p['name']) ?>" data-product-price="<?= (int)$p['price'] ?>" data-product-image="images/<?= htmlspecialchars($p['image'] ?? 'logo.png') ?>" data-product-url="product_detail.php?id=<?= $p['id'] ?>" onclick="event.preventDefault();event.stopPropagation()">
            <svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
            Thêm giỏ hàng
          </button>
        </div>
      </a>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- SẢN PHẨM NỔI BẬT -->
  <div class="section-gap">
    <div class="section-hd">
      <div class="section-hd-left">
        <div class="line"></div>
        <div><h2>Sản phẩm nổi bật</h2><div class="sub">Được yêu thích nhất mùa này</div></div>
      </div>
      <a href="products.php?sort=featured" class="btn-more">Xem tất cả <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg></a>
    </div>
    <div class="product-grid g-3">
      <?php if (empty($featured)): ?>
        <p style="color:var(--gray-500);padding:20px 0;">Chưa có sản phẩm nổi bật.</p>
      <?php else: ?>
      <?php foreach ($featured as $p): ?>
      <a class="pcard" href="product_detail.php?id=<?= $p['id'] ?>" style="text-decoration:none;color:inherit;">
        <button class="pcard-wish" data-action="wishlist" data-product-id="<?= $p['id'] ?>" data-product-name="<?= htmlspecialchars($p['name']) ?>" data-product-price="<?= (int)$p['price'] ?>" data-product-image="images/<?= htmlspecialchars($p['image'] ?? 'logo.png') ?>" data-product-url="product_detail.php?id=<?= $p['id'] ?>" onclick="event.preventDefault();event.stopPropagation()">
          <svg viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
        </button>
        <div class="pcard-img">
          <img src="images/<?= htmlspecialchars($p['image'] ?? 'logo.png') ?>"
               alt="<?= htmlspecialchars($p['name']) ?>"
               onerror="this.onerror=null;this.src='images/logo.png'">
          <span class="pcard-tag tag-best">Nổi bật</span>
        </div>
        <div class="pcard-body">
          <div class="pname"><?= htmlspecialchars($p['name']) ?></div>
          <div class="price-row">
            <span class="price-new"><?= fmt($p['price']) ?></span>
            <?php if ($p['price_old']): ?>
            <span class="price-old"><?= fmt($p['price_old']) ?></span>
            <?php endif; ?>
          </div>
          <button class="btn-add" data-action="cart" data-product-id="<?= $p['id'] ?>" data-product-name="<?= htmlspecialchars($p['name']) ?>" data-product-price="<?= (int)$p['price'] ?>" data-product-image="images/<?= htmlspecialchars($p['image'] ?? 'logo.png') ?>" data-product-url="product_detail.php?id=<?= $p['id'] ?>" onclick="event.preventDefault();event.stopPropagation()">
            <svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
            Thêm giỏ hàng
          </button>
        </div>
      </a>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- SẢN PHẨM MỚI -->
  <div class="section-gap">
    <div class="section-hd">
      <div class="section-hd-left">
        <div class="line" style="background:linear-gradient(to bottom,#1d4ed8,#3b82f6)"></div>
        <div><h2>Sản phẩm mới</h2><div class="sub">Vừa cập nhật vào kho</div></div>
      </div>
      <a href="products.php?sort=newest" class="btn-more">Xem tất cả <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg></a>
    </div>
    <div class="product-grid g-4">
      <?php if (empty($newProds)): ?>
        <p style="color:var(--gray-500);padding:20px 0;">Chưa có sản phẩm mới.</p>
      <?php else: ?>
      <?php foreach ($newProds as $p): ?>
      <a class="pcard" href="product_detail.php?id=<?= $p['id'] ?>" style="text-decoration:none;color:inherit;">
        <button class="pcard-wish" data-action="wishlist" data-product-id="<?= $p['id'] ?>" data-product-name="<?= htmlspecialchars($p['name']) ?>" data-product-price="<?= (int)$p['price'] ?>" data-product-image="images/<?= htmlspecialchars($p['image'] ?? 'logo.png') ?>" data-product-url="product_detail.php?id=<?= $p['id'] ?>" onclick="event.preventDefault();event.stopPropagation()">
          <svg viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
        </button>
        <div class="pcard-img">
          <img src="images/<?= htmlspecialchars($p['image'] ?? 'logo.png') ?>"
               alt="<?= htmlspecialchars($p['name']) ?>"
               onerror="this.onerror=null;this.src='images/logo.png'">
          <span class="pcard-tag tag-new">Mới</span>
        </div>
        <div class="pcard-body">
          <div class="pname"><?= htmlspecialchars($p['name']) ?></div>
          <div class="price-row">
            <span class="price-new"><?= fmt($p['price']) ?></span>
            <?php if ($p['price_old']): ?>
            <span class="price-old"><?= fmt($p['price_old']) ?></span>
            <?php endif; ?>
          </div>
          <button class="btn-add" data-action="cart" data-product-id="<?= $p['id'] ?>" data-product-name="<?= htmlspecialchars($p['name']) ?>" data-product-price="<?= (int)$p['price'] ?>" data-product-image="images/<?= htmlspecialchars($p['image'] ?? 'logo.png') ?>" data-product-url="product_detail.php?id=<?= $p['id'] ?>" onclick="event.preventDefault();event.stopPropagation()">
            <svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
            Thêm giỏ hàng
          </button>
        </div>
      </a>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /main-content -->
</div><!-- /products-section -->

<!-- ══ FOOTER / LIÊN HỆ ══ -->
<footer id="contact">
  <div class="footer-trust">
    <div class="footer-trust-inner">
      <div class="ft-item">
        <div class="ft-icon-wrap"><svg viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v3h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></div>
        <div class="ft-title">Giao nhanh 2H</div>
        <div class="ft-sub">Nội thành TP.HCM & HN</div>
      </div>
      <div class="ft-item">
        <div class="ft-icon-wrap"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div>
        <div class="ft-title">Hàng chính gốc</div>
        <div class="ft-sub">Cam kết hoàn 100%</div>
      </div>
      <div class="ft-item">
        <div class="ft-icon-wrap"><svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.51"/></svg></div>
        <div class="ft-title">Đổi trả 30 ngày</div>
        <div class="ft-sub">Miễn phí, không điều kiện</div>
      </div>
      <div class="ft-item">
        <div class="ft-icon-wrap"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
        <div class="ft-title">Thương hiệu uy tín</div>
        <div class="ft-sub">10+ năm kinh nghiệm</div>
      </div>
    </div>
  </div>

  <div class="footer-main">
    <div class="footer-main-inner">
      <div class="footer-brand">
        <img src="images/logo.png" alt="Logo" onerror="this.style.display='none'">
        <p>Mộc Trà Thái Nguyên — thương hiệu trà uy tín từ vùng đất Tân Cương, mang hương vị thiên nhiên thuần khiết đến mọi gia đình Việt.</p>
        <div class="footer-hotline">1800 xxxx</div>
        <div class="footer-hotline-lbl">Miễn phí, 08:00 – 22:00 kể cả T7, CN</div>
        <div class="social-links">
          <a href="#" class="social-link"><svg viewBox="0 0 24 24"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg></a>
          <a href="#" class="social-link"><svg viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="20" rx="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg></a>
          <a href="#" class="social-link"><svg viewBox="0 0 24 24"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.95-1.96C18.88 4 12 4 12 4s-6.88 0-8.59.46a2.78 2.78 0 0 0-1.95 1.96A29 29 0 0 0 1 12a29 29 0 0 0 .46 5.58A2.78 2.78 0 0 0 3.41 19.5C5.12 20 12 20 12 20s6.88 0 8.59-.5a2.78 2.78 0 0 0 1.95-1.95A29 29 0 0 0 23 12a29 29 0 0 0-.46-5.58z"/><polygon points="9.75 15.02 15.5 12 9.75 8.98 9.75 15.02"/></svg></a>
        </div>
      </div>

      <div class="footer-col">
        <h4>Hỗ trợ khách hàng</h4>
        <ul>
          <li><a href="support.php?topic=faq">Câu hỏi thường gặp</a></li>
          <li><a href="support.php?topic=order">Hướng dẫn đặt hàng</a></li>
          <li><a href="support.php?topic=payment">Phương thức thanh toán</a></li>
          <li><a href="support.php?topic=shipping">Phương thức vận chuyển</a></li>
          <li><a href="support.php?topic=returns">Chính sách đổi trả</a></li>
          <li><a href="contact.php">Gửi yêu cầu hỗ trợ</a></li>
        </ul>
      </div>

      <div class="footer-col">
        <h4>Về Mộc Trà</h4>
        <ul>
          <li><a href="#about" onclick="smoothTo('about',event)">Giới thiệu</a></li>
          <li><a href="contact.php">Liên hệ</a></li>
        </ul>
      </div>

      <div class="footer-newsletter">
        <h4>Cập nhật khuyến mãi</h4>
        <p>Đăng ký để nhận ưu đãi độc quyền và thông tin sản phẩm mới nhất.</p>
        <div class="newsletter-form">
          <input type="email" placeholder="Email của bạn">
          <button type="button">Đăng ký</button>
        </div>
        <div style="margin-top:18px;">
          <div style="font-size:12px;color:rgba(255,255,255,0.4);margin-bottom:10px;font-weight:600;text-transform:uppercase;letter-spacing:1px;">Thanh toán</div>
          <div class="payment-icons">
            <span class="pay-icon">VISA</span>
            <span class="pay-icon">ATM</span>
            <span class="pay-icon">MOMO</span>
            <span class="pay-icon">COD</span>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="footer-bottom">
    <div class="footer-bottom-inner">
      <p>© 2025 Mộc Trà Thái Nguyên. Bảo lưu mọi quyền.</p>
      <p style="color:rgba(255,255,255,0.25);">Được xây dựng với ❤ bởi Nhóm 3</p>
    </div>
  </div>
</footer>

<!-- ── Notification Modal ── -->
<?php if ($isLoggedIn): ?>
<div class="notif-modal-overlay" id="notifModalOverlay">
  <div class="notif-modal" id="notifModal">
    <div class="notif-modal-hd">
      <h3 id="notifModalTitle">Chi tiết thông báo</h3>
      <button class="notif-modal-close" id="notifModalClose" aria-label="Đóng">&times;</button>
    </div>
    <div class="notif-modal-body" id="notifModalBody"></div>
    <div class="notif-modal-reply">
      <textarea id="notifReplyText" placeholder="Nhập phản hồi của bạn..." rows="3"></textarea>
      <button class="btn-send-reply" id="notifSendReply">Gửi phản hồi</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
function smoothTo(id, e) {
  if (e) e.preventDefault();
  if (id === 'top') { window.scrollTo({ top: 0, behavior: 'smooth' }); return; }
  var el = document.getElementById(id);
  if (el) {
    var top = el.getBoundingClientRect().top + window.scrollY - 90;
    window.scrollTo({ top: top, behavior: 'smooth' });
  }
}

(function () {
  var catBtn  = document.getElementById('catBtn');
  var catMenu = document.getElementById('catMenu');
  var userBtn = document.getElementById('userBtn');
  var userMenu= document.getElementById('userMenu');

  function closeAll() {
    catMenu  && catMenu.classList.remove('open');
    userMenu && userMenu.classList.remove('open');
    catBtn   && catBtn.classList.remove('open');
    userBtn  && userBtn.classList.remove('open');
  }

  catBtn && catBtn.addEventListener('click', function (e) {
    e.stopPropagation();
    var isOpen = catMenu.classList.contains('open'); closeAll();
    if (!isOpen) { catMenu.classList.add('open'); catBtn.classList.add('open'); }
  });

  userBtn && userBtn.addEventListener('click', function (e) {
    e.stopPropagation();
    var isOpen = userMenu.classList.contains('open'); closeAll();
    if (!isOpen) { userMenu.classList.add('open'); userBtn.classList.add('open'); }
  });

  document.addEventListener('click', closeAll);
  catMenu  && catMenu.addEventListener('click',  function (e) { e.stopPropagation(); });
  userMenu && userMenu.addEventListener('click', function (e) { e.stopPropagation(); });
})();

window.addEventListener('scroll', function () {
  var nb = document.getElementById('navbar');
  if (nb) nb.style.boxShadow = window.scrollY > 30
    ? '0 4px 20px rgba(0,0,0,0.14)'
    : '0 2px 12px rgba(0,0,0,0.06)';
});

(function () {
  var SALE_KEY = 'moctra_flash_end';
  var DURATION = (2 * 3600 + 44 * 60 + 30) * 1000;
  var stored = parseInt(localStorage.getItem(SALE_KEY), 10);
  var end = (stored && stored > Date.now()) ? stored : Date.now() + DURATION;
  localStorage.setItem(SALE_KEY, end);
  function tick() {
    var diff = Math.max(0, end - Date.now());
    var h = Math.floor(diff / 3600000);
    var m = Math.floor((diff % 3600000) / 60000);
    var s = Math.floor((diff % 60000) / 1000);
    function pad(n) { return String(n).padStart(2, '0'); }
    var eh = document.getElementById('cd-h');
    var em = document.getElementById('cd-m');
    var es = document.getElementById('cd-s');
    if (eh) eh.textContent = pad(h);
    if (em) em.textContent = pad(m);
    if (es) es.textContent = pad(s);
    if (diff > 0) setTimeout(tick, 1000);
  }
  tick();
})();

(function () {
  var slides   = document.querySelectorAll('.slide');
  var dotsWrap = document.getElementById('slideDots');
  if (!slides.length || !dotsWrap) return;
  var current = 0;
  slides.forEach(function (_, i) {
    var btn = document.createElement('button');
    btn.className = 'slide-dot' + (i === 0 ? ' active' : '');
    btn.addEventListener('click', function () { go(i); });
    dotsWrap.appendChild(btn);
  });
  function go(idx) {
    slides[current].classList.remove('active');
    dotsWrap.children[current].classList.remove('active');
    current = idx;
    slides[current].classList.add('active');
    dotsWrap.children[current].classList.add('active');
  }
  setInterval(function () { go((current + 1) % slides.length); }, 3500);
})();

</script>
<script src="js/moctra-functions.js"></script>
<script src="js/search-suggestion.js"></script>

<?php if ($isLoggedIn): ?>
<script>
(function () {
  var CSRF       = <?= json_encode($csrfToken) ?>;
  var notifWrap  = document.getElementById('notifWrap');
  var notifBtn   = document.getElementById('notifBtn');
  var notifBadge = document.getElementById('notifBadge');
  var notifDropdown = document.getElementById('notifDropdown');
  var notifList  = document.getElementById('notifList');
  var notifMarkAll  = document.getElementById('notifMarkAll');
  var overlay    = document.getElementById('notifModalOverlay');
  var modalTitle = document.getElementById('notifModalTitle');
  var modalBody  = document.getElementById('notifModalBody');
  var replyText  = document.getElementById('notifReplyText');
  var sendReply  = document.getElementById('notifSendReply');

  var currentNotifId = 0;
  var notifications  = [];

  function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
  }

  function updateBadge() {
    var unread = notifications.filter(function (x) { return !x.is_read; }).length;
    if (unread > 0) {
      notifBadge.textContent = unread > 9 ? '9+' : unread;
      notifBadge.style.display = 'flex';
    } else {
      notifBadge.style.display = 'none';
    }
  }

  function renderList() {
    if (!notifications.length) {
      notifList.innerHTML = '<div class="notif-empty">Bạn chưa có thông báo nào.</div>';
      return;
    }
    var html = '';
    notifications.forEach(function (n) {
      var icon  = n.type === 'review_reply'   ? '⭐'
                : n.type === 'voucher_gifted' ? '🎁' : '✉️';
      var title = n.type === 'review_reply'   ? 'Phản hồi đánh giá: '  + (n.context_label || '')
                : n.type === 'voucher_gifted' ? 'Voucher ưu đãi dành cho bạn!'
                :                               'Phản hồi liên hệ: '   + (n.context_label || '');
      html += '<div class="notif-item' + (n.is_read ? '' : ' unread') + '" data-id="' + n.id + '">' +
        '<div class="notif-icon">' + icon + '</div>' +
        '<div class="notif-item-body">' +
          '<div class="notif-item-title">' + escHtml(title) + '</div>' +
          '<div class="notif-item-preview">' + escHtml(n.message) + '</div>' +
          '<div class="notif-item-time">'   + escHtml(n.time_ago) + '</div>' +
        '</div>' +
        (n.is_read ? '' : '<div class="notif-unread-dot"></div>') +
      '</div>';
    });
    notifList.innerHTML = html;
    notifList.querySelectorAll('.notif-item').forEach(function (el) {
      el.addEventListener('click', function () {
        openModal(parseInt(el.dataset.id, 10));
      });
    });
  }

  function loadNotifications() {
    fetch('api/notifications.php')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.success) return;
        notifications = d.notifications;
        updateBadge();
        renderList();
      })
      .catch(function () {});
  }

  function markRead(id) {
    var fd = new FormData();
    fd.append('action', 'mark_read');
    fd.append('id', id);
    fd.append('csrf_token', CSRF);
    fetch('api/notifications.php', { method: 'POST', body: fd })
      .then(function () {
        var n = notifications.find(function (x) { return x.id === id; });
        if (n) n.is_read = 1;
        updateBadge();
        renderList();
      })
      .catch(function () {});
  }

  function openModal(id) {
    var n = notifications.find(function (x) { return x.id === id; });
    if (!n) return;
    currentNotifId = id;

    if (!n.is_read) markRead(id);

    modalTitle.textContent = n.type === 'review_reply'   ? 'Phản hồi về đánh giá sản phẩm'
                           : n.type === 'voucher_gifted' ? 'Voucher ưu đãi dành cho bạn!'
                           :                               'Phản hồi từ đội ngũ hỗ trợ';

    var bHtml = '';
    if (n.context_label) {
      bHtml += '<div class="notif-modal-label">' +
        (n.type === 'review_reply' ? 'Sản phẩm' : 'Chủ đề liên hệ') + '</div>' +
        '<div class="notif-modal-text" style="font-weight:600;background:#f3f4f6;">' +
          escHtml(n.context_label) + '</div>';
    }
    if (n.original_message) {
      bHtml += '<div class="notif-modal-label">Nội dung bạn đã gửi</div>' +
        '<div class="notif-modal-text">' + escHtml(n.original_message) + '</div>';
    }
    if (n.admin_reply) {
      bHtml += '<div class="notif-modal-label">Phản hồi từ admin</div>' +
        '<div class="notif-admin-reply">' + escHtml(n.admin_reply) + '</div>';
    }
    if (!bHtml) {
      bHtml = '<div class="notif-modal-text">' + escHtml(n.message) + '</div>';
    }
    modalBody.innerHTML = bHtml;

    replyText.value = '';
    sendReply.disabled = false;
    sendReply.textContent = 'Gửi phản hồi';

    var replySection = document.querySelector('.notif-modal-reply');
    if (replySection) replySection.style.display = n.type === 'voucher_gifted' ? 'none' : '';

    notifDropdown.classList.remove('open');
    overlay.classList.add('open');
  }

  /* Bell toggle */
  notifBtn.addEventListener('click', function (e) {
    e.stopPropagation();
    notifDropdown.classList.toggle('open');
  });
  notifDropdown.addEventListener('click', function (e) { e.stopPropagation(); });
  document.addEventListener('click', function (e) {
    if (!notifWrap.contains(e.target)) notifDropdown.classList.remove('open');
  });

  /* Mark all read */
  notifMarkAll.addEventListener('click', function () {
    var fd = new FormData();
    fd.append('action', 'mark_all_read');
    fd.append('csrf_token', CSRF);
    fetch('api/notifications.php', { method: 'POST', body: fd })
      .then(function () {
        notifications.forEach(function (n) { n.is_read = 1; });
        updateBadge();
        renderList();
      })
      .catch(function () {});
  });

  /* Modal close */
  document.getElementById('notifModalClose').addEventListener('click', function () {
    overlay.classList.remove('open');
  });
  overlay.addEventListener('click', function (e) {
    if (e.target === overlay) overlay.classList.remove('open');
  });

  /* Send reply */
  sendReply.addEventListener('click', function () {
    var txt = replyText.value.trim();
    if (!txt) { replyText.focus(); return; }
    sendReply.disabled = true;
    sendReply.textContent = 'Đang gửi...';

    var fd = new FormData();
    fd.append('notification_id', currentNotifId);
    fd.append('reply_text', txt);
    fd.append('csrf_token', CSRF);
    fetch('api/notification_reply.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.success) {
          sendReply.textContent = '✓ Đã gửi!';
          replyText.value = '';
          setTimeout(function () { overlay.classList.remove('open'); }, 1400);
        } else {
          sendReply.disabled = false;
          sendReply.textContent = 'Gửi phản hồi';
          if (typeof showToast === 'function') showToast(d.message || 'Lỗi khi gửi. Vui lòng thử lại.');
        }
      })
      .catch(function () {
        sendReply.disabled = false;
        sendReply.textContent = 'Gửi phản hồi';
        if (typeof showToast === 'function') showToast('Lỗi kết nối. Vui lòng thử lại.');
      });
  });

  loadNotifications();

  /* ── SSE realtime (fallback to polling if EventSource unavailable) ── */
  var _sseLastCount = -1;
  var _sse          = null;

  function startSSE() {
    if (typeof EventSource === 'undefined') {
      setInterval(loadNotifications, 60000);
      return;
    }
    _sse = new EventSource('api/sse_notifications.php');
    _sse.onmessage = function (e) {
      try {
        var d = JSON.parse(e.data);
        if (_sseLastCount !== d.unread_count) {
          _sseLastCount = d.unread_count;
          loadNotifications();
        }
      } catch (_) {}
    };
    _sse.onerror = function () {
      _sse.close();
      _sse = null;
      setTimeout(startSSE, 30000);
    };
  }

  // Pause SSE when tab is hidden to free Apache connections
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      if (_sse) { _sse.close(); _sse = null; }
    } else {
      _sseLastCount = -1;
      loadNotifications();
      startSSE();
    }
  });

  startSSE();
})();
</script>
<?php endif; ?>
</body>
</html>


