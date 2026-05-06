<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    // Đã đăng nhập nhưng không phải admin → báo không đủ quyền
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8">
    <title>Không có quyền truy cập</title>
    <style>
        body{font-family:Arial,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f3f4f6;}
        .box{text-align:center;background:#fff;padding:48px 40px;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.1);}
        h2{color:#e53935;font-size:22px;margin-bottom:12px;}
        p{color:#555;margin-bottom:24px;}
        a{display:inline-block;padding:10px 24px;background:#2e7d32;color:#fff;border-radius:6px;text-decoration:none;font-weight:bold;}
        a:hover{background:#1b5e20;}
    </style></head><body>
    <div class="box">
        <div style="font-size:48px;margin-bottom:16px;">🚫</div>
        <h2>Không có quyền truy cập</h2>
        <p>Tài khoản của bạn không có quyền vào trang quản trị.</p>
        <a href="../index.php">← Về trang chủ</a>
    </div>
    </body></html>';
    exit();
}

$adminName    = htmlspecialchars($_SESSION['username'] ?? 'Admin');
$adminInitial = strtoupper(substr($adminName, 0, 1));

// Tạo CSRF token nếu chưa có
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
