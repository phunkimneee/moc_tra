<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

$pageTitle  = 'Đánh giá sản phẩm';
$activePage = 'reviews';
$breadcrumb = [['label' => 'Đánh giá']];

/* ── Xử lý xoá đánh giá ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['_confirm_action'] ?? '') === 'delete_review'
) {
    if (!empty($_POST['_csrf']) && hash_equals($csrfToken, $_POST['_csrf'])) {
        $delId = (int)($_POST['_confirm_id'] ?? 0);
        if ($delId > 0) {
            $st = $conn->prepare("DELETE FROM product_reviews WHERE id=?");
            $st->bind_param('i', $delId);
            $st->execute();
        }
    }
    header('Location: reviews.php?deleted=1');
    exit();
}

/* ── Bộ lọc ── */
$filterProduct = (int)($_GET['product_id'] ?? 0);
$filterRating  = (int)($_GET['rating']     ?? 0);
$page          = max(1, (int)($_GET['page'] ?? 1));
$perPage       = 20;
$offset        = ($page - 1) * $perPage;

/* ── Thống kê tổng quan ── */
$stats = $conn->query(
    "SELECT
        COUNT(*)                       AS total,
        ROUND(AVG(rating), 1)          AS avg_rating,
        SUM(rating = 5)                AS five,
        SUM(rating = 4)                AS four,
        SUM(rating = 3)                AS three,
        SUM(rating <= 2)               AS low
     FROM product_reviews"
)->fetch_assoc();

/* ── Danh sách sản phẩm để lọc ── */
$productList = $conn->query(
    "SELECT p.id, p.name,
            COUNT(r.id)         AS review_count,
            ROUND(AVG(r.rating),1) AS avg_rating
     FROM products p
     LEFT JOIN product_reviews r ON r.product_id = p.id
     GROUP BY p.id
     ORDER BY review_count DESC"
)->fetch_all(MYSQLI_ASSOC);

/* ── Tổng số bản ghi (để phân trang) ── */
$whereClause = '';
$whereParams = [];
$whereTypes  = '';
if ($filterProduct > 0) {
    $whereClause   .= ' AND r.product_id = ?';
    $whereParams[]  = $filterProduct;
    $whereTypes    .= 'i';
}
if ($filterRating > 0) {
    $whereClause   .= ' AND r.rating = ?';
    $whereParams[]  = $filterRating;
    $whereTypes    .= 'i';
}

$countSt = $conn->prepare(
    "SELECT COUNT(*) FROM product_reviews r WHERE 1=1" . $whereClause
);
if ($whereTypes) $countSt->bind_param($whereTypes, ...$whereParams);
$countSt->execute();
$totalRows  = (int)$countSt->get_result()->fetch_row()[0];
$totalPages = max(1, (int)ceil($totalRows / $perPage));

/* ── Lấy danh sách đánh giá ── */
$listSt = $conn->prepare(
    "SELECT r.id, r.rating, r.comment, r.created_at,
            u.username,
            p.name  AS product_name,
            p.image AS product_image,
            o.id    AS order_id
     FROM product_reviews r
     JOIN users    u ON u.id = r.user_id
     JOIN products p ON p.id = r.product_id
     LEFT JOIN orders o ON o.id = r.order_id
     WHERE 1=1" . $whereClause . "
     ORDER BY r.created_at DESC
     LIMIT ? OFFSET ?"
);
$listTypes  = $whereTypes . 'ii';
$listParams = array_merge($whereParams, [$perPage, $offset]);
$listSt->bind_param($listTypes, ...$listParams);
$listSt->execute();
$reviews = $listSt->get_result()->fetch_all(MYSQLI_ASSOC);

function starHtml(int $n): string {
    $out = '';
    for ($i = 1; $i <= 5; $i++) {
        $out .= $i <= $n
            ? '<svg class="star filled" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>'
            : '<svg class="star" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
    }
    return $out;
}

include 'includes/header.php';
?>

<style>
/* ── Stat strip ── */
.rev-stats {
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(140px,1fr));
  gap:14px;
  margin-bottom:24px;
}
.rev-stat-card {
  background:#fff;
  border-radius:10px;
  border:1px solid var(--gray-200);
  padding:16px 18px;
  display:flex;
  align-items:center;
  gap:14px;
}
.rev-stat-icon {
  width:42px;height:42px;border-radius:10px;
  display:flex;align-items:center;justify-content:center;
  flex-shrink:0;
}
.rev-stat-icon svg { width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round; }
.rev-stat-val { font-size:22px;font-weight:700;line-height:1; }
.rev-stat-lbl { font-size:11px;color:var(--gray-500);margin-top:3px; }

