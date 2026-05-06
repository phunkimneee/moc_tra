<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

$pageTitle  = 'Quản lý mã giảm giá';
$activePage = 'coupons';
$breadcrumb = [['label' => 'Mã giảm giá']];

$msg        = $msgType = '';
$errors     = [];
$editCoupon = null;

/* ── Đảm bảo bảng tồn tại + thêm cột mới nếu chưa có ── */
$conn->query("CREATE TABLE IF NOT EXISTS `coupons` (
  `id`             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `code`           VARCHAR(50)      NOT NULL,
  `discount_type`  ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  `discount_value` INT UNSIGNED     NOT NULL DEFAULT 0,
  `min_order`      INT UNSIGNED     NOT NULL DEFAULT 0,
  `max_uses`       INT              NOT NULL DEFAULT 0,
  `used_count`     INT              NOT NULL DEFAULT 0,
  `expires_at`     DATE             DEFAULT NULL,
  `is_active`      TINYINT(1)       NOT NULL DEFAULT 1,
  `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("ALTER TABLE `coupons`
  ADD COLUMN IF NOT EXISTS `coupon_role`      ENUM('public','private') NOT NULL DEFAULT 'public'              AFTER `is_active`,
  ADD COLUMN IF NOT EXISTS `specific_user_id` INT UNSIGNED                       DEFAULT NULL                 AFTER `coupon_role`,
  ADD COLUMN IF NOT EXISTS `condition_type`   ENUM('none','min_spent','new_member') NOT NULL DEFAULT 'none'   AFTER `specific_user_id`,
  ADD COLUMN IF NOT EXISTS `condition_value`  INT UNSIGNED             NOT NULL DEFAULT 0                     AFTER `condition_type`");

/* ── POST handler ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['_csrf']) || !hash_equals($_SESSION['csrf_token'], $_POST['_csrf'])) {
        header('Location: coupons.php'); exit();
    }

    $action = $_POST['action'] ?? '';

    /* Xóa (qua modal) */
    if ($action === 'delete') {
        $delId = (int)($_POST['_confirm_id'] ?? 0);
        if ($delId) {
            $st = $conn->prepare("DELETE FROM coupons WHERE id=?");
            $st->bind_param('i', $delId);
            $st->execute();
        }
        header('Location: coupons.php?msg=' . urlencode('Đã xóa mã giảm giá.') . '&type=info');
        exit();
    }

    /* Thêm / Sửa */
    $couponId      = (int)($_POST['coupon_id'] ?? 0);
    $code          = strtoupper(preg_replace('/[^A-Za-z0-9_\-]/', '', trim($_POST['code'] ?? '')));
    $type          = in_array($_POST['discount_type'] ?? '', ['percent', 'fixed'])
                     ? $_POST['discount_type'] : 'percent';
    $value         = (int)($_POST['discount_value'] ?? 0);
    $minOrder      = (int)($_POST['min_order'] ?? 0);
    $maxUses       = max(0, (int)($_POST['max_uses'] ?? 0));
    $expiresAt     = trim($_POST['expires_at'] ?? '') ?: null;
    $isActive      = isset($_POST['is_active']) ? 1 : 0;
    $couponRole    = in_array($_POST['coupon_role'] ?? '', ['public', 'private']) ? $_POST['coupon_role'] : 'public';
    $specificUser  = ($couponRole === 'private' && (int)($_POST['specific_user_id'] ?? 0) > 0)
                     ? (int)$_POST['specific_user_id'] : null;
    $condType      = in_array($_POST['condition_type'] ?? '', ['none', 'min_spent', 'new_member'])
                     ? $_POST['condition_type'] : 'none';
    $condValue     = max(0, (int)($_POST['condition_value'] ?? 0));

    /* Validate */
    if (!$code)                                            $errors[] = 'Mã giảm giá không được để trống.';
    elseif (strlen($code) < 3 || strlen($code) > 50)      $errors[] = 'Mã phải từ 3 đến 50 ký tự.';
    if ($value <= 0)                                       $errors[] = 'Giá trị giảm phải lớn hơn 0.';
    if ($type === 'percent' && $value > 100)               $errors[] = 'Giảm % không được vượt quá 100.';
    if ($expiresAt && !strtotime($expiresAt))              { $errors[] = 'Ngày hết hạn không hợp lệ.'; $expiresAt = null; }
    if ($couponRole === 'private' && !$specificUser)       $errors[] = 'Mã riêng tư cần chỉ định ID người dùng.';
    if ($condType === 'min_spent' && $condValue <= 0)      $errors[] = 'Điều kiện chi tiêu tối thiểu phải lớn hơn 0.';

    if (empty($errors)) {
        /* Kiểm tra code trùng */
        $chk = $conn->prepare("SELECT id FROM coupons WHERE code=? AND id!=? LIMIT 1");
        $chk->bind_param('si', $code, $couponId);
        $chk->execute();

        if ($chk->get_result()->num_rows > 0) {
            $errors[] = "Mã «{$code}» đã tồn tại, vui lòng chọn mã khác.";
        } elseif ($couponId) {
            /* Sửa */
            $st = $conn->prepare(
                "UPDATE coupons SET code=?,discount_type=?,discount_value=?,min_order=?,max_uses=?,expires_at=?,is_active=?,coupon_role=?,specific_user_id=?,condition_type=?,condition_value=? WHERE id=?"
            );
            $st->bind_param('ssiiisisisii', $code, $type, $value, $minOrder, $maxUses, $expiresAt, $isActive, $couponRole, $specificUser, $condType, $condValue, $couponId);
            $st->execute();
            header('Location: coupons.php?msg=' . urlencode('Cập nhật mã thành công.') . '&type=success');
            exit();
        } else {
            /* Thêm */
            $st = $conn->prepare(
                "INSERT INTO coupons (code,discount_type,discount_value,min_order,max_uses,expires_at,is_active,coupon_role,specific_user_id,condition_type,condition_value) VALUES (?,?,?,?,?,?,?,?,?,?,?)"
            );
            $st->bind_param('ssiiisisisi', $code, $type, $value, $minOrder, $maxUses, $expiresAt, $isActive, $couponRole, $specificUser, $condType, $condValue);
            $st->execute();
            header('Location: coupons.php?msg=' . urlencode('Thêm mã giảm giá thành công.') . '&type=success');
            exit();
        }
    }
}

/* ── Flash message từ redirect ── */
if (!empty($_GET['msg'])) { $msg = $_GET['msg']; $msgType = $_GET['type'] ?? 'info'; }

/* ── GET: Load form sửa ── */
if (!empty($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $st  = $conn->prepare("SELECT * FROM coupons WHERE id=? LIMIT 1");
    $st->bind_param('i', $eid);
    $st->execute();
    $editCoupon = $st->get_result()->fetch_assoc();
}

/* ── Lấy tất cả mã ── */
$coupons = $conn->query("SELECT * FROM coupons ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);
$today   = date('Y-m-d');

include 'includes/header.php';

/* ── Helper hiển thị ── */
function couponStatus(array $c, string $today): array {
    if (!$c['is_active'])                                           return ['badge-gray',   'Tắt'];
    if ($c['expires_at'] && $c['expires_at'] < $today)             return ['badge-sale',   'Hết hạn'];
    if ($c['max_uses'] > 0 && $c['used_count'] >= $c['max_uses'])  return ['badge-gray',   'Hết lượt'];
    return ['badge-active', 'Đang chạy'];
}
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType === 'error' ? 'error' : ($msgType === 'success' ? 'success' : 'info') ?>" data-auto-dismiss>
  <i class="fa-solid fa-<?= $msgType === 'success' ? 'check' : 'circle-info' ?>"></i>
  <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<?php foreach ($errors as $e): ?>
<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>

<div class="two-col" style="grid-template-columns:3fr 2fr;align-items:start">

  <!-- ══ BẢNG DANH SÁCH ══ -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">
        <i class="fa-solid fa-ticket" style="margin-right:6px"></i>Tất cả mã giảm giá
      </div>
      <span class="text-muted"><?= count($coupons) ?> mã</span>
    </div>
    <div class="card-body p0">
      <?php if (empty($coupons)): ?>
        <div class="empty-state">
          <i class="fa-solid fa-ticket" style="font-size:32px;margin-bottom:12px;opacity:.3"></i>
          <h3>Chưa có mã giảm giá</h3>
          <p>Tạo mã đầu tiên bằng form bên phải.</p>
        </div>
      <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Mã</th>
              <th>Loại / Giá trị</th>
              <th>Phân quyền</th>
              <th>Đơn tối thiểu</th>
              <th>Đã dùng</th>
              <th>Hết hạn</th>
              <th>Trạng thái</th>
              <th>Thao tác</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($coupons as $c):
              [$badgeClass, $badgeText] = couponStatus($c, $today);
              $discountText = $c['discount_type'] === 'percent'
                  ? "-{$c['discount_value']}%"
                  : '-' . number_format($c['discount_value'], 0, ',', '.') . 'đ';
              $minText  = $c['min_order'] > 0
                  ? number_format($c['min_order'], 0, ',', '.') . 'đ'
                  : '—';
              $usageText = $c['max_uses'] > 0
                  ? $c['used_count'] . ' / ' . $c['max_uses']
                  : $c['used_count'] . ' / ∞';
              $expText  = $c['expires_at']
                  ? date('d/m/Y', strtotime($c['expires_at']))
                  : '—';
            ?>
            <?php
              $roleLabel = ($c['coupon_role'] ?? 'public') === 'private' ? 'Riêng tư' : 'Công khai';
              $roleBadge = ($c['coupon_role'] ?? 'public') === 'private' ? 'badge-amber' : 'badge-active';
            ?>
            <tr>
              <td>
                <code style="background:var(--green-100);color:var(--green-700);padding:3px 8px;border-radius:5px;font-size:13px;font-weight:700;letter-spacing:.5px">
                  <?= htmlspecialchars($c['code']) ?>
                </code>
              </td>
              <td class="fw700" style="color:var(--green-700)"><?= htmlspecialchars($discountText) ?></td>
              <td><span class="badge <?= $roleBadge ?>"><?= $roleLabel ?></span></td>
              <td class="text-muted"><?= $minText ?></td>
              <td><?= $usageText ?></td>
              <td class="text-muted"><?= $expText ?></td>
              <td><span class="badge <?= $badgeClass ?>"><?= $badgeText ?></span></td>
              <td>
                <div class="flex-center">
                  <a href="coupons.php?edit=<?= $c['id'] ?>" class="btn btn-sm btn-amber btn-edit">
                    <i class="fa-solid fa-pen-to-square"></i>Sửa
                  </a>
                  <button type="button" class="btn btn-sm btn-danger btn-delete"
                    onclick="openModal({
                      title: 'Xóa mã giảm giá',
                      desc:  'Bạn có chắc muốn xóa mã «<?= addslashes(htmlspecialchars($c['code'])) ?>»?',
                      id:    '<?= $c['id'] ?>',
                      action:'delete',
                      url:   'coupons.php',
                      btnText:'Xóa mã'
                    })">
                    <i class="fa-solid fa-trash"></i>Xóa
                  </button>
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

  <!-- ══ FORM THÊM / SỬA ══ -->
  <div class="card" style="position:sticky;top:76px">
    <div class="card-header">
      <div class="card-title">
        <?= $editCoupon ? '✏️ Sửa mã' : '➕ Thêm mã mới' ?>
      </div>
      <?php if ($editCoupon): ?>
        <a href="coupons.php" class="btn btn-sm btn-secondary">Hủy</a>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <form method="POST" id="couponForm">
        <input type="hidden" name="_csrf"      value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action"     value="<?= $editCoupon ? 'edit' : 'add' ?>">
        <input type="hidden" name="coupon_id"  value="<?= $editCoupon ? (int)$editCoupon['id'] : 0 ?>">

        <div class="form-group" style="margin-bottom:14px">
          <label>Mã giảm giá <span style="color:var(--red-700)">*</span></label>
          <input type="text" name="code" id="codeInput" required maxlength="50"
                 style="text-transform:uppercase;font-weight:700;letter-spacing:1px"
                 value="<?= htmlspecialchars($editCoupon ? $editCoupon['code'] : strtoupper($_POST['code'] ?? '')) ?>"
                 placeholder="VD: MOCTRA10">
          <div class="hint">Chỉ gồm chữ HOA, số, dấu gạch ngang hoặc gạch dưới.</div>
        </div>

        <div class="form-group" style="margin-bottom:14px">
          <label>Loại giảm <span style="color:var(--red-700)">*</span></label>
          <select name="discount_type" id="discountType">
            <option value="percent" <?= (!$editCoupon || $editCoupon['discount_type']==='percent') ? 'selected':'' ?>>
              Phần trăm (%)
            </option>
            <option value="fixed"   <?= ($editCoupon && $editCoupon['discount_type']==='fixed')   ? 'selected':'' ?>>
              Số tiền cố định (VNĐ)
            </option>
          </select>
        </div>

        <div class="form-group" style="margin-bottom:14px">
          <label>Giá trị giảm <span style="color:var(--red-700)">*</span></label>
          <input type="number" name="discount_value" id="discountValue" min="1" required
                 value="<?= $editCoupon ? (int)$editCoupon['discount_value'] : (int)($_POST['discount_value'] ?? '') ?>">
          <div class="hint" id="valueHint">
            <?= (!$editCoupon || $editCoupon['discount_type']==='percent')
                ? 'Nhập số từ 1–100 (ví dụ: 10 = giảm 10%).'
                : 'Nhập số tiền VNĐ (ví dụ: 50000).' ?>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:14px">
          <label>Đơn tối thiểu (VNĐ)</label>
          <input type="number" name="min_order" min="0"
                 value="<?= $editCoupon ? (int)$editCoupon['min_order'] : (int)($_POST['min_order'] ?? 0) ?>">
          <div class="hint">Để 0 nếu không giới hạn giá trị đơn hàng.</div>
        </div>

        <div class="form-group" style="margin-bottom:14px">
          <label>Giới hạn lượt dùng</label>
          <input type="number" name="max_uses" min="0"
                 value="<?= $editCoupon ? (int)$editCoupon['max_uses'] : (int)($_POST['max_uses'] ?? 0) ?>">
          <div class="hint">Để 0 = không giới hạn số lần sử dụng.</div>
        </div>

        <div class="form-group" style="margin-bottom:14px">
          <label>Ngày hết hạn</label>
          <input type="date" name="expires_at"
                 value="<?= htmlspecialchars($editCoupon ? ($editCoupon['expires_at'] ?? '') : ($_POST['expires_at'] ?? '')) ?>">
          <div class="hint">Để trống nếu mã không có ngày hết hạn.</div>
        </div>

        <div class="form-group" style="margin-bottom:14px">
          <label>Phân quyền mã</label>
          <select name="coupon_role" id="couponRole">
            <option value="public"  <?= (!$editCoupon || ($editCoupon['coupon_role'] ?? 'public') === 'public')   ? 'selected' : '' ?>>Công khai (tất cả khách hàng)</option>
            <option value="private" <?= ($editCoupon && ($editCoupon['coupon_role'] ?? '') === 'private') ? 'selected' : '' ?>>Riêng tư (1 khách hàng)</option>
          </select>
        </div>

        <div id="specificUserRow" style="margin-bottom:14px;<?= ($editCoupon && ($editCoupon['coupon_role'] ?? '') === 'private') ? '' : 'display:none' ?>">
          <div class="form-group">
            <label>ID người dùng <span style="color:var(--red-700)">*</span></label>
            <input type="number" name="specific_user_id" id="specificUserId" min="1"
                   value="<?= $editCoupon ? (int)($editCoupon['specific_user_id'] ?? 0) : 0 ?>">
            <div class="hint">Nhập ID của khách hàng trong bảng <code>users</code>.</div>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:14px">
          <label>Điều kiện sử dụng</label>
          <select name="condition_type" id="conditionType">
            <option value="none"       <?= (!$editCoupon || ($editCoupon['condition_type'] ?? 'none') === 'none')           ? 'selected' : '' ?>>Không có điều kiện</option>
            <option value="min_spent"  <?= ($editCoupon && ($editCoupon['condition_type'] ?? '') === 'min_spent')  ? 'selected' : '' ?>>Tổng chi tiêu tối thiểu</option>
            <option value="new_member" <?= ($editCoupon && ($editCoupon['condition_type'] ?? '') === 'new_member') ? 'selected' : '' ?>>Thành viên mới (chưa mua lần nào)</option>
          </select>
        </div>

        <div id="condValueRow" style="margin-bottom:14px;<?= ($editCoupon && ($editCoupon['condition_type'] ?? 'none') === 'min_spent') ? '' : 'display:none' ?>">
          <div class="form-group">
            <label>Giá trị điều kiện (VNĐ) <span style="color:var(--red-700)">*</span></label>
            <input type="number" name="condition_value" id="conditionValue" min="0"
                   value="<?= $editCoupon ? (int)($editCoupon['condition_value'] ?? 0) : 0 ?>">
            <div class="hint">Tổng tiền đã chi (đơn hoàn thành) phải đạt giá trị này.</div>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:20px;flex-direction:row;align-items:center;gap:10px">
          <input type="checkbox" name="is_active" id="isActive" style="width:16px;height:16px;cursor:pointer;accent-color:var(--green-700)"
                 <?= (!$editCoupon || $editCoupon['is_active']) ? 'checked' : '' ?>>
          <label for="isActive" style="cursor:pointer;font-size:14px;font-weight:500;margin:0">
            Kích hoạt mã ngay
          </label>
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">
          <i class="fa-solid fa-<?= $editCoupon ? 'floppy-disk' : 'plus' ?>"></i>
          <?= $editCoupon ? 'Lưu thay đổi' : 'Thêm mã giảm giá' ?>
        </button>
      </form>
    </div>
  </div>

</div>

<script>
(function () {
  /* Auto-uppercase code input */
  var codeInput = document.getElementById('codeInput');
  if (codeInput) {
    codeInput.addEventListener('input', function () {
      var pos = this.selectionStart;
      this.value = this.value.replace(/[^A-Za-z0-9_\-]/g, '').toUpperCase();
      this.setSelectionRange(pos, pos);
    });
  }

  /* Update hint text when discount type changes */
  var typeSelect = document.getElementById('discountType');
  var valueHint  = document.getElementById('valueHint');
  var valueInput = document.getElementById('discountValue');
  if (typeSelect) {
    typeSelect.addEventListener('change', function () {
      if (this.value === 'percent') {
        valueHint.textContent = 'Nhập số từ 1–100 (ví dụ: 10 = giảm 10%).';
        valueInput.max = 100;
      } else {
        valueHint.textContent = 'Nhập số tiền VNĐ (ví dụ: 50000).';
        valueInput.removeAttribute('max');
      }
    });
  }

  /* Show/hide specific_user_id based on coupon_role */
  var couponRole      = document.getElementById('couponRole');
  var specificUserRow = document.getElementById('specificUserRow');
  if (couponRole && specificUserRow) {
    couponRole.addEventListener('change', function () {
      specificUserRow.style.display = this.value === 'private' ? '' : 'none';
    });
  }

  /* Show/hide condition_value based on condition_type */
  var conditionType = document.getElementById('conditionType');
  var condValueRow  = document.getElementById('condValueRow');
  if (conditionType && condValueRow) {
    conditionType.addEventListener('change', function () {
      condValueRow.style.display = this.value === 'min_spent' ? '' : 'none';
    });
  }
})();
</script>

<?php include 'includes/footer.php'; ?>
