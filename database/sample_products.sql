-- ============================================================
--  Dữ liệu mẫu sản phẩm — Mộc Trà Thái Nguyên
--  44 sản phẩm đầy đủ ảnh, phân loại, giá vốn, tồn kho
--  Chạy SAU KHI đã import schema.sql
--  (hoặc schema.sql đã bao gồm file này ở cuối)
-- ============================================================

SET NAMES utf8mb4;

INSERT INTO `products`
  (`name`, `category_id`, `price`, `cost_price`, `price_old`, `origin`, `weight`, `type`,
   `is_featured`, `is_new`, `stock`, `image`, `description`)
VALUES

-- ══════════════════════════════════════════════
-- TRÀ XANH  (category_id = 1)
-- ══════════════════════════════════════════════
('Trà Xanh Thái Nguyên Thượng Hạng', 1, 250000, 120000, NULL,
 'Thái Nguyên, Việt Nam', '200g', 'la', 1, 0, 50,
 'tra-xanh-thai-nguyen-thuong-hang.jpg',
 'Vị chát dịu, hậu ngọt sâu, mang hương cốm non đặc trưng của trà Tân Cương.'),

('Trà Shan Tuyết Cổ Thụ Hà Giang', 1, 350000, 150000, NULL,
 'Hà Giang, Việt Nam', '100g', 'la', 1, 0, 30,
 'tra-shan-tuyet-co-thu-ha-giang.jpeg',
 'Hái từ cây cổ thụ, búp trà phủ lông tơ trắng, nước vàng sánh, hương thơm mộc mạc.'),

('Trà Xanh Ướp Hoa Lài', 1, 160000, 80000, 200000,
 'Lâm Đồng, Việt Nam', '250g', 'la', 0, 0, 40,
 'tra-xanh-uop-hoa-lai.jpg',
 'Trà xanh xao kỹ ướp cùng hoa lài tươi tự nhiên, mang lại cảm giác thanh mát, thư giãn.'),

('Trà Sen Tây Hồ Bách Diệp', 1, 800000, 300000, NULL,
 'Hà Nội, Việt Nam', '100g', 'la', 1, 0, 15,
 'tra-sen-tay-ho-bach-diep.jpg',
 'Dòng trà xa xỉ ướp từ hoa sen Bách Diệp Hồ Tây, hương thơm tinh khiết, đậm đà vị truyền thống.'),

('Trà Xanh Bancha', 1, 220000, 100000, 260000,
 'Shizuoka, Nhật Bản', '200g', 'la', 0, 0, 35,
 'tra-xanh-bancha.jpg',
 'Lá trà già thu hoạch muộn, lượng caffeine thấp, rất lành tính và phù hợp uống vào buổi tối.'),

('Trà Long Tỉnh Hàng Châu', 1, 550000, 250000, NULL,
 'Hàng Châu, Trung Quốc', '100g', 'la', 0, 0, 20,
 'tra-long-tinh-hang-chau.jpg',
 'Búp trà dẹt phẳng, màu xanh mướt, pha ra nước trong, vị thanh ngọt và thoảng hương hạt dẻ.'),

('Trà Xanh Lộc Phát Pha Chế', 1, 180000, 90000, 220000,
 'Bảo Lộc, Việt Nam', '500g', 'la', 0, 0, 60,
 'tra-xanh-loc-phat-pha-che.jpg',
 'Trà xanh chuyên dụng cho pha chế trà chanh, trà đá tại các quán nước, giữ vị tốt khi thêm đá.'),

('Bột Matcha Uji Ceremonial', 1, 450000, 220000, NULL,
 'Kyoto, Nhật Bản', '100g', 'bot', 1, 0, 25,
 'bot-matcha-uji-ceremonial.jpg',
 'Dùng trong Trà Đạo, bột siêu mịn màu xanh ngọc bích, vị đắng dịu, Umami cực kỳ rõ nét.'),

('Bột Matcha Vụ Xuân Shizuoka', 1, 380000, 180000, NULL,
 'Shizuoka, Nhật Bản', '100g', 'bot', 0, 0, 30,
 'bot-matcha-vu-xuan-shizuoka.jpg',
 'Thu hoạch từ búp lá non vụ xuân, vị thanh mát, hoàn hảo để pha chế Matcha Latte.'),

('Bột Houjicha (Trà Xanh Rang)', 1, 400000, 190000, NULL,
 'Nhật Bản', '100g', 'bot', 0, 1, 20,
 'bot-ujimatcha.jpg',
 'Trà xanh trải qua quá trình rang, bột màu nâu caramel, hương khói thơm bùi, ít caffeine.'),

