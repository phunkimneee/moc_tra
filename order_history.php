<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();
require_once 'config/db.php';
require_once __DIR__ . '/config/payment.php';

// Tạo CSRF token nếu chưa có
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }
if ($_SESSION['role'] === 'admin') { header('Location: admin/dashboard.php'); exit(); }

$user_id     = (int)$_SESSION['user_id'];
$username    = htmlspecialchars($_SESSION['username'] ?? '');
$userInitial = strtoupper(substr($username, 0, 1));
$cats        = $conn->query("SELECT * FROM categories ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);

/* ── Params ── */
$focus_order_id = (int)($_GET['order_id'] ?? 0);
$is_new         = !empty($_GET['new']);
$filter_status  = $_GET['status'] ?? '';

/* ── Cart / wish count cho navbar ── */
$cart_count = array_sum(array_column($_SESSION['cart'] ?? [], 'qty'));
$wish_count = 0;
// Tạo bảng wishlist nếu chưa có
$conn->query("CREATE TABLE IF NOT EXISTS wishlist (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_product (user_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$wc = $conn->prepare("SELECT COUNT(*) FROM wishlist WHERE user_id=?");
if ($wc) {
    $wc->bind_param('i', $user_id);
    $wc->execute();
    $wish_count = (int)($wc->get_result()->fetch_row()[0] ?? 0);
}

/* ── Lấy tất cả đơn hàng của user ── */
$where_sql = 'user_id = ?';
$params    = [$user_id];
$types     = 'i';

// Logic Tab Hoàn thành: Hiển thị cả 'delivered' và 'reviewed'
if ($filter_status === 'delivered') {
    $where_sql .= " AND (status = 'delivered' OR status = 'reviewed')";
} elseif ($filter_status) {
    $where_sql .= ' AND status = ?';
    $params[]   = $filter_status;
    $types     .= 's';
}

$st = $conn->prepare("SELECT * FROM orders WHERE $where_sql ORDER BY created_at DESC");
$st->bind_param($types, ...$params);
$st->execute();
$orders = $st->get_result()->fetch_all(MYSQLI_ASSOC);

/* ── Đếm theo trạng thái (cho tabs) ── */
$count_st = $conn->prepare("SELECT status, COUNT(*) as cnt FROM orders WHERE user_id=? GROUP BY status");
$count_st->bind_param('i', $user_id);
$count_st->execute();
$counts_raw = $count_st->get_result()->fetch_all(MYSQLI_ASSOC);
$counts = ['pending'=>0,'processing'=>0,'shipping'=>0,'delivered'=>0,'reviewed'=>0,'cancelled'=>0];
foreach ($counts_raw as $r) $counts[$r['status']] = (int)$r['cnt'];
$total_orders = array_sum($counts);
$completed_count = $counts['delivered'] + $counts['reviewed'];

/* ── Lấy items cho từng đơn hàng (batch query) ── */
$order_ids  = array_column($orders, 'id');
$items_map  = [];
if ($order_ids) {
    $in    = implode(',', $order_ids);
    $ires  = $conn->query(
        "SELECT oi.*, p.image
         FROM order_items oi
         LEFT JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id IN ($in)"
    );
    while ($row = $ires->fetch_assoc()) {
        $items_map[$row['order_id']][] = $row;
    }
}

/* ── Lấy chi tiết đơn focus (nếu có) ── */
$focus_order       = null;
$focus_items       = [];
if ($focus_order_id) {
    $fo = $conn->prepare("SELECT * FROM orders WHERE id=? AND user_id=? LIMIT 1");
    $fo->bind_param('ii', $focus_order_id, $user_id);
    $fo->execute();
    $focus_order = $fo->get_result()->fetch_assoc();
    if ($focus_order) $focus_items = $items_map[$focus_order_id] ?? [];
}

function fmt(int $n): string { return number_format($n, 0, ',', '.') . 'đ'; }

$status_cfg = [
    'pending'   => ['label'=>'Chờ xác nhận', 'color'=>'#f59e0b', 'bg'=>'#fffbeb', 'step'=>0],
    'processing'=> ['label'=>'Chờ lấy hàng', 'color'=>'#3b82f6', 'bg'=>'#eff6ff', 'step'=>1],
    'shipping'  => ['label'=>'Chờ giao hàng','color'=>'#8b5cf6', 'bg'=>'#f5f3ff', 'step'=>2],
    'delivered' => ['label'=>'Chờ đánh giá', 'color'=>'#16a34a', 'bg'=>'#f0fdf4', 'step'=>3],
    'reviewed'  => ['label'=>'Đã đánh giá',  'color'=>'#059669', 'bg'=>'#ecfdf5', 'step'=>4],
    'cancelled' => ['label'=>'Đã hủy',       'color'=>'#dc2626', 'bg'=>'#fef2f2', 'step'=>-1],
];
$pay_labels = ['cod'=>'Thanh toán khi nhận','momo'=>'Ví MoMo','bank'=>'Thẻ ATM/Tín dụng'];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
  <title>Đơn hàng của tôi — Mộc Trà Thái Nguyên</title>
  <!-- Font Awesome 6 -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Be+Vietnam+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/index.css">
  <link rel="stylesheet" href="css/products.css">
<style>
/* Global Icon Styles */
i.fa-solid, i.fa-regular, i.fa-brands {
    color: #2d5a27;
    transition: color 0.3s ease, transform 0.3s ease;
    margin-right: 8px;
}
.text-danger { color: #dc3545; font-weight: bold; margin-left: 2px; }

body { background:#f9fafb; }

/* ── LAYOUT ── */
.orders-wrap {
  max-width: 1000px;
  margin: 32px auto 60px;
  padding: 0 36px;
}
.orders-wrap h1 {
  font-family: 'Playfair Display', serif;
  font-size: 26px; font-weight: 700; color: #111827;
  margin-bottom: 6px;
}
.orders-wrap .sub { font-size: 14px; color: #6b7280; margin-bottom: 24px; }

.purchase-hub {
  background: #fff;
  border: 1px solid #f3f4f6;
  border-radius: 18px;
  padding: 28px 30px;
  margin-bottom: 24px;
  box-shadow: 0 10px 30px rgba(15,81,50,.05);
}
.purchase-hub-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 24px;
  flex-wrap: wrap;
}
.purchase-hub-head h2 {
  font-size: 22px;
  font-weight: 700;
  color: #111827;
}
.purchase-hub-link {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  color: #374151;
  text-decoration: none;
  font-size: 14px;
  font-weight: 600;
}
.purchase-hub-link:hover { color: #166534; }
.purchase-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
}
.purchase-card {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  padding: 20px 14px 16px;
  border-radius: 16px;
  text-decoration: none;
  color: #111827;
  border: 1px solid transparent;
  transition: transform .18s, border-color .18s, background .18s;
}
.purchase-card:hover {
  transform: translateY(-2px);
  background: #f9fafb;
  border-color: #dcfce7;
}
.purchase-card.active {
  background: #f0fdf4;
  border-color: #86efac;
}
.purchase-icon {
  width: 58px;
  height: 58px;
  border-radius: 18px;
  display: flex;
  align-items: center; justify-content: center;
  background: #f9fafb; color: #111827;
}
.purchase-card.active .purchase-icon {
  background: #dcfce7;
  color: #166534;
}
.purchase-icon svg {
  width: 30px; height: 30px; stroke: currentColor; fill: none; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round;
}
.purchase-label { font-size: 14px; font-weight: 600; text-align: center; line-height: 1.35; }
.purchase-badge {
  position: absolute; top: 10px; right: 18px; min-width: 28px; height: 28px;
  padding: 0 8px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center;
  background: #ef4444; color: #fff; font-size: 12px; font-weight: 700;
  box-shadow: 0 8px 18px rgba(239,68,68,.24);
}

/* ── SUCCESS BANNER ── */
.success-banner {
  background: linear-gradient(135deg, #f0fdf4, #dcfce7);
  border: 1.5px solid #86efac; border-radius: 12px; padding: 18px 24px;
  display: flex; align-items: center; gap: 14px; margin-bottom: 24px;
  animation: slidein .5s ease;
}
@keyframes slidein { from{opacity:0;transform:translateY(-12px)} to{opacity:1;transform:none} }
.success-banner .icon { width:44px;height:44px;background:#16a34a;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0; }
.success-banner .icon svg { width:24px;height:24px;stroke:white;fill:none;stroke-width:2.5;stroke-linecap:round; }
.success-banner .text h3 { font-size:15px;font-weight:700;color:#166534; }
.success-banner .text p  { font-size:13px; color:#15803d; margin-top:2px; }

/* ── STATUS TABS ── */
.status-tabs {
  display: flex; gap: 0; background: #fff; border-radius: 12px; border: 1px solid #f3f4f6; overflow: hidden; margin-bottom: 20px;
}
.status-tab {
  flex: 1; padding: 13px 8px; text-align: center; font-size: 13px; font-weight: 600; color: #6b7280;
  text-decoration: none; cursor: pointer; border-bottom: 2.5px solid transparent; transition: all .2s; position: relative;
}
.status-tab:hover { color: #166534; background: #f9fafb; }
.status-tab.active { color: #166534; border-color: #166534; background: #f0fdf4; }
.status-tab .tab-badge {
  display: inline-flex; align-items: center; justify-content: center;
  background: #dc2626; color: white; font-size: 10px; font-weight: 700;
  min-width: 17px; height: 17px; border-radius: 99px; padding: 0 4px; margin-left: 5px; vertical-align: middle;
}

/* ── ORDER CARD ── */
.order-card {
  background: #fff; border-radius: 14px; border: 1px solid #f3f4f6; margin-bottom: 16px; overflow: hidden; transition: box-shadow .2s;
}
.order-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
.order-card.focused { border-color: #86efac; box-shadow: 0 0 0 3px rgba(22,163,74,0.12); }

.order-header { padding: 16px 20px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #f9fafb; flex-wrap: wrap; gap: 10px; }
.order-id-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.order-num { font-size: 14px; font-weight: 700; color: #111827; }
.order-date { font-size: 12.5px; color: #9ca3af; }
.status-badge { font-size: 12px; font-weight: 700; padding: 4px 12px; border-radius: 99px; }

/* ── STEPPER ── */
.stepper { padding: 20px 24px; border-bottom: 1px solid #f9fafb; }
.steps { display: flex; align-items: flex-start; position: relative; }
.steps::before { content: ''; position: absolute; top: 18px; left: 18px; right: 18px; height: 2px; background: #e5e7eb; z-index: 0; }
.step { flex: 1; display: flex; flex-direction: column; align-items: center; position: relative; z-index: 1; gap: 8px; }
.step-circle { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: #e5e7eb; border: 2px solid #d1d5db; transition: all .3s; position: relative; }
.step-circle svg { width: 18px; height: 18px; stroke: #9ca3af; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.step.done .step-circle { background: #166534; border-color: #166534; }
.step.done .step-circle svg { stroke: white; }
.step.active .step-circle { background: #f0fdf4; border-color: #16a34a; box-shadow: 0 0 0 4px rgba(22,163,74,0.15); }
.step.active .step-circle svg { stroke: #166534; }
.step.done .step-label, .step.active .step-label { color: #166534; }
.step-label { font-size: 11.5px; font-weight: 600; color: #9ca3af; text-align: center; line-height: 1.3; }

.cancelled-bar { display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: #fef2f2; border-radius: 8px; font-size: 13.5px; font-weight: 600; color: #dc2626; }
.cancelled-bar svg { width: 20px; height: 20px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; }

/* ── ORDER ITEMS ── */
.order-items-list { padding: 16px 20px; }
.oi-row { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid #f9fafb; }
.oi-row:last-child { border-bottom: none; }
.oi-img { width: 58px; height: 58px; border-radius: 8px; object-fit: cover; background: #f0fdf4; flex-shrink: 0; }
.oi-info { flex: 1; }
.oi-name { font-size: 14px; font-weight: 600; color: #111827; line-height: 1.3; }
.oi-qty  { font-size: 12.5px; color: #9ca3af; margin-top: 3px; }
.oi-price{ font-size: 14px; font-weight: 700; color: #dc2626; white-space: nowrap; }

/* ── ORDER FOOTER ── */
.order-footer { padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; background: #fafafa; flex-wrap: wrap; gap: 10px; }
.order-total { font-size: 15px; }
.order-total strong { color: #dc2626; font-size: 17px; }
.order-actions { display: flex; gap: 8px; }
.btn-reorder { padding: 8px 18px; background: #166534; color: white; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: background .2s; }
.btn-reorder:hover { background: #0f5132; }
.btn-detail-toggle { padding: 8px 16px; background: white; color: #374151; border: 1.5px solid #e5e7eb; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit; transition: all .2s; }
.btn-detail-toggle:hover { border-color: #166534; color: #166534; }

.order-items-wrap { display: none; }
.order-items-wrap.open { display: block; }

/* ── EMPTY STATE ── */
.empty-orders { text-align: center; padding: 70px 20px; background: #fff; border-radius: 14px; border: 1px solid #f3f4f6; }
.empty-orders svg { width: 72px; height: 72px; stroke: #d1d5db; fill: none; stroke-width: 1.5; margin: 0 auto 16px; display: block; }
.empty-orders h3 { font-size: 19px; font-weight: 600; color: #374151; margin-bottom: 8px; }
.empty-orders p  { font-size: 14px; color: #9ca3af; margin-bottom: 24px; }
.btn-shop { display: inline-block; padding: 12px 30px; background: #166534; color: white; border-radius: 8px; text-decoration: none; font-size: 14px; font-weight: 700; }

@media (max-width: 860px) { .orders-wrap { padding: 0 18px; } .purchase-grid { grid-template-columns: repeat(2, 1fr); } }

/* ── ORDER CODE TAG ── */
.order-code-tag {
  display: inline-flex; align-items: center;
  padding: 3px 9px; border-radius: 6px;
  background: #eff6ff; color: #1d4ed8;
  font-size: 12px; font-weight: 700; font-family: monospace;
  letter-spacing: .3px; white-space: nowrap;
}

/* ── QR MODAL ── */
.qr-modal-body { padding: 28px 24px 24px; text-align: center; }
.qr-modal-body h3 { font-size: 17px; font-weight: 700; color: #111827; margin-bottom: 4px; }
.qr-modal-body .qr-code-label { font-size: 13px; color: #6b7280; margin-bottom: 20px; }
.qr-modal-body img { border-radius: 12px; border: 4px solid #f0fdf4; box-shadow: 0 8px 24px rgba(15,81,50,.12); }
.qr-modal-body .qr-code-val { margin-top: 16px; font-size: 16px; font-weight: 700; font-family: monospace; color: #1d4ed8; letter-spacing: .5px; }
.qr-modal-body .qr-hint { margin-top: 8px; font-size: 12px; color: #9ca3af; }
.btn-qr { padding: 7px 14px; background: #eff6ff; color: #1d4ed8; border: 1.5px solid #bfdbfe; border-radius: 7px; font-size: 12px; font-weight: 700; cursor: pointer; font-family: inherit; transition: all .2s; display: inline-flex; align-items: center; gap: 5px; }
.btn-qr:hover { background: #dbeafe; border-color: #93c5fd; }

/* ══ REVIEWS Modal Refined ══ */
.modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); z-index: 9999; display: none; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
.modal-overlay.active { display: flex; }
.modal-content { background: #fff; width: 100%; max-width: 520px; border-radius: 20px; overflow: hidden; animation: modalPop 0.3s ease; position: relative; }
@keyframes modalPop { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }
.review-modal-content { padding: 32px 28px; text-align: center; }
.modal-close { position: absolute; top: 18px; right: 20px; background: none; border: none; font-size: 28px; color: #9ca3af; cursor: pointer; line-height: 1; transition: color 0.2s; z-index: 10; }
.modal-close:hover { color: #4b5563; }
.modal-icon { width: 68px; height: 68px; border-radius: 50%; background: #dcfce7; color: #16a34a; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; }
.modal-icon svg { width: 36px; height: 36px; fill: currentColor; }
.modal-header { padding: 0 0 16px; border: none; display: block; }
.modal-header h3 { font-size: 20px; font-weight: 700; color: #166534; margin: 0; }
.modal-body { padding: 0; }
.star-rating-wrap { margin-bottom: 28px; }
.star-rating-wrap label { display: block; font-size: 13px; font-weight: 700; color: #16a34a; margin-bottom: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
.star-picker { display: flex; gap: 8px; flex-direction: row-reverse; justify-content: center; margin-bottom: 0; }
.star-pick-label { cursor: pointer; line-height: 1; }
.star-pick-label input { position: absolute; opacity: 0; width: 0; height: 0; }
.star-pick { width: 40px; height: 40px; fill: #e5e7eb; stroke: #d1d5db; stroke-width: 1; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); }
.star-picker:hover .star-pick { fill: #22c55e; stroke: #16a34a; transform: scale(1.1); }
.star-pick-label:hover ~ .star-pick-label .star-pick { fill: #e5e7eb; stroke: #d1d5db; transform: scale(1); }
.star-pick-label input:checked ~ .star-pick { fill: #e5e7eb; stroke: #d1d5db; }
.star-pick-label input:checked + .star-pick,
.star-pick-label:has(input:checked) ~ .star-pick-label .star-pick { fill: #22c55e !important; stroke: #16a34a !important; }
.star-pick-label:hover input:checked + .star-pick { transform: scale(1.1); }
.modal-body textarea { width: 100%; border: 1.5px solid #bbf7d0; border-radius: 12px; padding: 18px 20px; font-family: inherit; font-size: 15px; outline: none; transition: all 0.2s; resize: vertical; min-height: 120px; background: #fcfcfc; margin-top: 8px; }
.modal-body textarea::placeholder { color: #9ca3af; }
.modal-body textarea:focus { border-color: #16a34a; background: #fff; box-shadow: 0 0 0 4px rgba(22,163,74,0.1); }
.modal-footer { padding: 24px 0 0; background: transparent; display: flex; justify-content: center; gap: 16px; }
.btn-cancel, .btn-submit { flex: 1; padding: 14px 24px; border-radius: 10px; font-weight: 700; font-size: 15px; cursor: pointer; transition: all 0.2s; font-family: inherit; display: flex; align-items: center; justify-content: center; }
.btn-cancel { background: #fff; border: 1.5px solid #166534; color: #166534; }
.btn-cancel:hover { background: #f0fdf4; }
.btn-submit { background: #166534; border: 1.5px solid #166534; color: #fff; }
.btn-submit:hover { background: #15803d; border-color: #15803d; transform: translateY(-1px); }
@media (max-width: 560px) { .review-modal-content { padding: 24px 20px; } .modal-footer { flex-direction: column; gap: 12px; } .btn-cancel, .btn-submit { width: 100%; } .star-pick { width: 38px; height: 38px; } }
</style>
</head>
<body>

<!-- ══ NAVBAR ══ -->
<nav class="navbar" id="navbar">
  <div class="navbar-inner">
    <a href="index.php" class="brand">
      <img src="images/logo.png" alt="Logo">
      <span class="brand-name">Mộc Trà</span>
    </a>
    <div class="nav-menu">
      <a href="index.php" class="nav-link">Trang chủ</a>
      <a href="products.php" class="nav-link">Sản phẩm</a>
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

<div class="orders-wrap">
  <h1>Lịch sử đơn hàng</h1>

  <!-- Status tabs -->
  <div class="status-tabs" id="orders-list">
    <a href="order_history.php" class="status-tab <?= !$filter_status ? 'active' : '' ?>">
      Tất cả <?php if($total_orders): ?><span class="tab-badge"><?= $total_orders ?></span><?php endif; ?>
    </a>
    <a href="order_history.php?status=pending" class="status-tab <?= $filter_status==='pending' ? 'active' : '' ?>">
      Chờ xác nhận <?php if($counts['pending']): ?><span class="tab-badge"><?= $counts['pending'] ?></span><?php endif; ?>
    </a>
    <a href="order_history.php?status=delivered" class="status-tab <?= $filter_status==='delivered' ? 'active' : '' ?>">
      Hoàn thành <?php if($completed_count): ?><span class="tab-badge"><?= $completed_count ?></span><?php endif; ?>
    </a>
    <a href="order_history.php?status=cancelled" class="status-tab <?= $filter_status==='cancelled' ? 'active' : '' ?>">
      Đã hủy <?php if($counts['cancelled']): ?><span class="tab-badge"><?= $counts['cancelled'] ?></span><?php endif; ?>
    </a>
  </div>

  <?php if (empty($orders)): ?>
  <div class="empty-orders"><h3>Chưa có đơn hàng nào</h3></div>
  <?php else: foreach ($orders as $order):
    $cfg     = $status_cfg[$order['status']] ?? $status_cfg['pending'];
    $oi_list = $items_map[$order['id']] ?? [];
  ?>
  <div class="order-card" id="order-<?= $order['id'] ?>">
    <?php
      $oc = $order['order_code'] ?? generate_order_code((int)$order['id']);
    ?>
    <div class="order-header">
      <div class="order-id-row">
        <span class="order-num">Đơn hàng #<?= $order['id'] ?></span>
        <span class="order-code-tag"><?= htmlspecialchars($oc) ?></span>
        <span class="order-date"><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></span>
      </div>
      <span class="status-badge" style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>"><?= $cfg['label'] ?></span>
    </div>

    <div class="order-items-list">
        <?php foreach ($oi_list as $oi):
            $imgFile = basename($oi['image'] ?? '');
            $imgSrc  = ($imgFile && file_exists(__DIR__ . '/images/' . $imgFile))
                       ? 'images/' . $imgFile
                       : 'images/logo.png';
        ?>
        <div class="oi-row">
          <img class="oi-img"
               src="<?= htmlspecialchars($imgSrc) ?>"
               onerror="this.onerror=null;this.src='images/logo.png'"
               alt="<?= htmlspecialchars($oi['product_name']) ?>">
          <div class="oi-info">
            <div class="oi-name"><?= htmlspecialchars($oi['product_name']) ?></div>
            <div class="oi-qty">x<?= $oi['qty'] ?></div>
          </div>
          <div class="oi-price"><?= fmt($oi['price'] * $oi['qty']) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="order-footer">
      <div class="order-total">Tổng: <strong><?= fmt((int)$order['total']) ?></strong></div>
      <div class="order-actions">
        <?php if ($order['status'] === 'delivered'): ?>
            <button class="btn-reorder" onclick="openReviewModal(<?= $order['id'] ?>)">Đánh giá ngay</button>
        <?php elseif ($order['status'] === 'reviewed'): ?>
            <span style="color:#16a34a;font-weight:700">✓ Đã đánh giá</span>
        <?php endif; ?>
        <button class="btn-qr" onclick="openQrModal(<?= $order['id'] ?>, <?= json_encode($oc) ?>)">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="3" height="3"/><rect x="18" y="18" width="3" height="3"/></svg>
          QR
        </button>
        <a href="products.php" class="btn-reorder" style="background:white;color:#166534;border:1px solid #166534">Mua lại</a>
      </div>
    </div>
  </div>
  <?php endforeach; endif; ?>
</div>

<!-- Modal -->
<div id="reviewModal" class="modal-overlay">
  <div class="modal-content review-modal-content">
    <button class="modal-close" onclick="closeReviewModal()">&times;</button>
    <div class="modal-icon">
      <svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
    </div>
    <div class="modal-header">
      <h3>Đánh giá đơn hàng #<span id="modalOrderId"></span></h3>
    </div>
    <form action="submit_order_review.php" method="POST">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="order_id" id="inputOrderId">
      <div class="modal-body">
        <div class="star-rating-wrap">
          <label>MỨC ĐỘ HÀI LÒNG</label>
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
        </div>
        <textarea name="comment" rows="4" placeholder="Nhận xét của bạn..."></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeReviewModal()">Hủy</button>
        <button type="submit" class="btn-submit">Gửi đánh giá</button>
      </div>
    </form>
  </div>
</div>

<!-- QR Modal -->
<div id="qrModal" class="modal-overlay">
  <div class="modal-content">
    <button class="modal-close" onclick="closeQrModal()">&times;</button>
    <div class="qr-modal-body">
      <h3>Mã QR đơn hàng</h3>
      <div class="qr-code-label" id="qrOrderLabel"></div>
      <img id="qrImg" src="" alt="QR Code" width="200" height="200">
      <div class="qr-code-val" id="qrCodeVal"></div>
      <div class="qr-hint">Quét mã để lưu thông tin đơn hàng.</div>
    </div>
  </div>
</div>

<script>
function openQrModal(orderId, orderCode) {
  var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(orderCode) + '&ecc=M&margin=4';
  document.getElementById('qrOrderLabel').textContent = 'Đơn hàng #' + orderId;
  document.getElementById('qrImg').src = qrUrl;
  document.getElementById('qrCodeVal').textContent = orderCode;
  document.getElementById('qrModal').classList.add('active');
}
function closeQrModal() { document.getElementById('qrModal').classList.remove('active'); }

function openReviewModal(id) {
  document.getElementById('modalOrderId').textContent = id;
  document.getElementById('inputOrderId').value = id;
  document.getElementById('reviewModal').classList.add('active');
}
function closeReviewModal() { document.getElementById('reviewModal').classList.remove('active'); }

(function(){
  var catBtn=document.getElementById('catBtn'),catMenu=document.getElementById('catMenu');
  var userBtn=document.getElementById('userBtn'),userMenu=document.getElementById('userMenu');
  function closeAll(){catMenu&&catMenu.classList.remove('open');userMenu&&userMenu.classList.remove('open');catBtn&&catBtn.classList.remove('open');userBtn&&userBtn.classList.remove('open');}
  catBtn&&catBtn.addEventListener('click',function(e){e.stopPropagation();var o=catMenu.classList.contains('open');closeAll();if(!o){catMenu.classList.add('open');catBtn.classList.add('open');}});
  userBtn&&userBtn.addEventListener('click',function(e){e.stopPropagation();var o=userMenu.classList.contains('open');closeAll();if(!o){userMenu.classList.add('open');userBtn.classList.add('open');}});
  document.addEventListener('click',closeAll);
})();
</script>
<script src="js/moctra-functions.js"></script>
</body>
</html>


