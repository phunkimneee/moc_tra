<?php
require_once __DIR__ . '/constants.php';

if (!defined('DB_HOST')) {
    define('DB_HOST',    'localhost');
    define('DB_USER',    'root');
    define('DB_PASS',    '');
    define('DB_NAME',    'teashop');
    define('DB_CHARSET', 'utf8mb4');
}
if (!isset($conn)) {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset(DB_CHARSET);
    if ($conn->connect_error) {
        error_log('DB Connection failed: ' . $conn->connect_error);
        die('<p style="color:red;text-align:center;padding:40px">⚠ Không thể kết nối cơ sở dữ liệu. Kiểm tra XAMPP MySQL.</p>');
    }
}

// Initialize CSRF token if not exists
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

