<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

$pageTitle  = 'Quản lý khách hàng';
$activePage = 'users';
$breadcrumb = [['label'=>'Khách hàng']];

/* ── Toggle lock/unlock ── */
if (!empty($_GET['toggle'])) {
    $uid = (int)$_GET['toggle'];
    /* Không cho khóa chính mình */
    if ($uid !== (int)$_SESSION['user_id']) {
        $stLk = $conn->prepare("SELECT is_locked FROM users WHERE id=? LIMIT 1");
        $stLk->bind_param('i', $uid);
        $stLk->execute();
        $r = $stLk->get_result()->fetch_assoc();
        if ($r) {
            $newLock = $r['is_locked'] ? 0 : 1;
            $stUpd = $conn->prepare("UPDATE users SET is_locked=?, failed_attempts=0 WHERE id=?");
            $stUpd->bind_param('ii', $newLock, $uid);
            $stUpd->execute();
        }
    }
    header('Location: users.php?' . http_build_query(array_filter([
        'q' => $_GET['q'] ?? '',
        'status' => $_GET['status'] ?? '',
        'page'   => $_GET['page'] ?? 1,
        'toggled' => 1,
    ])));
    exit();
}

/* ── Params ── */
$q            = trim($_GET['q'] ?? '');
$filterStatus = $_GET['status'] ?? ''; // '' all, 'active', 'locked'
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;
$showMsg      = !empty($_GET['toggled']);

/* ── WHERE ── */
$where  = ["role = 'customer'"];
$params = [];
$types  = '';

if ($q) {
    $like     = "%$q%";
    $where[]  = '(username LIKE ? OR email LIKE ? OR phone LIKE ?)';
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= 'sss';
}
if ($filterStatus === 'active')  { $where[] = 'is_locked = 0'; }
if ($filterStatus === 'locked')  { $where[] = 'is_locked = 1'; }
$whereSql = implode(' AND ', $where);

/* ── Count ── */
$countSql = "SELECT COUNT(*) FROM users WHERE $whereSql";
if ($params) {
    $st = $conn->prepare($countSql); $st->bind_param($types, ...$params); $st->execute();
    $total = (int)$st->get_result()->fetch_row()[0];
} else {
    $total = (int)$conn->query($countSql)->fetch_row()[0];
}

