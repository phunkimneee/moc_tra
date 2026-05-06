<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

$pageTitle  = 'Quản lý danh mục';
$activePage = 'categories';
$breadcrumb = [['label'=>'Danh mục']];

$msg = $msgType = '';
$errors = [];
$editCat = null;

/* ── POST: Thêm hoặc sửa ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if ($csrfToken === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        $errors[] = 'Lỗi bảo mật (CSRF).';
    } else {
        $action    = $_POST['action'] ?? '';
        $catId     = (int)($_POST['cat_id'] ?? 0);
        $name      = trim($_POST['name'] ?? '');
        $slug      = trim($_POST['slug'] ?? '');
        $icon      = trim($_POST['icon'] ?? 'fa-solid fa-mug-hot');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (!$name)  $errors[] = 'Tên danh mục không được để trống.';
        if (!$slug)  $errors[] = 'Slug không được để trống.';
        if (!preg_match('/^[a-z0-9\-]+$/', $slug)) $errors[] = 'Slug chỉ gồm chữ thường, số và dấu gạch ngang.';

        if (empty($errors)) {
            try {
                if ($action === 'edit' && $catId) {
                    $st = $conn->prepare("UPDATE categories SET name=?,slug=?,icon=?,sort_order=? WHERE id=?");
                    $st->bind_param('sssii', $name, $slug, $icon, $sortOrder, $catId);
                    $st->execute();
                    $msg = 'Cập nhật danh mục thành công.'; $msgType = 'success';
                } else {
                    /* Kiểm tra slug trùng */
                    $chk = $conn->prepare("SELECT id FROM categories WHERE slug=? LIMIT 1");
                    $chk->bind_param('s', $slug); $chk->execute();
                    if ($chk->get_result()->num_rows > 0) {
                        $errors[] = 'Slug đã tồn tại, hãy chọn slug khác.';
                    } else {
                        $st = $conn->prepare("INSERT INTO categories (name,slug,icon,sort_order) VALUES (?,?,?,?)");
                        $st->bind_param('sssi', $name, $slug, $icon, $sortOrder);
                        $st->execute();
                        $msg = 'Thêm danh mục thành công.'; $msgType = 'success';
                    }
                }
            } catch (Throwable $e) {
                error_log('Categories Error: ' . $e->getMessage());
                $errors[] = 'Lỗi hệ thống khi cập nhật cơ sở dữ liệu.';
            }
        }
    }
}

/* ── POST: Xóa ── */
if (!empty($_GET['delete'])) {
    $delId = (int)$_GET['delete'];
    try {
        /* Kiểm tra còn SP không */
        $stCnt = $conn->prepare("SELECT COUNT(*) FROM products WHERE category_id=?");
        $stCnt->bind_param('i', $delId);
        $stCnt->execute();
        $cnt = (int)$stCnt->get_result()->fetch_row()[0];
        if ($cnt > 0) {
            $msg = "Không thể xóa vì còn $cnt sản phẩm trong danh mục này."; $msgType = 'error';
        } else {
            $st = $conn->prepare("DELETE FROM categories WHERE id=?");
            $st->bind_param('i', $delId); $st->execute();
            $msg = 'Đã xóa danh mục.'; $msgType = 'info';
        }
    } catch (Throwable $e) {
        error_log('Categories Delete Error: ' . $e->getMessage());
        $msg = 'Lỗi hệ thống khi xóa danh mục.'; $msgType = 'error';
    }
    header('Location: categories.php?msg=' . urlencode($msg) . '&type=' . $msgType);
    exit();
}
if (!empty($_GET['msg'])) { $msg = $_GET['msg']; $msgType = $_GET['type'] ?? 'info'; }

