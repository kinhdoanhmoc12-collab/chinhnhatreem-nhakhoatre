<?php
require_once __DIR__ . '/db.php';

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('nkt_admin');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/quan-tri/', 'secure' => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
        'httponly' => true, 'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '28800');
    session_start();
    // Hết phiên sau 8 giờ không thao tác
    if (isset($_SESSION['seen']) && time() - $_SESSION['seen'] > 28800) { $_SESSION = []; session_regenerate_id(true); }
    $_SESSION['seen'] = time();
}

function current_user(): ?array {
    static $u = false;
    if ($u !== false) return $u;
    $id = $_SESSION['uid'] ?? null;
    if (!$id) return $u = null;
    $st = db()->prepare('SELECT * FROM users WHERE id = ? AND active = 1');
    $st->execute([$id]);
    $row = $st->fetch() ?: null;
    // Mật khẩu đã bị đổi/cấp lại ở nơi khác -> phiên cũ hết hiệu lực
    if ($row && !hash_equals((string)($_SESSION['pwf'] ?? ''), pw_fingerprint($row['pass_hash']))) { $_SESSION = []; session_regenerate_id(true); $row = null; }
    return $u = $row;
}

function pw_fingerprint(string $hash): string { return substr(hash('sha256', $hash), 0, 20); }

function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }

function too_many_fails(string $username): bool {
    $since = time() - 900;
    // Khóa theo cặp IP + tài khoản (8 lần) và theo IP (30 lần) để không khóa nhầm cả phòng khám
    $st = db()->prepare('SELECT SUM(username = ?), COUNT(*) FROM login_fail WHERE at > ? AND ip = ?');
    $st->execute([mb_strtolower($username), $since, client_ip()]);
    [$pair, $ip] = $st->fetch(PDO::FETCH_NUM);
    return (int)$pair >= 8 || (int)$ip >= 30;
}

function attempt_login(string $username, string $password): string {
    $username = mb_strtolower(trim($username));
    db()->prepare('DELETE FROM login_fail WHERE at < ?')->execute([time() - 86400]);
    if (too_many_fails($username)) return 'Đăng nhập sai quá nhiều lần. Vui lòng thử lại sau 15 phút.';
    $st = db()->prepare('SELECT * FROM users WHERE username = ? AND active = 1');
    $st->execute([$username]);
    $u = $st->fetch();
    if (!$u || !password_verify($password, $u['pass_hash'])) {
        db()->prepare('INSERT INTO login_fail (ip, username, at) VALUES (?,?,?)')->execute([client_ip(), $username, time()]);
        if (!$u) password_hash($password, PASSWORD_DEFAULT); // cân thời gian phản hồi
        return 'Sai tên đăng nhập hoặc mật khẩu.';
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['pwf'] = pw_fingerprint($u['pass_hash']);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    db()->prepare('DELETE FROM login_fail WHERE username = ?')->execute([$username]);
    log_activity((int)$u['id'], 'Đăng nhập');
    return '';
}

function logout(): void {
    $_SESSION = [];
    session_regenerate_id(true);
    session_destroy();
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function check_csrf(): void {
    $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) { http_response_code(403); exit('Phiên làm việc đã hết hạn. Vui lòng tải lại trang.'); }
}

function can(string $what, ?array $u = null): bool {
    $u = $u ?? current_user();
    if (!$u) return false;
    $r = $u['role'];
    switch ($what) {
        case 'publish':  return in_array($r, ['quan_tri', 'tham_dinh'], true);
        case 'pages':    return $r === 'quan_tri';
        case 'users':    return $r === 'quan_tri';
        case 'leads':    return $r === 'quan_tri';
        case 'write':    return true;
    }
    return false;
}

// Xóa bài: bác sĩ thẩm định/quản trị xóa mọi bài; bác sĩ viết bài chỉ xóa bài của mình chưa đăng
function can_delete_article(array $a, ?array $u = null): bool {
    $u = $u ?? current_user();
    if (!$u) return false;
    if (can('publish', $u)) return true;
    return (int)$a['created_by'] === (int)$u['id'] && $a['status'] !== 'published';
}

function can_edit_article(array $a, ?array $u = null): bool {
    $u = $u ?? current_user();
    if (!$u) return false;
    if (can('publish', $u)) return true;
    // Bác sĩ viết bài: chỉ sửa bài của mình khi chưa đăng
    return (int)$a['created_by'] === (int)$u['id'] && $a['status'] !== 'published';
}

function make_password(int $n = 12): string {
    $c = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < $n; $i++) $s .= $c[random_int(0, strlen($c) - 1)];
    return $s;
}
