(function () {
  var STORAGE_KEYS = {
    wishlist: 'moctra_wishlist',
    cart: 'moctra_cart'
  };

  function readStore(key) {
    try {
      var raw = window.localStorage.getItem(key);
      if (!raw) return {};
      var parsed = JSON.parse(raw);
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (err) {
      return {};
    }
  }

  function writeStore(key, value) {
    try {
      window.localStorage.setItem(key, JSON.stringify(value));
    } catch (err) {}
  }

  function readWishlist() {
    return readStore(STORAGE_KEYS.wishlist);
  }

  function writeWishlist(value) {
    writeStore(STORAGE_KEYS.wishlist, value);
  }

  function readCart() {
    return readStore(STORAGE_KEYS.cart);
  }

  function writeCart(value) {
    writeStore(STORAGE_KEYS.cart, value);
  }

  function clampBadgeCount(count) {
    return count > 99 ? '99+' : String(count);
  }

  function injectUiStyles() {
    if (document.getElementById('moctra-ui-styles')) return;

    var style = document.createElement('style');
    style.id = 'moctra-ui-styles';
    style.textContent = [
      '.badge.is-hidden{display:none;}',
      '.badge.is-bump{animation:moctraBadgePop .45s ease;}',
      '[data-nav-wishlist].is-pulse,[data-nav-cart].is-pulse{animation:moctraIconPulse .45s ease;}',
      '.pcard-wish.is-active,.btn-wish.is-active{color:#dc2626;background:#fef2f2;border-color:#fecaca;}',
      '.pcard-wish.is-active svg,.btn-wish.is-active svg{fill:#dc2626;}',
      '.moctra-fly-icon{position:fixed;z-index:3000;pointer-events:none;width:22px;height:22px;color:#dc2626;transform:translate(-50%,-50%);}',
      '.moctra-fly-icon svg{width:100%;height:100%;stroke:currentColor;fill:#fee2e2;stroke-width:2;filter:drop-shadow(0 8px 16px rgba(220,38,38,.28));}',
      '.moctra-toast-wrap{position:fixed;top:132px;right:24px;z-index:3200;display:flex;flex-direction:column;gap:10px;pointer-events:none;}',
      '.moctra-toast{background:#14532d;color:#fff;padding:12px 16px;border-radius:12px;box-shadow:0 14px 34px rgba(20,83,45,.24);font-size:14px;font-weight:600;opacity:0;transform:translateY(-10px);animation:moctraToastIn .22s ease forwards;}',
      '.moctra-toast.is-leaving{animation:moctraToastOut .22s ease forwards;}',
      '@keyframes moctraToastIn{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}',
      '@keyframes moctraToastOut{from{opacity:1;transform:translateY(0)}to{opacity:0;transform:translateY(-8px)}}',
      '@keyframes moctraBadgePop{0%{transform:scale(.82)}55%{transform:scale(1.18)}100%{transform:scale(1)}}',
      '@keyframes moctraIconPulse{0%{transform:scale(1)}50%{transform:scale(1.12)}100%{transform:scale(1)}}',
      '.notif-bell{position:relative;display:flex;align-items:center;}',
      '.notif-badge{pointer-events:none;}',
      '.notif-dropdown{display:none;position:absolute;top:calc(100% + 8px);right:-8px;width:320px;background:#fff;border:1px solid #f3f4f6;border-radius:14px;box-shadow:0 12px 40px rgba(0,0,0,.14);z-index:2100;overflow:hidden;}',
      '.notif-dropdown.open{display:block;animation:moctraToastIn .18s ease;}',
      '.notif-hdr{display:flex;align-items:center;justify-content:space-between;padding:14px 16px 10px;border-bottom:1px solid #f3f4f6;}',
      '.notif-hdr-title{font-size:14px;font-weight:700;color:#111827;}',
      '.notif-mark-all{font-size:12px;color:#166534;background:none;border:none;cursor:pointer;font-family:inherit;font-weight:600;padding:0;}',
      '.notif-mark-all:hover{text-decoration:underline;}',
      '.notif-list{max-height:340px;overflow-y:auto;}',
      '.notif-item{padding:12px 16px;border-bottom:1px solid #f9fafb;cursor:pointer;transition:background .15s;display:flex;gap:10px;align-items:flex-start;}',
      '.notif-item:hover{background:#f9fafb;}',
      '.notif-item.unread{background:#f0fdf4;}',
      '.notif-item.unread:hover{background:#dcfce7;}',
      '.notif-icon-wrap{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}',
      '.notif-icon-wrap.ic-voucher{background:#fef9c3;}',
      '.notif-icon-wrap.ic-review{background:#f0f9ff;}',
      '.notif-icon-wrap.ic-contact{background:#f0fdf4;}',
      '.notif-icon-wrap svg{width:16px;height:16px;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;fill:none;}',
      '.notif-icon-wrap.ic-voucher svg{stroke:#d97706;}',
      '.notif-icon-wrap.ic-review svg{stroke:#0284c7;}',
      '.notif-icon-wrap.ic-contact svg{stroke:#166534;}',
      '.notif-body{flex:1;min-width:0;}',
      '.notif-msg{font-size:13px;color:#374151;line-height:1.5;margin-bottom:3px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;}',
      '.notif-time{font-size:11px;color:#9ca3af;}',
      '.notif-dot{width:7px;height:7px;background:#166534;border-radius:50%;flex-shrink:0;margin-top:5px;}',
      '.notif-empty{padding:28px 16px;text-align:center;color:#9ca3af;font-size:13px;}',
      '.notif-footer{padding:10px 16px;text-align:center;border-top:1px solid #f3f4f6;}',
      '.notif-footer a{font-size:12.5px;color:#166534;font-weight:600;text-decoration:none;}',
      '.notif-footer a:hover{text-decoration:underline;}'
    ].join('');
    document.head.appendChild(style);
  }

  function getToastWrap() {
    var wrap = document.querySelector('.moctra-toast-wrap');
    if (wrap) return wrap;
    wrap = document.createElement('div');
    wrap.className = 'moctra-toast-wrap';
    document.body.appendChild(wrap);
    return wrap;
  }

  function showToast(message) {
    var toast = document.createElement('div');
    toast.className = 'moctra-toast';
    toast.textContent = message;
    getToastWrap().appendChild(toast);

    window.setTimeout(function () {
      toast.classList.add('is-leaving');
      window.setTimeout(function () {
        toast.remove();
      }, 220);
    }, 1800);
  }

  function ensureBadge(target, attrName) {
    if (!target) return null;

    var badge = target.querySelector('[' + attrName + ']');
    if (badge) return badge;

    badge = document.createElement('span');
    badge.className = 'badge is-hidden';
    badge.setAttribute(attrName, '');
    target.appendChild(badge);
    return badge;
  }

  function getWishlistCount() {
    return Object.keys(readWishlist()).length;
  }

  function getCartCount() {
    var cart = readCart();
    var total = 0;
    Object.keys(cart).forEach(function (key) {
      var item = cart[key];
      total += item && item.qty ? Number(item.qty) : 0;
    });
    return total;
  }

  function pulseBadge(badge, icon) {
    if (badge) {
      badge.classList.remove('is-bump');
      void badge.offsetWidth;
      badge.classList.add('is-bump');
    }
    if (icon) {
      icon.classList.remove('is-pulse');
      void icon.offsetWidth;
      icon.classList.add('is-pulse');
    }
  }

  function updateWishlistUi() {
    var count = getWishlistCount();
    var nav = document.querySelector('[data-nav-wishlist]');
    var badge = ensureBadge(nav, 'data-wishlist-badge');
    var wishlist = readWishlist();

    if (badge) {
      badge.textContent = count ? clampBadgeCount(count) : '';
      badge.classList.toggle('is-hidden', !count);
    }

    document.querySelectorAll('[data-action="wishlist"][data-product-id]').forEach(function (button) {
      var productId = button.getAttribute('data-product-id');
      var wished = !!wishlist[productId];
      button.classList.toggle('is-active', wished);
      if (button.classList.contains('btn-wish')) {
        var labelNode = button.childNodes[button.childNodes.length - 1];
        if (labelNode && labelNode.nodeType === 3) {
          labelNode.textContent = wished ? ' Đã thêm vào yêu thích' : ' Thêm vào yêu thích';
        }
      }
      if (button.classList.contains('pcard-wish')) {
        button.setAttribute('aria-pressed', wished ? 'true' : 'false');
      }
    });
  }

  function updateCartUi() {
    var count = getCartCount();
    var nav = document.querySelector('[data-nav-cart]');
    var badge = ensureBadge(nav, 'data-cart-badge');

    if (badge) {
      badge.textContent = count ? clampBadgeCount(count) : '';
      badge.classList.toggle('is-hidden', !count);
    }
  }

  function extractQty(button) {
    var qtySelector = button.getAttribute('data-qty-input');
    if (qtySelector) {
      var qtyInput = document.querySelector(qtySelector);
      if (qtyInput) {
        var qtyValue = parseInt(qtyInput.value, 10);
        return Number.isFinite(qtyValue) && qtyValue > 0 ? qtyValue : 1;
      }
    }
    return 1;
  }

  function getProductPayload(button, qty) {
    var card = button.closest('.pcard');
    var image = button.getAttribute('data-product-image');
    var url = button.getAttribute('data-product-url');
    var price = parseInt(button.getAttribute('data-product-price'), 10);

    if (!image) {
      var img = (card || document).querySelector('img');
      image = img ? img.getAttribute('src') : '';
    }

    if (!url && card && card.getAttribute('href')) {
      url = card.getAttribute('href');
    }

    if (!Number.isFinite(price)) {
      var priceNode = (card || document).querySelector('.price-new, .detail-price');
      if (priceNode) {
        price = parseInt(String(priceNode.textContent).replace(/[^\d]/g, ''), 10);
      }
    }

    return {
      id: button.getAttribute('data-product-id'),
      name: button.getAttribute('data-product-name') || '',
      price: Number.isFinite(price) ? price : 0,
      image: image || '',
      url: url || '',
      qty: qty
    };
  }

  function animateToTarget(source, target, type) {
    if (!source || !target) return;

    var sourceRect = source.getBoundingClientRect();
    var targetRect = target.getBoundingClientRect();
    var flyIcon = document.createElement('div');
    flyIcon.className = 'moctra-fly-icon';
    flyIcon.innerHTML = type === 'cart'
      ? '<svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>'
      : '<svg viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>';
    document.body.appendChild(flyIcon);

    var startX = sourceRect.left + sourceRect.width / 2;
    var startY = sourceRect.top + sourceRect.height / 2;
    var endX = targetRect.left + targetRect.width / 2;
    var endY = targetRect.top + targetRect.height / 2;
    var ctrlX = startX + (endX - startX) / 2;
    var ctrlY = Math.min(startY, endY) - 90;
    var startTime = null;
    var duration = 650;

    function step(timestamp) {
      if (!startTime) startTime = timestamp;
      var progress = Math.min((timestamp - startTime) / duration, 1);
      var curve = 1 - Math.pow(1 - progress, 3);
      var x = Math.pow(1 - curve, 2) * startX + 2 * (1 - curve) * curve * ctrlX + Math.pow(curve, 2) * endX;
      var y = Math.pow(1 - curve, 2) * startY + 2 * (1 - curve) * curve * ctrlY + Math.pow(curve, 2) * endY;
      var scale = 1 - curve * 0.35;
      var rotate = type === 'cart' ? curve * 18 : curve * 8;

      flyIcon.style.left = x + 'px';
      flyIcon.style.top = y + 'px';
      flyIcon.style.opacity = String(1 - curve * 0.2);
      flyIcon.style.transform = 'translate(-50%,-50%) scale(' + scale + ') rotate(' + rotate + 'deg)';

      if (progress < 1) {
        window.requestAnimationFrame(step);
      } else {
        flyIcon.remove();
      }
    }

    flyIcon.style.left = startX + 'px';
    flyIcon.style.top = startY + 'px';
    window.requestAnimationFrame(step);
  }

  function addToWishlist(button) {
    var productId = button.getAttribute('data-product-id');
    if (!productId) return;

    var wishlist = readWishlist();
    var target = document.querySelector('[data-nav-wishlist]');
    var isRemoving = !!wishlist[productId];

    if (isRemoving) {
      delete wishlist[productId];
      writeWishlist(wishlist);
      updateWishlistUi();
      showToast('Đã bỏ khỏi danh sách yêu thích');
      pulseBadge(ensureBadge(target, 'data-wishlist-badge'), target);
      return false;
    }

    wishlist[productId] = getProductPayload(button, 1);
    writeWishlist(wishlist);
    updateWishlistUi();
    showToast('Đã thêm vào danh sách yêu thích');
    animateToTarget(button, target, 'wishlist');
    pulseBadge(ensureBadge(target, 'data-wishlist-badge'), target);
    return true;
  }

  function addToCart(button) {
    var productId = button.getAttribute('data-product-id');
    if (!productId) return;

    var qty = extractQty(button);
    var cart = readCart();
    var payload = getProductPayload(button, qty);
    var current = cart[productId] && cart[productId].qty ? Number(cart[productId].qty) : 0;
    payload.qty = current + qty;
    cart[productId] = payload;
    writeCart(cart);
    updateCartUi();

    if (!button._skelBusy) {
      button._skelBusy = true;
      var origHtml = button.innerHTML;
      button.innerHTML = '<svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round"><polyline points="20 6 9 17 4 12"/></svg> Đã thêm!';
      setTimeout(function () { button.innerHTML = origHtml; button._skelBusy = false; }, 1500);
    }

    var target = document.querySelector('[data-nav-cart]');
    animateToTarget(button, target, 'cart');
    pulseBadge(ensureBadge(target, 'data-cart-badge'), target);
  }

  function setCartItemQty(productId, qty) {
    var cart = readCart();
    if (!cart[productId]) return;
    if (qty <= 0) {
      delete cart[productId];
    } else {
      cart[productId].qty = qty;
    }
    writeCart(cart);
    updateCartUi();
  }

  function removeCartItem(productId) {
    var cart = readCart();
    if (!cart[productId]) return;
    delete cart[productId];
    writeCart(cart);
    updateCartUi();
  }

  function removeWishlistItem(productId) {
    var wishlist = readWishlist();
    if (!wishlist[productId]) return;
    delete wishlist[productId];
    writeWishlist(wishlist);
    updateWishlistUi();
  }

  function initActionButtons() {
    document.querySelectorAll('[data-action="wishlist"]').forEach(function (button) {
      if (button.dataset.moctraBound === '1') return;
      button.dataset.moctraBound = '1';
      button.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        addToWishlist(button);
      });
    });

    document.querySelectorAll('[data-action="cart"]').forEach(function (button) {
      if (button.dataset.moctraBound === '1') return;
      button.dataset.moctraBound = '1';
      button.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        addToCart(button);
      });
    });
  }

  function initHomeNav() {
    var body = document.body;
    if (!body || !document.getElementById('products-section')) return;

    var navbar = document.getElementById('navbar');
    var homeLink = document.querySelector('.nav-link[href="#top"]');
    var navConfig = [
      { id: 'top', link: homeLink },
      { id: 'products-section', link: document.getElementById('navProducts') },
      { id: 'about', link: document.querySelector('.nav-link[href="#about"]') },
      { id: 'contact', link: document.querySelector('.nav-link[href="#contact"]') }
    ].filter(function (item) { return item.link; });
    var clickLockUntil = 0;

    function navOffset() {
      return navbar ? navbar.offsetHeight : 90;
    }

    function setActive(link) {
      document.querySelectorAll('.nav-menu .nav-link').forEach(function (item) {
        item.classList.remove('active');
      });
      if (link) link.classList.add('active');
    }

    function resolveTargetTop(id) {
      if (id === 'top') return 0;
      var el = document.getElementById(id);
      if (!el) return 0;
      return Math.max(0, el.getBoundingClientRect().top + window.scrollY - navOffset() + 2);
    }

    navConfig.forEach(function (item) {
      item.link.addEventListener('click', function () {
        clickLockUntil = Date.now() + 700;
        setActive(item.link);
      });
    });

    function syncByScroll() {
      if (Date.now() < clickLockUntil) return;

      var current = homeLink;
      var checkpoint = window.scrollY + navOffset() + 24;

      navConfig.forEach(function (item) {
        if (item.id === 'top') return;
        var el = document.getElementById(item.id);
        if (el && el.offsetTop <= checkpoint) current = item.link;
      });

      setActive(current);
    }

    window.addEventListener('scroll', syncByScroll, { passive: true });
    window.addEventListener('load', syncByScroll);

    window.smoothTo = function (id, e) {
      if (e) e.preventDefault();
      var link = document.querySelector('.nav-menu .nav-link[href="#' + id + '"]') || (id === 'top' ? homeLink : null);
      if (link) {
        clickLockUntil = Date.now() + 700;
        setActive(link);
      }
      window.scrollTo({
        top: resolveTargetTop(id),
        behavior: 'smooth'
      });
    };
  }

  function syncWishlistFromServer() {
    if (!window.fetch) return;
    fetch('wishlist_sync.php')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.sync || !data.items || !data.items.length) return;
        var wishlist = readWishlist();
        data.items.forEach(function (item) {
          if (!wishlist[item.id]) {
            wishlist[item.id] = item;
          }
        });
        writeWishlist(wishlist);
        updateWishlistUi();
      })
      .catch(function () {});
  }

  /* ── Notification Bell ── */
  function initNotificationBell() {
    var navRight = document.querySelector('.nav-right');
    if (!navRight || !document.querySelector('.user-btn')) return; // not a customer page
    if (document.getElementById('notifWrap') || document.getElementById('notifBell')) return; // already injected by page

    // Build bell HTML
    var bell = document.createElement('div');
    bell.className = 'notif-bell';
    bell.id = 'notifBell';
    bell.innerHTML = [
      '<a href="#" class="nav-icon-btn notif-btn" id="notifBellBtn" aria-label="Thông báo" onclick="event.preventDefault()">',
        '<svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
        '<span class="badge notif-badge is-hidden" id="notifBadge"></span>',
      '</a>',
      '<div class="notif-dropdown" id="notifDropdown">',
        '<div class="notif-hdr">',
          '<span class="notif-hdr-title">Thông báo</span>',
          '<button class="notif-mark-all" id="notifMarkAll">Đánh dấu tất cả đã đọc</button>',
        '</div>',
        '<div class="notif-list" id="notifList">',
          '<div class="notif-empty">Đang tải...</div>',
        '</div>',
        '<div class="notif-footer"><a href="my_vouchers.php">Xem Kho Voucher →</a></div>',
      '</div>'
    ].join('');

    // Insert before user-dropdown
    var userDropdown = navRight.querySelector('.user-dropdown');
    navRight.insertBefore(bell, userDropdown);

    var bellBtn   = document.getElementById('notifBellBtn');
    var dropdown  = document.getElementById('notifDropdown');
    var badge     = document.getElementById('notifBadge');
    var list      = document.getElementById('notifList');
    var markAllBtn = document.getElementById('notifMarkAll');
    var isOpen    = false;
    var loaded    = false;

    function getCsrf() {
      var meta = document.querySelector('meta[name="csrf-token"]');
      if (meta) return meta.getAttribute('content');
      var inp = document.querySelector('input[name="_csrf"], input[name="csrf_token"]');
      return inp ? inp.value : '';
    }

    function timeAgo(dateStr) {
      var diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
      if (diff < 60)     return 'Vừa xong';
      if (diff < 3600)   return Math.floor(diff / 60) + ' phút trước';
      if (diff < 86400)  return Math.floor(diff / 3600) + ' giờ trước';
      if (diff < 604800) return Math.floor(diff / 86400) + ' ngày trước';
      return new Date(dateStr).toLocaleDateString('vi-VN');
    }

    function iconForType(type) {
      if (type === 'voucher_gifted') {
        return '<div class="notif-icon-wrap ic-voucher"><svg viewBox="0 0 24 24"><path d="M20 12v10H4V12"/><path d="M22 7H2v5h20V7z"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg></div>';
      }
      if (type === 'review_reply') {
        return '<div class="notif-icon-wrap ic-review"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>';
      }
      return '<div class="notif-icon-wrap ic-contact"><svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.64 1h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.64a16 16 0 0 0 6.29 6.29l.96-.96a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg></div>';
      }

    function renderNotifications(notifications) {
      if (!notifications || !notifications.length) {
        list.innerHTML = '<div class="notif-empty">Không có thông báo nào.</div>';
        return;
      }
      list.innerHTML = notifications.map(function(n) {
        var unreadCls = n.is_read ? '' : ' unread';
        var msg = n.message || n.admin_reply || '';
        return [
          '<div class="notif-item' + unreadCls + '" data-id="' + n.id + '">',
            iconForType(n.type),
            '<div class="notif-body">',
              '<div class="notif-msg">' + msg + '</div>',
              '<div class="notif-time">' + timeAgo(n.created_at) + '</div>',
            '</div>',
            n.is_read ? '' : '<div class="notif-dot"></div>',
          '</div>'
        ].join('');
      }).join('');

      // Mark individual as read on click
      list.querySelectorAll('.notif-item').forEach(function(el) {
        el.addEventListener('click', function() {
          var id = this.dataset.id;
          this.classList.remove('unread');
          this.querySelector('.notif-dot') && this.querySelector('.notif-dot').remove();
          fetch('api/notifications.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=mark_read&id=' + id + '&csrf_token=' + encodeURIComponent(getCsrf())
          }).catch(function(){});
        });
      });
    }

    function loadNotifications() {
      fetch('api/notifications.php')
        .then(function(r){ return r.json(); })
        .then(function(d) {
          if (!d.success) return;
          loaded = true;
          renderNotifications(d.notifications);
          updateBadge(d.unread_count);
        })
        .catch(function(){
          list.innerHTML = '<div class="notif-empty">Không thể tải thông báo.</div>';
        });
    }

    function updateBadge(count) {
      if (count > 0) {
        badge.textContent = count > 99 ? '99+' : String(count);
        badge.classList.remove('is-hidden');
      } else {
        badge.textContent = '';
        badge.classList.add('is-hidden');
      }
    }

    // Toggle dropdown
    bellBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      isOpen = !isOpen;
      dropdown.classList.toggle('open', isOpen);
      if (isOpen && !loaded) loadNotifications();
    });

    // Mark all as read
    markAllBtn.addEventListener('click', function() {
      list.querySelectorAll('.notif-item.unread').forEach(function(el) {
        el.classList.remove('unread');
        el.querySelector('.notif-dot') && el.querySelector('.notif-dot').remove();
      });
      updateBadge(0);
      fetch('api/notifications.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=mark_all_read&csrf_token=' + encodeURIComponent(getCsrf())
      }).catch(function(){});
    });

    // Close on outside click
    document.addEventListener('click', function() {
      if (isOpen) { isOpen = false; dropdown.classList.remove('open'); }
    });
    bell.addEventListener('click', function(e){ e.stopPropagation(); });

    // SSE for real-time badge updates
    if (window.EventSource) {
      var sseBase = window.location.pathname.replace(/\/[^/]*$/, '') + '/api/sse_notifications.php';
      var sse = new EventSource(sseBase);
      sse.onmessage = function(e) {
        try {
          var d = JSON.parse(e.data);
          if (typeof d.unread_count === 'number') {
            updateBadge(d.unread_count);
            if (isOpen) loadNotifications(); // refresh list if open
          }
        } catch (_) {}
      };
      sse.onerror = function() { sse.close(); };
    }
  }

  function init() {
    injectUiStyles();
    initActionButtons();
    initHomeNav();
    updateWishlistUi();
    updateCartUi();
    syncWishlistFromServer();
    initNotificationBell();

    // User Dropdown toggle logic
    var userBtn = document.getElementById('userBtn');
    if (userBtn) {
      userBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        var dropdown = this.closest('.user-dropdown');
        if (dropdown) dropdown.classList.toggle('active');
      });
    }
    document.addEventListener('click', function () {
      document.querySelectorAll('.user-dropdown.active').forEach(function (d) {
        d.classList.remove('active');
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.toggleWish = function (button) {
    if (!button) return;
    addToWishlist(button);
  };

  window.showToast = showToast;

  window.MocTraStore = {
    readCart: readCart,
    writeCart: function (cart) {
      writeCart(cart || {});
      updateCartUi();
    },
    setCartItemQty: setCartItemQty,
    removeCartItem: removeCartItem,
    readWishlist: readWishlist,
    writeWishlist: function (wishlist) {
      writeWishlist(wishlist || {});
      updateWishlistUi();
    },
    removeWishlistItem: removeWishlistItem,
    updateWishlistUi: updateWishlistUi,
    updateCartUi: updateCartUi,
    formatMoney: function (value) {
      var amount = Number(value) || 0;
      return amount.toLocaleString('vi-VN') + 'đ';
    }
  };
})();
