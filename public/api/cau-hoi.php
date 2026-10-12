<?php
date_default_timezone_set('Asia/Ho_Chi_Minh');
require __DIR__ . '/_luu.php';
$cau = lam_sach($_POST['cau_hoi'] ?? '', 1500);
$lh = lam_sach($_POST['lien_he'] ?? '', 120);
if ($cau === '') tra_ve(false, 422, 'Vui lòng nhập câu hỏi của bạn.');
if ($lh === '') tra_ve(false, 422, 'Vui lòng nhập số điện thoại hoặc email liên hệ.');
luu('cau-hoi.csv', ['Câu hỏi' => $cau, 'Email / Zalo' => $lh]);
