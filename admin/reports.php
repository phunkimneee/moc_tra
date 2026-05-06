<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

$pageTitle  = 'Báo cáo doanh thu';
$activePage = 'reports';
$breadcrumb = [['label' => 'Báo cáo doanh thu']];

/* ── Kỳ báo cáo ── */
$period = $_GET['period'] ?? 'month'; // month | quarter | year
$now    = new DateTime();

switch ($period) {
    case '7d':
        $fromDate    = date('Y-m-d', strtotime('-6 days'));
        $periodLabel = '7 ngày gần nhất';
        $groupFmt    = '%Y-%m-%d';
        $dateFmt     = 'd/m';
        break;
    case '4w':
        $fromDate    = date('Y-m-d', strtotime('-27 days'));
        $periodLabel = '4 tuần gần nhất';
        $groupFmt    = '%Y-%m-%d';
        $dateFmt     = 'd/m';
        break;
    case 'quarter':
        $fromDate    = (new DateTime())->modify('-3 months')->format('Y-m-d');
        $periodLabel = '3 tháng gần nhất';
        $groupFmt    = '%Y-%m';
        $dateFmt     = 'M/Y';
        break;
    case 'year':
        $fromDate    = (new DateTime())->modify('-12 months')->format('Y-m-d');
        $periodLabel = '12 tháng gần nhất';
        $groupFmt    = '%Y-%m';
        $dateFmt     = 'M/Y';
        break;
    default: // month = 30 days
        $fromDate    = (new DateTime())->modify('-30 days')->format('Y-m-d');
        $periodLabel = '30 ngày gần nhất';
        $groupFmt    = '%Y-%m-%d';
        $dateFmt     = 'd/m';
        break;
}

/* ── Helper ── */
function fmtMoney(int $n): string { return number_format($n, 0, ',', '.') . 'đ'; }
function fmtNum(int $n): string   { return number_format($n, 0, ',', '.'); }

