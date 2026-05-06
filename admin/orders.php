<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

$pageTitle  = 'Quản lý đơn hàng';
$activePage = 'orders';
$breadcrumb = [['label' => 'Đơn hàng']];

/* ── Params ── */
$filterStatus = $_GET['status'] ?? '';
$q            = trim($_GET['q'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;

/* ── Alert từ action ── */
$msg = '';
if (!empty($_GET['updated'])) $msg = 'Cập nhật trạng thái đơn hàng thành công.';

/* ── Build WHERE ── */
$where  = ['1=1'];
$params = [];
$types  = '';

if ($filterStatus) {
    $where[]  = 'o.status = ?';
    $params[] = $filterStatus;
    $types   .= 's';
}
if ($q !== '') {
    $where[]  = '(o.id = ? OR o.full_name LIKE ? OR o.phone LIKE ?)';
    $qNum     = is_numeric($q) ? (int)$q : 0;
    $qLike    = "%$q%";
    $params[] = $qNum;
    $params[] = $qLike;
    $params[] = $qLike;
    $types   .= 'iss';
}
$whereSql = implode(' AND ', $where);

/* ── Count ── */
$countSql = "SELECT COUNT(*) FROM orders o WHERE $whereSql";
if ($params) {
    $st = $conn->prepare($countSql);
    $st->bind_param($types, ...$params);
    $st->execute();
    $total = (int)$st->get_result()->fetch_row()[0];
} else {
    $total = (int)$conn->query($countSql)->fetch_row()[0];
}

$totalPages = max(1, ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

/* ── Fetch ── */
$sql = "SELECT o.id, o.full_name, o.phone, o.address, o.total, o.status,
               o.payment_method, o.created_at,
               COUNT(oi.id) as item_count
        FROM orders o
        LEFT JOIN order_items oi ON oi.order_id = o.id
        WHERE $whereSql
        GROUP BY o.id
        ORDER BY o.created_at DESC
        LIMIT $perPage OFFSET $offset";

if ($params) {
    $st = $conn->prepare($sql);
    $st->bind_param($types, ...$params);
    $st->execute();
    $orders = $st->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $orders = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

/* ── Counts per status ── */
$statusCountsRaw = $conn->query("SELECT status, COUNT(*) as cnt FROM orders GROUP BY status")->fetch_all(MYSQLI_ASSOC);
$sCounts = [''=>0,'pending'=>0,'processing'=>0,'shipping'=>0,'delivered'=>0,'reviewed'=>0,'cancelled'=>0];
$sCounts[''] = (int)$conn->query("SELECT COUNT(*) FROM orders")->fetch_row()[0];
foreach ($statusCountsRaw as $s) $sCounts[$s['status']] = (int)$s['cnt'];

$statusCfg = [
    'pending'    => ['label'=>'Chờ xác nhận','class'=>'badge-pending'],
    'processing' => ['label'=>'Đang xử lý',  'class'=>'badge-processing'],
    'shipping'   => ['label'=>'Đang giao',   'class'=>'badge-shipping'],
    'delivered'  => ['label'=>'Đã giao',     'class'=>'badge-delivered'],
    'reviewed'   => ['label'=>'Đã đánh giá', 'class'=>'badge-reviewed'],
    'cancelled'  => ['label'=>'Đã hủy',      'class'=>'badge-cancelled'],
];
$tabLabels = [
    '' => 'Tất cả',
    'pending'    => 'Chờ xác nhận',
    'processing' => 'Đang xử lý',
    'shipping'   => 'Đang giao',
    'delivered'  => 'Đã giao',
    'reviewed'   => 'Đã đánh giá',
    'cancelled'  => 'Đã hủy',
];
$payLabels = ['cod'=>'COD','momo'=>'MoMo','bank'=>'Thẻ'];

$extraHead = '<style>
.action-wrapper {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  gap: 10px;
}
.action-btn {
  width: 34px;
  height: 34px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 6px;
  transition: all 0.2s ease;
  border: none;
  cursor: pointer;
  flex-shrink: 0;
  padding: 0;
}
/* Nút Xem - Mộc Trà Style */
.btn-view {
  background-color: #f0f0f0;
  color: #666;
}
.btn-view:hover {
  background-color: #e2e2e2;
  color: #333;
}
/* Nút Xóa/Hủy - Mộc Trà Style */
.btn-cancel {
  background-color: #ffebee;
  color: #c62828;
}
.btn-cancel:hover {
  background-color: #ffcdd2;
  color: #b71c1c;
}
.action-btn svg {
  width: 18px;
  height: 18px;
}
/* Placeholder để giữ hàng lối */
.spacer {
  width: 34px;
  height: 34px;
  flex-shrink: 0;
}
/* Tránh lỗi cache CSS */
.badge-reviewed {
  background-color: var(--purple-100, #ede9fe);
  color: var(--purple-700, #6d28d9);
}
/* ── Orders table column layout ── */
table[data-enhance] th:nth-child(1),
table[data-enhance] td:nth-child(1) { width:62px; min-width:62px; padding-left:16px; text-align:center; }
table[data-enhance] th:nth-child(4),
table[data-enhance] td:nth-child(4) { width:70px; text-align:center; }
table[data-enhance] th:nth-child(5),
table[data-enhance] td:nth-child(5) { width:112px; text-align:right; }
table[data-enhance] th:nth-child(6),
table[data-enhance] td:nth-child(6) { width:88px; text-align:center; }
table[data-enhance] th:nth-child(7),
table[data-enhance] td:nth-child(7) { width:116px; text-align:center; }
table[data-enhance] th:nth-child(8),
table[data-enhance] td:nth-child(8) { width:120px; text-align:center; }
table[data-enhance] th:nth-child(9),
table[data-enhance] td:nth-child(9) { width:82px; text-align:center; }
</style>';

function fmtMoney(int $n): string { return number_format($n, 0, ',', '.') . 'đ'; }
function buildOrderUrl(array $ov = []): string {
    global $filterStatus, $q, $page;
    $base = ['status'=>$filterStatus,'q'=>$q,'page'=>$page];
    $merged = array_merge($base, $ov);
    $merged = array_filter($merged, fn($v)=>$v!==''&&$v!==null);
    return 'orders.php?' . http_build_query($merged);
}

include 'includes/header.php';
?>

<?php if ($msg): ?>
<div class="alert alert-success" data-auto-dismiss>
  <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
  <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- Tabs trạng thái -->
<div class="status-tabs">
  <?php foreach ($tabLabels as $key => $label): ?>
    <a href="<?= buildOrderUrl(['status'=>$key,'page'=>1]) ?>"
       class="status-tab <?= $filterStatus === $key ? 'active':'' ?>">
      <?= $label ?>
      <span class="cnt"><?= $sCounts[$key] ?? 0 ?></span>
    </a>
  <?php endforeach; ?>
</div>

<div class="card">
  <!-- Toolbar -->
  <div class="card-header">
    <div class="toolbar-left">
      <form method="GET" action="orders.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
        <div class="search-input-wrap">
          <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" name="q" placeholder="Tìm ID, tên, SĐT..."
                 value="<?= htmlspecialchars($q) ?>">
        </div>
        <button class="btn btn-secondary btn-sm" type="submit">Tìm</button>
        <?php if ($q): ?>
          <a href="<?= buildOrderUrl(['q'=>'','page'=>1]) ?>" class="btn btn-sm btn-secondary">✕ Xóa</a>
        <?php endif; ?>
      </form>
    </div>
    <div class="text-muted">Tìm thấy <strong><?= $total ?></strong> đơn hàng</div>
  </div>

  <!-- Table -->
  <div class="card-body p0">
    <?php if (empty($orders)): ?>
      <div class="empty-state">
        <svg viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="2"/></svg>
        <h3>Không có đơn hàng</h3>
        <p>Thử thay đổi bộ lọc hoặc tìm kiếm.</p>
      </div>
    <?php else: ?>
    <div class="table-wrap">
      <table data-enhance>
        <thead>
          <tr>
            <th data-no-enhance>#ID</th>
            <th data-no-enhance>Khách hàng</th>
            <th data-no-enhance>Số điện thoại</th>
            <th data-no-enhance>Số SP</th>
            <th>Tổng tiền</th>
            <th data-no-enhance>Thanh toán</th>
            <th>Trạng thái</th>
            <th>Ngày đặt</th>
            <th>Thao tác</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $o):
            $sc = $statusCfg[$o['status']] ?? ['label'=>$o['status'],'class'=>'badge-gray'];
          ?>
          <tr>
            <td><a href="order_detail.php?id=<?= $o['id'] ?>" class="text-green fw700">#<?= $o['id'] ?></a></td>
            <td class="td-name"><?= htmlspecialchars($o['full_name']) ?></td>
            <td><?= htmlspecialchars($o['phone']) ?></td>
            <td class="text-muted"><?= (int)$o['item_count'] ?> sp</td>
            <td style="color:var(--red-700);font-weight:700"><?= fmtMoney((int)$o['total']) ?></td>
            <td><span class="badge badge-gray"><?= $payLabels[$o['payment_method']] ?? $o['payment_method'] ?></span></td>
            <td><span class="badge <?= $sc['class'] ?>"><?= $sc['label'] ?></span></td>
            <td class="text-muted"><?= date('d/m/Y H:i', strtotime($o['created_at'])) ?></td>
            <td>
              <div class="action-wrapper">
                <a href="order_detail.php?id=<?= $o['id'] ?>"
                   class="action-btn btn-view" title="Xem chi tiết">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </a>
                <?php if ($o['status'] !== 'delivered' && $o['status'] !== 'reviewed' && $o['status'] !== 'cancelled'): ?>
                <button class="action-btn btn-cancel" title="Hủy đơn"
                  onclick="openModal({
                    title:'Hủy đơn hàng #<?= $o['id'] ?>?',
                    desc:'Đơn hàng sẽ bị hủy và không thể khôi phục.',
                    id:'<?= $o['id'] ?>',action:'cancelled',
                    url:'order_action.php',btnText:'Hủy đơn',btnClass:'btn-danger'
                  })">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
                <?php else: ?>
                <div class="spacer"></div>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <a href="<?= buildOrderUrl(['page'=>$page-1]) ?>" class="page-btn <?= $page<=1?'disabled':'' ?>">‹</a>
      <?php for ($i=1;$i<=$totalPages;$i++): if($i===1||$i===$totalPages||abs($i-$page)<=2): ?>
        <a href="<?= buildOrderUrl(['page'=>$i]) ?>" class="page-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
      <?php elseif(abs($i-$page)===3): ?><span class="page-btn disabled" style="border:none">…</span>
      <?php endif; endfor; ?>
      <a href="<?= buildOrderUrl(['page'=>$page+1]) ?>" class="page-btn <?= $page>=$totalPages?'disabled':'' ?>">›</a>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
