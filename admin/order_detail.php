<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

$orderId = (int)($_GET['id'] ?? 0);
if (!$orderId) { header('Location: orders.php'); exit(); }

/* ── Lấy đơn hàng ── */
$st = $conn->prepare("SELECT o.*, u.username, u.email FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.id = ? LIMIT 1");
$st->bind_param('i', $orderId);
$st->execute();
$order = $st->get_result()->fetch_assoc();
if (!$order) { header('Location: orders.php'); exit(); }

/* ── Lấy items ── */
$stItems = $conn->prepare(
    "SELECT oi.*, p.image FROM order_items oi
     LEFT JOIN products p ON p.id = oi.product_id
     WHERE oi.order_id = ?"
);
$stItems->bind_param('i', $orderId);
$stItems->execute();
$items = $stItems->get_result()->fetch_all(MYSQLI_ASSOC);

$pageTitle  = 'Chi tiết đơn #' . $orderId;
$activePage = 'orders';
$breadcrumb = [['label'=>'Đơn hàng','url'=>'orders.php'], ['label'=>'#'.$orderId]];

$msg = '';
if (!empty($_GET['updated'])) $msg = 'Cập nhật trạng thái thành công.';

$statusCfg = [
    'pending'    => ['label'=>'Chờ xác nhận','class'=>'badge-pending',    'step'=>0],
    'processing' => ['label'=>'Đang xử lý',  'class'=>'badge-processing', 'step'=>1],
    'shipping'   => ['label'=>'Đang giao',   'class'=>'badge-shipping',   'step'=>2],
    'delivered'  => ['label'=>'Đã giao',     'class'=>'badge-delivered',  'step'=>3],
    'cancelled'  => ['label'=>'Đã hủy',      'class'=>'badge-cancelled',  'step'=>-1],
];
$payLabels = ['cod'=>'Thanh toán khi nhận (COD)','momo'=>'Ví MoMo','bank'=>'Thẻ ATM/Tín dụng'];
$nextStatus = ['pending'=>'processing','processing'=>'shipping','shipping'=>'delivered'];

function fmtMoney(int $n): string { return number_format($n, 0, ',', '.') . 'đ'; }

$sc   = $statusCfg[$order['status']] ?? ['label'=>$order['status'],'class'=>'badge-gray','step'=>-1];
$step = $sc['step'];

include 'includes/header.php';
?>

