<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();
require_once 'config/db.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$isLoggedIn  = isset($_SESSION['user_id']);
$isAdmin     = $isLoggedIn && ($_SESSION['role'] ?? '') === 'admin';
$userId      = $isLoggedIn ? (int)$_SESSION['user_id'] : 0;
$username    = htmlspecialchars($_SESSION['username'] ?? '');
$userInitial = $username ? strtoupper(substr($username, 0, 1)) : '';

if ($isAdmin) {
    header('Location: admin/dashboard.php'); exit();
}

$cats = $conn->query("SELECT * FROM categories ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);

/* ── Xử lý POST ── */
$postError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isLoggedIn) {
    if (empty($_POST['_csrf']) || !hash_equals($_SESSION['csrf_token'], $_POST['_csrf'])) {
        $postError = 'Yêu cầu không hợp lệ.';
    } else {
        $productId = (int)($_POST['product_id'] ?? 0);
        $rating    = (int)($_POST['rating']     ?? 0);
        $comment   = trim($_POST['comment']     ?? '');

        if ($productId <= 0 || $rating < 1 || $rating > 5) {
            $postError = 'Vui lòng chọn sản phẩm và chọn số sao đánh giá.';
        } elseif (mb_strlen($comment) < 10) {
            $postError = 'Nội dung đánh giá cần ít nhất 10 ký tự.';
        } else {
            $stOrd = $conn->prepare(
                "SELECT o.id FROM orders o
                 JOIN order_items oi ON o.id = oi.order_id
                 WHERE o.user_id = ? AND oi.product_id = ? AND o.status = 'delivered'
                   AND NOT EXISTS (
                     SELECT 1 FROM product_reviews pr
                     WHERE pr.order_id = o.id AND pr.product_id = ? AND pr.user_id = ?
                   )
                 ORDER BY o.created_at DESC LIMIT 1"
            );
            $stOrd->bind_param('iiii', $userId, $productId, $productId, $userId);
            $stOrd->execute();
            $ordRow = $stOrd->get_result()->fetch_row();

            if (!$ordRow) {
                $postError = 'Bạn chưa mua sản phẩm này hoặc đã gửi đánh giá rồi.';
            } else {
                $orderId = (int)$ordRow[0];
                $stIns = $conn->prepare(
                    "INSERT INTO product_reviews (product_id, order_id, user_id, rating, comment)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE rating=VALUES(rating), comment=VALUES(comment), created_at=NOW()"
                );
                $stIns->bind_param('iiiis', $productId, $orderId, $userId, $rating, $comment);
                if ($stIns->execute()) {
                    header('Location: reviews.php?success=1');
                    exit();
                } else {
                    $postError = 'Gửi đánh giá thất bại, vui lòng thử lại.';
                }
            }
        }
    }
}

/* ── Dữ liệu hiển thị ── */
$avgRow = $conn->query(
    "SELECT ROUND(AVG(rating),1) AS avg_r, COUNT(*) AS total FROM product_reviews"
)->fetch_assoc();
$avgRating    = (float)($avgRow['avg_r']   ?? 0);
$totalReviews = (int)  ($avgRow['total']   ?? 0);

$distRows = $conn->query(
    "SELECT rating, COUNT(*) AS cnt FROM product_reviews GROUP BY rating ORDER BY rating DESC"
);
$dist = [];
if ($distRows) {
    while ($dr = $distRows->fetch_assoc()) {
        $dist[(int)$dr['rating']] = (int)$dr['cnt'];
    }
}

$allReviews = $conn->query(
    "SELECT pr.id, pr.rating, pr.comment, pr.created_at,
            p.name AS product_name, p.image AS product_image,
            COALESCE(u.full_name, u.username) AS reviewer_name
     FROM product_reviews pr
     JOIN products p  ON pr.product_id = p.id
     JOIN users   u  ON pr.user_id     = u.id
     ORDER BY pr.created_at DESC"
)->fetch_all(MYSQLI_ASSOC);

$eligibleProducts = [];
if ($isLoggedIn) {
    $stElig = $conn->prepare(
        "SELECT DISTINCT p.id, p.name
         FROM orders o
         JOIN order_items oi ON o.id = oi.order_id
         JOIN products   p  ON oi.product_id = p.id
         WHERE o.user_id = ? AND o.status = 'delivered'
           AND NOT EXISTS (
             SELECT 1 FROM product_reviews pr2
             WHERE pr2.order_id = o.id AND pr2.product_id = p.id AND pr2.user_id = ?
           )
         ORDER BY p.name"
    );
    $stElig->bind_param('ii', $userId, $userId);
    $stElig->execute();
    $eligibleProducts = $stElig->get_result()->fetch_all(MYSQLI_ASSOC);
}