/* ── Rating bar ── */
.rating-bars { display:flex;flex-direction:column;gap:6px; }
.rating-bar-row { display:flex;align-items:center;gap:8px;font-size:12px; }
.rating-bar-row .lbl { width:28px;text-align:right;font-weight:600; }
.rating-bar-track { flex:1;height:7px;background:var(--gray-100);border-radius:99px;overflow:hidden; }
.rating-bar-fill  { height:100%;border-radius:99px;background:#f59e0b;transition:width .5s; }
.rating-bar-row .cnt { width:24px;color:var(--gray-500); }

/* ── Stars ── */
.stars { display:inline-flex;gap:2px; }
.star { width:14px;height:14px;stroke:#d1d5db;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round; }
.star.filled { stroke:#f59e0b;fill:#f59e0b; }

/* ── Review table ── */
.rev-comment { max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.product-thumb-sm { display:flex;align-items:center;gap:8px; }
.product-thumb-sm img { width:34px;height:34px;border-radius:6px;object-fit:cover;border:1px solid var(--gray-200); }
.filter-bar { display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;align-items:center; }
.filter-bar select, .filter-bar input { height:34px;padding:0 10px;border:1px solid var(--gray-200);border-radius:6px;font-size:13px; }
.filter-bar .btn { height:34px;padding:0 14px; }
.pagination { display:flex;gap:6px;justify-content:center;padding:16px 0 4px; }
.page-btn { width:32px;height:32px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;border:1px solid var(--gray-200);background:#fff;cursor:pointer;text-decoration:none;color:var(--gray-700); }
.page-btn.active, .page-btn:hover { background:var(--green-700);color:#fff;border-color:var(--green-700); }
.page-btn.disabled { opacity:.4;pointer-events:none; }
</style>

<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success" data-auto-dismiss style="margin-bottom:18px">
  <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
  Đã xoá đánh giá.
</div>
<?php endif; ?>

<!-- ── STAT STRIP ── -->
<div class="rev-stats">

  <div class="rev-stat-card">
    <div class="rev-stat-icon" style="background:#fef9c3;color:#ca8a04">
      <svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
    </div>
    <div>
      <div class="rev-stat-val"><?= number_format((float)($stats['avg_rating'] ?? 0), 1) ?></div>
      <div class="rev-stat-lbl">Điểm TB / 5</div>
    </div>
  </div>

  <div class="rev-stat-card">
    <div class="rev-stat-icon" style="background:#dcfce7;color:#16a34a">
      <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
    </div>
    <div>
      <div class="rev-stat-val"><?= number_format((int)($stats['total'] ?? 0)) ?></div>
      <div class="rev-stat-lbl">Tổng đánh giá</div>
    </div>
  </div>

  <div class="rev-stat-card">
    <div class="rev-stat-icon" style="background:#fef3c7;color:#d97706">
      <svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
    </div>
    <div>
      <div class="rev-stat-val"><?= (int)($stats['five'] ?? 0) ?></div>
      <div class="rev-stat-lbl">Đánh giá 5 ★</div>
    </div>
  </div>

  <div class="rev-stat-card">
    <div class="rev-stat-icon" style="background:#fee2e2;color:#dc2626">
      <svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
    </div>
    <div>
      <div class="rev-stat-val"><?= (int)($stats['low'] ?? 0) ?></div>
      <div class="rev-stat-lbl">Đánh giá 1–2 ★</div>
    </div>
  </div>

  <!-- Rating distribution -->
  <div class="rev-stat-card" style="grid-column:span 2">
    <div class="rating-bars" style="flex:1">
      <?php
      $distMap = [5=>'five',4=>'four',3=>'three',2=>'low',1=>'low'];
      $barData = [
          5 => (int)($stats['five']  ?? 0),
          4 => (int)($stats['four']  ?? 0),
          3 => (int)($stats['three'] ?? 0),
          2 => (int)($stats['low']   ?? 0),
      ];
      // Separate query for 1★ and 2★
      $oneTwo = $conn->query("SELECT rating, COUNT(*) as c FROM product_reviews WHERE rating <= 2 GROUP BY rating")->fetch_all(MYSQLI_ASSOC);
      $barData[1] = 0; $barData[2] = 0;
      foreach ($oneTwo as $r) $barData[(int)$r['rating']] = (int)$r['c'];
      $barData[3] = (int)($stats['three'] ?? 0);

      $totalForBar = max(1, (int)($stats['total'] ?? 1));
      foreach ([5,4,3,2,1] as $star):
          $cnt = $barData[$star];
          $pct = round($cnt / $totalForBar * 100);
      ?>
      <div class="rating-bar-row">
        <span class="lbl"><?= $star ?>★</span>
        <div class="rating-bar-track"><div class="rating-bar-fill" style="width:<?= $pct ?>%"></div></div>
        <span class="cnt"><?= $cnt ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<!-- ── FILTER BAR ── -->
<form method="GET" class="filter-bar">
  <select name="product_id">
    <option value="">-- Tất cả sản phẩm --</option>
    <?php foreach ($productList as $pl): ?>
      <option value="<?= $pl['id'] ?>" <?= $filterProduct === (int)$pl['id'] ? 'selected' : '' ?>>
        <?= htmlspecialchars($pl['name']) ?> (<?= $pl['review_count'] ?>)
      </option>
    <?php endforeach; ?>
  </select>
  <select name="rating">
    <option value="">-- Tất cả sao --</option>
    <?php for ($r = 5; $r >= 1; $r--): ?>
      <option value="<?= $r ?>" <?= $filterRating === $r ? 'selected' : '' ?>><?= $r ?> sao</option>
    <?php endfor; ?>
  </select>
  <button type="submit" class="btn btn-primary">Lọc</button>
  <?php if ($filterProduct || $filterRating): ?>
    <a href="reviews.php" class="btn btn-secondary">Xoá lọc</a>
  <?php endif; ?>
  <span style="margin-left:auto;font-size:13px;color:var(--gray-500)">
    Tổng: <strong><?= $totalRows ?></strong> đánh giá
  </span>
</form>

<!-- ── REVIEWS TABLE ── -->
<div class="card">
  <div class="card-body p0">
    <?php if (empty($reviews)): ?>
      <div class="empty-state">
        <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        <h3>Chưa có đánh giá nào</h3>
        <p>Khi khách hàng đánh giá sản phẩm, dữ liệu sẽ hiện ở đây.</p>
      </div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th style="width:40px">#</th>
            <th>Sản phẩm</th>
            <th>Khách hàng</th>
            <th>Đánh giá</th>
            <th>Nội dung</th>
            <th>Đơn hàng</th>
            <th>Ngày</th>
            <th style="width:60px"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($reviews as $rev): ?>
          <tr>
            <td class="text-muted" style="font-size:12px"><?= $rev['id'] ?></td>
            <td>
              <div class="product-thumb-sm">
                <?php if ($rev['product_image']): ?>
                  <img src="../images/<?= htmlspecialchars($rev['product_image']) ?>"
                       onerror="this.onerror=null;this.src='../images/logo.png'"
                       alt="<?= htmlspecialchars($rev['product_name']) ?>">
                <?php endif; ?>
                <span class="td-name" style="max-width:130px"><?= htmlspecialchars($rev['product_name']) ?></span>
              </div>
            </td>
            <td style="font-weight:600"><?= htmlspecialchars($rev['username']) ?></td>
            <td>
              <div class="stars"><?= starHtml((int)$rev['rating']) ?></div>
              <div style="font-size:11px;color:var(--gray-400)"><?= $rev['rating'] ?>/5</div>
            </td>
            <td>
              <?php if ($rev['comment']): ?>
                <span class="rev-comment" title="<?= htmlspecialchars($rev['comment']) ?>">
                  <?= htmlspecialchars($rev['comment']) ?>
                </span>
              <?php else: ?>
                <span class="text-muted" style="font-size:12px;font-style:italic">Không có nội dung</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($rev['order_id']): ?>
                <a href="order_detail.php?id=<?= $rev['order_id'] ?>" class="text-green fw700">#<?= $rev['order_id'] ?></a>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-muted" style="font-size:12px;white-space:nowrap">
              <?= date('d/m/Y', strtotime($rev['created_at'])) ?><br>
              <span style="color:var(--gray-400)"><?= date('H:i', strtotime($rev['created_at'])) ?></span>
            </td>
            <td>
              <button class="btn btn-sm btn-danger"
                onclick="openModal({
                  title:'Xoá đánh giá?',
                  desc:'Đánh giá của <?= htmlspecialchars(addslashes($rev['username'])) ?> sẽ bị xoá vĩnh viễn.',
                  id:<?= $rev['id'] ?>,
                  action:'delete_review',
                  url:'reviews.php',
                  btnText:'Xoá',btnClass:'btn-danger'
                })">
                <svg viewBox="0 0 24 24" style="width:13px;height:13px"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- ── PAGINATION ── -->
    <?php if ($totalPages > 1):
      $qBase = http_build_query(array_filter([
          'product_id' => $filterProduct ?: null,
          'rating'     => $filterRating  ?: null,
      ]));
      $qBase = $qBase ? '&' . $qBase : '';
    ?>
    <div class="pagination">
      <a href="?page=<?= max(1,$page-1) . $qBase ?>"
         class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">‹</a>
      <?php for ($p = max(1,$page-2); $p <= min($totalPages,$page+2); $p++): ?>
        <a href="?page=<?= $p . $qBase ?>"
           class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
      <?php endfor; ?>
      <a href="?page=<?= min($totalPages,$page+1) . $qBase ?>"
         class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">›</a>
    </div>
    <?php endif; ?>

    <?php endif; ?>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
