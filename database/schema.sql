-- ============================================================
--  Moctra Teashop — Schema đầy đủ (All-in-one)
--  Database: teashop  |  Engine: InnoDB  |  Charset: utf8mb4
--  Tạo lại: DROP database rồi chạy file này (không cần migrate thêm)
--  Cập nhật lần cuối: 2026-04-30
-- ============================================================

CREATE DATABASE IF NOT EXISTS `teashop`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `teashop`;

-- ------------------------------------------------------------
-- 1. users
-- ------------------------------------------------------------
CREATE TABLE `users` (
  `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `username`        VARCHAR(30)      NOT NULL,
  `password`        VARCHAR(255)     NOT NULL,
  `email`           VARCHAR(150)     NOT NULL DEFAULT '',
  `phone`           VARCHAR(20)      NOT NULL DEFAULT '',
  `address`         TEXT,
  `role`            ENUM('customer','admin') NOT NULL DEFAULT 'customer',
  `is_locked`       TINYINT(1)       NOT NULL DEFAULT 0,
  `failed_attempts` INT              NOT NULL DEFAULT 0,
  `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tài khoản admin mặc định (password: admin123)
INSERT INTO `users` (`username`, `password`, `email`, `phone`, `role`) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@moctra.vn', '0900000000', 'admin');

-- ------------------------------------------------------------
-- 2. categories
-- ------------------------------------------------------------
CREATE TABLE `categories` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(100) NOT NULL,
  `slug`       VARCHAR(100) NOT NULL,
  `icon`       VARCHAR(50)  NOT NULL DEFAULT 'fa-solid fa-mug-hot',
  `sort_order` INT          NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `categories` (`name`, `slug`, `icon`, `sort_order`) VALUES
('Trà xanh',     'tra-xanh',     'fa-solid fa-leaf',        1),
('Trà oolong',   'tra-oolong',   'fa-solid fa-seedling',    2),
('Trà đen',      'tra-den',      'fa-solid fa-mug-hot',     3),
('Trà thảo mộc', 'tra-thao-moc', 'fa-solid fa-mortar-pestle', 4),
('Hộp quà',      'hop-qua',      'fa-solid fa-gift',        5);

-- ------------------------------------------------------------
-- 3. products  (bao gồm cost_price từ migrate_cost_price.sql)
-- ------------------------------------------------------------
CREATE TABLE `products` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(255) NOT NULL,
  `category_id` INT UNSIGNED NOT NULL,
  `price`       INT UNSIGNED NOT NULL DEFAULT 0,
  `cost_price`  INT UNSIGNED NOT NULL DEFAULT 0
                COMMENT 'Giá vốn nhập hàng (VND) — dùng để tính lợi nhuận',
  `price_old`   INT UNSIGNED          DEFAULT NULL,
  `origin`      VARCHAR(100)          DEFAULT NULL,
  `weight`      VARCHAR(50)           DEFAULT NULL,
  `type`        ENUM('la','tui_loc','bot','hop_qua') DEFAULT NULL,
  `is_featured` TINYINT(1)   NOT NULL DEFAULT 0,
  `is_new`      TINYINT(1)   NOT NULL DEFAULT 0,
  `stock`       INT          NOT NULL DEFAULT 0,
  `image`       VARCHAR(255)          DEFAULT NULL,
  `description` TEXT,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_featured` (`is_featured`),
  KEY `idx_new`      (`is_new`),
  CONSTRAINT `fk_product_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. coupons  (tạo trước orders vì orders có FK coupon_id)
--    Bao gồm các cột từ migrate_coupons.sql + migrate_coupon_roles.sql
-- ------------------------------------------------------------
CREATE TABLE `coupons` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`             VARCHAR(50)  NOT NULL,
  `discount_type`    ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  `discount_value`   INT UNSIGNED NOT NULL DEFAULT 0,
  `min_order`        INT UNSIGNED NOT NULL DEFAULT 0,
  `max_uses`         INT          NOT NULL DEFAULT 0,
  `used_count`       INT          NOT NULL DEFAULT 0,
  `expires_at`       DATE                  DEFAULT NULL,
  `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `coupon_role`      ENUM('public','private') NOT NULL DEFAULT 'public',
  `specific_user_id` INT UNSIGNED          DEFAULT NULL,
  `condition_type`   ENUM('none','min_spent','new_member') NOT NULL DEFAULT 'none',
  `condition_value`  INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mã mẫu
INSERT IGNORE INTO `coupons` (`code`, `discount_type`, `discount_value`, `min_order`, `max_uses`, `expires_at`) VALUES
('MOCTRA10', 'percent', 10,    200000, 100, DATE_ADD(CURDATE(), INTERVAL 30 DAY)),
('GIAM50K',  'fixed',   50000, 350000, 50,  DATE_ADD(CURDATE(), INTERVAL 14 DAY)),
('VIPONLY',  'percent', 20,    800000, 0,   NULL);

-- ------------------------------------------------------------
-- 5. orders  (bao gồm coupon_id, discount_amount, is_gifted)
-- ------------------------------------------------------------
CREATE TABLE `orders` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_code`      VARCHAR(20)           DEFAULT NULL,
  `user_id`         INT UNSIGNED NOT NULL,
  `full_name`       VARCHAR(100) NOT NULL,
  `phone`           VARCHAR(20)  NOT NULL,
  `address`         TEXT         NOT NULL,
  `note`            TEXT,
  `payment_method`  ENUM('cod','momo','bank') NOT NULL DEFAULT 'cod',
  `status`          ENUM('pending','processing','shipping','delivered','reviewed','cancelled') NOT NULL DEFAULT 'pending',
  `total`           INT UNSIGNED NOT NULL DEFAULT 0,
  `coupon_id`       INT UNSIGNED          DEFAULT NULL,
  `discount_amount` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_gifted`       TINYINT(1)   NOT NULL DEFAULT 0,
  `paid_at`         DATETIME              DEFAULT NULL
                    COMMENT 'Thời điểm thanh toán xác nhận qua Pay2S webhook',
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user`    (`user_id`),
  KEY `idx_status`  (`status`),
  KEY `idx_created` (`created_at`),
  KEY `idx_coupon`  (`coupon_id`),
  UNIQUE KEY `uq_order_code` (`order_code`),
  CONSTRAINT `fk_order_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. order_items
-- ------------------------------------------------------------
CREATE TABLE `order_items` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`     INT UNSIGNED NOT NULL,
  `product_id`   INT UNSIGNED NOT NULL,
  `product_name` VARCHAR(255) NOT NULL,
  `price`        INT UNSIGNED NOT NULL DEFAULT 0,
  `qty`          INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_order`   (`order_id`),
  KEY `idx_product` (`product_id`),
  CONSTRAINT `fk_item_order`   FOREIGN KEY (`order_id`)   REFERENCES `orders`   (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_item_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7. contacts
-- ------------------------------------------------------------
CREATE TABLE `contacts` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100) NOT NULL,
  `email`       VARCHAR(150)          DEFAULT '',
  `phone`       VARCHAR(20)           DEFAULT '',
  `subject`     VARCHAR(200)          DEFAULT 'Liên hệ chung',
  `message`     TEXT         NOT NULL,
  `admin_reply` TEXT                  DEFAULT NULL,
  `replied_at`  DATETIME              DEFAULT NULL,
  `status`      ENUM('new','read','replied') NOT NULL DEFAULT 'new',
  `admin_note`  TEXT,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 8. wishlist
-- ------------------------------------------------------------
CREATE TABLE `wishlist` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_product` (`user_id`, `product_id`),
  KEY `idx_user`    (`user_id`),
  KEY `idx_product` (`product_id`),
  CONSTRAINT `fk_wish_user`    FOREIGN KEY (`user_id`)    REFERENCES `users`    (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wish_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 9. product_reviews
-- ------------------------------------------------------------
CREATE TABLE `product_reviews` (
  `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `product_id`  INT UNSIGNED     NOT NULL,
  `user_id`     INT UNSIGNED     NOT NULL,
  `rating`      TINYINT UNSIGNED NOT NULL DEFAULT 5,
  `comment`     TEXT,
  `admin_reply` TEXT                      DEFAULT NULL,
  `replied_at`  DATETIME                  DEFAULT NULL,
  `created_at`  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_product` (`user_id`, `product_id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_user`    (`user_id`),
  CONSTRAINT `fk_rev_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rev_user`    FOREIGN KEY (`user_id`)    REFERENCES `users`    (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 10. notifications  (từ migrate_voucher_system.sql)
-- ------------------------------------------------------------
CREATE TABLE `notifications` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED  NOT NULL,
  `type`         ENUM('review_reply','contact_reply','voucher_gifted','payment_confirmed') NOT NULL,
  `reference_id` INT UNSIGNED  NOT NULL DEFAULT 0,
  `message`      VARCHAR(1000) NOT NULL DEFAULT '',
  `is_read`      TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_read` (`user_id`, `is_read`),
  KEY `idx_created`   (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 11. inventory_history
-- ------------------------------------------------------------
CREATE TABLE `inventory_history` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`    INT UNSIGNED NOT NULL,
  `admin_id`      INT UNSIGNED          DEFAULT NULL,
  `change_amount` INT          NOT NULL DEFAULT 0,
  `old_stock`     INT          NOT NULL DEFAULT 0,
  `new_stock`     INT          NOT NULL DEFAULT 0,
  `note`          VARCHAR(255) NOT NULL DEFAULT '',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
