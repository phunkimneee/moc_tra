  </div><!-- /page-body -->
</main>
</div><!-- /admin-layout -->

<!-- ══ MODAL OVERLAY (dùng chung) ══ -->
<div class="modal-overlay" id="globalModal">
  <div class="modal">
    <div class="modal-title" id="modalTitle"></div>
    <div class="modal-desc" id="modalDesc"></div>
    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="closeModal()">Hủy</button>
      <form id="modalForm" method="POST" style="display:inline">
        <input type="hidden" name="_confirm_id" id="modalId">
        <input type="hidden" name="_confirm_action" id="modalAction">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <button type="submit" class="btn btn-danger" id="modalConfirmBtn">Xác nhận</button>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  /* Modal helper */
  window.openModal = function(opts) {
    document.getElementById('modalTitle').textContent = opts.title || 'Xác nhận';
    document.getElementById('modalDesc').textContent  = opts.desc  || '';
    document.getElementById('modalId').value          = opts.id    || '';
    document.getElementById('modalAction').value      = opts.action|| '';
    document.getElementById('modalForm').action       = opts.url   || '';
    var btn = document.getElementById('modalConfirmBtn');
    btn.textContent = opts.btnText || 'Xác nhận';
    btn.className   = 'btn ' + (opts.btnClass || 'btn-danger');
    document.getElementById('globalModal').classList.add('open');
  };
  window.closeModal = function() {
    document.getElementById('globalModal').classList.remove('open');
  };
  document.getElementById('globalModal').addEventListener('click', function(e){
    if (e.target === this) closeModal();
  });

  /* Auto-dismiss alerts */
  document.querySelectorAll('.alert[data-auto-dismiss]').forEach(function(el){
    setTimeout(function(){ el.style.opacity='0'; el.style.transition='opacity .4s'; setTimeout(function(){ el.remove(); }, 400); }, 3500);
  });
})();
</script>
<script src="js/table-enhance.js"></script>
<?= $extraScript ?? '' ?>
</body>
</html>
