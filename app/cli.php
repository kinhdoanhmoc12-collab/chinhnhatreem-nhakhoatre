<?php
// Công cụ dòng lệnh: php cli.php create-admin <ten_dang_nhap> | publish
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/render.php';

$cmd = $argv[1] ?? '';
if ($cmd === 'create-admin') {
    $un = strtolower($argv[2] ?? 'admin');
    $st = db()->prepare('SELECT id FROM users WHERE username = ?'); $st->execute([$un]);
    if ($st->fetch()) { echo "EXISTS\n"; exit(0); }
    $pw = make_password(14);
    db()->prepare('INSERT INTO users (username, display_name, doctor_key, role, pass_hash, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$un, 'Quản trị website', null, 'quan_tri', password_hash($pw, PASSWORD_DEFAULT), now()]);
    echo $pw . "\n";
} elseif ($cmd === 'publish') {
    publish_all();
    echo "OK\n";
} else {
    echo "Dùng: php cli.php create-admin <ten> | publish\n";
}
