<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();
require_once 'config/db.php';

/* ══════════════════════════════════════════════════════
   Quên mật khẩu — Mộc Trà
   Flow: Bước 1 (username+email) → Bước 2 (OTP) → Bước 3 (mật khẩu mới) → Bước 4 (thành công)
   OTP lưu trong SESSION (không cần DB thêm), hiển thị trực tiếp (dev mode)
   ══════════════════════════════════════════════════════ */

// Nếu đã đăng nhập → về trang chính
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Hủy flow & về bước 1
if (isset($_GET['reset'])) {
    foreach (['fp_step','fp_uid','fp_email','fp_otp','fp_otp_exp',
              'fp_otp_att','fp_resend_cnt','fp_resend_lock'] as $k) {
        unset($_SESSION[$k]);
    }
    header("Location: forgot_password.php");
    exit();
}

$step    = (int)($_SESSION['fp_step'] ?? 1);
$error   = '';
$success = '';
$devOtp  = ''; // Hiển thị OTP trên màn hình (dev/demo)

// Nếu đang ở bước 4 và user refresh bằng GET → reset về bước 1
if ($step === 4 && $_SERVER['REQUEST_METHOD'] === 'GET') {
    unset($_SESSION['fp_step']);
    $step = 1;
}

/* ── Xử lý POST ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* ─ BƯỚC 1: Xác minh username + email ─ */
    if (isset($_POST['btnVerify']) && $step === 1) {
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email']    ?? '');

        if ($username === '' || $email === '') {
            $error = 'Vui lòng nhập đầy đủ thông tin.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email không đúng định dạng.';
        } else {
            $stmt = $conn->prepare(
                "SELECT id, email, is_locked FROM users WHERE username = ? AND email = ? LIMIT 1"
            );
            $stmt->bind_param('ss', $username, $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            if ($user) {
                if ($user['is_locked']) {
                    $error = 'Tài khoản đang bị khóa. Vui lòng liên hệ quản trị viên.';
                } else {
                    // Tạo OTP 6 chữ số
                    $otp = sprintf('%06d', random_int(0, 999999));
                    $_SESSION['fp_step']       = 2;
                    $_SESSION['fp_uid']        = $user['id'];
                    $_SESSION['fp_email']      = $user['email'];
                    $_SESSION['fp_otp']        = $otp;
                    $_SESSION['fp_otp_exp']    = time() + 300; // 5 phút
                    $_SESSION['fp_otp_att']    = 0;
                    $_SESSION['fp_resend_cnt'] = 0;
                    $_SESSION['fp_resend_lock']= 0;
                    $step    = 2;
                    $devOtp  = $otp;
                    $success = 'Mã xác minh đã được tạo thành công.';
                }
            } else {
                $error = 'Thông tin không khớp. Vui lòng kiểm tra lại.';
            }
        }
    }

    /* ─ BƯỚC 2A: Xác minh OTP ─ */
    elseif (isset($_POST['btnOtp']) && $step === 2) {
        $otpInput = trim(str_replace(' ', '', $_POST['otp'] ?? ''));
        $attempts = (int)($_SESSION['fp_otp_att'] ?? 0);

        if (strlen($otpInput) !== 6) {
            $error = 'Vui lòng nhập đủ 6 chữ số.';
        } elseif ($attempts >= 5) {
            $error = 'Quá số lần thử cho phép. Vui lòng yêu cầu mã mới.';
            unset($_SESSION['fp_otp'], $_SESSION['fp_otp_exp'], $_SESSION['fp_otp_att']);
        } elseif (time() > (int)($_SESSION['fp_otp_exp'] ?? 0)) {
            $error = 'Mã xác minh đã hết hạn. Vui lòng nhấn "Gửi lại mã".';
            unset($_SESSION['fp_otp'], $_SESSION['fp_otp_exp']);
        } elseif ($otpInput !== ($_SESSION['fp_otp'] ?? '')) {
            $_SESSION['fp_otp_att']++;
            $remain = 5 - $_SESSION['fp_otp_att'];
            $error  = "Mã xác minh không đúng. Còn $remain lần thử.";
        } else {
            // OTP đúng → chuyển bước 3, vô hiệu hóa OTP
            unset($_SESSION['fp_otp'], $_SESSION['fp_otp_exp'], $_SESSION['fp_otp_att']);
            $_SESSION['fp_step'] = 3;
            $step = 3;
        }
    }

    /* ─ BƯỚC 2B: Gửi lại OTP ─ */
    elseif (isset($_POST['btnResend']) && $step === 2) {
        $resendCnt  = (int)($_SESSION['fp_resend_cnt']  ?? 0);
        $resendLock = (int)($_SESSION['fp_resend_lock'] ?? 0);

        if ($resendCnt >= 3 && time() < $resendLock) {
            $mins  = ceil(($resendLock - time()) / 60);
            $error = "Bạn đã gửi lại quá nhiều lần. Thử lại sau {$mins} phút.";
        } else {
            // Chỉ reset count khi đã từng bị khóa VÀ thời gian khóa đã hết
            if ($resendLock > 0 && time() >= $resendLock) {
                $_SESSION['fp_resend_cnt'] = 0;
            }
            $otp = sprintf('%06d', random_int(0, 999999));
            $_SESSION['fp_otp']     = $otp;
            $_SESSION['fp_otp_exp'] = time() + 300;
            $_SESSION['fp_otp_att'] = 0;
            $_SESSION['fp_resend_cnt']++;
            if ($_SESSION['fp_resend_cnt'] >= 3) {
                $_SESSION['fp_resend_lock'] = time() + 900; // khóa 15 phút
            }
            $devOtp  = $otp;
            $success = 'Mã xác minh mới đã được tạo.';
        }
    }

    /* ─ BƯỚC 3: Đặt lại mật khẩu ─ */
    elseif (isset($_POST['btnReset']) && $step === 3) {
        $pass   = $_POST['password']   ?? '';
        $repass = $_POST['repassword'] ?? '';
        $uid    = (int)($_SESSION['fp_uid'] ?? 0);

        if (!$uid) {
            header('Location: forgot_password.php?reset=1');
            exit();
        }

        if ($pass === '') {
            $error = 'Vui lòng nhập mật khẩu mới.';
        } elseif (strlen($pass) < 6) {
            $error = 'Mật khẩu phải từ 6 ký tự trở lên.';
        } elseif ($pass !== $repass) {
            $error = 'Mật khẩu xác nhận không khớp.';
        } else {
            // Kiểm tra mật khẩu mới không được trùng mật khẩu cũ
            $chkOld = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
            $chkOld->bind_param('i', $uid);
            $chkOld->execute();
            $oldRow = $chkOld->get_result()->fetch_assoc();

            if ($oldRow && password_verify($pass, $oldRow['password'])) {
                $error = 'Mật khẩu mới không được trùng với mật khẩu hiện tại.';
            } else {
            $hashed = password_hash($pass, PASSWORD_BCRYPT);
            $stmt   = $conn->prepare(
                "UPDATE users SET password = ?, is_locked = 0, failed_attempts = 0 WHERE id = ?"
            );
            $stmt->bind_param('si', $hashed, $uid);

            if ($stmt->execute()) {
                // Xóa toàn bộ dữ liệu flow
                foreach (['fp_step','fp_uid','fp_email','fp_otp','fp_otp_exp',
                          'fp_otp_att','fp_resend_cnt','fp_resend_lock'] as $k) {
                    unset($_SESSION[$k]);
                }
                $_SESSION['fp_step'] = 4;
                $step = 4;
            } else {
                $error = 'Có lỗi xảy ra, vui lòng thử lại.';
            }
            } // end else (mật khẩu không trùng)
        }
    }
}

