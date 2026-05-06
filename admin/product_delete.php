<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: products.php'); exit(); }

// CSRF check
if (empty($_POST['_csrf']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['_csrf'])) {
    header('Location: products.php?err=csrf'); exit();
}

$id = (int)($_POST['_confirm_id'] ?? 0);
if ($id) {
    $st = $conn->prepare("DELETE FROM products WHERE id=?");
    $st->bind_param('i', $id);
    $st->execute();
}
header('Location: products.php?deleted=1');
exit();
