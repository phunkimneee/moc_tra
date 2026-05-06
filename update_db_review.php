<?php
$c = new mysqli('localhost', 'root', '', 'teashop');
if ($c->connect_error) die("DB Error");

// 1. Thêm trạng thái 'reviewed' vào ENUM
$c->query("ALTER TABLE orders MODIFY COLUMN status ENUM('pending','processing','shipping','delivered','cancelled','reviewed') NOT NULL DEFAULT 'pending'");

// 2. Thêm cột order_id vào product_reviews
$res = $c->query("SHOW COLUMNS FROM product_reviews LIKE 'order_id'");
if ($res->num_rows == 0) {
    $c->query("ALTER TABLE product_reviews ADD COLUMN order_id INT UNSIGNED AFTER product_id");
}

// 3. Xóa index cũ và tạo index mới để cho phép đánh giá theo đơn hàng
$c->query("ALTER TABLE product_reviews DROP INDEX uq_user_product");
$c->query("ALTER TABLE product_reviews ADD UNIQUE KEY uq_order_product (order_id, product_id)");

echo "Database updated successfully";
unlink(__FILE__);


