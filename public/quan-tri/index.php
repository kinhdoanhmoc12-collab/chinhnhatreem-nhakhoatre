<?php
// Trang quản trị chinhnhatreem.com
require_once dirname(__DIR__, 2) . '/app/auth.php';
require_once dirname(__DIR__, 2) . '/app/seo.php';
require_once dirname(__DIR__, 2) . '/app/render.php';
require_once dirname(__DIR__, 2) . '/app/telegram.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
start_session();

$p = $_GET['p'] ?? 'home';
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') check_csrf_if_logged_in();

function check_csrf_if_logged_in(): void { if (!empty($_SESSION['uid']) || ($_GET['p'] ?? '') === 'login') check_csrf(); }
function go(string $to, string $flash = ''): never { if ($flash) $_SESSION['flash'] = $flash; header('Location: ?p=' . $to); exit; }
function json_out($d): never { header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function deny(): never { http_response_code(403); layout('Không có quyền', '<div class="card"><p>Tài khoản của bạn không có quyền vào mục này.</p></div>'); exit; }
function badge(string $s): string {
    $m = ['draft' => ['Nháp', 'gray'], 'pending' => ['Chờ duyệt', 'orange'], 'published' => ['Đã đăng', 'green']];
    return '<span class="badge ' . $m[$s][1] . '">' . $m[$s][0] . '</span>';
}
function review_card(array $a): string {
    $last = '';
    if (!empty($a['reviewed_at'])) {
        $j = json_decode($a['review_checks'] ?: '{}', true);
        $last = '<p class="hint">Lần thẩm định gần nhất: ' . e(vn_date($a['reviewed_at'])) . (!empty($j['by']) ? ' bởi ' . e($j['by']) : '') . '. Mỗi lần đăng hoặc cập nhật cần xác nhận lại.</p>';
    }
    $h = '<div class="card review"><h2>Kiểm duyệt y khoa</h2><p class="hint">Bắt buộc trước khi đăng. Máy chỉ kiểm tra được cấu trúc, còn độ chính xác y khoa do bác sĩ xác nhận.</p>';
    foreach (REVIEW_CHECKLIST as $k => $t) $h .= '<label class="chk"><input type="checkbox" name="rv[]" value="' . e($k) . '"> <span>' . e($t) . '</span></label>';
    return $h . $last . '</div>';
}
function score_class(int $s): string { return $s >= 80 ? 'green' : ($s >= 55 ? 'orange' : 'red'); }
function opt(array $list, string $sel, bool $useLabelKey = false): string {
    $o = '';
    foreach ($list as $k => $v) { $label = is_array($v) ? $v['name'] : $v; $o .= '<option value="' . e($k) . '"' . ($k === $sel ? ' selected' : '') . '>' . e($label) . '</option>'; }
    return $o;
}

function layout(string $title, string $body): void {
    $u = current_user();
    $flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
    $nav = '';
    if ($u) {
        $items = [['home', 'Bảng điều khiển'], ['articles', 'Bài viết'], ['edit', '+ Viết bài mới']];
        if (can('pages')) $items[] = ['pages', 'SEO từng trang'];
        if (can('leads')) { $items[] = ['leads', 'Lịch hẹn & câu hỏi']; $items[] = ['telegram', 'Báo qua Telegram']; }
        if (can('users')) $items[] = ['users', 'Tài khoản'];
        $items[] = ['account', 'Đổi mật khẩu'];
        $cur = $_GET['p'] ?? 'home';
        foreach ($items as [$k, $l]) $nav .= '<a href="?p=' . $k . '"' . ($cur === $k ? ' class="on"' : '') . '>' . $l . '</a>';
        $nav .= '<form method="post" action="?p=logout">' . csrf_field() . '<button class="link">Đăng xuất</button></form>';
    }
    $who = $u ? '<span class="who">' . e($u['display_name']) . ' · ' . e(ROLES[$u['role']]) . '</span>' : '';
    echo '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
        . '<title>' . e($title) . ' · Quản trị Nha Khoa Trẻ</title><link rel="icon" href="/images/logo-nha-khoa-tre.png"><link rel="stylesheet" href="admin.css?v=13"></head><body>'
        . '<header class="top"><a href="?p=home" class="brand"><img src="/images/logo-nha-khoa-tre.webp" alt="" height="36"> Quản trị</a>' . $who . '<a href="/" target="_blank" class="site">Xem website ↗</a></header>'
        . ($u ? '<nav class="side">' . $nav . '</nav>' : '')
        . '<main class="' . ($u ? 'with-side' : 'solo') . '">' . ($flash ? '<div class="flash">' . e($flash) . '</div>' : '') . '<h1>' . e($title) . '</h1>' . $body . '</main>'
        . '<script src="admin.js?v=11"></script></body></html>';
}

// ---------- Đăng nhập ----------
if ($p === 'login') {
    $err = '';
    if ($method === 'POST') {
        $err = attempt_login((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
        if ($err === '') go(current_user() && current_user()['must_change'] ? 'account' : 'home');
    }
    layout('Đăng nhập', '<form method="post" class="card narrow">' . csrf_field()
        . ($err ? '<p class="err">' . e($err) . '</p>' : '')
        . '<label>Tên đăng nhập<input name="username" autocomplete="username" required autofocus></label>'
        . '<label>Mật khẩu<input type="password" name="password" autocomplete="current-password" required></label>'
        . '<button class="btn">Đăng nhập</button></form>');
    exit;
}
$u = current_user();
if (!$u) go('login');
if ($p === 'logout' && $method === 'POST') { log_activity((int)$u['id'], 'Đăng xuất'); logout(); header('Location: ?p=login'); exit; }
if ($u['must_change'] && !in_array($p, ['account'], true)) go('account', 'Vui lòng đổi mật khẩu tạm thời trước khi sử dụng.');

// ---------- Đổi mật khẩu ----------
if ($p === 'account') {
    $msg = '';
    if ($method === 'POST') {
        $old = (string)($_POST['old'] ?? ''); $new = (string)($_POST['new'] ?? ''); $new2 = (string)($_POST['new2'] ?? '');
        if (!password_verify($old, $u['pass_hash'])) $msg = 'Mật khẩu hiện tại không đúng.';
        elseif (mb_strlen($new) < 10) $msg = 'Mật khẩu mới cần ít nhất 10 ký tự.';
        elseif ($new !== $new2) $msg = 'Hai lần nhập mật khẩu mới không khớp.';
        else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            db()->prepare('UPDATE users SET pass_hash = ?, must_change = 0 WHERE id = ?')->execute([$hash, $u['id']]);
            session_regenerate_id(true);
            $_SESSION['pwf'] = pw_fingerprint($hash); // các thiết bị khác đang đăng nhập sẽ bị đăng xuất
            log_activity((int)$u['id'], 'Đổi mật khẩu');
            go('home', 'Đã đổi mật khẩu.');
        }
    }
    layout('Đổi mật khẩu', '<form method="post" class="card narrow">' . csrf_field() . ($msg ? '<p class="err">' . e($msg) . '</p>' : '')
        . '<label>Mật khẩu hiện tại<input type="password" name="old" required autocomplete="current-password"></label>'
        . '<label>Mật khẩu mới (ít nhất 10 ký tự)<input type="password" name="new" required minlength="10" autocomplete="new-password"></label>'
        . '<label>Nhập lại mật khẩu mới<input type="password" name="new2" required minlength="10" autocomplete="new-password"></label>'
        . '<button class="btn">Lưu mật khẩu</button></form>');
    exit;
}

// ---------- Tải ảnh lên ----------
if ($p === 'upload' && $method === 'POST') {
    $r = save_upload($_FILES['file'] ?? [], (string)($_POST['name'] ?? 'anh'));
    if (!empty($r['url'])) log_activity((int)$u['id'], 'Tải ảnh ' . $r['url']);
    json_out($r);
}

// ---------- Chấm điểm SEO trực tiếp ----------
if ($p === 'seo' && $method === 'POST') {
    $d = $_POST;
    $d['content'] = clean_html((string)($d['content'] ?? ''));
    $d['slug'] = slugify((string)($d['slug'] ?? '') ?: (string)($d['title'] ?? ''), 12);
    $d['id'] = (int)($_POST['id'] ?? 0);
    $r = seo_check($d);
    $r['html'] = seo_checks_html($r);
    unset($r['checks']);
    json_out($r);
}

// ---------- Tổng quan ----------
if ($p === 'home') {
    require_once dirname(__DIR__, 2) . '/app/dashboard.php';
    $b = '<p class="sub">Xin chào ' . e($u['display_name']) . '. Hôm nay ' . date('d/m/Y') . '.</p>' . dashboard_html($u)
        . '<details class="card guide-box"><summary><b>Hướng dẫn nhanh</b></summary><ol class="guide"><li>Bấm <b>+ Viết bài mới</b>, điền tiêu đề, từ khóa chính và nội dung.</li><li>Xem điểm SEO (Google) và điểm GEO (AI trích dẫn) bên phải, sửa các mục màu đỏ cho đến khi cả hai đạt từ 80 điểm.</li>'
        . '<li>Bác sĩ viết bài bấm <b>Gửi duyệt</b>. Bác sĩ thẩm định tick phiếu <b>Kiểm duyệt y khoa</b> rồi bấm <b>Duyệt &amp; đăng bài</b>.</li><li>Bài đăng xong tự có trang riêng, tự vào danh sách bài, trang chủ và sitemap cho Google.</li></ol></details>';
    layout('Bảng điều khiển', $b); exit;
}

// ---------- Danh sách bài ----------
if ($p === 'articles') {
    // Thùng rác: tự xóa hẳn bài đã nằm trong thùng rác quá 30 ngày
    db()->prepare('DELETE FROM articles WHERE deleted_at IS NOT NULL AND deleted_at < ?')->execute([date('Y-m-d H:i:s', time() - 30 * 86400)]);
    if ($method === 'POST') {
        $act = (string)($_POST['action'] ?? '');
        $st = db()->prepare('SELECT * FROM articles WHERE id = ?'); $st->execute([(int)($_POST['id'] ?? 0)]);
        $a = $st->fetch();
        if (!$a) go('articles', 'Không tìm thấy bài.');
        $back = (string)($_POST['back'] ?? '');
        $back = in_array($back, ['draft', 'pending', 'published', 'trash'], true) ? 'articles&s=' . $back : 'articles';
        if ($act === 'trash' && $a['deleted_at'] === null) {
            if (!can_delete_article($a)) deny();
            $wasPub = $a['status'] === 'published';
            db()->prepare("UPDATE articles SET deleted_at = ?, status = 'draft', updated_at = ? WHERE id = ?")->execute([now(), now(), $a['id']]);
            if ($wasPub) { db()->prepare('INSERT OR REPLACE INTO gone_slugs (slug, at) VALUES (?,?)')->execute([$a['slug'], now()]); publish_all(); }
            log_activity((int)$u['id'], 'Chuyển vào thùng rác: ' . $a['title']);
            go($back, $wasPub ? 'Đã gỡ bài khỏi website và chuyển vào Thùng rác. Đường dẫn cũ tự chuyển về trang Kiến thức chỉnh nha.' : 'Đã chuyển bài vào Thùng rác. Có thể khôi phục trong 30 ngày.');
        }
        if ($act === 'restore' && $a['deleted_at'] !== null) {
            if (!can_delete_article($a)) deny();
            db()->prepare('UPDATE articles SET deleted_at = NULL, updated_at = ? WHERE id = ?')->execute([now(), $a['id']]);
            log_activity((int)$u['id'], 'Khôi phục bài: ' . $a['title']);
            go('edit&id=' . $a['id'], 'Đã khôi phục bài về dạng Nháp. Muốn hiện lại trên web thì cần thẩm định và đăng lại.');
        }
        if ($act === 'purge' && $a['deleted_at'] !== null) {
            if ($u['role'] !== 'quan_tri') deny();
            db()->prepare('DELETE FROM articles WHERE id = ?')->execute([$a['id']]);
            log_activity((int)$u['id'], 'Xóa vĩnh viễn bài: ' . $a['title']);
            go('articles&s=trash', 'Đã xóa vĩnh viễn.');
        }
        go($back);
    }
    $f = $_GET['s'] ?? '';
    $trash = $f === 'trash';
    $sql = 'SELECT a.*, u.display_name FROM articles a LEFT JOIN users u ON u.id = a.created_by WHERE a.deleted_at IS ' . ($trash ? 'NOT NULL' : 'NULL');
    $par = [];
    if (in_array($f, ['draft', 'pending', 'published'], true)) { $sql .= ' AND a.status = ?'; $par[] = $f; }
    if ($trash && !can('publish')) { $sql .= ' AND a.created_by = ?'; $par[] = $u['id']; }
    $sql .= $trash ? ' ORDER BY a.deleted_at DESC' : ' ORDER BY a.updated_at DESC';
    $st = db()->prepare($sql); $st->execute($par);
    $rows = $st->fetchAll();
    $nTrash = db()->prepare('SELECT COUNT(*) FROM articles WHERE deleted_at IS NOT NULL' . (can('publish') ? '' : ' AND created_by = ?'));
    $nTrash->execute(can('publish') ? [] : [$u['id']]); $nTrash = (int)$nTrash->fetchColumn();
    $tab = fn($k, $l) => '<a href="?p=articles' . ($k ? '&s=' . $k : '') . '"' . ($f === $k ? ' class="on"' : '') . '>' . $l . '</a>';
    $b = '<div class="tabs">' . $tab('', 'Tất cả') . $tab('published', 'Đã đăng') . $tab('pending', 'Chờ duyệt') . $tab('draft', 'Nháp') . $tab('trash', 'Thùng rác' . ($nTrash ? ' (' . $nTrash . ')' : '')) . '</div>';
    $form = fn($a, $act, $label, $cls, $confirm) => '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="id" value="' . $a['id'] . '"><input type="hidden" name="back" value="' . e($f) . '"><button class="link ' . $cls . '" name="action" value="' . $act . '"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . $label . '</button></form>';
    if ($trash) {
        $b .= '<div class="card"><p class="hint">Bài trong Thùng rác không hiện trên website và tự xóa hẳn sau 30 ngày. Khôi phục sẽ đưa bài về dạng Nháp.</p>';
        if (!$rows) $b .= '<p>Thùng rác trống.</p>';
        else {
            $b .= '<div class="wrap"><table><tr><th>Tiêu đề</th><th>Tác giả</th><th>Xóa lúc</th><th>Tự xóa hẳn sau</th><th></th></tr>';
            foreach ($rows as $a) {
                $left = max(0, 30 - (int)floor((time() - strtotime($a['deleted_at'])) / 86400));
                $b .= '<tr><td><b>' . e($a['title']) . '</b></td><td>' . e(DOCTORS[$a['author_key']]['name'] ?? '') . '</td><td>' . e(date('H:i d/m/Y', strtotime($a['deleted_at']))) . '</td><td>' . $left . ' ngày</td><td class="nowrap">'
                    . $form($a, 'restore', 'Khôi phục', '', '')
                    . ($u['role'] === 'quan_tri' ? ' · ' . $form($a, 'purge', 'Xóa vĩnh viễn', 'del', 'Xóa vĩnh viễn bài "' . $a['title'] . '"? Không thể khôi phục.') : '') . '</td></tr>';
            }
            $b .= '</table></div>';
        }
        $b .= '</div>';
        layout('Bài viết', $b); exit;
    }
    $b .= '<div class="card"><div class="wrap"><table><tr><th>Tiêu đề</th><th>Chuyên mục</th><th>Tác giả</th><th>SEO</th><th>GEO</th><th>Trạng thái</th><th>Cập nhật</th></tr>';
    foreach ($rows as $a) {
        $r = seo_check($a); $sc = $r['score']; $gc = $r['geo'];
        $acts = [];
        if ($a['status'] === 'published') $acts[] = '<a href="/bai-viet/' . e($a['slug']) . '" target="_blank" class="small">Xem ↗</a>';
        if (can_delete_article($a)) $acts[] = $form($a, 'trash', 'Xóa', 'del small', $a['status'] === 'published'
            ? 'Bài "' . $a['title'] . '" đang hiện trên website. Xóa sẽ gỡ bài khỏi web và chuyển vào Thùng rác (khôi phục được trong 30 ngày). Tiếp tục?'
            : 'Chuyển bài "' . $a['title'] . '" vào Thùng rác? Có thể khôi phục trong 30 ngày.');
        $b .= '<tr><td><a href="?p=edit&id=' . $a['id'] . '"><b>' . e($a['title']) . '</b></a>' . ($acts ? '<div class="row-acts">' . implode('<span aria-hidden="true">·</span>', $acts) . '</div>' : '') . '</td><td>' . e(CATEGORIES[$a['category']] ?? '') . '</td><td>' . e(DOCTORS[$a['author_key']]['name'] ?? '') . '</td>'
            . '<td><span class="score ' . score_class($sc) . '">' . $sc . '</span></td><td><span class="score ' . score_class($gc) . '">' . $gc . '</span></td><td>' . badge($a['status']) . '</td><td>' . e(vn_date($a['updated_at'])) . '</td></tr>';
    }
    $b .= '</table></div></div>';
    layout('Bài viết', $b); exit;
}

// ---------- Xem trước ----------
if ($p === 'preview') {
    $st = db()->prepare('SELECT * FROM articles WHERE id = ?'); $st->execute([(int)($_GET['id'] ?? 0)]);
    $a = $st->fetch();
    if (!$a || !can_edit_article($a) && !can('publish')) deny();
    $html = render_article($a);
    echo str_replace('<body>', '<body><div style="position:sticky;top:0;z-index:99;background:#E8722A;color:#fff;text-align:center;padding:8px;font:600 14px system-ui">BẢN XEM TRƯỚC – chưa hiển thị công khai nếu bài chưa đăng</div>', $html);
    exit;
}

// ---------- Soạn / sửa bài ----------
if ($p === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    $a = null;
    if ($id) { $st = db()->prepare('SELECT * FROM articles WHERE id = ?'); $st->execute([$id]); $a = $st->fetch(); if (!$a) go('articles', 'Không tìm thấy bài.'); if ($a['deleted_at'] !== null) go('articles&s=trash', 'Bài này đang ở Thùng rác. Bấm Khôi phục để sửa tiếp.'); }
    $readonly = $a && !can_edit_article($a);
    $errors = [];
    if ($method === 'POST' && !$readonly) {
        $act = (string)($_POST['action'] ?? 'save');
        if ($act === 'delete' && $a) {
            if (!can_delete_article($a)) deny();
            $wasPub = $a['status'] === 'published';
            db()->prepare("UPDATE articles SET deleted_at = ?, status = 'draft', updated_at = ? WHERE id = ?")->execute([now(), now(), $a['id']]);
            if ($wasPub) { db()->prepare('INSERT OR REPLACE INTO gone_slugs (slug, at) VALUES (?,?)')->execute([$a['slug'], now()]); publish_all(); }
            log_activity((int)$u['id'], 'Chuyển vào thùng rác: ' . $a['title']);
            go('articles', 'Đã chuyển bài vào Thùng rác. Có thể khôi phục trong 30 ngày.');
        }
        $d = [
            'title' => mb_substr(trim((string)($_POST['title'] ?? '')), 0, 140),
            'slug' => slugify((string)($_POST['slug'] ?? '') ?: (string)($_POST['title'] ?? ''), 12),
            'category' => (string)($_POST['category'] ?? ''),
            'focus_kw' => mb_substr(trim((string)($_POST['focus_kw'] ?? '')), 0, 100),
            'seo_title' => trim((string)($_POST['seo_title'] ?? '')),
            'meta_desc' => trim(preg_replace('/\s+/u', ' ', (string)($_POST['meta_desc'] ?? ''))),
            'excerpt' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST['excerpt'] ?? ''))), 0, 300),
            'cover' => (string)($_POST['cover'] ?? ''),
            'cover_alt' => mb_substr(trim((string)($_POST['cover_alt'] ?? '')), 0, 200),
            'author_key' => (string)($_POST['author_key'] ?? ''),
            'reviewer_key' => (string)($_POST['reviewer_key'] ?? ''),
            'content' => clean_html((string)($_POST['content'] ?? '')),
        ];
        if ($a && $a['status'] === 'published') $d['slug'] = $a['slug']; // không đổi đường dẫn bài đã đăng
        if (!preg_match('#^/uploads/[a-z0-9/_\-]+\.webp$|^/images/[a-z0-9_\-]+\.webp$|^$#', $d['cover'])) $d['cover'] = '';
        if ($u['role'] === 'tham_dinh' && $u['doctor_key'] && in_array($act, ['publish'], true)) $d['reviewer_key'] = $u['doctor_key'];
        if ($d['title'] === '') $errors[] = 'Chưa có tiêu đề bài.';
        if (!isset(CATEGORIES[$d['category']])) $errors[] = 'Chưa chọn chuyên mục.';
        if (!isset(DOCTORS[$d['author_key']])) $errors[] = 'Chưa chọn bác sĩ tác giả.';
        if ($d['reviewer_key'] !== '' && !isset(DOCTORS[$d['reviewer_key']])) $d['reviewer_key'] = '';
        if ($d['slug'] === '') $errors[] = 'Đường dẫn không hợp lệ.';
        $dup = db()->prepare('SELECT id FROM articles WHERE slug = ? AND id <> ?'); $dup->execute([$d['slug'], $a['id'] ?? 0]);
        if ($dup->fetch()) $errors[] = 'Đường dẫn /bai-viet/' . $d['slug'] . ' đã được dùng cho bài khác (có thể đang ở Thùng rác).';
        $status = $a['status'] ?? 'draft';
        $note = $a['review_note'] ?? '';
        if ($act === 'save' && $status === 'published') $errors[] = 'Bài đang hiện trên website nên mọi thay đổi cần bác sĩ thẩm định xác nhận: tick đủ phiếu "Kiểm duyệt y khoa" rồi bấm "Cập nhật bài đã đăng". Nội dung vừa sửa chưa được lưu, vẫn còn trên màn hình.';
        if ($act === 'submit') { $status = 'pending'; $note = ''; }
        if ($act === 'publish') {
            if (!can('publish')) deny();
            if ($d['reviewer_key'] === '') $errors[] = 'Bài y khoa cần có bác sĩ thẩm định trước khi đăng.';
            if (word_count($d['content']) < 150) $errors[] = 'Nội dung quá ngắn để đăng (dưới 150 từ).';
            $ticked = array_intersect(array_keys(REVIEW_CHECKLIST), (array)($_POST['rv'] ?? []));
            if (count($ticked) < count(REVIEW_CHECKLIST)) $errors[] = 'Cần xác nhận đủ ' . count(REVIEW_CHECKLIST) . ' mục trong "Kiểm duyệt y khoa" trước khi đăng.';
            $status = 'published'; $note = '';
        }
        if ($act === 'unpublish') { if (!can('publish')) deny(); $status = 'draft'; db()->prepare('INSERT OR REPLACE INTO gone_slugs (slug, at) VALUES (?,?)')->execute([$a['slug'], now()]); }
        if ($act === 'return') { if (!can('publish')) deny(); $status = 'draft'; $note = trim((string)($_POST['review_note'] ?? '')); }
        if (!$errors) {
            $t = now();
            $pubAt = $a['published_at'] ?? null;
            if ($status === 'published' && !$pubAt) $pubAt = $t;
            $revAt = $a['reviewed_at'] ?? null; $revChecks = $a['review_checks'] ?? '';
            if ($act === 'publish') { $revAt = $t; $revChecks = json_encode(['by' => $u['display_name'], 'items' => array_keys(REVIEW_CHECKLIST), 'at' => $t], JSON_UNESCAPED_UNICODE); }
            if ($a) {
                db()->prepare('UPDATE articles SET slug=?, title=?, seo_title=?, meta_desc=?, excerpt=?, category=?, focus_kw=?, content=?, cover=?, cover_alt=?, author_key=?, reviewer_key=?, status=?, review_note=?, published_at=?, updated_at=?, reviewed_at=?, review_checks=? WHERE id=?')
                    ->execute([$d['slug'], $d['title'], $d['seo_title'], $d['meta_desc'], $d['excerpt'], $d['category'], $d['focus_kw'], $d['content'], $d['cover'], $d['cover_alt'], $d['author_key'], $d['reviewer_key'], $status, $note, $pubAt, $t, $revAt, $revChecks, $a['id']]);
                $id = (int)$a['id'];
            } else {
                db()->prepare('INSERT INTO articles (slug, title, seo_title, meta_desc, excerpt, category, focus_kw, content, cover, cover_alt, author_key, reviewer_key, status, review_note, created_by, published_at, updated_at, created_at, reviewed_at, review_checks) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$d['slug'], $d['title'], $d['seo_title'], $d['meta_desc'], $d['excerpt'], $d['category'], $d['focus_kw'], $d['content'], $d['cover'], $d['cover_alt'], $d['author_key'], $d['reviewer_key'], $status, $note, $u['id'], $pubAt, $t, $t, $revAt, $revChecks]);
                $id = (int)db()->lastInsertId();
            }
            $msgs = ['save' => 'Đã lưu.', 'submit' => 'Đã gửi bài để bác sĩ thẩm định duyệt.', 'publish' => 'Đã đăng bài lên website.', 'unpublish' => 'Đã gỡ bài khỏi website (chuyển về nháp).', 'return' => 'Đã trả bài lại cho tác giả sửa.'];
            log_activity((int)$u['id'], ($msgs[$act] ?? 'Lưu') . ' ' . $d['title']);
            $wasPub = ($a['status'] ?? '') === 'published';
            if ($status === 'published' || $wasPub) publish_all();
            go('edit&id=' . $id, $msgs[$act] ?? 'Đã lưu.');
        }
        $a = array_merge($a ?? ['status' => 'draft', 'review_note' => '', 'created_by' => $u['id'], 'id' => 0, 'published_at' => null], $d);
    }
    if (!$a) $a = ['id' => 0, 'title' => '', 'slug' => '', 'category' => '', 'focus_kw' => '', 'seo_title' => '', 'meta_desc' => '', 'excerpt' => '', 'cover' => '', 'cover_alt' => '', 'content' => '',
        'author_key' => $u['doctor_key'] ?: '', 'reviewer_key' => DEFAULT_REVIEWER, 'status' => 'draft', 'review_note' => '', 'created_by' => $u['id'], 'published_at' => null];
    $isPub = $a['status'] === 'published';
    $dis = $readonly ? ' disabled' : '';
    $seo = seo_check($a);
    $b = '';
    if ($errors) $b .= '<div class="err box">' . implode('<br>', array_map('e', $errors)) . '</div>';
    if ($a['review_note'] !== '') $b .= '<div class="note box"><b>Góp ý của bác sĩ thẩm định:</b> ' . nl2br(e($a['review_note'])) . '</div>';
    if ($readonly) $b .= '<div class="note box">Bài đã đăng hoặc không phải bài của bạn nên chỉ xem được. Liên hệ bác sĩ thẩm định nếu cần sửa.</div>';
    $b .= '<form method="post" id="ed" class="editor-grid" data-id="' . (int)$a['id'] . '">' . csrf_field()
        . '<div class="col-main">'
        . '<div class="card"><label>Tiêu đề bài (H1)<input name="title" id="f-title" value="' . e($a['title']) . '" required maxlength="140"' . $dis . '></label>'
        . '<label>Đường dẫn<span class="hint">chinhnhatreem.com/bai-viet/</span><input name="slug" id="f-slug" value="' . e($a['slug']) . '"' . ($isPub ? ' readonly title="Bài đã đăng không đổi đường dẫn để giữ thứ hạng Google"' : '') . $dis . '></label>'
        . '<div class="row2"><label>Chuyên mục<select name="category" required' . $dis . '><option value="">Chọn…</option>' . opt(CATEGORIES, $a['category']) . '</select></label>'
        . '<label>Từ khóa chính<input name="focus_kw" id="f-kw" value="' . e($a['focus_kw']) . '" placeholder="vd: niềng răng cho trẻ 7 tuổi"' . $dis . '></label></div>'
        . '<label>Tóm tắt ngắn (hiện dưới tiêu đề và ở danh sách bài)<textarea name="excerpt" rows="2" maxlength="300"' . $dis . '>' . e($a['excerpt']) . '</textarea></label></div>'
        . '<div class="card"><div class="lbl">Nội dung bài viết</div>'
        . ($readonly ? '' : '<div class="toolbar" role="toolbar"><button type="button" data-cmd="p">Đoạn</button><button type="button" data-cmd="h2">H2</button><button type="button" data-cmd="h3">H3</button><button type="button" data-cmd="bold"><b>B</b></button><button type="button" data-cmd="italic"><i>I</i></button><button type="button" data-cmd="ul">• Danh sách</button><button type="button" data-cmd="ol">1. Danh sách</button><button type="button" data-cmd="quote">❝ Trích</button><button type="button" data-cmd="link">Liên kết</button><button type="button" data-cmd="unlink">Bỏ liên kết</button><button type="button" data-cmd="image">Ảnh</button><button type="button" data-cmd="auto-heading" class="btn-auto-heading" title="Tự động nhận diện và phân chia các tiêu đề H2, H3 theo nội dung">✨ Tự động H2/H3</button></div>')
        . '<div id="rte" class="rte" contenteditable="' . ($readonly ? 'false' : 'true') . '">' . $a['content'] . '</div><textarea name="content" id="f-content" hidden>' . e($a['content']) . '</textarea>'
        . '<p class="hint">✨ <b>Tự động khớp cấu trúc:</b> Dán toàn bộ bài viết vào đây (từ Word, Docs, ChatGPT...) hệ thống sẽ tự động phân chia H2, H3, danh sách và trích dẫn. Hoặc bấm "✨ Tự động H2/H3" để chuẩn hóa lại bài viết.</p></div>'
        . '<div class="card"><h2>SEO trên Google</h2><div class="gprev"><div class="g-url">chinhnhatreem.com › bai-viet › <span id="gp-slug">' . e($a['slug']) . '</span></div><div class="g-title" id="gp-title"></div><div class="g-desc" id="gp-desc"></div></div>'
        . '<label>Tiêu đề SEO <span class="count" data-for="f-seot" data-min="30" data-max="60"></span><input name="seo_title" id="f-seot" value="' . e($a['seo_title']) . '" placeholder="Để trống sẽ dùng: Tiêu đề bài | Nha Khoa Trẻ"' . $dis . '></label>'
        . '<label>Mô tả (meta description) <span class="count" data-for="f-desc" data-min="120" data-max="160"></span><textarea name="meta_desc" id="f-desc" rows="3"' . $dis . '>' . e($a['meta_desc']) . '</textarea></label></div>'
        . '</div><aside class="col-side">'
        . '<div class="card"><div class="status">' . badge($a['status']) . ($isPub ? ' <a href="/bai-viet/' . e($a['slug']) . '" target="_blank">Xem trên web ↗</a>' : '') . '</div>';
    if (!$readonly) {
        if (!$isPub) $b .= '<button class="btn ghost" name="action" value="save">Lưu</button>';
        else $b .= '<p class="hint">Bài đang hiện trên web. Sửa xong, bác sĩ thẩm định tick phiếu Kiểm duyệt y khoa bên dưới rồi bấm "Cập nhật bài đã đăng".</p>';
        if ($a['id']) $b .= '<a class="btn ghost" href="?p=preview&id=' . (int)$a['id'] . '" target="_blank">Xem trước</a>';
        if (!can('publish') && $a['status'] !== 'pending') $b .= '<button class="btn" name="action" value="submit">Gửi duyệt</button>';
        if (can('publish')) {
            $b .= '<button class="btn" name="action" value="publish" data-confirm="Đăng bài này lên website?">' . ($isPub ? 'Cập nhật bài đã đăng' : 'Duyệt & đăng bài') . '</button>';
            if ($isPub) $b .= '<button class="btn ghost" name="action" value="unpublish" data-confirm="Gỡ bài khỏi website?">Gỡ bài</button>';
            if ($a['status'] === 'pending') $b .= '<details class="ret"><summary>Trả lại để sửa</summary><textarea name="review_note" rows="3" placeholder="Góp ý cho tác giả…"></textarea><button class="btn ghost" name="action" value="return">Gửi góp ý</button></details>';
        }
        if ($a['id'] && can_delete_article($a)) $b .= '<button class="btn danger" name="action" value="delete" formnovalidate data-confirm="' . ($isPub ? 'Bài đang hiện trên website. Xóa sẽ gỡ bài và chuyển vào Thùng rác (khôi phục được trong 30 ngày). Tiếp tục?' : 'Chuyển bài vào Thùng rác? Có thể khôi phục trong 30 ngày.') . '">Xóa bài</button>';
    }
    $b .= '</div>'
        . '<div class="card"><h2>Điểm SEO &amp; GEO</h2><div class="scores"><div><div class="big-score ' . score_class($seo['score']) . '" id="sc">' . $seo['score'] . '</div><b>SEO</b><span class="hint">Google</span></div>'
        . '<div><div class="big-score ' . score_class($seo['geo']) . '" id="gc">' . $seo['geo'] . '</div><b>GEO</b><span class="hint">AI trích dẫn</span></div></div><p class="hint center" id="sc-words">' . $seo['words'] . ' từ · nên đạt cả hai từ 80 điểm</p><ul class="checks" id="checks">';
    $b .= seo_checks_html($seo);
    $b .= '</ul></div>'
        . (can('publish') && !$readonly ? review_card($a) : '')
        . '<div class="card"><h2>Ảnh đại diện</h2><div id="cover-box">' . ($a['cover'] ? '<img src="' . e($a['cover']) . '" alt="">' : '') . '</div>'
        . '<input type="hidden" name="cover" id="f-cover" value="' . e($a['cover']) . '">'
        . ($readonly ? '' : '<input type="file" id="cover-file" accept="image/jpeg,image/png,image/webp">')
        . '<label>Mô tả ảnh (alt)<input name="cover_alt" value="' . e($a['cover_alt']) . '" placeholder="vd: Bé gái 7 tuổi được bác sĩ khám răng"' . $dis . '></label>'
        . '<p class="hint">Ảnh ngang, nên từ 1200 px. Hệ thống tự nén sang WebP.</p></div>'
        . '<div class="card"><h2>Bác sĩ</h2><label>Tác giả<select name="author_key" required' . $dis . '><option value="">Chọn…</option>' . opt(DOCTORS, $a['author_key']) . '</select></label>'
        . '<label>Thẩm định y khoa<select name="reviewer_key"' . $dis . '><option value="">Chưa chọn</option>' . opt(DOCTORS, $a['reviewer_key']) . '</select></label></div>'
        . '</aside></form>';
    layout($a['id'] ? 'Sửa bài' : 'Viết bài mới', $b); exit;
}

