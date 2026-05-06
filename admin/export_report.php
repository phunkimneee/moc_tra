<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

$period = $_GET['period'] ?? 'month';

switch ($period) {
    case 'quarter':
        $fromDate    = (new DateTime())->modify('-3 months')->format('Y-m-d');
        $periodLabel = '3 tháng gần nhất';
        $groupFmt    = '%Y-%m';
        break;
    case 'year':
        $fromDate    = (new DateTime())->modify('-12 months')->format('Y-m-d');
        $periodLabel = '12 tháng gần nhất';
        $groupFmt    = '%Y-%m';
        break;
    default:
        $fromDate    = (new DateTime())->modify('-30 days')->format('Y-m-d');
        $periodLabel = '30 ngày gần nhất';
        $groupFmt    = '%Y-%m-%d';
        break;
}

/* ── Queries ── */
$stKpi = $conn->prepare("SELECT COALESCE(SUM(total),0) AS revenue, COUNT(*) AS total_orders FROM orders WHERE DATE(created_at) >= ? AND status != 'cancelled'");
$stKpi->bind_param('s', $fromDate); $stKpi->execute();
$kpi = $stKpi->get_result()->fetch_assoc();

$stCan = $conn->prepare("SELECT COUNT(*) FROM orders WHERE DATE(created_at)>=? AND status='cancelled'");
$stCan->bind_param('s', $fromDate); $stCan->execute();
$kpiCancelled = (int)$stCan->get_result()->fetch_row()[0];

$stRev = $conn->prepare("SELECT DATE_FORMAT(created_at,?) AS period, COALESCE(SUM(total),0) AS revenue, COUNT(*) AS orders FROM orders WHERE DATE(created_at) >= ? AND status != 'cancelled' GROUP BY period ORDER BY period ASC");
$stRev->bind_param('ss', $groupFmt, $fromDate); $stRev->execute();
$revRows = $stRev->get_result()->fetch_all(MYSQLI_ASSOC);

$stTop = $conn->prepare("SELECT p.name, SUM(oi.qty) AS total_sold, SUM(oi.qty*oi.price) AS revenue FROM order_items oi JOIN products p ON p.id=oi.product_id JOIN orders o ON o.id=oi.order_id WHERE DATE(o.created_at)>=? AND o.status!='cancelled' GROUP BY oi.product_id ORDER BY total_sold DESC LIMIT 20");
$stTop->bind_param('s', $fromDate); $stTop->execute();
$topProducts = $stTop->get_result()->fetch_all(MYSQLI_ASSOC);

$stCat = $conn->prepare("SELECT c.name AS cat_name, SUM(oi.qty) AS total_sold, SUM(oi.qty*oi.price) AS revenue FROM order_items oi JOIN products p ON p.id=oi.product_id JOIN categories c ON c.id=p.category_id JOIN orders o ON o.id=oi.order_id WHERE DATE(o.created_at)>=? AND o.status!='cancelled' GROUP BY c.id ORDER BY total_sold DESC");
$stCat->bind_param('s', $fromDate); $stCat->execute();
$catRows = $stCat->get_result()->fetch_all(MYSQLI_ASSOC);

$stSt = $conn->prepare("SELECT status, COUNT(*) AS cnt FROM orders WHERE DATE(created_at)>=? GROUP BY status");
$stSt->bind_param('s', $fromDate); $stSt->execute();
$stRows = $stSt->get_result()->fetch_all(MYSQLI_ASSOC);

/* ── Output CSV ── */
$filename = 'moctra_baocao_' . $period . '_' . date('Ymd') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');

// UTF-8 BOM for Excel
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

/* Section 1: Summary */
fputcsv($out, ['BÁO CÁO DOANH THU MỘC TRÀ — ' . strtoupper($periodLabel)]);
fputcsv($out, ['Ngày xuất:', date('d/m/Y H:i')]);
fputcsv($out, []);
fputcsv($out, ['TỔNG QUAN KỲ BÁO CÁO']);
fputcsv($out, ['Tổng doanh thu (VNĐ)', (int)$kpi['revenue']]);
fputcsv($out, ['Tổng đơn hàng', (int)$kpi['total_orders']]);
fputcsv($out, ['Đơn đã hủy', $kpiCancelled]);
fputcsv($out, []);

/* Section 2: Revenue by period */
fputcsv($out, ['DOANH THU THEO ' . ($period === 'month' ? 'NGÀY' : 'THÁNG')]);
fputcsv($out, ['Kỳ', 'Doanh thu (VNĐ)', 'Số đơn']);
foreach ($revRows as $row) {
    fputcsv($out, [$row['period'], (int)$row['revenue'], (int)$row['orders']]);
}
fputcsv($out, []);

/* Section 3: Top products */
fputcsv($out, ['TOP SẢN PHẨM BÁN CHẠY']);
fputcsv($out, ['Tên sản phẩm', 'Số lượng bán', 'Doanh thu (VNĐ)']);
foreach ($topProducts as $row) {
    fputcsv($out, [$row['name'], (int)$row['total_sold'], (int)$row['revenue']]);
}
fputcsv($out, []);

/* Section 4: Category breakdown */
fputcsv($out, ['DOANH THU THEO DANH MỤC']);
fputcsv($out, ['Danh mục', 'Số lượng bán', 'Doanh thu (VNĐ)']);
foreach ($catRows as $row) {
    fputcsv($out, [$row['cat_name'], (int)$row['total_sold'], (int)$row['revenue']]);
}
fputcsv($out, []);

/* Section 5: Order status */
$statusLabels = ['pending'=>'Chờ xác nhận','processing'=>'Đang xử lý','shipping'=>'Đang giao','delivered'=>'Đã giao','cancelled'=>'Đã hủy'];
fputcsv($out, ['PHÂN BỔ TRẠNG THÁI ĐƠN HÀNG']);
fputcsv($out, ['Trạng thái', 'Số đơn']);
foreach ($stRows as $row) {
    fputcsv($out, [$statusLabels[$row['status']] ?? $row['status'], (int)$row['cnt']]);
}

fclose($out);
exit();
