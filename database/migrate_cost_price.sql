-- ============================================================
--  Migration: Thêm cột Giá vốn (cost_price) cho sản phẩm
--  Chạy 1 lần trong phpMyAdmin → teashop → SQL
-- ============================================================

USE `teashop`;

ALTER TABLE `products`
  ADD COLUMN IF NOT EXISTS `cost_price` INT UNSIGNED NOT NULL DEFAULT 0
  COMMENT 'Giá vốn nhập hàng (VND) — dùng để tính lợi nhuận'
  AFTER `price`;
Mục tiêu:
Khắc phục lỗi hiển thị Icon và định dạng văn bản trong bảng "Tất cả danh mục" để giao diện chuyên nghiệp và logic hơn.

❌ Vấn đề hiện tại (Bug Description):
Lỗi hiển thị Icon (Missing Icons):

Cột ICON trong bảng danh sách hoàn toàn trống trơn, không hiển thị biểu tượng mặc dù đã có dữ liệu mẫu.

Nguyên nhân có thể do file chưa nhúng thư viện Font Awesome hoặc đoạn code hiển thị đang gọi sai class (ví dụ: thiếu thẻ <i>).

Lỗi format tại ô "Font Awesome Class":

Trong form "Thêm danh mục", ô nhập class đang hiển thị chữ fa-solid fa-mug-hot nhưng không có bản xem trước (preview) của icon đó.

Văn bản trong ô này đang bị lệch hoặc chưa được style đúng cách để người dùng dễ nhận diện.

Lỗi canh lề (Alignment Issues):

Dữ liệu trong các cột như "THỨ TỰ", "SP" (Số lượng sản phẩm) đang bị lệch so với tiêu đề cột, khiến bảng nhìn rất rối và khó theo dõi.

🎯 Yêu cầu chi tiết để sửa lỗi:
Nhúng Thư viện: Kiểm tra và đảm bảo đã thêm liên kết CDN của Font Awesome 6 vào phần <head> của trang quản trị.

Render Icon Logic:

Trong vòng lặp hiển thị bảng, thay vì chỉ in ra text, hãy bọc giá trị từ database vào thẻ: <i class="<?php echo $row['icon_class']; ?>"></i>.

Thêm tính năng Preview Icon:

Khi Admin nhập class vào ô "Font Awesome Class", hãy dùng JavaScript để hiển thị icon tương ứng ngay bên cạnh để Admin biết mình đã nhập đúng hay chưa.

Chỉnh sửa CSS Bảng:

Căn giữa (center) nội dung cho các cột ICON, THỨ TỰ, và SP.

Đảm bảo các nút "Sửa" và "Xóa" có khoảng cách đều nhau.

⚙️ Tuân thủ kỹ thuật:
Dự án: Mộc Trà (Admin Dashboard).

Database: Bảng categories trong MySQL cần kiểm tra xem cột lưu icon có đúng tên là icon_class (hoặc tương đương) không.

Framework/Library: Font Awesome Solid.