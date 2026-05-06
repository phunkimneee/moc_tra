<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();

require_once '../config/db.php';

header('Content-Type: application/json; charset=utf-8');

// Chỉ khách hàng đã đăng nhập mới được dùng
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Tạo bảng notifications nếu chưa có
$conn->query("CREATE TABLE IF NOT EXISTS `notifications` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `type`         ENUM('review_reply','contact_reply','voucher_gifted') NOT NULL,
  `reference_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `message`      VARCHAR(1000) NOT NULL DEFAULT '',
  `is_read`      TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_read` (`user_id`, `is_read`),
  KEY `idx_created`   (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Mở rộng type ENUM nếu bảng đã tồn tại với schema cũ
$conn->query("ALTER TABLE `notifications`
  MODIFY COLUMN `type`         ENUM('review_reply','contact_reply','voucher_gifted') NOT NULL,
  MODIFY COLUMN `reference_id` INT UNSIGNED NOT NULL DEFAULT 0,
  MODIFY COLUMN `message`      VARCHAR(1000) NOT NULL DEFAULT ''");

$userId = (int)$_SESSION['user_id'];

/* ── GET: Lấy danh sách thông báo ── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $st = $conn->prepare("
        SELECT
            n.id, n.type, n.reference_id, n.message, n.is_read, n.created_at,
            CASE n.type
                WHEN 'review_reply'  THEN pr.admin_reply
                WHEN 'contact_reply' THEN c.admin_reply
                ELSE NULL
            END AS admin_reply,
            CASE n.type
                WHEN 'review_reply'  THEN pr.comment
                WHEN 'contact_reply' THEN c.message
                ELSE NULL
            END AS original_message,
            CASE n.type
                WHEN 'review_reply'  THEN p.name
                WHEN 'contact_reply' THEN c.subject
                WHEN 'voucher_gifted' THEN 'Ưu đãi riêng'
            END AS context_label,
            CASE n.type
                WHEN 'review_reply' THEN pr.product_id
                ELSE NULL
            END AS product_id
        FROM notifications n
        LEFT JOIN product_reviews pr ON n.type = 'review_reply'  AND n.reference_id = pr.id
        LEFT JOIN products         p  ON pr.product_id = p.id
        LEFT JOIN contacts         c  ON n.type = 'contact_reply' AND n.reference_id = c.id
        WHERE n.user_id = ?
        ORDER BY n.created_at DESC
        LIMIT 10
    ");
    $st->bind_param('i', $userId);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);

    // Đếm chưa đọc
    $stU = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stU->bind_param('i', $userId);
    $stU->execute();
    $unread = (int)$stU->get_result()->fetch_row()[0];

    // Format thời gian tương đối
    foreach ($rows as &$r) {
        $diff = time() - strtotime($r['created_at']);
        if      ($diff < 60)     $r['time_ago'] = 'Vừa xong';
        elseif  ($diff < 3600)   $r['time_ago'] = floor($diff / 60) . ' phút trước';
        elseif  ($diff < 86400)  $r['time_ago'] = floor($diff / 3600) . ' giờ trước';
        elseif  ($diff < 604800) $r['time_ago'] = floor($diff / 86400) . ' ngày trước';
        else                     $r['time_ago'] = date('d/m/Y', strtotime($r['created_at']));
        $r['is_read'] = (int)$r['is_read'];
    }
    unset($r);

    echo json_encode(['success' => true, 'notifications' => $rows, 'unread_count' => $unread]);
    exit;
}

/* ── POST: Đánh dấu đã đọc ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    // Kiểm tra CSRF
    if (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Phiên làm việc hết hạn.']);
        exit;
    }

    if ($action === 'mark_read') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $st = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $st->bind_param('ii', $id, $userId);
            $st->execute();
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'mark_all_read') {
        $st = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $st->bind_param('i', $userId);
        $st->execute();
        echo json_encode(['success' => true]);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid request']);