// ---------- SEO từng trang ----------
if ($p === 'pages') {
    if (!can('pages')) deny();
    $key = (string)($_GET['k'] ?? '');
    if ($key !== '' && isset(PAGES[$key])) {
        $html = pub($key . '.html');
        preg_match('#<title>(.*?)</title>#s', $html, $m1); preg_match('#<meta name="description" content="([^"]*)">#', $html, $m2);
        $defT = html_entity_decode($m1[1] ?? '', ENT_QUOTES, 'UTF-8'); $defD = html_entity_decode($m2[1] ?? '', ENT_QUOTES, 'UTF-8');
        $row = page_seo_row($key);
        if ($method === 'POST') {
            if (($_POST['action'] ?? '') === 'reset') {
                db()->prepare('DELETE FROM page_seo WHERE page_key = ?')->execute([$key]);
            } else {
                $og = (string)($_POST['og_image'] ?? '');
                if (!preg_match('#^/uploads/[a-z0-9/_\-]+\.webp$|^$#', $og)) $og = '';
                db()->prepare('INSERT INTO page_seo (page_key, seo_title, meta_desc, og_image, updated_at) VALUES (?,?,?,?,?) ON CONFLICT(page_key) DO UPDATE SET seo_title=excluded.seo_title, meta_desc=excluded.meta_desc, og_image=excluded.og_image, updated_at=excluded.updated_at')
                    ->execute([$key, trim((string)$_POST['seo_title']), trim(preg_replace('/\s+/u', ' ', (string)$_POST['meta_desc'])), $og, now()]);
            }
            publish_all();
            log_activity((int)$u['id'], 'Sửa SEO trang ' . PAGES[$key]['label']);
            go('pages&k=' . $key, 'Đã lưu và cập nhật lên website.');
        }
        $t = $row['seo_title'] ?? '' ?: $defT; $d = $row['meta_desc'] ?? '' ?: $defD; $og = $row['og_image'] ?? '';
        $b = '<p><a href="?p=pages">← Tất cả trang</a></p><form method="post" class="card" id="pg">' . csrf_field()
            . '<div class="gprev"><div class="g-url">chinhnhatreem.com' . e(PAGES[$key]['path'] === '/' ? '' : ' › ' . ltrim(PAGES[$key]['path'], '/')) . '</div><div class="g-title" id="gp-title"></div><div class="g-desc" id="gp-desc"></div></div>'
            . '<label>Tiêu đề SEO <span class="count" data-for="f-seot" data-min="30" data-max="60"></span><input name="seo_title" id="f-seot" value="' . e($t) . '" required></label>'
            . '<label>Mô tả <span class="count" data-for="f-desc" data-min="120" data-max="160"></span><textarea name="meta_desc" id="f-desc" rows="3" required>' . e($d) . '</textarea></label>'
            . '<ul class="checks">';
        foreach (page_seo_check($t, $d) as [$lv, $tx]) $b .= '<li class="' . $lv . '">' . e($tx) . '</li>';
        $b .= '</ul><div class="lbl">Ảnh khi chia sẻ Facebook/Zalo (tùy chọn, 1200 × 630 px)</div><div id="cover-box">' . ($og ? '<img src="' . e($og) . '" alt="">' : '') . '</div>'
            . '<input type="hidden" name="og_image" id="f-cover" value="' . e($og) . '"><input type="file" id="cover-file" accept="image/jpeg,image/png,image/webp">'
            . '<div class="actions"><button class="btn" name="action" value="save">Lưu & cập nhật website</button>' . ($row ? '<button class="btn ghost" name="action" value="reset" data-confirm="Khôi phục về nội dung SEO gốc?">Khôi phục mặc định</button>' : '') . '</div></form>';
        layout('SEO: ' . PAGES[$key]['label'], $b); exit;
    }
    $b = '<div class="card"><div class="wrap"><table><tr><th>Trang</th><th>Tiêu đề hiện tại</th><th>Tình trạng</th></tr>';
    foreach (PAGES as $k => $pg) {
        $html = is_file(GEN_DIR . "/$k.html") ? file_get_contents(GEN_DIR . "/$k.html") : pub("$k.html");
        preg_match('#<title>(.*?)</title>#s', $html, $m1); preg_match('#<meta name="description" content="([^"]*)">#', $html, $m2);
        $t = html_entity_decode($m1[1] ?? '', ENT_QUOTES, 'UTF-8'); $d = html_entity_decode($m2[1] ?? '', ENT_QUOTES, 'UTF-8');
        $bad = count(array_filter(page_seo_check($t, $d), fn($x) => $x[0] !== 'ok'));
        $b .= '<tr><td><a href="?p=pages&k=' . e($k) . '"><b>' . e($pg['label']) . '</b></a>' . (!empty($pg['noindex']) ? ' <span class="small">(không lập chỉ mục)</span>' : '') . '</td><td>' . e($t) . '</td><td>' . ($bad ? '<span class="badge orange">' . $bad . ' gợi ý</span>' : '<span class="badge green">Đạt</span>') . '</td></tr>';
    }
    $b .= '</table></div></div><p class="hint">Bấm vào tên trang để sửa tiêu đề, mô tả và ảnh chia sẻ. Nội dung trang giữ nguyên thiết kế.</p>';
    layout('SEO từng trang', $b); exit;
}

