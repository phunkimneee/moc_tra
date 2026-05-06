<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();
require_once 'config/db.php';

if (isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }

/* ── Tạo captcha lần đầu ── */
function newCaptcha(): void {
    $pool = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 6; $i++) $code .= $pool[random_int(0, strlen($pool) - 1)];
    $_SESSION['cap_code']    = $code;
    $_SESSION['cap_expires'] = time() + 300;
}
if (empty($_SESSION['cap_code']) || time() > ($_SESSION['cap_expires'] ?? 0)) {
    newCaptcha();
}

$err = [];

/* ── POST: chỉ chạy khi JS đã validate xong và submit thật ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email']    ?? '');
    $phone    = trim($_POST['phone']    ?? '');
    $password = $_POST['password']      ?? '';

    /* Server re-validate (bảo mật) */
    $capInput  = strtoupper(trim($_POST['captcha'] ?? ''));
    $capServer = strtoupper($_SESSION['cap_code'] ?? '');
    $capExpiry = (int)($_SESSION['cap_expires'] ?? 0);
    $csrfToken = $_POST['csrf_token'] ?? '';

    if ($csrfToken === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        $err['general'] = 'Lỗi bảo mật (CSRF). Vui lòng tải lại trang.';
    } elseif ($username === '' || $email === '' || $phone === '' || $password === '') {
        $err['general'] = 'Vui lòng nhập đầy đủ thông tin.';
    } elseif ($capInput === '' || $capInput !== $capServer || time() > $capExpiry) {
        $err['general'] = 'Mã xác nhận không đúng hoặc đã hết hạn.';
        newCaptcha();
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err['email'] = 'Email không hợp lệ.';
    } elseif (!preg_match('/^(0|\+84)[0-9]{9}$/', $phone)) {
        $err['phone'] = 'Số điện thoại không hợp lệ.';
    } elseif (strlen($password) < 6) {
        $err['password'] = 'Mật khẩu tối thiểu 6 ký tự.';
    } else {
        /* Kiểm tra username trùng */
        $chk = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $chk->bind_param("s", $username);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $err['username'] = 'Tên đăng nhập đã tồn tại, vui lòng chọn tên khác.';
        }
    }

    if (empty($err)) {
        $hash    = password_hash($password, PASSWORD_DEFAULT);
        $address = '';
        $ins = $conn->prepare(
            "INSERT INTO users (username,password,email,phone,address,role,is_locked,failed_attempts)
             VALUES (?,?,?,?,?,'customer',0,0)"
        );
        $ins->bind_param("sssss", $username, $hash, $email, $phone, $address);
        if ($ins->execute()) {
            unset($_SESSION['cap_code'], $_SESSION['cap_expires']);
            session_regenerate_id(true); // Chống session fixation
            header("Location: login.php?registered=1");
            exit();
        } else {
            $err['general'] = 'Đã xảy ra lỗi khi tạo tài khoản, vui lòng thử lại.';
        }
    }
    /* Nếu có lỗi server-side: tiếp tục render form bên dưới với $err */
}

