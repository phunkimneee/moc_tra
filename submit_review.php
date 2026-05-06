<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();
require_once 'config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: products.php'); exit();
}
if (empty($_SESSION['user_id'])) {
    header('Location: login.php'); exit();
}
if (empty($_POST['_csrf']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['_csrf'])) {
    header('Location: products.php'); exit();
}

$productId = (int)($_POST['product_id'] ?? 0);
$rating    = (int)($_POST['rating'] ?? 0);
$comment   = trim($_POST['comment'] ?? '');
$userId    = (int)$_SESSION['user_id'];
$userRole  = $_SESSION['role'] ?? 'customer';

if ($productId <= 0 || $rating < 1 || $rating > 5) {
    header('Location: product_detail.php?id=' . $productId . '#tab-review');
    exit();
}

// Verify product exists
$chk = $conn->prepare("SELECT id FROM products WHERE id=? LIMIT 1");
$chk->bind_param('i', $productId);
$chk->execute();
if (!$chk->get_result()->num_rows) {
    header('Location: products.php'); exit();
}

// Security: Verify user has actually bought and received the product (Status: delivered)
// EXCEPTION: Allow Admin to review for testing purposes
$orderId = null;
if ($userRole !== 'admin') {
    $stBought = $conn->prepare(
        "SELECT o.id FROM orders o
         JOIN order_items oi ON o.id = oi.order_id
         WHERE o.user_id = ? AND oi.product_id = ? AND o.status = 'delivered'
         ORDER BY o.created_at DESC LIMIT 1"
    );
    $stBought->bind_param('ii', $userId, $productId);
    $stBought->execute();
    $boughtRow = $stBought->get_result()->fetch_row();
    if (!$boughtRow) {
        header('Location: product_detail.php?id=' . $productId . '&error=not_bought#tab-review');
        exit();
    }
    $orderId = (int)$boughtRow[0];

    // Insert với order_id để unique key (order_id, product_id) hoạt động đúng
    $st = $conn->prepare(
        "INSERT INTO product_reviews (product_id, order_id, user_id, rating, comment)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE rating=VALUES(rating), comment=VALUES(comment), created_at=NOW()"
    );
    $st->bind_param('iiiis', $productId, $orderId, $userId, $rating, $comment);
} else {
    // Admin: kiểm tra thủ công trước khi insert/update (order_id = NULL không kích hoạt unique key)
    $stExist = $conn->prepare("SELECT id FROM product_reviews WHERE product_id=? AND user_id=? LIMIT 1");
    $stExist->bind_param('ii', $productId, $userId);
    $stExist->execute();
    $existRow = $stExist->get_result()->fetch_assoc();

    if ($existRow) {
        $st = $conn->prepare(
            "UPDATE product_reviews SET rating=?, comment=?, created_at=NOW() WHERE id=?"
        );
        $st->bind_param('isi', $rating, $comment, $existRow['id']);
    } else {
        $st = $conn->prepare(
            "INSERT INTO product_reviews (product_id, user_id, rating, comment) VALUES (?, ?, ?, ?)"
        );
        $st->bind_param('iiis', $productId, $userId, $rating, $comment);
    }
}
$st->execute();

header('Location: product_detail.php?id=' . $productId . '&reviewed=1');
exit();