function renderStars(int $rating, string $size = 'md'): string {
    $out = '<span class="stars stars--' . $size . '">';
    for ($i = 1; $i <= 5; $i++) {
        $out .= $i <= $rating ? '<span class="s-filled">★</span>' : '<span class="s-empty">★</span>';
    }
    return $out . '</span>';
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php if ($isLoggedIn): ?>
  <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
  <?php endif; ?>
  <title>Đánh giá khách hàng — Mộc Trà Thái Nguyên</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Be+Vietnam+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/index.css">
  <style>
    body { background:#f9fafb; }

    /* Breadcrumb */
    .breadcrumb-bar { background:#fff; border-bottom:1px solid #f3f4f6; padding:12px 0; }
    .breadcrumb-inner { max-width:1200px; margin:0 auto; padding:0 24px; display:flex; align-items:center; gap:8px; font-size:13px; color:#6b7280; }
    .breadcrumb-inner a { color:#166534; text-decoration:none; }
    .breadcrumb-inner a:hover { text-decoration:underline; }
    .sep { color:#d1d5db; }

    /* ── Rating Hero ── */
    .rating-hero {
      background: linear-gradient(135deg, #166534 0%, #0f5132 60%, #064e2b 100%);
      padding: 52px 24px;
    }
    .rh-inner {
      max-width: 1200px;
      margin: 0 auto;
      display: flex;
      gap: 64px;
      align-items: center;
    }
    .rh-left { text-align: center; flex-shrink: 0; }
    .rh-score {
      font-size: 72px;
      font-weight: 700;
      color: #fff;
      line-height: 1;
      font-family: 'Playfair Display', serif;
    }
    .rh-stars-big { font-size: 28px; margin: 8px 0 6px; letter-spacing: 2px; }
    .rh-stars-big .s-filled { color: #fbbf24; }
    .rh-stars-big .s-empty  { color: rgba(255,255,255,.3); }
    .rh-count-label { color: rgba(255,255,255,.7); font-size: 14px; }
    .rh-divider { width:1px; background:rgba(255,255,255,.15); height:100px; flex-shrink:0; }
    .rh-bars { flex:1; }
    .rh-bars h3 {
      color: #fff;
      font-size: 16px;
      font-weight: 600;
      margin-bottom: 16px;
      font-family: 'Playfair Display', serif;
    }
    .rh-bar-row {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 8px;
    }
    .rh-bar-row .lbl { color: rgba(255,255,255,.8); font-size: 13px; width: 24px; text-align:right; flex-shrink:0; }
    .rh-bar-track { flex:1; height:8px; background:rgba(255,255,255,.15); border-radius:99px; overflow:hidden; }
    .rh-bar-fill { height:100%; background:#fbbf24; border-radius:99px; transition:width .6s ease; }
    .rh-bar-row .cnt { color: rgba(255,255,255,.6); font-size: 12px; width: 28px; flex-shrink:0; }
    .rh-empty-msg { color: rgba(255,255,255,.6); font-size: 14px; }

    /* ── Page Layout ── */
    .rv-wrap {
      max-width: 1200px;
      margin: 40px auto 80px;
      padding: 0 24px;
      display: grid;
      grid-template-columns: 1fr 380px;
      gap: 32px;
      align-items: start;
    }

    /* ── Alerts ── */
    .alert-success {
      background:#f0fdf4; border:1px solid #bbf7d0; border-left:4px solid #22c55e;
      border-radius:10px; padding:18px 20px; color:#15803d; margin-bottom:28px;
    }
    .alert-success h3 { font-size:16px; margin-bottom:4px; }
    .alert-success p  { font-size:13.5px; opacity:.9; }
    .alert-error {
      background:#fef2f2; border:1px solid #fecaca; border-left:4px solid #ef4444;
      border-radius:10px; padding:14px 18px; color:#b91c1c; font-size:13.5px; margin-bottom:20px;
    }

    /* ── Stars ── */
    .stars .s-filled { color:#f59e0b; }
    .stars .s-empty  { color:#d1d5db; }
    .stars--sm { font-size:14px; }
    .stars--md { font-size:18px; }
    .stars--lg { font-size:22px; }

    /* ── Section heading ── */
    .section-heading {
      font-family: 'Playfair Display', serif;
      font-size: 22px;
      color: #111827;
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .section-heading span { font-size:13px; color:#6b7280; font-family:'Be Vietnam Pro',sans-serif; font-weight:400; }

    /* ── Review Grid ── */
    .rv-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }
    .rv-card {
      background: #fff;
      border: 1px solid #f3f4f6;
      border-radius: 16px;
      padding: 20px 22px;
      transition: box-shadow .2s, transform .2s;
    }
    .rv-card:hover { box-shadow: 0 6px 20px rgba(0,0,0,.08); transform: translateY(-2px); }
    .rv-card-top {
      display: flex;
      align-items: flex-start;
      gap: 12px;
      margin-bottom: 12px;
    }
    .rv-avatar {
      width: 38px; height: 38px; border-radius: 50%;
      background: linear-gradient(135deg, #166534, #0f5132);
      color: #fff; font-weight: 700; font-size: 15px;
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
    }
    .rv-meta { flex: 1; min-width: 0; }
    .rv-reviewer { font-weight: 600; font-size: 14px; color: #111827; }
    .rv-date { font-size: 12px; color: #9ca3af; margin-top: 2px; }
    .rv-product-tag {
      display: inline-block;
      background: #f0fdf4;
      color: #166534;
      border: 1px solid #bbf7d0;
      border-radius: 6px;
      font-size: 11.5px;
      font-weight: 600;
      padding: 2px 8px;
      margin-bottom: 8px;
      max-width: 100%;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .rv-comment {
      font-size: 13.5px;
      color: #374151;
      line-height: 1.65;
      margin-top: 8px;
      display: -webkit-box;
      -webkit-line-clamp: 4;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }

    /* empty state */
    .rv-empty {
      grid-column: 1 / -1;
      text-align: center;
      padding: 60px 20px;
      color: #9ca3af;
    }
    .rv-empty .rv-empty-icon { font-size: 48px; margin-bottom: 12px; opacity: .4; }
    .rv-empty p { font-size: 15px; }

    /* ── Submission Form ── */
    .rv-form-card {
      background: #fff;
      border-radius: 20px;
      padding: 32px 28px;
      box-shadow: 0 4px 24px rgba(0,0,0,.06);
      position: sticky;
      top: 100px;
    }
    .rv-form-card h2 {
      font-family: 'Playfair Display', serif;
      font-size: 20px;
      color: #111827;
      margin-bottom: 6px;
    }
    .rv-form-card > p { font-size: 13px; color: #6b7280; margin-bottom: 22px; }
    .fg { margin-bottom: 16px; }
    .fg label { display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:6px; }
    .fg .req { color:#dc2626; margin-left:2px; }
    .inp {
      width:100%; padding:10px 13px;
      border:1.5px solid #e5e7eb; border-radius:10px;
      font-size:14px; font-family:inherit; color:#111827;
      background:#f9fafb; transition:border-color .2s, box-shadow .2s, background .2s; outline:none;
    }
    .inp:focus { border-color:#166534; background:#fff; box-shadow:0 0 0 3px rgba(22,101,52,.1); }
    textarea.inp { resize:vertical; min-height:100px; line-height:1.6; }

    /* Star picker */
    .star-picker {
      display: flex;
      gap: 4px;
      margin-top: 4px;
    }
    .star-picker .sp-star {
      font-size: 32px;
      color: #d1d5db;
      cursor: pointer;
      transition: color .1s, transform .1s;
      line-height: 1;
      user-select: none;
    }
    .star-picker .sp-star.active { color: #f59e0b; }
    .star-picker .sp-star:hover  { transform: scale(1.15); }
    .star-label { font-size: 13px; color: #6b7280; margin-top: 6px; min-height: 18px; }
    .field-err { font-size: 12px; color: #dc2626; margin-top: 4px; }

    .btn-submit-rv {
      width: 100%;
      padding: 13px;
      background: linear-gradient(135deg, #166534, #0f5132);
      color: #fff; border: none; border-radius: 12px;
      font-size: 15px; font-weight: 700; font-family: inherit;
      cursor: pointer; transition: opacity .2s, transform .15s;
      margin-top: 4px;
    }
    .btn-submit-rv:hover  { opacity: .88; transform: translateY(-2px); }
    .btn-submit-rv:active { transform: translateY(0); }

    /* Info cards when form not available */
    .rv-info-card {
      background: #fff;
      border-radius: 20px;
      padding: 32px 24px;
      box-shadow: 0 4px 24px rgba(0,0,0,.06);
      text-align: center;
    }
    .rv-info-card .rv-ic-icon { font-size: 40px; margin-bottom: 12px; color: #166534; }
    .rv-info-card h3 { font-size: 17px; font-weight: 700; color: #111827; margin-bottom: 8px; }
    .rv-info-card p { font-size: 13.5px; color: #6b7280; line-height: 1.6; margin-bottom: 20px; }
    .rv-info-card .btn-login {
      display: inline-block;
      padding: 11px 28px;
      background: linear-gradient(135deg, #166534, #0f5132);
      color: #fff; border-radius: 10px;
      font-size: 14px; font-weight: 600; text-decoration: none;
      transition: opacity .2s;
    }
    .rv-info-card .btn-login:hover { opacity: .85; }
    .rv-no-eligible { font-size: 13.5px; color: #6b7280; line-height: 1.7; }
    .rv-no-eligible a { color: #166534; font-weight: 600; text-decoration: none; }
    .rv-no-eligible a:hover { text-decoration: underline; }

    @media (max-width: 1024px) {
      .rv-wrap { grid-template-columns: 1fr; }
      .rv-form-card { position: static; }
    }
    @media (max-width: 640px) {
      .rv-grid { grid-template-columns: 1fr; }
      .rh-inner { flex-direction: column; gap: 28px; }
      .rh-divider { width: 80px; height: 1px; }
    }
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
      <a href="reviews.php" class="nav-link active">Đánh giá</a>
      <a href="contact.php" class="nav-link">Liên hệ</a>
    </div>
    <div class="nav-right">
      <?php if ($isLoggedIn): ?>
      <a href="wishlist.php" class="nav-icon-btn" title="Yêu thích">
        <svg viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
      </a>
      <a href="cart.php" class="nav-icon-btn" title="Giỏ hàng">
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
      <?php else: ?>
      <a href="login.php" class="nav-icon-btn" title="Đăng nhập" style="font-size:13px;font-weight:600;color:#166534;padding:8px 16px;border:1.5px solid #166534;border-radius:8px;text-decoration:none;">Đăng nhập</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<!-- Breadcrumb -->
<div class="breadcrumb-bar">
  <div class="breadcrumb-inner">
    <a href="index.php">Trang chủ</a>
    <span class="sep">›</span>
    <span>Đánh giá khách hàng</span>
  </div>
</div>

<!-- ══ Rating Hero ══ -->
<section class="rating-hero">
  <div class="rh-inner">
    <div class="rh-left">
      <div class="rh-score"><?= $totalReviews > 0 ? number_format($avgRating, 1) : '—' ?></div>
      <div class="rh-stars-big">
        <?php
        $rounded = round($avgRating);
        for ($i = 1; $i <= 5; $i++) {
            echo $i <= $rounded ? '<span class="s-filled">★</span>' : '<span class="s-empty">★</span>';
        }
        ?>
      </div>
      <div class="rh-count-label">
        <?= $totalReviews > 0
            ? 'Dựa trên <strong style="color:#fff">' . $totalReviews . '</strong> đánh giá'
            : 'Chưa có đánh giá nào' ?>
      </div>
    </div>

    <div class="rh-divider"></div>

    <div class="rh-bars">
      <h3>Phân bố đánh giá</h3>
      <?php if ($totalReviews > 0): ?>
        <?php for ($s = 5; $s >= 1; $s--): ?>
          <?php $cnt = $dist[$s] ?? 0; $pct = $totalReviews > 0 ? round($cnt / $totalReviews * 100) : 0; ?>
          <div class="rh-bar-row">
            <span class="lbl"><?= $s ?>★</span>
            <div class="rh-bar-track">
              <div class="rh-bar-fill" style="width:<?= $pct ?>%"></div>
            </div>
            <span class="cnt"><?= $cnt ?></span>
          </div>
        <?php endfor; ?>
      <?php else: ?>
        <p class="rh-empty-msg">Chưa có đánh giá để thống kê.</p>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- ══ Main Content ══ -->
<div class="rv-wrap">

  <!-- Reviews -->
  <main class="rv-main">

    <?php if (isset($_GET['success'])): ?>
    <div class="alert-success">
      <h3>Cảm ơn bạn đã đánh giá!</h3>
      <p>Nhận xét của bạn đã được ghi nhận và sẽ giúp ích cho những khách hàng khác.</p>
    </div>
    <?php endif; ?>

    <h2 class="section-heading">
      Nhận xét từ khách hàng
      <span><?= $totalReviews ?> đánh giá</span>
    </h2>

    <div class="rv-grid">
      <?php if (empty($allReviews)): ?>
      <div class="rv-empty">
        <div class="rv-empty-icon">💬</div>
        <p>Chưa có đánh giá nào. Hãy là người đầu tiên chia sẻ trải nghiệm!</p>
      </div>
      <?php else: ?>
        <?php foreach ($allReviews as $rv): ?>
        <div class="rv-card">
          <div class="rv-card-top">
            <div class="rv-avatar"><?= htmlspecialchars(strtoupper(mb_substr($rv['reviewer_name'], 0, 1))) ?></div>
            <div class="rv-meta">
              <div class="rv-reviewer"><?= htmlspecialchars($rv['reviewer_name']) ?></div>
              <div class="rv-date"><?= date('d/m/Y', strtotime($rv['created_at'])) ?></div>
            </div>
          </div>
          <?= renderStars((int)$rv['rating'], 'sm') ?>
          <div class="rv-product-tag" title="<?= htmlspecialchars($rv['product_name']) ?>">
            <?= htmlspecialchars($rv['product_name']) ?>
          </div>
          <div class="rv-comment"><?= nl2br(htmlspecialchars($rv['comment'])) ?></div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </main>

  <!-- Sidebar: Form -->
  <aside>
    <?php if (!$isLoggedIn): ?>
    <div class="rv-info-card">
      <div class="rv-ic-icon"><i class="fa-regular fa-star"></i></div>
      <h3>Chia sẻ trải nghiệm của bạn</h3>
      <p>Đăng nhập để viết đánh giá về những sản phẩm bạn đã mua và nhận được.</p>
      <a href="login.php?redirect=reviews.php" class="btn-login">Đăng nhập ngay</a>
    </div>

    <?php elseif (empty($eligibleProducts)): ?>
    <div class="rv-form-card">
      <h2>Gửi đánh giá</h2>
      <p class="rv-no-eligible">
        Bạn chưa có đơn hàng nào đã giao thành công, hoặc đã đánh giá tất cả sản phẩm rồi.
        <br><br>
        Sau khi đơn hàng được giao, bạn sẽ có thể đánh giá sản phẩm tại đây.
        <br><br>
        <a href="products.php">Khám phá sản phẩm</a> &nbsp;·&nbsp; <a href="order_history.php">Xem đơn hàng</a>
      </p>
    </div>

    <?php else: ?>
    <div class="rv-form-card">
      <h2>Gửi đánh giá</h2>
      <p>Chia sẻ trải nghiệm thực tế của bạn về sản phẩm đã mua.</p>

      <?php if ($postError): ?>
      <div class="alert-error"><?= htmlspecialchars($postError) ?></div>
      <?php endif; ?>

      <form method="POST" id="rvForm">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

        <div class="fg">
          <label for="rv-product">Sản phẩm <span class="req">*</span></label>
          <select class="inp" id="rv-product" name="product_id">
            <option value="">— Chọn sản phẩm —</option>
            <?php foreach ($eligibleProducts as $ep): ?>
            <option value="<?= $ep['id'] ?>" <?= (int)($_POST['product_id'] ?? 0) === $ep['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($ep['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div class="field-err" id="err-product"></div>
        </div>

        <div class="fg">
          <label>Đánh giá <span class="req">*</span></label>
          <div class="star-picker" id="starPicker">
            <input type="hidden" name="rating" id="ratingInput" value="<?= (int)($_POST['rating'] ?? 0) ?>">
            <?php for ($i = 1; $i <= 5; $i++): ?>
            <span class="sp-star <?= (int)($_POST['rating'] ?? 0) >= $i ? 'active' : '' ?>"
                  data-val="<?= $i ?>">★</span>
            <?php endfor; ?>
          </div>
          <div class="star-label" id="starLabel"><?= (int)($_POST['rating'] ?? 0) > 0 ? ['','Rất tệ','Tệ','Bình thường','Tốt','Xuất sắc'][(int)$_POST['rating']] : 'Chạm để chọn số sao' ?></div>
          <div class="field-err" id="err-rating"></div>
        </div>

        <div class="fg">
          <label for="rv-comment">Nhận xét <span class="req">*</span></label>
          <textarea class="inp" id="rv-comment" name="comment"
            placeholder="Hương vị, chất lượng, đóng gói, dịch vụ... (ít nhất 10 ký tự)"><?= htmlspecialchars($_POST['comment'] ?? '') ?></textarea>
          <div class="field-err" id="err-comment"></div>
        </div>

        <button type="submit" class="btn-submit-rv" id="rvSubmit">Gửi đánh giá →</button>
      </form>
    </div>
    <?php endif; ?>
  </aside>

</div><!-- /.rv-wrap -->

<footer style="background:#111827;color:rgba(255,255,255,.5);text-align:center;padding:20px;font-size:13px;">
  © 2025 Mộc Trà Thái Nguyên. Mọi quyền được bảo lưu.
</footer>

<script>
(function () {
  /* ── Star picker ── */
  var picker    = document.getElementById('starPicker');
  var ratingInp = document.getElementById('ratingInput');
  var starLabel = document.getElementById('starLabel');
  var labels    = ['', 'Rất tệ', 'Tệ', 'Bình thường', 'Tốt', 'Xuất sắc'];

  if (picker) {
    var spStars = picker.querySelectorAll('.sp-star');

    function highlightStars(val) {
      spStars.forEach(function (s) {
        s.classList.toggle('active', parseInt(s.dataset.val) <= val);
      });
      if (starLabel) starLabel.textContent = val > 0 ? labels[val] : 'Chạm để chọn số sao';
    }

    spStars.forEach(function (s) {
      s.addEventListener('mouseenter', function () { highlightStars(parseInt(s.dataset.val)); });
      s.addEventListener('click', function () {
        ratingInp.value = s.dataset.val;
        highlightStars(parseInt(s.dataset.val));
        var errRating = document.getElementById('err-rating');
        if (errRating) errRating.textContent = '';
      });
    });
    picker.addEventListener('mouseleave', function () {
      highlightStars(parseInt(ratingInp.value) || 0);
    });
  }

  /* ── Form validation ── */
  var rvForm = document.getElementById('rvForm');
  if (rvForm) {
    function setErr(id, msg) {
      var el = document.getElementById(id);
      if (el) el.textContent = msg;
    }
    function clearErr(id) { setErr(id, ''); }

    rvForm.addEventListener('submit', function (e) {
      var ok = true;
      var product = document.getElementById('rv-product');
      var comment = document.getElementById('rv-comment');
      var rating  = ratingInp ? parseInt(ratingInp.value) || 0 : 0;

      if (product && !product.value) {
        setErr('err-product', 'Vui lòng chọn sản phẩm.');
        ok = false;
      } else {
        clearErr('err-product');
      }

      if (rating < 1) {
        setErr('err-rating', 'Vui lòng chọn số sao.');
        ok = false;
      } else {
        clearErr('err-rating');
      }

      if (comment && comment.value.trim().length < 10) {
        setErr('err-comment', 'Nội dung cần ít nhất 10 ký tự.');
        ok = false;
      } else {
        clearErr('err-comment');
      }

      if (!ok) {
        e.preventDefault();
        return;
      }
      var btn = document.getElementById('rvSubmit');
      if (btn) { btn.disabled = true; btn.textContent = 'Đang gửi...'; }
    });
  }

  /* ── Navbar dropdowns ── */
  var catBtn  = document.getElementById('catBtn');
  var catMenu = document.getElementById('catMenu');
  var userBtn = document.getElementById('userBtn');
  var userMenu = document.getElementById('userMenu');

  function closeAll() {
    catMenu  && catMenu.classList.remove('open');
    userMenu && userMenu.classList.remove('open');
  }
  catBtn  && catBtn.addEventListener('click',  function (e) { e.stopPropagation(); catMenu.classList.toggle('open'); });
  userBtn && userBtn.addEventListener('click', function (e) { e.stopPropagation(); userMenu.classList.toggle('open'); });
  document.addEventListener('click', closeAll);
})();
</script>
<script src="js/moctra-functions.js"></script>
</body>
</html>
