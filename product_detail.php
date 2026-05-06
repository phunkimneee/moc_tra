<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();
require_once 'config/db.php';

$isLoggedIn  = isset($_SESSION['user_id']); // Cả customer và admin đều có thể đăng nhập
$isAdmin     = isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'admin';
$isCustomer  = isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'customer';
$username    = $isLoggedIn ? htmlspecialchars($_SESSION['username'] ?? '') : '';
$userInitial = $isLoggedIn && $username ? strtoupper(substr($username, 0, 1)) : '';

/* ── Lấy danh mục cho navbar ── */
$cats = $conn->query("SELECT * FROM categories ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);

/* ── Lấy sản phẩm theo ID ── */
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header("Location: products.php"); exit(); }

$stmt = $conn->prepare(
    "SELECT p.*, c.name AS cat_name, c.slug AS cat_slug
     FROM products p
     JOIN categories c ON c.id = p.category_id
     WHERE p.id = ? LIMIT 1"
);
$stmt->bind_param("i", $id);
$stmt->execute();
$p = $stmt->get_result()->fetch_assoc();
if (!$p) { header("Location: products.php"); exit(); }

/* ── Sản phẩm tương tự (cùng danh mục, khác id) ── */
$st2 = $conn->prepare(
    "SELECT p.*, c.name AS cat_name FROM products p
     JOIN categories c ON c.id = p.category_id
     WHERE p.category_id = ? AND p.id != ?
     ORDER BY p.is_featured DESC, p.id DESC LIMIT 4"
);
$st2->bind_param("ii", $p['category_id'], $id);
$st2->execute();
$related = $st2->get_result()->fetch_all(MYSQLI_ASSOC);

