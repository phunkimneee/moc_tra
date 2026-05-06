<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

$pageTitle  = 'Tổng quan';
$activePage = 'dashboard';
$breadcrumb = [];

/* ── Pending count (sidebar badge + alert) ── */
$stPending = $conn->prepare("SELECT COUNT(*) FROM orders WHERE status='pending'");
$stPending->execute();
$pendingCount = (int)$stPending->get_result()->fetch_row()[0];

/* ── STATS ── */
$today     = date('Y-m-d');
$thisYear  = (int)date('Y');
$thisMonth = (int)date('n');

/* Doanh thu hôm nay */
$st = $conn->prepare("SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE(created_at)=? AND status!='cancelled'");
$st->bind_param('s', $today); $st->execute();
$revenueToday = (int)$st->get_result()->fetch_row()[0];

/* Doanh thu tháng này */
$st2 = $conn->prepare("SELECT COALESCE(SUM(total),0) FROM orders WHERE YEAR(created_at)=? AND MONTH(created_at)=? AND status!='cancelled'");
$st2->bind_param('ii', $thisYear, $thisMonth); $st2->execute();
$revenueMonth = (int)$st2->get_result()->fetch_row()[0];

/* Đơn hàng hôm nay */
$st3 = $conn->prepare("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=?");
$st3->bind_param('s', $today); $st3->execute();
$ordersToday = (int)$st3->get_result()->fetch_row()[0];

/* Tổng đơn */
$totalOrders = (int)$conn->query("SELECT COUNT(*) FROM orders")->fetch_row()[0];

/* Tổng SP */
$totalProducts = (int)$conn->query("SELECT COUNT(*) FROM products")->fetch_row()[0];

/* Tổng users (customer) */
$totalUsers = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetch_row()[0];

/* ── Lợi nhuận tháng ──
   Kiểm tra cột cost_price; nếu chưa có hoặc chưa có dữ liệu → fallback 30% doanh thu */
$hasCostPrice = false;
$cpCol = $conn->query("SHOW COLUMNS FROM `products` LIKE 'cost_price'");
if ($cpCol && $cpCol->num_rows > 0) {
    $anyCP = (int)$conn->query("SELECT COUNT(*) FROM products WHERE cost_price > 0")->fetch_row()[0];
    $hasCostPrice = $anyCP > 0;
}
if ($hasCostPrice) {
    $stPr = $conn->prepare(
        "SELECT COALESCE(SUM(oi.price * oi.qty) - SUM(COALESCE(p.cost_price,0) * oi.qty), 0)
         FROM orders o
         JOIN order_items oi ON o.id = oi.order_id
         JOIN products p ON p.id = oi.product_id
         WHERE YEAR(o.created_at)=? AND MONTH(o.created_at)=? AND o.status!='cancelled'"
    );
    $stPr->bind_param('ii', $thisYear, $thisMonth); $stPr->execute();
    $profitMonth = max(0, (int)$stPr->get_result()->fetch_row()[0]);
} else {
    $profitMonth = (int)($revenueMonth * 0.30);
}
$profitMargin = $revenueMonth > 0 ? round($profitMonth / $revenueMonth * 100, 1) : 0;

/* Đơn hàng theo trạng thái */
$statusCountsRaw = $conn->query("SELECT status, COUNT(*) as cnt FROM orders GROUP BY status")->fetch_all(MYSQLI_ASSOC);
$statusCounts = ['pending'=>0,'processing'=>0,'shipping'=>0,'delivered'=>0,'cancelled'=>0];
foreach ($statusCountsRaw as $s) $statusCounts[$s['status']] = (int)$s['cnt'];

/* Doanh thu 7 ngày — 1 query thay vì 7 */
$d7Start = date('Y-m-d', strtotime('-6 days'));
$revRaw  = $conn->prepare(
    "SELECT DATE(created_at) as day, COALESCE(SUM(total),0) as val
     FROM orders
     WHERE DATE(created_at) >= ? AND status!='cancelled'
     GROUP BY DATE(created_at)"
);
$revRaw->bind_param('s', $d7Start); $revRaw->execute();
$revMap = [];
foreach ($revRaw->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $revMap[$row['day']] = (int)$row['val'];
}

