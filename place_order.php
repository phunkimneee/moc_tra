<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();
require_once 'config/db.php';
require_once __DIR__ . '/config/mailer.php';
require_once __DIR__ . '/config/payment.php';

if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: checkout.php');
    exit();
}

if (empty($_POST['_csrf']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['_csrf'])) {
    header('Location: checkout.php');
    exit();
}

if ($_SESSION['role'] === 'admin') {
    header('Location: admin/dashboard.php');
    exit();
}

$cart = $_SESSION['cart'] ?? [];
if (empty($cart)) {
    header('Location: cart.php');
    exit();
}

$userId = (int)$_SESSION['user_id'];
$fullName = trim($_POST['fullname'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$address = trim($_POST['address'] ?? '');
$note = trim($_POST['note'] ?? '');
$paymentMethod = trim($_POST['payment_method'] ?? 'cod');

if ($paymentMethod === 'card') {
    $paymentMethod = 'bank';
}

$allowedPayments = ['cod', 'momo', 'bank'];
if ($fullName === '' || $phone === '' || $address === '' || !in_array($paymentMethod, $allowedPayments, true)) {
    header('Location: checkout.php');
    exit();
}

// Validate định dạng số điện thoại
if (!preg_match('/^(0|\+84)[0-9]{9}$/', $phone)) {
    header('Location: checkout.php');
    exit();
}

$productIds = array_map('intval', array_keys($cart));
if (empty($productIds)) {
    header('Location: cart.php');
    exit();
}

$in = implode(',', $productIds);
$products = [];
$res = $conn->query("SELECT id, name, price, stock FROM products WHERE id IN ($in)");
while ($row = $res->fetch_assoc()) {
    $products[(int)$row['id']] = $row;
}

$subtotal = 0;
$itemsToInsert = [];
$outOfStock = [];
foreach ($cart as $productId => $item) {
    $pid = (int)$productId;
    $qty = max(1, (int)($item['qty'] ?? 0));
    if (!isset($products[$pid])) {
        continue;
    }
    if ((int)$products[$pid]['stock'] < $qty) {
        $outOfStock[] = $products[$pid]['name'];
        continue;
    }
    $price = (int)$products[$pid]['price'];
    $subtotal += $price * $qty;
    $itemsToInsert[] = [
        'product_id'   => $pid,
        'product_name' => $products[$pid]['name'],
        'price'        => $price,
        'qty'          => $qty,
        'old_stock'    => (int)$products[$pid]['stock'],
    ];
}

if (!empty($outOfStock)) {
    $names = implode(', ', array_map('htmlspecialchars', $outOfStock));
    header('Location: cart.php?out_of_stock=' . urlencode($names));
    exit();
}

if (empty($itemsToInsert)) {
    header('Location: cart.php');
    exit();
}

$shipping = $subtotal >= FREE_SHIP_THRESHOLD ? 0 : SHIP_FEE;

/* ── Coupon discount ── */
$discount   = 0;
$couponId   = null;
$couponCode = strtoupper(trim($_POST['coupon_code'] ?? ''));
if ($couponCode) {
    $stCoupon = $conn->prepare(
        "SELECT * FROM coupons WHERE code=? AND is_active=1 LIMIT 1"
    );
    // Check table exists first
    $tblCheck = $conn->query("SHOW TABLES LIKE 'coupons'");
    if ($tblCheck && $tblCheck->num_rows > 0) {
        $stCoupon->bind_param('s', $couponCode);
        $stCoupon->execute();
        $coupon = $stCoupon->get_result()->fetch_assoc();
        if (
            $coupon &&
            (!$coupon['expires_at'] || $coupon['expires_at'] >= date('Y-m-d')) &&
            (!$coupon['max_uses'] || $coupon['used_count'] < $coupon['max_uses']) &&
            (!$coupon['min_order'] || $subtotal >= $coupon['min_order'])
        ) {
            $couponId = (int)$coupon['id'];
            $discount = $coupon['discount_type'] === 'percent'
                ? (int)round($subtotal * $coupon['discount_value'] / 100)
                : (int)$coupon['discount_value'];
            $discount = min($discount, $subtotal);
        }
    }
}

$grandTotal = $subtotal + $shipping - $discount;

$conn->begin_transaction();

try {
    /* Kiểm tra orders table có cột coupon_id chưa (tương thích khi chưa chạy migration) */
    $hasCouponCols = (bool)$conn->query(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders' AND COLUMN_NAME='coupon_id' LIMIT 1"
    )->num_rows;

    if ($hasCouponCols) {
        $orderStmt = $conn->prepare(
            "INSERT INTO orders (user_id, full_name, phone, address, note, payment_method, status, total, coupon_id, discount_amount, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, NOW(), NOW())"
        );
        $orderStmt->bind_param('isssssiii', $userId, $fullName, $phone, $address, $note, $paymentMethod, $grandTotal, $couponId, $discount);
    } else {
        $orderStmt = $conn->prepare(
            "INSERT INTO orders (user_id, full_name, phone, address, note, payment_method, status, total, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), NOW())"
        );
        $orderStmt->bind_param('isssssi', $userId, $fullName, $phone, $address, $note, $paymentMethod, $grandTotal);
    }
    $orderStmt->execute();
    $orderId = (int)$conn->insert_id;

    /* Sinh và lưu order_code (tương thích khi chưa chạy migration) */
    $hasOrderCodeCol = (bool)$conn->query(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders' AND COLUMN_NAME='order_code' LIMIT 1"
    )->num_rows;
    if ($hasOrderCodeCol) {
        $generatedCode = generate_order_code($orderId);
        $stCode = $conn->prepare("UPDATE orders SET order_code=? WHERE id=?");
        $stCode->bind_param('si', $generatedCode, $orderId);
        $stCode->execute();
    }

    $itemStmt = $conn->prepare(
        "INSERT INTO order_items (order_id, product_id, product_name, price, qty)
         VALUES (?, ?, ?, ?, ?)"
    );

    foreach ($itemsToInsert as $orderItem) {
        $itemStmt->bind_param(
            'iisii',
            $orderId,
            $orderItem['product_id'],
            $orderItem['product_name'],
            $orderItem['price'],
            $orderItem['qty']
        );
        $itemStmt->execute();
    }

    $stockStmt = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?");
    $logStmt = $conn->prepare("INSERT INTO inventory_history (product_id, change_amount, old_stock, new_stock, note, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    
    foreach ($itemsToInsert as $orderItem) {
        $stockStmt->bind_param('iii', $orderItem['qty'], $orderItem['product_id'], $orderItem['qty']);
        $stockStmt->execute();
        
        // Ghi lại lịch sử kho
        $changeAmount = -$orderItem['qty'];
        $newStock = $orderItem['old_stock'] + $changeAmount;
        $note = "Đơn hàng #" . $orderId;
        $logStmt->bind_param('iiiis', $orderItem['product_id'], $changeAmount, $orderItem['old_stock'], $newStock, $note);
        $logStmt->execute();
    }

    /* Tăng used_count bên trong transaction để đảm bảo atomicity */
    if ($couponId) {
        $stInc = $conn->prepare("UPDATE coupons SET used_count = used_count + 1 WHERE id = ?");
        $stInc->bind_param('i', $couponId);
        $stInc->execute();
    }

    $conn->commit();
    unset($_SESSION['cart'], $_SESSION['coupon']);

    // Gửi email xác nhận đơn hàng
    $stUser = $conn->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
    $stUser->bind_param('i', $userId);
    $stUser->execute();
    $userRow = $stUser->get_result()->fetch_assoc();
    if ($userRow && $userRow['email']) {
        moctra_email_order_confirm($userRow['email'], $fullName, $orderId, $grandTotal, $itemsToInsert);
    }

    header('Location: order_success.php?order_id=' . $orderId . '&new=1');
    exit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log('Place order failed: ' . $e->getMessage());
    header('Location: checkout.php');
    exit();
}
?>