/* ── GET: sửa ── */
if (!empty($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $st  = $conn->prepare("SELECT * FROM categories WHERE id=? LIMIT 1");
    $st->bind_param('i', $eid); $st->execute();
    $editCat = $st->get_result()->fetch_assoc();
}

/* ── Lấy danh sách ── */
$cats = $conn->query(
    "SELECT c.*, COUNT(p.id) as product_count
     FROM categories c
     LEFT JOIN products p ON p.category_id = c.id
     GROUP BY c.id
     ORDER BY c.sort_order, c.id"
)->fetch_all(MYSQLI_ASSOC);

include 'includes/header.php';
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType === 'error' ? 'error' : ($msgType === 'success' ? 'success' : 'info') ?>" data-auto-dismiss>
  <i class="fa-solid fa-check"></i>
  <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>
<?php foreach ($errors as $e): ?>
<div class="alert alert-error">
  <i class="fa-solid fa-circle-exclamation"></i>
  <?= htmlspecialchars($e) ?>
</div>
<?php endforeach; ?>

<div class="two-col" style="align-items:start">

  <!-- Danh sách -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">Tất cả danh mục</div>
      <span class="text-muted"><?= count($cats) ?> danh mục</span>
    </div>
    <div class="card-body p0">
      <?php if (empty($cats)): ?>
        <div class="empty-state">
          <i class="fa-solid fa-folder-open" style="font-size:32px;margin-bottom:12px;opacity:.3"></i>
          <h3>Chưa có danh mục</h3>
          <p>Thêm danh mục đầu tiên bên phải.</p>
        </div>
      <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th style="text-align:center;width:60px">Icon</th>
              <th>Tên danh mục</th>
              <th>Slug</th>
              <th style="text-align:center;width:76px">Thứ tự</th>
              <th style="text-align:center;width:60px">SP</th>
              <th>Thao tác</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($cats as $c): ?>
            <tr>
              <td style="text-align:center">
                <span style="display:inline-block;width:36px;height:36px;line-height:34px;background:var(--green-50);border:1.5px solid var(--green-200);border-radius:8px;text-align:center;vertical-align:middle;">
                  <i class="<?= htmlspecialchars($c['icon']) ?>" style="font-size:16px;color:var(--green-700);margin:0;vertical-align:middle;"></i>
                </span>
              </td>
              <td class="td-name"><?= htmlspecialchars($c['name']) ?></td>
              <td><code style="background:var(--gray-100);padding:2px 6px;border-radius:4px;font-size:12px"><?= htmlspecialchars($c['slug']) ?></code></td>
              <td style="text-align:center;color:var(--gray-500);font-weight:600"><?= $c['sort_order'] ?></td>
              <td style="text-align:center">
                <a href="products.php?cat=<?= $c['id'] ?>" class="text-green fw700"><?= (int)$c['product_count'] ?></a>
              </td>
              <td>
                <div class="flex-center">
                  <a href="categories.php?edit=<?= $c['id'] ?>" class="btn btn-sm btn-amber btn-edit">
                    <i class="fa-solid fa-pen-to-square"></i>
                    Sửa
                  </a>
                  <a href="categories.php?delete=<?= $c['id'] ?>"
                     class="btn btn-sm btn-danger btn-delete"
                     onclick="return confirm('Xóa danh mục &quot;<?= addslashes(htmlspecialchars($c['name'])) ?>&quot;?')">
                    <i class="fa-solid fa-trash"></i>
                    Xóa
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

  <!-- Form thêm/sửa -->
  <div class="card" style="position:sticky;top:76px">
    <div class="card-header">
      <div class="card-title"><?= $editCat ? '✏️ Sửa danh mục' : '➕ Thêm danh mục' ?></div>
      <?php if ($editCat): ?>
        <a href="categories.php" class="btn btn-sm btn-secondary">Hủy sửa</a>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
        <input type="hidden" name="action" value="<?= $editCat ? 'edit' : 'add' ?>">
        <?php if ($editCat): ?>
          <input type="hidden" name="cat_id" value="<?= $editCat['id'] ?>">
        <?php endif; ?>

        <div class="form-group" style="margin-bottom:14px">
          <label>Font Awesome Class <span style="color:var(--red-700)">*</span></label>
          <div style="display:flex;align-items:center;gap:10px">
            <input type="text" name="icon" id="iconInput"
                   value="<?= htmlspecialchars($editCat ? $editCat['icon'] : 'fa-solid fa-mug-hot') ?>"
                   placeholder="fa-solid fa-leaf" style="flex:1;font-family:monospace;font-size:13px">
            <span id="iconPreview" title="Xem trước icon"
                  style="display:inline-block;width:40px;height:40px;line-height:38px;flex-shrink:0;background:var(--green-50);border:1.5px solid var(--green-200);border-radius:8px;text-align:center;vertical-align:middle;">
              <i id="iconPreviewI" style="font-size:17px;color:var(--green-700);margin:0;vertical-align:middle;"></i>
            </span>
          </div>
          <div class="hint">Ví dụ: <code>fa-solid fa-leaf</code>, <code>fa-solid fa-gift</code>...</div>
        </div>

        <div class="form-group" style="margin-bottom:14px">
          <label>Tên danh mục <span style="color:var(--red-700)">*</span></label>
          <input type="text" name="name" required
                 value="<?= htmlspecialchars($editCat ? $editCat['name'] : ($_POST['name'] ?? '')) ?>"
                 placeholder="Trà xanh" id="catName">
        </div>

        <div class="form-group" style="margin-bottom:14px">
          <label>Slug <span style="color:var(--red-700)">*</span></label>
          <input type="text" name="slug" required
                 value="<?= htmlspecialchars($editCat ? $editCat['slug'] : ($_POST['slug'] ?? '')) ?>"
                 placeholder="tra-xanh" id="catSlug">
          <div class="hint">Chỉ gồm chữ thường, số và dấu gạch ngang. Dùng trong URL.</div>
        </div>

        <div class="form-group" style="margin-bottom:16px">
          <label>Thứ tự hiển thị</label>
          <input type="number" name="sort_order" min="0"
                 value="<?= $editCat ? (int)$editCat['sort_order'] : (count($cats) + 1) ?>"
                 placeholder="1">
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%">
          <i class="fa-solid fa-floppy-disk"></i>
          <?= $editCat ? 'Lưu thay đổi' : 'Thêm danh mục' ?>
        </button>
      </form>
    </div>
  </div>
</div>

<script>
/* Icon preview */
(function(){
  var input   = document.getElementById('iconInput');
  var preview = document.getElementById('iconPreviewI');
  if (!input || !preview) return;

  function updatePreview() {
    var cls = input.value.trim();
    preview.className = cls || 'fa-solid fa-question';
    preview.style.cssText = 'font-size:17px;color:var(--green-700);margin:0';
  }
  input.addEventListener('input', updatePreview);
  updatePreview(); // khởi tạo ngay khi load
})();

/* Auto-generate slug từ tên */
(function(){
  var nameInput = document.getElementById('catName');
  var slugInput = document.getElementById('catSlug');
  if (!nameInput || !slugInput || slugInput.value) return;

  function toSlug(str) {
    var map = {'à':'a','á':'a','ả':'a','ã':'a','ạ':'a','ă':'a','ắ':'a','ặ':'a','ằ':'a','ẵ':'a','ẳ':'a','â':'a','ấ':'a','ầ':'a','ẩ':'a','ẫ':'a','ậ':'a','đ':'d','è':'e','é':'e','ẻ':'e','ẽ':'e','ẹ':'e','ê':'e','ế':'e','ề':'e','ể':'e','ễ':'e','ệ':'e','ì':'i','í':'i','ỉ':'i','ĩ':'i','ị':'i','ò':'o','ó':'o','ỏ':'o','ọ':'o','ô':'o','ố':'o','ồ':'o','ổ':'o','ỗ':'o','ộ':'o','ơ':'o','ớ':'o','ờ':'o','ở':'o','ỡ':'o','ợ':'o','ù':'u','ú':'u','ủ':'u','ũ':'u','ụ':'u','ư':'u','ứ':'u','ừ':'u','ử':'u','ữ':'u','ự':'u','ỳ':'y','ý':'y','ỷ':'y','ỹ':'y','ỵ':'y'};
    return str.toLowerCase().split('').map(function(c){ return map[c]||c; }).join('').replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'');
  }
  nameInput.addEventListener('input', function(){
    if (!slugInput.dataset.manual) slugInput.value = toSlug(this.value);
  });
  slugInput.addEventListener('input', function(){
    slugInput.dataset.manual = '1';
  });
})();
</script>

<?php include 'includes/footer.php'; ?>
