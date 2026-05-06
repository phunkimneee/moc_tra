-- ============================================================
--  Migration: Pay2S Payment Integration
--  Chạy file này trong phpMyAdmin (DB: teashop)
--  Chỉ cần chạy 1 lần — an toàn khi chạy lại (IF NOT EXISTS)
-- ============================================================

USE `teashop`;

-- 1. Thêm cột paid_at vào orders
ALTER TABLE `orders`
  ADD COLUMN `paid_at` DATETIME DEFAULT NULL
  AFTER `is_gifted`;

-- 2. Mở rộng ENUM notifications.type để hỗ trợ 'payment_confirmed'
ALTER TABLE `notifications`
  MODIFY `type`
    ENUM('review_reply','contact_reply','voucher_gifted','payment_confirmed')
    NOT NULL;
