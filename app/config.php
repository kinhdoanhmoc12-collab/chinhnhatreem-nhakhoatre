<?php
// Cấu hình chung cho trang quản trị chinhnhatreem.com
date_default_timezone_set('Asia/Ho_Chi_Minh');
mb_internal_encoding('UTF-8');

define('SITE_URL', 'https://chinhnhatreem.com');
define('SITE_NAME', 'Nha Khoa Trẻ');
define('ROOT_DIR', dirname(__DIR__));                    // /var/www/chinhnhatreem.com
define('PUBLIC_DIR', ROOT_DIR . '/public');              // web gốc (chỉ đọc)
define('GEN_DIR', PUBLIC_DIR . '/_gen');                 // trang được xuất ra (www-data ghi)
define('UPLOAD_DIR', GEN_DIR . '/uploads');              // ảnh tải lên
define('DATA_DIR', getenv('NKT_DATA_DIR') ?: '/var/www/chinhnhatreem-data');
define('DB_FILE', DATA_DIR . '/site.db');
define('ORG_ID', 'https://nhakhoatre.com/#organization');

// Hồ sơ 5 bác sĩ (khớp với trang /doi-ngu-bac-si)
const DOCTORS = [
    'bs-nguyen-huy-hoang' => ['name' => 'BS. Nguyễn Huy Hoàng', 'job' => 'Bác sĩ sáng lập, phụ trách chuyên môn'],
    'bs-pham-xuan-dang'   => ['name' => 'BS. Phạm Xuân Đáng',   'job' => 'Bác sĩ chỉnh nha'],
    'bs-le-thi-nhat-minh' => ['name' => 'BS. Lê Thị Nhật Minh', 'job' => 'Bác sĩ chỉnh nha'],
    'bs-nguyen-thu-hoai'  => ['name' => 'BS. Nguyễn Thu Hoài',  'job' => 'Bác sĩ chỉnh nha'],
    'bs-dam-kieu-ly'      => ['name' => 'BS. Đàm Kiều Ly',      'job' => 'Bác sĩ chỉnh nha'],
];
const DEFAULT_REVIEWER = 'bs-nguyen-huy-hoang';

// Chuyên mục (khớp với nút lọc trên trang Kiến thức chỉnh nha)
const CATEGORIES = [
    'thoidiem'   => 'Dấu hiệu & thời điểm vàng',
    'phuongphap' => 'Phương pháp chỉnh nha',
    'thoiquen'   => 'Thói quen xấu',
    'chamsoc'    => 'Chăm sóc khi niềng',
];

// Các trang chính có thể chỉnh SEO
const PAGES = [
    'index'                => ['path' => '/',                     'label' => 'Trang chủ'],
    'doi-ngu-bac-si'       => ['path' => '/doi-ngu-bac-si',       'label' => 'Đội ngũ bác sĩ'],
    'kien-thuc-chinh-nha'  => ['path' => '/kien-thuc-chinh-nha',  'label' => 'Kiến thức chỉnh nha'],
    'hoi-dap'              => ['path' => '/hoi-dap',              'label' => 'Hỏi đáp phụ huynh'],
    'dat-lich-tu-van'      => ['path' => '/dat-lich-tu-van',      'label' => 'Đặt lịch tư vấn', 'noindex' => true],
    'chinh-sach-bao-mat'   => ['path' => '/chinh-sach-bao-mat',   'label' => 'Chính sách bảo mật'],
    'mien-tru-trach-nhiem' => ['path' => '/mien-tru-trach-nhiem', 'label' => 'Miễn trừ trách nhiệm'],
];

const ROLES = [
    'quan_tri' => 'Quản trị',
    'tham_dinh' => 'Bác sĩ thẩm định',
    'bac_si' => 'Bác sĩ viết bài',
];