-- ══════════════════════════════════════════════
-- TRÀ OOLONG  (category_id = 2)
-- ══════════════════════════════════════════════
('Trà Ô Long Tứ Quý', 2, 320000, 150000, NULL,
 'Lâm Đồng, Việt Nam', '250g', 'la', 1, 0, 35,
 'tra-o-long-tu-quy.jpg',
 'Mang hương hoa mộc lan nhẹ nhàng, vị thanh tao, nước vàng xanh óng ánh đẹp mắt.'),

('Trà Thiết Quan Âm Phúc Kiến', 2, 400000, 180000, NULL,
 'Phúc Kiến, Trung Quốc', '100g', 'la', 1, 0, 25,
 'tra-thiet-quan-am-phuc-kien.jpg',
 'Viên trà cuộn tròn chặt chẽ, khi pha nở bung, hương thơm lan tỏa mạnh, pha được rất nhiều nước.'),

('Trà Ô Long Sữa', 2, 350000, 160000, NULL,
 'Nam Đầu, Đài Loan', '200g', 'la', 0, 0, 30,
 'tra-o-long-sua.jpg',
 'Dòng Ô long đặc biệt có mùi thơm béo ngậy của sữa tươi tự nhiên cùng vị ngọt ngào lưu luyến.'),

('Trà Ô Long Nhân Sâm', 2, 450000, 200000, NULL,
 'Nam Đầu, Đài Loan', '150g', 'la', 0, 0, 20,
 'tra-o-long-nhan-sam.jpg',
 'Trà được phủ lớp bột nhân sâm mịn, uống vào ngọt giọng, bồi bổ khí huyết cực tốt.'),

('Trà Ô Long Mộc Châu Cao Cấp', 2, 300000, 140000, 360000,
 'Sơn La, Việt Nam', '250g', 'la', 0, 0, 40,
 'tra-o-long-moc-chau.jpg',
 'Trồng ở cao nguyên mù sương, chất trà dày dặn, chát nhẹ ban đầu và ngọt hậu kéo dài.'),

('Trà Ô Long Đen (Đại Hồng Bào)', 2, 800000, 350000, NULL,
 'Vũ Di Sơn, Trung Quốc', '100g', 'la', 1, 0, 10,
 'tra-o-long-den.jpg',
 'Nước trà màu hổ phách đậm, mang hương khói than củi nhẹ và vị khoáng chất đặc trưng của vách đá.'),

('Trà Ô Long Thúy Ngọc', 2, 280000, 130000, NULL,
 'Bảo Lộc, Việt Nam', '250g', 'la', 0, 0, 35,
 'tra-o-long-thuy-ngoc.png',
 'Hương hoa ngọc lan dịu dàng, dễ uống, vô cùng thích hợp cho phái nữ thưởng thức mỗi ngày.'),

-- ══════════════════════════════════════════════
-- TRÀ ĐEN  (category_id = 3)
-- ══════════════════════════════════════════════
('Trà Đen Assam Đậm Vị', 3, 230000, 110000, NULL,
 'Đài Loan', '200g', 'la', 1, 0, 45,
 'tra-den-assam.jpg',
 'Vị đậm đà nồng nàn, màu nước đỏ tươi, là lựa chọn hoàn hảo để pha trà sữa truyền thống.'),

('Trà Bá Tước (Earl Grey)', 3, 260000, 120000, NULL,
 'Anh Quốc', '150g', 'la', 0, 0, 40,
 'tra-ba-tuoc.jpg',
 'Trà đen ướp tinh dầu cam Bergamot, mang hương vị hoàng gia sang trọng và quyến rũ.'),

('Trà Đen Ceylon', 3, 280000, 130000, NULL,
 'Sri Lanka', '250g', 'la', 0, 0, 35,
 'tra-den-ceylon.png',
 'Chát thanh tao, hậu ngọt êm, nước trà có màu sắc rực rỡ và hương thơm thoang thoảng.'),

('Trà English Breakfast', 3, 300000, 140000, NULL,
 'Anh Quốc', '200g', 'la', 0, 0, 30,
 'tra-english-breakfast.jpg',
 'Hỗn hợp trà đen mạnh mẽ, giúp tỉnh táo tinh thần, rất tuyệt vời khi thưởng thức cùng bữa sáng.'),

('Trà Đen Chuyên Pha Trà Sữa', 3, 160000, 80000, 190000,
 'Lâm Đồng, Việt Nam', '500g', 'la', 0, 0, 55,
 'tra-den-chuyen-pha-tra-sua.jpg',
 'Lá trà đen xao kỹ, cốt trà đậm đặc không bị lấn át bởi vị béo của bột sữa.'),

