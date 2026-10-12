<?php
// Bảng điều khiển: số liệu tổng hợp, việc cần làm, biểu đồ
require_once __DIR__ . '/seo.php';
require_once __DIR__ . '/render.php';

function lead_rows(string $file): array {
    $path = DATA_DIR . "/$file.csv";
    if (!is_file($path) || !($fh = fopen($path, 'r'))) return [];
    $rows = [];
    fgetcsv($fh);
    while (($r = fgetcsv($fh)) !== false) if (!empty($r[0])) $rows[] = $r;
    fclose($fh);
    return $rows;
}

function count_since(array $rows, int $fromTs, int $toTs): int {
    $n = 0;
    foreach ($rows as $r) { $t = strtotime($r[0]); if ($t >= $fromTs && $t < $toTs) $n++; }
    return $n;
}

function get_monthly_lead_stats(int $months = 6): array {
    sync_leads_from_csv();
    $db = db();
    $stats = [];
    $startM = date('Y-m-01');
    for ($i = $months - 1; $i >= 0; $i--) {
        $m = date('Y-m', strtotime("$startM -$i month"));
        $from = "$m-01 00:00:00";
        $to = date('Y-m-t 23:59:59', strtotime($from));
        $st = $db->prepare("SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN source_type = 'dat-lich' THEN 1 ELSE 0 END) as dat_lich,
            SUM(CASE WHEN source_type = 'cau-hoi' THEN 1 ELSE 0 END) as cau_hoi,
            SUM(CASE WHEN status != 'chua_lien_he' THEN 1 ELSE 0 END) as da_lien_he,
            SUM(CASE WHEN status = 'chua_lien_he' THEN 1 ELSE 0 END) as chua_lien_he,
            SUM(CASE WHEN status = 'da_hen' THEN 1 ELSE 0 END) as da_hen
            FROM leads WHERE created_at >= ? AND created_at <= ? AND deleted_at IS NULL");
        $st->execute([$from, $to]);
        $r = $st->fetch() ?: [];
        $tot = (int)($r['total'] ?? 0);
        $dl = (int)($r['dat_lich'] ?? 0);
        $ch = (int)($r['cau_hoi'] ?? 0);
        $dlh = (int)($r['da_lien_he'] ?? 0);
        $clh = (int)($r['chua_lien_he'] ?? 0);
        $dh = (int)($r['da_hen'] ?? 0);
        $rate = $tot > 0 ? (int)round(($dlh / $tot) * 100) : 0;
        $stats[$m] = [
            'month' => $m,
            'label' => 'Tháng ' . date('n/Y', strtotime($from)),
            'short_label' => 'T' . date('n', strtotime($from)),
            'total' => $tot,
            'dat_lich' => $dl,
            'cau_hoi' => $ch,
            'da_lien_he' => $dlh,
            'chua_lien_he' => $clh,
            'da_hen' => $dh,
            'rate' => $rate,
        ];
    }
    return $stats;
}

// Biểu đồ cột dọc 1 dãy số (SVG, tự co giãn). $labels/$values cùng độ dài.
function svg_bars(array $labels, array $values, string $unit, string $aria): string {
    $W = 560; $H = 220; $L = 34; $R = 8; $T = 14; $B = 30;
    $max = max(1, max($values));
    $step = $max <= 5 ? 1 : ($max <= 10 ? 2 : ($max <= 25 ? 5 : ($max <= 50 ? 10 : 20)));
    $top = (int)(ceil($max / $step) * $step);
    $n = count($values); $slot = ($W - $L - $R) / max(1, $n); $bw = min(36, $slot * 0.56);
    $y = fn($v) => $T + ($H - $T - $B) * (1 - $v / $top);
    $s = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($aria) . '">';
    for ($v = 0; $v <= $top; $v += $step) {
        $yy = round($y($v), 1);
        $s .= '<line x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $yy . '" y2="' . $yy . '" class="grid' . ($v === 0 ? ' base' : '') . '"/>'
            . '<text x="' . ($L - 6) . '" y="' . ($yy + 4) . '" class="ax" text-anchor="end">' . $v . '</text>';
    }
    foreach ($values as $i => $v) {
        $cx = $L + $slot * ($i + 0.5); $x = round($cx - $bw / 2, 1);
        $yy = round($y($v), 1); $h = round($H - $B - $yy, 1);
        $last = $i === $n - 1;
        $s .= '<g class="bar' . ($last ? ' last' : '') . '"><title>' . e($labels[$i]) . ': ' . $v . ' ' . e($unit) . '</title>'
            . '<rect x="' . round($cx - $slot / 2, 1) . '" y="' . $T . '" width="' . round($slot, 1) . '" height="' . ($H - $T - $B) . '" class="hit"/>';
        if ($v > 0) $s .= '<path d="M' . $x . ',' . ($H - $B) . 'V' . ($yy + 4) . 'q0,-4 4,-4h' . round($bw - 8, 1) . 'q4,0 4,4V' . ($H - $B) . 'Z" class="fill"/>';
        if ($last || $v === max($values)) $s .= '<text x="' . round($cx, 1) . '" y="' . ($yy - 5) . '" class="val" text-anchor="middle">' . $v . '</text>';
        $s .= '<text x="' . round($cx, 1) . '" y="' . ($H - 10) . '" class="ax" text-anchor="middle">' . e($labels[$i]) . '</text></g>';
    }
    return $s . '</svg>';
}

// Thanh ngang so với mục tiêu (dùng cho chuyên mục, bác sĩ)
function hbars(array $items, int $target = 0): string {
    $max = max(1, $target, max(array_column($items, 1) ?: [0]));
    $h = '<ul class="hbars">';
    foreach ($items as [$label, $v, $sub]) {
        $h .= '<li><span class="hb-l">' . e($label) . '</span><span class="hb-t"><span class="hb-f" style="width:' . round($v * 100 / $max, 1) . '%"></span>'
            . ($target ? '<span class="hb-g" style="left:' . round($target * 100 / $max, 1) . '%" title="Mục tiêu ' . $target . '"></span>' : '') . '</span>'
            . '<span class="hb-v">' . $v . ($target ? '/' . $target : '') . ($sub !== '' ? ' <span class="hint">' . e($sub) . '</span>' : '') . '</span></li>';
    }
    return $h . '</ul>';
}

function dashboard_html(array $u): string {
    $mine = $u['role'] === 'bac_si';
    $arts = db()->query('SELECT * FROM articles WHERE deleted_at IS NULL ORDER BY updated_at DESC')->fetchAll();
    if ($mine) $arts = array_values(array_filter($arts, fn($a) => (int)$a['created_by'] === (int)$u['id'] || $a['author_key'] === $u['doctor_key']));
    $pub = array_values(array_filter($arts, fn($a) => $a['status'] === 'published'));
    $pend = array_values(array_filter($arts, fn($a) => $a['status'] === 'pending'));
    $draft = array_values(array_filter($arts, fn($a) => $a['status'] === 'draft'));
    $scores = [];
    foreach ($arts as $a) $scores[$a['id']] = seo_check($a);
    $avg = fn($k) => $pub ? (int)round(array_sum(array_map(fn($a) => $scores[$a['id']][$k], $pub)) / count($pub)) : 0;
    $now = time(); $wk = 7 * 86400;

    $mStats = can('leads') ? get_monthly_lead_stats(6) : [];
    $curM = date('Y-m');
    $prevM = date('Y-m', strtotime('-1 month'));
    $curMData = $mStats[$curM] ?? ['total' => 0, 'dat_lich' => 0, 'cau_hoi' => 0, 'da_lien_he' => 0, 'chua_lien_he' => 0, 'da_hen' => 0, 'rate' => 0];
    $prevMData = $mStats[$prevM] ?? ['total' => 0];
    $mDelta = $curMData['total'] - $prevMData['total'];
    $mDeltaText = ($mDelta >= 0 ? '+' : '') . $mDelta . ' so với tháng trước';
    $uncontactedTotal = 0;
    if (can('leads')) {
        $uncontactedTotal = (int)db()->query("SELECT COUNT(*) FROM leads WHERE status = 'chua_lien_he' AND deleted_at IS NULL")->fetchColumn();
    }

    // ---- Ô số liệu ----
    $tiles = [
        ['Bài đã đăng', count($pub), 'trên website', '?p=articles&s=published'],
        ['Chờ duyệt', count($pend), $pend ? 'cần bác sĩ thẩm định' : 'không có bài chờ', '?p=articles&s=pending'],
        ['Điểm SEO TB', $pub ? $avg('score') : '–', 'các bài đã đăng', '?p=articles'],
        ['Điểm GEO TB', $pub ? $avg('geo') : '–', 'các bài đã đăng', '?p=articles'],
    ];
    if (can('leads')) {
        array_splice($tiles, 2, 0, [
            ['Lead tháng ' . date('n'), $curMData['total'], $mDeltaText, '?p=leads&m=' . $curM],
            ['Chưa liên hệ', $uncontactedTotal, $uncontactedTotal > 0 ? 'Cần gọi điện tư vấn ngay' : 'Đã xử lý tất cả', '?p=leads&st=chua_lien_he'],
            ['Đã chốt hẹn T' . date('n'), $curMData['da_hen'], 'Tỷ lệ xử lý ' . $curMData['rate'] . '%', '?p=leads&st=da_hen&m=' . $curM],
        ]);
    }
    $h = '<div class="tiles">';
    foreach ($tiles as [$l, $v, $s, $href]) {
        $cls = '';
        if (in_array($l, ['Điểm SEO TB', 'Điểm GEO TB'], true) && is_int($v)) $cls = ' ' . ($v >= 80 ? 'ok' : ($v >= 55 ? 'warn' : 'bad'));
        if ($l === 'Chưa liên hệ' && $v > 0) $cls = ' bad';
        if ($l === 'Lead tháng ' . date('n') && $mDelta > 0) $cls = ' ok';
        $h .= '<a class="tile' . $cls . '" href="' . $href . '"><span class="t-l">' . e($l) . '</span><b>' . e((string)$v) . '</b><span class="t-s">' . e($s) . '</span></a>';
    }
    $h .= '</div>';

    // ---- Việc cần làm ----
    $todo = [];
    if (can('leads') && $uncontactedTotal > 0) {
        $todo[] = ['bad', 'Chưa liên hệ', 'Có ' . $uncontactedTotal . ' khách hàng chưa được liên hệ tư vấn', 'Gọi ngay', '?p=leads&st=chua_lien_he'];
    }
    if (can('publish')) foreach ($pend as $a) $todo[] = ['bad', 'Chờ duyệt', $a['title'], 'Thẩm định & đăng', '?p=edit&id=' . $a['id']];
    foreach ($arts as $a) if ($a['status'] === 'draft' && $a['review_note'] !== '' && ((int)$a['created_by'] === (int)$u['id'])) $todo[] = ['bad', 'Cần sửa theo góp ý', $a['title'], 'Mở bài', '?p=edit&id=' . $a['id']];
    foreach ($pub as $a) {
        $s = $scores[$a['id']];
        if ($s['score'] < 80 || $s['geo'] < 80) $todo[] = ['warn', 'Điểm thấp', $a['title'] . ' (SEO ' . $s['score'] . ' · GEO ' . $s['geo'] . ')', 'Cải thiện', '?p=edit&id=' . $a['id']];
        if (!empty($a['reviewed_at']) && $now - strtotime($a['reviewed_at']) > 335 * 86400) $todo[] = ['warn', 'Sắp hết hạn thẩm định', $a['title'] . ' (thẩm định ' . vn_date($a['reviewed_at']) . ')', 'Rà soát lại', '?p=edit&id=' . $a['id']];
    }
    foreach ($draft as $a) if ($now - strtotime($a['updated_at']) > 14 * 86400 && $a['review_note'] === '') $todo[] = ['info', 'Nháp lâu chưa xong', $a['title'] . ' (sửa lần cuối ' . vn_date($a['updated_at']) . ')', 'Viết tiếp', '?p=edit&id=' . $a['id']];
    if (can('pages')) foreach (PAGES as $k => $pg) {
        $f = is_file(GEN_DIR . "/$k.html") ? GEN_DIR . "/$k.html" : PUBLIC_DIR . "/$k.html";
        $html = file_get_contents($f);
        preg_match('#<title>(.*?)</title>#s', $html, $m1); preg_match('#<meta name="description" content="([^"]*)">#', $html, $m2);
        $bad = array_filter(page_seo_check(html_entity_decode($m1[1] ?? '', ENT_QUOTES, 'UTF-8'), html_entity_decode($m2[1] ?? '', ENT_QUOTES, 'UTF-8')), fn($x) => $x[0] === 'bad');
        if ($bad && empty($pg['noindex'])) $todo[] = ['info', 'SEO trang', $pg['label'] . ': ' . $bad[array_key_first($bad)][1], 'Sửa', '?p=pages&k=' . $k];
    }
    if (can('leads') && function_exists('tg_conf')) {
        $tg = tg_conf(DATA_DIR); $tst = tg_state(DATA_DIR);
        if (!tg_ready($tg)) $todo[] = ['warn', 'Thông báo lịch hẹn', 'Chưa bật báo qua Telegram: lịch hẹn mới chỉ thấy khi đăng nhập vào đây', 'Bật ngay', '?p=telegram'];
        elseif ($tst && !$tst['ok']) $todo[] = ['bad', 'Telegram lỗi', 'Lần báo lúc ' . date('H:i d/m', strtotime($tst['at'])) . ' không gửi được: ' . $tst['err'], 'Kiểm tra', '?p=telegram'];
    }
    if (!$mine && count($pub) < 15) $todo[] = ['info', 'Kế hoạch nội dung', 'Đã đăng ' . count($pub) . '/15 bài của kế hoạch 3 tháng đầu', 'Viết bài mới', '?p=edit'];
    $ico = ['bad' => '!', 'warn' => '▲', 'info' => '•'];
    $h .= '<div class="dash-grid"><section class="card todo"><h2>Việc cần làm <span class="badge ' . ($todo ? 'orange' : 'green') . '">' . count($todo) . '</span></h2>';
    if (!$todo) $h .= '<p class="hint">Không có việc tồn đọng. Mọi bài và khách hàng đã được xử lý.</p>';
    else {
        $h .= '<ul class="todo-list">';
        foreach (array_slice($todo, 0, 10) as [$lv, $tag, $t, $btn, $href])
            $h .= '<li class="' . $lv . '"><span class="sev" aria-hidden="true">' . $ico[$lv] . '</span><span class="td-t"><span class="td-tag">' . e($tag) . '</span>' . e($t) . '</span><a href="' . $href . '" class="btn mini">' . e($btn) . '</a></li>';
        $h .= '</ul>' . (count($todo) > 10 ? '<p class="hint">Và ' . (count($todo) - 10) . ' việc khác.</p>' : '');
    }
    $h .= '</section>';

    // ---- Biểu đồ lead hàng tháng & bài viết ----
    if (can('leads')) {
        $mLabels = array_column($mStats, 'short_label');
        $mVals = array_column($mStats, 'total');
        $h .= '<section class="card"><h2>Thống kê lead theo tháng</h2>'
            . '<p class="hint">Tổng số khách đặt lịch và câu hỏi qua website mỗi tháng (6 tháng gần nhất).</p>'
            . svg_bars($mLabels, $mVals, 'lead', 'Lead hàng tháng') . '</section>';
    } else {
        $labels = []; $vals = [];
        for ($i = 5; $i >= 0; $i--) { $m = strtotime(date('Y-m-01') . " -$i month"); $labels[] = 'T' . date('n', $m); $vals[] = count(array_filter($pub, fn($a) => date('Y-m', strtotime($a['published_at'])) === date('Y-m', $m))); }
        $h .= '<section class="card"><h2>Bài đăng theo tháng</h2>' . svg_bars($labels, $vals, 'bài', 'Bài đăng theo tháng') . '</section>';
    }

    if (can('leads')) {
        $labels = []; $vals = [];
        $all = db()->query("SELECT published_at FROM articles WHERE status = 'published' AND deleted_at IS NULL")->fetchAll(PDO::FETCH_COLUMN);
        for ($i = 5; $i >= 0; $i--) { $m = strtotime(date('Y-m-01') . " -$i month"); $labels[] = 'T' . date('n', $m); $vals[] = count(array_filter($all, fn($d) => date('Y-m', strtotime($d)) === date('Y-m', $m))); }
        $h .= '<section class="card"><h2>Bài đăng theo tháng</h2><p class="hint">Nên đều đặn 1–2 bài mỗi tuần để Google và AI thấy website hoạt động.</p>' . svg_bars($labels, $vals, 'bài', 'Bài đăng theo tháng') . '</section>';
    }

    // Bảng thống kê lead hàng tháng
    if (can('leads')) {
        $h .= '<section class="card" style="grid-column:1/-1"><h2>Bảng thống kê lead hàng tháng</h2>'
            . '<p class="hint">Tổng hợp chi tiết số lượng khách hàng gửi thông tin và tỷ lệ nhân viên đã liên hệ chăm sóc phụ huynh.</p>'
            . '<div class="wrap"><table><thead><tr>'
            . '<th>Tháng</th><th>Lịch hẹn</th><th>Câu hỏi</th><th>Tổng lead</th><th>Đã liên hệ</th><th>Đã hẹn khám</th><th>Chưa liên hệ</th><th>Tỷ lệ xử lý</th><th>Thao tác</th>'
            . '</tr></thead><tbody>';
        $reversedMStats = array_reverse($mStats);
        foreach ($reversedMStats as $ms) {
            $isThisMonth = ($ms['month'] === $curM);
            $tag = $isThisMonth ? ' <span class="badge green">Tháng này</span>' : '';
            $clhBadge = $ms['chua_lien_he'] > 0 
                ? '<span class="badge orange">' . $ms['chua_lien_he'] . ' chờ</span>' 
                : '<span class="hint">0</span>';
            $h .= '<tr>'
                . '<td class="nowrap"><b>' . e($ms['label']) . '</b>' . $tag . '</td>'
                . '<td>' . $ms['dat_lich'] . '</td>'
                . '<td>' . $ms['cau_hoi'] . '</td>'
                . '<td><b style="font-size:16px">' . $ms['total'] . '</b></td>'
                . '<td>' . $ms['da_lien_he'] . '</td>'
                . '<td><span class="badge blue">' . $ms['da_hen'] . '</span></td>'
                . '<td>' . $clhBadge . '</td>'
                . '<td><b>' . $ms['rate'] . '%</b></td>'
                . '<td><a href="?p=leads&m=' . e($ms['month']) . '" class="btn mini ghost">Xem danh sách →</a></td>'
                . '</tr>';
        }
        $h .= '</tbody></table></div></section>';
    }

    // Chuyên mục so với kế hoạch (15 bài: 4/4/3/4)
    $plan = ['thoidiem' => 4, 'phuongphap' => 4, 'thoiquen' => 3, 'chamsoc' => 4];
    $items = [];
    foreach (CATEGORIES as $k => $l) $items[] = [$l, count(array_filter($pub, fn($a) => $a['category'] === $k)), ''];
    $h .= '<section class="card"><h2>Bài theo chuyên mục</h2><p class="hint">' . ($mine ? 'Các bài bạn đã viết, theo chuyên mục. Vạch dọc là mục tiêu chung của cả đội.' : 'So với kế hoạch 15 bài đầu tiên. Vạch dọc là mục tiêu.') . '</p><ul class="hbars">';
    foreach (CATEGORIES as $k => $l) {
        $v = count(array_filter($pub, fn($a) => $a['category'] === $k)); $t = $plan[$k]; $mx = max($t, $v);
        $h .= '<li><span class="hb-l">' . e($l) . '</span><span class="hb-t"><span class="hb-f" style="width:' . round($v * 100 / $mx, 1) . '%"></span><span class="hb-g" style="left:calc(' . round($t * 100 / $mx, 1) . '% - 1px)"></span></span><span class="hb-v">' . $v . '/' . $t . '</span></li>';
    }
    $h .= '</ul></section>';

    if (!$mine) {
        $items = [];
        foreach (DOCTORS as $k => $d) {
            $n = count(array_filter($pub, fn($a) => $a['author_key'] === $k));
            $rv = count(array_filter($pub, fn($a) => $a['reviewer_key'] === $k));
            $items[] = [$d['name'], $n, $rv ? "· duyệt $rv" : ''];
        }
        $h .= '<section class="card"><h2>Bài theo bác sĩ</h2><p class="hint">Số bài mỗi bác sĩ là tác giả. Nhiều bác sĩ cùng viết giúp tăng độ tin cậy (E-E-A-T).</p>' . hbars($items) . '</section>';
    }

    // Bài cần cải thiện
    $low = $pub;
    usort($low, fn($p, $q) => ($scores[$p['id']]['score'] + $scores[$p['id']]['geo']) <=> ($scores[$q['id']]['score'] + $scores[$q['id']]['geo']));
    $h .= '<section class="card"><h2>Bài điểm thấp nhất</h2>';
    if (!$low) $h .= '<p class="hint">Chưa có bài đã đăng.</p>';
    else {
        $h .= '<div class="wrap"><table><tr><th>Bài</th><th>SEO</th><th>GEO</th></tr>';
        foreach (array_slice($low, 0, 5) as $a) { $s = $scores[$a['id']]; $h .= '<tr><td><a href="?p=edit&id=' . $a['id'] . '">' . e($a['title']) . '</a></td><td><span class="score ' . score_class($s['score']) . '">' . $s['score'] . '</span></td><td><span class="score ' . score_class($s['geo']) . '">' . $s['geo'] . '</span></td></tr>'; }
        $h .= '</table></div>';
    }
    $h .= '</section>';

    if (can('leads')) {
        $h .= '<section class="card"><h2>Lịch hẹn mới nhất</h2>';
        $recentLeads = db()->query("SELECT l.*, u.display_name as staff_name FROM leads l LEFT JOIN users u ON u.id = l.contacted_by WHERE l.source_type = 'dat-lich' AND l.deleted_at IS NULL ORDER BY l.created_at DESC LIMIT 5")->fetchAll();
        if (!$recentLeads) $h .= '<p class="hint">Chưa có lịch hẹn nào.</p>';
        else {
            $h .= '<div class="wrap"><table><tr><th>Lúc</th><th>Phụ huynh</th><th>Điện thoại</th><th>Lo về</th><th>Trạng thái</th></tr>';
            foreach ($recentLeads as $r) {
                $stInfo = LEAD_STATUSES[$r['status']] ?? ['label' => $r['status'], 'badge' => 'gray'];
                $stBadge = '<span class="badge ' . $stInfo['badge'] . '">' . e($stInfo['label']) . '</span>';
                $h .= '<tr><td class="nowrap">' . e(date('d/m H:i', strtotime($r['created_at']))) . '</td><td>' . e($r['name'] ?? '') . '</td><td class="nowrap">' . e($r['phone'] ?? '') . '</td><td>' . e($r['concerns'] ?? '') . '</td><td>' . $stBadge . '</td></tr>';
            }
            $h .= '</table></div><p><a href="?p=leads">Xem tất cả lịch hẹn →</a></p>';
        }
        $h .= '</section>';
    }

    $sql = "SELECT a.*, u.display_name FROM activity a LEFT JOIN users u ON u.id = a.user_id WHERE a.action NOT LIKE 'Đăng nhập%' AND a.action NOT LIKE 'Đăng xuất%'" . ($mine ? ' AND a.user_id = ' . (int)$u['id'] : '') . ' ORDER BY a.id DESC LIMIT 8';
    $act = db()->query($sql)->fetchAll();
    if (!$act) $act = [];
    $h .= '<section class="card"><h2>' . ($mine ? 'Hoạt động của bạn' : 'Hoạt động gần đây') . '</h2>' . (!$act ? '<p class="hint">Chưa có hoạt động.</p>' : '') . '<ul class="feed">';
    foreach ($act as $x) $h .= '<li><span class="hint nowrap">' . e(date('d/m H:i', strtotime($x['at']))) . '</span><span><b>' . e($x['display_name'] ?? '') . '</b> ' . e($x['action']) . '</span></li>';
    $h .= '</ul></section></div>';
    return $h;
}