/* ── Reviews ── */
$conn->query("CREATE TABLE IF NOT EXISTS product_reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL DEFAULT 5,
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_product (user_id, product_id),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$stRev = $conn->prepare(
    "SELECT r.rating, r.comment, r.created_at, r.admin_reply, r.replied_at, u.username
     FROM product_reviews r JOIN users u ON u.id = r.user_id
     WHERE r.product_id = ? ORDER BY r.created_at DESC LIMIT 20"
);
$stRev->bind_param('i', $id);
$stRev->execute();
$reviews     = $stRev->get_result()->fetch_all(MYSQLI_ASSOC);
$reviewCount = count($reviews);
$avgRating   = $reviewCount > 0 ? array_sum(array_column($reviews, 'rating')) / $reviewCount : 0;

$userHasReview = false;
$canReview     = false;
if ($isLoggedIn) {
    $uid = (int)$_SESSION['user_id'];
    // Kiểm tra xem đã đánh giá chưa
    $stHas = $conn->prepare("SELECT 1 FROM product_reviews WHERE product_id=? AND user_id=? LIMIT 1");
    $stHas->bind_param('ii', $id, $uid);
    $stHas->execute();
    $userHasReview = (bool)$stHas->get_result()->num_rows;

    // Kiểm tra xem đã mua và nhận hàng chưa
    // EXCEPTION: Admin can review for testing
    if ($isAdmin) {
        $canReview = true;
    } else {
        $stBought = $conn->prepare(
            "SELECT 1 FROM orders o
             JOIN order_items oi ON o.id = oi.order_id
             WHERE o.user_id = ? AND oi.product_id = ? AND o.status = 'delivered'
             LIMIT 1"
        );
        $stBought->bind_param('ii', $uid, $id);
        $stBought->execute();
        $canReview = (bool)$stBought->get_result()->num_rows;
    }
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function fmt(int $n): string {
    return number_format($n, 0, ',', '.') . 'đ';
}

$pct = $p['price_old'] ? '-' . round((1 - $p['price'] / ($p['price_old'] ?: 1)) * 100) . '%' : '';
$type_labels = ['la'=>'Trà lá','tui_loc'=>'Túi lọc','bot'=>'Bột','hop_qua'=>'Hộp quà'];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php if ($isLoggedIn): ?><meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"><?php endif; ?>
  <title><?= htmlspecialchars($p['name']) ?> — Mộc Trà Thái Nguyên</title>
  <!-- Font Awesome 6 -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Be+Vietnam+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/index.css">
  <link rel="stylesheet" href="css/components.css">
  <link rel="stylesheet" href="css/products.css">
<style>
/* Global Icon Styles */
i.fa-solid, i.fa-regular, i.fa-brands {
    color: #2d5a27;
    transition: color 0.3s ease, transform 0.3s ease;
    margin-right: 8px;
}
.text-danger { color: #dc3545; font-weight: bold; margin-left: 2px; }

/* ══ PRODUCT DETAIL STYLES ══ */
body { background: #f9fafb; }

.detail-wrap {
  max-width: 1200px;
  margin: 32px auto 60px;
  padding: 0 36px;
}

/* ── MAIN SECTION ── */
.detail-main {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 56px;
  background: #fff;
  border-radius: 16px;
  padding: 40px;
  box-shadow: 0 4px 24px rgba(0,0,0,0.08);
  margin-bottom: 40px;
}

/* ── GALLERY ── */
.gallery { display: flex; flex-direction: column; gap: 14px; }
.gallery-main {
  background: #f0fdf4;
  border-radius: 12px;
  overflow: hidden;
  aspect-ratio: 1/1;
  display: flex; align-items: center; justify-content: center;
  position: relative;
}
.gallery-main img {
  width: 100%; height: 100%;
  object-fit: cover;
  transition: transform 0.4s ease;
}
.gallery-main:hover img { transform: scale(1.04); }

.gallery-thumbs {
  display: flex; gap: 10px;
}
.thumb {
  width: 72px; height: 72px;
  border-radius: 8px;
  overflow: hidden;
  border: 2px solid transparent;
  cursor: pointer;
  background: #f0fdf4;
  flex-shrink: 0;
  transition: border-color 0.2s;
}
.thumb.active { border-color: #166534; }
.thumb img { width: 100%; height: 100%; object-fit: cover; }

/* ── INFO PANEL ── */
.detail-cat {
  font-size: 13px; font-weight: 600;
  color: #166534; text-decoration: none;
  display: inline-flex; align-items: center; gap: 5px;
  margin-bottom: 10px;
  transition: opacity 0.2s;
}
.detail-cat:hover { opacity: 0.75; }

.detail-name {
  font-family: 'Playfair Display', serif;
  font-size: 28px; font-weight: 700;
  color: #111827; line-height: 1.3;
  margin-bottom: 14px;
}

/* Stars */
.detail-stars {
  display: flex; align-items: center; gap: 8px;
  margin-bottom: 18px;
}
.stars { display: flex; gap: 2px; }
.star { width: 18px; height: 18px; fill: #f59e0b; }
.star-empty { fill: #e5e7eb; }
.review-count { font-size: 13px; color: #6b7280; }

/* Price */
.detail-price-row {
  display: flex; align-items: center; gap: 12px;
  margin-bottom: 20px;
  padding-bottom: 20px;
  border-bottom: 1px solid #f3f4f6;
}
.detail-price { font-size: 30px; font-weight: 700; color: #dc2626; }
.detail-price-old { font-size: 16px; color: #9ca3af; text-decoration: line-through; }
.detail-discount {
  background: #fef2f2; color: #dc2626;
  font-size: 13px; font-weight: 700;
  padding: 3px 10px; border-radius: 6px;
}

/* Meta info */
.detail-meta {
  display: grid; grid-template-columns: 1fr 1fr;
  gap: 10px; margin-bottom: 24px;
}
.meta-item {
  display: flex; flex-direction: column; gap: 3px;
  padding: 12px 14px;
  background: #f9fafb;
  border-radius: 8px;
  border: 1px solid #f3f4f6;
}
.meta-label { font-size: 11px; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px; }
.meta-value { font-size: 14px; font-weight: 600; color: #374151; }

/* Quantity */
.qty-section { margin-bottom: 20px; }
.qty-label { font-size: 13px; font-weight: 700; color: #374151; margin-bottom: 8px; }
.qty-wrap {
  display: inline-flex; align-items: center;
  border: 1.5px solid #e5e7eb; border-radius: 8px;
  overflow: hidden;
}
.qty-btn {
  width: 40px; height: 40px;
  border: none; background: #f9fafb;
  font-size: 18px; cursor: pointer;
  color: #374151; transition: background 0.2s;
  display: flex; align-items: center; justify-content: center;
}
.qty-btn:hover { background: #f0fdf4; color: #166534; }
.qty-input {
  width: 52px; height: 40px;
  border: none; border-left: 1.5px solid #e5e7eb; border-right: 1.5px solid #e5e7eb;
  text-align: center; font-size: 15px; font-weight: 600;
  font-family: inherit; outline: none; color: #111827;
}

/* Action buttons */
.detail-actions { display: flex; flex-direction: column; gap: 10px; margin-bottom: 24px; }
.btn-buy {
  width: 100%; padding: 14px;
  background: linear-gradient(135deg, #166534, #0f5132);
  color: white; border: none; border-radius: 8px;
  font-size: 15px; font-weight: 700; font-family: inherit;
  cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;
  display: flex; align-items: center; justify-content: center; gap: 8px;
  box-shadow: 0 4px 14px rgba(22,101,52,0.3);
}
.btn-buy:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(22,101,52,0.4); }
.btn-buy svg { width: 18px; height: 18px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; }

.btn-wish {
  width: 100%; padding: 13px;
  background: #fff; color: #374151;
  border: 1.5px solid #e5e7eb; border-radius: 8px;
  font-size: 14px; font-weight: 600; font-family: inherit;
  cursor: pointer; transition: all 0.2s;
  display: flex; align-items: center; justify-content: center; gap: 8px;
}
.btn-wish:hover { border-color: #dc2626; color: #dc2626; background: #fef2f2; }
.btn-wish svg { width: 17px; height: 17px; stroke: currentColor; fill: none; stroke-width: 2; }

/* Policies */
.detail-policies {
  display: grid; grid-template-columns: 1fr 1fr;
  gap: 8px;
}
.policy-item {
  display: flex; align-items: center; gap: 8px;
  font-size: 12.5px; color: #6b7280;
  padding: 8px 10px;
  background: #f9fafb; border-radius: 6px;
}
.policy-item svg { width: 16px; height: 16px; stroke: #166534; fill: none; stroke-width: 2; flex-shrink: 0; }

/* ── DESCRIPTION TAB ── */
.detail-tabs {
  background: #fff;
  border-radius: 16px;
  padding: 36px 40px;
  box-shadow: 0 4px 24px rgba(0,0,0,0.08);
  margin-bottom: 40px;
}
.tab-nav {
  display: flex; gap: 0;
  border-bottom: 2px solid #f3f4f6;
  margin-bottom: 28px;
}
.tab-btn {
  padding: 10px 24px;
  font-size: 14px; font-weight: 600;
  color: #6b7280; border: none; background: none;
  cursor: pointer; font-family: inherit;
  border-bottom: 2px solid transparent;
  margin-bottom: -2px;
  transition: color 0.2s, border-color 0.2s;
}
.tab-btn.active { color: #166534; border-color: #166534; }
.tab-pane { display: none; }
.tab-pane.active { display: block; }

.tab-pane p { font-size: 15px; color: #374151; line-height: 1.8; margin-bottom: 14px; }
.tab-pane ul { padding-left: 20px; display: flex; flex-direction: column; gap: 8px; }
.tab-pane ul li { font-size: 14.5px; color: #374151; line-height: 1.6; }

.spec-table { width: 100%; border-collapse: collapse; }
.spec-table tr:nth-child(odd) td { background: #f9fafb; }
.spec-table td { padding: 11px 16px; font-size: 14px; border: 1px solid #f3f4f6; }
.spec-table td:first-child { font-weight: 600; color: #6b7280; width: 35%; }
.spec-table td:last-child { color: #111827; }

/* ── RELATED PRODUCTS ── */
.related-section {
  margin-bottom: 20px;
}
.related-title {
  font-family: 'Playfair Display', serif;
  font-size: 22px; font-weight: 700; color: #111827;
  margin-bottom: 20px;
  display: flex; align-items: center; gap: 12px;
}
.related-title::before {
  content: '';
  width: 4px; height: 24px;
  background: linear-gradient(to bottom, #166534, #16a34a);
  border-radius: 99px;
  flex-shrink: 0;
}
.related-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
}

/* ── FOOTER ── */
.site-footer {
  background: #0f5132;
}
.footer-trust-bar {
  border-bottom: 1px solid rgba(255,255,255,0.08);
  padding: 24px 0;
}
.footer-trust-inner {
  max-width: 1200px; margin: 0 auto; padding: 0 36px;
  display: grid; grid-template-columns: repeat(4,1fr); gap: 14px;
}
.ft-item {
  display: flex; flex-direction: column; align-items: center; gap: 8px;
  text-align: center; padding: 14px;
  border-radius: 10px;
  border: 1px solid rgba(255,255,255,0.08);
  background: rgba(255,255,255,0.04);
}
.ft-icon { width: 44px; height: 44px; background: rgba(255,255,255,0.10); border-radius: 50%; display: flex; align-items: center; justify-content: center; }
.ft-icon svg { width: 22px; height: 22px; stroke: #fde68a; fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
.ft-title { font-size: 13px; font-weight: 700; color: #fff; }
.ft-sub { font-size: 11.5px; color: rgba(255,255,255,0.5); }

.footer-bottom-bar {
  padding: 18px 0;
}
.footer-bottom-inner {
  max-width: 1200px; margin: 0 auto; padding: 0 36px;
  display: flex; align-items: center; justify-content: space-between;
  font-size: 13px; color: rgba(255,255,255,0.35);
}
.footer-bottom-inner a { color: rgba(255,255,255,0.55); text-decoration: none; }
.footer-bottom-inner a:hover { color: #fde68a; }

/* ── REVIEWS ── */
.review-success {
  display: flex; align-items: center; gap: 10px;
  background: var(--green-50); color: var(--green-700);
  border: 1px solid var(--green-100); border-radius: 10px;
  padding: 12px 18px; margin-bottom: 20px; font-weight: 600;
}
.review-success svg { width: 18px; height: 18px; stroke: var(--green-700); fill: none; stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round; }
.review-summary {
  display: flex; align-items: center; gap: 20px;
  background: var(--green-50); border-radius: 12px; padding: 18px 22px;
  margin-bottom: 24px; border: 1px solid var(--green-100);
}
.review-avg { font-size: 44px; font-weight: 700; color: var(--green-700); line-height: 1; }
.review-total { font-size: 13px; color: var(--gray-500); margin-top: 4px; }
.review-empty { color: var(--gray-500); font-size: 14px; padding: 16px 0; }
.review-list { display: flex; flex-direction: column; gap: 16px; margin-bottom: 32px; }
.review-item {
  background: var(--white); border: 1px solid var(--gray-100);
  border-radius: 12px; padding: 18px 20px;
}
.review-header { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
.reviewer-avatar {
  width: 38px; height: 38px; border-radius: 50%;
  background: var(--green-700); color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 15px; flex-shrink: 0;
}
.reviewer-name { font-weight: 600; font-size: 14px; color: var(--gray-900); }
.reviewer-date { font-size: 12px; color: var(--gray-400); margin-top: 2px; }
.review-comment { font-size: 14px; color: var(--gray-700); line-height: 1.6; }
.star-sm { width: 14px; height: 14px; }
.review-form-wrap {
  background: var(--white); border: 1px solid var(--gray-200);
  border-radius: 14px; padding: 24px 26px; margin-top: 8px;
}
.review-form-wrap h3 { font-size: 16px; font-weight: 700; color: var(--gray-900); margin-bottom: 16px; }
.star-picker { display: flex; gap: 6px; margin-bottom: 14px; flex-direction: row; }
.star-pick-label { cursor: pointer; order: 1; }
.star-pick { width: 28px; height: 28px; fill: #d1d5db; stroke: #d1d5db; stroke-width: 1; transition: fill 0.15s; }

/* Khi hover hoặc check, tô màu vàng cho chính nó và các sao bên trái */
.star-picker:hover .star-pick { fill: #f59e0b; stroke: #f59e0b; }
.star-pick-label:hover ~ .star-pick-label .star-pick { fill: #d1d5db; stroke: #d1d5db; }

.star-pick-label input:checked ~ .star-pick { fill: #d1d5db; stroke: #d1d5db; }
.star-pick-label input:checked + .star-pick,
.star-pick-label:has(input:checked) ~ .star-pick-label .star-pick { 
  fill: #f59e0b !important; stroke: #f59e0b !important; 
}

/* ── REVIEWS Star Picker Fix ── */
.star-picker {
  display: flex;
  gap: 4px;
  flex-direction: row-reverse;
  justify-content: flex-end;
  margin-bottom: 16px;
}
.star-pick-label {
  cursor: pointer;
  line-height: 1;
}
.star-pick-label input {
  position: absolute;
  opacity: 0;
  width: 0; height: 0;
}
.star-pick {
  width: 32px;
  height: 32px;
  fill: #d1d5db;
  stroke: #d1d5db;
  stroke-width: 1;
  transition: all 0.2s ease;
}
/* Hover & Checked Logic */
.star-pick-label:hover .star-pick,
.star-pick-label:hover ~ .star-pick-label .star-pick,
.star-pick-label input:checked ~ .star-pick,
.star-pick-label input:checked + .star-pick {
  fill: #f59e0b !important;
  stroke: #f59e0b !important;
  transform: scale(1.1);
}
.star-pick-label input:checked + .star-pick {
  filter: drop-shadow(0 0 4px rgba(245,158,11,0.4));
}
.review-form-wrap textarea {
  width: 100%; padding: 10px 14px; border: 1.5px solid var(--gray-200);
  border-radius: 8px; font-family: inherit; font-size: 14px;
  resize: vertical; min-height: 90px; margin-bottom: 14px;
  outline: none; transition: border-color 0.2s;
}
.review-form-wrap textarea:focus { border-color: var(--green-700); }
.btn-submit-review {
  background: var(--green-700); color: #fff; border: none;
  padding: 10px 24px; border-radius: 8px; font-size: 14px;
  font-weight: 600; cursor: pointer; font-family: inherit;
  transition: background 0.18s;
}
.btn-submit-review:hover { background: var(--green-800); }
.review-already, .review-login-hint { color: var(--gray-500); font-size: 14px; margin-top: 12px; }
.review-login-hint a { color: var(--green-700); font-weight: 600; text-decoration: none; }
</style>
</head>
<body>

<!-- ══ NAVBAR ══ -->
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
            <?php foreach($cats as $c): ?>
            <a href="products.php?category=<?= $c['slug'] ?>">
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
      <a href="products.php" class="nav-link active">Sản phẩm</a>
      <a href="index.php#about" class="nav-link">Giới thiệu</a>
      <a href="reviews.php" class="nav-link">Đánh giá</a>
      <a href="contact.php" class="nav-link">Liên hệ</a>
    </div>
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
      <?php if ($isLoggedIn): ?>
      <a href="wishlist.php" class="nav-icon-btn" title="Yêu thích" data-nav-wishlist>
        <svg viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
      </a>
      <?php endif; ?>
      <a href="cart.php" class="nav-icon-btn" title="Giỏ hàng" data-nav-cart>
        <svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
        <span class="badge" data-cart-badge>0</span>
      </a>
      <?php if ($isLoggedIn): ?>
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
      <a href="admin/dashboard.php" class="nav-icon-btn" title="Về trang quản trị"
         style="background:#fef3c7;color:#92400e;border-radius:6px;padding:6px 12px;font-size:13px;font-weight:700;white-space:nowrap;text-decoration:none;">
        ⚙️ Admin
      </a>
      <?php else: ?>
      <a href="login.php" class="btn-login-nav">Đăng nhập</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<!-- ══ BREADCRUMB ══ -->
<div class="breadcrumb-bar">
  <div class="breadcrumb-inner">
    <a href="index.php">Trang chủ</a>
    <span class="sep">›</span>
    <a href="products.php">Sản phẩm</a>
    <span class="sep">›</span>
    <a href="products.php?category=<?= htmlspecialchars($p['cat_slug']) ?>"><?= htmlspecialchars($p['cat_name']) ?></a>
    <span class="sep">›</span>
    <span class="current"><?= htmlspecialchars($p['name']) ?></span>
  </div>
</div>

<!-- ══ DETAIL WRAP ══ -->
<div class="detail-wrap">

  <!-- MAIN: Ảnh + Info -->
  <div class="detail-main">

    <!-- Gallery -->
    <div class="gallery">
      <div class="gallery-main" id="mainImg">
        <img id="mainImgEl"
             src="images/<?= htmlspecialchars($p['image'] ?? 'logo.png') ?>"
             alt="<?= htmlspecialchars($p['name']) ?>"
             onerror="this.onerror=null;this.src='images/logo.png'">
      </div>
      <div class="gallery-thumbs">
        <!-- Thumb chính = ảnh sản phẩm -->
        <div class="thumb active" onclick="switchImg(this, 'images/<?= htmlspecialchars($p['image'] ?? 'logo.png') ?>')">
          <img src="images/<?= htmlspecialchars($p['image'] ?? 'logo.png') ?>"
               alt="Ảnh 1" onerror="this.onerror=null;this.src='images/logo.png'">
        </div>
        <!-- Thumbs phụ (dùng cùng ảnh làm mẫu, thực tế sẽ có nhiều ảnh từ DB) -->
        <div class="thumb" onclick="switchImg(this, 'images/<?= htmlspecialchars($p['image'] ?? 'logo.png') ?>')">
          <img src="images/<?= htmlspecialchars($p['image'] ?? 'logo.png') ?>"
               alt="Ảnh 2" onerror="this.onerror=null;this.src='images/logo.png'">
        </div>
        <div class="thumb" onclick="switchImg(this, 'images/<?= htmlspecialchars($p['image'] ?? 'logo.png') ?>')">
          <img src="images/<?= htmlspecialchars($p['image'] ?? 'logo.png') ?>"
               alt="Ảnh 3" onerror="this.onerror=null;this.src='images/logo.png'">
        </div>
      </div>
    </div>

    <!-- Info -->
    <div class="detail-info">
      <a href="products.php?category=<?= htmlspecialchars($p['cat_slug']) ?>" class="detail-cat">
        ← <?= htmlspecialchars($p['cat_name']) ?>
      </a>

      <h1 class="detail-name"><?= htmlspecialchars($p['name']) ?></h1>

      <!-- Stars -->
      <div class="detail-stars">
        <div class="stars">
          <?php $rnd = (int)round($avgRating); for($i=1;$i<=5;$i++): ?>
          <svg class="star <?= $i>$rnd?'star-empty':'' ?>" viewBox="0 0 24 24">
            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
          </svg>
          <?php endfor; ?>
        </div>
        <span class="review-count">(<?= $reviewCount ?> đánh giá)</span>
      </div>

      <!-- Price -->
      <div class="detail-price-row">
        <span class="detail-price"><?= fmt($p['price']) ?></span>
        <?php if ($p['price_old']): ?>
          <span class="detail-price-old"><?= fmt($p['price_old']) ?></span>
          <span class="detail-discount"><?= $pct ?></span>
        <?php endif; ?>
      </div>

      <!-- Meta -->
      <div class="detail-meta">
        <?php if ($p['origin']): ?>
        <div class="meta-item">
          <span class="meta-label">Xuất xứ</span>
          <span class="meta-value"><?= htmlspecialchars($p['origin']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($p['weight']): ?>
        <div class="meta-item">
          <span class="meta-label">Khối lượng</span>
          <span class="meta-value"><?= htmlspecialchars($p['weight']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($p['type']): ?>
        <div class="meta-item">
          <span class="meta-label">Dạng sản phẩm</span>
          <span class="meta-value"><?= $type_labels[$p['type']] ?? $p['type'] ?></span>
        </div>
        <?php endif; ?>
        <div class="meta-item">
          <span class="meta-label">Tình trạng</span>
          <span class="meta-value" style="color:<?= ($p['stock']??0)>0?'#166534':'#dc2626' ?>">
            <?= ($p['stock']??0)>0 ? 'Còn hàng ('.$p['stock'].')' : 'Hết hàng' ?>
          </span>
        </div>
      </div>

      <!-- Quantity -->
      <div class="qty-section">
        <div class="qty-label">Số lượng</div>
        <div class="qty-wrap">
          <button class="qty-btn" onclick="changeQty(-1)">−</button>
          <input type="number" class="qty-input" id="qtyInput" value="1" min="1" max="<?= $p['stock']??99 ?>">
          <button class="qty-btn" onclick="changeQty(1)">+</button>
        </div>
      </div>

      <!-- Actions -->
      <div class="detail-actions">
        <button class="btn-buy" data-action="cart" data-product-id="<?= $p['id'] ?>" data-product-name="<?= htmlspecialchars($p['name']) ?>" data-product-price="<?= (int)$p['price'] ?>" data-product-image="images/<?= htmlspecialchars($p['image'] ?? 'logo.png') ?>" data-product-url="product_detail.php?id=<?= $p['id'] ?>" data-qty-input="#qtyInput">
          <svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
          Thêm vào giỏ hàng
        </button>
        <button class="btn-wish" id="btnWish" data-action="wishlist" data-product-id="<?= $p['id'] ?>" data-product-name="<?= htmlspecialchars($p['name']) ?>" data-product-price="<?= (int)$p['price'] ?>" data-product-image="images/<?= htmlspecialchars($p['image'] ?? 'logo.png') ?>" data-product-url="product_detail.php?id=<?= $p['id'] ?>">
          <svg viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
          Thêm vào yêu thích
        </button>
      </div>

      <!-- Policies -->
      <div class="detail-policies">
        <div class="policy-item">
          <svg viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v3h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
          Giao hàng toàn quốc
        </div>
        <div class="policy-item">
          <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          Hàng chính gốc 100%
        </div>
        <div class="policy-item">
          <svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.51"/></svg>
          Đổi trả 30 ngày
        </div>
        <div class="policy-item">
          <svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.18h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.77a16 16 0 0 0 6.29 6.29l.95-.95a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
          Hỗ trợ 24/7
        </div>
      </div>
    </div>
  </div>

  <!-- TABS: Mô tả / Thông số -->
  <div class="detail-tabs">
    <div class="tab-nav">
      <button class="tab-btn active" onclick="switchTab(this,'tab-desc')">Mô tả sản phẩm</button>
      <button class="tab-btn" onclick="switchTab(this,'tab-spec')">Thông số</button>
      <button class="tab-btn" onclick="switchTab(this,'tab-review')">Đánh giá (<?= $reviewCount ?>)</button>
    </div>

    <!-- Mô tả -->
    <div class="tab-pane active" id="tab-desc">
      <?php if ($p['description']): ?>
        <p><?= nl2br(htmlspecialchars($p['description'])) ?></p>
      <?php else: ?>
        <p><?= htmlspecialchars($p['name']) ?> là sản phẩm trà chất lượng cao từ vùng chè Tân Cương, Thái Nguyên — vùng đất nổi tiếng với thổ nhưỡng lý tưởng và khí hậu mát mẻ quanh năm.</p>
        <p>Sản phẩm được thu hái theo phương pháp thủ công truyền thống, đảm bảo giữ nguyên hương thơm tự nhiên và các dưỡng chất có lợi cho sức khỏe.</p>
        <ul>
          <li>Thu hái thủ công từ búp chè non 1 tôm 2 lá</li>
          <li>Không sử dụng hương liệu nhân tạo hay phẩm màu</li>
          <li>Bảo quản trong bao bì kín hơi, giữ hương thơm lâu dài</li>
          <li>Thích hợp pha nóng và pha lạnh</li>
        </ul>
      <?php endif; ?>
    </div>

    <!-- Thông số -->
    <div class="tab-pane" id="tab-spec">
      <table class="spec-table">
        <tr><td>Tên sản phẩm</td><td><?= htmlspecialchars($p['name']) ?></td></tr>
        <tr><td>Danh mục</td><td><?= htmlspecialchars($p['cat_name']) ?></td></tr>
        <?php if($p['origin']): ?><tr><td>Xuất xứ</td><td><?= htmlspecialchars($p['origin']) ?></td></tr><?php endif; ?>
        <?php if($p['weight']): ?><tr><td>Khối lượng</td><td><?= htmlspecialchars($p['weight']) ?></td></tr><?php endif; ?>
        <?php if($p['type']): ?><tr><td>Dạng sản phẩm</td><td><?= $type_labels[$p['type']] ?? $p['type'] ?></td></tr><?php endif; ?>
        <tr><td>Giá</td><td><?= fmt($p['price']) ?><?= $p['price_old'] ? ' <span style="color:#9ca3af;text-decoration:line-through;font-size:13px">'.fmt($p['price_old']).'</span>' : '' ?></td></tr>
        <tr><td>Tình trạng</td><td><?= ($p['stock']??0)>0 ? 'Còn hàng' : 'Hết hàng' ?></td></tr>
      </table>
    </div>

    <!-- Đánh giá -->
    <div class="tab-pane" id="tab-review">

      <?php if (!empty($_GET['reviewed'])): ?>
      <div class="review-success">
        <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
        Cảm ơn bạn đã đánh giá sản phẩm!
      </div>
      <?php endif; ?>

      <?php if ($reviewCount > 0): ?>
      <div class="review-summary">
        <div class="review-avg"><?= number_format($avgRating, 1) ?></div>
        <div>
          <div class="stars">
            <?php for($i=1;$i<=5;$i++): ?>
            <svg class="star <?= $i>$rnd?'star-empty':'' ?>" viewBox="0 0 24 24">
              <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
            </svg>
            <?php endfor; ?>
          </div>
          <div class="review-total"><?= $reviewCount ?> đánh giá</div>
        </div>
      </div>

      <div class="review-list">
        <?php foreach ($reviews as $rv): ?>
        <div class="review-item">
          <div class="review-header">
            <div class="reviewer-avatar"><?= htmlspecialchars(strtoupper(substr($rv['username'], 0, 1))) ?></div>
            <div>
              <div class="reviewer-name"><?= htmlspecialchars($rv['username']) ?></div>
              <div class="reviewer-date"><?= date('d/m/Y', strtotime($rv['created_at'])) ?></div>
            </div>
            <div class="stars" style="margin-left:auto">
              <?php for($i=1;$i<=5;$i++): ?>
              <svg class="star star-sm <?= $i>$rv['rating']?'star-empty':'' ?>" viewBox="0 0 24 24">
                <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
              </svg>
              <?php endfor; ?>
            </div>
          </div>
          <?php if ($rv['comment']): ?>
          <p class="review-comment"><?= nl2br(htmlspecialchars($rv['comment'])) ?></p>
          <?php endif; ?>

          <?php if (!empty($rv['admin_reply'])): ?>
          <div class="admin-reply-box" style="margin-top:12px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:12px 16px; position:relative;">
            <div style="position:absolute; top:-6px; left:24px; width:10px; height:10px; background:#f0fdf4; border-top:1px solid #bbf7d0; border-left:1px solid #bbf7d0; transform:rotate(45deg);"></div>
            <div style="font-size:12.5px; font-weight:700; color:#166534; margin-bottom:6px; display:flex; align-items:center; gap:6px;">
              <i class="fa-solid fa-headset"></i> Phản hồi từ Admin (<?= date('d/m/Y H:i', strtotime($rv['replied_at'])) ?>)
            </div>
            <div style="font-size:14px; color:#15803d; line-height:1.5;">
              <?= nl2br(htmlspecialchars($rv['admin_reply'])) ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <p class="review-empty">Chưa có đánh giá nào. Hãy là người đầu tiên nhận xét!</p>
      <?php endif; ?>

      <?php if ($isLoggedIn && !$userHasReview && $canReview): ?>
      <div class="review-form-wrap">
        <h3>Viết đánh giá của bạn</h3>
        <form method="POST" action="submit_review.php">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="product_id" value="<?= $id ?>">
          <div class="star-picker" id="starPicker">
            <?php for($i=5;$i>=1;$i--): ?>
            <label class="star-pick-label">
              <input type="radio" name="rating" value="<?= $i ?>" <?= $i===5?'checked':'' ?> required>
              <svg class="star-pick" viewBox="0 0 24 24">
                <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
              </svg>
            </label>
            <?php endfor; ?>
          </div>
          <textarea name="comment" rows="3" placeholder="Chia sẻ cảm nhận của bạn (không bắt buộc)..."></textarea>
          <button type="submit" class="btn-submit-review">Gửi đánh giá</button>
        </form>
      </div>
      <?php elseif ($isLoggedIn && $userHasReview): ?>
      <p class="review-already">Bạn đã đánh giá sản phẩm này rồi.</p>
      <?php elseif ($isLoggedIn && !$canReview): ?>
      <p class="review-login-hint">Bạn cần mua và nhận sản phẩm này để có thể viết đánh giá.</p>
      <?php else: ?>
      <p class="review-login-hint"><a href="login.php">Đăng nhập</a> để viết đánh giá sản phẩm.</p>
      <?php endif; ?>

    </div>
  </div>

  <!-- RELATED PRODUCTS -->
  <?php if (!empty($related)): ?>
  <div class="related-section">
    <div class="related-title">Có thể bạn thích</div>
    <div class="related-grid">
      <?php foreach ($related as $r):
        $rtag = '';
        if ($r['is_featured']) $rtag = ['label'=>'Nổi bật','class'=>'tag-best'];
        if ($r['is_new'])      $rtag = ['label'=>'Mới','class'=>'tag-new'];
        if ($r['price_old'])   $rtag = ['label'=>'Sale','class'=>'tag-sale'];
        $rpct = $r['price_old'] ? '-'.round((1-$r['price']/($r['price_old']?:1))*100).'%' : '';
      ?>
      <a class="pcard" href="product_detail.php?id=<?= $r['id'] ?>">
        <div class="pcard-img">
          <img src="images/<?= htmlspecialchars($r['image'] ?? 'logo.png') ?>"
               alt="<?= htmlspecialchars($r['name']) ?>"
               onerror="this.onerror=null;this.src='images/logo.png'">
          <?php if ($rtag): ?>
            <span class="pcard-tag <?= $rtag['class'] ?>"><?= $rtag['label'] ?></span>
          <?php endif; ?>
        </div>
        <div class="pcard-body">
          <div class="pcard-name"><?= htmlspecialchars($r['name']) ?></div>
          <div class="price-row">
            <span class="price-new"><?= fmt($r['price']) ?></span>
            <?php if($r['price_old']): ?>
              <span class="price-old"><?= fmt($r['price_old']) ?></span>
              <span class="price-pct"><?= $rpct ?></span>
            <?php endif; ?>
          </div>
          <button class="btn-add" data-action="cart" data-product-id="<?= $r['id'] ?>" data-product-name="<?= htmlspecialchars($r['name']) ?>" data-product-price="<?= (int)$r['price'] ?>" data-product-image="images/<?= htmlspecialchars($r['image'] ?? 'logo.png') ?>" data-product-url="product_detail.php?id=<?= $r['id'] ?>" onclick="event.preventDefault();event.stopPropagation()">
            <svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
            Thêm giỏ hàng
          </button>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /detail-wrap -->

<!-- ══ FOOTER ══ -->
<footer class="site-footer" id="contact">
  <div class="footer-trust-bar">
    <div class="footer-trust-inner">
      <div class="ft-item">
        <div class="ft-icon"><svg viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v3h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></div>
        <div class="ft-title">Giao hàng toàn quốc</div>
        <div class="ft-sub">Miễn phí từ 300.000đ</div>
      </div>
      <div class="ft-item">
        <div class="ft-icon"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div>
        <div class="ft-title">Hàng chính gốc 100%</div>
        <div class="ft-sub">Cam kết hoàn tiền</div>
      </div>
      <div class="ft-item">
        <div class="ft-icon"><svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.51"/></svg></div>
        <div class="ft-title">Đổi trả 30 ngày</div>
        <div class="ft-sub">Miễn phí, không điều kiện</div>
      </div>
      <div class="ft-item">
        <div class="ft-icon"><svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.18h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.77a16 16 0 0 0 6.29 6.29l.95-.95a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg></div>
        <div class="ft-title">Hỗ trợ 24/7</div>
        <div class="ft-sub">Hotline: 1800 xxxx</div>
      </div>
    </div>
  </div>
  <div class="footer-bottom-bar">
    <div class="footer-bottom-inner">
      <span>© 2025 Mộc Trà Thái Nguyên. Bảo lưu mọi quyền.</span>
      <span>Được xây dựng với ❤ bởi <a href="#">Nhóm 3</a></span>
    </div>
  </div>
</footer>

<script>
(function(){
  /* ── Dropdowns ── */
  var catBtn=document.getElementById('catBtn'),catMenu=document.getElementById('catMenu');
  var userBtn=document.getElementById('userBtn'),userMenu=document.getElementById('userMenu');
  function closeAll(){
    catMenu&&catMenu.classList.remove('open');
    userMenu&&userMenu.classList.remove('open');
    catBtn&&catBtn.classList.remove('open');
    userBtn&&userBtn.classList.remove('open');
  }
  catBtn&&catBtn.addEventListener('click',function(e){e.stopPropagation();var o=catMenu.classList.contains('open');closeAll();if(!o){catMenu.classList.add('open');catBtn.classList.add('open');}});
  userBtn&&userBtn.addEventListener('click',function(e){e.stopPropagation();var o=userMenu.classList.contains('open');closeAll();if(!o){userMenu.classList.add('open');userBtn.classList.add('open');}});
  document.addEventListener('click',closeAll);
  catMenu&&catMenu.addEventListener('click',function(e){e.stopPropagation();});
  userMenu&&userMenu.addEventListener('click',function(e){e.stopPropagation();});

  /* ── Navbar shadow ── */
  window.addEventListener('scroll',function(){
    var nb=document.getElementById('navbar');
    if(nb) nb.style.boxShadow=window.scrollY>30?'0 4px 20px rgba(0,0,0,0.14)':'0 2px 12px rgba(0,0,0,0.06)';
  });
})();

/* ── Gallery switch ── */
function switchImg(thumb, src) {
  document.getElementById('mainImgEl').src = src;
  document.querySelectorAll('.thumb').forEach(function(t){ t.classList.remove('active'); });
  thumb.classList.add('active');
}

/* ── Qty ── */
function changeQty(delta) {
  var inp = document.getElementById('qtyInput');
  var v = parseInt(inp.value) + delta;
  var max = parseInt(inp.max) || 99;
  if (v < 1) v = 1;
  if (v > max) v = max;
  inp.value = v;
}

/* ── Tabs ── */
function switchTab(btn, paneId) {
  document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
  document.querySelectorAll('.tab-pane').forEach(function(p){ p.classList.remove('active'); });
  btn.classList.add('active');
  document.getElementById(paneId).classList.add('active');
}

/* Auto-open review tab if redirected back from submit */
(function(){
  var qs = new URLSearchParams(window.location.search);
  if (qs.get('reviewed') === '1' || window.location.hash === '#tab-review') {
    var btn = document.querySelector('[onclick*="tab-review"]');
    if (btn) switchTab(btn, 'tab-review');
    var el = document.getElementById('tab-review');
    if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
  }
})();

</script>
<script src="js/moctra-functions.js"></script>
<script src="js/search-suggestion.js"></script>
</body>
</html>