/* ────────────────────────────────────── */
/* KPI tổng trong kỳ                      */
/* ────────────────────────────────────── */
$stKpi = $conn->prepare("SELECT
    COALESCE(SUM(total),0)  AS revenue,
    COUNT(*)                AS total_orders,
    COUNT(CASE WHEN status='delivered' THEN 1 END) AS delivered,
    COUNT(CASE WHEN status='cancelled' THEN 1 END) AS cancelled
    FROM orders
    WHERE DATE(created_at) >= ? AND status != 'cancelled'");
$stKpi->bind_param('s', $fromDate);
$stKpi->execute();
$kpi1 = $stKpi->get_result()->fetch_assoc();

$kpiRevenue = (int)$kpi1['revenue'];

$stO = $conn->prepare("SELECT COUNT(*) FROM orders WHERE DATE(created_at)>=?");
$stO->bind_param('s', $fromDate); $stO->execute();
$kpiOrders = (int)$stO->get_result()->fetch_row()[0];

$stD = $conn->prepare("SELECT COUNT(*) FROM orders WHERE DATE(created_at)>=? AND status='delivered'");
$stD->bind_param('s', $fromDate); $stD->execute();
$kpiDelivered = (int)$stD->get_result()->fetch_row()[0];

$stC = $conn->prepare("SELECT COUNT(*) FROM orders WHERE DATE(created_at)>=? AND status='cancelled'");
$stC->bind_param('s', $fromDate); $stC->execute();
$kpiCancelled = (int)$stC->get_result()->fetch_row()[0];

$stU = $conn->prepare("SELECT COUNT(*) FROM users WHERE DATE(created_at)>=? AND role='customer'");
$stU->bind_param('s', $fromDate); $stU->execute();
$kpiNewUsers = (int)$stU->get_result()->fetch_row()[0];
$kpiAvgOrder   = $kpiOrders > 0 ? (int)($kpiRevenue / max($kpiOrders - $kpiCancelled, 1)) : 0;

/* ── Lợi nhuận kỳ ── */
$hasCostPrice = false;
$cpCol = $conn->query("SHOW COLUMNS FROM `products` LIKE 'cost_price'");
if ($cpCol && $cpCol->num_rows > 0) {
    $anyCP = (int)$conn->query("SELECT COUNT(*) FROM products WHERE cost_price > 0")->fetch_row()[0];
    $hasCostPrice = $anyCP > 0;
}
if ($hasCostPrice) {
    $stKP = $conn->prepare("
        SELECT COALESCE(SUM(oi.price*oi.qty) - SUM(COALESCE(p.cost_price,0)*oi.qty), 0)
        FROM orders o
        JOIN order_items oi ON o.id = oi.order_id
        JOIN products p ON p.id = oi.product_id
        WHERE DATE(o.created_at) >= ? AND o.status != 'cancelled'");
    $stKP->bind_param('s', $fromDate); $stKP->execute();
    $kpiProfit = max(0, (int)$stKP->get_result()->fetch_row()[0]);
} else {
    $kpiProfit = (int)($kpiRevenue * 0.30);
}
$kpiMargin = $kpiRevenue > 0 ? round($kpiProfit / $kpiRevenue * 100, 1) : 0;

/* ────────────────────────────────────── */
/* Doanh thu theo ngày/tháng              */
/* ────────────────────────────────────── */
$stRev = $conn->prepare("
    SELECT DATE_FORMAT(created_at,?) AS period,
           COALESCE(SUM(total),0) AS revenue,
           COUNT(*) AS orders
    FROM orders
    WHERE DATE(created_at) >= ? AND status != 'cancelled'
    GROUP BY period
    ORDER BY period ASC");
$stRev->bind_param('ss', $groupFmt, $fromDate);
$stRev->execute();
$revRows = $stRev->get_result()->fetch_all(MYSQLI_ASSOC);

/* Lợi nhuận theo period */
$profitByPeriod = [];
if ($hasCostPrice) {
    $stRP = $conn->prepare("
        SELECT DATE_FORMAT(o.created_at, ?) AS period,
               COALESCE(SUM(oi.price*oi.qty) - SUM(COALESCE(p.cost_price,0)*oi.qty), 0) AS profit
        FROM orders o
        JOIN order_items oi ON o.id = oi.order_id
        JOIN products p ON p.id = oi.product_id
        WHERE DATE(o.created_at) >= ? AND o.status != 'cancelled'
        GROUP BY period ORDER BY period ASC");
    $stRP->bind_param('ss', $groupFmt, $fromDate); $stRP->execute();
    foreach ($stRP->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $profitByPeriod[$row['period']] = max(0, (int)$row['profit']);
    }
}
foreach ($revRows as &$r) {
    $r['profit'] = $hasCostPrice
        ? ($profitByPeriod[$r['period']] ?? 0)
        : (int)($r['revenue'] * 0.30);
}
unset($r);

/* Gộp thành tuần nếu period=4w */
if ($period === '4w') {
    $chartData = [];
    for ($w = 3; $w >= 0; $w--) {
        $ws = date('Y-m-d', strtotime('-' . ($w * 7 + 6) . ' days'));
        $we = date('Y-m-d', strtotime('-' . ($w * 7) . ' days'));
        $rv = 0; $pf = 0;
        foreach ($revRows as $r) {
            if ($r['period'] >= $ws && $r['period'] <= $we) {
                $rv += (int)$r['revenue'];
                $pf += (int)$r['profit'];
            }
        }
        $chartData[] = [
            'period'  => date('d/m', strtotime('-' . ($w * 7 + 6) . ' days')) . '–' . date('d/m', strtotime('-' . ($w * 7) . ' days')),
            'revenue' => $rv,
            'profit'  => $pf,
        ];
    }
} else {
    $chartData = $revRows;
}
$maxRev = max(array_column($chartData, 'revenue') ?: [1]);

/* ────────────────────────────────────── */
/* Phân bổ trạng thái đơn hàng            */
/* ────────────────────────────────────── */
$stSt = $conn->prepare("
    SELECT status, COUNT(*) AS cnt
    FROM orders
    WHERE DATE(created_at) >= ?
    GROUP BY status");
$stSt->bind_param('s', $fromDate);
$stSt->execute();
$statusRows = $stSt->get_result()->fetch_all(MYSQLI_ASSOC);
$statusMap = [];
foreach ($statusRows as $s) $statusMap[$s['status']] = (int)$s['cnt'];
$totalForStatus = array_sum($statusMap) ?: 1;

$statusCfg = [
    'pending'    => ['label'=>'Chờ xác nhận','color'=>'#f59e0b','class'=>'badge-pending'],
    'processing' => ['label'=>'Đang xử lý',  'color'=>'#3b82f6','class'=>'badge-processing'],
    'shipping'   => ['label'=>'Đang giao',   'color'=>'#8b5cf6','class'=>'badge-shipping'],
    'delivered'  => ['label'=>'Đã giao',     'color'=>'#10b981','class'=>'badge-delivered'],
    'cancelled'  => ['label'=>'Đã hủy',      'color'=>'#ef4444','class'=>'badge-cancelled'],
];

/* ────────────────────────────────────── */
/* Top 10 sản phẩm bán chạy              */
/* ────────────────────────────────────── */
/* ── Phân tích hiệu quả sản phẩm (xếp theo tổng lợi nhuận) ── */
if ($hasCostPrice) {
    $stPerf = $conn->prepare("
        SELECT p.name, p.image, p.price, COALESCE(p.cost_price,0) AS cost_price,
               SUM(oi.qty) AS qty_sold,
               SUM(oi.price * oi.qty) AS total_revenue,
               SUM((oi.price - COALESCE(p.cost_price,0)) * oi.qty) AS total_profit
        FROM order_items oi
        JOIN products p ON p.id = oi.product_id
        JOIN orders o ON o.id = oi.order_id
        WHERE DATE(o.created_at) >= ? AND o.status != 'cancelled'
        GROUP BY oi.product_id
        ORDER BY total_profit DESC
        LIMIT 10");
} else {
    $stPerf = $conn->prepare("
        SELECT p.name, p.image, p.price, 0 AS cost_price,
               SUM(oi.qty) AS qty_sold,
               SUM(oi.price * oi.qty) AS total_revenue,
               SUM(oi.price * oi.qty) * 0.30 AS total_profit
        FROM order_items oi
        JOIN products p ON p.id = oi.product_id
        JOIN orders o ON o.id = oi.order_id
        WHERE DATE(o.created_at) >= ? AND o.status != 'cancelled'
        GROUP BY oi.product_id
        ORDER BY total_profit DESC
        LIMIT 10");
}
$stPerf->bind_param('s', $fromDate);
$stPerf->execute();
$perfProducts = $stPerf->get_result()->fetch_all(MYSQLI_ASSOC);

/* ────────────────────────────────────── */
/* Phân bổ phương thức thanh toán         */
/* ────────────────────────────────────── */
$stPay = $conn->prepare("
    SELECT payment_method, COUNT(*) AS cnt, COALESCE(SUM(total),0) AS revenue
    FROM orders
    WHERE DATE(created_at) >= ? AND status != 'cancelled'
    GROUP BY payment_method");
$stPay->bind_param('s', $fromDate);
$stPay->execute();
$payRows = $stPay->get_result()->fetch_all(MYSQLI_ASSOC);
$payLabels = ['cod'=>'COD (Tiền mặt)','momo'=>'Ví MoMo','bank'=>'Thẻ ATM/Tín dụng'];
$payColors = ['cod'=>'#f59e0b','momo'=>'#ec4899','bank'=>'#3b82f6'];
$totalPayOrders = array_sum(array_column($payRows, 'cnt')) ?: 1;

/* ────────────────────────────────────── */
/* Biểu đồ đăng ký KH theo ngày/tháng    */
/* ────────────────────────────────────── */
$thisYear    = (int)date('Y');
$thisMonth   = (int)date('m');
$daysInMonth = (int)date('t');

$stUsrDay = $conn->prepare("
    SELECT DAY(created_at) AS d, COUNT(*) AS cnt
    FROM users
    WHERE role='customer' AND YEAR(created_at)=? AND MONTH(created_at)=?
    GROUP BY DAY(created_at)");
$stUsrDay->bind_param('ii', $thisYear, $thisMonth);
$stUsrDay->execute();
$usrDayMap = [];
foreach ($stUsrDay->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $usrDayMap[(int)$r['d']] = (int)$r['cnt'];
}
$dailyRegLabels = [];
$dailyRegData   = [];
for ($d = 1; $d <= $daysInMonth; $d++) {
    $dailyRegLabels[] = sprintf('%02d/%02d', $d, $thisMonth);
    $dailyRegData[]   = $usrDayMap[$d] ?? 0;
}

$stUsrMon = $conn->prepare("
    SELECT MONTH(created_at) AS m, COUNT(*) AS cnt
    FROM users
    WHERE role='customer' AND YEAR(created_at)=?
    GROUP BY MONTH(created_at)");
$stUsrMon->bind_param('i', $thisYear);
$stUsrMon->execute();
$usrMonMap = [];
foreach ($stUsrMon->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $usrMonMap[(int)$r['m']] = (int)$r['cnt'];
}
$monthlyRegLabels = [];
$monthlyRegData   = [];
for ($m = 1; $m <= 12; $m++) {
    $monthlyRegLabels[] = sprintf('%02d/%04d', $m, $thisYear);
    $monthlyRegData[]   = $usrMonMap[$m] ?? 0;
}
$totalRegDay   = array_sum($dailyRegData);
$totalRegMonth = array_sum($monthlyRegData);

/* ────────────────────────────────────── */
/* Phân tích hiệu quả SP theo tuần/tháng */
/* ────────────────────────────────────── */
$perfProfitExpr = $hasCostPrice
    ? "GREATEST(SUM((oi.price - COALESCE(p.cost_price,0)) * oi.qty), 0)"
    : "SUM(oi.price * oi.qty) * 0.30";

$weekLabel  = 'Tuần ' . (int)date('W') . ' - ' . $thisYear;
$monthLabel = 'Tháng ' . sprintf('%02d', $thisMonth) . ' - ' . $thisYear;

$stPerfW = $conn->prepare("
    SELECT p.name, SUM(oi.qty) AS qty_sold,
           SUM(oi.price * oi.qty) AS revenue,
           {$perfProfitExpr} AS profit
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    JOIN products p ON p.id = oi.product_id
    WHERE o.status IN ('delivered','reviewed')
      AND YEARWEEK(o.created_at, 1) = YEARWEEK(CURDATE(), 1)
    GROUP BY p.id ORDER BY revenue DESC LIMIT 10");
$stPerfW->execute();
$perfWeekRows = $stPerfW->get_result()->fetch_all(MYSQLI_ASSOC);

$stPerfM = $conn->prepare("
    SELECT p.name, SUM(oi.qty) AS qty_sold,
           SUM(oi.price * oi.qty) AS revenue,
           {$perfProfitExpr} AS profit
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    JOIN products p ON p.id = oi.product_id
    WHERE o.status IN ('delivered','reviewed')
      AND YEAR(o.created_at)=? AND MONTH(o.created_at)=?
    GROUP BY p.id ORDER BY revenue DESC LIMIT 10");
$stPerfM->bind_param('ii', $thisYear, $thisMonth);
$stPerfM->execute();
$perfMonRows = $stPerfM->get_result()->fetch_all(MYSQLI_ASSOC);

/* ────────────────────────────────────── */
/* Danh mục bán chạy                      */
/* ────────────────────────────────────── */
$stCat = $conn->prepare("
    SELECT c.name AS cat_name, SUM(oi.qty) AS total_sold, SUM(oi.qty*oi.price) AS revenue
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    JOIN categories c ON c.id = p.category_id
    JOIN orders o ON o.id = oi.order_id
    WHERE DATE(o.created_at) >= ? AND o.status != 'cancelled'
    GROUP BY c.id
    ORDER BY total_sold DESC");
$stCat->bind_param('s', $fromDate);
$stCat->execute();
$catRows = $stCat->get_result()->fetch_all(MYSQLI_ASSOC);
$totalCatSold = array_sum(array_column($catRows, 'total_sold')) ?: 1;

$extraHead = '<style>
  @media print {
    .sidebar, .topbar, .btn, .status-tabs { display:none!important; }
    .main-content { margin:0!important; padding:0!important; }
    .admin-layout { display:block!important; }
    .card { box-shadow:none!important; border:1px solid #ddd!important; break-inside:avoid; }
  }
</style>';

include 'includes/header.php';
?>

<!-- ── Period selector + Print ── -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px">
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php foreach(['7d'=>'7 ngày','4w'=>'4 tuần','month'=>'30 ngày','quarter'=>'3 tháng','year'=>'12 tháng'] as $k=>$lbl): ?>
    <a href="reports.php?period=<?= $k ?>"
       class="btn <?= $period===$k ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
      <?= $lbl ?>
    </a>
    <?php endforeach; ?>
    <span style="align-self:center;font-size:13px;color:var(--gray-500)">
      — <?= $periodLabel ?>
    </span>
  </div>
  <div style="display:flex;gap:8px">
    <a href="export_report.php?period=<?= $period ?>" class="btn btn-secondary btn-sm">
      <svg viewBox="0 0 24 24" style="width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Xuất CSV
    </a>
    <button class="btn btn-secondary btn-sm" onclick="window.print()">
      <svg viewBox="0 0 24 24" style="width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      In báo cáo
    </button>
  </div>
</div>

<!-- ── KPI CARDS (6 cards, 3 cols) ── -->
<div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px">
  <div class="stat-card">
    <div class="stat-icon green">
      <svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-label">Doanh thu</div>
      <div class="stat-value text-green-val"><?= fmtMoney($kpiRevenue) ?></div>
      <div class="stat-sub"><?= $periodLabel ?></div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon blue">
      <svg viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-label">Lợi nhuận</div>
      <div class="stat-value" style="color:#2563eb"><?= fmtMoney($kpiProfit) ?></div>
      <div class="stat-sub"><?= $hasCostPrice ? $periodLabel : 'Ước tính 30% doanh thu' ?></div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon <?= $kpiMargin >= 25 ? 'green' : ($kpiMargin >= 10 ? 'amber' : 'red') ?>">
      <svg viewBox="0 0 24 24"><line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-label">Tỷ suất lợi nhuận</div>
      <div class="stat-value"><?= $kpiMargin ?>%</div>
      <div class="stat-sub"><?= $periodLabel ?></div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon amber">
      <svg viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="2"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-label">Tổng đơn hàng</div>
      <div class="stat-value"><?= fmtNum($kpiOrders) ?></div>
      <div class="stat-sub">Đã giao: <?= $kpiDelivered ?> | Hủy: <?= $kpiCancelled ?></div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon green">
      <svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-label">Giá trị TB / đơn</div>
      <div class="stat-value"><?= fmtMoney($kpiAvgOrder) ?></div>
      <div class="stat-sub">Không tính đơn hủy</div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon purple">
      <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-label">Khách hàng mới</div>
      <div class="stat-value"><?= fmtNum($kpiNewUsers) ?></div>
      <div class="stat-sub">Đăng ký trong kỳ</div>
    </div>
  </div>
</div>

<!-- ── ROW: Doanh thu + Trạng thái đơn ── -->
<div class="two-col" style="margin-bottom:20px">

  <!-- Biểu đồ doanh thu & lợi nhuận -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">Doanh thu &amp; Lợi nhuận</div>
      <span class="text-muted" style="font-size:12px"><?= $periodLabel ?></span>
    </div>
    <div class="card-body">
      <?php if (empty($chartData)): ?>
        <div style="color:var(--gray-400);text-align:center;padding:30px">Chưa có dữ liệu</div>
      <?php else: ?>
        <canvas id="revProfitChart" height="130"></canvas>
        <div style="margin-top:10px;display:flex;gap:20px;flex-wrap:wrap;font-size:12px;color:var(--gray-400)">
          <span>Doanh thu: <strong style="color:#166534"><?= fmtMoney(array_sum(array_column($chartData,'revenue'))) ?></strong></span>
          <span>Lợi nhuận: <strong style="color:#2563eb"><?= fmtMoney(array_sum(array_column($chartData,'profit'))) ?></strong></span>
          <?php if (!$hasCostPrice): ?>
            <span style="color:#d97706">⚠ Ước tính 30% (chưa cập nhật giá vốn)</span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Trạng thái đơn -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">Phân bổ trạng thái đơn hàng</div>
      <span class="text-muted" style="font-size:12px">Tổng <?= array_sum($statusMap) ?> đơn</span>
    </div>
    <div class="card-body">
      <?php foreach ($statusCfg as $key => $cfg):
        $cnt = $statusMap[$key] ?? 0;
        $pct = round($cnt / $totalForStatus * 100);
      ?>
      <div style="margin-bottom:14px">
        <div style="display:flex;justify-content:space-between;margin-bottom:5px;align-items:center">
          <span class="badge <?= $cfg['class'] ?>"><?= $cfg['label'] ?></span>
          <span style="font-size:13px;font-weight:700"><?= $cnt ?>
            <span style="font-weight:400;color:var(--gray-400)">(<?= $pct ?>%)</span>
          </span>
        </div>
        <div style="height:6px;background:var(--gray-100);border-radius:99px;overflow:hidden">
          <div style="height:100%;width:<?= $pct ?>%;background:<?= $cfg['color'] ?>;border-radius:99px;transition:width .5s"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ── ROW: Top products + Danh mục ── -->
<div class="two-col" style="margin-bottom:20px">

  <!-- Phân tích hiệu quả sản phẩm -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">Phân tích hiệu quả sản phẩm</div>
      <a href="products.php" class="btn btn-sm btn-secondary">Tất cả SP</a>
    </div>
    <div class="card-body p0">
      <?php if (empty($perfProducts)): ?>
        <div class="empty-state"><p>Chưa có dữ liệu bán hàng trong kỳ.</p></div>
      <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Sản phẩm</th>
              <th>Đã bán</th>
              <th>Tỷ suất LN</th>
              <th>Doanh thu</th>
              <th>Lợi nhuận<?= !$hasCostPrice ? ' <span title="Ước tính 30%" style="color:#d97706">*</span>' : '' ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($perfProducts as $i => $tp):
              $margin = (float)$tp['total_revenue'] > 0
                  ? round((float)$tp['total_profit'] / (float)$tp['total_revenue'] * 100, 1)
                  : 0;
              $mc = $margin >= 30 ? 'var(--green-600)' : ($margin >= 15 ? '#d97706' : '#dc2626');
            ?>
            <tr>
              <td style="color:var(--gray-400);font-weight:700"><?= $i+1 ?></td>
              <td>
                <div class="product-thumb">
                  <img src="../images/<?= htmlspecialchars($tp['image'] ?? '') ?>"
                       onerror="this.onerror=null;this.src='../images/logo.png'"
                       alt="<?= htmlspecialchars($tp['name']) ?>">
                  <span class="td-name"><?= htmlspecialchars($tp['name']) ?></span>
                </div>
              </td>
              <td><strong><?= (int)$tp['qty_sold'] ?></strong> <span class="text-muted">sp</span></td>
              <td style="font-weight:700;color:<?= $mc ?>"><?= $margin ?>%</td>
              <td style="color:var(--red-700);font-weight:700"><?= fmtMoney((int)$tp['total_revenue']) ?></td>
              <td style="color:var(--green-700);font-weight:700"><?= fmtMoney((int)$tp['total_profit']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (!$hasCostPrice): ?>
        <div style="padding:8px 16px;font-size:12px;color:#92400e;background:#fffbeb;border-top:1px solid #fde68a">
          * Lợi nhuận ước tính = 30% doanh thu.
          <a href="products.php" style="color:#166534;font-weight:600;margin-left:4px">Cập nhật giá vốn →</a>
        </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Danh mục + Phương thức thanh toán -->
  <div style="display:flex;flex-direction:column;gap:16px">

    <!-- Danh mục bán chạy -->
    <div class="card" style="flex:1">
      <div class="card-header">
        <div class="card-title">Doanh thu theo danh mục</div>
      </div>
      <div class="card-body">
        <?php if (empty($catRows)): ?>
          <div style="color:var(--gray-400);text-align:center;padding:20px">Chưa có dữ liệu</div>
        <?php else: ?>
          <?php foreach ($catRows as $cr):
            $pct = round((int)$cr['total_sold'] / $totalCatSold * 100);
          ?>
          <div style="margin-bottom:12px">
            <div style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:13px">
              <span style="font-weight:600"><?= htmlspecialchars($cr['cat_name']) ?></span>
              <span style="color:var(--gray-500)"><?= (int)$cr['total_sold'] ?> sp (<?= $pct ?>%)</span>
            </div>
            <div style="height:6px;background:var(--gray-100);border-radius:99px;overflow:hidden">
              <div style="height:100%;width:<?= $pct ?>%;background:var(--green-500,#52b788);border-radius:99px"></div>
            </div>
            <div style="font-size:12px;color:var(--red-600);font-weight:600;margin-top:2px">
              <?= fmtMoney((int)$cr['revenue']) ?>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Phương thức thanh toán -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">Phương thức thanh toán</div>
      </div>
      <div class="card-body">
        <?php if (empty($payRows)): ?>
          <div style="color:var(--gray-400);text-align:center;padding:20px">Chưa có dữ liệu</div>
        <?php else: ?>
          <?php foreach ($payRows as $pr):
            $pct = round((int)$pr['cnt'] / $totalPayOrders * 100);
            $key = $pr['payment_method'];
            $color = $payColors[$key] ?? '#6b7280';
          ?>
          <div style="margin-bottom:12px">
            <div style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:13px">
              <span style="font-weight:600"><?= $payLabels[$key] ?? $key ?></span>
              <span style="color:var(--gray-500)"><?= $pr['cnt'] ?> đơn (<?= $pct ?>%)</span>
            </div>
            <div style="height:6px;background:var(--gray-100);border-radius:99px;overflow:hidden">
              <div style="height:100%;width:<?= $pct ?>%;background:<?= $color ?>;border-radius:99px"></div>
            </div>
            <div style="font-size:12px;color:var(--red-600);font-weight:600;margin-top:2px">
              <?= fmtMoney((int)$pr['revenue']) ?>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<!-- ── Biểu đồ Khách hàng đăng ký mới ── -->
<div class="card" style="margin-bottom:20px">
  <div class="card-header">
    <div class="card-title">Khách hàng đăng ký mới</div>
    <div style="display:flex;gap:8px;align-items:center">
      <select id="userChartMode" class="filter-select" style="font-size:12px;padding:5px 10px;height:32px">
        <option value="day">Theo Ngày — <?= sprintf('%02d/%04d', $thisMonth, $thisYear) ?></option>
        <option value="month">Theo Tháng — <?= $thisYear ?></option>
      </select>
      <a href="users.php" class="btn btn-sm btn-secondary">Xem tất cả</a>
    </div>
  </div>
  <div class="card-body">
    <canvas id="userRegChart" height="100"></canvas>
    <div style="margin-top:8px;font-size:12px;color:var(--gray-400)" id="userRegSub">
      Tháng <?= sprintf('%02d/%04d', $thisMonth, $thisYear) ?>:
      <strong style="color:#3b82f6"><?= $totalRegDay ?> khách mới</strong>
    </div>
  </div>
</div>

<!-- ── Phân tích hiệu quả SP theo Tuần/Tháng ── -->
<div class="card" style="margin-bottom:20px">
  <div class="card-header">
    <div class="card-title">Hiệu quả sản phẩm (đơn hoàn thành)</div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <select id="perfPeriodMode" class="filter-select" style="font-size:12px;padding:5px 10px;height:32px">
        <option value="week"><?= htmlspecialchars($weekLabel) ?></option>
        <option value="month"><?= htmlspecialchars($monthLabel) ?></option>
      </select>
      <select id="perfMetricMode" class="filter-select" style="font-size:12px;padding:5px 10px;height:32px">
        <option value="revenue">Doanh thu</option>
        <option value="profit">Lợi nhuận<?= !$hasCostPrice ? ' (ước tính)' : '' ?></option>
        <option value="qty">Số lượng bán</option>
      </select>
    </div>
  </div>
  <div class="card-body">
    <?php if (empty($perfWeekRows) && empty($perfMonRows)): ?>
      <div style="color:var(--gray-400);text-align:center;padding:30px">
        Chưa có đơn hàng hoàn thành trong kỳ
      </div>
    <?php else: ?>
      <canvas id="perfChart" height="130"></canvas>
      <?php if (!$hasCostPrice): ?>
        <div style="margin-top:8px;font-size:12px;color:#d97706">
          ⚠ Lợi nhuận ước tính = 30% doanh thu.
          <a href="products.php" style="color:#166534;font-weight:600">Cập nhật giá vốn →</a>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<!-- ── Bảng chi tiết đơn hàng trong kỳ ── -->
<div class="card">
  <div class="card-header">
    <div class="card-title">Chi tiết đơn hàng trong kỳ</div>
    <a href="orders.php" class="btn btn-sm btn-secondary">Quản lý đơn</a>
  </div>
  <div class="card-body p0">
    <?php
    $recentOrders = $conn->query("
        SELECT o.id, o.full_name, o.total, o.status, o.payment_method, o.created_at,
               COUNT(oi.id) AS item_count
        FROM orders o
        LEFT JOIN order_items oi ON oi.order_id = o.id
        WHERE DATE(o.created_at) >= '$fromDate'
        GROUP BY o.id
        ORDER BY o.created_at DESC
        LIMIT 20
    ")->fetch_all(MYSQLI_ASSOC);

    $scLabels = [
        'pending'=>['label'=>'Chờ xác nhận','class'=>'badge-pending'],
        'processing'=>['label'=>'Đang xử lý','class'=>'badge-processing'],
        'shipping'=>['label'=>'Đang giao','class'=>'badge-shipping'],
        'delivered'=>['label'=>'Đã giao','class'=>'badge-delivered'],
        'cancelled'=>['label'=>'Đã hủy','class'=>'badge-cancelled'],
    ];
    $payLbl = ['cod'=>'COD','momo'=>'MoMo','bank'=>'Thẻ'];
    ?>
    <?php if (empty($recentOrders)): ?>
      <div class="empty-state"><p>Chưa có đơn hàng trong kỳ này.</p></div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>#ID</th><th>Khách hàng</th><th>Số SP</th>
            <th>Tổng tiền</th><th>Thanh toán</th><th>Trạng thái</th><th>Ngày đặt</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentOrders as $o):
            $sc = $scLabels[$o['status']] ?? ['label'=>$o['status'],'class'=>'badge-gray'];
          ?>
          <tr>
            <td><a href="order_detail.php?id=<?= $o['id'] ?>" class="text-green fw700">#<?= $o['id'] ?></a></td>
            <td class="td-name"><?= htmlspecialchars($o['full_name']) ?></td>
            <td class="text-muted"><?= (int)$o['item_count'] ?> sp</td>
            <td style="color:var(--red-700);font-weight:700"><?= fmtMoney((int)$o['total']) ?></td>
            <td><span class="badge badge-gray"><?= $payLbl[$o['payment_method']] ?? $o['payment_method'] ?></span></td>
            <td><span class="badge <?= $sc['class'] ?>"><?= $sc['label'] ?></span></td>
            <td class="text-muted"><?= date('d/m/Y H:i', strtotime($o['created_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
/* ── Biểu đồ Doanh thu & Lợi nhuận ── */
(function () {
  var canvas = document.getElementById('revProfitChart');
  if (!canvas) return;
  var labels  = <?= json_encode(array_column($chartData, 'period')) ?>;
  var revenue = <?= json_encode(array_map(fn($r) => (int)$r['revenue'], $chartData)) ?>;
  var profit  = <?= json_encode(array_map(fn($r) => (int)($r['profit'] ?? 0), $chartData)) ?>;

  new Chart(canvas, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [
        {
          label: 'Doanh thu',
          data: revenue,
          backgroundColor: 'rgba(22,101,52,0.15)',
          borderColor: '#166534',
          borderWidth: 2,
          borderRadius: 5,
          borderSkipped: false,
        },
        {
          label: '<?= $hasCostPrice ? "Lợi nhuận" : "LN ước tính (30%)" ?>',
          data: profit,
          backgroundColor: 'rgba(37,99,235,0.15)',
          borderColor: '#2563eb',
          borderWidth: 2,
          borderRadius: 5,
          borderSkipped: false,
        }
      ]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { position: 'top', labels: { font: { size: 11 }, boxWidth: 12, padding: 10 } },
        tooltip: {
          callbacks: {
            label: function(ctx) {
              return ' ' + ctx.dataset.label + ': ' + new Intl.NumberFormat('vi-VN').format(ctx.raw) + 'đ';
            }
          }
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            callback: function(v) {
              if (v >= 1000000) return (v/1000000).toFixed(1) + 'tr';
              if (v >= 1000)    return (v/1000).toFixed(0) + 'k';
              return v;
            },
            font: { size: 11 }
          },
          grid: { color: 'rgba(0,0,0,0.04)' }
        },
        x: { ticks: { font: { size: 11 }, maxRotation: 45 }, grid: { display: false } }
      }
    }
  });
})();

/* ── Biểu đồ Khách hàng đăng ký mới ── */
(function () {
  var canvas = document.getElementById('userRegChart');
  if (!canvas) return;

  var dailyLabels   = <?= json_encode($dailyRegLabels) ?>;
  var dailyData     = <?= json_encode($dailyRegData) ?>;
  var monthlyLabels = <?= json_encode($monthlyRegLabels) ?>;
  var monthlyData   = <?= json_encode($monthlyRegData) ?>;
  var totalDay      = <?= $totalRegDay ?>;
  var totalMonth    = <?= $totalRegMonth ?>;
  var subEl         = document.getElementById('userRegSub');
  var monthLabel    = '<?= sprintf('%02d/%04d', $thisMonth, $thisYear) ?>';
  var yearLabel     = '<?= $thisYear ?>';

  var userChart = new Chart(canvas, {
    type: 'bar',
    data: {
      labels: dailyLabels,
      datasets: [{
        label: 'Khách đăng ký',
        data: dailyData,
        backgroundColor: 'rgba(59,130,246,0.18)',
        borderColor: '#3b82f6',
        borderWidth: 2,
        borderRadius: 4,
        borderSkipped: false,
      }]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: function(c){ return ' ' + c.raw + ' khách'; } } }
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: { stepSize: 1, precision: 0, font: { size: 11 } },
          grid: { color: 'rgba(0,0,0,0.04)' }
        },
        x: { ticks: { font: { size: 10 }, maxRotation: 45 }, grid: { display: false } }
      }
    }
  });

  document.getElementById('userChartMode').addEventListener('change', function () {
    var isDay = this.value === 'day';
    userChart.data.labels = isDay ? dailyLabels : monthlyLabels;
    userChart.data.datasets[0].data = isDay ? dailyData : monthlyData;
    userChart.update();
    var total = isDay ? totalDay : totalMonth;
    var period = isDay ? ('Tháng ' + monthLabel) : ('Năm ' + yearLabel);
    subEl.innerHTML = period + ': <strong style="color:#3b82f6">' + total + ' khách mới</strong>';
  });
})();

/* ── Biểu đồ Hiệu quả sản phẩm theo Tuần/Tháng ── */
(function () {
  var canvas = document.getElementById('perfChart');
  if (!canvas) return;

  var weekData  = <?= json_encode(array_map(fn($r) => [
      'name'    => $r['name'],
      'revenue' => (int)$r['revenue'],
      'profit'  => (int)($r['profit'] ?? 0),
      'qty'     => (int)$r['qty_sold'],
  ], $perfWeekRows)) ?>;
  var monthData = <?= json_encode(array_map(fn($r) => [
      'name'    => $r['name'],
      'revenue' => (int)$r['revenue'],
      'profit'  => (int)($r['profit'] ?? 0),
      'qty'     => (int)$r['qty_sold'],
  ], $perfMonRows)) ?>;

  var metaCfg = {
    revenue: { label: 'Doanh thu', color: '#166534', bg: 'rgba(22,101,52,0.15)', fmt: function(v){ return new Intl.NumberFormat('vi-VN').format(v) + 'đ'; } },
    profit:  { label: '<?= $hasCostPrice ? "Lợi nhuận" : "LN ước tính" ?>', color: '#2563eb', bg: 'rgba(37,99,235,0.15)', fmt: function(v){ return new Intl.NumberFormat('vi-VN').format(v) + 'đ'; } },
    qty:     { label: 'Số lượng bán', color: '#d97706', bg: 'rgba(217,119,6,0.15)', fmt: function(v){ return v + ' sp'; } }
  };

  function buildDataset(rows, metric) {
    var cfg = metaCfg[metric];
    return {
      label: cfg.label,
      data: rows.map(function(r){ return r[metric]; }),
      backgroundColor: cfg.bg,
      borderColor: cfg.color,
      borderWidth: 2,
      borderRadius: 5,
      borderSkipped: false,
    };
  }

  var currentPeriod = 'week';
  var currentMetric = 'revenue';
  var activeRows    = weekData.length ? weekData : monthData;

  var perfChart = new Chart(canvas, {
    type: 'bar',
    data: {
      labels: activeRows.map(function(r){ return r.name; }),
      datasets: [ buildDataset(activeRows, currentMetric) ]
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: function(c){ return ' ' + metaCfg[currentMetric].fmt(c.raw); }
          }
        }
      },
      scales: {
        x: {
          beginAtZero: true,
          ticks: {
            callback: function(v) {
              if (currentMetric === 'qty') return v;
              if (v >= 1000000) return (v/1000000).toFixed(1) + 'tr';
              if (v >= 1000)    return (v/1000).toFixed(0) + 'k';
              return v;
            },
            font: { size: 11 }
          },
          grid: { color: 'rgba(0,0,0,0.04)' }
        },
        y: { ticks: { font: { size: 11 } }, grid: { display: false } }
      }
    }
  });

  function updatePerfChart() {
    var rows = currentPeriod === 'week' ? weekData : monthData;
    if (!rows.length) rows = currentPeriod === 'week' ? monthData : weekData;
    perfChart.data.labels = rows.map(function(r){ return r.name; });
    perfChart.data.datasets = [ buildDataset(rows, currentMetric) ];
    perfChart.update();
  }

  document.getElementById('perfPeriodMode').addEventListener('change', function(){
    currentPeriod = this.value;
    updatePerfChart();
  });
  document.getElementById('perfMetricMode').addEventListener('change', function(){
    currentMetric = this.value;
    updatePerfChart();
  });
})();
</script>

<?php include 'includes/footer.php'; ?>
