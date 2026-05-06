<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();

require_once '../config/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Phương thức không hợp lệ.']);
    exit;
}

// Auth
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    echo json_encode(['success' => false, 'message' => 'Bạn cần đăng nhập.']);
    exit;
}

// CSRF
if (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Phiên làm việc hết hạn. Tải lại trang và thử lại.']);
    exit;
}

$userId    = (int)$_SESSION['user_id'];
$notifId   = (int)($_POST['notification_id'] ?? 0);
$replyText = trim($_POST['reply_text'] ?? '');

if ($notifId <= 0 || $replyText === '') {
    echo json_encode(['success' => false, 'message' => 'Dữ liệu không hợp lệ.']);
    exit;
}
if (mb_strlen($replyText) > 1000) {
    echo json_encode(['success' => false, 'message' => 'Nội dung quá dài (tối đa 1000 ký tự).']);
    exit;
}

// Kiểm tra thông báo thuộc về user này
$stN = $conn->prepare("SELECT * FROM notifications WHERE id = ? AND user_id = ?");
$stN->bind_param('ii', $notifId, $userId);
$stN->execute();
$notif = $stN->get_result()->fetch_assoc();
if (!$notif) {
    echo json_encode(['success' => false, 'message' => 'Thông báo không tồn tại.']);
    exit;
}

// Lấy thông tin user
$stU = $conn->prepare("SELECT username, email FROM users WHERE id = ?");
$stU->bind_param('i', $userId);
$stU->execute();
$user = $stU->get_result()->fetch_assoc();

$name    = $user['username'] ?? 'Khách hàng';
$email   = $user['email']    ?? '';
$subject = $notif['type'] === 'review_reply'
    ? 'Phản hồi tiếp về đánh giá #' . $notif['reference_id']
    : 'Phản hồi tiếp về liên hệ #' . $notif['reference_id'];

// Lưu vào contacts. Thử INSERT với user_id trước, fallback nếu cột chưa tồn tại
$stIns = $conn->prepare(
    "INSERT INTO contacts (name, email, subject, message, status, user_id, created_at)
     VALUES (?, ?, ?, ?, 'new', ?, NOW())"
);
if ($stIns) {
    $stIns->bind_param('ssssi', $name, $email, $subject, $replyText, $userId);
} else {
    $stIns = $conn->prepare(
        "INSERT INTO contacts (name, email, subject, message, status, created_at)
         VALUES (?, ?, ?, ?, 'new', NOW())"
    );
    $stIns->bind_param('ssss', $name, $email, $subject, $replyText);
}

if ($stIns->execute()) {
    // Đánh dấu thông báo gốc là đã đọc
    $stM = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
    $stM->bind_param('i', $notifId);
    $stM->execute();

    echo json_encode(['success' => true, 'message' => 'Đã gửi phản hồi! Admin sẽ liên hệ lại sớm.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Lỗi khi gửi. Vui lòng thử lại.']);
}