$totalPages = max(1, ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

/* ── Fetch ── */
$sql = "SELECT u.id, u.username, u.email, u.phone, u.is_locked, u.failed_attempts, u.created_at,
               COUNT(o.id) as order_count,
               COALESCE(SUM(CASE WHEN o.status != 'cancelled' THEN o.total ELSE 0 END), 0) as total_spent
        FROM users u
        LEFT JOIN orders o ON o.user_id = u.id
        WHERE $whereSql
        GROUP BY u.id
        ORDER BY u.created_at DESC
        LIMIT $perPage OFFSET $offset";
if ($params) {
    $st = $conn->prepare($sql); $st->bind_param($types, ...$params); $st->execute();
    $users = $st->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $users = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

/* ── Summary counts ── */
$totalCustomers = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetch_row()[0];
$lockedCount    = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role='customer' AND is_locked=1")->fetch_row()[0];
$activeCount    = $totalCustomers - $lockedCount;

function fmtMoney(int $n): string { return number_format($n, 0, ',', '.') . 'đ'; }
function buildUserUrl(array $ov = []): string {
    global $q, $filterStatus, $page;
    $b = ['q'=>$q,'status'=>$filterStatus,'page'=>$page];
    $m = array_filter(array_merge($b, $ov), fn($v)=>$v!==''&&$v!==null);
    return 'users.php?' . http_build_query($m);
}

$extraHead = '<style>
/* ── Users table column layout ── */
table[data-enhance] th:nth-child(1),
table[data-enhance] td:nth-child(1) { width:56px; min-width:56px; text-align:center; padding-left:16px; }
table[data-enhance] th:nth-child(5),
table[data-enhance] td:nth-child(5) { width:90px; text-align:center; }
table[data-enhance] th:nth-child(6),
table[data-enhance] td:nth-child(6) { width:110px; text-align:right; }
table[data-enhance] th:nth-child(7),
table[data-enhance] td:nth-child(7) { width:108px; text-align:center; }
table[data-enhance] th:nth-child(8),
table[data-enhance] td:nth-child(8) { width:108px; text-align:center; }
table[data-enhance] th:nth-child(9),
table[data-enhance] td:nth-child(9) { width:110px; text-align:center; }
</style>';

include 'includes/header.php';
?>

<?php if ($showMsg): ?>
<div class="alert alert-success" data-auto-dismiss>
  <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
  Đã cập nhật trạng thái khách hàng.
</div>
<?php endif; ?>

<!-- Stats nhỏ -->
<div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px">
  <div class="stat-card">
    <div class="stat-icon purple">
      <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-label">Tổng khách hàng</div>
      <div class="stat-value"><?= $totalCustomers ?></div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green">
      <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-label">Đang hoạt động</div>
      <div class="stat-value"><?= $activeCount ?></div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon red">
      <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-label">Bị khóa</div>
      <div class="stat-value"><?= $lockedCount ?></div>
    </div>
  </div>
</div>

<!-- Tabs + toolbar -->
<div class="status-tabs">
  <a href="<?= buildUserUrl(['status'=>'','page'=>1]) ?>"
     class="status-tab <?= $filterStatus==='' ? 'active':'' ?>">
    Tất cả <span class="cnt"><?= $totalCustomers ?></span>
  </a>
  <a href="<?= buildUserUrl(['status'=>'active','page'=>1]) ?>"
     class="status-tab <?= $filterStatus==='active' ? 'active':'' ?>">
    Hoạt động <span class="cnt"><?= $activeCount ?></span>
  </a>
  <a href="<?= buildUserUrl(['status'=>'locked','page'=>1]) ?>"
     class="status-tab <?= $filterStatus==='locked' ? 'active':'' ?>">
    Bị khóa <span class="cnt"><?= $lockedCount ?></span>
  </a>
</div>

<div class="card">
  <div class="card-header">
    <form method="GET" action="users.php" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
      <div class="search-input-wrap">
        <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" name="q" placeholder="Tìm username, email, SĐT..."
               value="<?= htmlspecialchars($q) ?>">
      </div>
      <button class="btn btn-secondary btn-sm" type="submit">Tìm</button>
      <?php if ($q): ?>
        <a href="<?= buildUserUrl(['q'=>'','page'=>1]) ?>" class="btn btn-sm btn-secondary">✕</a>
      <?php endif; ?>
    </form>
    <div class="text-muted">Tổng: <strong><?= $total ?></strong></div>
  </div>

  <div class="card-body p0">
    <?php if (empty($users)): ?>
      <div class="empty-state">
        <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        <h3>Không tìm thấy khách hàng</h3>
      </div>
    <?php else: ?>
    <div class="table-wrap">
      <table data-enhance>
        <thead>
          <tr>
            <th data-no-enhance>#</th>
            <th data-no-enhance>Khách hàng</th>
            <th data-no-enhance>Email</th>
            <th data-no-enhance>Số điện thoại</th>
            <th>Đơn hàng</th>
            <th>Tổng chi</th>
            <th>Ngày đăng ký</th>
            <th>Trạng thái</th>
            <th>Thao tác</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
          <tr>
            <td class="text-muted"><?= $u['id'] ?></td>
            <td>
              <div style="display:flex;align-items:center;gap:10px">
                <div style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--green-700),var(--green-800));color:#fff;font-weight:700;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0">
                  <?= strtoupper(substr($u['username'],0,1)) ?>
                </div>
                <div class="td-name"><?= htmlspecialchars($u['username']) ?></div>
              </div>
            </td>
            <td class="text-muted"><?= htmlspecialchars($u['email'] ?? '—') ?></td>
            <td><?= htmlspecialchars($u['phone'] ?? '—') ?></td>
            <td>
              <a href="orders.php?q=<?= urlencode($u['username']) ?>" class="text-green fw700">
                <?= (int)$u['order_count'] ?>
              </a>
              <span class="text-muted"> đơn</span>
            </td>
            <td style="color:var(--red-700);font-weight:700">
              <?= fmtMoney((int)$u['total_spent']) ?>
            </td>
            <td class="text-muted"><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
            <td>
              <?php if ($u['is_locked']): ?>
                <span class="badge badge-locked">Bị khóa</span>
                <?php if ($u['failed_attempts']): ?>
                  <div class="text-muted" style="font-size:11px;margin-top:2px"><?= $u['failed_attempts'] ?> lần sai</div>
                <?php endif; ?>
              <?php else: ?>
                <span class="badge badge-active">Hoạt động</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($u['id'] != $_SESSION['user_id']): ?>
              <a href="<?= buildUserUrl(['toggle'=>$u['id']]) ?>"
                 class="btn btn-sm <?= $u['is_locked'] ? 'btn-primary' : 'btn-danger' ?>"
                 onclick="return confirm('<?= $u['is_locked'] ? 'Mở khóa' : 'Khóa' ?> tài khoản &quot;<?= addslashes($u['username']) ?>&quot;?')">
                <?php if ($u['is_locked']): ?>
                  <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>
                  Mở khóa
                <?php else: ?>
                  <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                  Khóa
                <?php endif; ?>
              </a>
              <?php else: ?>
                <span class="text-muted" style="font-size:12px">Tài khoản của bạn</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <a href="<?= buildUserUrl(['page'=>$page-1]) ?>" class="page-btn <?= $page<=1?'disabled':'' ?>">‹</a>
      <?php for($i=1;$i<=$totalPages;$i++): if($i===1||$i===$totalPages||abs($i-$page)<=2): ?>
        <a href="<?= buildUserUrl(['page'=>$i]) ?>" class="page-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
      <?php elseif(abs($i-$page)===3): ?><span class="page-btn disabled" style="border:none">…</span>
      <?php endif; endfor; ?>
      <a href="<?= buildUserUrl(['page'=>$page+1]) ?>" class="page-btn <?= $page>=$totalPages?'disabled':'' ?>">›</a>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