/* Lợi nhuận 7 ngày */
$profitMap7 = [];
if ($hasCostPrice) {
    $prRaw = $conn->prepare(
        "SELECT DATE(o.created_at) as day,
                COALESCE(SUM(oi.price*oi.qty) - SUM(COALESCE(p.cost_price,0)*oi.qty), 0) as profit
         FROM orders o
         JOIN order_items oi ON o.id = oi.order_id
         JOIN products p ON p.id = oi.product_id
         WHERE DATE(o.created_at) >= ? AND o.status!='cancelled'
         GROUP BY DATE(o.created_at)"
    );
    $prRaw->bind_param('s', $d7Start); $prRaw->execute();
    foreach ($prRaw->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $profitMap7[$row['day']] = max(0, (int)$row['profit']);
    }
}
$rev7 = [];
for ($i = 6; $i >= 0; $i--) {
    $d   = date('Y-m-d', strtotime("-$i days"));
    $rev = $revMap[$d] ?? 0;
    $rev7[] = [
        'day'    => date('d/m', strtotime("-$i days")),
        'val'    => $rev,
        'profit' => $hasCostPrice ? ($profitMap7[$d] ?? 0) : (int)($rev * 0.30),
    ];
}
$maxRev = max(array_column($rev7, 'val')) ?: 1;

/* Đơn hàng gần nhất */
$recentOrders = $conn->query(
    "SELECT o.id, o.full_name, o.total, o.status, o.payment_method, o.created_at,
            COUNT(oi.id) as item_count
     FROM orders o
     LEFT JOIN order_items oi ON oi.order_id = o.id
     GROUP BY o.id
     ORDER BY o.created_at DESC LIMIT 8"
)->fetch_all(MYSQLI_ASSOC);

/* Sản phẩm bán chạy */
$topProducts = $conn->query(
    "SELECT p.name, p.image, SUM(oi.qty) as total_sold, SUM(oi.price * oi.qty) as revenue
     FROM order_items oi
     JOIN products p ON p.id = oi.product_id
     GROUP BY oi.product_id
     ORDER BY total_sold DESC LIMIT 5"
)->fetch_all(MYSQLI_ASSOC);

function fmtMoney(int $n): string { return number_format($n, 0, ',', '.') . 'đ'; }

$statusCfg = [
    'pending'    => ['label'=>'Chờ xác nhận','class'=>'badge-pending',    'bar'=>'#f59e0b'],
    'processing' => ['label'=>'Đang xử lý',  'class'=>'badge-processing', 'bar'=>'#3b82f6'],
    'shipping'   => ['label'=>'Đang giao',   'class'=>'badge-shipping',   'bar'=>'#8b5cf6'],
    'delivered'  => ['label'=>'Đã giao',     'class'=>'badge-delivered',  'bar'=>'#16a34a'],
    'cancelled'  => ['label'=>'Đã hủy',      'class'=>'badge-cancelled',  'bar'=>'#ef4444'],
];
$payLabels = ['cod'=>'COD','momo'=>'MoMo','bank'=>'Thẻ ngân hàng'];

/* Greeting */
$hour = (int)date('H');
$greeting = $hour < 12 ? 'Chào buổi sáng' : ($hour < 18 ? 'Chào buổi chiều' : 'Chào buổi tối');
$todayLabel = date('l, d/m/Y');
$todayVi = ['Monday'=>'Thứ Hai','Tuesday'=>'Thứ Ba','Wednesday'=>'Thứ Tư',
            'Thursday'=>'Thứ Năm','Friday'=>'Thứ Sáu','Saturday'=>'Thứ Bảy','Sunday'=>'Chủ Nhật'];
$todayLabel = ($todayVi[date('l')] ?? date('l')) . ', ' . date('d/m/Y');

include 'includes/header.php';
?>

<!-- ── WELCOME ── -->
<div style="margin-bottom:28px">
  <h1 style="font-size:26px;font-weight:700;color:var(--gray-900);margin:0 0 6px">
    <?= $greeting ?>, <span style="color:var(--green-700)"><?= htmlspecialchars($adminName) ?></span> 👋
  </h1>
  <p style="margin:0;font-size:14px;color:var(--gray-400)"><?= $todayLabel ?> — Tổng quan hoạt động kinh doanh.</p>
</div>

<?php if ($pendingCount > 0): ?>
<!-- ── ALERT: Pending orders ── -->
<div class="alert alert-info" style="margin-bottom:20px">
  <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  <span>Có <strong><?= $pendingCount ?></strong> đơn hàng đang chờ xác nhận.
    <a href="orders.php?status=pending" style="font-weight:700;text-decoration:underline;margin-left:4px">Xử lý ngay →</a>
  </span>
</div>
<?php endif; ?>

