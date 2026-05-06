<?php
/**
 * Admin Dashboard API
 * GET /admin/api/dashboard.php
 * Returns JSON consumed by the Next.js admin frontend.
 */

require_once dirname(__DIR__) . '/includes/auth.php';   // session + admin check
require_once dirname(__DIR__) . '/includes/db.php';     // $conn

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Credentials: true');

/* Only allow GET */
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$today     = date('Y-m-d');
$thisYear  = (int)date('Y');
$thisMonth = (int)date('n');

// ── Stats ─────────────────────────────────────────────────────────────────────

$st = $conn->prepare(
    "SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE(created_at)=? AND status!='cancelled'"
);
$st->bind_param('s', $today);
$st->execute();
$revenueToday = (int)$st->get_result()->fetch_row()[0];

$st2 = $conn->prepare(
    "SELECT COALESCE(SUM(total),0) FROM orders
     WHERE YEAR(created_at)=? AND MONTH(created_at)=? AND status!='cancelled'"
);
$st2->bind_param('ii', $thisYear, $thisMonth);
$st2->execute();
$revenueMonth = (int)$st2->get_result()->fetch_row()[0];

$st3 = $conn->prepare("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=?");
$st3->bind_param('s', $today);
$st3->execute();
$ordersToday = (int)$st3->get_result()->fetch_row()[0];

$totalOrders   = (int)$conn->query("SELECT COUNT(*) FROM orders")->fetch_row()[0];
$totalProducts = (int)$conn->query("SELECT COUNT(*) FROM products")->fetch_row()[0];
$totalUsers    = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetch_row()[0];
$pendingCount  = (int)$conn->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetch_row()[0];

// ── Order status breakdown ────────────────────────────────────────────────────

$statusRows = $conn->query(
    "SELECT status, COUNT(*) as cnt FROM orders GROUP BY status"
)->fetch_all(MYSQLI_ASSOC);

$statusCounts = ['pending'=>0,'processing'=>0,'shipping'=>0,'delivered'=>0,'cancelled'=>0];
foreach ($statusRows as $s) {
    if (isset($statusCounts[$s['status']])) {
        $statusCounts[$s['status']] = (int)$s['cnt'];
    }
}

// ── Revenue last 7 days ───────────────────────────────────────────────────────

$d7Start = date('Y-m-d', strtotime('-6 days'));
$revRaw  = $conn->prepare(
    "SELECT DATE(created_at) AS day, COALESCE(SUM(total),0) AS val
     FROM orders
     WHERE DATE(created_at) >= ? AND status != 'cancelled'
     GROUP BY DATE(created_at)"
);
$revRaw->bind_param('s', $d7Start);
$revRaw->execute();

$revMap = [];
foreach ($revRaw->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $revMap[$row['day']] = (int)$row['val'];
}

$rev7 = [];
for ($i = 6; $i >= 0; $i--) {
    $d      = date('Y-m-d', strtotime("-$i days"));
    $rev7[] = [
        'day' => date('d/m', strtotime("-$i days")),
        'val' => $revMap[$d] ?? 0,
    ];
}

// ── Recent orders (last 8) ────────────────────────────────────────────────────

$recentOrders = $conn->query(
    "SELECT o.id, o.full_name, o.total, o.status, o.payment_method,
            DATE_FORMAT(o.created_at, '%d/%m %H:%i') AS created_at,
            COUNT(oi.id) AS item_count
     FROM orders o
     LEFT JOIN order_items oi ON oi.order_id = o.id
     GROUP BY o.id
     ORDER BY o.created_at DESC
     LIMIT 8"
)->fetch_all(MYSQLI_ASSOC);

foreach ($recentOrders as &$o) {
    $o['id']    = (int)$o['id'];
    $o['total'] = (int)$o['total'];
    $o['item_count'] = (int)$o['item_count'];
}
unset($o);

// ── Top 5 selling products ────────────────────────────────────────────────────

$topProducts = $conn->query(
    "SELECT p.name, p.image,
            SUM(oi.qty) AS total_sold,
            SUM(oi.price * oi.qty) AS revenue
     FROM order_items oi
     JOIN products p ON p.id = oi.product_id
     GROUP BY oi.product_id
     ORDER BY total_sold DESC
     LIMIT 5"
)->fetch_all(MYSQLI_ASSOC);

foreach ($topProducts as &$p) {
    $p['total_sold'] = (int)$p['total_sold'];
    $p['revenue']    = (int)$p['revenue'];
    $p['image_url']  = !empty($p['image'])
        ? 'http://localhost/moctra/images/' . rawurlencode($p['image'])
        : null;
}
unset($p);

// ── Greeting ──────────────────────────────────────────────────────────────────

$hour     = (int)date('H');
$greeting = $hour < 12 ? 'Chào buổi sáng' : ($hour < 18 ? 'Chào buổi chiều' : 'Chào buổi tối');

$todayVi = [
    'Monday'    => 'Thứ Hai', 'Tuesday'  => 'Thứ Ba',   'Wednesday' => 'Thứ Tư',
    'Thursday'  => 'Thứ Năm', 'Friday'   => 'Thứ Sáu',  'Saturday'  => 'Thứ Bảy',
    'Sunday'    => 'Chủ Nhật',
];
$todayLabel = ($todayVi[date('l')] ?? date('l')) . ', ' . date('d/m/Y');

// ── Assemble and return ───────────────────────────────────────────────────────

echo json_encode([
    'stats' => [
        'revenueToday'  => $revenueToday,
        'revenueMonth'  => $revenueMonth,
        'ordersToday'   => $ordersToday,
        'totalOrders'   => $totalOrders,
        'totalProducts' => $totalProducts,
        'totalUsers'    => $totalUsers,
        'pendingCount'  => $pendingCount,
    ],
    'statusCounts' => $statusCounts,
    'rev7'         => $rev7,
    'recentOrders' => $recentOrders,
    'topProducts'  => $topProducts,
    'meta' => [
        'greeting'   => $greeting,
        'adminName'  => $adminName,
        'todayLabel' => $todayLabel,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
