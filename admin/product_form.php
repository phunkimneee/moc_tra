<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

$editId   = (int)($_GET['id'] ?? 0);
$isEdit   = $editId > 0;
$product  = null;
$errors   = [];

if ($isEdit) {
    $st = $conn->prepare("SELECT * FROM products WHERE id=? LIMIT 1");
    $st->bind_param('i', $editId);
    $st->execute();
    $product = $st->get_result()->fetch_assoc();
    if (!$product) { header('Location: products.php'); exit(); }
}

$cats = $conn->query("SELECT * FROM categories ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);

/* ── POST ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name      = trim($_POST['name'] ?? '');
    $catId     = (int)($_POST['category_id'] ?? 0);
    $price     = (int)($_POST['price'] ?? 0);
    $priceOld  = (int)($_POST['price_old'] ?? 0) ?: null;
    $costPrice = max(0, (int)($_POST['cost_price'] ?? 0));
    $origin    = trim($_POST['origin'] ?? '');
    $weight    = trim($_POST['weight'] ?? '');
    $type      = trim($_POST['type'] ?? '');
    $isFeatured = isset($_POST['is_featured']) ? 1 : 0;
    $isNew     = isset($_POST['is_new']) ? 1 : 0;
    $desc      = trim($_POST['description'] ?? '');

    /* Validate */
    if (!$name)   $errors[] = 'Tên sản phẩm không được để trống.';
    if (!$catId)  $errors[] = 'Vui lòng chọn danh mục.';
    if ($price <= 0) $errors[] = 'Giá bán phải lớn hơn 0.';
    if ($costPrice > 0 && $costPrice >= $price) $errors[] = 'Giá vốn phải nhỏ hơn giá bán.';

    /* Image upload */
    $imageName = $product['image'] ?? '';
    if (!empty($_FILES['image']['name'])) {
        $file    = $_FILES['image'];
        $ext          = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed      = ['jpg','jpeg','png','webp','gif'];
        $allowedMime  = ['image/jpeg','image/png','image/webp','image/gif'];
        $detectedMime = mime_content_type($file['tmp_name']);
        if (!in_array($ext, $allowed) || !in_array($detectedMime, $allowedMime)) {
            $errors[] = 'Định dạng ảnh không hợp lệ (jpg, png, webp, gif).';
        } elseif ($file['size'] > MAX_IMAGE_SIZE) {
            $errors[] = 'Ảnh tối đa 5MB.';
        } else {
            $newName   = 'product_' . time() . '_' . rand(100,999) . '.' . $ext;
            $uploadDir = dirname(__DIR__) . '/images/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            if (move_uploaded_file($file['tmp_name'], $uploadDir . $newName)) {
                if ($isEdit && !empty($product['image']) && $product['image'] !== $newName) {
                    $oldPath = $uploadDir . $product['image'];
                    if (is_file($oldPath)) @unlink($oldPath);
                }
                $imageName = $newName;
            } else {
                $errors[] = 'Upload ảnh thất bại.';
            }
        }
    }

    if (empty($errors)) {
        if ($isEdit) {
            $st = $conn->prepare("UPDATE products SET name=?,category_id=?,price=?,price_old=?,cost_price=?,origin=?,weight=?,type=?,is_featured=?,is_new=?,image=?,description=? WHERE id=?");
            $st->bind_param('siiiisssiissi', $name,$catId,$price,$priceOld,$costPrice,$origin,$weight,$type,$isFeatured,$isNew,$imageName,$desc,$editId);
            $st->execute();
            header('Location: products.php?updated=1');
        } else {
            $st = $conn->prepare("INSERT INTO products (name,category_id,price,price_old,cost_price,origin,weight,type,is_featured,is_new,image,description,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
            $st->bind_param('siiiisssiiss', $name,$catId,$price,$priceOld,$costPrice,$origin,$weight,$type,$isFeatured,$isNew,$imageName,$desc);
            $st->execute();
            header('Location: products.php?added=1');
        }
        exit();
    }

    /* Re-fill trên lỗi */
    $product = array_merge($product ?? [], compact('name','catId','price','priceOld','origin','weight','type','isFeatured','isNew','desc','imageName'));
    $product['category_id'] = $catId;
    $product['price_old']   = $priceOld;
    $product['cost_price']  = $costPrice;
    $product['is_featured'] = $isFeatured;
    $product['is_new']      = $isNew;
    $product['description'] = $desc;
    $product['image']       = $imageName;
}

