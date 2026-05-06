<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();
require_once 'config/db.php';
require_once 'config/constants.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }
if ($_SESSION['role'] === 'admin') { header('Location: admin/dashboard.php'); exit(); }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$user_id     = (int)$_SESSION['user_id'];
$username    = htmlspecialchars($_SESSION['username'] ?? '');
$userInitial = strtoupper(substr($username, 0, 1));
$cats        = $conn->query("SELECT * FROM categories ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);

$today = date('Y-m-d');

/* ── Lấy tất cả voucher của user (riêng tư + công khai) ── */
$st = $conn->prepare("
    SELECT id, code, discount_type, discount_value, min_order,
           max_uses, used_count, expires_at, is_active, created_at,
           coupon_role, condition_type, condition_value
    FROM coupons
    WHERE (coupon_role = 'private' AND specific_user_id = ?)
       OR (coupon_role = 'public')
    ORDER BY is_active DESC, expires_at ASC, created_at DESC
");
$st->bind_param('i', $user_id);
$st->execute();
$all = $st->get_result()->fetch_all(MYSQLI_ASSOC);

$active  = [];
$used    = [];
$expired = [];

foreach ($all as $v) {
    $isExpired = $v['expires_at'] && $v['expires_at'] < $today;
    $isUsedUp  = $v['max_uses'] > 0 && $v['used_count'] >= $v['max_uses'];
    if (!$v['is_active'] || $isExpired) {
        $expired[] = $v;
    } elseif ($isUsedUp) {
        $used[]    = $v;
    } else {
        $active[]  = $v;
    }
}

function fmtVoucher(array $v): string {
    return $v['discount_type'] === 'percent'
        ? 'Giảm ' . $v['discount_value'] . '%'
        : 'Giảm ' . number_format($v['discount_value'], 0, ',', '.') . 'đ';
}
function fmtExp(?string $d): string {
    return $d ? date('d/m/Y', strtotime($d)) : 'Không hết hạn';
}
function fmtCondition(array $v): string {
    if (($v['condition_type'] ?? 'none') === 'min_spent') {
        return 'Chi tiêu tối thiểu ' . number_format($v['condition_value'], 0, ',', '.') . 'đ';
    }
    if (($v['condition_type'] ?? 'none') === 'new_member') {
        return 'Dành cho khách hàng mới';
    }
    if ($v['min_order'] > 0) {
        return 'Đơn từ ' . number_format($v['min_order'], 0, ',', '.') . 'đ';
    }
    return 'Mọi đơn hàng';
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
  <title>Kho Voucher — Mộc Trà Thái Nguyên</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Be+Vietnam+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/index.css">
  <link rel="stylesheet" href="css/components.css">
<style>
body { background:#f9fafb; }

/* BREADCRUMB */
.breadcrumb-bar { background: #fff; border-bottom: 1px solid #f3f4f6; padding: 12px 0; }
.breadcrumb-inner { max-width: 1000px; margin: 0 auto; padding: 0 24px; display: flex; align-items: center; gap: 8px; font-size: 13.5px; color: #6b7280; }
.breadcrumb-inner a { color: #6b7280; text-decoration: none; font-weight: 500; transition: color 0.2s; }
.breadcrumb-inner a:hover { color: #166534; }
.breadcrumb-inner .sep { font-size: 14px; color: #d1d5db; }
.breadcrumb-inner .current { color: #111827; font-weight: 600; }

.vk-wrap { max-width:1000px; margin:32px auto 60px; padding:0 24px; }
.vk-wrap h1 { font-family:'Playfair Display',serif; font-size:30px; font-weight:700; color:#111827; margin-bottom:8px; display:flex; align-items:center; gap:12px; }
.vk-subtitle { font-size:15px; color:#6b7280; margin-bottom:28px; }

/* TABS */
.vk-tabs { display:flex; gap:32px; border-bottom:1px solid #e5e7eb; margin-bottom:28px; }
.vk-tab { background:none; border:none; padding:0 0 12px; font-size:15px; font-weight:600; color:#6b7280; cursor:pointer; position:relative; font-family:inherit; transition:color .2s; }
.vk-tab:hover { color:#111827; }
.vk-tab.active { color:#b45309; }
.vk-tab.active::after { content:''; position:absolute; bottom:-1px; left:0; width:100%; height:2px; background:linear-gradient(90deg,#d97706,#fbbf24); border-radius:2px 2px 0 0; }
.vk-tab-count { background:#f3f4f6; color:#374151; font-size:12px; padding:2px 8px; border-radius:99px; margin-left:6px; font-weight:700; }
.vk-tab.active .vk-tab-count { background:#fef3c7; color:#92400e; }

.vk-pane { display:none; animation:fadeIn .3s ease; }
.vk-pane.active { display:block; }
@keyframes fadeIn { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:none} }

.vk-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:20px; }
.vk-card { background:#fff; border-radius:16px; border:1px solid #e5e7eb; padding:0; position:relative; transition:all .25s cubic-bezier(0.4, 0, 0.2, 1); }
.vk-card:hover { box-shadow:0 12px 36px rgba(200,150,12,.18); transform:translateY(-4px); border-color:#fde68a; }
.vk-card.vk-used, .vk-card.vk-expired { opacity:.75; filter:grayscale(.4); border-color:#e5e7eb; }
.vk-card:hover.vk-used, .vk-card:hover.vk-expired { transform:none; box-shadow:none; }
.vk-card-top { background:radial-gradient(circle,rgba(255,255,255,0.10) 1px,transparent 1px) 0 0/14px 14px,linear-gradient(135deg,#d97706,#b45309,#92400e); padding:24px 20px 20px; position:relative; border-radius:16px 16px 0 0; display:flex; flex-direction:column; justify-content:center; min-height:120px; }
.vk-card-top::before, .vk-card-top::after { content:''; position:absolute; bottom:-12px; width:24px; height:24px; background:#f9fafb; border-radius:50%; border:1px solid #e5e7eb; z-index:2; box-shadow:inset 0 2px 4px rgba(0,0,0,.04); }
.vk-card-top::before { left:-13px; border-right-color:transparent; border-top-color:transparent; transform:rotate(45deg); }
.vk-card-top::after { right:-13px; border-left-color:transparent; border-top-color:transparent; transform:rotate(-45deg); }
.vk-card-top.vk-used-bg  { background:radial-gradient(circle,rgba(255,255,255,0.08) 1px,transparent 1px) 0 0/14px 14px,linear-gradient(135deg,#6b7280,#4b5563); }
.vk-card-top.vk-exp-bg   { background:radial-gradient(circle,rgba(255,255,255,0.08) 1px,transparent 1px) 0 0/14px 14px,linear-gradient(135deg,#9ca3af,#6b7280); }
.vk-amount { font-family:'Playfair Display',serif; font-size:36px; font-weight:700; color:#fff; line-height:1; text-shadow:0 2px 6px rgba(0,0,0,.2); }
.vk-amount small { font-family:'Be Vietnam Pro',sans-serif; font-size:16px; font-weight:600; opacity:.9; margin-left:4px; text-shadow:none; }
.vk-code { display:inline-flex; align-items:center; gap:6px; margin-top:12px; background:rgba(255,255,255,.15); color:#fff; font-weight:700; font-size:14px; letter-spacing:1.5px; padding:6px 14px; border-radius:8px; border:1px dashed rgba(255,255,255,.35); backdrop-filter:blur(4px); align-self:flex-start; }
.vk-status-badge { position:absolute; top:16px; right:16px; font-size:11px; font-weight:700; padding:4px 10px; border-radius:99px; box-shadow:0 4px 12px rgba(0,0,0,.15); text-transform:uppercase; letter-spacing:0.5px; }
.vk-status-active  { background:#fef3c7; color:#78350f; box-shadow:0 2px 8px rgba(200,150,12,.20); }
.vk-status-used    { background:#fee2e2; color:#991b1b; box-shadow:none; }
.vk-status-expired { background:#e5e7eb; color:#4b5563; box-shadow:none; }
.vk-card-body { padding:22px 20px 20px; border-radius:0 0 16px 16px; background:#fff; position:relative; }
.vk-card-body::before { content:''; position:absolute; top:0; left:16px; right:16px; border-top:2px dashed #e5e7eb; }
.vk-detail-row { display:flex; justify-content:space-between; align-items:center; font-size:13px; color:#6b7280; margin-bottom:10px; }
.vk-detail-row strong { color:#111827; font-weight:700; font-size:13.5px; }
.vk-divider { display:none; }
.vk-actions { display:flex; gap:12px; margin-top:20px; }
.vk-copy-btn { flex:1; padding:10px; background:#fffbeb; color:#92400e; border:1.5px solid #fde68a; border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; font-family:inherit; transition:all .2s; display:flex; align-items:center; justify-content:center; gap:6px; }
.vk-copy-btn:hover { background:#fef3c7; border-color:#fbbf24; transform:translateY(-1px); }
.vk-copy-btn.copied { background:#b45309; color:#fff; border-color:#b45309; }
.vk-use-btn { flex:1; padding:10px; background:linear-gradient(135deg,#d97706,#b45309); color:#fff; border:none; border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; font-family:inherit; transition:all .2s; display:flex; align-items:center; justify-content:center; text-decoration:none; box-shadow:0 4px 12px rgba(200,150,12,.30); }
.vk-use-btn:hover { background:linear-gradient(135deg,#b45309,#92400e); transform:translateY(-1px); box-shadow:0 6px 18px rgba(200,150,12,.38); }
.vk-empty { text-align:center; padding:64px 24px; background:#fff; border-radius:16px; border:1.5px dashed #d1d5db; }
.vk-empty svg { width:56px; height:56px; stroke:#9ca3af; fill:none; stroke-width:1.5; margin-bottom:16px; }
.vk-empty p { color:#6b7280; font-size:14.5px; margin-top:8px; line-height:1.6; max-width:400px; margin-left:auto; margin-right:auto; }
.vk-empty a { display:inline-block; margin-top:20px; padding:10px 24px; background:linear-gradient(135deg,#d97706,#b45309); color:#fff; border-radius:8px; font-weight:600; text-decoration:none; transition:all .2s; box-shadow:0 4px 12px rgba(200,150,12,.28); }
.vk-empty a:hover { background:linear-gradient(135deg,#b45309,#92400e); transform:translateY(-1px); }
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
      <a href="reviews.php" class="nav-link">Đánh giá</a>
      <a href="contact.php" class="nav-link">Liên hệ</a>
      <a href="order_history.php" class="nav-link">Đơn hàng</a>
    </div>
    <div class="nav-right">
      <a href="cart.php" class="nav-icon-btn" data-nav-cart>
        <svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
        <span class="badge badge-cart" data-cart-badge></span>
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
          <a href="my_vouchers.php" style="color:#166534;font-weight:600">
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
    <a href="index.php">Trang chủ</a><span class="sep">›</span>
    <span class="current">Kho Voucher</span>
  </div>
</div>

<div class="vk-wrap">
  <h1>
    <svg viewBox="0 0 24 24" style="width:32px;height:32px;fill:none;stroke:#d97706;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round;"><path d="M20 12v10H4V12"/><path d="M22 7H2v5h20V7z"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>
    Kho Voucher của tôi
  </h1>
  <p class="vk-subtitle">Danh sách các mã ưu đãi hiện có dành cho tài khoản <strong><?= $username ?></strong>.</p>

  <div class="vk-tabs" id="vkTabs">
    <button class="vk-tab active" data-target="tab-active">
      Đang hiệu lực <span class="vk-tab-count"><?= count($active) ?></span>
    </button>
    <button class="vk-tab" data-target="tab-used">
      Đã sử dụng <span class="vk-tab-count"><?= count($used) ?></span>
    </button>
    <button class="vk-tab" data-target="tab-expired">
      Hết hạn / Vô hiệu <span class="vk-tab-count"><?= count($expired) ?></span>
    </button>
  </div>

  <?php if (empty($all)): ?>
  <div class="vk-empty">
    <svg viewBox="0 0 24 24"><path d="M20 12v10H4V12"/><path d="M22 7H2v5h20V7z"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>
    <h3 style="font-size:16px;font-weight:700;color:#374151;margin-bottom:6px">Chưa có voucher nào</h3>
    <p>Hãy mua sắm để nhận voucher ưu đãi đặc quyền từ Mộc Trà!</p>
    <a href="products.php">Mua sắm ngay</a>
  </div>
  <?php else: ?>

  <!-- Tab: Active -->
  <div class="vk-pane active" id="tab-active">
    <?php if (empty($active)): ?>
      <div class="vk-empty" style="padding:48px 24px;">
        <h3 style="font-size:15px;font-weight:600;color:#6b7280;">Không có voucher nào đang hiệu lực</h3>
      </div>
    <?php else: ?>
    <div class="vk-grid">
      <?php foreach ($active as $v): ?>
      <div class="vk-card">
        <div class="vk-card-top">
          <span class="vk-status-badge vk-status-active">Có thể dùng</span>
          <?php if (($v['coupon_role'] ?? '') === 'private'): ?>
            <span style="position:absolute; top:16px; left:20px; font-size:10px; font-weight:700; color:#14532d; background:rgba(255,255,255,.9); padding:3px 8px; border-radius:4px; text-transform:uppercase; letter-spacing:0.5px;">Mã riêng tư</span>
          <?php endif; ?>
          <div class="vk-amount" style="<?= (($v['coupon_role'] ?? '') === 'private') ? 'margin-top:16px;' : '' ?>">
            <?php if ($v['discount_type'] === 'percent'): ?>
              <?= $v['discount_value'] ?><small>%</small>
            <?php else: ?>
              <?= number_format($v['discount_value'], 0, ',', '.') ?><small>đ</small>
            <?php endif; ?>
          </div>
          <div class="vk-code"><?= htmlspecialchars($v['code']) ?></div>
        </div>
        <div class="vk-card-body">
          <div class="vk-detail-row"><span>Giảm</span><strong><?= fmtVoucher($v) ?></strong></div>
          <div class="vk-detail-row"><span>Điều kiện</span><strong><?= fmtCondition($v) ?></strong></div>
          <div class="vk-detail-row"><span>Hết hạn</span><strong><?= fmtExp($v['expires_at']) ?></strong></div>
          <div class="vk-actions">
            <button class="vk-copy-btn" onclick="copyCode(this,'<?= htmlspecialchars($v['code']) ?>')">
              <svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;margin-bottom:-1px;"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
              Lưu mã
            </button>
            <a href="products.php" class="vk-use-btn">Dùng ngay</a>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Tab: Used -->
  <div class="vk-pane" id="tab-used">
    <?php if (empty($used)): ?>
      <div class="vk-empty" style="padding:48px 24px;">
        <h3 style="font-size:15px;font-weight:600;color:#6b7280;">Bạn chưa sử dụng voucher nào</h3>
      </div>
    <?php else: ?>
    <div class="vk-grid">
      <?php foreach ($used as $v): ?>
      <div class="vk-card vk-used">
        <div class="vk-card-top vk-used-bg">
          <span class="vk-status-badge vk-status-used">Đã dùng</span>
          <?php if (($v['coupon_role'] ?? '') === 'private'): ?>
            <span style="position:absolute; top:16px; left:20px; font-size:10px; font-weight:700; color:#4b5563; background:rgba(255,255,255,.9); padding:3px 8px; border-radius:4px; text-transform:uppercase; letter-spacing:0.5px;">Mã riêng tư</span>
          <?php endif; ?>
          <div class="vk-amount" style="<?= (($v['coupon_role'] ?? '') === 'private') ? 'margin-top:16px;' : '' ?>">
            <?php if ($v['discount_type'] === 'percent'): ?>
              <?= $v['discount_value'] ?><small>%</small>
            <?php else: ?>
              <?= number_format($v['discount_value'], 0, ',', '.') ?><small>đ</small>
            <?php endif; ?>
          </div>
          <div class="vk-code"><?= htmlspecialchars($v['code']) ?></div>
        </div>
        <div class="vk-card-body">
          <div class="vk-detail-row"><span>Giảm</span><strong><?= fmtVoucher($v) ?></strong></div>
          <div class="vk-detail-row"><span>Điều kiện</span><strong><?= fmtCondition($v) ?></strong></div>
          <div class="vk-detail-row"><span>Trạng thái</span><strong>Đã sử dụng hết lượt</strong></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Tab: Expired -->
  <div class="vk-pane" id="tab-expired">
    <?php if (empty($expired)): ?>
      <div class="vk-empty" style="padding:48px 24px;">
        <h3 style="font-size:15px;font-weight:600;color:#6b7280;">Không có voucher hết hạn</h3>
      </div>
    <?php else: ?>
    <div class="vk-grid">
      <?php foreach ($expired as $v): ?>
      <div class="vk-card vk-expired">
        <div class="vk-card-top vk-exp-bg">
          <span class="vk-status-badge vk-status-expired">Hết hạn</span>
          <?php if (($v['coupon_role'] ?? '') === 'private'): ?>
            <span style="position:absolute; top:16px; left:20px; font-size:10px; font-weight:700; color:#4b5563; background:rgba(255,255,255,.9); padding:3px 8px; border-radius:4px; text-transform:uppercase; letter-spacing:0.5px;">Mã riêng tư</span>
          <?php endif; ?>
          <div class="vk-amount" style="<?= (($v['coupon_role'] ?? '') === 'private') ? 'margin-top:16px;' : '' ?>">
            <?php if ($v['discount_type'] === 'percent'): ?>
              <?= $v['discount_value'] ?><small>%</small>
            <?php else: ?>
              <?= number_format($v['discount_value'], 0, ',', '.') ?><small>đ</small>
            <?php endif; ?>
          </div>
          <div class="vk-code"><?= htmlspecialchars($v['code']) ?></div>
        </div>
        <div class="vk-card-body">
          <div class="vk-detail-row"><span>Giảm</span><strong><?= fmtVoucher($v) ?></strong></div>
          <div class="vk-detail-row"><span>Điều kiện</span><strong><?= fmtCondition($v) ?></strong></div>
          <div class="vk-detail-row"><span>Hết hạn</span><strong><?= fmtExp($v['expires_at']) ?></strong></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php endif; ?>
</div>

<script src="js/moctra-functions.js"></script>
<script>
// Tab Switching Logic
(function() {
  var tabs = document.querySelectorAll('.vk-tab');
  var panes = document.querySelectorAll('.vk-pane');

  tabs.forEach(function(tab) {
    tab.addEventListener('click', function() {
      tabs.forEach(function(t) { t.classList.remove('active'); });
      panes.forEach(function(p) { p.classList.remove('active'); });

      this.classList.add('active');
      document.getElementById(this.getAttribute('data-target')).classList.add('active');
    });
  });
})();

function copyCode(btn, code) {
  var successFeedback = function() {
    if (typeof window.showToast === 'function') {
      window.showToast('Đã lưu mã ' + code + ' vào bộ nhớ tạm!');
    }
    var orig = btn.innerHTML;
    btn.innerHTML = '<svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2.5;margin-bottom:-1px;"><polyline points="20 6 9 17 4 12"/></svg> Đã lưu';
    btn.classList.add('copied');
    setTimeout(function() {
      btn.innerHTML = orig;
      btn.classList.remove('copied');
    }, 2000);
  };

  if (navigator.clipboard) {
    navigator.clipboard.writeText(code).then(successFeedback).catch(fallbackCopy);
  } else {
    fallbackCopy();
  }

  function fallbackCopy() {
    var ta = document.createElement('textarea');
    ta.value = code;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
    successFeedback();
  }
}
</script>
</body>
</html>
