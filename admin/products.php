<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

$pageTitle  = 'Quản lý sản phẩm';
$activePage = 'products';
$breadcrumb = [['label'=>'Sản phẩm']];

/* ── Params ── */
$q       = trim($_GET['q'] ?? '');
$catId   = (int)($_GET['cat'] ?? 0);
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

/* ── Alert ── */
$msg = $msgType = '';
if (!empty($_GET['added']))   { $msg = 'Thêm sản phẩm thành công!'; $msgType = 'success'; }
if (!empty($_GET['updated'])) { $msg = 'Cập nhật sản phẩm thành công!'; $msgType = 'success'; }
if (!empty($_GET['deleted'])) { $msg = 'Đã xóa sản phẩm.'; $msgType = 'info'; }

/* ── Categories ── */
$cats = $conn->query("SELECT * FROM categories ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);

/* ── WHERE ── */
$where  = ['1=1'];
$params = [];
$types  = '';
if ($q) {
    $where[]  = 'p.name LIKE ?';
    $params[] = "%$q%";
    $types   .= 's';
}
if ($catId) {
    $where[]  = 'p.category_id = ?';
    $params[] = $catId;
    $types   .= 'i';
}
$whereSql = implode(' AND ', $where);

/* ── Count ── */
$countSql = "SELECT COUNT(*) FROM products p WHERE $whereSql";
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
$sql = "SELECT p.*, c.name as cat_name
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE $whereSql
        ORDER BY p.id DESC
        LIMIT $perPage OFFSET $offset";
if ($params) {
    $st = $conn->prepare($sql);
    $st->bind_param($types, ...$params);
    $st->execute();
    $products = $st->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $products = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

function fmtMoney(int $n): string { return number_format($n, 0, ',', '.') . 'đ'; }
function buildProdUrl(array $ov = []): string {
    global $q, $catId, $page;
    $b = ['q'=>$q,'cat'=>$catId?$catId:'','page'=>$page];
    $m = array_filter(array_merge($b, $ov), fn($v)=>$v!==''&&$v!==null&&$v!==0);
    return 'products.php?' . http_build_query($m);
}
$typeLabels = ['la'=>'Trà lá','tui_loc'=>'Túi lọc','bot'=>'Bột','hop_qua'=>'Hộp quà'];

$extraHead = '<style>
/* ── Products table column layout ── */
table[data-enhance] th:nth-child(1),
table[data-enhance] td:nth-child(1) { width:56px; min-width:56px; text-align:center; padding-left:16px; }
table[data-enhance] th:nth-child(3),
table[data-enhance] td:nth-child(3) { width:120px; }
table[data-enhance] th:nth-child(4),
table[data-enhance] td:nth-child(4) { width:110px; text-align:right; }
table[data-enhance] th:nth-child(5),
table[data-enhance] td:nth-child(5) { width:100px; text-align:right; }
table[data-enhance] th:nth-child(6),
table[data-enhance] td:nth-child(6) { width:100px; text-align:right; }
table[data-enhance] th:nth-child(7),
table[data-enhance] td:nth-child(7) { width:80px; text-align:center; }
table[data-enhance] th:nth-child(8),
table[data-enhance] td:nth-child(8) { width:88px; text-align:center; }
table[data-enhance] th:nth-child(9),
table[data-enhance] td:nth-child(9) { width:108px; text-align:center; }
table[data-enhance] th:nth-child(10),
table[data-enhance] td:nth-child(10) { width:88px; text-align:center; }
</style>';

include 'includes/header.php';
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>" data-auto-dismiss>
  <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
  <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<div class="toolbar">
  <div class="toolbar-left">
    <form method="GET" action="products.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <div class="search-input-wrap">
        <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" name="q" placeholder="Tìm tên sản phẩm..."
               value="<?= htmlspecialchars($q) ?>">
      </div>
      <select name="cat" class="filter-select">
        <option value="">Tất cả danh mục</option>
        <?php foreach ($cats as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $catId===$c['id']?'selected':'' ?>>
            <?= htmlspecialchars($c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-secondary btn-sm" type="submit">Lọc</button>
      <?php if ($q || $catId): ?>
        <a href="products.php" class="btn btn-sm btn-secondary">✕ Xóa lọc</a>
      <?php endif; ?>
    </form>
  </div>
  <div class="toolbar-right">
    <a href="product_form.php" class="btn btn-primary">
      <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Thêm sản phẩm
    </a>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <div class="card-title">Tất cả sản phẩm</div>
    <span class="text-muted">Tổng: <strong><?= $total ?></strong> sản phẩm</span>
  </div>
  <div class="card-body p0">
    <?php if (empty($products)): ?>
      <div class="empty-state">
        <svg viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
        <h3>Không tìm thấy sản phẩm</h3>
        <p>Thử thay đổi bộ lọc hoặc <a href="product_form.php" class="text-green">thêm sản phẩm mới</a>.</p>
      </div>
    <?php else: ?>
    <div class="table-wrap">
      <table data-enhance>
        <thead>
          <tr>
            <th data-no-enhance>#</th>
            <th data-no-enhance>Sản phẩm</th>
            <th data-no-enhance>Danh mục</th>
            <th>Giá bán</th>
            <th>Giá vốn</th>
            <th data-no-enhance>Giá cũ</th>
            <th data-no-enhance>Dạng</th>
            <th data-no-enhance>Xuất xứ</th>
            <th>Trạng thái</th>
            <th>Thao tác</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($products as $p): ?>
          <tr>
            <td class="text-muted"><?= $p['id'] ?></td>
            <td>
              <div class="product-thumb">
                <img src="../images/<?= htmlspecialchars($p['image'] ?? '') ?>"
                     onerror="this.onerror=null;this.src='../images/logo.png'"
                     alt="<?= htmlspecialchars($p['name']) ?>">
                <div>
                  <div class="td-name"><?= htmlspecialchars($p['name']) ?></div>
                  <?php if ($p['weight']): ?>
                    <div class="text-muted"><?= htmlspecialchars($p['weight']) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td><?= htmlspecialchars($p['cat_name'] ?? '—') ?></td>
            <td style="color:var(--red-700);font-weight:700"><?= fmtMoney((int)$p['price']) ?></td>
            <td class="text-muted"><?= $p['cost_price'] ? fmtMoney((int)$p['cost_price']) : '—' ?></td>
            <td class="text-muted">
              <?= $p['price_old'] ? '<s>' . fmtMoney((int)$p['price_old']) . '</s>' : '—' ?>
            </td>
            <td><?= $typeLabels[$p['type']] ?? ($p['type'] ? htmlspecialchars($p['type']) : '—') ?></td>
            <td class="text-muted"><?= htmlspecialchars($p['origin'] ?? '—') ?></td>
            <td>
              <?php if ($p['price_old']): ?><span class="badge badge-sale">Sale</span><?php endif; ?>
              <?php if ($p['is_featured']): ?><span class="badge badge-featured">Nổi bật</span><?php endif; ?>
              <?php if ($p['is_new']): ?><span class="badge badge-new">Mới</span><?php endif; ?>
              <?php if (!$p['price_old'] && !$p['is_featured'] && !$p['is_new']): ?>
                <span class="badge badge-active">Đang bán</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="flex-center">
                <a href="../product_detail.php?id=<?= $p['id'] ?>" target="_blank"
                   class="btn btn-sm btn-secondary" title="Xem">
                  <i class="fa-solid fa-eye" style="margin-right:0"></i>
                </a>
                <a href="product_form.php?id=<?= $p['id'] ?>"
                   class="btn btn-sm btn-amber btn-edit" title="Sửa">
                  <i class="fa-solid fa-pen-to-square" style="margin-right:0"></i>
                </a>
                <button class="btn btn-sm btn-danger btn-delete" title="Xóa"
                  onclick="openModal({
                    title:'Xóa sản phẩm?',
                    desc:'Sản phẩm &quot;<?= addslashes(htmlspecialchars($p['name'])) ?>&quot; sẽ bị xóa vĩnh viễn.',
                    id:'<?= $p['id'] ?>',action:'delete',
                    url:'product_delete.php',btnText:'Xóa',btnClass:'btn-danger'
                  })">
                  <i class="fa-solid fa-trash" style="margin-right:0"></i>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <a href="<?= buildProdUrl(['page'=>$page-1]) ?>" class="page-btn <?= $page<=1?'disabled':'' ?>">‹</a>
      <?php for($i=1;$i<=$totalPages;$i++): if($i===1||$i===$totalPages||abs($i-$page)<=2): ?>
        <a href="<?= buildProdUrl(['page'=>$i]) ?>" class="page-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
      <?php elseif(abs($i-$page)===3): ?><span class="page-btn disabled" style="border:none">…</span>
      <?php endif; endfor; ?>
      <a href="<?= buildProdUrl(['page'=>$page+1]) ?>" class="page-btn <?= $page>=$totalPages?'disabled':'' ?>">›</a>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