<?php if ($msg): ?>
<div class="alert alert-success" data-auto-dismiss>
  <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
  <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<div class="two-col" style="align-items:start">

  <!-- LEFT: Order info -->
  <div style="display:flex;flex-direction:column;gap:18px">

    <!-- Thông tin khách -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">Thông tin giao hàng</div>
        <span class="badge <?= $sc['class'] ?>"><?= $sc['label'] ?></span>
      </div>
      <div class="card-body">
        <div class="form-grid">
          <div>
            <div class="text-muted mb16">Khách hàng</div>
            <div class="fw700" style="font-size:15px"><?= htmlspecialchars($order['full_name']) ?></div>
            <?php if ($order['username']): ?>
              <div class="text-muted" style="margin-top:2px">@<?= htmlspecialchars($order['username']) ?></div>
            <?php endif; ?>
          </div>
          <div>
            <div class="text-muted mb16">Liên hệ</div>
            <div><?= htmlspecialchars($order['phone']) ?></div>
            <?php if ($order['email']): ?>
              <div class="text-muted"><?= htmlspecialchars($order['email']) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <div class="divider"></div>
        <div>
          <div class="text-muted" style="margin-bottom:4px">Địa chỉ giao hàng</div>
          <div><?= htmlspecialchars($order['address']) ?></div>
        </div>
        <?php if ($order['note']): ?>
        <div style="margin-top:12px;padding:10px 14px;background:var(--amber-100);border-radius:6px;font-size:13px;color:var(--amber-700)">
          <strong>Ghi chú:</strong> <?= htmlspecialchars($order['note']) ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Danh sách sản phẩm -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">Sản phẩm đã đặt</div>
        <span class="text-muted"><?= count($items) ?> sản phẩm</span>
      </div>
      <div class="card-body p0">
        <div class="table-wrap">
          <table>
            <thead>
              <tr><th>Sản phẩm</th><th>Đơn giá</th><th>Số lượng</th><th>Thành tiền</th></tr>
            </thead>
            <tbody>
              <?php foreach ($items as $item): ?>
              <tr>
                <td>
                  <div class="product-thumb">
                    <img src="../images/<?= htmlspecialchars($item['image'] ?? '') ?>"
                         onerror="this.onerror=null;this.src='../images/logo.png'"
                         alt="<?= htmlspecialchars($item['product_name']) ?>">
                    <div>
                      <div class="fw700"><?= htmlspecialchars($item['product_name']) ?></div>
                      <div class="text-muted">Mã SP #<?= $item['product_id'] ?></div>
                    </div>
                  </div>
                </td>
                <td><?= fmtMoney((int)$item['price']) ?></td>
                <td>x<?= (int)$item['qty'] ?></td>
                <td style="color:var(--red-700);font-weight:700"><?= fmtMoney((int)$item['price'] * (int)$item['qty']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <!-- Summary -->
        <div style="padding:16px 20px;border-top:1px solid var(--gray-100)">
          <?php
          $subtotal = array_sum(array_map(fn($i)=>$i['price']*$i['qty'], $items));
          $ship     = $order['total'] - $subtotal;
          ?>
          <div style="display:flex;justify-content:space-between;font-size:13.5px;color:var(--gray-500);margin-bottom:6px">
            <span>Tạm tính</span><span><?= fmtMoney((int)$subtotal) ?></span>
          </div>
          <div style="display:flex;justify-content:space-between;font-size:13.5px;color:var(--gray-500);margin-bottom:10px">
            <span>Phí vận chuyển</span>
            <span><?= $ship > 0 ? fmtMoney((int)$ship) : 'Miễn phí' ?></span>
          </div>
          <div style="display:flex;justify-content:space-between;font-size:16px;font-weight:700;color:var(--red-700);padding-top:10px;border-top:1px solid var(--gray-100)">
            <span>Tổng cộng</span><span><?= fmtMoney((int)$order['total']) ?></span>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- RIGHT: Status + Actions -->
  <div style="display:flex;flex-direction:column;gap:18px">

    <!-- Thông tin đơn -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">Thông tin đơn hàng</div>
      </div>
      <div class="card-body">
        <div style="display:flex;flex-direction:column;gap:12px;font-size:13.5px">
          <div style="display:flex;justify-content:space-between">
            <span class="text-muted">Mã đơn hàng</span>
            <strong>#<?= $orderId ?></strong>
          </div>
          <div style="display:flex;justify-content:space-between">
            <span class="text-muted">Ngày đặt</span>
            <span><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></span>
          </div>
          <div style="display:flex;justify-content:space-between">
            <span class="text-muted">Cập nhật lần cuối</span>
            <span><?= date('d/m/Y H:i', strtotime($order['updated_at'] ?? $order['created_at'])) ?></span>
          </div>
          <div style="display:flex;justify-content:space-between">
            <span class="text-muted">Thanh toán</span>
            <span><?= $payLabels[$order['payment_method']] ?? $order['payment_method'] ?></span>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center">
            <span class="text-muted">Trạng thái</span>
            <span class="badge <?= $sc['class'] ?>"><?= $sc['label'] ?></span>
          </div>
        </div>
      </div>
    </div>

    <!-- Timeline -->
    <?php if ($order['status'] !== 'cancelled'): ?>
    <div class="card">
      <div class="card-header"><div class="card-title">Tiến trình đơn hàng</div></div>
      <div class="card-body">
        <div class="timeline">
          <?php
          $allSteps = [
            'pending'    => ['icon'=>'fa-solid fa-clipboard-list','label'=>'Chờ xác nhận'],
            'processing' => ['icon'=>'fa-solid fa-box-open',     'label'=>'Đang xử lý'],
            'shipping'   => ['icon'=>'fa-solid fa-truck-fast',   'label'=>'Đang giao'],
            'delivered'  => ['icon'=>'fa-solid fa-circle-check', 'label'=>'Đã giao'],
          ];
          
          // Xác định index của trạng thái hiện tại
          $statusKeys = array_keys($allSteps);
          $currentIdx = array_search($order['status'], $statusKeys);
          if ($order['status'] === 'cancelled') $currentIdx = -1;

          $i = 0;
          foreach ($allSteps as $key => $s):
            $isDone    = ($currentIdx > $i);
            $isCurrent = ($currentIdx === $i);
            $cls = $isDone ? 'done' : ($isCurrent ? 'current' : '');
          ?>
          <div class="timeline-step <?= $cls ?>">
            <div class="timeline-dot">
              <?php if ($isDone): ?>
                <i class="fa-solid fa-check" style="font-size:12px;color:white;margin-right:0"></i>
              <?php else: ?>
                <i class="<?= $s['icon'] ?>" style="font-size:12px;color:inherit;margin-right:0"></i>
              <?php endif; ?>
            </div>
            <div class="timeline-label"><?= $s['label'] ?></div>
          </div>
          <?php $i++; endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Cập nhật trạng thái -->
    <div class="card">
      <div class="card-header"><div class="card-title">Cập nhật trạng thái</div></div>
      <div class="card-body">
        <?php if ($order['status'] === 'delivered' || $order['status'] === 'cancelled'): ?>
          <p class="text-muted">Đơn hàng đã hoàn tất, không thể cập nhật thêm.</p>
        <?php else: ?>
          <form method="POST" action="order_action.php">
            <input type="hidden" name="back_url" value="order_detail">
            <div class="form-group" style="margin-bottom:14px">
              <label>Chọn trạng thái mới</label>
              <select name="_confirm_action" class="filter-select" style="width:100%">
                <?php
                $canSet = [];
                if ($order['status'] === 'pending')    $canSet = ['processing', 'cancelled'];
                if ($order['status'] === 'processing') $canSet = ['shipping', 'cancelled'];
                if ($order['status'] === 'shipping')   $canSet = ['delivered', 'cancelled'];

                foreach ($canSet as $s):
                  $lbl = $statusCfg[$s]['label'];
                ?>
                  <option value="<?= $s ?>"><?= $lbl ?></option>
                <?php endforeach; ?>
              </select>
            </div>            <input type="hidden" name="_confirm_id" value="<?= $orderId ?>">
            <button type="submit" class="btn btn-primary" style="width:100%">
              <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
              Cập nhật ngay
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- Nút hủy (nếu pending) -->
    <?php if ($order['status'] === 'pending'): ?>
    <button class="btn btn-danger" style="width:100%"
      onclick="openModal({
        title:'Hủy đơn hàng #<?= $orderId ?>?',
        desc:'Đơn hàng sẽ bị hủy và không thể khôi phục.',
        id:'<?= $orderId ?>',action:'cancelled',
        url:'order_action.php',btnText:'Hủy đơn',btnClass:'btn-danger'
      })">
      <svg viewBox="0 0 24 24" style="width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      Hủy đơn hàng
    </button>
    <?php endif; ?>

  </div>
</div>

<?php
$extraScript = '<script>document.getElementById("modalForm").addEventListener("submit",function(){var sel=document.querySelector("[name=_confirm_action]");var hidden=document.getElementById("modalAction");if(sel&&hidden)hidden.value=sel.value;});</script>';
include 'includes/footer.php';
?>
