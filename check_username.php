<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();
/**
 * check_username.php — AJAX: kiểm tra username có tồn tại chưa
 */
require_once 'config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$username = trim($_GET['u'] ?? '');
if ($username === '') {
    echo json_encode(['exists' => false]);
    exit;
}

$stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
$stmt->bind_param("s", $username);
$stmt->execute();
$exists = $stmt->get_result()->num_rows > 0;
echo json_encode(['exists' => $exists]);


