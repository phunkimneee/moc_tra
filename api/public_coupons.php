<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();
require_once '../config/db.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    echo json_encode([]);
    exit();
}

$userId   = (int)$_SESSION['user_id'];
$subtotal = max(0, (int)($_GET['subtotal'] ?? 0));
$today    = date('Y-m-d');

$result = [];

/* ── Mã riêng tư của user (ưu tiên hiển thị trước) ── */
$stPriv = $conn->prepare("
    SELECT code, discount_type, discount_value, min_order, expires_at
    FROM coupons
    WHERE is_active = 1
      AND coupon_role = 'private'
      AND specific_user_id = ?
      AND (expires_at IS NULL OR expires_at >= ?)
      AND (max_uses = 0 OR used_count < max_uses)
    ORDER BY created_at DESC
");
$stPriv->bind_param('is', $userId, $today);
$stPriv->execute();
foreach ($stPriv->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $label = '[Ưu đãi riêng] ' . $r['code'];
    $label .= $r['discount_type'] === 'percent'
        ? ' — Giảm ' . $r['discount_value'] . '%'
        : ' — Giảm ' . number_format($r['discount_value'], 0, ',', '.') . 'đ';
    if ($r['expires_at']) {
        $label .= ' · HSD: ' . date('d/m/Y', strtotime($r['expires_at']));
    }

    $result[] = [
        'code'     => $r['code'],
        'label'    => $label,
        'eligible' => ($r['min_order'] === 0 || $subtotal >= $r['min_order']),
        'role'     => 'private',
    ];
}

/* ── Mã công khai ── */
$stPub = $conn->prepare("
    SELECT code, discount_type, discount_value, min_order, expires_at
    FROM coupons
    WHERE is_active = 1
      AND coupon_role = 'public'
      AND (expires_at IS NULL OR expires_at >= ?)
      AND (max_uses = 0 OR used_count < max_uses)
    ORDER BY discount_value DESC
");
$stPub->bind_param('s', $today);
$stPub->execute();
foreach ($stPub->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $label = $r['code'];
    $label .= $r['discount_type'] === 'percent'
        ? ' — Giảm ' . $r['discount_value'] . '%'
        : ' — Giảm ' . number_format($r['discount_value'], 0, ',', '.') . 'đ';
    if ($r['min_order'] > 0) {
        $label .= ' (đơn từ ' . number_format($r['min_order'], 0, ',', '.') . 'đ)';
    }

    $result[] = [
        'code'     => $r['code'],
        'label'    => $label,
        'eligible' => ($r['min_order'] === 0 || $subtotal >= $r['min_order']),
        'role'     => 'public',
    ];
}

echo json_encode($result);