('Hồng Trà Cổ Thụ Suối Giàng', 3, 350000, 160000, NULL,
 'Yên Bái, Việt Nam', '100g', 'la', 1, 0, 25,
 'hong-tra-co-thu-suoi-giang.jpg',
 'Lên men từ lá trà Shan Tuyết cổ thụ, vị ngọt như mật ong rừng, hoàn toàn không có vị chát.'),

('Trà Đen Nguyên Lá Khô', 3, 150000, 70000, NULL,
 'Phú Thọ, Việt Nam', '250g', 'la', 0, 0, 50,
 'tra-den-nguyen-la-kho.jpg',
 'Sản xuất theo phương pháp truyền thống, giữ nguyên vóc dáng lá trà, thích hợp uống hàng ngày.'),

-- ══════════════════════════════════════════════
-- TRÀ THẢO MỘC  (category_id = 4)
-- ══════════════════════════════════════════════
('Trà Hoa Cúc Vàng Sấy Lạnh', 4, 180000, 80000, NULL,
 'Hưng Yên, Việt Nam', '100g', 'la', 0, 0, 40,
 'tra-hoa-cuc-vang-say-lanh.jpg',
 'Hoa cúc nguyên bông sấy lạnh giữ màu, giúp an thần, sáng mắt, giải nhiệt cơ thể hiệu quả.'),

('Trà Nụ Hoa Hồng Đà Lạt', 4, 200000, 90000, NULL,
 'Lâm Đồng, Việt Nam', '100g', 'la', 0, 0, 35,
 'tra-nu-hoa-hong-da-lat.jpg',
 'Nụ hồng hàm tiếu sấy khô, chứa nhiều vitamin C giúp dưỡng nhan và làm mờ thâm sạm.'),

('Trà Đậu Biếc Khô', 4, 140000, 60000, NULL,
 'Đồng Tháp, Việt Nam', '100g', 'la', 0, 0, 45,
 'tra-hoa-dau-biec.jpg',
 'Tạo màu xanh dương/tím tự nhiên cực đẹp cho đồ uống, giàu chất chống oxy hóa.'),

('Trà Hoa Nhài Tự Nhiên', 4, 170000, 75000, NULL,
 'Hà Nội, Việt Nam', '100g', 'la', 0, 0, 40,
 'tra-hoa-nhai-tu-nhien.png',
 'Hương hoa nhài nồng nàn quyến rũ, giúp xua tan mệt mỏi, giảm stress và thanh lọc cơ thể.'),

('Trà Quế Hoa (Hoa Mộc)', 4, 280000, 120000, NULL,
 'Hà Giang, Việt Nam', '50g', 'la', 0, 0, 20,
 'tra-hoa-moc.jpg',
 'Những nụ hoa nhỏ li ti nhưng mang hương thơm ngọt ngào tựa như trái dứa và quả mơ chín.'),

('Trà Hoa Oải Hương (Lavender)', 4, 350000, 150000, NULL,
 'Provence, Pháp', '50g', 'la', 0, 1, 25,
 'tra-hoa-lavender.jpg',
 'Hương thơm thư giãn cực mạnh, là thần dược hỗ trợ một giấc ngủ sâu và ngon giấc.'),

('Trà Nụ Tam Thất Bao Tử', 4, 550000, 250000, NULL,
 'Lào Cai, Việt Nam', '100g', 'la', 0, 1, 15,
 'tra-nu-tam-that-bao-tu.jpg',
 'Vị đắng nhẹ lúc đầu nhưng hậu ngọt sâu, đặc trị chứng mất ngủ kinh niên và cao huyết áp.'),

('Trà Gừng Mật Ong Hòa Tan', 4, 95000, 45000, 120000,
 'Việt Nam', 'Hộp 20 gói', 'tui_loc', 0, 0, 60,
 'tra-gung-mat-ong-hoa-tan.png',
 'Dạng gói tiện lợi, giúp làm ấm bụng tức thì, giải cảm và chống buồn nôn hiệu quả.'),

('Trà Táo Đỏ Kỷ Tử Dưỡng Nhan', 4, 180000, 85000, NULL,
 'Tân Cương, Trung Quốc', '250g', 'la', 0, 0, 40,
 'tra-tao-do-ky-tu.jpg',
 'Sự kết hợp hoàn hảo giúp bổ máu, lưu thông khí huyết, vị ngọt thanh tự nhiên rất dễ uống.'),

