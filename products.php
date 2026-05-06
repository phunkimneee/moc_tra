<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();
require_once 'config/db.php';

$isLoggedIn  = isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'customer';
$isAdmin     = isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'admin';
$username    = $isLoggedIn ? htmlspecialchars($_SESSION['username'] ?? '') : '';
$userInitial = $isLoggedIn && $username ? strtoupper(substr($username, 0, 1)) : '';

/* ── Lấy danh mục ── */
$cats = $conn->query("SELECT * FROM categories ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);

/* ── Params từ URL ── */
/* Multi-category: ?cats[]=tra-xanh&cats[]=tra-den */
$cat_slugs   = array_filter(array_map('trim', (array)($_GET['cats'] ?? [])));
/* Backward compat: ?category=tra-xanh (single, từ dropdown navbar cũ) */
if (empty($cat_slugs) && !empty($_GET['category'])) {
    $cat_slugs = [trim($_GET['category'])];
}

$q           = trim($_GET['q']    ?? '');
$sort        = $_GET['sort']      ?? 'default';
$min_price   = (int)($_GET['min'] ?? 0);
$max_price   = (int)($_GET['max'] ?? 0);
$type_filter = $_GET['type']      ?? '';
$page        = max(1, (int)($_GET['page'] ?? 1));
$per_page    = 12;

/* ── Map slug → id cho multi-filter ── */
$slug_to_cat = [];
foreach ($cats as $c) { $slug_to_cat[$c['slug']] = $c; }

$selected_cat_ids   = [];
$selected_cat_names = [];
foreach ($cat_slugs as $s) {
    if (isset($slug_to_cat[$s])) {
        $selected_cat_ids[]   = (int)$slug_to_cat[$s]['id'];
        $selected_cat_names[] = $slug_to_cat[$s]['name'];
    }
}

/* ── Build WHERE ── */
$where  = ['1=1'];
$params = [];
$types  = '';

if ($selected_cat_ids) {
    /* Dùng IN (...) — an toàn vì chỉ chứa integer */
    $in = implode(',', $selected_cat_ids);
    $where[] = "p.category_id IN ($in)";
    /* Không cần bind_param cho phần này vì đã ép int */
}
if ($q !== '') {
    $where[]  = '(p.name LIKE ? OR p.origin LIKE ?)';
    $like     = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}
if ($min_price > 0) { $where[] = 'p.price >= ?'; $params[] = $min_price; $types .= 'i'; }
if ($max_price > 0) { $where[] = 'p.price <= ?'; $params[] = $max_price; $types .= 'i'; }
if ($type_filter !== '') { $where[] = 'p.type = ?'; $params[] = $type_filter; $types .= 's'; }

$where_sql = implode(' AND ', $where);

/* ── ORDER BY ── */
$order_sql = match($sort) {
    'price_asc'  => 'p.price ASC',
    'price_desc' => 'p.price DESC',
    'newest'     => 'p.created_at DESC',
    'featured'   => 'p.is_featured DESC, p.created_at DESC',
    default      => 'p.id DESC',
};

/* ── Count total ── */
$count_sql = "SELECT COUNT(*) FROM products p WHERE $where_sql";
if ($params) {
    $st = $conn->prepare($count_sql);
    $st->bind_param($types, ...$params);
    $st->execute();
    $total = $st->get_result()->fetch_row()[0];
} else {
    $total = $conn->query($count_sql)->fetch_row()[0];
}

$total_pages = max(1, ceil($total / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

/* ── Fetch products ── */
$sql = "SELECT p.*, c.name AS cat_name, c.slug AS cat_slug
        FROM products p
        JOIN categories c ON c.id = p.category_id
        WHERE $where_sql
        ORDER BY $order_sql
        LIMIT ? OFFSET ?";

$params[] = $per_page;
$params[] = $offset;
$types .= 'ii';

$st = $conn->prepare($sql);
$st->bind_param($types, ...$params);
$st->execute();
$products = $st->get_result()->fetch_all(MYSQLI_ASSOC);

/* ── Count per category (cho sidebar) ── */
$cat_counts = [];
$cc = $conn->query("SELECT category_id, COUNT(*) as cnt FROM products GROUP BY category_id");
while ($row = $cc->fetch_assoc()) {
    $cat_counts[$row['category_id']] = $row['cnt'];
}

/* ── Helper: build URL giữ params ── */
function buildUrl(array $overrides = []): string {
    global $cat_slugs, $q, $sort, $min_price, $max_price, $type_filter;
    /* Base từ state hiện tại */
    $base = [
        'q'    => $q,
        'sort' => $sort !== 'default' ? $sort : '',
        'min'  => $min_price ?: '',
        'max'  => $max_price ?: '',
        'type' => $type_filter,
        'page' => '',
    ];
    $merged = array_merge($base, $overrides);
    $merged = array_filter($merged, fn($v) => $v !== '' && $v !== null);

    /* Xử lý cats[] riêng (http_build_query tự handle array) */
    $cats_val = $overrides['cats'] ?? $cat_slugs;
    if (!empty($cats_val)) {
        $merged['cats'] = $cats_val;
    } else {
        unset($merged['cats']);
    }

    return 'products.php?' . http_build_query($merged);
}

function formatPrice(int $price): string {
    return number_format($price, 0, ',', '.') . 'đ';
}

$type_labels = ['la'=>'Trà lá','tui_loc'=>'Túi lọc','bot'=>'Bột','hop_qua'=>'Hộp quà'];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php if ($isLoggedIn): ?><meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"><?php endif; ?>
  <title>
    <?php
      if ($selected_cat_names) echo htmlspecialchars(implode(' + ', $selected_cat_names));
      elseif ($q) echo 'Tìm kiếm: ' . htmlspecialchars($q);
      else echo 'Sản phẩm';
    ?>
    — Mộc Trà Thái Nguyên
  </title>
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
        margin-right: 6px;
    }
    /* Mega-menu icon dots: neutralize global margin so the icon sits flush in its circle */
    .dd-dot i { margin: 0 !important; line-height: 1; vertical-align: middle; }
    .text-danger { color: #dc3545; font-weight: bold; margin-left: 2px; }

    /* Bug 2 Fix: Product card alignment */
    .product-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
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

    /* ── Multi-category checkbox trong sidebar ── */
    .cat-check-item {
      display: flex; align-items: center; gap: 0;
      cursor: pointer; border-radius: 8px;
      transition: background .15s;
    }
    .cat-check-item input[type=checkbox] { display: none; }
    .cat-check-item > span:first-of-type {
      flex: 1; display: flex; align-items: center;
      padding: 7px 10px; font-size: 13.5px;
      color: var(--gray-700, #374151);
    }
    .cat-check-item .cat-count {
      font-size: 11px; background: var(--gray-100, #f3f4f6);
      color: var(--gray-500, #6b7280); border-radius: 20px;
      padding: 2px 7px; margin-right: 8px; white-space: nowrap;
    }
    .cat-check-item:hover { background: var(--gray-50, #f9fafb); }
    .cat-check-item.active > span:first-of-type {
      color: var(--green-700, #15803d); font-weight: 600;
    }
    .cat-check-item.active .cat-count {
      background: var(--green-100, #dcfce7);
      color: var(--green-700, #15803d);
    }
    /* ── Ẩn hoàn toàn view-btns nếu CSS cũ còn khai báo ── */
    .view-btns { display: none !important; }
  </style>
</head>
<body>

<!-- ══ NAVBAR (dùng lại từ index) ══ -->
<nav class="navbar" id="navbar">
  <div class="navbar-inner">
    <a href="index.php" class="brand">
      <img src="images/logo.png" alt="Logo" onerror="this.style.display='none'">
    </a>
    <div class="nav-menu">
      <a href="index.php" class="nav-link">Trang chủ</a>
      <div class="nav-dropdown">
        <button id="catBtn" class="nav-link active" style="display:flex;align-items:center;gap:4px;">
          Danh mục
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div id="catMenu" class="nav-dropdown-menu">
          <div class="dd-col" style="flex:1">
            <h4>Loại trà</h4>
            <?php foreach($cats as $c): ?>
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
            <a href="products.php?q=Ấn+Độ">🇮🇳 Ấn Độ</a>
          </div>
        </div>
      </div>
      <a href="index.php#products-section" class="nav-link">Sản phẩm</a>
      <a href="index.php#about" class="nav-link">Giới thiệu</a>
      <a href="reviews.php" class="nav-link">Đánh giá</a>
      <a href="contact.php" class="nav-link">Liên hệ</a>
    </div>
    <div class="nav-search-wrapper">
      <div class="nav-search">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <form action="products.php" method="GET" style="display:flex; flex:1; margin:0; gap:8px;" onsubmit="event.preventDefault(); var v=this.q.value.trim(); if(v){ var url=new URL('products.php', window.location.href); url.searchParams.set('q', v); <?php if(!empty($cat_slugs)): ?>url.searchParams.set('category', '<?= htmlspecialchars($cat_slugs[0]) ?>');<?php endif; ?> window.location.href=url.toString(); }">
          <input type="text" name="q" placeholder="Tìm kiếm sản phẩm..." value="<?= htmlspecialchars($q) ?>" style="flex:1;" autocomplete="off">
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
      <a href="admin/dashboard.php" title="Về trang quản trị"
   style="display: inline-flex; align-items: center; justify-content: center; gap: 6px; width: auto; height: auto; background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; border-radius: 6px; padding: 8px 16px; font-size: 14px; font-weight: 700; white-space: nowrap; text-decoration: none;">
   ⚙ Admin
    
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
    <a href="products.php">Danh mục</a>
    <?php if ($selected_cat_names): ?>
      <span class="sep">›</span>
      <span class="current"><?= htmlspecialchars(implode(' + ', $selected_cat_names)) ?></span>
    <?php elseif ($q): ?>
      <span class="sep">›</span>
      <span class="current">Tìm kiếm: "<?= htmlspecialchars($q) ?>"</span>
    <?php elseif ($type_filter && isset($type_labels[$type_filter])): ?>
      <span class="sep">›</span>
      <span class="current"><?= $type_labels[$type_filter] ?></span>
    <?php else: ?>
      <span class="sep">›</span>
      <span class="current">Tất cả sản phẩm</span>
    <?php endif; ?>
  </div>
</div>

<!-- ══ PAGE LAYOUT ══ -->
<div class="page-inner">

  <!-- SIDEBAR -->
  <aside class="sidebar">

    <!-- Danh mục — MULTI CHECKBOX -->
    <div class="sidebar-section">
      <div class="sidebar-title">Danh mục</div>
      <form method="GET" action="products.php" id="catForm">
        <?php if($q): ?><input type="hidden" name="q" value="<?= htmlspecialchars($q) ?>"><?php endif; ?>
        <?php if($sort !== 'default'): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>"><?php endif; ?>
        <?php if($type_filter): ?><input type="hidden" name="type" value="<?= htmlspecialchars($type_filter) ?>"><?php endif; ?>

        <ul class="cat-list">
          <li>
            <label class="cat-check-item <?= empty($cat_slugs) ? 'active' : '' ?>">
              <input type="checkbox" name="_all" value="1"
                <?= empty($cat_slugs) ? 'checked' : '' ?>
                onchange="document.querySelectorAll('[name=\'cats[]\']').forEach(c => c.checked = false); this.checked = true; document.getElementById('catForm').submit();">
              <span><span class="cat-icon"><i class="fa-solid fa-shop"></i></span>Tất cả sản phẩm</span>
              <span class="cat-count"><?= array_sum($cat_counts) ?></span>
            </label>
          </li>
          <?php foreach ($cats as $c): ?>
          <li>
            <label class="cat-check-item <?= in_array($c['slug'], $cat_slugs) ? 'active' : '' ?>">
              <input type="checkbox" name="cats[]" value="<?= $c['slug'] ?>"
                <?= in_array($c['slug'], $cat_slugs) ? 'checked' : '' ?>
                onchange="document.querySelectorAll('[name=\'cats[]\'], [name=_all]').forEach(c => c.checked = false); this.checked = true; document.getElementById('catForm').submit();">
              <span><span class="cat-icon"><i class="<?= htmlspecialchars($c['icon']) ?>"></i></span><?= htmlspecialchars($c['name']) ?></span>
              <span class="cat-count"><?= $cat_counts[$c['id']] ?? 0 ?></span>
            </label>
          </li>
          <?php endforeach; ?>
        </ul>
      </form>
    </div>

    <!-- Dạng sản phẩm -->
    <div class="sidebar-section">
      <div class="sidebar-title">Dạng sản phẩm</div>
      <form method="GET" action="products.php" id="typeForm">
        <?php foreach($cat_slugs as $s): ?>
          <input type="hidden" name="cats[]" value="<?= htmlspecialchars($s) ?>">
        <?php endforeach; ?>
        <?php if($q): ?><input type="hidden" name="q" value="<?= htmlspecialchars($q) ?>"><?php endif; ?>
        <?php if($sort !== 'default'): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>"><?php endif; ?>

        <div class="filter-group">
          <?php foreach ($type_labels as $val => $lbl): ?>
          <label class="filter-item">
            <input type="checkbox" name="type" value="<?= $val ?>"
              <?= $type_filter === $val ? 'checked' : '' ?>
              onchange="var v=this.checked; document.querySelectorAll('[name=type]').forEach(c=>c.checked=false); this.checked=v; document.getElementById('typeForm').submit()">
            <span class="filter-check"></span>
            <?= $lbl ?>
          </label>
          <?php endforeach; ?>
        </div>
      </form>
    </div>

    <!-- Khoảng giá -->
    <div class="sidebar-section">
      <div class="sidebar-title">Khoảng giá</div>
      <form method="GET" action="products.php">
        <?php foreach($cat_slugs as $s): ?>
          <input type="hidden" name="cats[]" value="<?= htmlspecialchars($s) ?>">
        <?php endforeach; ?>
        <?php if($q): ?><input type="hidden" name="q" value="<?= htmlspecialchars($q) ?>"><?php endif; ?>
        <?php if($type_filter): ?><input type="hidden" name="type" value="<?= htmlspecialchars($type_filter) ?>"><?php endif; ?>
        <div class="price-inputs">
          <input type="number" name="min" placeholder="Từ"  value="<?= $min_price ?: '' ?>" min="0" step="10000">
          <span>–</span>
          <input type="number" name="max" placeholder="Đến" value="<?= $max_price ?: '' ?>" min="0" step="10000">
        </div>
        <button type="submit" class="btn-filter-apply">Áp dụng</button>
      </form>
    </div>

    <!-- Xuất xứ -->
    <div class="sidebar-section">
      <div class="sidebar-title">Xuất xứ</div>
      <div class="filter-group">
        <?php foreach (['Việt Nam','Nhật Bản','Đài Loan','Ấn Độ'] as $origin): ?>
        <a href="products.php?q=<?= urlencode($origin) ?>" style="display:flex;align-items:center;gap:8px;padding:6px 0;font-size:13.5px;color:var(--gray-700);text-decoration:none;transition:color .15s"
           <?= $q === $origin ? 'style="color:var(--green-700);font-weight:700"' : '' ?>>
          <?= $origin ?>
          <?php if($q === $origin): ?> <span style="color:var(--green-600)">✓</span><?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>

  </aside>

  <!-- MAIN -->
  <main class="products-main">

    <!-- Toolbar -->
    <div class="toolbar">
      <div class="toolbar-left">
        <h1>
          <?php if ($selected_cat_names): ?>
            <?= htmlspecialchars(implode(' + ', $selected_cat_names)) ?>
          <?php elseif ($q): ?>
            Kết quả tìm kiếm: "<?= htmlspecialchars($q) ?>"
          <?php else: ?>
            Tất cả sản phẩm
          <?php endif; ?>
        </h1>
        <p>Tìm thấy <?= $total ?> sản phẩm</p>
      </div>
      <div class="toolbar-right">
        <!-- Sort dropdown: dùng JS build URL đúng với cats[] array -->
        <select class="sort-select" id="sortSelect">
          <option value="default"    <?= $sort==='default'    ? 'selected':'' ?>>Mặc định</option>
          <option value="newest"     <?= $sort==='newest'     ? 'selected':'' ?>>Mới nhất</option>
          <option value="featured"   <?= $sort==='featured'   ? 'selected':'' ?>>Nổi bật</option>
          <option value="price_asc"  <?= $sort==='price_asc'  ? 'selected':'' ?>>Giá tăng dần</option>
          <option value="price_desc" <?= $sort==='price_desc' ? 'selected':'' ?>>Giá giảm dần</option>
        </select>
        <!-- View buttons đã bị xóa theo yêu cầu -->
      </div>
    </div>

    <!-- Active filter chips -->
    <?php if ($cat_slugs || $q || $min_price || $max_price || $type_filter): ?>
    <div class="active-filters">
      <?php foreach ($cat_slugs as $s): ?>
        <?php $cn = $slug_to_cat[$s]['name'] ?? $s; ?>
        <?php $newSlugs = array_values(array_filter($cat_slugs, fn($x) => $x !== $s)); ?>
        <span class="filter-chip">
          <?= htmlspecialchars($cn) ?>
          <a href="<?= buildUrl(['cats' => $newSlugs, 'page' => '']) ?>">✕</a>
        </span>
      <?php endforeach; ?>
      <?php if ($q): ?>
        <span class="filter-chip">Tìm: "<?= htmlspecialchars($q) ?>" <a href="<?= buildUrl(['q'=>'','page'=>'']) ?>">✕</a></span>
      <?php endif; ?>
      <?php if ($type_filter): ?>
        <span class="filter-chip"><?= $type_labels[$type_filter] ?? $type_filter ?> <a href="<?= buildUrl(['type'=>'','page'=>'']) ?>">✕</a></span>
      <?php endif; ?>
      <?php if ($min_price || $max_price): ?>
        <span class="filter-chip">
          <?= $min_price ? formatPrice($min_price) : '0đ' ?> – <?= $max_price ? formatPrice($max_price) : '...' ?>
          <a href="<?= buildUrl(['min'=>'','max'=>'','page'=>'']) ?>">✕</a>
        </span>
      <?php endif; ?>
      <a href="products.php" style="font-size:13px;color:var(--red-sale);text-decoration:none;padding:5px 10px;">Xóa tất cả</a>
    </div>
    <?php endif; ?>

    <!-- Product grid -->
    <?php if (empty($products)): ?>
    <div class="empty-state">
      <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <h3>Không tìm thấy sản phẩm</h3>
      <p>Thử thay đổi bộ lọc hoặc từ khóa tìm kiếm.</p>
    </div>
    <?php else: ?>
    <div class="product-grid" id="productGrid">
      <?php foreach ($products as $p):
        $tag = '';
        if ($p['is_featured']) $tag = ['label'=>'Nổi bật','class'=>'tag-best'];
        if ($p['is_new'])      $tag = ['label'=>'Mới','class'=>'tag-new'];
        if ($p['price_old'])   $tag = ['label'=>'Sale','class'=>'tag-sale'];
        $pct = $p['price_old'] ? '-'.round((1 - $p['price']/($p['price_old'] ?: 1))*100).'%' : '';
      ?>
      <a class="pcard" href="product_detail.php?id=<?= $p['id'] ?>">
        <button class="pcard-wish" data-action="wishlist" data-product-id="<?= $p['id'] ?>" data-product-name="<?= htmlspecialchars($p['name']) ?>" data-product-price="<?= (int)$p['price'] ?>" data-product-image="images/<?= htmlspecialchars($p['image'] ?? 'logo.png') ?>" data-product-url="product_detail.php?id=<?= $p['id'] ?>" onclick="event.preventDefault();event.stopPropagation()">
          <svg viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
        </button>
        <div class="pcard-img">
          <img src="images/<?= htmlspecialchars($p['image'] ?? 'logo.png') ?>"
               alt="<?= htmlspecialchars($p['name']) ?>"
               onerror="this.onerror=null;this.src='images/logo.png'">
          <?php if ($tag): ?>
            <span class="pcard-tag <?= $tag['class'] ?>"><?= $tag['label'] ?></span>
          <?php endif; ?>
        </div>
        <div class="pcard-body">
          <?php if ($p['origin']): ?>
            <div class="pcard-origin"><?= htmlspecialchars($p['origin']) ?></div>
          <?php endif; ?>
          <div class="pcard-name"><?= htmlspecialchars($p['name']) ?></div>
          <div class="pcard-meta">
            <?php if ($p['weight']): ?>
              <span class="meta-pill"><?= htmlspecialchars($p['weight']) ?></span>
            <?php endif; ?>
            <?php if ($p['type'] && isset($type_labels[$p['type']])): ?>
              <span class="meta-pill"><?= $type_labels[$p['type']] ?></span>
            <?php endif; ?>
          </div>
          <div class="price-row">
            <span class="price-new"><?= formatPrice($p['price']) ?></span>
            <?php if ($p['price_old']): ?>
              <span class="price-old"><?= formatPrice($p['price_old']) ?></span>
              <span class="price-pct"><?= $pct ?></span>
            <?php endif; ?>
          </div>
          <button class="btn-add" data-action="cart" data-product-id="<?= $p['id'] ?>" data-product-name="<?= htmlspecialchars($p['name']) ?>" data-product-price="<?= (int)$p['price'] ?>" data-product-image="images/<?= htmlspecialchars($p['image'] ?? 'logo.png') ?>" data-product-url="product_detail.php?id=<?= $p['id'] ?>" onclick="event.preventDefault();event.stopPropagation()">
            <svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
            Thêm giỏ hàng
          </button>
        </div>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination">
      <a href="<?= buildUrl(['page' => $page-1]) ?>"
         class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">‹</a>
      <?php for ($i = 1; $i <= $total_pages; $i++):
        if ($i === 1 || $i === $total_pages || abs($i - $page) <= 2): ?>
          <a href="<?= buildUrl(['page' => $i]) ?>"
             class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
        <?php elseif (abs($i - $page) === 3): ?>
          <span class="page-btn disabled" style="border:none;background:none">…</span>
        <?php endif; ?>
      <?php endfor; ?>
      <a href="<?= buildUrl(['page' => $page+1]) ?>"
         class="page-btn <?= $page >= $total_pages ? 'disabled' : '' ?>">›</a>
    </div>
    <?php endif; ?>
    <?php endif; ?>

  </main>
</div>

<script>
(function(){
  var catBtn  = document.getElementById('catBtn');
  var catMenu = document.getElementById('catMenu');
  var userBtn = document.getElementById('userBtn');
  var userMenu= document.getElementById('userMenu');
  function closeAll(){
    catMenu && catMenu.classList.remove('open');
    userMenu&& userMenu.classList.remove('open');
    catBtn  && catBtn.classList.remove('open');
    userBtn && userBtn.classList.remove('open');
  }
  catBtn && catBtn.addEventListener('click',function(e){
    e.stopPropagation();
    var o=catMenu.classList.contains('open'); closeAll();
    if(!o){catMenu.classList.add('open');catBtn.classList.add('open');}
  });
  userBtn && userBtn.addEventListener('click',function(e){
    e.stopPropagation();
    var o=userMenu.classList.contains('open'); closeAll();
    if(!o){userMenu.classList.add('open');userBtn.classList.add('open');}
  });
  document.addEventListener('click',closeAll);
  catMenu && catMenu.addEventListener('click',function(e){e.stopPropagation();});
  userMenu&& userMenu.addEventListener('click',function(e){e.stopPropagation();});

  /* ── Sort dropdown: giữ nguyên cats[] params ── */
  var sortSel = document.getElementById('sortSelect');
  sortSel && sortSel.addEventListener('change', function() {
    var url = new URL(window.location.href);
    /* Giữ lại tất cả params hiện tại, chỉ đổi sort */
    if (this.value === 'default') {
      url.searchParams.delete('sort');
    } else {
      url.searchParams.set('sort', this.value);
    }
    url.searchParams.delete('page'); /* reset về trang 1 */
    window.location.href = url.toString();
  });

  window.addEventListener('scroll',function(){
    var nb=document.getElementById('navbar');
    if(nb) nb.style.boxShadow=window.scrollY>30?'0 4px 20px rgba(0,0,0,0.14)':'0 2px 12px rgba(0,0,0,0.06)';
  });
})();
</script>
<script src="js/moctra-functions.js"></script>
<script src="js/search-suggestion.js"></script>
</body>
</html>