// Mask email để hiển thị
function maskEmail(string $email): string {
    [$local, $domain] = explode('@', $email, 2);
    $show = substr($local, 0, min(3, strlen($local)));
    return $show . str_repeat('*', max(0, strlen($local) - 3)) . '@' . $domain;
}

$maskedEmail  = isset($_SESSION['fp_email']) ? maskEmail($_SESSION['fp_email']) : '';
$otpExpiresAt = (int)($_SESSION['fp_otp_exp'] ?? 0);
$resendLeft   = max(0, 3 - (int)($_SESSION['fp_resend_cnt'] ?? 0));
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Quên mật khẩu — Mộc Trà Thái Nguyên</title>
  <!-- Font Awesome 6 -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
/* Global Icon Styles */
i.fa-solid, i.fa-regular, i.fa-brands {
    color: #2d5a27;
    transition: color 0.3s ease, transform 0.3s ease;
    margin-right: 8px;
}
.text-danger { color: #dc3545; font-weight: bold; margin-left: 2px; }

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: Arial, Helvetica, sans-serif;
  min-height: 100vh;
  display: flex; align-items: center; justify-content: center;
  padding: 24px 16px;
  background: url('images/nentam.png') center center / cover no-repeat fixed;
}

/* ── Card ── */
.box {
  background: #fff;
  width: 100%; max-width: 460px;
  border-radius: 14px;
  padding: 44px 44px 36px;
  box-shadow: 0 20px 60px rgba(0,0,0,.30), 0 4px 16px rgba(0,0,0,.15);
  animation: pop .4s ease both;
}
@keyframes pop {
  from { opacity: 0; transform: translateY(20px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* ── Tiêu đề ── */
.box-title {
  text-align: center;
  color: #0f5132;
  font-size: 24px;
  font-weight: bold;
  margin-bottom: 6px;
}
.box-sub {
  text-align: center;
  font-size: 13.5px;
  color: #666;
  margin-bottom: 28px;
  line-height: 1.5;
}

/* ── Steps indicator ── */
.steps {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0;
  margin-bottom: 28px;
}
.step-dot {
  width: 28px; height: 28px;
  border-radius: 50%;
  background: #e0e0e0;
  color: #999;
  font-size: 12px; font-weight: bold;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  transition: background .3s, color .3s;
  position: relative; z-index: 1;
}
.step-dot.active  { background: #2e7d32; color: #fff; }
.step-dot.done    { background: #a5d6a7; color: #fff; }
.step-line {
  flex: 1; height: 2px;
  background: #e0e0e0;
  transition: background .3s;
  max-width: 48px;
}
.step-line.done { background: #a5d6a7; }

/* ── Alert ── */
.alert {
  padding: 10px 13px;
  border-radius: 6px;
  font-size: 13.5px;
  margin-bottom: 16px;
  line-height: 1.45;
  display: none;
}
.alert.on { display: block; }
.alert-err { background: #ffebee; color: #b71c1c; border-left: 4px solid #e53935; }
.alert-ok  { background: #e8f5e9; color: #1b5e20; border-left: 4px solid #43a047; }

/* Dev OTP box */
.dev-otp {
  display: none;
  padding: 12px 14px;
  background: #fff8e1;
  border: 1.5px dashed #fb8c00;
  border-radius: 8px;
  margin-bottom: 16px;
  font-size: 13px;
  color: #6d4c00;
  line-height: 1.6;
}
.dev-otp.on { display: block; }
.dev-otp-code {
  font-size: 24px;
  font-weight: bold;
  letter-spacing: 6px;
  color: #e65100;
  display: block;
  margin-top: 4px;
  text-align: center;
}

/* ── Form fields ── */
.fg { margin-bottom: 16px; }
.fg-label {
  display: block;
  font-size: 14px; font-weight: bold;
  color: #333; margin-bottom: 6px;
}
.req { color: #e53935; }
.inp {
  width: 100%; padding: 12px 14px;
  border: 1.5px solid #ccc; border-radius: 6px;
  font-size: 15px; font-family: Arial, sans-serif;
  background: #f8f8f8; outline: none; display: block;
  transition: border-color .2s, box-shadow .2s, background .2s;
}
.inp:focus { border-color: #2e7d32; background: #fff; box-shadow: 0 0 0 3px rgba(46,125,50,.15); }
.inp.is-err { border-color: #e53935; box-shadow: 0 0 0 3px rgba(229,57,53,.10); }
.ferr { font-size: 12px; color: #e53935; margin-top: 4px; display: none; }
.ferr.on { display: block; }

/* Password eye */
.pw-wrap { position: relative; }
.pw-wrap .inp { padding-right: 42px; }
.btn-eye {
  position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
  background: none; border: none; cursor: pointer;
  padding: 4px; color: #999;
  display: flex; align-items: center;
  transition: color .2s; line-height: 0;
}
.btn-eye:hover { color: #2e7d32; }
.btn-eye svg { width: 18px; height: 18px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

/* Password strength */
.pw-str { display: none; margin-top: 8px; }
.str-track { display: flex; gap: 4px; margin-bottom: 4px; }
.str-seg { flex: 1; height: 4px; background: #e0e0e0; border-radius: 99px; transition: background .3s; }
.str-seg.weak   { background: #e53935; }
.str-seg.medium { background: #fb8c00; }
.str-seg.strong { background: #2e7d32; }
.str-txt { font-size: 12px; font-weight: bold; }
.str-txt.weak   { color: #e53935; }
.str-txt.medium { color: #fb8c00; }
.str-txt.strong { color: #2e7d32; }

/* OTP input boxes */
.otp-wrap {
  display: flex;
  gap: 8px;
  justify-content: center;
  margin-bottom: 4px;
}
.otp-box {
  width: 46px; height: 52px;
  border: 2px solid #ccc;
  border-radius: 8px;
  font-size: 22px; font-weight: bold;
  text-align: center;
  outline: none;
  background: #f8f8f8;
  color: #0f5132;
  transition: border-color .2s, box-shadow .2s, background .2s;
  caret-color: transparent;
}
.otp-box:focus { border-color: #2e7d32; background: #fff; box-shadow: 0 0 0 3px rgba(46,125,50,.15); }
.otp-box.filled { border-color: #2e7d32; background: #f1f8f2; }
.otp-box.is-err { border-color: #e53935; background: #fff5f5; }

/* Countdown */
.countdown-wrap {
  text-align: center;
  font-size: 13px;
  color: #666;
  margin: 8px 0 14px;
}
.countdown-num { font-weight: bold; color: #2e7d32; transition: color .3s; }
.countdown-num.urgent { color: #e53935; }

/* ── Buttons ── */
.btn {
  width: 100%; padding: 13px;
  background: #2e7d32; border: none;
  color: #fff; font-size: 15px; font-weight: bold;
  font-family: Arial, sans-serif;
  border-radius: 6px; cursor: pointer;
  box-shadow: 0 4px 14px rgba(46,125,50,.35);
  transition: background .25s, transform .2s, box-shadow .25s;
  margin-top: 4px;
}
.btn:hover { background: #1b5e20; transform: translateY(-2px); box-shadow: 0 6px 20px rgba(46,125,50,.40); }
.btn:active { transform: translateY(0); }
.btn:disabled { background: #a5d6a7; box-shadow: none; cursor: not-allowed; transform: none; }

.btn-ghost {
  width: 100%; padding: 11px;
  background: none;
  border: 1.5px solid #a5d6a7;
  color: #2e7d32; font-size: 14px; font-weight: bold;
  font-family: Arial, sans-serif;
  border-radius: 6px; cursor: pointer;
  transition: background .2s, border-color .2s;
  margin-top: 8px;
}
.btn-ghost:hover { background: #f1f8f2; border-color: #2e7d32; }
.btn-ghost:disabled { color: #bbb; border-color: #e0e0e0; cursor: not-allowed; background: none; }

/* ── Success screen ── */
.success-screen { text-align: center; padding: 10px 0; }
.success-icon {
  font-size: 56px;
  margin-bottom: 14px;
  display: block;
  animation: bounce .6s ease both .2s;
}
@keyframes bounce {
  0%   { transform: scale(0); opacity: 0; }
  70%  { transform: scale(1.15); }
  100% { transform: scale(1); opacity: 1; }
}
.success-title { font-size: 22px; font-weight: bold; color: #0f5132; margin-bottom: 8px; }
.success-sub   { font-size: 13.5px; color: #555; line-height: 1.6; margin-bottom: 24px; }
.redirect-note { font-size: 12px; color: #999; margin-top: 10px; }

/* ── Footer ── */
.footer-link {
  text-align: center;
  margin-top: 20px;
  font-size: 14px;
  color: #555;
}
.footer-link a {
  color: #2e7d32; font-weight: bold;
  text-decoration: none;
}
.footer-link a:hover { text-decoration: underline; }

.divider { border: none; border-top: 1px solid #f0f0f0; margin: 18px 0; }
</style>
</head>
<body>
<div class="box">

  <!-- ── Tiêu đề & Step indicator ── -->
  <div class="box-title">🔒 Quên mật khẩu</div>

  <?php if ($step < 4): ?>
  <div class="box-sub">
    <?php if ($step === 1): ?>Nhập thông tin tài khoản để xác minh danh tính
    <?php elseif ($step === 2): ?>Nhập mã xác minh đã gửi đến <strong><?= htmlspecialchars($maskedEmail) ?></strong>
    <?php elseif ($step === 3): ?>Đặt mật khẩu mới cho tài khoản của bạn
    <?php endif; ?>
  </div>

  <!-- Steps -->
  <div class="steps">
    <div class="step-dot <?= $step === 1 ? 'active' : 'done' ?>">1</div>
    <div class="step-line <?= $step > 1 ? 'done' : '' ?>"></div>
    <div class="step-dot <?= $step === 2 ? 'active' : ($step > 2 ? 'done' : '') ?>">2</div>
    <div class="step-line <?= $step > 2 ? 'done' : '' ?>"></div>
    <div class="step-dot <?= $step === 3 ? 'active' : ($step > 3 ? 'done' : '') ?>">3</div>
    <div class="step-line <?= $step > 3 ? 'done' : '' ?>"></div>
    <div class="step-dot <?= $step === 4 ? 'active' : '' ?>">✓</div>
  </div>
  <?php endif; ?>

  <!-- Alert lỗi -->
  <div class="alert alert-err <?= $error ? 'on' : '' ?>" id="alertErr">
    <?= htmlspecialchars($error) ?>
  </div>

  <!-- Alert thành công -->
  <div class="alert alert-ok <?= $success ? 'on' : '' ?>" id="alertOk">
    <?= htmlspecialchars($success) ?>
  </div>

  <!-- Dev OTP box -->
  <?php if ($devOtp): ?>
  <div class="dev-otp on">
    ⚠️ <strong>[Chế độ Demo]</strong> Mã xác minh của bạn là:
    <span class="dev-otp-code"><?= $devOtp ?></span>
    <small style="display:block;text-align:center;margin-top:4px;color:#999">Hiệu lực trong 5 phút</small>
  </div>
  <?php endif; ?>

  <!-- ════════════════════════════════════
       BƯỚC 1 — Xác minh tài khoản
       ════════════════════════════════════ -->
  <?php if ($step === 1): ?>
  <form method="POST" id="form1" novalidate>
    <div class="fg">
      <label class="fg-label" for="inp-un">Tên đăng nhập <span class="text-danger">*</span></label>
      <input class="inp" type="text" id="inp-un" name="username"
        placeholder="Nhập tên đăng nhập của bạn"
        value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
        autocomplete="username" maxlength="50">
      <div class="ferr" id="ferr-un"></div>
    </div>

    <div class="fg">
      <label class="fg-label" for="inp-em">Email đăng ký <span class="text-danger">*</span></label>
      <input class="inp" type="email" id="inp-em" name="email"
        placeholder="example@email.com"
        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
        autocomplete="email">
      <div class="ferr" id="ferr-em"></div>
    </div>

    <button type="submit" name="btnVerify" class="btn">Gửi mã xác minh</button>
  </form>

  <script>
  document.getElementById('form1').addEventListener('submit', function(e) {
    var ok = true;
    var un = document.getElementById('inp-un').value.trim();
    var em = document.getElementById('inp-em').value.trim();
    var ferrUn = document.getElementById('ferr-un');
    var ferrEm = document.getElementById('ferr-em');
    ferrUn.classList.remove('on'); ferrEm.classList.remove('on');
    document.getElementById('inp-un').classList.remove('is-err');
    document.getElementById('inp-em').classList.remove('is-err');

    if (!un) {
      ferrUn.textContent = 'Vui lòng nhập tên đăng nhập.';
      ferrUn.classList.add('on');
      document.getElementById('inp-un').classList.add('is-err');
      ok = false;
    }
    if (!em) {
      ferrEm.textContent = 'Vui lòng nhập email.';
      ferrEm.classList.add('on');
      document.getElementById('inp-em').classList.add('is-err');
      ok = false;
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) {
      ferrEm.textContent = 'Email không đúng định dạng.';
      ferrEm.classList.add('on');
      document.getElementById('inp-em').classList.add('is-err');
      ok = false;
    }
    if (!ok) e.preventDefault();
  });
  </script>

  <!-- ════════════════════════════════════
       BƯỚC 2 — Nhập OTP
       ════════════════════════════════════ -->
  <?php elseif ($step === 2): ?>
  <form method="POST" id="form2" novalidate>
    <div class="fg">
      <label class="fg-label" style="text-align:center;display:block">Nhập mã 6 chữ số <span class="req">*</span></label>
      <!-- 6 ô OTP riêng biệt -->
      <div class="otp-wrap" id="otpWrap">
        <?php for ($i = 1; $i <= 6; $i++): ?>
        <input class="otp-box" type="text" inputmode="numeric"
          id="otp<?= $i ?>" maxlength="1" autocomplete="off"
          pattern="[0-9]">
        <?php endfor; ?>
      </div>
      <!-- Hidden field gộp OTP để submit -->
      <input type="hidden" name="otp" id="otpHidden">
      <div class="ferr" id="ferr-otp" style="text-align:center"></div>
    </div>

    <!-- Countdown -->
    <div class="countdown-wrap">
      Mã hết hạn sau: <span class="countdown-num" id="cdNum">05:00</span>
    </div>

    <button type="submit" name="btnOtp" class="btn" id="btnOtpSubmit">Xác minh</button>
  </form>

  <hr class="divider">

  <form method="POST">
    <button type="submit" name="btnResend" class="btn-ghost"
      id="btnResend" <?= $resendLeft <= 0 ? 'disabled' : '' ?>>
      Gửi lại mã <?= $resendLeft > 0 ? "({$resendLeft} lần còn lại)" : '(đã hết lượt)' ?>
    </button>
  </form>

  <script>
  (function() {
    /* ── OTP boxes: tự động focus, xóa, paste ── */
    var boxes = [];
    for (var i = 1; i <= 6; i++) boxes.push(document.getElementById('otp' + i));
    var hidden = document.getElementById('otpHidden');

    function sync() {
      hidden.value = boxes.map(function(b){ return b.value; }).join('');
    }

    boxes.forEach(function(box, idx) {
      box.addEventListener('keydown', function(e) {
        if (e.key === 'Backspace') {
          if (box.value === '' && idx > 0) {
            boxes[idx-1].value = '';
            boxes[idx-1].focus();
            boxes[idx-1].classList.remove('filled');
          } else {
            box.value = '';
            box.classList.remove('filled');
          }
          sync();
          e.preventDefault();
        } else if (e.key === 'ArrowLeft' && idx > 0) {
          boxes[idx-1].focus();
        } else if (e.key === 'ArrowRight' && idx < 5) {
          boxes[idx+1].focus();
        }
      });

      box.addEventListener('input', function() {
        var v = box.value.replace(/\D/g,'');
        box.value = v.slice(-1);
        box.classList.toggle('filled', box.value !== '');
        if (box.value && idx < 5) boxes[idx+1].focus();
        sync();
        // Auto submit khi điền đủ 6 ô — dùng .click() để giữ name="btnOtp" trong POST
        if (hidden.value.length === 6) {
          document.getElementById('btnOtpSubmit').click();
        }
      });

      box.addEventListener('paste', function(e) {
        e.preventDefault();
        var pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g,'');
        for (var j = 0; j < 6 && j < pasted.length; j++) {
          boxes[j].value = pasted[j];
          boxes[j].classList.add('filled');
        }
        sync();
        var focusIdx = Math.min(pasted.length, 5);
        boxes[focusIdx].focus();
        if (pasted.length >= 6) document.getElementById('btnOtpSubmit').click();
      });
    });

    /* ── Countdown timer ── */
    var expiresAt = <?= $otpExpiresAt ?>;
    var cdEl = document.getElementById('cdNum');

    function updateCountdown() {
      var now  = Math.floor(Date.now() / 1000);
      var left = expiresAt - now;
      if (left <= 0) {
        cdEl.textContent = '00:00';
        cdEl.classList.add('urgent');
        document.getElementById('btnOtpSubmit').disabled = true;
        document.getElementById('btnOtpSubmit').textContent = 'Mã đã hết hạn';
        return;
      }
      var mm = String(Math.floor(left / 60)).padStart(2, '0');
      var ss = String(left % 60).padStart(2, '0');
      cdEl.textContent = mm + ':' + ss;
      cdEl.classList.toggle('urgent', left <= 60);
      setTimeout(updateCountdown, 1000);
    }
    updateCountdown();

    /* ── Auto focus ô đầu tiên ── */
    boxes[0].focus();
  })();
  </script>

  <!-- ════════════════════════════════════
       BƯỚC 3 — Đặt mật khẩu mới
       ════════════════════════════════════ -->
  <?php elseif ($step === 3): ?>
  <form method="POST" id="form3" novalidate>
    <div class="fg">
      <label class="fg-label" for="inp-pw">Mật khẩu mới <span class="text-danger">*</span></label>
      <div class="pw-wrap">
        <input class="inp" type="password" id="inp-pw" name="password"
          placeholder="Tối thiểu 6 ký tự"
          autocomplete="new-password" maxlength="64">
        <button type="button" class="btn-eye" id="eyeBtn1" aria-label="Hiện/ẩn mật khẩu">
          <svg id="ico-show1" viewBox="0 0 24 24">
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
            <circle cx="12" cy="12" r="3"/>
          </svg>
          <svg id="ico-hide1" viewBox="0 0 24 24" style="display:none">
            <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/>
            <path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/>
            <line x1="1" y1="1" x2="23" y2="23"/>
          </svg>
        </button>
      </div>
      <div class="ferr" id="ferr-pw"></div>
      <div class="pw-str" id="pwStr">
        <div class="str-track">
          <div class="str-seg" id="seg1"></div>
          <div class="str-seg" id="seg2"></div>
          <div class="str-seg" id="seg3"></div>
          <div class="str-seg" id="seg4"></div>
        </div>
        <span class="str-txt" id="strTxt"></span>
      </div>
    </div>

    <div class="fg">
      <label class="fg-label" for="inp-rp">Xác nhận mật khẩu <span class="text-danger">*</span></label>
      <div class="pw-wrap">
        <input class="inp" type="password" id="inp-rp" name="repassword"
          placeholder="Nhập lại mật khẩu mới"
          autocomplete="new-password" maxlength="64">
        <button type="button" class="btn-eye" id="eyeBtn2" aria-label="Hiện/ẩn mật khẩu">
          <svg id="ico-show2" viewBox="0 0 24 24">
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
            <circle cx="12" cy="12" r="3"/>
          </svg>
          <svg id="ico-hide2" viewBox="0 0 24 24" style="display:none">
            <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/>
            <path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/>
            <line x1="1" y1="1" x2="23" y2="23"/>
          </svg>
        </button>
      </div>
      <div class="ferr" id="ferr-rp"></div>
    </div>

    <button type="submit" name="btnReset" class="btn">Đặt lại mật khẩu</button>
  </form>

  <script>
  (function() {
    /* Eye toggles */
    function eyeToggle(btnId, inpId, showId, hideId) {
      document.getElementById(btnId).addEventListener('click', function() {
        var inp  = document.getElementById(inpId);
        var show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        document.getElementById(showId).style.display = show ? 'none' : '';
        document.getElementById(hideId).style.display = show ? ''     : 'none';
      });
    }
    eyeToggle('eyeBtn1','inp-pw','ico-show1','ico-hide1');
    eyeToggle('eyeBtn2','inp-rp','ico-show2','ico-hide2');

    /* Password strength */
    var pwInp  = document.getElementById('inp-pw');
    var pwStr  = document.getElementById('pwStr');
    var segs   = ['seg1','seg2','seg3','seg4'].map(function(id){ return document.getElementById(id); });
    var strTxt = document.getElementById('strTxt');

    pwInp.addEventListener('input', function() {
      var v = pwInp.value;
      if (!v) { pwStr.style.display = 'none'; return; }
      pwStr.style.display = 'block';
      var sc = 0;
      if (v.length >= 6)            sc++;
      if (/[A-Z]/.test(v))          sc++;
      if (/[0-9]/.test(v))          sc++;
      if (/[^A-Za-z0-9]/.test(v))   sc++;
      var lvl  = sc <= 1 ? 'weak' : sc <= 3 ? 'medium' : 'strong';
      var fill = { weak:1, medium:3, strong:4 };
      var txt  = { weak:'Mật khẩu yếu', medium:'Mật khẩu trung bình', strong:'Mật khẩu mạnh' };
      segs.forEach(function(s,i){ s.className = 'str-seg' + (i < fill[lvl] ? ' ' + lvl : ''); });
      strTxt.textContent = txt[lvl];
      strTxt.className   = 'str-txt ' + lvl;
    });

    /* Client validate */
    document.getElementById('form3').addEventListener('submit', function(e) {
      var ok  = true;
      var pv  = pwInp.value;
      var rpv = document.getElementById('inp-rp').value;
      var ferrPw = document.getElementById('ferr-pw');
      var ferrRp = document.getElementById('ferr-rp');
      ferrPw.classList.remove('on'); ferrRp.classList.remove('on');
      pwInp.classList.remove('is-err');
      document.getElementById('inp-rp').classList.remove('is-err');

      if (!pv) {
        ferrPw.textContent = 'Vui lòng nhập mật khẩu mới.';
        ferrPw.classList.add('on'); pwInp.classList.add('is-err');
        ok = false;
      } else if (pv.length < 6) {
        ferrPw.textContent = 'Mật khẩu phải từ 6 ký tự trở lên.';
        ferrPw.classList.add('on'); pwInp.classList.add('is-err');
        ok = false;
      }
      if (!rpv) {
        ferrRp.textContent = 'Vui lòng xác nhận mật khẩu.';
        ferrRp.classList.add('on');
        document.getElementById('inp-rp').classList.add('is-err');
        ok = false;
      } else if (pv !== rpv) {
        ferrRp.textContent = 'Mật khẩu xác nhận không khớp.';
        ferrRp.classList.add('on');
        document.getElementById('inp-rp').classList.add('is-err');
        ok = false;
      }
      if (!ok) e.preventDefault();
    });
  })();
  </script>

  <!-- ════════════════════════════════════
       BƯỚC 4 — Thành công
       ════════════════════════════════════ -->
  <?php elseif ($step === 4): ?>
  <div class="success-screen">
    <span class="success-icon">✅</span>
    <div class="success-title">Đặt lại mật khẩu thành công!</div>
    <div class="success-sub">
      Mật khẩu của bạn đã được cập nhật.<br>
      Vui lòng đăng nhập lại với mật khẩu mới.
    </div>
    <a href="login.php" class="btn" style="display:block;text-decoration:none;text-align:center">
      Đến trang Đăng nhập
    </a>
    <div class="redirect-note" id="redirectNote">Tự động chuyển hướng sau <span id="rdSec">3</span> giây...</div>
  </div>
  <script>
  (function() {
    var sec = 3;
    var el = document.getElementById('rdSec');
    var t = setInterval(function() {
      sec--;
      if (el) el.textContent = sec;
      if (sec <= 0) {
        clearInterval(t);
        window.location.href = 'login.php';
      }
    }, 1000);
  })();
  </script>
  <?php endif; ?>

  <!-- Footer links (bước 1, 2, 3) -->
  <?php if ($step < 4): ?>
  <div class="footer-link">
    <?php if ($step > 1 && $step < 4): ?>
      <a href="forgot_password.php?reset=1">← Bắt đầu lại</a> &nbsp;·&nbsp;
    <?php endif; ?>
    <a href="login.php">Quay lại Đăng nhập</a>
  </div>
  <?php endif; ?>

</div>
</body>
</html>


