<?php
// Lưu dữ liệu form vào file CSV nằm ngoài thư mục web.
const DATA_DIR = '/var/www/chinhnhatreem-data';

function tra_ve($ok, $code = 200, $msg = '') {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo json_encode(['ok' => $ok, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
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
    $time = date('Y-m-d H:i:s');
    fputcsv($fh, array_merge([$time], array_values($cot)));
    flock($fh, LOCK_UN);
    fclose($fh);

    // Đồng thời lưu vào SQLite DB nếu có
    try {
        $dbFile = DATA_DIR . '/site.db';
        if (file_exists($dbFile)) {
            $pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $st = basename($file, '.csv');
            if ($st === 'dat-lich') {
                $name = (string)($cot['Họ tên phụ huynh'] ?? '');
                $phone = (string)(ltrim($cot['Số điện thoại / Zalo'] ?? '', "'"));
                $age = (string)($cot['Tuổi của bé'] ?? '');
                $concerns = (string)($cot['Bố mẹ lo về'] ?? '');
                $note = (string)($cot['Ghi chú'] ?? '');
                $hash = md5('dat-lich|' . $time . '|' . $phone . '|' . $name);
            } else {
                $name = 'Phụ huynh';
                $phone = (string)(ltrim($cot['Email / Zalo'] ?? '', "'"));
                $age = '';
                $concerns = (string)($cot['Câu hỏi'] ?? '');
                $note = '';
                $hash = md5('cau-hoi|' . $time . '|' . $phone . '|' . $concerns);
            }
            $ins = $pdo->prepare('INSERT OR IGNORE INTO leads (source_type, created_at, name, phone, child_age, concerns, note, status, raw_hash) VALUES (?,?,?,?,?,?,?,?,?)');
            $ins->execute([$st, $time, $name, $phone, $age, $concerns, $note, 'chua_lien_he', $hash]);
        }
    } catch (Throwable $e) {}

    // Trả lời bố mẹ ngay, rồi mới gửi Telegram (bố mẹ không phải chờ)
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo json_encode(['ok' => true]);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    bao_telegram(basename($file, '.csv'), $cot);
    exit;
}

function bao_telegram(string $loai, array $cot): void {
    $lib = dirname(__DIR__, 2) . '/app/telegram.php';
    if (!is_file($lib)) return;
    require_once $lib;
    $c = tg_conf(DATA_DIR);
    if (!tg_ready($c) || empty($c[$loai === 'dat-lich' ? 'dat_lich' : 'cau_hoi'])) return;
    tg_send(DATA_DIR, tg_form_message($loai, $cot, 'https://chinhnhatreem.com'));
}