('Set Trà Thảo Mộc 7 Vị', 4, 220000, 90000, NULL,
 'Việt Nam', '10 gói/hộp', 'tui_loc', 0, 0, 30,
 'tra-thao-moc-7-vi.jpg',
 'Đóng gói sẵn từng set nhỏ gồm táo đỏ, kỷ tử, long nhãn, hoa cúc... bồi bổ sức khỏe toàn diện.'),

('Trà Gừng Khô Cắt Lát', 4, 130000, 60000, 160000,
 'Đắk Lắk, Việt Nam', '200g', 'la', 0, 0, 50,
 'tra-gung-kho-cat-lat.jpg',
 'Hương vị nồng ấm, cay nhẹ, rất tốt cho hệ tiêu hóa và tăng cường hệ miễn dịch mùa lạnh.'),

('Trà Atiso Túi Lọc', 4, 150000, 70000, NULL,
 'Đà Lạt, Việt Nam', 'Hộp 100 túi', 'tui_loc', 0, 0, 40,
 'tra-atiso- tui-loc.jpg',
 'Dạng túi lọc tiện dụng, giúp mát gan, thanh nhiệt, giải độc cơ thể do bia rượu.'),

('Trà Cam Quế Mật Ong Khô', 4, 240000, 110000, NULL,
 'Việt Nam', '15 gói/hộp', 'tui_loc', 0, 0, 35,
 'tra-cam-que-mat-ong.jpg',
 'Vị chua ngọt của cam vàng hòa quyện hương quế nồng ấm, thích hợp uống vào buổi sáng.'),

('Trà Khổ Qua Rừng Sấy Khô', 4, 160000, 75000, NULL,
 'Tây Ninh, Việt Nam', '150g', 'la', 0, 0, 40,
 'tra-kho-qua-rung-say-kho.jpg',
 'Khổ qua thái mỏng sấy giòn, vị đắng đặc trưng, hỗ trợ hạ đường huyết và giảm mỡ máu.'),

('Trà Đào Túi Lọc Mộc Trà', 4, 65000, 35000, 85000,
 'Phú Thọ, Việt Nam', 'Hộp 25 túi', 'tui_loc', 0, 1, 80,
 'tra-dao-tui-loc-moc-tra.jpg',
 'Từng túi lọc gọn nhẹ mang hương đào tươi mát, chỉ cần 3 phút có ngay ly trà đào thơm lừng.'),

('Trà Đào Mật Ong Hàn Quốc', 4, 280000, 130000, NULL,
 'Hàn Quốc', 'Hũ 1kg', 'la', 0, 1, 30,
 'tra-dao-mat-ong-han-quoc.jpg',
 'Dạng mứt sệt có xác đào giòn sần sật, pha cùng nước lạnh và đá viên để giải khát mùa hè.'),

('Set Trà Đào Cam Sả Tiện Lợi', 4, 220000, 100000, NULL,
 'Việt Nam', '10 set/hộp', 'tui_loc', 0, 1, 40,
 'set-tra-dao-cam-sa-tien-loi.jpg',
 'Đóng sẵn tỷ lệ chuẩn trà đào, cam sấy và sả khô, dễ dàng pha chế tại nhà chuẩn vị ngoài quán.'),

-- ══════════════════════════════════════════════
-- HỘP QUÀ  (category_id = 5)
-- ══════════════════════════════════════════════
('Hộp Quà Trà Ô Long Tứ Linh', 5, 890000, 450000, NULL,
 'Lâm Đồng, Việt Nam', 'Hộp 400g', 'hop_qua', 1, 0, 15,
 'hop-qua-tra-o-long-tu-linh.jpg',
 'Gồm 4 hộp Ô long cao cấp nhỏ gọn, đóng trong hộp gỗ khắc laser sang trọng biếu đối tác.'),

('Set Quà Trà Chiều Anh Quốc', 5, 1200000, 550000, NULL,
 'Nhập khẩu Anh', 'Hộp 300g', 'hop_qua', 1, 0, 10,
 'set-qua-tra-chieu-anh-quoc.jpeg',
 'Gồm Earl Grey, English Breakfast kèm một bộ tách trà sứ hoàng gia tinh tế.'),

('Set Quà Biếu Tết Thượng Hạng', 5, 850000, 400000, NULL,
 'Thái Nguyên, Việt Nam', 'Hộp 500g', 'hop_qua', 1, 0, 12,
 'set-tra-biet-tet-thuong-hang.jpg',
 'Thiết kế hộp giấy đỏ ép kim cao cấp, bên trong là dòng trà Nõn Tôm đậm đà hương vị truyền thống.');
