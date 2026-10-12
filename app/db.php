<?php
require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY,
        username TEXT UNIQUE NOT NULL,
        display_name TEXT NOT NULL,
        doctor_key TEXT,
        role TEXT NOT NULL,
        pass_hash TEXT NOT NULL,
        active INTEGER NOT NULL DEFAULT 1,
        must_change INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS articles (
        id INTEGER PRIMARY KEY,
        slug TEXT UNIQUE NOT NULL,
        title TEXT NOT NULL,
        seo_title TEXT NOT NULL DEFAULT '',
        meta_desc TEXT NOT NULL DEFAULT '',
        excerpt TEXT NOT NULL DEFAULT '',
        category TEXT NOT NULL,
        focus_kw TEXT NOT NULL DEFAULT '',
        content TEXT NOT NULL DEFAULT '',
        cover TEXT NOT NULL DEFAULT '',
        cover_alt TEXT NOT NULL DEFAULT '',
        author_key TEXT NOT NULL,
        reviewer_key TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'draft',
        review_note TEXT NOT NULL DEFAULT '',
        created_by INTEGER REFERENCES users(id),
        published_at TEXT,
        updated_at TEXT NOT NULL,
        created_at TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS page_seo (
        page_key TEXT PRIMARY KEY,
        seo_title TEXT NOT NULL DEFAULT '',
        meta_desc TEXT NOT NULL DEFAULT '',
        og_image TEXT NOT NULL DEFAULT '',
        updated_at TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS login_fail (
        id INTEGER PRIMARY KEY,
        ip TEXT NOT NULL,
        username TEXT NOT NULL,
        at INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS activity (
        id INTEGER PRIMARY KEY,
        user_id INTEGER,
        action TEXT NOT NULL,
        at TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS leads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_type TEXT NOT NULL DEFAULT 'dat-lich',
        created_at TEXT NOT NULL,
        name TEXT NOT NULL DEFAULT '',
        phone TEXT NOT NULL DEFAULT '',
        child_age TEXT NOT NULL DEFAULT '',
        concerns TEXT NOT NULL DEFAULT '',
        note TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'chua_lien_he',
        contact_note TEXT NOT NULL DEFAULT '',
        contacted_at TEXT,
        contacted_by INTEGER REFERENCES users(id),
        raw_hash TEXT UNIQUE
    );
    CREATE INDEX IF NOT EXISTS idx_leads_source_created ON leads(source_type, created_at);
    CREATE INDEX IF NOT EXISTS idx_leads_status ON leads(status);
    ");
    // Nâng cấp bảng cũ: thêm cột thẩm định
    $cols = array_column($pdo->query('PRAGMA table_info(articles)')->fetchAll(), 'name');
    if (!in_array('reviewed_at', $cols, true)) $pdo->exec("ALTER TABLE articles ADD COLUMN reviewed_at TEXT");
    if (!in_array('review_checks', $cols, true)) $pdo->exec("ALTER TABLE articles ADD COLUMN review_checks TEXT NOT NULL DEFAULT ''");
    if (!in_array('deleted_at', $cols, true)) $pdo->exec("ALTER TABLE articles ADD COLUMN deleted_at TEXT");
    // Đường dẫn bài đã từng đăng rồi bị xóa: chuyển hướng 301 về trang Kiến thức để giữ SEO
    $pdo->exec("CREATE TABLE IF NOT EXISTS gone_slugs (slug TEXT PRIMARY KEY, at TEXT NOT NULL)");
    return $pdo;
}

const LEAD_STATUSES = [
    'chua_lien_he' => ['label' => 'Chưa liên hệ', 'badge' => 'orange', 'icon' => '⏳'],
    'da_lien_he'   => ['label' => 'Đã liên hệ',   'badge' => 'green',  'icon' => '📞'],
    'da_hen'       => ['label' => 'Đã hẹn khám',  'badge' => 'blue',   'icon' => '📅'],
    'khong_nghe'   => ['label' => 'Không nghe máy / Gọi lại', 'badge' => 'yellow', 'icon' => '🔁'],
    'huy'          => ['label' => 'Hủy / Không nhu cầu',      'badge' => 'gray',   'icon' => '✕'],
];

function sync_leads_from_csv(): void {
    try {
        $db = db();
        // 1. dat-lich.csv
        $fDatLich = DATA_DIR . '/dat-lich.csv';
        if (is_file($fDatLich) && ($fh = fopen($fDatLich, 'r'))) {
            fgetcsv($fh); // bỏ dòng tiêu đề
            $stmtCheck = $db->prepare('SELECT id FROM leads WHERE raw_hash = ?');
            $stmtIns = $db->prepare('INSERT INTO leads (source_type, created_at, name, phone, child_age, concerns, note, status, raw_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            while (($row = fgetcsv($fh)) !== false) {
                if (empty($row[0])) continue;
                $time = trim($row[0]);
                $name = trim($row[1] ?? '');
                $phone = trim(ltrim($row[2] ?? '', "'"));
                $age = trim($row[3] ?? '');
                $concerns = trim($row[4] ?? '');
                $note = trim($row[5] ?? '');
                $hash = md5('dat-lich|' . $time . '|' . $phone . '|' . $name);
                $stmtCheck->execute([$hash]);
                if (!$stmtCheck->fetchColumn()) {
                    $stmtIns->execute(['dat-lich', $time, $name, $phone, $age, $concerns, $note, 'chua_lien_he', $hash]);
                }
            }
            fclose($fh);
        }
        // 2. cau-hoi.csv
        $fCauHoi = DATA_DIR . '/cau-hoi.csv';
        if (is_file($fCauHoi) && ($fh = fopen($fCauHoi, 'r'))) {
            fgetcsv($fh); // bỏ dòng tiêu đề
            $stmtCheck = $db->prepare('SELECT id FROM leads WHERE raw_hash = ?');
            $stmtIns = $db->prepare('INSERT INTO leads (source_type, created_at, name, phone, child_age, concerns, note, status, raw_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            while (($row = fgetcsv($fh)) !== false) {
                if (empty($row[0])) continue;
                $time = trim($row[0]);
                $cauHoi = trim($row[1] ?? '');
                $lienHe = trim(ltrim($row[2] ?? '', "'"));
                $hash = md5('cau-hoi|' . $time . '|' . $lienHe . '|' . $cauHoi);
                $stmtCheck->execute([$hash]);
                if (!$stmtCheck->fetchColumn()) {
                    $stmtIns->execute(['cau-hoi', $time, 'Phụ huynh', $lienHe, '', $cauHoi, '', 'chua_lien_he', $hash]);
                }
            }
            fclose($fh);
        }
    } catch (Throwable $e) {
        error_log('sync_leads_from_csv error: ' . $e->getMessage());
    }
}

function now(): string { return date('Y-m-d H:i:s'); }

function log_activity(?int $uid, string $action): void {
    db()->prepare('INSERT INTO activity (user_id, action, at) VALUES (?,?,?)')->execute([$uid, mb_substr($action, 0, 300), now()]);
}

function slugify(string $t, int $maxWords = 12): string {
    $t = str_replace(['đ', 'Đ'], ['d', 'D'], $t);
    if (class_exists('Normalizer')) {
        $t = Normalizer::normalize($t, Normalizer::FORM_D);
        $t = preg_replace('/\p{Mn}/u', '', $t);
    } else {
        $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
    }
    $t = strtolower($t);
    $t = preg_replace('/[^a-z0-9]+/', '-', $t);
    $t = trim($t, '-');
    return implode('-', array_slice(explode('-', $t), 0, $maxWords));
}

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