$pageTitle  = $isEdit ? 'Sửa sản phẩm' : 'Thêm sản phẩm';
$activePage = 'products';
$breadcrumb = [['label'=>'Sản phẩm','url'=>'products.php'],['label'=>$pageTitle]];
$typeLabels = ['la'=>'Trà lá rời','tui_loc'=>'Trà túi lọc','bot'=>'Bột trà nghiền','hop_qua'=>'Hộp quà'];

include 'includes/header.php';
?>

<?php foreach ($errors as $e): ?>
<div class="alert alert-error">
  <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  <?= htmlspecialchars($e) ?>
</div>
<?php endforeach; ?>

<form method="POST" enctype="multipart/form-data">
<div class="two-col" style="align-items:start">

  <!-- LEFT: Main info -->
  <div style="display:flex;flex-direction:column;gap:18px">

    <!-- Thông tin cơ bản -->
    <div class="card">
      <div class="card-header"><div class="card-title">Thông tin sản phẩm</div></div>
      <div class="card-body">
        <div class="form-grid" style="margin-bottom:16px">
          <div class="form-group form-full">
            <label>Tên sản phẩm <span style="color:var(--red-700)">*</span></label>
            <input type="text" name="name" required
                   value="<?= htmlspecialchars($product['name'] ?? '') ?>"
                   placeholder="Ví dụ: Trà Shan Tuyết Mộc Châu">
          </div>
          <div class="form-group">
            <label>Danh mục <span style="color:var(--red-700)">*</span></label>
            <select name="category_id" required>
              <option value="">-- Chọn danh mục --</option>
              <?php foreach ($cats as $c): ?>
                <option value="<?= $c['id'] ?>"
                  <?= ($product['category_id'] ?? 0) == $c['id'] ? 'selected':'' ?>>
                  <?= htmlspecialchars($c['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Dạng sản phẩm</label>
            <select name="type">
              <option value="">-- Chọn dạng --</option>
              <?php foreach ($typeLabels as $v => $l): ?>
                <option value="<?= $v ?>" <?= ($product['type'] ?? '') === $v ? 'selected':'' ?>>
                  <?= $l ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label>Xuất xứ</label>
            <input type="text" name="origin" placeholder="Ví dụ: Thái Nguyên, Việt Nam"
                   value="<?= htmlspecialchars($product['origin'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>Trọng lượng</label>
            <input type="text" name="weight" placeholder="Ví dụ: 200g, 500g"
                   value="<?= htmlspecialchars($product['weight'] ?? '') ?>">
          </div>
        </div>

        <!-- Description if possible -->
        <div class="form-group" style="margin-top:16px">
          <label>Mô tả sản phẩm</label>
          <textarea name="description" rows="4" placeholder="Mô tả chi tiết về sản phẩm..."><?= htmlspecialchars($product['description'] ?? '') ?></textarea>
          <div class="hint">Mô tả sẽ hiển thị trên trang chi tiết sản phẩm.</div>
        </div>
      </div>
    </div>

    <!-- Giá -->
    <div class="card">
      <div class="card-header"><div class="card-title">Thông tin giá</div></div>
      <div class="card-body">
        <div class="form-grid">
          <div class="form-group">
            <label>Giá bán <span style="color:var(--red-700)">*</span></label>
            <input type="number" name="price" required min="0" step="1000"
                   value="<?= (int)($product['price'] ?? 0) ?>"
                   placeholder="250000">
            <div class="hint">Nhập số không có dấu chấm/phẩy (VD: 250000)</div>
          </div>
          <div class="form-group">
            <label>Giá cũ (để trống nếu không sale)</label>
            <input type="number" name="price_old" min="0" step="1000"
                   value="<?= $product['price_old'] ? (int)$product['price_old'] : '' ?>"
                   placeholder="350000">
            <div class="hint">Khi có giá cũ sẽ hiện badge "Sale" trên sản phẩm</div>
          </div>
          <div class="form-group">
            <label>Giá vốn (để trống nếu chưa biết)</label>
            <input type="number" name="cost_price" min="0" step="1000"
                   value="<?= (int)($product['cost_price'] ?? 0) ?: '' ?>"
                   placeholder="180000">
            <div class="hint">Giá nhập hàng — dùng tính lợi nhuận trên Dashboard</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- RIGHT: Image + Tags -->
  <div style="display:flex;flex-direction:column;gap:18px">

    <!-- Ảnh sản phẩm -->
    <div class="card">
      <div class="card-header"><div class="card-title">Hình ảnh</div></div>
      <div class="card-body">
        <?php if (!empty($product['image'])): ?>
        <div class="img-preview-wrap" style="margin-bottom:14px">
          <img src="../images/<?= htmlspecialchars($product['image']) ?>"
               onerror="this.onerror=null;this.src='../images/logo.png'"
               class="img-preview" id="imgPreview">
          <div style="font-size:12px;color:var(--gray-400)">Ảnh hiện tại</div>
        </div>
        <?php else: ?>
        <div style="margin-bottom:14px">
          <img src="" class="img-preview" id="imgPreview"
               style="display:none;margin-bottom:8px">
        </div>
        <?php endif; ?>
        <div class="form-group">
          <label>Chọn ảnh <?= $isEdit ? '(để trống giữ ảnh cũ)' : '' ?></label>
          <input type="file" name="image" accept="image/*" id="imgInput"
                 <?= !$isEdit ? 'required' : '' ?>>
          <div class="hint">JPG, PNG, WebP. Tối đa 5MB.</div>
        </div>
      </div>
    </div>

    <!-- Tags / Flags -->
    <div class="card">
      <div class="card-header"><div class="card-title">Nhãn hiển thị</div></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:14px">
        <label class="check-group">
          <input type="checkbox" name="is_featured" value="1"
                 <?= ($product['is_featured'] ?? 0) ? 'checked':'' ?>>
          <span>⭐ Sản phẩm nổi bật</span>
        </label>
        <div class="hint" style="margin-top:-10px;margin-left:24px">Hiển thị badge "Nổi bật" và xuất hiện trong section đặc biệt</div>
        <label class="check-group">
          <input type="checkbox" name="is_new" value="1"
                 <?= ($product['is_new'] ?? 0) ? 'checked':'' ?>>
          <span>🆕 Sản phẩm mới</span>
        </label>
        <div class="hint" style="margin-top:-10px;margin-left:24px">Hiển thị badge "Mới" trên card sản phẩm</div>
      </div>
    </div>

    <!-- Submit -->
    <div style="display:flex;gap:12px">
      <button type="submit" class="btn btn-primary" style="flex:1">
        <svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        <?= $isEdit ? 'Lưu thay đổi' : 'Thêm sản phẩm' ?>
      </button>
      <a href="products.php" class="btn btn-secondary">Hủy</a>
    </div>
  </div>
</div>
</form>

<script>
document.getElementById('imgInput') && document.getElementById('imgInput').addEventListener('change', function(){
  var f = this.files[0];
  if (!f) return;
  var url = URL.createObjectURL(f);
  var img = document.getElementById('imgPreview');
  img.src = url; img.style.display = 'block';
});
</script>

<?php include 'includes/footer.php'; ?>
