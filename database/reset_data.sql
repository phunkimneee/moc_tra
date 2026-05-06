-- ============================================================
--  Moctra — Reset toàn bộ dữ liệu demo
--  Chạy trong phpMyAdmin (DB: teashop)
--  ⚠️  KHÔNG THỂ HOÀN TÁC — hãy backup trước nếu cần
-- ============================================================
--  Giữ lại : tài khoản admin + bảng categories
--  Xóa sạch: products, orders, order_items, users(customer),
--             coupons, contacts, reviews, notifications,
--             inventory_history, wishlist
-- ============================================================

USE `teashop`;

-- Xóa theo đúng thứ tự: bảng con trước, bảng cha sau
-- (không cần tắt FK_CHECKS)
DELETE FROM `notifications`;
DELETE FROM `inventory_history`;
DELETE FROM `product_reviews`;
DELETE FROM `wishlist`;
DELETE FROM `order_items`;
DELETE FROM `orders`;
DELETE FROM `coupons`;
DELETE FROM `products`;
DELETE FROM `contacts`;
DELETE FROM `users` WHERE `role` = 'customer';

-- Reset AUTO_INCREMENT về 1 cho sạch
ALTER TABLE `products`           AUTO_INCREMENT = 1;
ALTER TABLE `orders`             AUTO_INCREMENT = 1;
ALTER TABLE `order_items`        AUTO_INCREMENT = 1;
ALTER TABLE `coupons`            AUTO_INCREMENT = 1;
ALTER TABLE `contacts`           AUTO_INCREMENT = 1;
ALTER TABLE `product_reviews`    AUTO_INCREMENT = 1;
ALTER TABLE `notifications`      AUTO_INCREMENT = 1;
ALTER TABLE `inventory_history`  AUTO_INCREMENT = 1;
ALTER TABLE `wishlist`           AUTO_INCREMENT = 1;

-- Giữ AUTO_INCREMENT của users tiếp từ sau admin (id=1)
ALTER TABLE `users` AUTO_INCREMENT = 2;
