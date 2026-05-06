<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
if ($_SESSION['role'] === 'admin') { header("Location: admin/dashboard.php"); exit(); }

$userId      = $_SESSION['user_id'];
$username    = htmlspecialchars($_SESSION['username'] ?? '');
$userInitial = strtoupper(substr($username, 0, 1));

$cats = $conn->query("SELECT * FROM categories ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);

/* ── Lấy thông tin user ── */
$stmt = $conn->prepare("SELECT username, email, phone, address, created_at FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) { header("Location: logout.php"); exit(); }

$success = '';
$errors  = [];
$tab     = $_GET['tab'] ?? 'info'; // 'info' hoặc 'password'

/* ── POST: Cập nhật thông tin ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'update_info') {
        $email   = trim($_POST['email'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if ($email === '') {
            $errors[] = 'Email không được để trống.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email không hợp lệ.';
        }
        if ($phone !== '' && !preg_match('/^(0|\+84)[0-9]{9}$/', $phone)) {
            $errors[] = 'Số điện thoại không hợp lệ.';
        }

        if (empty($errors)) {
            $upd = $conn->prepare("UPDATE users SET email = ?, phone = ?, address = ? WHERE id = ?");
            $upd->bind_param("sssi", $email, $phone, $address, $userId);
            if ($upd->execute()) {
                $success = 'Cập nhật thông tin thành công!';
                $user['email']   = $email;
                $user['phone']   = $phone;
                $user['address'] = $address;
            } else {
                $errors[] = 'Có lỗi xảy ra, vui lòng thử lại.';
            }
        }
        $tab = 'info';
    }

    if ($_POST['action'] === 'change_password') {
        $currentPw = $_POST['current_password'] ?? '';
        $newPw     = $_POST['new_password'] ?? '';
        $confirmPw = $_POST['confirm_password'] ?? '';

        if ($currentPw === '' || $newPw === '' || $confirmPw === '') {
            $errors[] = 'Vui lòng nhập đầy đủ các trường mật khẩu.';
        } elseif (strlen($newPw) < 6) {
            $errors[] = 'Mật khẩu mới tối thiểu 6 ký tự.';
        } elseif ($newPw !== $confirmPw) {
            $errors[] = 'Mật khẩu xác nhận không khớp.';
        } else {
            $chk = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
            $chk->bind_param("i", $userId);
            $chk->execute();
            $row = $chk->get_result()->fetch_assoc();

            if (!password_verify($currentPw, $row['password'])) {
                $errors[] = 'Mật khẩu hiện tại không đúng.';
            } else {
                $hash = password_hash($newPw, PASSWORD_DEFAULT);
                $upd  = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $upd->bind_param("si", $hash, $userId);
                if ($upd->execute()) {
                    $success = 'Đổi mật khẩu thành công!';
                } else {
                    $errors[] = 'Có lỗi xảy ra, vui lòng thử lại.';
                }
            }
        }
        $tab = 'password';
    }
}

$memberSince = date('d/m/Y', strtotime($user['created_at']));
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
  <title>Hồ sơ của tôi — Mộc Trà Thái Nguyên</title>
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

    body { background: #f9fafb; }
    .profile-wrap { max-width: 900px; margin: 32px auto 60px; padding: 0 24px; }
    .profile-head { margin-bottom: 28px; }
    .profile-head h1 { font-family: 'Playfair Display', serif; font-size: 30px; color: #111827; }
    .profile-head p { color: #6b7280; font-size: 14px; margin-top: 6px; }

    .profile-layout { display: grid; grid-template-columns: 260px minmax(0,1fr); gap: 24px; align-items: start; }

    /* Sidebar */
    .profile-sidebar { background: #fff; border-radius: 18px; border: 1px solid #f3f4f6; box-shadow: 0 10px 32px rgba(15,81,50,.06); padding: 28px 20px; text-align: center; }
    .profile-avatar { width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg, #166534, #0f5132); color: #fff; font-family: 'Playfair Display', serif; font-size: 34px; font-weight: 700; display: flex; align-items: center; justify-content: center; margin: 0 auto 14px; box-shadow: 0 6px 20px rgba(15,81,50,.2); }
    .profile-sidebar .name { font-size: 18px; font-weight: 700; color: #111827; }
    .profile-sidebar .role { font-size: 13px; color: #6b7280; margin-top: 2px; }
    .profile-sidebar .since { font-size: 12px; color: #9ca3af; margin-top: 10px; }
    .profile-sidebar .divider { height: 1px; background: #f3f4f6; margin: 18px 0; }

    .tab-nav { display: flex; flex-direction: column; gap: 4px; }
    .tab-nav a { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-radius: 10px; font-size: 14px; font-weight: 500; color: #374151; text-decoration: none; transition: all .15s; }
    .tab-nav a:hover { background: #f0fdf4; color: #166534; }
    .tab-nav a.active { background: #dcfce7; color: #166534; font-weight: 700; }
    .tab-nav a svg { width: 18px; height: 18px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }

    /* Main panel */
    .profile-panel { background: #fff; border-radius: 18px; border: 1px solid #f3f4f6; box-shadow: 0 10px 32px rgba(15,81,50,.06); padding: 32px; }
    .panel-title { font-family: 'Playfair Display', serif; font-size: 22px; color: #111827; margin-bottom: 6px; }
    .panel-desc { font-size: 14px; color: #6b7280; margin-bottom: 24px; }

    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; font-size: 14px; font-weight: 600; color: #374151; margin-bottom: 6px; }
    .form-group input, .form-group textarea {
      width: 100%; padding: 11px 14px; border: 1.5px solid #d1d5db; border-radius: 10px;
      font-size: 15px; font-family: 'Be Vietnam Pro', Arial, sans-serif; background: #f9fafb;
      outline: none; transition: border-color .2s, box-shadow .2s;
    }
    .form-group input:focus, .form-group textarea:focus {
      border-color: #166534; background: #fff; box-shadow: 0 0 0 3px rgba(22,101,52,.1);
    }
    .form-group textarea { resize: vertical; min-height: 80px; }
    .form-group .hint { font-size: 12px; color: #9ca3af; margin-top: 4px; }
    .form-group input[disabled] { background: #f3f4f6; color: #6b7280; cursor: not-allowed; }

    .alert { padding: 12px 16px; border-radius: 10px; font-size: 14px; margin-bottom: 20px; }
    .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

    .btn-save {
      display: inline-flex; align-items: center; gap: 8px; padding: 12px 28px;
      background: linear-gradient(135deg, #166534, #0f5132); color: #fff;
      border: none; border-radius: 999px; font-size: 15px; font-weight: 700;
      font-family: 'Be Vietnam Pro', Arial, sans-serif; cursor: pointer;
      box-shadow: 0 8px 20px rgba(15,81,50,.2); transition: transform .15s, box-shadow .15s;
    }
    .btn-save:hover { transform: translateY(-1px); box-shadow: 0 10px 28px rgba(15,81,50,.28); }

    .pw-wrap { position: relative; }
    .pw-wrap input { padding-right: 42px; }
    .pw-toggle {
      position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer; color: #9ca3af; padding: 4px;
      display: flex; align-items: center; transition: color .2s;
    }
    .pw-toggle:hover { color: #166534; }
    .pw-toggle svg { width: 18px; height: 18px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

    @media (max-width: 768px) {
      .profile-layout { grid-template-columns: 1fr; }
      .profile-sidebar { text-align: center; }
      .tab-nav { flex-direction: row; flex-wrap: wrap; justify-content: center; }
    }
  </style>
</head>
<body>

<!-- ══ NAVBAR ══ -->
<nav class="navbar" id="navbar">
  <div class="navbar-inner">
    <button class="hamburger" id="hamburger" aria-label="Menu"><span></span><span></span><span></span></button>
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

<!-- ══ BREADCRUMB ══ -->
<div class="breadcrumb-bar">
  <div class="breadcrumb-inner">
    <a href="index.php">Trang chủ</a>
    <span class="sep">›</span>
    <span class="current">Hồ sơ của tôi</span>
  </div>
</div>

<!-- ══ PROFILE ══ -->
<div class="profile-wrap">
  <div class="profile-head">
    <h1>Hồ sơ của tôi</h1>
    <p>Quản lý thông tin cá nhân và bảo mật tài khoản</p>
  </div>

  <div class="profile-layout">

    <!-- Sidebar -->
    <div class="profile-sidebar">
      <div class="profile-avatar"><?= $userInitial ?></div>
      <div class="name"><?= $username ?></div>
      <div class="role">Khách hàng</div>
      <div class="since">Thành viên từ <?= $memberSince ?></div>
      <div class="divider"></div>
      <nav class="tab-nav">
        <a href="profile.php?tab=info" class="<?= $tab === 'info' ? 'active' : '' ?>">
          <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          Thông tin cá nhân
        </a>
        <a href="profile.php?tab=password" class="<?= $tab === 'password' ? 'active' : '' ?>">
          <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Đổi mật khẩu
        </a>
      </nav>
    </div>

    <!-- Main panel -->
    <div class="profile-panel">

      <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
      <?php endif; ?>
      <?php if ($errors): ?>
        <div class="alert alert-error">
          <?php foreach ($errors as $e): ?>
            <div><?= htmlspecialchars($e) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($tab === 'info'): ?>
      <!-- ── Tab: Thông tin cá nhân ── -->
      <div class="panel-title">Thông tin cá nhân</div>
      <div class="panel-desc">Cập nhật email, số điện thoại và địa chỉ giao hàng.</div>

      <form method="POST">
        <input type="hidden" name="action" value="update_info">

        <div class="form-group">
          <label>Tên đăng nhập</label>
          <input type="text" value="<?= $username ?>" disabled>
          <div class="hint">Tên đăng nhập không thể thay đổi.</div>
        </div>

        <div class="form-group">
          <label for="email">Email</label>
          <input type="email" id="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="example@email.com">
        </div>

        <div class="form-group">
          <label for="phone">Số điện thoại</label>
          <input type="tel" id="phone" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="0912345678">
        </div>

        <div class="form-group">
          <label for="address">Địa chỉ giao hàng</label>
          <textarea id="address" name="address" placeholder="Nhập địa chỉ giao hàng mặc định..."><?= htmlspecialchars($user['address'] ?? '') ?></textarea>
        </div>

        <button type="submit" class="btn-save">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          Lưu thay đổi
        </button>
      </form>

      <?php else: ?>
      <!-- ── Tab: Đổi mật khẩu ── -->
      <div class="panel-title">Đổi mật khẩu</div>
      <div class="panel-desc">Để bảo mật tài khoản, vui lòng không chia sẻ mật khẩu cho người khác.</div>

      <form method="POST">
        <input type="hidden" name="action" value="change_password">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

        <div class="form-group">
          <label for="current_password">Mật khẩu hiện tại</label>
          <div class="pw-wrap">
            <input type="password" id="current_password" name="current_password" placeholder="Nhập mật khẩu hiện tại" autocomplete="current-password">
            <button type="button" class="pw-toggle" data-toggle-pw="current_password">
              <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>

        <div class="form-group">
          <label for="new_password">Mật khẩu mới</label>
          <div class="pw-wrap">
            <input type="password" id="new_password" name="new_password" placeholder="Tối thiểu 6 ký tự" autocomplete="new-password">
            <button type="button" class="pw-toggle" data-toggle-pw="new_password">
              <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>

        <div class="form-group">
          <label for="confirm_password">Xác nhận mật khẩu mới</label>
          <div class="pw-wrap">
            <input type="password" id="confirm_password" name="confirm_password" placeholder="Nhập lại mật khẩu mới" autocomplete="new-password">
            <button type="button" class="pw-toggle" data-toggle-pw="confirm_password">
              <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>

        <button type="submit" class="btn-save">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Đổi mật khẩu
        </button>
      </form>
      <?php endif; ?>

    </div>
  </div>
</div>

<script>
(function(){
  var catBtn=document.getElementById('catBtn'),catMenu=document.getElementById('catMenu');
  var userBtn=document.getElementById('userBtn'),userMenu=document.getElementById('userMenu');
  function closeAll(){catMenu&&catMenu.classList.remove('open');userMenu&&userMenu.classList.remove('open');catBtn&&catBtn.classList.remove('open');userBtn&&userBtn.classList.remove('open');}
  catBtn&&catBtn.addEventListener('click',function(e){e.stopPropagation();var o=catMenu.classList.contains('open');closeAll();if(!o){catMenu.classList.add('open');catBtn.classList.add('open');}});
  userBtn&&userBtn.addEventListener('click',function(e){e.stopPropagation();var o=userMenu.classList.contains('open');closeAll();if(!o){userMenu.classList.add('open');userBtn.classList.add('open');}});
  document.addEventListener('click',closeAll);

  /* Password toggle */
  document.querySelectorAll('[data-toggle-pw]').forEach(function(btn){
    btn.addEventListener('click',function(){
      var inp=document.getElementById(btn.getAttribute('data-toggle-pw'));
      inp.type=inp.type==='password'?'text':'password';
    });
  });
})();
</script>
<script src="js/moctra-functions.js"></script>
</body>
</html>


();
</script>
<script src="js/moctra-functions.js"></script>
</body>
</html>


