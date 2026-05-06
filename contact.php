<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();
/**
 * contact.php — Form liên hệ / gửi phản hồi
 * Khách có thể gửi tin nhắn, tin sẽ hiện trong admin/contacts.php
 */
require_once 'config/db.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Tự động tạo bảng contacts nếu chưa có
$conn->query("
    CREATE TABLE IF NOT EXISTS contacts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(150) DEFAULT '',
        phone VARCHAR(20) DEFAULT '',
        subject VARCHAR(200) DEFAULT 'Liên hệ chung',
        message TEXT NOT NULL,
        status ENUM('new','read','replied') DEFAULT 'new',
        admin_note TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Migration: add user_id column for notification linkage
$chkCol = $conn->query("SHOW COLUMNS FROM contacts LIKE 'user_id'");
if ($chkCol && $chkCol->num_rows === 0) {
    $conn->query("ALTER TABLE contacts ADD COLUMN user_id INT UNSIGNED DEFAULT NULL");
}

// Notification table
$conn->query("CREATE TABLE IF NOT EXISTS `notifications` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `type`         ENUM('review_reply','contact_reply') NOT NULL,
  `reference_id` INT UNSIGNED NOT NULL,
  `message`      VARCHAR(500) NOT NULL DEFAULT '',
  `is_read`      TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_read` (`user_id`, `is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$isLoggedIn  = isset($_SESSION['user_id']);
$isAdmin     = $isLoggedIn && ($_SESSION['role'] ?? '') === 'admin';
$username    = htmlspecialchars($_SESSION['username'] ?? '');
$userInitial = $username ? strtoupper(substr($username, 0, 1)) : '';

// Nếu admin đang đăng nhập → redirect về dashboard
if ($isAdmin) {
    header('Location: admin/contacts.php');
    exit();
}

// Lấy thông tin user đã đăng nhập để pre-fill form
$prefillName  = '';
$prefillEmail = '';
$prefillPhone = '';
if ($isLoggedIn) {
    $st = $conn->prepare("SELECT full_name, email, phone FROM users WHERE id = ? LIMIT 1");
    $st->bind_param('i', $_SESSION['user_id']);
    $st->execute();
    $me = $st->get_result()->fetch_assoc();
    $prefillName  = $me['full_name'] ?? $username;
    $prefillEmail = $me['email'] ?? '';
    $prefillPhone = $me['phone'] ?? '';
}

// Lấy danh mục cho navbar dropdown
$cats = $conn->query("SELECT * FROM categories ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);

/* ── Xử lý POST ── */
$success = false;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['_csrf']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['_csrf'])) {
        $errors[] = 'Yêu cầu không hợp lệ.';
    }

    $name    = trim($_POST['name']    ?? '');
    $email   = trim($_POST['email']   ?? '');
    $phone   = trim($_POST['phone']   ?? '');
    $subject = trim($_POST['subject'] ?? 'Liên hệ chung');
    $message = trim($_POST['message'] ?? '');

    // Validate
    if ($name === '')    $errors[] = 'Vui lòng nhập họ và tên.';
    if ($message === '') $errors[] = 'Vui lòng nhập nội dung tin nhắn.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email không hợp lệ.';
    }

    if (empty($errors)) {
        $userId = $isLoggedIn ? (int)$_SESSION['user_id'] : null;
        $st = $conn->prepare(
            "INSERT INTO contacts (name, email, phone, subject, message, status, user_id, created_at)
             VALUES (?, ?, ?, ?, ?, 'new', ?, NOW())"
        );
        $st->bind_param('sssssi', $name, $email, $phone, $subject, $message, $userId);
        if ($st->execute()) {
            $success = true;
        } else {
            $errors[] = 'Gửi tin nhắn thất bại, vui lòng thử lại.';
        }
    }
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
  <title>Liên hệ — Mộc Trà Thái Nguyên</title>
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

    body { background: #f9fafb; }

    .contact-wrap {
      max-width: 1100px;
      margin: 48px auto 80px;
      padding: 0 24px;
      display: grid;
      grid-template-columns: 1fr 1.6fr;
      gap: 40px;
      align-items: start;
    }

    /* ── Info Panel ── */
    .contact-info {
      background: linear-gradient(145deg, #166534, #0f5132);
      border-radius: 20px;
      padding: 44px 36px;
      color: #fff;
      position: sticky;
      top: 100px;
    }
    .contact-info h2 {
      font-family: 'Playfair Display', serif;
      font-size: 28px;
      margin-bottom: 12px;
    }
    .contact-info p {
      font-size: 14px;
      opacity: .8;
      line-height: 1.7;
      margin-bottom: 32px;
    }
    .info-item {
      display: flex;
      gap: 14px;
      align-items: flex-start;
      margin-bottom: 24px;
    }
    .info-icon {
      width: 42px; height: 42px;
      background: rgba(255,255,255,.15);
      border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
    }
    .info-icon svg { width: 20px; height: 20px; stroke: #fff; fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    .info-label { font-size: 12px; opacity: .65; margin-bottom: 3px; text-transform: uppercase; letter-spacing: .5px; }
    .info-value { font-weight: 600; font-size: 14.5px; }

    /* ── Form Panel ── */
    .contact-form-wrap {
      background: #fff;
      border-radius: 20px;
      padding: 44px 40px;
      box-shadow: 0 4px 24px rgba(0,0,0,.06);
    }
    .contact-form-wrap h2 {
      font-family: 'Playfair Display', serif;
      font-size: 26px;
      color: #111827;
      margin-bottom: 6px;
    }
    .contact-form-wrap > p {
      color: #6b7280;
      font-size: 14px;
      margin-bottom: 28px;
    }

    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }
    .fg { margin-bottom: 18px; }
    .fg label {
      display: block;
      font-size: 13px;
      font-weight: 600;
      color: #374151;
      margin-bottom: 7px;
    }
    .fg .req { color: #dc2626; margin-left: 2px; }
    .inp {
      width: 100%;
      padding: 11px 14px;
      border: 1.5px solid #e5e7eb;
      border-radius: 10px;
      font-size: 14px;
      font-family: inherit;
      color: #111827;
      background: #f9fafb;
      transition: border-color .2s, box-shadow .2s, background .2s;
      outline: none;
    }
    .inp:focus {
      border-color: #166534;
      background: #fff;
      box-shadow: 0 0 0 3px rgba(22,101,52,.1);
    }
    textarea.inp { resize: vertical; min-height: 120px; line-height: 1.6; }

    .alert-success {
      background: #f0fdf4;
      border: 1px solid #bbf7d0;
      border-left: 4px solid #22c55e;
      border-radius: 10px;
      padding: 18px 20px;
      color: #15803d;
      margin-bottom: 24px;
    }
    .alert-success h3 { font-size: 16px; margin-bottom: 6px; }
    .alert-success p  { font-size: 13.5px; opacity: .9; }

    .alert-error {
      background: #fef2f2;
      border: 1px solid #fecaca;
      border-left: 4px solid #ef4444;
      border-radius: 10px;
      padding: 14px 18px;
      color: #b91c1c;
      font-size: 13.5px;
      margin-bottom: 20px;
    }
    .alert-error ul { margin: 6px 0 0 16px; }
    .alert-error li { margin-bottom: 3px; }

    .btn-send {
      width: 100%;
      padding: 14px;
      background: linear-gradient(135deg, #166534, #0f5132);
      color: #fff;
      border: none;
      border-radius: 12px;
      font-size: 15px;
      font-weight: 700;
      font-family: inherit;
      cursor: pointer;
      transition: opacity .2s, transform .15s;
      margin-top: 6px;
    }
    .btn-send:hover   { opacity: .88; transform: translateY(-2px); }
    .btn-send:active  { transform: translateY(0); }
    /* ── Field Error ── */
    .ct-field-err { font-size: 12px; color: #dc2626; margin-top: 4px; }

    /* Breadcrumb */
    .breadcrumb-bar { background: #fff; border-bottom: 1px solid #f3f4f6; padding: 12px 0; margin-bottom: 0; }
    .breadcrumb-inner { max-width: 1100px; margin: 0 auto; padding: 0 24px; display: flex; align-items: center; gap: 8px; font-size: 13px; color: #6b7280; }
    .breadcrumb-inner a { color: #166534; text-decoration: none; }
    .breadcrumb-inner a:hover { text-decoration: underline; }
    .sep { color: #d1d5db; }

    @media (max-width: 900px) {
      .contact-wrap { grid-template-columns: 1fr; }
      .contact-info { position: static; }
    }
    @media (max-width: 600px) {
      .form-row { grid-template-columns: 1fr; }
      .contact-form-wrap { padding: 28px 20px; }
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
      <a href="reviews.php" class="nav-link">Đánh giá</a>
      <a href="contact.php" class="nav-link active">Liên hệ</a>
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
    <span>Liên hệ</span>
  </div>
</div>

<div class="contact-wrap">

  <!-- Info Panel -->
  <div class="contact-info">
    <h2>Hỗ trợ khách hàng</h2>
    <p>Chúng tôi luôn sẵn sàng lắng nghe và hỗ trợ bạn. Gửi tin nhắn hoặc liên hệ trực tiếp qua các kênh bên dưới.</p>

    <div class="info-item">
      <div class="info-icon">
        <svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.41 2 2 0 0 1 3.6 1h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L7.91 8.6a16 16 0 0 0 6 6l.92-.92a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
      </div>
      <div>
        <div class="info-label">Hotline hỗ trợ</div>
        <div class="info-value">1800 xxxx</div>
        <div style="font-size:12px;opacity:.6;margin-top:2px;">Miễn phí · 08:00 – 22:00 kể cả T7, CN</div>
      </div>
    </div>

    <div class="info-item">
      <div class="info-icon">
        <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
      </div>
      <div>
        <div class="info-label">Email</div>
        <div class="info-value">support@moctrathainguyen.vn</div>
      </div>
    </div>

    <div class="info-item">
      <div class="info-icon">
        <svg viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
      </div>
      <div>
        <div class="info-label">Địa chỉ</div>
        <div class="info-value">Xã Tân Cương, TP. Thái Nguyên</div>
        <div style="font-size:12px;opacity:.6;margin-top:2px;">Tỉnh Thái Nguyên, Việt Nam</div>
      </div>
    </div>

    <div class="info-item">
      <div class="info-icon">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      </div>
      <div>
        <div class="info-label">Thời gian làm việc</div>
        <div class="info-value">08:00 – 22:00 hàng ngày</div>
      </div>
    </div>
  </div>

  <!-- Form Panel -->
  <div class="contact-form-wrap">
    <h2>Gửi tin nhắn cho chúng tôi</h2>
    <p>Điền thông tin bên dưới, chúng tôi sẽ phản hồi trong vòng 24 giờ.</p>

    <?php if ($success): ?>
    <div class="alert-success">
      <h3>✅ Tin nhắn đã được gửi!</h3>
      <p>Cảm ơn bạn đã liên hệ. Đội ngũ hỗ trợ sẽ phản hồi trong vòng 24 giờ làm việc.</p>
    </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <div class="alert-error">
      <strong>Vui lòng kiểm tra lại:</strong>
      <ul>
        <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <?php if (!$success): ?>
    <form method="POST" id="contactForm">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

      <div class="form-row">
        <div class="fg">
          <label for="inp-name">Họ và tên <span class="text-danger">*</span></label>
          <input class="inp" type="text" id="inp-name" name="name"
            value="<?= htmlspecialchars($_POST['name'] ?? $prefillName) ?>"
            placeholder="Nhập họ và tên">
          <div class="ct-field-err" id="err-name"></div>
        </div>
        <div class="fg">
          <label for="inp-phone">Số điện thoại</label>
          <input class="inp" type="tel" id="inp-phone" name="phone"
            value="<?= htmlspecialchars($_POST['phone'] ?? $prefillPhone) ?>"
            placeholder="Nhập số điện thoại">
        </div>
      </div>

      <div class="fg">
        <label for="inp-email">Email</label>
        <input class="inp" type="email" id="inp-email" name="email"
          value="<?= htmlspecialchars($_POST['email'] ?? $prefillEmail) ?>"
          placeholder="Nhập địa chỉ email">
        <div class="ct-field-err" id="err-email"></div>
      </div>

      <div class="fg">
        <label for="inp-subject">Chủ đề</label>
        <select class="inp" id="inp-subject" name="subject">
          <?php
          $subjects = [
            'Hỏi về sản phẩm'      => 'Hỏi về sản phẩm',
            'Phản hồi đơn hàng'    => 'Phản hồi đơn hàng',
            'Chính sách đổi trả'   => 'Chính sách đổi trả',
            'Hợp tác kinh doanh'   => 'Hợp tác kinh doanh',
            'Góp ý chất lượng'     => 'Góp ý chất lượng',
            'Khiếu nại'            => 'Khiếu nại',
            'Liên hệ chung'        => 'Liên hệ chung',
          ];
          $sel = $_POST['subject'] ?? 'Liên hệ chung';
          foreach ($subjects as $val => $label):
          ?>
            <option value="<?= $val ?>" <?= $sel === $val ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="fg">
        <label for="inp-message">Nội dung <span class="text-danger">*</span></label>
        <textarea class="inp" id="inp-message" name="message"
          placeholder="Nhập nội dung tin nhắn..."><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
        <div class="ct-field-err" id="err-message"></div>
      </div>

      <button type="submit" class="btn-send" id="btnSend">
        Gửi tin nhắn →
      </button>
    </form>
    <?php else: ?>
    <div style="text-align:center;margin-top:16px;">
      <a href="contact.php" style="color:#166534;font-weight:600;text-decoration:none;">Gửi tin nhắn khác</a>
      &nbsp;·&nbsp;
      <a href="index.php" style="color:#166534;font-weight:600;text-decoration:none;">Về trang chủ</a>
    </div>
    <?php endif; ?>
  </div>

</div>

<!-- Footer giản lược -->
<footer style="background:#111827;color:rgba(255,255,255,.5);text-align:center;padding:20px;font-size:13px;">
  © 2025 Mộc Trà Thái Nguyên. Mọi quyền được bảo lưu.
</footer>

<script>
(function () {
  /* ── Contact Form Inline Validation ── */
  var contactForm = document.getElementById('contactForm');
  if (contactForm) {
    function ctSetErr(id, msg) {
      var el = document.getElementById(id);
      var inp = contactForm.querySelector('#inp-' + id.replace('err-', ''));
      if (el)  el.textContent = msg;
      if (inp) inp.style.borderColor = '#dc2626';
    }
    function ctClearErr(id) {
      var el = document.getElementById(id);
      var inp = contactForm.querySelector('#inp-' + id.replace('err-', ''));
      if (el)  el.textContent = '';
      if (inp) inp.style.borderColor = '';
    }
    function validEmail(v) { return v === '' || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); }

    var nameInp    = document.getElementById('inp-name');
    var emailInp   = document.getElementById('inp-email');
    var messageInp = document.getElementById('inp-message');

    nameInp && nameInp.addEventListener('blur', function () {
      nameInp.value.trim() ? ctClearErr('err-name') : ctSetErr('err-name', 'Vui lòng nhập họ và tên.');
    });
    nameInp && nameInp.addEventListener('input', function () {
      if (nameInp.value.trim()) ctClearErr('err-name');
    });

    emailInp && emailInp.addEventListener('blur', function () {
      validEmail(emailInp.value.trim()) ? ctClearErr('err-email') : ctSetErr('err-email', 'Email không hợp lệ.');
    });
    emailInp && emailInp.addEventListener('input', function () {
      if (validEmail(emailInp.value.trim())) ctClearErr('err-email');
    });

    messageInp && messageInp.addEventListener('blur', function () {
      messageInp.value.trim() ? ctClearErr('err-message') : ctSetErr('err-message', 'Vui lòng nhập nội dung tin nhắn.');
    });
    messageInp && messageInp.addEventListener('input', function () {
      if (messageInp.value.trim()) ctClearErr('err-message');
    });

    contactForm.addEventListener('submit', function (e) {
      var ok = true;
      if (!nameInp.value.trim()) { ctSetErr('err-name', 'Vui lòng nhập họ và tên.'); ok = false; }
      else ctClearErr('err-name');
      if (!validEmail(emailInp.value.trim())) { ctSetErr('err-email', 'Email không hợp lệ.'); ok = false; }
      else ctClearErr('err-email');
      if (!messageInp.value.trim()) { ctSetErr('err-message', 'Vui lòng nhập nội dung tin nhắn.'); ok = false; }
      else ctClearErr('err-message');
      if (!ok) {
        e.preventDefault();
        var firstInvalid = contactForm.querySelector('input[style*="dc2626"], textarea[style*="dc2626"]');
        if (firstInvalid) firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
      }
      var btnSend = document.getElementById('btnSend');
      if (btnSend) { btnSend.disabled = true; btnSend.textContent = 'Đang gửi...'; }
    });
  }

  var catBtn  = document.getElementById('catBtn');
  var catMenu = document.getElementById('catMenu');
  var userBtn = document.getElementById('userBtn');
  var userMenu = document.getElementById('userMenu');

  function closeAll() {
    catMenu  && catMenu.classList.remove('open');
    userMenu && userMenu.classList.remove('open');
  }
  catBtn  && catBtn.addEventListener('click', function (e) {
    e.stopPropagation();
    catMenu.classList.toggle('open');
  });
  userBtn && userBtn.addEventListener('click', function (e) {
    e.stopPropagation();
    userMenu.classList.toggle('open');
  });
  document.addEventListener('click', closeAll);
})();
</script>
<script src="js/moctra-functions.js"></script>
</body>
</html>


