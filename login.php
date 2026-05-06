<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();
require_once 'config/db.php';

// Nếu đã đăng nhập thì điều hướng ngay theo vai trò
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: admin/dashboard.php");
    } else {
        header("Location: index.php");
    }
    exit();
}

$error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Lỗi CSRF: Token không hợp lệ.");
    }
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = "Vui lòng nhập đầy đủ thông tin.";
    } else {
        $stmt = $conn->prepare("SELECT id, password, role, is_locked, failed_attempts FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user) {
            $error = "Tài khoản không tồn tại trên hệ thống.";
        } elseif ($user['is_locked']) {
            $error = "Tài khoản bị khóa do nhập sai quá nhiều lần. Vui lòng liên hệ hỗ trợ.";
        } elseif (!password_verify($password, $user['password'])) {
            // Logic xử lý sai mật khẩu (tăng số lần thử)
            $newAtt = $user['failed_attempts'] + 1;
            if ($newAtt >= LOGIN_LOCK_THRESHOLD) {
                $stUpd = $conn->prepare("UPDATE users SET is_locked=1, failed_attempts=? WHERE id=?");
                $stUpd->bind_param("ii", $newAtt, $user['id']);
                $stUpd->execute();
                $error = "Tài khoản của bạn đã bị khóa. Vui lòng liên hệ Admin.";
            } else {
                $stUpd = $conn->prepare("UPDATE users SET failed_attempts=? WHERE id=?");
                $stUpd->bind_param("ii", $newAtt, $user['id']);
                $stUpd->execute();
                $remaining = LOGIN_LOCK_THRESHOLD - $newAtt;
                $error = "Mật khẩu không chính xác. Bạn còn $remaining lần thử.";
            }
        } else {
            // ĐĂNG NHẬP THÀNH CÔNG
            $stUpd = $conn->prepare("UPDATE users SET failed_attempts=0 WHERE id=?");
            $stUpd->bind_param("i", $user['id']);
            $stUpd->execute();
            session_regenerate_id(true);
            
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $username;
            $_SESSION['role']     = $user['role'];

            // LOGIC ĐIỀU HƯỚNG THÔNG MINH
            if ($user['role'] === 'admin') {
                header("Location: admin/dashboard.php");
            } else {
                $_SESSION['wishlist_sync_needed'] = true;
                header("Location: index.php");
            }
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Đăng nhập — Mộc Trà Thái Nguyên</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Be Vietnam Pro', sans-serif;
      min-height: 100vh; display: flex; align-items: center; justify-content: center;
      background: url('images/nentam.png') center center / cover no-repeat fixed;
      padding: 20px;
    }
    .box {
      background: #fff; width: 100%; max-width: 450px; min-height: 550px;
      border-radius: 20px; padding: 40px;
      box-shadow: 0 20px 50px rgba(0,0,0,0.15);
      display: flex; flex-direction: column; animation: fadeIn 0.5s ease;
    }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    .logo-area { text-align: center; margin-bottom: 30px; }
    .logo-area img { width: 70px; margin-bottom: 10px; }
    .logo-area h2 { font-size: 24px; color: #111827; margin-bottom: 5px; }
    .logo-area p { font-size: 14px; color: #6b7280; }

    .form-group { margin-bottom: 20px; }
    .label { display: block; font-size: 14px; font-weight: 600; color: #374151; margin-bottom: 8px; }
    .input-wrapper { position: relative; }
    .input-wrapper i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #9ca3af; }
    .inp {
      width: 100%; padding: 12px 14px 12px 42px; border: 1.5px solid #e5e7eb;
      border-radius: 10px; font-size: 15px; outline: none; transition: all 0.2s;
    }
    .inp:focus { border-color: #166534; box-shadow: 0 0 0 4px rgba(22,101,52,0.1); }
    
    .btn-eye { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #9ca3af; cursor: pointer; }

    .opt-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 25px; font-size: 14px; }
    .chk-area { display: flex; align-items: center; gap: 8px; cursor: pointer; }
    .chk-area input { cursor: pointer; accent-color: #166534; }
    .forgot-link { color: #166534; text-decoration: none; font-weight: 600; }

    .btn-submit {
      width: 100%; padding: 14px; background: #166534; color: #fff; border: none;
      border-radius: 10px; font-size: 16px; font-weight: 700; cursor: pointer;
      transition: all 0.3s; box-shadow: 0 4px 12px rgba(22,101,52,0.2);
    }
    .btn-submit:hover { background: #114a2a; transform: translateY(-1px); }

    .alert-err { background: #fef2f2; color: #dc2626; padding: 12px; border-radius: 8px; font-size: 14px; margin-bottom: 20px; border-left: 4px solid #dc2626; }
    
    .footer-text { text-align: center; margin-top: auto; padding-top: 30px; font-size: 14px; color: #6b7280; }
    .footer-text a { color: #166534; font-weight: 700; text-decoration: none; }
  </style>
</head>
<body>

<div class="box">
  <div class="logo-area">
    <img src="images/logo.png" alt="Logo" onerror="this.src='https://cdn-icons-png.flaticon.com/512/3504/3504810.png'">
    <h2>Mộc Trà Thái Nguyên</h2>
    <p>Chào mừng bạn đến với hệ thống của chúng tôi</p>
  </div>

  <?php if ($error): ?>
  <div class="alert-err"><i class="fa-solid fa-circle-exclamation"></i> <?= $error ?></div>
  <?php endif; ?>

  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
    <div class="form-group">
      <label class="label">Tên đăng nhập hoặc Email</label>
      <div class="input-wrapper">
        <i class="fa-solid fa-user"></i>
        <input type="text" name="username" class="inp" placeholder="Nhập tài khoản" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
      </div>
    </div>

    <div class="form-group">
      <label class="label">Mật khẩu</label>
      <div class="input-wrapper">
        <i class="fa-solid fa-lock"></i>
        <input type="password" name="password" id="pwInput" class="inp" placeholder="Nhập mật khẩu" required>
        <button type="button" class="btn-eye" onclick="togglePw()">
          <i class="fa-solid fa-eye" id="eyeIcon"></i>
        </button>
      </div>
    </div>

    <div class="opt-row">
      <label class="chk-area">
        <input type="checkbox" name="remember"> Ghi nhớ đăng nhập
      </label>
      <a href="forgot_password.php" class="forgot-link">Quên mật khẩu?</a>
    </div>

    <button type="submit" class="btn-submit">Đăng nhập ngay</button>
  </form>

  <div class="footer-text">
    Bạn chưa có tài khoản? <a href="register.php">Đăng ký thành viên</a>
  </div>
</div>

<script>
function togglePw() {
  const inp = document.getElementById('pwInput');
  const icon = document.getElementById('eyeIcon');
  if (inp.type === 'password') {
    inp.type = 'text';
    icon.classList.replace('fa-eye', 'fa-eye-slash');
  } else {
    inp.type = 'password';
    icon.classList.replace('fa-eye-slash', 'fa-eye');
  }
}
</script>

</body>
</html>


