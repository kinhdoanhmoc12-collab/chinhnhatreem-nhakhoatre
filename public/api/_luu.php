<?php
// Lưu dữ liệu form vào file CSV nằm ngoài thư mục web.
const DATA_DIR = '/var/www/chinhnhatreem-data';

function tra_ve($ok, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo json_encode(['ok' => $ok]);
    exit;
}

function lam_sach($v, $max) {
    $v = is_array($v) ? implode(', ', $v) : (string)$v;
    $v = trim(preg_replace('/[\r\n\t]+/', ' ', $v));
    $v = mb_substr($v, 0, $max);
    // Chặn công thức khi mở bằng Excel
    if ($v !== '' && strpbrk($v[0], '=+-@') !== false) $v = "'" . $v;
    return $v;
}

function luu($file, array $cot) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') tra_ve(false, 405);
    if (!empty($_POST['website'])) tra_ve(true);          // bẫy spam: bot điền ô ẩn
    $path = DATA_DIR . '/' . $file;
    $moi = !file_exists($path);
    $fh = fopen($path, 'a');
    if (!$fh) tra_ve(false, 500);
    flock($fh, LOCK_EX);
    if ($moi) { fwrite($fh, "\xEF\xBB\xBF"); fputcsv($fh, array_merge(['Thời gian'], array_keys($cot))); }
    fputcsv($fh, array_merge([date('Y-m-d H:i:s')], array_values($cot)));
    flock($fh, LOCK_UN);
    fclose($fh);
    tra_ve(true);
}