$capCode = htmlspecialchars($_SESSION['cap_code'] ?? '');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Đăng ký — Mộc Trà Thái Nguyên</title>
  <!-- Font Awesome 6 -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
  font-family: 'Be Vietnam Pro', Arial, sans-serif;
  min-height: 100vh;
  display: flex; align-items: center; justify-content: center;
  padding: 24px 16px;
  background: url('images/nentam.png') center center / cover no-repeat fixed;
}
.box {
  background: #fff; width: 100%; max-width: 460px;
  border-radius: 16px; padding: 40px 44px 36px;
  box-shadow: 0 25px 60px rgba(0,0,0,.22), 0 4px 16px rgba(0,0,0,.12);
  animation: pop .4s ease both;
}
@keyframes pop { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }
.box-logo {
  display: flex; flex-direction: column; align-items: center; margin-bottom: 24px; gap: 6px;
}
.box-logo-icon {
  width: 52px; height: 52px;
  background: linear-gradient(135deg, #0f5132, #166534);
  border-radius: 14px;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 4px 14px rgba(22,101,52,.35);
}
.box-logo-icon svg { width: 26px; height: 26px; stroke: #fff; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.box-logo-name { font-size: 13px; font-weight: 600; color: #6b7280; }
.box h2 { text-align:center; color:#111827; font-size:22px; font-weight:700; margin-bottom:4px; }
.box-sub-text { text-align:center; font-size:13.5px; color:#6b7280; margin-bottom:24px; }
.fg { margin-bottom: 16px; }
.fg-label { display:block; font-size:14px; font-weight:bold; color:#333; margin-bottom:6px; }
.req { color: #e53935; }
.inp {
  width: 100%; padding: 0 14px;
  border: 1.5px solid #d1d5db; border-radius: 8px;
  font-size: 14px; font-family: 'Be Vietnam Pro', Arial, sans-serif;
  background: #fff; color: #111827; outline: none; display: block;
  height: 42px;
  transition: border-color .2s, box-shadow .2s;
}
.inp::placeholder { color: #9ca3af; }
.inp:focus { border-color: #166634; box-shadow: 0 0 0 3px rgba(22,101,52,.12); }
.inp.is-err { border-color: #dc2626; box-shadow: 0 0 0 3px rgba(220,38,38,.10); }
.pw-wrap { position:relative; }
.pw-wrap .inp { padding-right:40px; }
.btn-eye {
  position:absolute; right:10px; top:50%; transform:translateY(-50%);
  background:none; border:none; cursor:pointer; padding:4px; color:#999;
  display:flex; align-items:center; transition:color .2s; line-height:0;
}
.btn-eye:hover { color:#2e7d32; }
.btn-eye svg { width:18px; height:18px; stroke:currentColor; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
.ferr { font-size:12px; color:#e53935; margin-top:4px; display:none; }
.ferr.on { display:block; }
.pw-str { display:none; margin-top:7px; }
.str-track { display:flex; gap:4px; margin-bottom:3px; }
.str-seg { flex:1; height:4px; background:#e0e0e0; border-radius:99px; transition:background .3s; }
.str-seg.weak{background:#e53935} .str-seg.medium{background:#fb8c00} .str-seg.strong{background:#2e7d32}
.str-txt { font-size:12px; font-weight:bold; }
.str-txt.weak{color:#e53935} .str-txt.medium{color:#fb8c00} .str-txt.strong{color:#2e7d32}
.cap-row { display:flex; align-items:stretch; gap:8px; }
.btn-refresh {
  flex-shrink:0; width:36px;
  background:#e8f5e9; border:1.5px solid #a5d6a7;
  border-radius:6px; color:#2e7d32;
  cursor:pointer; display:flex; align-items:center; justify-content:center;
  transition:background .2s, color .2s, border-color .2s, transform .3s;
}
.btn-refresh:hover { background:#2e7d32; border-color:#2e7d32; color:#fff; transform:rotate(180deg); }
.btn-refresh svg { width:15px; height:15px; stroke:currentColor; fill:none; stroke-width:2.2; stroke-linecap:round; stroke-linejoin:round; }
.cap-inp { flex:1; }
.alert-box {
  display:none; padding:10px 13px; border-radius:6px;
  font-size:13.5px; margin-bottom:14px; line-height:1.45;
  border-left:4px solid #e53935; background:#ffebee; color:#b71c1c;
}
.alert-box.on { display:block; }
.btn-submit {
  width: 100%; height: 44px; padding: 0 20px;
  background: #166534; border: none;
  color: #fff; font-size: 15px; font-weight: 600;
  font-family: 'Be Vietnam Pro', Arial, sans-serif;
  border-radius: 8px; cursor: pointer;
  box-shadow: 0 1px 2px rgba(0,0,0,.06), 0 4px 12px rgba(22,101,52,.25);
  transition: background .18s, transform .14s, box-shadow .18s;
  display: flex; align-items: center; justify-content: center; gap: 8px;
  margin-top: 4px;
}
.btn-submit:hover { background: #14532d; transform: translateY(-1px); box-shadow: 0 6px 18px rgba(22,101,52,.35); }
.btn-submit:active { transform: translateY(0); }
.btn-submit:focus-visible { outline: 2px solid #166534; outline-offset: 2px; }
.footer-link { text-align:center; margin-top:22px; font-size:14px; color:#6b7280; }
.footer-link a { color:#166534; font-weight:600; text-decoration:none; }
.footer-link a:hover { text-decoration:underline; }
</style>
</head>
<body>
<div class="box">
  <div class="box-logo">
    <div class="box-logo-icon">
      <svg viewBox="0 0 24 24"><path d="M17 8h1a4 4 0 0 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4V8z"/><line x1="6" y1="2" x2="6" y2="4"/><line x1="10" y1="2" x2="10" y2="4"/><line x1="14" y1="2" x2="14" y2="4"/></svg>
    </div>
    <div class="box-logo-name">Mộc Trà Thái Nguyên</div>
  </div>
  <h2>Tạo tài khoản mới</h2>
  <p class="box-sub-text">Điền thông tin để bắt đầu mua sắm</p>

  <?php if (!empty($err['general'])): ?>
  <div class="alert-box on"><?= htmlspecialchars($err['general']) ?></div>
  <?php endif; ?>

  <form method="POST" id="regForm" novalidate>
    <!-- Hidden: captcha secret cho JS so sánh -->
    <input type="hidden" id="cap-secret" value="<?= $capCode ?>">

    <!-- Tên đăng nhập -->
    <div class="fg">
      <label class="fg-label" for="inp-un">Tên đăng nhập <span class="text-danger">*</span></label>
      <input class="inp <?= isset($err['username']) ? 'is-err' : '' ?>"
        type="text" id="inp-un" name="username"
        placeholder="Nhập tên đăng nhập"
        value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
        autocomplete="username" maxlength="30">
      <div class="ferr <?= isset($err['username']) ? 'on' : '' ?>" id="ferr-un">
        <?= htmlspecialchars($err['username'] ?? '') ?>
      </div>
    </div>

    <!-- Email -->
    <div class="fg">
      <label class="fg-label" for="inp-em">Email <span class="text-danger">*</span></label>
      <input class="inp <?= isset($err['email']) ? 'is-err' : '' ?>"
        type="email" id="inp-em" name="email"
        placeholder="example@email.com"
        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
        autocomplete="email">
      <div class="ferr <?= isset($err['email']) ? 'on' : '' ?>" id="ferr-em">
        <?= htmlspecialchars($err['email'] ?? '') ?>
      </div>
    </div>

    <!-- Số điện thoại -->
    <div class="fg">
      <label class="fg-label" for="inp-ph">Số điện thoại <span class="text-danger">*</span></label>
      <input class="inp <?= isset($err['phone']) ? 'is-err' : '' ?>"
        type="tel" id="inp-ph" name="phone"
        placeholder="0912345678"
        value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
        autocomplete="tel" maxlength="15">
      <div class="ferr <?= isset($err['phone']) ? 'on' : '' ?>" id="ferr-ph">
        <?= htmlspecialchars($err['phone'] ?? '') ?>
      </div>
    </div>

    <!-- Mật khẩu -->
    <div class="fg">
      <label class="fg-label" for="inp-pw">Mật khẩu <span class="text-danger">*</span></label>
      <div class="pw-wrap">
        <input class="inp <?= isset($err['password']) ? 'is-err' : '' ?>"
          type="password" id="inp-pw" name="password"
          placeholder="6–32 ký tự"
          autocomplete="new-password" maxlength="32">
        <button type="button" class="btn-eye" id="eyeBtn" aria-label="Hiện/ẩn mật khẩu">
          <svg id="ico-show" viewBox="0 0 24 24">
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
            <circle cx="12" cy="12" r="3"/>
          </svg>
          <svg id="ico-hide" viewBox="0 0 24 24" style="display:none">
            <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/>
            <path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/>
            <line x1="1" y1="1" x2="23" y2="23"/>
          </svg>
        </button>
      </div>
      <div class="ferr <?= isset($err['password']) ? 'on' : '' ?>" id="ferr-pw">
        <?= htmlspecialchars($err['password'] ?? '') ?>
      </div>
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

    <!-- Captcha -->
    <div class="fg">
      <label class="fg-label">Mã xác nhận <span class="text-danger">*</span></label>
      <div class="cap-row">
        <canvas id="capCanvas" width="130" height="44"
          style="border-radius:6px;cursor:default;flex-shrink:0;display:block;"></canvas>
        <button type="button" class="btn-refresh" id="btnRefresh" aria-label="Làm mới mã">
          <svg viewBox="0 0 24 24">
            <polyline points="23 4 23 10 17 10"/>
            <polyline points="1 20 1 14 7 14"/>
            <path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>
          </svg>
        </button>
        <input class="inp cap-inp"
          type="text" id="inp-cap" name="captcha"
          placeholder="Nhập mã trên"
          autocomplete="off" maxlength="6">
      </div>
      <div class="ferr" id="ferr-cap"></div>
    </div>

    <button type="submit" class="btn-submit">
      <svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
      Tạo tài khoản
    </button>
  </form>

  <div class="footer-link">
    Đã có tài khoản? <a href="login.php">Đăng nhập</a>
  </div>
</div>

<script>
(function () {
  'use strict';

  /* ══ CANVAS CAPTCHA ══════════════════════════ */
  var canvas    = document.getElementById('capCanvas');
  var ctx       = canvas.getContext('2d');
  var capSecret = document.getElementById('cap-secret');

  function rand(a, b) { return Math.random() * (b - a) + a; }

  function drawCaptcha(code) {
    var W = canvas.width, H = canvas.height;
    ctx.clearRect(0, 0, W, H);

    /* Nền xanh */
    var g = ctx.createLinearGradient(0, 0, W, H);
    g.addColorStop(0, '#0f5132');
    g.addColorStop(1, '#2e7d32');
    ctx.fillStyle = g;
    ctx.beginPath();
    if (ctx.roundRect) { ctx.roundRect(0, 0, W, H, 6); }
    else { ctx.rect(0, 0, W, H); }
    ctx.fill();

    /* Đường nhiễu */
    for (var n = 0; n < 5; n++) {
      ctx.beginPath();
      ctx.moveTo(rand(0,W), rand(0,H));
      ctx.bezierCurveTo(rand(0,W),rand(0,H),rand(0,W),rand(0,H),rand(0,W),rand(0,H));
      ctx.strokeStyle = 'rgba(255,255,255,' + (0.08 + Math.random()*0.12) + ')';
      ctx.lineWidth = 1;
      ctx.stroke();
    }

    /* Ký tự sát nhau */
    var fonts = ['bold 22px Arial','bold 21px Impact','bold 23px Verdana','bold 20px Tahoma'];
    var step  = (W - 16) / code.length;
    for (var i = 0; i < code.length; i++) {
      ctx.save();
      var cx    = 8 + i * step + step * 0.45;
      var cy    = H / 2 + rand(-5, 5);
      var angle = (Math.random() - 0.5) * 0.5;
      ctx.translate(cx, cy);
      ctx.rotate(angle);
      ctx.shadowColor = 'rgba(0,0,0,0.4)';
      ctx.shadowBlur  = 3;
      ctx.shadowOffsetX = 1; ctx.shadowOffsetY = 1;
      ctx.font         = fonts[i % fonts.length];
      ctx.fillStyle    = i % 2 === 0 ? '#ffffff' : '#f5e642';
      ctx.textAlign    = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(code[i], 0, 0);
      ctx.restore();
    }

    /* Chấm nhiễu */
    for (var d = 0; d < 25; d++) {
      ctx.beginPath();
      ctx.arc(rand(0,W), rand(0,H), rand(1,2), 0, Math.PI*2);
      ctx.fillStyle = 'rgba(255,255,255,' + (0.1+Math.random()*0.15) + ')';
      ctx.fill();
    }
  }

  /* Vẽ lần đầu */
  drawCaptcha(capSecret.value);

  /* ══ REFRESH CAPTCHA ══════════════════════════ */
  function refreshCaptcha(cb) {
    var xhr = new XMLHttpRequest();
    /* timestamp + session_id để chắc chắn không cache */
    var url = 'refresh_captcha.php?t=' + Date.now() + '&r=' + Math.random();
    xhr.open('GET', url, true);
    xhr.setRequestHeader('Cache-Control', 'no-cache');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      if (xhr.status === 200) {
        try {
          var d = JSON.parse(xhr.responseText);
          if (d && d.code) {
            capSecret.value = d.code;
            drawCaptcha(d.code);
            var ci = document.getElementById('inp-cap');
            ci.value = '';
            ci.classList.remove('is-err');
            document.getElementById('ferr-cap').classList.remove('on');
            if (cb) cb();
          }
        } catch(ex) { console.error('Captcha JSON err:', ex, xhr.responseText); }
      } else {
        console.error('Captcha XHR err, status:', xhr.status);
      }
    };
    xhr.send();
  }

  document.getElementById('btnRefresh').addEventListener('click', function () {
    refreshCaptcha(function () {
      document.getElementById('inp-cap').focus();
    });
  });

  /* ══ EYE TOGGLE ══════════════════════════════ */
  var pwInp   = document.getElementById('inp-pw');
  var icoShow = document.getElementById('ico-show');
  var icoHide = document.getElementById('ico-hide');

  document.getElementById('eyeBtn').addEventListener('click', function () {
    var show = pwInp.type === 'password';
    pwInp.type            = show ? 'text' : 'password';
    icoShow.style.display = show ? 'none' : '';
    icoHide.style.display = show ? ''     : 'none';
  });

  /* ══ PASSWORD STRENGTH ═══════════════════════ */
  var segs   = ['seg1','seg2','seg3','seg4'].map(function(id){ return document.getElementById(id); });
  var strTxt = document.getElementById('strTxt');
  var pwStr  = document.getElementById('pwStr');

  pwInp.addEventListener('input', function () {
    var v = pwInp.value;
    if (!v) { pwStr.style.display = 'none'; return; }
    pwStr.style.display = 'block';
    var sc = 0;
    if (v.length >= 6)           sc++;
    if (/[A-Z]/.test(v))         sc++;
    if (/[0-9]/.test(v))         sc++;
    if (/[^A-Za-z0-9]/.test(v)) sc++;
    var lvl  = sc <= 1 ? 'weak' : sc <= 3 ? 'medium' : 'strong';
    var fill = { weak:1, medium:3, strong:4 };
    var txt  = { weak:'Mật khẩu yếu', medium:'Mật khẩu trung bình', strong:'Mật khẩu mạnh' };
    segs.forEach(function(s,i){ s.className='str-seg'+(i<fill[lvl]?' '+lvl:''); });
    strTxt.textContent = txt[lvl];
    strTxt.className   = 'str-txt ' + lvl;
  });

  /* ══ SUBMIT — TOÀN BỘ VALIDATE CLIENT-SIDE ══
     - Captcha sai: refreshCaptcha, KHÔNG submit, KHÔNG reload
     - Username trùng: check AJAX, KHÔNG submit, KHÔNG reload
     - Mọi lỗi format: hiện thông báo ngay, KHÔNG xóa bất kỳ field nào
  ═══════════════════════════════════════════ */
  function showErr(id, msg) {
    var el = document.getElementById(id);
    el.textContent = msg;
    el.classList.add('on');
    document.getElementById(id.replace('ferr','inp')).classList.add('is-err');
  }
  function clearErr(id) {
    document.getElementById(id).classList.remove('on');
    var inp = document.getElementById(id.replace('ferr','inp'));
    if (inp) inp.classList.remove('is-err');
  }

  /* ── Blur validation (real-time) ── */
  document.getElementById('inp-un').addEventListener('blur', function () {
    if (!this.value.trim()) showErr('ferr-un', 'Vui lòng nhập tên đăng nhập.');
    else clearErr('ferr-un');
  });
  document.getElementById('inp-em').addEventListener('blur', function () {
    var v = this.value.trim();
    if (!v) showErr('ferr-em', 'Vui lòng nhập email.');
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) showErr('ferr-em', 'Email không hợp lệ.');
    else clearErr('ferr-em');
  });
  document.getElementById('inp-ph').addEventListener('blur', function () {
    var v = this.value.trim();
    if (!v) showErr('ferr-ph', 'Vui lòng nhập số điện thoại.');
    else if (!/^(0|\+84)[0-9]{9}$/.test(v)) showErr('ferr-ph', 'Số điện thoại không hợp lệ.');
    else clearErr('ferr-ph');
  });
  pwInp.addEventListener('blur', function () {
    if (!pwInp.value) showErr('ferr-pw', 'Vui lòng nhập mật khẩu.');
    else if (pwInp.value.length < 6) showErr('ferr-pw', 'Mật khẩu tối thiểu 6 ký tự.');
    else clearErr('ferr-pw');
  });

  document.getElementById('regForm').addEventListener('submit', function (e) {
    e.preventDefault();

    /* Clear tất cả lỗi cũ */
    ['ferr-un','ferr-em','ferr-ph','ferr-pw','ferr-cap'].forEach(clearErr);

    var uv  = document.getElementById('inp-un').value.trim();
    var ev  = document.getElementById('inp-em').value.trim();
    var phv = document.getElementById('inp-ph').value.trim();
    var pv  = pwInp.value;
    var cv  = document.getElementById('inp-cap').value.trim().toUpperCase();
    var secret = (capSecret.value || '').trim().toUpperCase();

    var ok = true;

    /* Validate rỗng */
    if (!uv) { showErr('ferr-un', 'Vui lòng nhập đầy đủ.'); ok = false; }
    if (!ev) { showErr('ferr-em', 'Vui lòng nhập đầy đủ.'); ok = false; }
    if (!phv){ showErr('ferr-ph', 'Vui lòng nhập đầy đủ.'); ok = false; }
    if (!pv) { showErr('ferr-pw', 'Vui lòng nhập đầy đủ.'); ok = false; }
    else if (pv.length < 6) { showErr('ferr-pw', 'Mật khẩu tối thiểu 6 ký tự.'); ok = false; }

    /* Validate format email */
    if (ev && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(ev)) {
      showErr('ferr-em', 'Email không hợp lệ.'); ok = false;
    }

    /* Validate format SĐT */
    if (phv && !/^(0|\+84)[0-9]{9}$/.test(phv)) {
      showErr('ferr-ph', 'Số điện thoại không hợp lệ (VD: 0912345678).'); ok = false;
    }

    /* Validate captcha */
    if (!cv) {
      showErr('ferr-cap', 'Vui lòng nhập mã xác nhận.');
      ok = false;
    } else if (cv !== secret) {
      showErr('ferr-cap', 'Mã xác nhận không chính xác, vui lòng nhập lại!');
      refreshCaptcha(null);
      return; /* dừng, không submit */
    }

    if (!ok) return;

    /* Check username trùng qua AJAX — không reload trang */
    var submitBtn = document.querySelector('.btn-submit');
    if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Đang kiểm tra...'; }

    var xhr2 = new XMLHttpRequest();
    xhr2.open('GET', 'check_username.php?u=' + encodeURIComponent(uv) + '&t=' + Date.now(), true);
    xhr2.onreadystatechange = function () {
      if (xhr2.readyState !== 4) return;
      try {
        var r = JSON.parse(xhr2.responseText);
        if (r.exists) {
          showErr('ferr-un', 'Tên đăng nhập đã tồn tại, vui lòng chọn tên khác.');
          if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Tạo tài khoản'; }
        } else {
          if (submitBtn) submitBtn.textContent = 'Đang tạo tài khoản...';
          document.getElementById('regForm').submit();
        }
      } catch(ex) {
        if (submitBtn) submitBtn.textContent = 'Đang tạo tài khoản...';
        document.getElementById('regForm').submit();
      }
    };
    xhr2.send();
  });

})();
</script>
</body>
</html>


