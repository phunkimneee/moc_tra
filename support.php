<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();
require_once 'config/db.php';

$userId      = (int)($_SESSION['user_id'] ?? 0);
$username    = htmlspecialchars($_SESSION['username'] ?? '');
$userInitial = strtoupper(substr($username, 0, 1));
$isLoggedIn  = $userId > 0;
$cats        = $conn->query("SELECT * FROM categories ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);

$cart_count  = array_sum(array_column($_SESSION['cart'] ?? [], 'qty'));

/* ── Danh sách topic hợp lệ ── */
$topics = [
    'faq'      => ['title' => 'Câu hỏi thường gặp',     'icon' => 'M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
    'order'    => ['title' => 'Hướng dẫn đặt hàng',      'icon' => 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z'],
    'payment'  => ['title' => 'Phương thức thanh toán',  'icon' => 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z'],
    'shipping' => ['title' => 'Phương thức vận chuyển',  'icon' => 'M9 17a2 2 0 11-4 0 2 2 0 014 0zM19 17a2 2 0 11-4 0 2 2 0 014 0zM1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6'],
    'returns'  => ['title' => 'Chính sách đổi trả',      'icon' => 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15'],
];

$topic = trim($_GET['topic'] ?? 'faq');
if (!array_key_exists($topic, $topics)) {
    $topic = 'faq';
}

$contentFile = __DIR__ . '/content/' . $topic . '.php';
$pageTitle   = $topics[$topic]['title'];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?> — Mộc Trà Thái Nguyên</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Be+Vietnam+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/index.css">
  <link rel="stylesheet" href="css/components.css">
<style>
body { background: #f9fafb; }

.sp-breadcrumb { background:#fff; border-bottom:1px solid #f3f4f6; padding:12px 0; }
.sp-breadcrumb-inner { max-width:1100px; margin:0 auto; padding:0 24px; display:flex; align-items:center; gap:8px; font-size:13px; color:#6b7280; }
.sp-breadcrumb-inner a { color:#6b7280; text-decoration:none; font-weight:500; }
.sp-breadcrumb-inner a:hover { color:#166534; }
.sp-breadcrumb-inner .sep { color:#d1d5db; }
.sp-breadcrumb-inner .cur { color:#111827; font-weight:600; }

.sp-wrap { max-width:1100px; margin:36px auto 72px; padding:0 24px; display:grid; grid-template-columns:240px 1fr; gap:28px; align-items:start; }

/* ── Sidebar ── */
.sp-sidebar { background:#fff; border-radius:16px; border:1px solid #f3f4f6; overflow:hidden; position:sticky; top:24px; box-shadow:0 4px 16px rgba(0,0,0,.04); }
.sp-sidebar-head { padding:18px 20px; background:linear-gradient(135deg,#166534,#0f5132); }
.sp-sidebar-head h3 { font-family:'Playfair Display',serif; font-size:15px; font-weight:700; color:#fff; margin:0; }
.sp-sidebar-head p  { font-size:12px; color:rgba(255,255,255,.6); margin:4px 0 0; }
.sp-nav { padding:8px 0; }
.sp-nav a {
  display:flex; align-items:center; gap:12px;
  padding:12px 20px; text-decoration:none;
  font-size:14px; font-weight:500; color:#374151;
  border-left:3px solid transparent;
  transition:all .18s;
}
.sp-nav a:hover  { background:#f9fafb; color:#166534; border-left-color:#86efac; }
.sp-nav a.active { background:#f0fdf4; color:#166534; font-weight:700; border-left-color:#166534; }
.sp-nav-icon { width:20px; height:20px; stroke:currentColor; fill:none; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; flex-shrink:0; }
.sp-nav-divider { height:1px; background:#f3f4f6; margin:4px 0; }

.sp-sidebar-contact { padding:16px 20px; border-top:1px solid #f3f4f6; }
.sp-sidebar-contact p { font-size:12px; color:#9ca3af; margin:0 0 10px; }
.sp-sidebar-contact a { display:flex; align-items:center; gap:8px; padding:9px 14px; background:#166534; color:#fff; border-radius:8px; text-decoration:none; font-size:13px; font-weight:600; transition:background .18s; }
.sp-sidebar-contact a:hover { background:#0f5132; }

/* ── Content area ── */
.sp-content { background:#fff; border-radius:16px; border:1px solid #f3f4f6; padding:36px 40px; box-shadow:0 4px 16px rgba(0,0,0,.04); min-height:480px; }
.sp-content-head { margin-bottom:28px; padding-bottom:20px; border-bottom:1px solid #f3f4f6; }
.sp-content-head h1 { font-family:'Playfair Display',serif; font-size:26px; font-weight:700; color:#111827; margin:0 0 6px; }
.sp-content-head p  { font-size:14px; color:#6b7280; margin:0; }

/* Nội dung văn bản */
.sp-content h2 { font-size:18px; font-weight:700; color:#166534; margin:32px 0 12px; padding-left:12px; border-left:4px solid #166534; }
.sp-content h2:first-child { margin-top:0; }
.sp-content h3 { font-size:15px; font-weight:700; color:#111827; margin:20px 0 8px; }
.sp-content p  { font-size:14.5px; color:#374151; line-height:1.8; margin:0 0 14px; }
.sp-content ul, .sp-content ol { font-size:14.5px; color:#374151; line-height:1.8; padding-left:22px; margin:0 0 16px; }
.sp-content li { margin-bottom:6px; }
.sp-content strong { color:#111827; font-weight:700; }

/* FAQ accordion */
.sp-faq-item { border:1px solid #f3f4f6; border-radius:10px; margin-bottom:10px; overflow:hidden; }
.sp-faq-q { width:100%; background:none; border:none; display:flex; align-items:center; justify-content:space-between; padding:16px 20px; font-size:14.5px; font-weight:600; color:#111827; cursor:pointer; font-family:inherit; text-align:left; transition:background .18s; }
.sp-faq-q:hover { background:#f9fafb; }
.sp-faq-q.open { background:#f0fdf4; color:#166534; }
.sp-faq-q svg { width:18px; height:18px; stroke:currentColor; fill:none; stroke-width:2.5; stroke-linecap:round; flex-shrink:0; transition:transform .25s; }
.sp-faq-q.open svg { transform:rotate(180deg); }
.sp-faq-a { display:none; padding:0 20px 16px; font-size:14px; color:#374151; line-height:1.75; border-top:1px solid #f3f4f6; padding-top:14px; }
.sp-faq-a.open { display:block; }

/* Steps */
.sp-steps { counter-reset:step; margin:0 0 20px; padding:0; list-style:none; }
.sp-steps li { display:flex; gap:16px; align-items:flex-start; margin-bottom:20px; }
.sp-steps li::before { counter-increment:step; content:counter(step); flex-shrink:0; width:32px; height:32px; border-radius:50%; background:#166534; color:#fff; font-size:14px; font-weight:700; display:flex; align-items:center; justify-content:center; margin-top:1px; }

/* Info boxes */
.sp-box { border-radius:10px; padding:16px 20px; margin:0 0 16px; font-size:14px; line-height:1.7; }
.sp-box-green  { background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; }
.sp-box-amber  { background:#fffbeb; border:1px solid #fde68a; color:#92400e; }
.sp-box-blue   { background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; }

@media (max-width: 860px) {
  .sp-wrap { grid-template-columns:1fr; }
  .sp-sidebar { position:static; }
  .sp-content { padding:24px 20px; }
}
</style>
</head>
<body>

<!-- ══ NAVBAR ══ -->
<nav class="navbar" id="navbar">
  <div class="navbar-inner">
    <a href="index.php" class="brand">
      <img src="images/logo.png" alt="Logo" onerror="this.style.display='none'">
      <span class="brand-name">Mộc Trà</span>
    </a>
    <div class="nav-menu">
      <a href="index.php" class="nav-link">Trang chủ</a>
      <a href="products.php" class="nav-link">Sản phẩm</a>
      <a href="reviews.php" class="nav-link">Đánh giá</a>
      <a href="contact.php" class="nav-link">Liên hệ</a>
    </div>
    <div class="nav-right">
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
          <a href="profile.php"><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Hồ sơ của tôi</a>
          <a href="order_history.php"><svg viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="2"/></svg>Đơn hàng</a>
          <div class="divider"></div>
          <a href="logout.php" class="logout"><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>Đăng xuất</a>
        </div>
      </div>
      <?php else: ?>
      <a href="login.php" class="nav-icon-btn" title="Đăng nhập">
        <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      </a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<!-- Breadcrumb -->
<div class="sp-breadcrumb">
  <div class="sp-breadcrumb-inner">
    <a href="index.php">Trang chủ</a>
    <span class="sep">›</span>
    <a href="support.php">Hỗ trợ khách hàng</a>
    <span class="sep">›</span>
    <span class="cur"><?= htmlspecialchars($pageTitle) ?></span>
  </div>
</div>

<div class="sp-wrap">

  <!-- ── Sidebar ── -->
  <aside class="sp-sidebar">
    <div class="sp-sidebar-head">
      <h3>Trung tâm hỗ trợ</h3>
      <p>Mộc Trà Thái Nguyên</p>
    </div>
    <nav class="sp-nav">
      <?php foreach ($topics as $key => $meta): ?>
      <a href="support.php?topic=<?= $key ?>" class="<?= $topic === $key ? 'active' : '' ?>">
        <svg class="sp-nav-icon" viewBox="0 0 24 24"><path d="<?= $meta['icon'] ?>"/></svg>
        <?= $meta['title'] ?>
      </a>
      <?php endforeach; ?>
    </nav>
    <div class="sp-nav-divider"></div>
    <div class="sp-sidebar-contact">
      <p>Không tìm được câu trả lời?</p>
      <a href="contact.php">
        <svg style="width:16px;height:16px;stroke:#fff;fill:none;stroke-width:2;stroke-linecap:round;" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        Gửi yêu cầu hỗ trợ
      </a>
    </div>
  </aside>

  <!-- ── Content ── -->
  <main class="sp-content">
    <div class="sp-content-head">
      <h1><?= htmlspecialchars($pageTitle) ?></h1>
      <p>Cập nhật lần cuối: <?= date('d/m/Y') ?></p>
    </div>
    <?php
      if (file_exists($contentFile)) {
          include $contentFile;
      } else {
          echo '<p style="color:#6b7280;">Nội dung đang được cập nhật. Vui lòng quay lại sau.</p>';
      }
    ?>
  </main>

</div>

<script src="js/moctra-functions.js"></script>
<script>
(function(){
  var userBtn  = document.getElementById('userBtn');
  var userMenu = document.getElementById('userMenu');
  if (userBtn && userMenu) {
    userBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      userMenu.classList.toggle('open');
      userBtn.classList.toggle('open');
    });
    document.addEventListener('click', function() {
      userMenu.classList.remove('open');
      userBtn.classList.remove('open');
    });
  }
})();

/* FAQ accordion */
document.querySelectorAll('.sp-faq-q').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var answer = this.nextElementSibling;
    var isOpen = this.classList.contains('open');
    document.querySelectorAll('.sp-faq-q').forEach(function(b) {
      b.classList.remove('open');
      b.nextElementSibling.classList.remove('open');
    });
    if (!isOpen) {
      this.classList.add('open');
      answer.classList.add('open');
    }
  });
});
</script>
</body>
</html>
