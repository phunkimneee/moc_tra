/* table-enhance.js — Column sort / filter / hide for admin tables with data-enhance */
(function () {
  var styleEl = document.createElement('style');
  styleEl.textContent = [
    '.th-enhance { position:relative; }',
    '.th-menu-btn {',
    '  position:absolute; right:4px; top:50%; transform:translateY(-50%);',
    '  background:none; border:none; cursor:pointer;',
    '  width:20px; height:20px; padding:0;',
    '  display:inline-flex; align-items:center; justify-content:center;',
    '  color:var(--gray-400,#9ca3af); border-radius:4px;',
    '  opacity:0; transition:opacity .15s, background .12s;',
    '}',
    '.th-enhance:hover .th-menu-btn { opacity:1; }',
    '.th-menu-btn:hover { background:var(--gray-200,#e5e7eb); color:var(--gray-700,#374151); }',
    '.th-menu-btn.te-active { opacity:1; color:var(--green-700,#15803d); }',
    '.col-menu {',
    '  position:absolute; top:calc(100% + 4px); right:0; z-index:1100;',
    '  background:#fff; border:1px solid var(--gray-200,#e5e7eb);',
    '  border-radius:8px; box-shadow:0 4px 20px rgba(0,0,0,.13);',
    '  min-width:190px; padding:4px 0; display:none;',
    '}',
    '.col-menu.te-open { display:block; }',
    '.col-menu-item {',
    '  display:flex; align-items:center; gap:8px;',
    '  padding:8px 14px; font-size:13px; cursor:pointer;',
    '  color:var(--gray-700,#374151); border:none; background:none;',
    '  width:100%; text-align:left; font-family:inherit;',
    '}',
    '.col-menu-item:hover { background:var(--gray-50,#f9fafb); }',
    '.col-menu-item.te-danger { color:#dc2626; }',
    '.col-menu-sep { height:1px; background:var(--gray-100,#f3f4f6); margin:4px 0; }',
    '.col-filter-wrap { padding:6px 10px 8px; }',
    '.col-filter-wrap input {',
    '  width:100%; padding:5px 8px; font-size:12px;',
    '  border:1.5px solid var(--gray-300,#d1d5db); border-radius:6px;',
    '  box-sizing:border-box; outline:none; font-family:inherit;',
    '}',
    '.col-filter-wrap input:focus { border-color:var(--green-600,#16a34a); }',
    '.te-col-hidden { display:none!important; }',
    '.te-show-bar {',
    '  display:none; align-items:center; gap:10px;',
    '  padding:8px 16px; background:#fffbeb;',
    '  border-bottom:1px solid #fde68a; font-size:13px; color:#92400e;',
    '}',
    '.te-show-bar.te-visible { display:flex; }',
  ].join('');
  document.head.appendChild(styleEl);

  function fmtTxt(td) {
    return (td.textContent || '').trim();
  }

  function sortTable(table, colIdx, dir) {
    var tbody = table.querySelector('tbody');
    if (!tbody) return;
    var rows = Array.from(tbody.querySelectorAll('tr'));
    rows.sort(function (a, b) {
      var aTds = a.querySelectorAll('td');
      var bTds = b.querySelectorAll('td');
      var aT = aTds[colIdx] ? fmtTxt(aTds[colIdx]) : '';
      var bT = bTds[colIdx] ? fmtTxt(bTds[colIdx]) : '';
      var aN = parseFloat(aT.replace(/[^\d,.\-]/g, '').replace(/\./g, '').replace(',', '.'));
      var bN = parseFloat(bT.replace(/[^\d,.\-]/g, '').replace(/\./g, '').replace(',', '.'));
      if (!isNaN(aN) && !isNaN(bN)) return dir * (aN - bN);
      return dir * aT.localeCompare(bT, 'vi');
    });
    rows.forEach(function (r) { tbody.appendChild(r); });
  }

  function applyFilters(table, filters) {
    var tbody = table.querySelector('tbody');
    if (!tbody) return;
    tbody.querySelectorAll('tr').forEach(function (row) {
      var tds = row.querySelectorAll('td');
      var show = true;
      Object.keys(filters).forEach(function (ci) {
        var f = filters[+ci];
        if (!f) return;
        var td = tds[+ci];
        if (td && fmtTxt(td).toLowerCase().indexOf(f) === -1) show = false;
      });
      row.style.display = show ? '' : 'none';
    });
  }

  function setColHidden(table, thead, ci, hide) {
    var ths = thead.querySelectorAll('th');
    if (ths[ci]) ths[ci].classList.toggle('te-col-hidden', hide);
    table.querySelectorAll('tbody tr').forEach(function (row) {
      var tds = row.querySelectorAll('td');
      if (tds[ci]) tds[ci].classList.toggle('te-col-hidden', hide);
    });
  }

  function closeAllMenus() {
    document.querySelectorAll('.col-menu.te-open').forEach(function (m) { m.classList.remove('te-open'); });
    document.querySelectorAll('.th-menu-btn.te-active').forEach(function (b) { b.classList.remove('te-active'); });
  }

  function enhanceTable(table) {
    var thead = table.querySelector('thead tr');
    if (!thead) return;
    var ths = thead.querySelectorAll('th');
    var filters = {};
    var hidden = new Set();

    /* Show-all bar */
    var wrap = table.closest('.table-wrap') || table.parentElement;
    var showBar = document.createElement('div');
    showBar.className = 'te-show-bar';
    showBar.innerHTML = '<span>Đang ẩn một số cột</span>'
      + '<button type="button" class="btn btn-sm btn-secondary">Hiện tất cả</button>';
    wrap.insertBefore(showBar, wrap.firstChild);
    showBar.querySelector('button').addEventListener('click', function () {
      hidden.forEach(function (ci) { setColHidden(table, thead, ci, false); });
      hidden.clear();
      showBar.classList.remove('te-visible');
    });

    ths.forEach(function (th, ci) {
      if (th.textContent.trim() === 'Thao tác' || th.hasAttribute('data-no-enhance')) return;

      th.classList.add('th-enhance');
      th.style.paddingRight = '22px';

      /* ⋮ button */
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'th-menu-btn';
      btn.innerHTML = '<svg width="10" height="9" viewBox="0 0 10 9" fill="currentColor">'
        + '<rect width="10" height="1.5" rx="0.75"/>'
        + '<rect x="2" y="3.75" width="6" height="1.5" rx="0.75"/>'
        + '<rect x="4" y="7.5" width="2" height="1.5" rx="0.75"/>'
        + '</svg>';
      btn.setAttribute('aria-label', 'Tùy chọn cột');
      th.appendChild(btn);

      /* dropdown menu */
      var menu = document.createElement('div');
      menu.className = 'col-menu';
      menu.innerHTML =
        '<button class="col-menu-item" data-act="asc">&#9650; Sắp xếp tăng dần</button>'
        + '<button class="col-menu-item" data-act="desc">&#9660; Sắp xếp giảm dần</button>'
        + '<div class="col-menu-sep"></div>'
        + '<div class="col-filter-wrap">'
        +   '<input type="text" placeholder="&#128269; Lọc cột này..." data-ci="' + ci + '">'
        + '</div>'
        + '<div class="col-menu-sep"></div>'
        + '<button class="col-menu-item te-danger" data-act="hide">&#10005; Ẩn cột này</button>';
      th.appendChild(menu);

      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var isOpen = menu.classList.contains('te-open');
        closeAllMenus();
        if (!isOpen) {
          menu.classList.add('te-open');
          btn.classList.add('te-active');
          /* flip left if clipped on right edge */
          var rect = menu.getBoundingClientRect();
          if (rect.right > window.innerWidth - 8) {
            menu.style.right = '0'; menu.style.left = 'auto';
          }
        }
      });

      menu.querySelectorAll('[data-act]').forEach(function (item) {
        item.addEventListener('click', function (e) {
          e.stopPropagation();
          var act = this.getAttribute('data-act');
          if (act === 'asc') sortTable(table, ci, 1);
          else if (act === 'desc') sortTable(table, ci, -1);
          else if (act === 'hide') {
            setColHidden(table, thead, ci, true);
            hidden.add(ci);
            showBar.classList.add('te-visible');
          }
          closeAllMenus();
        });
      });

      var filterInput = menu.querySelector('input[data-ci]');
      filterInput.value = filters[ci] || '';
      filterInput.addEventListener('input', function () {
        filters[ci] = this.value.trim().toLowerCase();
        applyFilters(table, filters);
      });
      filterInput.addEventListener('click', function (e) { e.stopPropagation(); });
    });
  }

  document.addEventListener('click', closeAllMenus);

  document.querySelectorAll('table[data-enhance]').forEach(enhanceTable);
})();
