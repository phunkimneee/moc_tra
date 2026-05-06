<?php
/*
 * TEMPORARY SMTP diagnostic tool — DELETE after debugging is done.
 * Access: http://localhost/moctra/smtp_test.php
 */
if ($_SERVER['REMOTE_ADDR'] !== '127.0.0.1' && $_SERVER['REMOTE_ADDR'] !== '::1') {
    http_response_code(403); exit('Forbidden');
}

require_once 'config/db.php';
require_once 'config/mailer.php';
require_once 'config/smtp_config.php';

$result  = null;
$logFile = __DIR__ . '/logs/smtp_debug_' . date('Y-m-d') . '.log';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $testTo = trim($_POST['to'] ?? '');
    if (filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
        $result = moctra_send_otp_email($testTo, '123456');
    } else {
        $result = 'invalid-email';
    }
}
?>
<!DOCTYPE html><html lang="vi"><head>
<meta charset="UTF-8">
<title>SMTP Test — Mộc Trà</title>
<style>
  body{font-family:monospace;max-width:720px;margin:40px auto;padding:0 20px}
  .ok{color:green;font-weight:bold} .fail{color:red;font-weight:bold}
  pre{background:#f4f4f4;padding:16px;border-radius:6px;overflow:auto;font-size:13px;white-space:pre-wrap}
  input[type=email]{width:100%;padding:8px;font-size:15px;margin:8px 0}
  button{padding:10px 24px;background:#166534;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:15px}
</style></head><body>
<h2>🔧 Email Diagnostic — Mộc Trà</h2>
<p>
  SMTP: <b><?= SMTP_HOST ?>:<?= SMTP_PORT ?></b> | User: <b><?= SMTP_USER ?></b><br>
  Key: <b><?= SMTP_PASS ? substr(SMTP_PASS,0,12).'…' : '<span style="color:red">⚠️ NOT SET</span>' ?></b> |
  HTTP fallback: <b><?= BREVO_API_KEY ? 'ready' : '<span style="color:red">⚠️ no BREVO_API_KEY</span>' ?></b>
</p>

<?php if ($result === true): ?>
  <p class="ok">✅ Email gửi thành công!</p>
<?php elseif ($result === false): ?>
  <p class="fail">❌ Gửi thất bại. Xem log bên dưới.</p>
<?php elseif ($result === 'invalid-email'): ?>
  <p class="fail">Email không hợp lệ.</p>
<?php endif; ?>

<form method="POST">
  <label>Gửi OTP test đến email:</label>
  <input type="email" name="to" placeholder="email@example.com"
         value="<?= htmlspecialchars($_POST['to'] ?? SMTP_USER) ?>">
  <button type="submit">Gửi thử</button>
</form>

<h3 style="margin-top:32px">📋 Debug Log (<?= date('Y-m-d') ?>)</h3>
<?php if (is_file($logFile)): ?>
  <pre><?= htmlspecialchars(file_get_contents($logFile)) ?></pre>
<?php else: ?>
  <p>Chưa có log hôm nay — nhấn "Gửi thử" để tạo log.</p>
<?php endif; ?>

<hr style="margin-top:40px">
<small style="color:#999">⚠️ Xóa file <code>smtp_test.php</code> sau khi debug xong.</small>
</body></html>