<!-- ── STAT CARDS (8 cards, 4-col × 2 rows) ── -->
<div class="stats-grid" style="margin-bottom:24px">

  <div class="stat-card">
    <div class="stat-icon green">
      <svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-label">Doanh thu hôm nay</div>
      <div class="stat-value text-green-val"><?= fmtMoney($revenueToday) ?></div>
      <div class="stat-sub">Tháng này: <?= fmtMoney($revenueMonth) ?></div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon green">
      <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-label">Doanh thu tháng <?= date('n') ?></div>
      <div class="stat-value text-green-val"><?= fmtMoney($revenueMonth) ?></div>
      <div class="stat-sub">Năm <?= date('Y') ?></div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon amber">
      <svg viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="2"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-label">Đơn mới hôm nay</div>
      <div class="stat-value"><?= $ordersToday ?></div>
      <div class="stat-sub">Tổng: <?= $totalOrders ?> đơn</div>
    </div>
  </div>

  <div class="stat-card <?= $pendingCount > 0 ? 'stat-card-alert' : '' ?>">
    <div class="stat-icon <?= $pendingCount > 0 ? 'red' : 'amber' ?>">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-label">Chờ xác nhận</div>
      <div class="stat-value <?= $pendingCount > 0 ? 'text-red-val' : '' ?>"><?= $pendingCount ?></div>
      <div class="stat-sub">
        <?= $pendingCount > 0 ? '<a href="orders.php?status=pending" style="color:var(--red-700);font-weight:600">Xem ngay →</a>' : 'Không có đơn chờ' ?>
      </div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon blue">
      <svg viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-label">Sản phẩm</div>
      <div class="stat-value"><?= $totalProducts ?></div>
      <div class="stat-sub"><a href="products.php" style="color:var(--blue-700);font-weight:600">Quản lý →</a></div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon purple">
      <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-label">Khách hàng</div>
      <div class="stat-value"><?= $totalUsers ?></div>
      <div class="stat-sub"><a href="users.php" style="color:var(--purple-700);font-weight:600">Xem danh sách →</a></div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon green">
      <svg viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-label">Lợi nhuận tháng <?= date('n') ?></div>
      <div class="stat-value text-green-val"><?= fmtMoney($profitMonth) ?></div>
      <div class="stat-sub"><?= $hasCostPrice ? 'Theo giá vốn thực tế' : 'Ước tính (30% doanh thu)' ?></div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon <?= $profitMargin >= 25 ? 'green' : ($profitMargin >= 10 ? 'amber' : 'red') ?>">
      <svg viewBox="0 0 24 24"><line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-label">Tỷ suất lợi nhuận</div>
      <div class="stat-value"><?= $profitMargin ?>%</div>
      <div class="stat-sub">Tháng <?= date('n/Y') ?></div>
    </div>
  </div>
</div>

<!-- ── ROW 2: Chart + Order status ── -->
<div class="two-col" style="margin-bottom:20px">

  <!-- Biểu đồ doanh thu 7 ngày -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">
        <svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;vertical-align:-2px;margin-right:6px"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        Doanh thu 7 ngày gần nhất
      </div>
      <span class="text-muted" style="font-size:12px">Không tính đơn đã huỷ</span>
    </div>
    <div class="card-body">
      <canvas id="revChart" height="110"></canvas>
      <div style="margin-top:14px;display:flex;justify-content:space-between;align-items:center">
        <span style="font-size:12px;color:var(--gray-400)">Tổng 7 ngày</span>
        <strong class="text-green" style="font-size:15px"><?= fmtMoney(array_sum(array_column($rev7,'val'))) ?></strong>
      </div>
    </div>
  </div>

  <!-- Trạng thái đơn hàng -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">
        <svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;vertical-align:-2px;margin-right:6px"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="2"/><path d="M9 12h6M9 16h4"/></svg>
        Phân bổ trạng thái đơn hàng
      </div>
      <a href="orders.php" class="btn btn-sm btn-secondary">Xem tất cả</a>
    </div>
    <div class="card-body">
      <?php foreach ($statusCfg as $key => $cfg):
        $cnt = $statusCounts[$key] ?? 0;
        $pct = $totalOrders ? round($cnt / $totalOrders * 100) : 0;
      ?>
      <div style="margin-bottom:14px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px">
          <span class="badge <?= $cfg['class'] ?>"><?= $cfg['label'] ?></span>
          <span style="font-size:13px;font-weight:700;color:var(--gray-700)"><?= $cnt ?> <span class="text-muted">(<?= $pct ?>%)</span></span>
        </div>
        <div style="height:6px;background:var(--gray-100);border-radius:99px;overflow:hidden">
          <div style="height:100%;width:<?= $pct ?>%;background:<?= $cfg['bar'] ?>;border-radius:99px;transition:width .5s ease"></div>
        </div>
      </div>
      <?php endforeach; ?>
      <div class="divider"></div>
      <div style="display:flex;justify-content:space-between;font-size:13px">
        <span class="text-muted">Tổng đơn hàng</span>
        <strong><?= $totalOrders ?></strong>
      </div>
    </div>
  </div>
