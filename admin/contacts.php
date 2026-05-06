<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

// Đảm bảo bảng notification và cột user_id trong contacts tồn tại
$conn->query("CREATE TABLE IF NOT EXISTS `notifications` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `type`         ENUM('review_reply','contact_reply') NOT NULL,
  `reference_id` INT UNSIGNED NOT NULL,
  `message`      VARCHAR(500) NOT NULL DEFAULT '',
  `is_read`      TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_read` (`user_id`, `is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$chkCol = $conn->query("SHOW COLUMNS FROM contacts LIKE 'user_id'");
if ($chkCol && $chkCol->num_rows === 0) {
    $conn->query("ALTER TABLE contacts ADD COLUMN user_id INT UNSIGNED DEFAULT NULL");
}

$type = $_GET['tab'] ?? 'messages'; // messages hoặc reviews
$pageTitle  = ($type === 'reviews' ? 'Quản lý Đánh giá' : 'Phản hồi & Liên hệ');
$activePage = 'contacts';
$breadcrumb = [['label' => $pageTitle]];

$msg = $msgType = '';

/* ── Xử lý Action ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'delete_contact') {
        $cid = (int)$_POST['contact_id'];
        $st = $conn->prepare("DELETE FROM contacts WHERE id = ?");
        $st->bind_param('i', $cid);
        if ($st->execute()) {
            $msg = "Đã xóa tin nhắn liên hệ."; $msgType = "info";
        }
    }

    if ($action === 'delete_review') {
        $rid = (int)$_POST['review_id'];
        $st = $conn->prepare("DELETE FROM product_reviews WHERE id = ?");
        $st->bind_param('i', $rid);
        if ($st->execute()) {
            $msg = "Đã xóa đánh giá thành công."; $msgType = "info";
        }
    }

    if ($action === 'reply_contact') {
        $cid   = (int)$_POST['contact_id'];
        $reply = trim($_POST['admin_reply']);
        $st = $conn->prepare("UPDATE contacts SET admin_reply=?, replied_at=NOW(), status='replied' WHERE id=?");
        $st->bind_param('si', $reply, $cid);
        if ($st->execute()) {
            $msg = "Đã gửi phản hồi tin nhắn thành công."; $msgType = "success";
            // Tạo thông báo cho khách nếu có user_id
            $stUid = $conn->prepare("SELECT user_id FROM contacts WHERE id = ?");
            $stUid->bind_param('i', $cid);
            $stUid->execute();
            $cRow = $stUid->get_result()->fetch_assoc();
            if (!empty($cRow['user_id'])) {
                $short = mb_substr($reply, 0, 200);
                $stN = $conn->prepare(
                    "INSERT INTO notifications (user_id, type, reference_id, message) VALUES (?, 'contact_reply', ?, ?)"
                );
                $stN->bind_param('iis', $cRow['user_id'], $cid, $short);
                $stN->execute();
            }
        }
    }

    if ($action === 'reply_review') {
        $rid   = (int)$_POST['review_id'];
        $reply = trim($_POST['admin_reply']);
        $st = $conn->prepare("UPDATE product_reviews SET admin_reply=?, replied_at=NOW(), status_admin='replied' WHERE id=?");
        $st->bind_param('si', $reply, $rid);
        if ($st->execute()) {
            $msg = "Đã gửi phản hồi đánh giá thành công."; $msgType = "success";
            // Tạo thông báo cho khách hàng
            $stUid = $conn->prepare("SELECT user_id FROM product_reviews WHERE id = ?");
            $stUid->bind_param('i', $rid);
            $stUid->execute();
            $rRow = $stUid->get_result()->fetch_assoc();
            if (!empty($rRow['user_id'])) {
                $short = mb_substr($reply, 0, 200);
                $stN = $conn->prepare(
                    "INSERT INTO notifications (user_id, type, reference_id, message) VALUES (?, 'review_reply', ?, ?)"
                );
                $stN->bind_param('iis', $rRow['user_id'], $rid, $short);
                $stN->execute();
            }
        }
    }

    if ($action === 'update_contact_status') {
        $cid = (int)$_POST['contact_id'];
        $ns  = $_POST['new_status'];
        $st  = $conn->prepare("UPDATE contacts SET status=? WHERE id=?");
        $st->bind_param('si', $ns, $cid);
        $st->execute();
        $msg = "Cập nhật trạng thái liên hệ thành công."; $msgType = "success";
    }
}

include 'includes/header.php';
?>

<style>
.tab-nav-main { display: flex; gap: 20px; border-bottom: 2px solid #f3f4f6; margin-bottom: 24px; }
.tab-link { 
    padding: 12px 4px; font-weight: 700; color: #6b7280; text-decoration: none; 
    border-bottom: 3px solid transparent; transition: all .2s; font-size: 15px;
}
.tab-link:hover { color: #166534; }
.tab-link.active { color: #166534; border-color: #166534; }
.tab-badge {
    background: #dc2626; color: white; padding: 2px 6px; border-radius: 12px;
    font-size: 11px; font-weight: 700; margin-left: 4px; vertical-align: middle;
}
.review-card { 
    background: white; border: 1.5px solid #e5e7eb; border-radius: 12px; 
    padding: 20px; margin-bottom: 16px; display: flex; gap: 16px; align-items: flex-start;
}
.rev-stars { color: #f59e0b; font-size: 14px; margin-bottom: 4px; }
.rev-info { flex: 1; }
.rev-user { font-weight: 700; color: #111827; font-size: 14px; }
.rev-prod { font-size: 13px; color: #6b7280; margin-bottom: 10px; }
.rev-msg { font-size: 14.5px; color: #374151; line-height: 1.6; background: #f9fafb; padding: 12px 16px; border-radius: 8px; border-left: 3px solid #d1d5db; }
.rev-date { font-size: 12px; color: #9ca3af; margin-top: 8px; }

/* Reply Box Styles */
.admin-reply-box {
    margin-top: 12px;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 8px;
    padding: 12px 16px;
    position: relative;
}
.admin-reply-box::before {
    content: '';
    position: absolute;
    top: -6px; left: 24px;
    width: 10px; height: 10px;
    background: #f0fdf4;
    border-top: 1px solid #bbf7d0;
    border-left: 1px solid #bbf7d0;
    transform: rotate(45deg);
}
.admin-reply-header {
    font-size: 12.5px; font-weight: 700; color: #166534; margin-bottom: 6px;
    display: flex; align-items: center; gap: 6px;
}
.admin-reply-content {
    font-size: 14px; color: #15803d; line-height: 1.5;
}

/* Reply Form */
.reply-form { margin-top: 14px; display: none; animation: slideDown 0.3s ease; }
@keyframes slideDown { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } }
.reply-form textarea {
    width: 100%; border: 1.5px solid #d1d5db; border-radius: 8px; padding: 12px; font-family: inherit; font-size: 14px; outline: none; transition: border-color 0.2s; resize: vertical; min-height: 80px;
}
.reply-form textarea:focus { border-color: #166534; }
.reply-form-actions { display: flex; gap: 8px; margin-top: 8px; }

/* Toast */
.admin-toast {
  position: fixed; bottom: 24px; right: 24px; z-index: 9999;
  padding: 13px 20px; border-radius: 10px;
  font-size: 14px; font-weight: 600; color: #fff;
  box-shadow: 0 4px 20px rgba(0,0,0,.18);
  opacity: 0; transform: translateY(12px);
  transition: opacity .35s, transform .35s;
  pointer-events: none;
}
.admin-toast.show { opacity: 1; transform: translateY(0); }
.admin-toast-success { background: #166534; }
.admin-toast-error   { background: #dc2626; }
</style>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>" data-auto-dismiss>
  <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
  <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<div class="tab-nav-main">
    <a href="contacts.php?tab=messages" class="tab-link <?= $type === 'messages' ? 'active' : '' ?>">
        <i class="fa-solid fa-comment-dots"></i> Tin nhắn liên hệ
        <span class="tab-badge" id="tabBadgeMessages" style="<?= $__newMessages > 0 ? '' : 'display:none' ?>"><?= $__newMessages ?></span>
    </a>
    <a href="contacts.php?tab=reviews" class="tab-link <?= $type === 'reviews' ? 'active' : '' ?>">
        <i class="fa-solid fa-star"></i> Đánh giá sản phẩm
        <span class="tab-badge" id="tabBadgeReviews" style="<?= $__newReviews > 0 ? '' : 'display:none' ?>"><?= $__newReviews ?></span>
    </a>
</div>

<?php if ($type === 'messages'): 
    /* ── LOGIC TIN NHẮN ── */
    $contacts = $conn->query("SELECT * FROM contacts ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);
?>
    <div class="card-list">
        <?php if (empty($contacts)): ?>
            <div class="empty-state"><h3>Chưa có tin nhắn liên hệ</h3></div>
        <?php else: foreach($contacts as $c): ?>
            <div class="review-card">
                <div class="rev-info">
                    <div class="rev-user"><?= htmlspecialchars($c['name']) ?> <span style="font-weight:400;color:#6b7280;margin-left:4px">&lt;<?= htmlspecialchars($c['email']) ?>&gt;</span></div>
                    <div class="rev-prod">Chủ đề: <strong style="color:#374151"><?= htmlspecialchars($c['subject']) ?></strong></div>
                    <div class="rev-msg"><?= nl2br(htmlspecialchars($c['message'])) ?></div>
                    <div class="rev-date">Gửi lúc: <?= date('d/m/Y H:i', strtotime($c['created_at'])) ?></div>
                    
                    <?php if (!empty($c['admin_reply'])): ?>
                        <div class="admin-reply-box">
                            <div class="admin-reply-header">
                                <i class="fa-solid fa-headset"></i> Admin đã phản hồi (<?= date('d/m/Y H:i', strtotime($c['replied_at'])) ?>):
                            </div>
                            <div class="admin-reply-content"><?= nl2br(htmlspecialchars($c['admin_reply'])) ?></div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" id="reply-c-<?= $c['id'] ?>" class="reply-form">
                        <input type="hidden" name="action" value="reply_contact">
                        <input type="hidden" name="contact_id" value="<?= $c['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <textarea name="admin_reply" placeholder="Nhập nội dung phản hồi cho khách hàng này..." required><?= htmlspecialchars($c['admin_reply'] ?? '') ?></textarea>
                        <div class="reply-form-actions">
                            <button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-paper-plane"></i> <?= !empty($c['admin_reply']) ? 'Cập nhật phản hồi' : 'Gửi phản hồi' ?></button>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="document.getElementById('reply-c-<?= $c['id'] ?>').style.display='none'">Hủy</button>
                        </div>
                    </form>
                </div>
                <div class="rev-actions" style="display:flex; flex-direction:column; gap:8px;">
                    <button type="button" class="btn btn-sm btn-outline" style="border-width:1.5px; border-color:var(--green-600); color:var(--green-700);" onclick="document.getElementById('reply-c-<?= $c['id'] ?>').style.display='block'; document.getElementById('reply-c-<?= $c['id'] ?>').querySelector('textarea').focus();">
                        <i class="fa-solid fa-reply"></i> Phản hồi
                    </button>
                    <form method="POST" onsubmit="return confirm('Xóa liên hệ này?')">
                        <input type="hidden" name="action" value="delete_contact">
                        <input type="hidden" name="contact_id" value="<?= $c['id'] ?>">
                        <button class="btn btn-sm btn-danger" style="width:100%; border-width:1.5px; border-color:#fca5a5;"><i class="fa-solid fa-trash"></i> Xóa</button>
                    </form>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>

<?php else: 
    /* ── LOGIC ĐÁNH GIÁ ── */
    $reviews = $conn->query("
        SELECT r.*, u.username, u.email, p.name as product_name, p.image 
        FROM product_reviews r
        JOIN users u ON r.user_id = u.id
        JOIN products p ON r.product_id = p.id
        ORDER BY r.created_at DESC
    ")->fetch_all(MYSQLI_ASSOC);
?>
    <div class="card-list">
        <?php if (empty($reviews)): ?>
            <div class="empty-state">
                <i class="fa-solid fa-face-meh" style="font-size:40px;opacity:.2"></i>
                <h3>Chưa có đánh giá nào từ khách hàng</h3>
                <p>Khi khách hàng đánh giá đơn hàng, nội dung sẽ hiện ở đây.</p>
            </div>
        <?php else: foreach($reviews as $r): ?>
            <div class="review-card">
                <img src="../images/<?= htmlspecialchars($r['image'] ?: 'logo.png') ?>" style="width:50px;height:50px;border-radius:8px;object-fit:cover">
                <div class="rev-info">
                    <div class="rev-stars">
                        <?php for($i=1;$i<=5;$i++) echo '<i class="fa-'.($i<=$r['rating']?'solid':'regular').' fa-star"></i>'; ?>
                    </div>
                    <div class="rev-user"><?= htmlspecialchars($r['username']) ?> <span style="font-weight:400;color:#6b7280;margin-left:4px">&lt;<?= htmlspecialchars($r['email']) ?>&gt;</span></div>
                    <div class="rev-prod">Sản phẩm: <strong style="color:#374151"><?= htmlspecialchars($r['product_name']) ?></strong> (Đơn hàng #<?= $r['order_id'] ?? 'N/A' ?>)</div>
                    <div class="rev-msg"><?= nl2br(htmlspecialchars($r['comment'] ?: 'Không có nội dung nhận xét.')) ?></div>
                    <div class="rev-date">Đánh giá lúc: <?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></div>

                    <?php if (!empty($r['admin_reply'])): ?>
                        <div class="admin-reply-box">
                            <div class="admin-reply-header">
                                <i class="fa-solid fa-headset"></i> Admin đã phản hồi (<?= date('d/m/Y H:i', strtotime($r['replied_at'])) ?>):
                            </div>
                            <div class="admin-reply-content"><?= nl2br(htmlspecialchars($r['admin_reply'])) ?></div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" id="reply-r-<?= $r['id'] ?>" class="reply-form">
                        <input type="hidden" name="action" value="reply_review">
                        <input type="hidden" name="review_id" value="<?= $r['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <textarea name="admin_reply" placeholder="Nhập nội dung phản hồi cho đánh giá này..." required><?= htmlspecialchars($r['admin_reply'] ?? '') ?></textarea>
                        <div class="reply-form-actions">
                            <button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-paper-plane"></i> <?= !empty($r['admin_reply']) ? 'Cập nhật phản hồi' : 'Gửi phản hồi' ?></button>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="document.getElementById('reply-r-<?= $r['id'] ?>').style.display='none'">Hủy</button>
                        </div>
                    </form>
                </div>
                <div class="rev-actions" style="display:flex; flex-direction:column; gap:8px;">
                    <button type="button" class="btn btn-sm btn-outline" style="border-width:1.5px; border-color:var(--green-600); color:var(--green-700);" onclick="document.getElementById('reply-r-<?= $r['id'] ?>').style.display='block'; document.getElementById('reply-r-<?= $r['id'] ?>').querySelector('textarea').focus();">
                        <i class="fa-solid fa-reply"></i> Phản hồi
                    </button>
                    <form method="POST" onsubmit="return confirm('Xóa đánh giá này?')">
                        <input type="hidden" name="action" value="delete_review">
                        <input type="hidden" name="review_id" value="<?= $r['id'] ?>">
                        <button class="btn btn-sm btn-danger" style="width:100%; border-width:1.5px; border-color:#fca5a5;"><i class="fa-solid fa-trash"></i> Xóa</button>
                    </form>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
<?php endif; ?>

<script>
(function () {
  'use strict';

  /* ── Toast ── */
  function showToast(msg, type) {
    var t = document.createElement('div');
    t.className = 'admin-toast admin-toast-' + (type || 'success');
    t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('show'); });
    setTimeout(function () {
      t.classList.remove('show');
      setTimeout(function () { t.remove(); }, 400);
    }, 3000);
  }

  /* ── Badge ── */
  var badgeEl = document.getElementById('sidebarContactsBadge');
  function updateBadge(count) {
    if (!badgeEl) return;
    if (count <= 0) {
      badgeEl.style.display = 'none';
    } else {
      badgeEl.textContent    = count;
      badgeEl.style.display  = '';
    }
  }

  /* ── AJAX reply (intercept tất cả .reply-form) ── */
  document.querySelectorAll('.reply-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();

      var btn      = form.querySelector('[type=submit]');
      var textarea = form.querySelector('textarea');
      var origHtml = btn.innerHTML;

      btn.disabled  = true;
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Đang gửi...';

      fetch('api/contact_reply.php', { method: 'POST', body: new FormData(form) })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          btn.disabled  = false;
          btn.innerHTML = origHtml;

          if (!d.success) { showToast(d.message || 'Lỗi!', 'error'); return; }

          showToast(d.message, 'success');
          updateBadge(d.new_count);
          if (d.contacts_count !== undefined) {
              var tMsg = document.getElementById('tabBadgeMessages');
              if (tMsg) {
                  tMsg.textContent = d.contacts_count;
                  tMsg.style.display = d.contacts_count > 0 ? '' : 'none';
              }
          }
          if (d.reviews_count !== undefined) {
              var tRev = document.getElementById('tabBadgeReviews');
              if (tRev) {
                  tRev.textContent = d.reviews_count;
                  tRev.style.display = d.reviews_count > 0 ? '' : 'none';
              }
          }

          /* Cập nhật UI card */
          var revInfo   = form.closest('.rev-info');
          var replyBox  = revInfo ? revInfo.querySelector('.admin-reply-box') : null;
          var safeReply = (d.admin_reply || '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/\n/g, '<br>');
          var hd = '<i class="fa-solid fa-headset"></i> Admin đã phản hồi (' + (d.replied_at || '') + '):';

          if (replyBox) {
            replyBox.querySelector('.admin-reply-header').innerHTML = hd;
            replyBox.querySelector('.admin-reply-content').innerHTML = safeReply;
          } else if (revInfo) {
            var box = document.createElement('div');
            box.className = 'admin-reply-box';
            box.innerHTML = '<div class="admin-reply-header">' + hd + '</div>' +
                            '<div class="admin-reply-content">' + safeReply + '</div>';
            form.insertAdjacentElement('beforebegin', box);
          }

          /* Clear + ẩn form, cập nhật label nút */
          textarea.value = '';
          form.style.display = 'none';
          btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Cập nhật phản hồi';
        })
        .catch(function () {
          btn.disabled  = false;
          btn.innerHTML = origHtml;
          showToast('Lỗi kết nối. Vui lòng thử lại.', 'error');
        });
    });
  });

})();
</script>

<?php include 'includes/footer.php'; ?>