// ---------- Tài khoản ----------
if ($p === 'users') {
    if (!can('users')) deny();
    $shown = '';
    if ($method === 'POST') {
        $act = (string)($_POST['action'] ?? '');
        if ($act === 'create') {
            $un = mb_strtolower(trim((string)$_POST['username']));
            $role = (string)$_POST['role']; $dk = (string)($_POST['doctor_key'] ?? '');
            $name = trim((string)$_POST['display_name']) ?: (DOCTORS[$dk]['name'] ?? '');
            if (!preg_match('/^[a-z0-9._-]{3,30}$/', $un) || !isset(ROLES[$role]) || $name === '') go('users', 'Thông tin chưa hợp lệ (tên đăng nhập 3–30 ký tự, chữ không dấu).');
            if ($dk !== '' && !isset(DOCTORS[$dk])) $dk = '';
            $pw = make_password();
            try {
                db()->prepare('INSERT INTO users (username, display_name, doctor_key, role, pass_hash, created_at) VALUES (?,?,?,?,?,?)')->execute([$un, $name, $dk ?: null, $role, password_hash($pw, PASSWORD_DEFAULT), now()]);
            } catch (PDOException $ex) { go('users', 'Tên đăng nhập đã tồn tại.'); }
            log_activity((int)$u['id'], 'Tạo tài khoản ' . $un);
            $_SESSION['newpw'] = [$un, $pw];
            go('users', 'Đã tạo tài khoản.');
        }
        $tid = (int)($_POST['id'] ?? 0);
        if ($tid === (int)$u['id']) go('users', $act === 'reset' ? 'Muốn đổi mật khẩu của chính bạn, dùng mục Đổi mật khẩu.' : 'Không thể tự khóa tài khoản đang dùng.');
        if ($act === 'reset') {
            $pw = make_password();
            db()->prepare('UPDATE users SET pass_hash = ?, must_change = 1 WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $tid]);
            $n = db()->prepare('SELECT username FROM users WHERE id = ?'); $n->execute([$tid]);
            $_SESSION['newpw'] = [$n->fetchColumn(), $pw];
            log_activity((int)$u['id'], 'Cấp lại mật khẩu tài khoản #' . $tid);
            go('users', 'Đã cấp mật khẩu mới.');
        }
        if ($act === 'toggle') {
            db()->prepare('UPDATE users SET active = 1 - active WHERE id = ?')->execute([$tid]);
            log_activity((int)$u['id'], 'Khóa/mở tài khoản #' . $tid);
            go('users', 'Đã cập nhật.');
        }
    }
    if (!empty($_SESSION['newpw'])) {
        [$un, $pw] = $_SESSION['newpw']; unset($_SESSION['newpw']);
        $shown = '<div class="note box">Mật khẩu tạm của <b>' . e($un) . '</b>: <code class="pw">' . e($pw) . '</code><br>Chỉ hiện một lần. Gửi riêng cho người dùng; họ sẽ phải đổi mật khẩu khi đăng nhập lần đầu.</div>';
    }
    $b = $shown . '<div class="card"><div class="wrap"><table><tr><th>Tên đăng nhập</th><th>Tên hiển thị</th><th>Vai trò</th><th>Hồ sơ bác sĩ</th><th>Trạng thái</th><th></th></tr>';
    foreach (db()->query('SELECT * FROM users ORDER BY id')->fetchAll() as $x) {
        $b .= '<tr><td>' . e($x['username']) . '</td><td>' . e($x['display_name']) . '</td><td>' . e(ROLES[$x['role']]) . '</td><td>' . e(DOCTORS[$x['doctor_key'] ?? '']['name'] ?? '—') . '</td><td>' . ($x['active'] ? '<span class="badge green">Hoạt động</span>' : '<span class="badge gray">Đã khóa</span>') . '</td><td class="nowrap">'
            . ((int)$x['id'] === (int)$u['id'] ? '<a href="?p=account">Đổi mật khẩu</a><form class="inline">' : '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="id" value="' . $x['id'] . '"><button class="link" name="action" value="reset" data-confirm="Cấp mật khẩu mới cho ' . e($x['username']) . '? Tài khoản này sẽ bị đăng xuất khỏi mọi thiết bị.">Cấp lại mật khẩu</button>')
            . ((int)$x['id'] !== (int)$u['id'] ? ' · <button class="link" name="action" value="toggle">' . ($x['active'] ? 'Khóa' : 'Mở khóa') . '</button>' : '') . '</form></td></tr>';
    }
    $b .= '</table></div></div><form method="post" class="card"><h2>Thêm tài khoản</h2>' . csrf_field() . '<input type="hidden" name="action" value="create">'
        . '<div class="row2"><label>Tên đăng nhập (không dấu)<input name="username" required pattern="[a-z0-9._\-]{3,30}" placeholder="vd: bshoang"></label>'
        . '<label>Vai trò<select name="role">' . opt(ROLES, 'bac_si') . '</select></label></div>'
        . '<div class="row2"><label>Gắn với hồ sơ bác sĩ<select name="doctor_key"><option value="">Không (nhân viên)</option>' . opt(DOCTORS, '') . '</select></label>'
        . '<label>Tên hiển thị (để trống sẽ lấy tên bác sĩ)<input name="display_name"></label></div>'
        . '<p class="hint"><b>Bác sĩ viết bài</b>: soạn bài và gửi duyệt. <b>Bác sĩ thẩm định</b>: duyệt và đăng bài, tên được ghi là người thẩm định. <b>Quản trị</b>: toàn quyền, sửa SEO trang, quản lý tài khoản, xem lịch hẹn.</p>'
        . '<button class="btn">Tạo tài khoản</button></form>';
    layout('Tài khoản', $b); exit;
}

// ---------- Lịch hẹn & câu hỏi ----------
if ($p === 'leads') {
    if (!can('leads')) deny();
    sync_leads_from_csv();

    // Xử lý cập nhật trạng thái & ghi chú
    if ($method === 'POST') {
        $act = (string)($_POST['action'] ?? '');
        $leadId = (int)($_POST['id'] ?? 0);
        $backQuery = (string)($_POST['back_query'] ?? '');
        $isAjax = !empty($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest');

        if ($act === 'status') {
            $newSt = (string)($_POST['status'] ?? '');
            if (!isset(LEAD_STATUSES[$newSt])) {
                if ($isAjax) json_out(['ok' => false, 'err' => 'Trạng thái không hợp lệ.']);
                go('leads' . ($backQuery ? "&$backQuery" : ''), 'Trạng thái không hợp lệ.');
            }
            $lead = db()->query('SELECT * FROM leads WHERE id = ' . $leadId)->fetch();
            if (!$lead) {
                if ($isAjax) json_out(['ok' => false, 'err' => 'Không tìm thấy lead.']);
                go('leads', 'Không tìm thấy lead.');
            }
            db()->prepare('UPDATE leads SET status = ?, contacted_at = ?, contacted_by = ? WHERE id = ?')
                ->execute([$newSt, now(), (int)$u['id'], $leadId]);
            $stInfo = LEAD_STATUSES[$newSt];
            log_activity((int)$u['id'], "Cập nhật lead #$leadId (" . ($lead['name'] ?: $lead['phone']) . ') sang: ' . $stInfo['label']);
            if ($isAjax) {
                json_out([
                    'ok' => true,
                    'status' => $newSt,
                    'label' => $stInfo['label'],
                    'badge' => $stInfo['badge'],
                    'icon' => $stInfo['icon'],
                    'contacted_at' => date('d/m H:i'),
                    'staff' => $u['display_name'],
                ]);
            }
            go('leads' . ($backQuery ? "&$backQuery" : ''), 'Đã cập nhật trạng thái liên hệ.');
        }

        if ($act === 'note') {
            $note = trim((string)($_POST['contact_note'] ?? ''));
            $newSt = (string)($_POST['status'] ?? '');
            $lead = db()->query('SELECT * FROM leads WHERE id = ' . $leadId)->fetch();
            if (!$lead) {
                if ($isAjax) json_out(['ok' => false, 'err' => 'Không tìm thấy lead.']);
                go('leads', 'Không tìm thấy lead.');
            }
            if (isset(LEAD_STATUSES[$newSt])) {
                db()->prepare('UPDATE leads SET contact_note = ?, status = ?, contacted_at = ?, contacted_by = ? WHERE id = ?')
                    ->execute([$note, $newSt, now(), (int)$u['id'], $leadId]);
            } else {
                db()->prepare('UPDATE leads SET contact_note = ?, contacted_at = ?, contacted_by = ? WHERE id = ?')
                    ->execute([$note, now(), (int)$u['id'], $leadId]);
            }
            $currentStatus = isset(LEAD_STATUSES[$newSt]) ? $newSt : $lead['status'];
            $stInfo = LEAD_STATUSES[$currentStatus] ?? ['label' => $currentStatus, 'badge' => 'gray'];
            log_activity((int)$u['id'], "Ghi chú CSKH lead #$leadId (" . ($lead['name'] ?: $lead['phone']) . ')');
            if ($isAjax) {
                json_out([
                    'ok' => true,
                    'note' => $note,
                    'status' => $currentStatus,
                    'label' => $stInfo['label'],
                    'badge' => $stInfo['badge'],
                    'contacted_at' => date('d/m H:i'),
                    'staff' => $u['display_name'],
                ]);
            }
            go('leads' . ($backQuery ? "&$backQuery" : ''), 'Đã lưu ghi chú liên hệ.');
        }

        if ($act === 'delete') {
            $lead = db()->query('SELECT * FROM leads WHERE id = ' . $leadId)->fetch();
            if ($lead) {
                db()->prepare('UPDATE leads SET deleted_at = ? WHERE id = ?')->execute([now(), $leadId]);
                log_activity((int)$u['id'], "Xóa lead #$leadId (" . ($lead['name'] ?: $lead['phone']) . ')');
            }
            if ($isAjax) {
                json_out(['ok' => true, 'id' => $leadId]);
            }
            go('leads' . ($backQuery ? "&$backQuery" : ''), 'Đã xóa mục.');
        }

        if ($act === 'bulk_delete') {
            $rawIds = $_POST['ids'] ?? '';
            $ids = is_array($rawIds) ? $rawIds : explode(',', (string)$rawIds);
            $ids = array_filter(array_map('intval', $ids));
            if ($ids) {
                $in = implode(',', $ids);
                db()->prepare("UPDATE leads SET deleted_at = ? WHERE id IN ($in)")->execute([now()]);
                log_activity((int)$u['id'], 'Xóa ' . count($ids) . ' mục lead');
            }
            if ($isAjax) {
                json_out(['ok' => true, 'count' => count($ids)]);
            }
            go('leads' . ($backQuery ? "&$backQuery" : ''), 'Đã xóa ' . count($ids) . ' mục đã chọn.');
        }
    }

    // Bộ lọc
    $loai = $_GET['xem'] ?? 'dat-lich';
    if (!in_array($loai, ['dat-lich', 'cau-hoi', 'all'], true)) $loai = 'dat-lich';
    $stFilter = trim((string)($_GET['st'] ?? ''));
    if (!isset(LEAD_STATUSES[$stFilter])) $stFilter = '';
    $monthFilter = trim((string)($_GET['m'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $monthFilter)) $monthFilter = '';
    $q = trim((string)($_GET['q'] ?? ''));

    // Xây dựng câu truy vấn
    $where = ['l.deleted_at IS NULL'];
    $params = [];
    if ($loai !== 'all') { $where[] = 'l.source_type = ?'; $params[] = $loai; }
    if ($stFilter !== '') { $where[] = 'l.status = ?'; $params[] = $stFilter; }
    if ($monthFilter !== '') { $where[] = 'l.created_at LIKE ?'; $params[] = "$monthFilter%"; }
    if ($q !== '') {
        $where[] = '(l.name LIKE ? OR l.phone LIKE ? OR l.concerns LIKE ? OR l.note LIKE ?)';
        $kw = "%$q%";
        $params[] = $kw; $params[] = $kw; $params[] = $kw; $params[] = $kw;
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    // Xuất Excel CSV
    if (isset($_GET['tai'])) {
        $sqlTai = "SELECT l.*, u.display_name as staff_name FROM leads l LEFT JOIN users u ON u.id = l.contacted_by $whereSql ORDER BY l.created_at DESC";
        $stt = db()->prepare($sqlTai);
        $stt->execute($params);
        $exportRows = $stt->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        $fn = 'leads-' . $loai . ($monthFilter ? "-$monthFilter" : '') . '-' . date('Ymd') . '.csv';
        header('Content-Disposition: attachment; filename="' . $fn . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Thời gian', 'Loại', 'Họ tên phụ huynh', 'Số điện thoại / Zalo', 'Tuổi của bé', 'Bố mẹ lo về / Câu hỏi', 'Ghi chú phụ huynh', 'Trạng thái liên hệ', 'Ghi chú CSKH', 'Thời gian liên hệ', 'Người xử lý']);
        foreach ($exportRows as $r) {
            $stLabel = LEAD_STATUSES[$r['status']]['label'] ?? $r['status'];
            $srcLabel = ($r['source_type'] === 'dat-lich') ? 'Đặt lịch' : 'Câu hỏi';
            fputcsv($out, [
                $r['created_at'],
                $srcLabel,
                $r['name'],
                "'" . $r['phone'],
                $r['child_age'],
                $r['concerns'],
                $r['note'],
                $stLabel,
                $r['contact_note'],
                $r['contacted_at'] ?: '',
                $r['staff_name'] ?: ''
            ]);
        }
        fclose($out);
        exit;
    }

    // Danh sách các tháng có dữ liệu để chọn trong dropdown
    $monthRows = db()->query("SELECT DISTINCT SUBSTR(created_at, 1, 7) as ym FROM leads WHERE deleted_at IS NULL ORDER BY ym DESC")->fetchAll(PDO::FETCH_COLUMN);

    // Thống kê nhanh theo loại hiện tại
    $statWhere = ['deleted_at IS NULL'];
    if ($loai !== 'all') $statWhere[] = "source_type = " . db()->quote($loai);
    if ($monthFilter !== '') $statWhere[] = "created_at LIKE " . db()->quote("$monthFilter%");
    $statWhereSql = 'WHERE ' . implode(' AND ', $statWhere);

    $statsCounts = db()->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'chua_lien_he' THEN 1 ELSE 0 END) as chua,
        SUM(CASE WHEN status = 'da_lien_he' THEN 1 ELSE 0 END) as da,
        SUM(CASE WHEN status = 'da_hen' THEN 1 ELSE 0 END) as hen,
        SUM(CASE WHEN status = 'khong_nghe' THEN 1 ELSE 0 END) as khong,
        SUM(CASE WHEN status = 'huy' THEN 1 ELSE 0 END) as huy
        FROM leads $statWhereSql")->fetch();

    // Truy vấn dữ liệu hiển thị
    $sqlList = "SELECT l.*, u.display_name as staff_name FROM leads l LEFT JOIN users u ON u.id = l.contacted_by $whereSql ORDER BY l.created_at DESC";
    $stt = db()->prepare($sqlList);
    $stt->execute($params);
    $leadsList = $stt->fetchAll();

    // Giữ tham số URL để quay lại sau khi cập nhật
    $backQuery = http_build_query([
        'xem' => $loai,
        'st' => $stFilter,
        'm' => $monthFilter,
        'q' => $q,
    ]);

    // Bắt đầu render
    $b = '<div class="tabs">'
        . '<a href="?p=leads&xem=dat-lich"' . ($loai === 'dat-lich' ? ' class="on"' : '') . '>Lịch hẹn</a>'
        . '<a href="?p=leads&xem=cau-hoi"' . ($loai === 'cau-hoi' ? ' class="on"' : '') . '>Câu hỏi</a>'
        . '<a href="?p=leads&xem=all"' . ($loai === 'all' ? ' class="on"' : '') . '>Tất cả lead</a>'
        . '<a href="?p=leads&' . $backQuery . '&tai=1" style="margin-left:auto">📥 Tải file Excel (CSV)</a>'
        . '</div>';

    // Thẻ thống kê nhanh
    $bUrl = fn($newSt) => '?p=leads&' . http_build_query(['xem' => $loai, 'st' => $newSt, 'm' => $monthFilter, 'q' => $q]);
    $b .= '<div class="lead-stats">'
        . '<a class="lead-stat' . ($stFilter === '' ? ' on' : '') . '" href="' . $bUrl('') . '">'
        . '<span class="ls-l">📋 Tất cả</span><b>' . (int)($statsCounts['total'] ?? 0) . '</b></a>'
        . '<a class="lead-stat stat-chua' . ($stFilter === 'chua_lien_he' ? ' on' : '') . '" href="' . $bUrl('chua_lien_he') . '">'
        . '<span class="ls-l">⏳ Chưa liên hệ</span><b>' . (int)($statsCounts['chua'] ?? 0) . '</b></a>'
        . '<a class="lead-stat stat-da' . ($stFilter === 'da_lien_he' ? ' on' : '') . '" href="' . $bUrl('da_lien_he') . '">'
        . '<span class="ls-l">📞 Đã liên hệ</span><b>' . (int)($statsCounts['da'] ?? 0) . '</b></a>'
        . '<a class="lead-stat stat-hen' . ($stFilter === 'da_hen' ? ' on' : '') . '" href="' . $bUrl('da_hen') . '">'
        . '<span class="ls-l">📅 Đã hẹn khám</span><b>' . (int)($statsCounts['hen'] ?? 0) . '</b></a>'
        . '<a class="lead-stat stat-khong' . ($stFilter === 'khong_nghe' ? ' on' : '') . '" href="' . $bUrl('khong_nghe') . '">'
        . '<span class="ls-l">🔁 Chưa nghe máy</span><b>' . (int)($statsCounts['khong'] ?? 0) . '</b></a>'
        . '</div>';

    // Thanh công cụ lọc & tìm kiếm
    $b .= '<div class="lead-toolbar"><form method="get" action="">'
        . '<input type="hidden" name="p" value="leads">'
        . '<input type="hidden" name="xem" value="' . e($loai) . '">'
        . '<select name="m" onchange="this.form.submit()"><option value="">Tất cả các tháng</option>';
    foreach ($monthRows as $ym) {
        $mLabel = 'Tháng ' . date('n/Y', strtotime("$ym-01"));
        $b .= '<option value="' . e($ym) . '"' . ($monthFilter === $ym ? ' selected' : '') . '>' . e($mLabel) . '</option>';
    }
    $b .= '</select>'
        . '<select name="st" onchange="this.form.submit()"><option value="">Tất cả trạng thái</option>';
    foreach (LEAD_STATUSES as $sk => $sinfo) {
        $b .= '<option value="' . e($sk) . '"' . ($stFilter === $sk ? ' selected' : '') . '>' . e($sinfo['label']) . '</option>';
    }
    $b .= '</select>'
        . '<input type="search" name="q" value="' . e($q) . '" placeholder="Tìm tên hoặc SĐT…">'
        . '<button class="btn mini" type="submit">Lọc</button>'
        . ($stFilter !== '' || $monthFilter !== '' || $q !== '' ? '<a href="?p=leads&xem=' . e($loai) . '" class="btn mini ghost">Xóa lọc</a>' : '')
        . '</form></div>';

    // Thanh thao tác hàng loạt (Bulk Bar)
    $b .= '<div class="bulk-bar" id="lead-bulk-bar" style="display:none;margin-bottom:12px">'
        . '<span>Đã chọn <b id="lead-bulk-count">0</b> mục</span>'
        . '<form method="post" action="?p=leads" id="lead-bulk-form" class="inline" style="margin-left:auto">'
        . csrf_field()
        . '<input type="hidden" name="action" value="bulk_delete">'
        . '<input type="hidden" name="back_query" value="' . e($backQuery) . '">'
        . '<input type="hidden" name="ids" id="lead-bulk-ids">'
        . '<button type="submit" class="btn mini danger" data-confirm="Xóa các mục đã chọn khỏi hệ thống?">🗑 Xóa các mục đã chọn</button>'
        . '</form></div>';

    // Bảng dữ liệu
    $b .= '<div class="card"><p><b>' . count($leadsList) . '</b> mục' . ($monthFilter ? ' trong tháng ' . date('n/Y', strtotime("$monthFilter-01")) : '') . ', mới nhất ở trên.</p>';
    if (!$leadsList) {
        $b .= '<p class="hint">Không có dữ liệu phù hợp với bộ lọc.</p>';
    } else {
        $b .= '<div class="wrap"><table class="lead-table"><thead><tr>'
            . '<th style="width:36px;text-align:center"><input type="checkbox" id="chk-all-leads" title="Chọn tất cả"></th>'
            . '<th>Thời gian</th>'
            . '<th>Phụ huynh</th>'
            . '<th>Số điện thoại / Zalo</th>'
            . '<th>Tuổi của bé</th>'
            . '<th>Bố mẹ lo về / Câu hỏi</th>'
            . '<th>Ghi chú của khách</th>'
            . '<th>Trạng thái liên hệ</th>'
            . '<th>Xử lý & CSKH</th>'
            . '<th style="text-align:center;width:75px">Xóa</th>'
            . '</tr></thead><tbody>';

        $today = date('Y-m-d');
        foreach ($leadsList as $r) {
            $isToday = str_starts_with($r['created_at'], $today);
            $stKey = $r['status'] ?? 'chua_lien_he';
            $stInfo = LEAD_STATUSES[$stKey] ?? ['label' => $stKey, 'badge' => 'gray'];
            $rawPhone = preg_replace('/[^0-9]/', '', (string)$r['phone']);
            $zaloPhone = $rawPhone;
            if (str_starts_with($zaloPhone, '84')) $zaloPhone = '0' . substr($zaloPhone, 2);

            $badgeHtml = '<span class="badge ' . $stInfo['badge'] . ' lead-badge-' . $r['id'] . '">' . e($stInfo['label']) . '</span>';

            // Selectbox cập nhật trạng thái nhanh
            $selectHtml = '<select class="quick-status" data-id="' . $r['id'] . '" data-csrf="' . e(csrf_token()) . '" data-back="' . e($backQuery) . '">';
            foreach (LEAD_STATUSES as $kOpt => $vOpt) {
                $selectHtml .= '<option value="' . e($kOpt) . '"' . ($kOpt === $stKey ? ' selected' : '') . '>' . e($vOpt['icon'] . ' ' . $vOpt['label']) . '</option>';
            }
            $selectHtml .= '</select>';

            // Box ghi chú chăm sóc
            $noteHtml = '<div class="lead-note-box-' . $r['id'] . '">';
            if (!empty($r['contact_note'])) {
                $noteHtml .= '<p class="note-text">' . nl2br(e($r['contact_note'])) . '</p>';
            }
            if (!empty($r['contacted_at'])) {
                $staffStr = !empty($r['staff_name']) ? e($r['staff_name']) . ' · ' : '';
                $noteHtml .= '<div class="note-meta">' . $staffStr . date('d/m H:i', strtotime($r['contacted_at'])) . '</div>';
            }
            $noteHtml .= '</div>';

            $noteBtnText = !empty($r['contact_note']) ? '✏ Sửa ghi chú' : '+ Thêm ghi chú';
            $actionBtn = '<button type="button" class="btn-add-note" data-id="' . $r['id'] . '" data-name="' . e($r['name']) . '" data-phone="' . e($r['phone']) . '" data-status="' . e($stKey) . '" data-note="' . e($r['contact_note']) . '">' . $noteBtnText . '</button>';

            $phoneHtml = '<div class="phone-box">'
                . '<span class="phone-num">' . e($r['phone']) . '</span>'
                . ($rawPhone ? '<a href="tel:' . e($rawPhone) . '" class="btn-icon btn-tel" title="Gọi điện">📞 Gọi</a>' : '')
                . ($zaloPhone ? '<a href="https://zalo.me/' . e($zaloPhone) . '" target="_blank" rel="noopener" class="btn-icon btn-zalo" title="Mở Zalo">💬 Zalo</a>' : '')
                . '</div>';

            $delForm = '<form method="post" action="?p=leads" class="inline del-lead-form" style="margin:0">'
                . csrf_field()
                . '<input type="hidden" name="action" value="delete">'
                . '<input type="hidden" name="id" value="' . $r['id'] . '">'
                . '<input type="hidden" name="back_query" value="' . e($backQuery) . '">'
                . '<button type="submit" class="btn mini danger btn-del-lead" data-id="' . $r['id'] . '" data-name="' . e($r['name'] ?: $r['phone']) . '" data-confirm="Xóa yêu cầu của ' . e($r['name'] ?: $r['phone']) . ' (' . e($r['phone']) . ')? Thao tác này sẽ ẩn khỏi hệ thống." style="margin:0;padding:5px 9px;font-size:12px">🗑 Xóa</button>'
                . '</form>';

            $b .= '<tr id="lead-row-' . $r['id'] . '">'
                . '<td style="text-align:center"><input type="checkbox" name="chk_lead[]" value="' . $r['id'] . '" class="lead-chk"></td>'
                . '<td class="nowrap">' . e(date('d/m/Y H:i', strtotime($r['created_at']))) . ($isToday ? ' <span class="badge green">Mới</span>' : '') . '</td>'
                . '<td><b>' . e($r['name'] ?: 'Phụ huynh') . '</b>' . ($r['source_type'] === 'cau-hoi' ? ' <span class="badge gray">Câu hỏi</span>' : '') . '</td>'
                . '<td class="nowrap">' . $phoneHtml . '</td>'
                . '<td>' . e($r['child_age'] ?: '–') . '</td>'
                . '<td>' . e($r['concerns'] ?: '–') . '</td>'
                . '<td>' . (!empty($r['note']) ? '<span class="small">' . e($r['note']) . '</span>' : '–') . '</td>'
                . '<td><div style="display:flex;flex-direction:column;gap:5px">' . $badgeHtml . $selectHtml . '</div></td>'
                . '<td><div style="display:flex;flex-direction:column;gap:4px">' . $noteHtml . $actionBtn . '</div></td>'
                . '<td style="text-align:center">' . $delForm . '</td>'
                . '</tr>';
        }
        $b .= '</tbody></table></div>';
    }
    $b .= '</div>';

    // Modal popup nhập ghi chú CSKH
    $b .= '<div class="nkt-modal" id="lead-note-modal">'
        . '<div class="nkt-modal-card">'
        . '<h3><span>Ghi chú chăm sóc khách hàng</span><button type="button" id="lead-modal-close">×</button></h3>'
        . '<form method="post" id="lead-note-form" action="?p=leads">'
        . csrf_field()
        . '<input type="hidden" name="action" value="note">'
        . '<input type="hidden" name="id" id="modal-lead-id">'
        . '<input type="hidden" name="back_query" value="' . e($backQuery) . '">'
        . '<p style="margin:0 0 10px;font-size:14px;color:#56635D">Khách: <b id="modal-lead-title" style="color:#0D3B2C"></b></p>'
        . '<label>Trạng thái liên hệ'
        . '<select name="status" id="modal-lead-status">';
    foreach (LEAD_STATUSES as $kOpt => $vOpt) {
        $b .= '<option value="' . e($kOpt) . '">' . e($vOpt['icon'] . ' ' . $vOpt['label']) . '</option>';
    }
    $b .= '</select></label>'
        . '<label>Nội dung cuộc gọi / tư vấn'
        . '<textarea name="contact_note" id="modal-lead-note" placeholder="Ví dụ: Đã gọi lúc 10h, tư vấn tình trạng răng khấp khểnh, phụ huynh hẹn thứ 7 tuần này lúc 9h sáng đưa bé qua khám..."></textarea></label>'
        . '<div class="nkt-modal-acts">'
        . '<button type="button" class="btn ghost" id="lead-modal-cancel">Hủy</button>'
        . '<button type="submit" class="btn">Lưu ghi chú</button>'
        . '</div></form></div></div>';

    layout('Lịch hẹn & câu hỏi', $b); exit;
}

// ---------- Thông báo Telegram ----------
if ($p === 'telegram') {
    if (!can('leads')) deny();
    $c = tg_conf(DATA_DIR);
    if ($method === 'POST') {
        $act = (string)($_POST['action'] ?? '');
        if ($act === 'token') {
            $t = trim((string)($_POST['token'] ?? ''));
            if (!tg_valid_token($t)) go('telegram', 'Mã bot chưa đúng dạng. Mã có dạng 123456789:AAH… (BotFather gửi cho bạn).');
            $me = tg_api($t, 'getMe');
            if (empty($me['ok'])) go('telegram', 'Telegram không nhận mã này: ' . ($me['description'] ?? 'lỗi') . '. Kiểm tra lại mã bot.');
            $c['token'] = $t; $c['bot'] = (string)($me['result']['username'] ?? '');
            $c['chat_id'] = ''; $c['chat_name'] = '';
            tg_save_conf(DATA_DIR, $c);
            log_activity((int)$u['id'], 'Kết nối bot Telegram @' . $c['bot']);
            go('telegram', 'Đã kết nối bot @' . $c['bot'] . '. Làm tiếp bước 3.');
        }
        if ($act === 'chat') {
            $id = (string)($_POST['chat_id'] ?? '');
            if (!preg_match('/^-?\d{1,20}$/', $id) || $c['token'] === '') go('telegram', 'Chưa chọn nhóm.');
            $c['chat_id'] = $id; $c['chat_name'] = mb_substr((string)($_POST['chat_name'] ?? ''), 0, 100);
            tg_save_conf(DATA_DIR, $c);
            $r = tg_send(DATA_DIR, "✅ Đã kết nối! Từ giờ, khi bố mẹ đặt lịch hoặc gửi câu hỏi trên chinhnhatreem.com, thông tin sẽ báo vào đây.");
            log_activity((int)$u['id'], 'Chọn nhóm nhận thông báo Telegram: ' . $c['chat_name']);
            go('telegram', $r['ok'] ? 'Đã chọn nhóm và gửi tin chào. Mở Telegram để kiểm tra.' : 'Đã lưu nhóm nhưng gửi thử lỗi: ' . ($r['description'] ?? ''));
        }
        if ($act === 'opts') {
            $c['dat_lich'] = !empty($_POST['dat_lich']); $c['cau_hoi'] = !empty($_POST['cau_hoi']);
            tg_save_conf(DATA_DIR, $c); go('telegram', 'Đã lưu.');
        }
        if ($act === 'test') {
            $r = tg_send(DATA_DIR, tg_form_message('dat-lich', ['Họ tên phụ huynh' => '(TIN THỬ) Chị Lan', 'Số điện thoại / Zalo' => '0900 000 000', 'Tuổi của bé' => '7 tuổi', 'Bố mẹ lo về' => 'Răng mọc lệch', 'Ghi chú' => 'Đây là tin thử từ trang quản trị'], SITE_URL));
            go('telegram', $r['ok'] ? 'Đã gửi tin thử. Mở Telegram để kiểm tra.' : 'Gửi thử lỗi: ' . ($r['description'] ?? ''));
        }
        if ($act === 'off') {
            tg_save_conf(DATA_DIR, ['token' => '', 'chat_id' => '', 'chat_name' => '', 'dat_lich' => true, 'cau_hoi' => true]);
            @unlink(tg_state_path(DATA_DIR));
            log_activity((int)$u['id'], 'Ngắt kết nối Telegram');
            go('telegram', 'Đã ngắt kết nối Telegram.');
        }
    }
    $done = fn($ok) => $ok ? '<span class="badge green">Xong</span>' : '';
    $st = tg_state(DATA_DIR);
    $b = '';
    if (tg_ready($c)) {
        $b .= '<div class="card"><h2>Trạng thái</h2><p>Đang báo vào: <b>' . e($c['chat_name'] ?: $c['chat_id']) . '</b> qua bot <b>@' . e($c['bot'] ?? '') . '</b>.</p>'
            . ($st ? ($st['ok'] ? '<p class="hint">Lần gửi gần nhất: thành công lúc ' . e(date('H:i d/m/Y', strtotime($st['at']))) . '.</p>' : '<p class="err">Lần gửi gần nhất bị lỗi (' . e(date('H:i d/m/Y', strtotime($st['at']))) . '): ' . e($st['err']) . '. Lịch hẹn vẫn được lưu đầy đủ ở mục Lịch hẹn & câu hỏi.</p>') : '')
            . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="opts">'
            . '<label class="chk"><input type="checkbox" name="dat_lich" value="1"' . (!empty($c['dat_lich']) ? ' checked' : '') . '> <span>Báo khi có lịch hẹn mới</span></label>'
            . '<label class="chk"><input type="checkbox" name="cau_hoi" value="1"' . (!empty($c['cau_hoi']) ? ' checked' : '') . '> <span>Báo khi có câu hỏi mới</span></label>'
            . '<div class="actions"><button class="btn">Lưu</button></div></form>'
            . '<form method="post" class="actions">' . csrf_field() . '<button class="btn ghost" name="action" value="test">Gửi tin thử</button>'
            . '<button class="btn ghost" name="action" value="off" data-confirm="Ngắt kết nối Telegram? Lịch hẹn vẫn được lưu trên trang quản trị.">Ngắt kết nối</button></form></div>';
    }
    $open = !tg_ready($c) || isset($_GET['tim']);
    $b .= ($open ? '<div class="card">' : '<details class="card guide-box"><summary><b>Đổi bot hoặc đổi nhóm nhận tin</b></summary>') . '<h2>Cài đặt (làm 1 lần, khoảng 3 phút)</h2>'
        . '<h3>Bước 1. Tạo bot ' . $done($c['token'] !== '') . '</h3><ol>'
        . '<li>Mở Telegram, tìm <b>@BotFather</b> (có dấu tích xanh), bấm <b>Start</b>.</li>'
        . '<li>Gửi lệnh <code>/newbot</code>. Đặt tên, ví dụ <b>Nha Khoa Trẻ - Lịch hẹn</b>.</li>'
        . '<li>Đặt tên đăng nhập cho bot, phải kết thúc bằng <b>bot</b>, ví dụ <code>nhakhoatre_lichhen_bot</code>.</li>'
        . '<li>BotFather gửi lại một <b>mã</b> dạng <code>123456789:AAH…</code>. Sao chép mã này. Không gửi mã cho ai khác.</li></ol>'
        . '<h3>Bước 2. Dán mã bot ' . $done($c['token'] !== '') . '</h3>'
        . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="token">'
        . '<label>Mã bot (token)<input name="token" autocomplete="off" spellcheck="false" placeholder="' . ($c['token'] !== '' ? 'Đã lưu mã của @' . e($c['bot'] ?? '') . ' (dán mã mới để thay)' : '123456789:AAH...') . '" required></label>'
        . '<button class="btn">Kết nối bot</button></form>';
    if ($c['token'] !== '') {
        $b .= '<h3>Bước 3. Thêm bot vào nhóm lễ tân ' . $done(tg_ready($c)) . '</h3><ol>'
            . '<li>Tạo nhóm Telegram (ví dụ <b>Lễ tân Nha Khoa Trẻ</b>) với những người cần nhận tin, hoặc dùng nhóm có sẵn.</li>'
            . '<li>Trong nhóm: bấm tên nhóm → <b>Thêm thành viên</b> → tìm <b>@' . e($c['bot'] ?? '') . '</b> → thêm.</li>'
            . '<li>Gửi vào nhóm tin nhắn <code>/start</code>.</li>'
            . '<li>Bấm nút dưới đây rồi chọn nhóm.</li></ol>'
            . '<form method="get"><input type="hidden" name="p" value="telegram"><button class="btn ghost" name="tim" value="1">Tìm nhóm</button></form>';
        if (isset($_GET['tim'])) {
            $f = tg_find_chats($c['token']);
            if (!$f['ok']) $b .= '<p class="err">Lỗi: ' . e($f['err']) . '</p>';
            elseif (!$f['chats']) $b .= '<p class="err">Chưa thấy nhóm nào. Kiểm tra bot đã được thêm vào nhóm và đã gửi <code>/start</code> trong nhóm, rồi bấm Tìm nhóm lần nữa.</p>';
            else {
                $b .= '<ul class="chat-pick">';
                foreach ($f['chats'] as $ch) $b .= '<li><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="chat"><input type="hidden" name="chat_id" value="' . e($ch['id']) . '"><input type="hidden" name="chat_name" value="' . e($ch['name']) . '">'
                    . '<span><b>' . e($ch['name']) . '</b> <span class="hint">' . e($ch['kind']) . '</span></span><button class="btn mini">Chọn</button></form></li>';
                $b .= '</ul>';
            }
        }
    }
    $b .= ($open ? '</div>' : '</details>') . '<p class="hint">Telegram chỉ là kênh báo nhanh. Mọi lịch hẹn và câu hỏi vẫn luôn được lưu ở mục <a href="?p=leads">Lịch hẹn & câu hỏi</a>, kể cả khi Telegram lỗi. Tin báo có tên và số điện thoại phụ huynh, nên chỉ thêm vào nhóm những người cần liên hệ lại.</p>';
    layout('Báo qua Telegram', $b); exit;
}

go('home');
