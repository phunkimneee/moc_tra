<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Phương thức không hợp lệ.']);
    exit;
}

if (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Phiên làm việc hết hạn. Tải lại trang.']);
    exit;
}

$action = $_POST['action'] ?? '';
$reply  = trim($_POST['admin_reply'] ?? '');

if ($reply === '') {
    echo json_encode(['success' => false, 'message' => 'Nội dung phản hồi không được để trống.']);
    exit;
}

if ($action === 'reply_contact') {
    $cid = (int)($_POST['contact_id'] ?? 0);
    if ($cid <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID liên hệ không hợp lệ.']);
        exit;
    }

    $st = $conn->prepare("UPDATE contacts SET admin_reply=?, replied_at=NOW(), status='replied' WHERE id=?");
    $st->bind_param('si', $reply, $cid);
    if (!$st->execute()) {
        echo json_encode(['success' => false, 'message' => 'Lỗi cập nhật database.']);
        exit;
    }

    // Tạo notification cho user nếu có user_id
    $stU = $conn->prepare("SELECT user_id, replied_at FROM contacts WHERE id = ?");
    $stU->bind_param('i', $cid);
    $stU->execute();
    $row = $stU->get_result()->fetch_assoc();

    if (!empty($row['user_id'])) {
        $short = mb_substr($reply, 0, 200);
        $stN   = $conn->prepare(
            "INSERT INTO notifications (user_id, type, reference_id, message) VALUES (?, 'contact_reply', ?, ?)"
        );
        $stN->bind_param('iis', $row['user_id'], $cid, $short);
        $stN->execute();
    }

    // Xóa cache badge trong session để lần load tiếp lấy lại từ DB
    unset($_SESSION['_badge_ts']);

    $contacts_count = (int)$conn->query("SELECT COUNT(*) FROM contacts WHERE status='new'")->fetch_row()[0];
    $reviews_count  = (int)$conn->query("SELECT COUNT(*) FROM product_reviews WHERE status_admin='new'")->fetch_row()[0];
    $newCount = $contacts_count + $reviews_count;

    echo json_encode([
        'success'     => true,
        'message'     => 'Đã gửi phản hồi thành công!',
        'new_count'   => $newCount,
        'contacts_count' => $contacts_count,
        'reviews_count'  => $reviews_count,
        'replied_at'  => date('d/m/Y H:i'),
        'admin_reply' => $reply,
    ]);
    exit;
}

if ($action === 'reply_review') {
    $rid = (int)($_POST['review_id'] ?? 0);
    if ($rid <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID đánh giá không hợp lệ.']);
        exit;
    }

    $st = $conn->prepare("UPDATE product_reviews SET admin_reply=?, replied_at=NOW(), status_admin='replied' WHERE id=?");
    $st->bind_param('si', $reply, $rid);
    if (!$st->execute()) {
        echo json_encode(['success' => false, 'message' => 'Lỗi cập nhật database.']);
        exit;
    }

    $stU = $conn->prepare("SELECT user_id, replied_at FROM product_reviews WHERE id = ?");
    $stU->bind_param('i', $rid);
    $stU->execute();
    $row = $stU->get_result()->fetch_assoc();

    if (!empty($row['user_id'])) {
        $short = mb_substr($reply, 0, 200);
        $stN   = $conn->prepare(
            "INSERT INTO notifications (user_id, type, reference_id, message) VALUES (?, 'review_reply', ?, ?)"
        );
        $stN->bind_param('iis', $row['user_id'], $rid, $short);
        $stN->execute();
    }

    unset($_SESSION['_badge_ts']);

    $contacts_count = (int)$conn->query("SELECT COUNT(*) FROM contacts WHERE status='new'")->fetch_row()[0];
    $reviews_count  = (int)$conn->query("SELECT COUNT(*) FROM product_reviews WHERE status_admin='new'")->fetch_row()[0];
    $newCount = $contacts_count + $reviews_count;

    echo json_encode([
        'success'     => true,
        'message'     => 'Đã gửi phản hồi thành công!',
        'new_count'   => $newCount,
        'contacts_count' => $contacts_count,
        'reviews_count'  => $reviews_count,
        'replied_at'  => date('d/m/Y H:i'),
        'admin_reply' => $reply,
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Action không hợp lệ.']);
