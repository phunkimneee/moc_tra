<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
$code  = '';
for ($i = 0; $i < 6; $i++) {
    $code .= $chars[random_int(0, strlen($chars) - 1)];
}
$_SESSION['cap_code']    = $code;
$_SESSION['cap_expires'] = time() + 300;

echo json_encode(['code' => $code]);


