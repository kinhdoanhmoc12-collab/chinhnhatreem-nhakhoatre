<?php
date_default_timezone_set('Asia/Ho_Chi_Minh');
require __DIR__ . '/_luu.php';
$ten = lam_sach($_POST['ten'] ?? '', 80);
$sdt = lam_sach($_POST['sdt'] ?? '', 15);
if ($ten === '') tra_ve(false, 422, 'Vui lòng điền họ tên phụ huynh.');
if (!preg_match('/^[0-9 .+]{9,15}$/', $sdt)) tra_ve(false, 422, 'Vui lòng nhập đúng số điện thoại (từ 9 - 11 chữ số).');
if (empty($_POST['dong_y'])) tra_ve(false, 422, 'Vui lòng tích vào ô đồng ý chính sách bảo mật trước khi gửi.');
luu('dat-lich.csv', [
    'Họ tên phụ huynh' => $ten,
    'Số điện thoại / Zalo' => $sdt,
    'Tuổi của bé' => lam_sach($_POST['tuoi'] ?? '', 30),
    'Bố mẹ lo về' => lam_sach($_POST['van_de'] ?? [], 200),
    'Ghi chú' => lam_sach($_POST['ghi_chu'] ?? '', 1000),
]);
