# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Running the Project

No build step required. Start XAMPP, ensure Apache and MySQL are running, then access:
- **Frontend**: `http://localhost/moctra/`
- **Admin**: `http://localhost/moctra/admin/dashboard.php`
- **phpMyAdmin**: `http://localhost/phpmyadmin/` (DB: `teashop`)

**Database setup** (first time):
1. Create DB `teashop` in phpMyAdmin
2. Import `database/schema.sql`
3. Default admin: `admin` / `admin123`

**Email**: Set `MAIL_DEV = true` in `config/mailer.php` (default) to write emails to `/logs/` instead of sending.

## Architecture

**File-based routing** — no router framework. Each `.php` file at the root is a page. URLs map directly to files (`/products.php?category=tra-xanh&sort=price_asc`).

**Two separate include systems**:
- Frontend pages are **self-contained** — each page embeds its own HTML structure, fetches categories inline, and checks `$_SESSION` directly. There is no shared header/footer template for frontend.
- Admin pages use a **template system**: every admin page starts with `require_once 'includes/auth.php'` then wraps content between `include 'includes/header.php'` and `include 'includes/footer.php'`.

**Cart is hybrid** — cart data lives in `localStorage` (via `js/moctra-functions.js`) for UI badges/animations, but `cart.php` reads from `$_SESSION['cart']`. The `wishlist_sync.php` endpoint bridges localStorage → session on login.

**Admin API** — `/admin/api/` contains JSON endpoints (`dashboard.php`, `update_stock.php`) with CORS headers, suggesting a future headless admin frontend.

**Inventory changes** must always log to the `inventory_history` table (not just update `products.stock`). See `admin/api/update_stock.php` for the pattern.

## Key Conventions

**PHP page structure** (mandatory order):
1. `session_name('MOCTRA_SESSION'); session_start();`
2. `require_once 'config/db.php';`
3. Auth/role check
4. Handle POST logic (with CSRF validation)
5. Fetch data for view
6. Output HTML

**Security requirements** (enforce on every change):
- All SQL: `$conn->prepare()` + `bind_param()` — never concatenate user input into queries
- All HTML output: wrap in `htmlspecialchars()`
- All POST forms: include `<input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">`

**Constants** (from `config/constants.php`): `SHIP_FEE` (22,000 VND), `FREE_SHIP_THRESHOLD` (300,000 VND), `MAX_IMAGE_SIZE`, `LOGIN_LOCK_THRESHOLD` (4 failed attempts).

## Database Notes

- `products.price` = current selling price; `products.price_old` = original price (shown struck-through). Always compute totals from `price`, never `price_old`.
- `orders.status` ENUM: `pending` → `processing` → `shipping` → `completed` (also `cancelled`).
- `order_items` stores `product_name` and `price` as a snapshot at order time — don't join back to `products` for historical order pricing.
- `users.role`: `'admin'` or `'customer'`. Admin check: `$_SESSION['role'] === 'admin'`.
