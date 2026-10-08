<?php
// Trang xem lịch hẹn và câu hỏi. Được bảo vệ bằng mật khẩu (Nginx basic auth).
header('X-Robots-Tag: noindex, nofollow');
const DATA_DIR = '/var/www/chinhnhatreem-data';
$loai = ($_GET['xem'] ?? 'dat-lich') === 'cau-hoi' ? 'cau-hoi' : 'dat-lich';
$file = DATA_DIR . "/$loai.csv";
if (isset($_GET['tai']) && is_file($file)) {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"$loai.csv\"");
    readfile($file); exit;
}
$rows = [];
if (is_file($file) && ($fh = fopen($file, 'r'))) {
    while (($r = fgetcsv($fh)) !== false) $rows[] = $r;
    fclose($fh);
}
$head = $rows ? array_shift($rows) : [];
if ($head) $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]);
$rows = array_reverse($rows);
function e($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?><!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Quản lý | Nha Khoa Trẻ</title>
<style>body{margin:0;font-family:system-ui,sans-serif;background:#FBF6EF;color:#0D3B2C}main{max-width:1200px;margin:0 auto;padding:24px}
nav a{display:inline-block;margin:0 8px 16px 0;padding:10px 18px;border-radius:999px;border:1.5px solid #087A3A;color:#087A3A;text-decoration:none;font-weight:700}
nav a.on{background:#087A3A;color:#fff}table{width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden;font-size:15px}
th,td{padding:10px 12px;border-bottom:1px solid #EBE2D5;text-align:left;vertical-align:top}th{background:#E5F3EA}.wrap{overflow-x:auto}</style></head>
<body><main><h1>Nha Khoa Trẻ – dữ liệu từ website</h1>
<nav><a href="?xem=dat-lich" class="<?= $loai === 'dat-lich' ? 'on' : '' ?>">Lịch hẹn</a><a href="?xem=cau-hoi" class="<?= $loai === 'cau-hoi' ? 'on' : '' ?>">Câu hỏi</a>
<?php if ($rows): ?><a href="?xem=<?= $loai ?>&amp;tai=1">Tải file Excel (CSV)</a><?php endif; ?></nav>
<p><?= count($rows) ?> mục, mới nhất ở trên.</p>
<?php if ($rows): ?><div class="wrap"><table><tr><?php foreach ($head as $h) echo '<th>' . e($h) . '</th>'; ?></tr>
<?php foreach ($rows as $r) { echo '<tr>'; foreach ($r as $c) echo '<td>' . e(ltrim($c, "'")) . '</td>'; echo '</tr>'; } ?>
</table></div><?php endif; ?></main></body></html>
