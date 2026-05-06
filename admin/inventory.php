<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

$pageTitle  = 'Quản lý tồn kho';
$activePage = 'inventory';
$breadcrumb = [['label' => 'Tồn kho']];

// Tự tạo bảng inventory_history nếu chưa tồn tại
$conn->query("CREATE TABLE IF NOT EXISTS `inventory_history` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`    INT UNSIGNED NOT NULL,
  `admin_id`      INT UNSIGNED DEFAULT NULL,
  `change_amount` INT          NOT NULL DEFAULT 0,
  `old_stock`     INT          NOT NULL DEFAULT 0,
  `new_stock`     INT          NOT NULL DEFAULT 0,
  `note`          VARCHAR(255) NOT NULL DEFAULT '',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── Params ── */
$catId  = (int)($_GET['cat']   ?? 0);
$stockF = trim($_GET['stock']  ?? '');
$logPid = (int)($_GET['log']   ?? 0);   // 0 = xem tất cả log

/* ── Categories ── */
$cats = $conn->query("SELECT * FROM categories ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);

/* ── Build WHERE for products ── */
$where  = ['1=1'];
$params = [];
$types  = '';
if ($catId) {
    $where[]  = 'p.category_id = ?';
    $params[] = $catId;
    $types   .= 'i';
}
if ($stockF === 'out')      { $where[] = 'p.stock = 0'; }
elseif ($stockF === 'low')  { $where[] = 'p.stock > 0 AND p.stock < 10'; }
elseif ($stockF === 'ok')   { $where[] = 'p.stock >= 10'; }
$whereSql = implode(' AND ', $where);

/* ── Fetch products ── */
$sql = "SELECT p.id, p.name, p.stock, p.image, c.name AS cat_name
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE $whereSql
        ORDER BY p.stock ASC, p.name ASC";
if ($params) {
    $st = $conn->prepare($sql);
    $st->bind_param($types, ...$params);
    $st->execute();
    $products = $st->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $products = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

/* ── Summary counts ── */
$statOut = (int)$conn->query("SELECT COUNT(*) FROM products WHERE stock = 0")->fetch_row()[0];
$statLow = (int)$conn->query("SELECT COUNT(*) FROM products WHERE stock > 0 AND stock < 10")->fetch_row()[0];
$statOk  = (int)$conn->query("SELECT COUNT(*) FROM products WHERE stock >= 10")->fetch_row()[0];

/* ── Inventory logs ── */
$logProduct = null;
if ($logPid > 0) {
    $stProd = $conn->prepare("SELECT name FROM products WHERE id = ?");
    $stProd->bind_param('i', $logPid);
    $stProd->execute();
    $logProduct = $stProd->get_result()->fetch_assoc();

    $stLog = $conn->prepare(
        "SELECT ih.*, u.username AS admin_name
         FROM inventory_history ih
         LEFT JOIN users u ON u.id = ih.admin_id
         WHERE ih.product_id = ?
         ORDER BY ih.created_at DESC LIMIT 80"
    );
    $stLog->bind_param('i', $logPid);
    $stLog->execute();
    $logs = $stLog->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $stLog = $conn->query(
        "SELECT ih.*, p.name AS product_name, u.username AS admin_name
         FROM inventory_history ih
         LEFT JOIN products p ON p.id = ih.product_id
         LEFT JOIN users u    ON u.id = ih.admin_id
         ORDER BY ih.created_at DESC LIMIT 40"
    );
    $logs = $stLog->fetch_all(MYSQLI_ASSOC);
}

/* ── Helpers ── */
function buildInvUrl(array $ov = []): string {
    global $catId, $stockF, $logPid;
    $b = ['cat' => $catId ?: '', 'stock' => $stockF, 'log' => $logPid ?: ''];
    $m = array_filter(array_merge($b, $ov), fn($v) => $v !== '' && $v !== null && $v !== 0);
    return 'inventory.php' . ($m ? '?' . http_build_query($m) : '');
}

$extraHead = <<<'CSS'
<style>
.stat-cards { display:flex; gap:16px; margin-bottom:24px; flex-wrap:wrap; }
.stat-card {
    flex:1; min-width:150px;
    background:#fff; border-radius:10px; padding:18px 22px;
    border:2px solid var(--gray-200);
    box-shadow:0 1px 3px rgba(0,0,0,.06);
    text-decoration:none; color:inherit; display:block;
    transition:box-shadow .2s, border-color .2s;
}
.stat-card:hover { box-shadow:0 4px 14px rgba(0,0,0,.1); border-color:var(--green-600); }
.stat-card.active { border-color:var(--green-600); background:#f0fdf4; }
.sc-label { font-size:12px; color:var(--gray-500); margin-bottom:6px; }
.sc-value { font-size:30px; font-weight:700; line-height:1; }
.sc-ok  .sc-value { color:#16a34a; }
.sc-low .sc-value { color:#d97706; }
.sc-out .sc-value { color:#dc2626; }
.sc-all .sc-value { color:var(--gray-700); }

.stock-badge {
    display:inline-flex; align-items:center; gap:4px;
    padding:3px 10px; border-radius:20px; font-size:12px; font-weight:600;
}
.stock-badge.ok  { background:#f0fdf4; color:#16a34a; }
.stock-badge.low { background:#fffbeb; color:#d97706; }
.stock-badge.out { background:#fef2f2; color:#dc2626; }

/* Update stock modal */
#invModal {
    display:none; position:fixed; inset:0;
    background:rgba(0,0,0,.5); z-index:2000;
    align-items:center; justify-content:center;
}
#invModal.open { display:flex; }
#invModal .imodal-box {
    background:#fff; border-radius:12px;
    width:100%; max-width:460px; padding:28px 28px 24px;
    box-shadow:0 8px 32px rgba(0,0,0,.18);
    animation:slideUp .2s ease;
}
@keyframes slideUp { from{transform:translateY(20px);opacity:0} to{transform:translateY(0);opacity:1} }
.imodal-title { font-size:17px; font-weight:700; margin-bottom:16px; color:var(--gray-800); }
.imodal-cur {
    background:#f8fafb; border-radius:8px; padding:12px 16px;
    margin-bottom:18px; font-size:14px; color:var(--gray-600);
}
.imodal-cur strong { font-size:22px; color:var(--green-600); }
.fg { margin-bottom:14px; }
.fg label { display:block; font-size:13px; font-weight:600; color:var(--gray-700); margin-bottom:5px; }
.fg input, .fg select, .fg textarea {
    width:100%; padding:9px 12px; border:1.5px solid var(--gray-300);
    border-radius:7px; font-size:14px; font-family:inherit;
    box-sizing:border-box; outline:none; transition:border-color .2s;
}
.fg input:focus, .fg select:focus, .fg textarea:focus { border-color:var(--green-600); }
.preview-line { font-size:13px; color:var(--gray-500); min-height:20px; margin:4px 0 14px; }
.imodal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:4px; }

.change-pos { color:#16a34a; font-weight:700; }
.change-neg { color:#dc2626; font-weight:700; }

#toastInv {
    position:fixed; bottom:24px; right:24px; z-index:9999;
    background:#fff; border-radius:10px; padding:14px 20px;
    min-width:260px; box-shadow:0 4px 20px rgba(0,0,0,.15);
    border-left:4px solid #16a34a; font-size:14px;
    display:none; transition:opacity .3s;
}
/* ── Inventory main table column layout ── */
table[data-enhance] th:nth-child(1),
table[data-enhance] td:nth-child(1) { width:56px; min-width:56px; text-align:center; padding-left:16px; }
table[data-enhance] th:nth-child(3),
table[data-enhance] td:nth-child(3) { width:120px; }
table[data-enhance] th:nth-child(4),
table[data-enhance] td:nth-child(4) { width:100px; }
table[data-enhance] th:nth-child(5),
table[data-enhance] td:nth-child(5) { width:120px; text-align:center; }
table[data-enhance] th:nth-child(6),
table[data-enhance] td:nth-child(6) { width:170px; text-align:center; }
</style>
CSS;

include 'includes/header.php';
?>

<!-- ── Summary Cards ── -->
<div class="stat-cards">
    <a href="<?= buildInvUrl(['stock'=>'ok','log'=>'']) ?>"
       class="stat-card sc-ok <?= $stockF==='ok'?'active':'' ?>">
        <div class="sc-label"><i class="fa-solid fa-circle-check" style="color:#16a34a"></i> Còn hàng (≥10)</div>
        <div class="sc-value"><?= $statOk ?></div>
    </a>
    <a href="<?= buildInvUrl(['stock'=>'low','log'=>'']) ?>"
       class="stat-card sc-low <?= $stockF==='low'?'active':'' ?>">
        <div class="sc-label"><i class="fa-solid fa-triangle-exclamation" style="color:#d97706"></i> Sắp hết (1–9)</div>
        <div class="sc-value"><?= $statLow ?></div>
    </a>
    <a href="<?= buildInvUrl(['stock'=>'out','log'=>'']) ?>"
       class="stat-card sc-out <?= $stockF==='out'?'active':'' ?>">
        <div class="sc-label"><i class="fa-solid fa-ban" style="color:#dc2626"></i> Hết hàng (0)</div>
        <div class="sc-value"><?= $statOut ?></div>
    </a>
    <a href="<?= buildInvUrl(['stock'=>'','log'=>'']) ?>"
       class="stat-card sc-all <?= !$stockF?'active':'' ?>">
        <div class="sc-label"><i class="fa-solid fa-boxes-stacked"></i> Tổng sản phẩm</div>
        <div class="sc-value"><?= $statOk + $statLow + $statOut ?></div>
    </a>
</div>

<!-- ── Toolbar ── -->
<div class="toolbar">
  <div class="toolbar-left">
    <form method="GET" action="inventory.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <?php if ($logPid): ?>
        <input type="hidden" name="log" value="<?= $logPid ?>">
      <?php endif; ?>
      <select name="cat" class="filter-select" onchange="this.form.submit()">
        <option value="">Tất cả danh mục</option>
        <?php foreach ($cats as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $catId===$c['id']?'selected':'' ?>>
            <?= htmlspecialchars($c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <select name="stock" class="filter-select" onchange="this.form.submit()">
        <option value="">Tất cả trạng thái</option>
        <option value="ok"  <?= $stockF==='ok' ?'selected':'' ?>>Còn hàng (≥10)</option>
        <option value="low" <?= $stockF==='low'?'selected':'' ?>>Sắp hết (1–9)</option>
        <option value="out" <?= $stockF==='out'?'selected':'' ?>>Hết hàng (0)</option>
      </select>
      <?php if ($catId || $stockF): ?>
        <a href="inventory.php<?= $logPid?"?log=$logPid":'' ?>" class="btn btn-sm btn-secondary">✕ Xóa lọc</a>
      <?php endif; ?>
    </form>
  </div>
  <div class="toolbar-right">
    <span class="text-muted" style="font-size:13px">
      Hiển thị <strong><?= count($products) ?></strong> sản phẩm
    </span>
  </div>
</div>

<!-- ── Stock Table ── -->
<div class="card" style="margin-bottom:24px">
  <div class="card-header">
    <div class="card-title"><i class="fa-solid fa-warehouse"></i> Danh sách tồn kho</div>
  </div>
  <div class="card-body p0">
    <?php if (empty($products)): ?>
      <div class="empty-state">
        <i class="fa-solid fa-boxes-stacked" style="font-size:40px;color:var(--gray-300);margin-bottom:12px;display:block"></i>
        <h3>Không có sản phẩm nào phù hợp</h3>
        <p>Thử thay đổi bộ lọc.</p>
      </div>
    <?php else: ?>
    <div class="table-wrap">
      <table data-enhance>
        <thead>
          <tr>
            <th data-no-enhance>#</th>
            <th data-no-enhance>Sản phẩm</th>
            <th data-no-enhance>Danh mục</th>
            <th>Tồn kho</th>
            <th>Trạng thái</th>
            <th>Thao tác</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($products as $p):
            if ($p['stock'] == 0)        { $sc = 'out'; $sl = 'Hết hàng';  $si = 'fa-ban'; }
            elseif ($p['stock'] < 10)    { $sc = 'low'; $sl = 'Sắp hết';   $si = 'fa-triangle-exclamation'; }
            else                          { $sc = 'ok';  $sl = 'Còn hàng';  $si = 'fa-circle-check'; }
          ?>
          <tr id="row-<?= $p['id'] ?>">
            <td class="text-muted"><?= $p['id'] ?></td>
            <td>
              <div style="display:flex;align-items:center;gap:10px">
                <img src="../images/<?= htmlspecialchars($p['image'] ?? '') ?>"
                     onerror="this.onerror=null;this.src='../images/logo.png'"
                     style="width:40px;height:40px;object-fit:cover;border-radius:6px;border:1px solid var(--gray-200)"
                     alt="">
                <span style="font-weight:500"><?= htmlspecialchars($p['name']) ?></span>
              </div>
            </td>
            <td class="text-muted"><?= htmlspecialchars($p['cat_name'] ?? '—') ?></td>
            <td style="text-align:center">
              <span style="font-size:16px;font-weight:700;color:var(--gray-700)" id="snum-<?= $p['id'] ?>">
                <?= $p['stock'] ?>
              </span>
            </td>
            <td style="text-align:center">
              <span class="stock-badge <?= $sc ?>" id="sbadge-<?= $p['id'] ?>">
                <i class="fa-solid <?= $si ?>" style="margin-right:3px;color:inherit;font-size:11px"></i>
                <?= $sl ?>
              </span>
            </td>
            <td style="text-align:center">
              <div style="display:flex;gap:6px;justify-content:center">
                <button class="btn btn-sm btn-primary"
                  onclick="openUpdateModal(
                    <?= $p['id'] ?>,
                    '<?= addslashes(htmlspecialchars($p['name'])) ?>',
                    <?= (int)$p['stock'] ?>
                  )">
                  <i class="fa-solid fa-pen-to-square" style="margin-right:4px"></i>Cập nhật
                </button>
                <a href="<?= buildInvUrl(['log' => $p['id']]) ?>#logsSection"
                   class="btn btn-sm btn-secondary" title="Xem lịch sử biến động kho"
                   style="padding:0 10px">
                  <i class="fa-solid fa-clock-rotate-left" style="margin-right:0"></i>
                </a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── Inventory Logs ── -->
<div class="card" id="logsSection">
  <div class="card-header">
    <div class="card-title">
      <i class="fa-solid fa-clock-rotate-left"></i>
      <?php if ($logProduct): ?>
        Lịch sử biến động: <strong style="color:var(--green-600)"><?= htmlspecialchars($logProduct['name']) ?></strong>
        <a href="inventory.php<?= ($catId||$stockF)?'?'.http_build_query(array_filter(['cat'=>$catId?:null,'stock'=>$stockF?:null])):'' ?>"
           style="font-size:12px;font-weight:normal;margin-left:12px;color:var(--green-600)">
          ← Xem tất cả
        </a>
      <?php else: ?>
        Lịch sử biến động gần đây
      <?php endif; ?>
    </div>
    <span class="text-muted" style="font-size:13px"><?= count($logs) ?> bản ghi</span>
  </div>
  <div class="card-body p0">
    <?php if (empty($logs)): ?>
      <div class="empty-state" style="padding:40px 0">
        <i class="fa-solid fa-inbox" style="font-size:36px;color:var(--gray-300);margin-bottom:12px;display:block"></i>
        <h3>Chưa có lịch sử thay đổi nào</h3>
        <p>Bắt đầu cập nhật kho để xem lịch sử tại đây.</p>
      </div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th style="width:140px">Thời gian</th>
            <?php if (!$logProduct): ?><th>Sản phẩm</th><?php endif; ?>
            <th style="text-align:center;width:80px">Thay đổi</th>
            <th style="text-align:center;width:90px">Tồn trước</th>
            <th style="text-align:center;width:90px">Tồn sau</th>
            <th>Lý do</th>
            <th style="width:110px">Thực hiện bởi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $l): ?>
          <tr>
            <td class="text-muted" style="font-size:13px;white-space:nowrap">
              <?= date('d/m/Y H:i', strtotime($l['created_at'])) ?>
            </td>
            <?php if (!$logProduct): ?>
            <td style="font-weight:500;font-size:13px">
              <?= htmlspecialchars($l['product_name'] ?? '—') ?>
            </td>
            <?php endif; ?>
            <td style="text-align:center">
              <?php if ($l['change_amount'] > 0): ?>
                <span class="change-pos">+<?= $l['change_amount'] ?></span>
              <?php elseif ($l['change_amount'] < 0): ?>
                <span class="change-neg"><?= $l['change_amount'] ?></span>
              <?php else: ?>
                <span class="text-muted">0</span>
              <?php endif; ?>
            </td>
            <td style="text-align:center;color:var(--gray-500)"><?= (int)$l['old_stock'] ?></td>
            <td style="text-align:center;font-weight:700;color:var(--gray-700)"><?= (int)$l['new_stock'] ?></td>
            <td style="font-size:13px"><?= htmlspecialchars($l['note'] ?? '—') ?></td>
            <td class="text-muted" style="font-size:13px"><?= htmlspecialchars($l['admin_name'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── Update Stock Modal ── -->
<div id="invModal" aria-modal="true" role="dialog">
  <div class="imodal-box">
    <div class="imodal-title" id="invModalTitle">Cập nhật tồn kho</div>
    <div class="imodal-cur">
      Tồn kho hiện tại: <strong id="modalCurNum">—</strong>
    </div>
    <form id="updateStockForm" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <input type="hidden" name="product_id" id="modalProductId">

      <div class="fg">
        <label for="changeAmount">
          Số lượng thay đổi
          <span style="font-weight:400;color:var(--gray-400)">(+nhập kho / −xuất kho)</span>
        </label>
        <input type="number" id="changeAmount" name="change_amount"
               placeholder="Ví dụ: 50 hoặc -5" autocomplete="off">
      </div>

      <div class="fg">
        <label for="changeNoteSelect">Lý do thay đổi</label>
        <select id="changeNoteSelect" onchange="handleNoteSelect(this)">
          <option value="">— Chọn lý do —</option>
          <option value="Nhập hàng mới">Nhập hàng mới</option>
          <option value="Kiểm kho định kỳ">Kiểm kho định kỳ</option>
          <option value="Hàng hư hỏng / Loại bỏ">Hàng hư hỏng / Loại bỏ</option>
          <option value="Trả hàng từ khách">Trả hàng từ khách</option>
          <option value="Điều chỉnh thủ công">Điều chỉnh thủ công</option>
          <option value="__other__">Khác (nhập thủ công)…</option>
        </select>
        <input type="text" id="customNote" placeholder="Nhập lý do..."
               style="display:none;margin-top:8px" maxlength="255">
      </div>

      <div class="preview-line" id="previewLine"></div>

      <div class="imodal-actions">
        <button type="button" class="btn btn-secondary" onclick="closeInvModal()">Hủy</button>
        <button type="submit" class="btn btn-primary" id="btnSubmitUpdate">
          <i class="fa-solid fa-floppy-disk"></i> Lưu thay đổi
        </button>
      </div>
    </form>
  </div>
</div>

<div id="toastInv"></div>

<?php
$extraScript = <<<'ENDJS'
<script>
(function () {
    var curStock = 0;
    var curPid   = 0;

    window.openUpdateModal = function (id, name, stock) {
        curStock = stock;
        curPid   = id;
        document.getElementById('invModalTitle').textContent = 'Cập nhật kho: ' + name;
        document.getElementById('modalCurNum').textContent   = stock;
        document.getElementById('modalProductId').value      = id;
        document.getElementById('changeAmount').value        = '';
        document.getElementById('changeNoteSelect').value    = '';
        document.getElementById('customNote').style.display  = 'none';
        document.getElementById('customNote').value          = '';
        document.getElementById('previewLine').innerHTML     = '';
        document.getElementById('invModal').classList.add('open');
        setTimeout(function () { document.getElementById('changeAmount').focus(); }, 80);
    };

    window.closeInvModal = function () {
        document.getElementById('invModal').classList.remove('open');
    };

    document.getElementById('invModal').addEventListener('click', function (e) {
        if (e.target === this) closeInvModal();
    });

    window.handleNoteSelect = function (sel) {
        var c = document.getElementById('customNote');
        if (sel.value === '__other__') { c.style.display = 'block'; c.focus(); }
        else { c.style.display = 'none'; c.value = ''; }
    };

    document.getElementById('changeAmount').addEventListener('input', function () {
        var val = parseInt(this.value, 10);
        var el  = document.getElementById('previewLine');
        if (!isNaN(val) && val !== 0) {
            var ns   = curStock + val;
            var sign = val > 0 ? '+' : '';
            var col  = val > 0 ? '#16a34a' : '#dc2626';
            el.innerHTML = 'Tồn kho sau cập nhật: <strong>' + curStock + '</strong>'
                + ' <span style="color:' + col + '">' + sign + val + '</span>'
                + ' → <strong>' + ns + '</strong>'
                + (ns < 0 ? ' <span style="color:#dc2626">⚠ Vượt quá tồn kho!</span>' : '');
        } else {
            el.innerHTML = '';
        }
    });

    document.getElementById('updateStockForm').addEventListener('submit', function (e) {
        e.preventDefault();

        var changeAmt = parseInt(document.getElementById('changeAmount').value, 10);
        if (isNaN(changeAmt) || changeAmt === 0) {
            toast('Vui lòng nhập số lượng thay đổi khác 0.', 'error'); return;
        }
        if (curStock + changeAmt < 0) {
            toast('Không thể xuất quá tồn kho hiện có (' + curStock + ').', 'error'); return;
        }

        var noteEl  = document.getElementById('changeNoteSelect');
        var noteVal;
        if (noteEl.value === '__other__') {
            noteVal = document.getElementById('customNote').value.trim();
            if (!noteVal) { toast('Vui lòng nhập lý do thay đổi.', 'error'); return; }
        } else {
            noteVal = noteEl.value;
            if (!noteVal) { toast('Vui lòng chọn lý do thay đổi.', 'error'); return; }
        }

        var btn = document.getElementById('btnSubmitUpdate');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Đang lưu...';

        fetch('api/inventory_update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                csrf_token:    document.querySelector('#updateStockForm input[name="csrf_token"]').value,
                product_id:    curPid,
                change_amount: changeAmt,
                note:          noteVal
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Lưu thay đổi';

            if (res.success) {
                closeInvModal();
                var ns = res.new_stock;
                document.getElementById('snum-' + curPid).textContent = ns;
                var badge = document.getElementById('sbadge-' + curPid);
                if (ns === 0) {
                    badge.className = 'stock-badge out';
                    badge.innerHTML = '<i class="fa-solid fa-ban" style="margin-right:3px;color:inherit;font-size:11px"></i> Hết hàng';
                } else if (ns < 10) {
                    badge.className = 'stock-badge low';
                    badge.innerHTML = '<i class="fa-solid fa-triangle-exclamation" style="margin-right:3px;color:inherit;font-size:11px"></i> Sắp hết';
                } else {
                    badge.className = 'stock-badge ok';
                    badge.innerHTML = '<i class="fa-solid fa-circle-check" style="margin-right:3px;color:inherit;font-size:11px"></i> Còn hàng';
                }
                toast(res.message, 'success');
                setTimeout(function () { location.reload(); }, 1400);
            } else {
                toast(res.message || 'Đã xảy ra lỗi.', 'error');
            }
        })
        .catch(function () {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Lưu thay đổi';
            toast('Lỗi kết nối. Vui lòng thử lại.', 'error');
        });
    });

    function toast(msg, type) {
        var t = document.getElementById('toastInv');
        t.textContent = msg;
        t.style.borderLeftColor = (type === 'error') ? '#dc2626' : '#16a34a';
        t.style.display = 'block';
        t.style.opacity = '1';
        setTimeout(function () {
            t.style.opacity = '0';
            setTimeout(function () { t.style.display = 'none'; t.style.opacity = '1'; }, 350);
        }, 3000);
    }
})();
</script>
ENDJS;

include 'includes/footer.php';
?>
