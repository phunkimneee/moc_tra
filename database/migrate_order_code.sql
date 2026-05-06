-- ============================================================
--  Migration: Order Code + QR System
--  Chạy trong phpMyAdmin (DB: teashop) — chỉ cần 1 lần
-- ============================================================

USE `teashop`;

-- 1. Thêm cột order_code
ALTER TABLE `orders`
  ADD COLUMN `order_code` VARCHAR(20) DEFAULT NULL
  AFTER `id`,
  ADD UNIQUE KEY `uq_order_code` (`order_code`);

-- 2. Backfill toàn bộ đơn hàng cũ
UPDATE `orders`
SET `order_code` = CONCAT(
  'ORD',
  DATE_FORMAT(`created_at`, '%Y%m%d'),
  '-',
  LPAD(`id`, 4, '0')
)
WHERE `order_code` IS NULL;