</div>

<!-- ── ROW 3: Recent orders + Top products ── -->
<div class="two-col">

  <!-- Đơn hàng gần nhất -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">
        <svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;vertical-align:-2px;margin-right:6px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        Đơn hàng mới nhất
      </div>
      <a href="orders.php" class="btn btn-sm btn-secondary">Tất cả đơn</a>
    </div>
    <div class="card-body p0">
      <?php if (empty($recentOrders)): ?>
        <div class="empty-state">
          <svg viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="2"/></svg>
          <h3>Chưa có đơn hàng</h3>
          <p>Đơn hàng mới sẽ xuất hiện ở đây</p>
        </div>
      <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Khách hàng</th>
              <th>Tổng tiền</th>
              <th>Trạng thái</th>
              <th>Ngày đặt</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentOrders as $o):
              $sc = $statusCfg[$o['status']] ?? ['label'=>$o['status'],'class'=>'badge-gray'];
            ?>
            <tr>
              <td><a href="order_detail.php?id=<?= $o['id'] ?>" class="text-green fw700">#<?= $o['id'] ?></a></td>
              <td class="td-name"><?= htmlspecialchars($o['full_name']) ?></td>
              <td style="color:var(--green-700);font-weight:700"><?= fmtMoney((int)$o['total']) ?></td>
              <td><span class="badge <?= $sc['class'] ?>"><?= $sc['label'] ?></span></td>
              <td class="text-muted"><?= date('d/m H:i', strtotime($o['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Sản phẩm bán chạy -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">
        <svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;vertical-align:-2px;margin-right:6px"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
        Sản phẩm bán chạy
      </div>
      <a href="products.php" class="btn btn-sm btn-secondary">Tất cả SP</a>
    </div>
    <div class="card-body p0">
      <?php if (empty($topProducts)): ?>
        <div class="empty-state">
          <svg viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/></svg>
          <h3>Chưa có dữ liệu</h3>
          <p>Dữ liệu bán hàng sẽ hiện sau khi có đơn giao thành công</p>
        </div>
      <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Sản phẩm</th>
              <th>Đã bán</th>
              <th>Doanh thu</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($topProducts as $i => $tp): ?>
            <tr>
              <td>
                <div class="product-thumb">
                  <span class="rank-badge rank-<?= $i+1 ?>"><?= $i+1 ?></span>
                  <img src="../images/<?= htmlspecialchars($tp['image'] ?? '') ?>"
                       onerror="this.onerror=null;this.src='../images/logo.png'"
                       alt="<?= htmlspecialchars($tp['name']) ?>">
                  <span class="td-name"><?= htmlspecialchars($tp['name']) ?></span>
                </div>
              </td>
              <td><strong><?= (int)$tp['total_sold'] ?></strong> <span class="text-muted">sp</span></td>
              <td style="color:var(--green-700);font-weight:700"><?= fmtMoney((int)$tp['revenue']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
  var labels  = <?= json_encode(array_column($rev7, 'day')) ?>;
  var revenue = <?= json_encode(array_column($rev7, 'val')) ?>;
  var profit  = <?= json_encode(array_column($rev7, 'profit')) ?>;

  new Chart(document.getElementById('revChart'), {
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
          borderRadius: 6,
          borderSkipped: false,
        },
        {
          label: '<?= $hasCostPrice ? "Lợi nhuận" : "LN ước tính" ?>',
          data: profit,
          backgroundColor: 'rgba(37,99,235,0.15)',
          borderColor: '#2563eb',
          borderWidth: 2,
          borderRadius: 6,
          borderSkipped: false,
        }
      ]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { display: true, position: 'top', labels: { font: { size: 11 }, boxWidth: 12, padding: 10 } },
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
        x: { ticks: { font: { size: 11 } }, grid: { display: false } }
      }
    }
  });
})();
</script>
<?php include 'includes/footer.php'; ?>
