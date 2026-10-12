<?php
// Gửi thông báo Telegram khi bố mẹ điền form. Dùng chung cho /api và trang quản trị.
// Cấu hình lưu ở <thư mục dữ liệu>/telegram.json (ngoài thư mục web, chỉ www-data đọc được).

function tg_conf_path(string $dir): string { return $dir . '/telegram.json'; }
function tg_state_path(string $dir): string { return $dir . '/telegram-trang-thai.json'; }

function tg_conf(string $dir): array {
    $f = tg_conf_path($dir);
    $c = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    return is_array($c) ? $c + ['token' => '', 'chat_id' => '', 'chat_name' => '', 'dat_lich' => true, 'cau_hoi' => true] : ['token' => '', 'chat_id' => '', 'chat_name' => '', 'dat_lich' => true, 'cau_hoi' => true];
}

function tg_save_conf(string $dir, array $c): void {
    $f = tg_conf_path($dir);
    file_put_contents($f, json_encode($c, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    @chmod($f, 0600);
}

function tg_ready(array $c): bool { return $c['token'] !== '' && $c['chat_id'] !== ''; }

function tg_valid_token(string $t): bool { return (bool)preg_match('/^\d{5,15}:[A-Za-z0-9_-]{30,60}$/', $t); }

// Gọi Bot API. Trả về mảng JSON của Telegram, hoặc ['ok'=>false,'description'=>...] khi lỗi mạng.
function tg_api(string $token, string $method, array $params = [], int $timeout = 8): array {
    $url = (getenv('NKT_TG_API') ?: 'https://api.telegram.org') . '/bot' . $token . '/' . $method;
    $body = http_build_query($params);
    $raw = false; $err = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => $timeout, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]);
        $raw = curl_exec($ch);
        if ($raw === false) $err = curl_error($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) $err = 'Không kết nối được tới Telegram';
    }
    $j = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($j)) return ['ok' => false, 'description' => $err ?: 'Telegram trả về dữ liệu lạ'];
    return $j;
}

// Gửi 1 tin nhắn tới nhóm đã cấu hình. Tự cập nhật mã nhóm khi Telegram nâng cấp nhóm lên "siêu nhóm".
function tg_send(string $dir, string $text): array {
    $c = tg_conf($dir);
    if (!tg_ready($c)) return ['ok' => false, 'description' => 'Chưa cấu hình'];
    $p = ['chat_id' => $c['chat_id'], 'text' => mb_substr($text, 0, 4000), 'disable_web_page_preview' => 'true'];
    $r = tg_api($c['token'], 'sendMessage', $p);
    $mig = $r['parameters']['migrate_to_chat_id'] ?? null;
    if (!$r['ok'] && $mig) {
        $c['chat_id'] = (string)$mig; tg_save_conf($dir, $c);
        $p['chat_id'] = $c['chat_id'];
        $r = tg_api($c['token'], 'sendMessage', $p);
    }
    $st = ['ok' => (bool)$r['ok'], 'at' => date('Y-m-d H:i:s'), 'err' => $r['ok'] ? '' : (string)($r['description'] ?? 'Lỗi không rõ')];
    @file_put_contents(tg_state_path($dir), json_encode($st, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $r;
}

function tg_state(string $dir): ?array {
    $f = tg_state_path($dir);
    $s = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    return is_array($s) ? $s : null;
}

// Soạn tin báo form mới (chữ thường, không định dạng, để không lỗi khi bố mẹ gõ ký tự đặc biệt)
function tg_form_message(string $loai, array $cot, string $siteUrl): string {
    $tieuDe = $loai === 'dat-lich' ? '🔔 LỊCH HẸN MỚI từ chinhnhatreem.com' : '💬 CÂU HỎI MỚI từ chinhnhatreem.com';
    $t = $tieuDe . "\n";
    foreach ($cot as $k => $v) { $v = ltrim((string)$v, "'"); if ($v !== '') $t .= "\n" . $k . ': ' . $v; }
    $t .= "\n\n🕒 " . date('H:i d/m/Y');
    if ($loai === 'dat-lich') $t .= "\nNhớ gọi lại cho bố mẹ trong 24 giờ làm việc.";
    $t .= "\nXem tất cả: " . $siteUrl . '/quan-tri/?p=leads' . ($loai === 'cau-hoi' ? '&xem=cau-hoi' : '');
    return $t;
}

// Tìm các nhóm/cuộc trò chuyện mà bot đã được thêm vào (từ tin nhắn gần đây)
function tg_find_chats(string $token): array {
    $r = tg_api($token, 'getUpdates', ['limit' => 100, 'allowed_updates' => json_encode(['message', 'my_chat_member', 'channel_post'])]);
    if (!$r['ok']) return ['ok' => false, 'err' => (string)($r['description'] ?? 'Lỗi')];
    $chats = [];
    foreach ($r['result'] as $u) {
        foreach (['message', 'my_chat_member', 'channel_post'] as $k) {
            $ch = $u[$k]['chat'] ?? null;
            if (!$ch) continue;
            $name = $ch['title'] ?? trim(($ch['first_name'] ?? '') . ' ' . ($ch['last_name'] ?? ''));
            $kind = ['private' => 'Tin nhắn riêng', 'group' => 'Nhóm', 'supergroup' => 'Nhóm', 'channel' => 'Kênh'][$ch['type']] ?? $ch['type'];
            $chats[(string)$ch['id']] = ['id' => (string)$ch['id'], 'name' => $name ?: '(không tên)', 'kind' => $kind];
        }
    }
    return ['ok' => true, 'chats' => array_values($chats)];
}
