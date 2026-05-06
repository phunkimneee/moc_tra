<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? 'Admin') ?> — Mộc Trà Admin</title>
  <!-- Font Awesome 6 -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/admin.css">
  <style>
    /* Global Icon Styles */
    i.fa-solid, i.fa-regular, i.fa-brands {
        color: #2d5a27;
        transition: color 0.3s ease, transform 0.3s ease;
        margin-right: 8px;
    }
    .btn-edit:hover i { color: #ffc107 !important; }
    .btn-delete:hover i { color: #dc3545 !important; }
  </style>
  <?= $extraHead ?? '' ?>
</head>
<body>
<div class="admin-layout">

<!-- ══ SIDEBAR ══ -->
<aside class="sidebar">
  <div class="sidebar-brand">
    <img src="../images/logo.png" alt="Logo" onerror="this.style.display='none'">
    <div class="brand-txt">
      <div class="brand-name">Mộc Trà</div>
      <div class="brand-role">Quản trị viên</div>
    </div>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section-title">Tổng quan</div>
    <a href="dashboard.php"
       class="nav-item <?= ($activePage??'')==='dashboard' ? 'active':'' ?>">
      <i class="fa-solid fa-gauge"></i>
      Tổng quan
    </a>
    <a href="reports.php"
       class="nav-item <?= ($activePage??'')==='reports' ? 'active':'' ?>">
      <i class="fa-solid fa-chart-line"></i>
      Báo cáo doanh thu
    </a>

    <div class="nav-section-title">Kinh doanh</div>
    <a href="orders.php"
       class="nav-item <?= ($activePage??'')==='orders' ? 'active':'' ?>">
      <i class="fa-solid fa-receipt"></i>
      Quản lý đơn hàng
      <?php if (!empty($pendingCount) && $pendingCount > 0): ?>
        <span class="badge-count"><?= $pendingCount ?></span>
      <?php endif; ?>
    </a>
    <a href="products.php"
       class="nav-item <?= ($activePage??'')==='products' ? 'active':'' ?>">
      <i class="fa-solid fa-leaf"></i>
      Quản lý sản phẩm
    </a>
    <a href="categories.php"
       class="nav-item <?= ($activePage??'')==='categories' ? 'active':'' ?>">
      <i class="fa-solid fa-tags"></i>
      Quản lý danh mục
    </a>
    <a href="inventory.php"
       class="nav-item <?= ($activePage??'')==='inventory' ? 'active':'' ?>">
      <i class="fa-solid fa-warehouse"></i>
      Quản lý tồn kho
    </a>
    <a href="coupons.php"
       class="nav-item <?= ($activePage??'')==='coupons' ? 'active':'' ?>">
      <i class="fa-solid fa-ticket"></i>
      Quản lý mã giảm giá
    </a>

    <a href="reviews.php"
       class="nav-item <?= ($activePage??'')==='reviews' ? 'active':'' ?>">
      <i class="fa-solid fa-star"></i>
      Quản lý đánh giá
    </a>

    <div class="nav-section-title">Người dùng</div>
    <a href="users.php"
       class="nav-item <?= ($activePage??'')==='users' ? 'active':'' ?>">
      <i class="fa-solid fa-users"></i>
      Quản lý khách hàng
    </a>
    <a href="contacts.php"
       class="nav-item <?= ($activePage??'')==='contacts' ? 'active':'' ?>">
      <i class="fa-solid fa-comment-dots"></i>
      Phản hồi / Liên hệ
      <span class="badge-count" id="sidebarContactsBadge"
            style="<?= $__newContacts > 0 ? '' : 'display:none' ?>"><?= $__newContacts ?></span>
    </a>
  </nav>

  <div class="sidebar-footer">
    <a href="../index.php" class="nav-item" target="_blank">
      <i class="fa-solid fa-display"></i>
      Xem trang shop
    </a>
    <a href="logout.php" class="nav-item">
      <i class="fa-solid fa-right-from-bracket"></i>
      Đăng xuất
    </a>
  </div>
  </aside>

  <!-- ══ TOPBAR ══ -->
  <div class="topbar">
  <div class="topbar-left">
    <div class="topbar-title"><?= htmlspecialchars($pageTitle ?? '') ?></div>
    <div class="breadcrumb">
      <a href="dashboard.php">Admin</a>
      <?php if (!empty($breadcrumb)): foreach($breadcrumb as $b): ?>
        <span>›</span>
        <?php if (isset($b['url'])): ?>
          <a href="<?= htmlspecialchars($b['url']) ?>"><?= htmlspecialchars($b['label']) ?></a>
        <?php else: ?>
          <span><?= htmlspecialchars($b['label']) ?></span>
        <?php endif; ?>
      <?php endforeach; endif; ?>
    </div>
  </div>
  <div class="topbar-right">
    <div class="admin-avatar"><?= $adminInitial ?></div>
    <div class="admin-name"><?= $adminName ?></div>
  </div>
  </div>

  <!-- ══ ADMIN SSE SCRIPT ══ -->
  <script>
  (function() {
      if (typeof EventSource !== "undefined") {
          var source = new EventSource("api/sse_admin.php");
          
          source.onmessage = function(event) {
              try {
                  var data = JSON.parse(event.data);
                  
                  // Update Badge Count
                  if (data.badge_count !== undefined) {
                      var badgeEl = document.getElementById('sidebarContactsBadge');
                      if (badgeEl) {
                          if (data.badge_count > 0) {
                              badgeEl.textContent = data.badge_count;
                              badgeEl.style.display = '';
                          } else {
                              badgeEl.style.display = 'none';
                          }
                      }
                  }
                  if (data.contacts_count !== undefined) {
                      var tMsg = document.getElementById('tabBadgeMessages');
                      if (tMsg) {
                          if (data.contacts_count > 0) {
                              tMsg.textContent = data.contacts_count;
                              tMsg.style.display = '';
                          } else {
                              tMsg.style.display = 'none';
                          }
                      }
                  }
                  if (data.reviews_count !== undefined) {
                      var tRev = document.getElementById('tabBadgeReviews');
                      if (tRev) {
                          if (data.reviews_count > 0) {
                              tRev.textContent = data.reviews_count;
                              tRev.style.display = '';
                          } else {
                              tRev.style.display = 'none';
                          }
                      }
                  }
                  
                  // Auto reload if on contacts page and new contact arrives
                  if (data.new_contact && window.location.pathname.indexOf('contacts.php') !== -1) {
                      if (!document.querySelector('.admin-toast-show')) { // avoid reload if toast is showing or user is interacting
                          window.location.reload();
                      }
                  }
                  
                  // Auto reload if on reviews page and new review arrives
                  if (data.new_review && window.location.pathname.indexOf('reviews.php') !== -1) {
                      if (!document.querySelector('.admin-toast-show')) {
                          window.location.reload();
                      }
                  }
                  
              } catch (e) {
                  console.error("SSE parse error", e);
              }
          };
      }
  })();
  </script>

<!-- ══ MAIN ══ -->
<main class="main-content">
  <div class="page-body">
