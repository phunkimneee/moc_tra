<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();
require_once 'config/db.php';
header('Content-Type: application/json; charset=utf-8');

if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'customer' ||
    empty($_SESSION['wishlist_sync_needed'])
) {
    echo json_encode(['sync' => false]);
    exit();
}

$uid = (int)$_SESSION['user_id'];

// Check wishlist table exists
$check = $conn->query("SHOW TABLES LIKE 'wishlist'");
if (!$check || $check->num_rows === 0) {
    $_SESSION['wishlist_sync_needed'] = false;
    echo json_encode(['sync' => false]);
    exit();
}

$st = $conn->prepare(
    "SELECT p.id, p.name, p.price, p.image
     FROM wishlist w
     JOIN products p ON p.id = w.product_id
     WHERE w.user_id = ?
     ORDER BY w.created_at DESC"
);
$st->bind_param('i', $uid);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);

$items = [];
foreach ($rows as $row) {
    $items[] = [
        'id'    => (string)$row['id'],
        'name'  => $row['name'],
        'price' => (int)$row['price'],
        'image' => 'images/' . ($row['image'] ?: 'logo.png'),
        'url'   => 'product_detail.php?id=' . $row['id'],
        'qty'   => 1,
    ];
}

$_SESSION['wishlist_sync_needed'] = false;
echo json_encode(['sync' => true, 'items' => $items]);


