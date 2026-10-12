<?php
// Xuất trang tĩnh: bài viết, danh sách bài, trang chủ, SEO từng trang, sitemap
require_once __DIR__ . '/content.php';

const F = "font-family: 'Be Vietnam Pro', 'Segoe UI', system-ui, sans-serif";

function tpl(string $name): string { return file_get_contents(__DIR__ . '/templates/' . $name); }
function pub(string $file): string { return file_get_contents(PUBLIC_DIR . '/' . $file); }

function gen_write(string $rel, string $content): void {
    $path = GEN_DIR . '/' . $rel;
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
    $tmp = $path . '.tmp' . getmypid();
    file_put_contents($tmp, $content);
    rename($tmp, $path);
}
function gen_delete(string $rel): void { $p = GEN_DIR . '/' . $rel; if (is_file($p)) unlink($p); }

function vn_date(?string $d): string { return $d ? date('d/m/Y', strtotime($d)) : ''; }
function iso_date(?string $d): string { return $d ? date('c', strtotime($d)) : ''; }
function abs_url(string $u): string { return preg_match('#^https?://#', $u) ? $u : SITE_URL . $u; }
function doctor_img(string $key): string { return '/images/chan-dung-' . $key . '.webp'; }

function published_articles(int $limit = 0, string $exceptSlug = '', string $cat = ''): array {
    $sql = "SELECT * FROM articles WHERE status = 'published' AND deleted_at IS NULL";
    $p = [];
    if ($exceptSlug !== '') { $sql .= ' AND slug <> ?'; $p[] = $exceptSlug; }
    if ($cat !== '') { $sql .= ' AND category = ?'; $p[] = $cat; }
    $sql .= ' ORDER BY published_at DESC';
    if ($limit) $sql .= ' LIMIT ' . (int)$limit;
    $st = db()->prepare($sql); $st->execute($p);
    return $st->fetchAll();
}

function card_html(array $a, bool $filterable): string {
    $cls = $filterable ? ' class="bl-post" data-cat="' . e($a['category']) . '"' : '';
    $img = $a['cover'] !== ''
        ? '<img src="' . e($a['cover']) . '" alt="' . e($a['cover_alt']) . '" loading="lazy" style="display: block; width: 100%; aspect-ratio: 3 / 2; border-radius: 24px; object-fit: cover; background: #E5F3EA">'
        : '<div style="aspect-ratio: 3 / 2; border-radius: 24px; background: #E5F3EA"></div>';
    return '<a href="/bai-viet/' . e($a['slug']) . '"' . $cls . ' style="display: flex; flex-direction: column; gap: 12px; text-decoration: none; color: #0D3B2C">' . "\n" . $img . "\n"
        . '<span style="font-size: 14px; font-weight: 600; color: #087A3A">' . e(CATEGORIES[$a['category']] ?? '') . "</span>\n"
        . '<h3 style="margin: 0; ' . F . '; font-weight: 800; font-size: 21px; line-height: 1.35">' . e($a['title']) . "</h3>\n"
        . '<p style="margin: 0; font-size: 15px; line-height: 1.65; color: #56635D">' . e($a['excerpt']) . "</p>\n</a>\n";
}

function page_seo_row(string $key): ?array {
    $st = db()->prepare('SELECT * FROM page_seo WHERE page_key = ?'); $st->execute([$key]);
    return $st->fetch() ?: null;
}

function apply_page_seo(string $html, string $key): string {
    $r = page_seo_row($key);
    if (!$r) return $html;
    if ($r['seo_title'] !== '') {
        $t = e($r['seo_title']);
        $html = preg_replace('#<title>.*?</title>#s', '<title>' . $t . '</title>', $html, 1);
        $html = preg_replace('#<meta property="og:title" content="[^"]*">#', '<meta property="og:title" content="' . $t . '">', $html, 1);
    }
    if ($r['meta_desc'] !== '') {
        $d = e($r['meta_desc']);
        $html = preg_replace('#<meta name="description" content="[^"]*">#', '<meta name="description" content="' . $d . '">', $html, 1);
        $html = preg_replace('#<meta property="og:description" content="[^"]*">#', '<meta property="og:description" content="' . $d . '">', $html, 1);
    }
    if ($r['og_image'] !== '') {
        $html = preg_replace('#<meta property="og:image" content="[^"]*">#', '<meta property="og:image" content="' . e(abs_url($r['og_image'])) . '">', $html, 1);
        $html = preg_replace('#\s*<meta property="og:image:(width|height)" content="[^"]*">#', '', $html);
    }
    return $html;
}

function replace_block(string $html, string $mark, string $inner): string {
    return preg_replace('#<!--' . $mark . '-->.*?<!--/' . $mark . '-->#s', '<!--' . $mark . '-->' . "\n" . str_replace(['\\', '$'], ['\\\\', '\\$'], $inner) . '<!--/' . $mark . '-->', $html, 1);
}

function render_pages(): void {
    $posts = published_articles();
    foreach (PAGES as $key => $p) {
        $file = $key . '.html';
        $html = pub($file);
        $changed = page_seo_row($key) !== null;
        if ($key === 'kien-thuc-chinh-nha' && $posts) {
            $html = replace_block($html, 'POSTS', implode('', array_map(fn($a) => card_html($a, true), $posts)));
            $changed = true;
        }
        if ($key === 'index' && $posts) {
            $html = replace_block($html, 'LATEST', implode('', array_map(fn($a) => card_html($a, false), array_slice($posts, 0, 3))));
            $changed = true;
        }
        if ($changed) gen_write($file, apply_page_seo($html, $key));
        else gen_delete($file);
    }
}

function render_article(array $a): string {
    $url = SITE_URL . '/bai-viet/' . $a['slug'];
    $title = $a['seo_title'] !== '' ? $a['seo_title'] : $a['title'] . ' | ' . SITE_NAME;
    $desc = $a['meta_desc'] !== '' ? $a['meta_desc'] : $a['excerpt'];
    $author = DOCTORS[$a['author_key']] ?? DOCTORS[DEFAULT_REVIEWER];
    $rev = DOCTORS[$a['reviewer_key']] ?? null;
    $cat = CATEGORIES[$a['category']] ?? '';
    $toc = [];
    $body = add_heading_ids($a['content'], $toc);
    $mins = max(1, (int)ceil(word_count($a['content']) / 200));
    $pub = $a['published_at'] ?: $a['updated_at'];
    $img = $a['cover'] !== '' ? abs_url($a['cover']) : SITE_URL . '/images/og-nha-khoa-tre.jpg';

    $person = fn(string $k) => ['@type' => 'Person', 'name' => DOCTORS[$k]['name'], 'jobTitle' => DOCTORS[$k]['job'],
        'url' => SITE_URL . '/doi-ngu-bac-si#' . $k, 'image' => SITE_URL . doctor_img($k), 'worksFor' => ['@id' => ORG_ID]];
    $page = ['@type' => 'WebPage', '@id' => $url, 'url' => $url, 'inLanguage' => 'vi'];
    $revAt = $a['reviewed_at'] ?? '' ?: $a['updated_at'];
    if ($rev) { $page['reviewedBy'] = $person($a['reviewer_key']); $page['lastReviewed'] = date('Y-m-d', strtotime($revAt)); }
    // Hỏi đáp trong bài -> Schema FAQPage (giúp Google và AI hiểu cặp câu hỏi – trả lời)
    preg_match_all('#<h[23][^>]*>(.*?)</h[23]>\s*<p[^>]*>(.*?)</p>#su', $a['content'], $qa, PREG_SET_ORDER);
    $qa = array_values(array_filter($qa, fn($x) => str_ends_with(trim(html_entity_decode(strip_tags($x[1]), ENT_QUOTES, 'UTF-8')), '?')));
    $faq = [];
    foreach ($qa as $q) $faq[] = ['@type' => 'Question', 'name' => trim(html_entity_decode(strip_tags($q[1]), ENT_QUOTES, 'UTF-8')),
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => trim(html_entity_decode(strip_tags($q[2]), ENT_QUOTES, 'UTF-8'))]];
    $schema = ['@context' => 'https://schema.org', '@graph' => [
        ['@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Trang chủ', 'item' => SITE_URL . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Kiến thức chỉnh nha', 'item' => SITE_URL . '/kien-thuc-chinh-nha'],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $a['title'], 'item' => $url]]],
        ['@type' => 'BlogPosting', 'headline' => mb_substr($a['title'], 0, 110), 'description' => $desc, 'image' => [$img],
            'datePublished' => iso_date($pub), 'dateModified' => iso_date($a['updated_at']), 'inLanguage' => 'vi',
            'articleSection' => $cat, 'keywords' => $a['focus_kw'], 'wordCount' => word_count($a['content']),
            'author' => $person(isset(DOCTORS[$a['author_key']]) ? $a['author_key'] : DEFAULT_REVIEWER),
            'publisher' => ['@id' => ORG_ID], 'mainEntityOfPage' => $page],
    ]];
    if (count($faq) >= 2) $schema['@graph'][] = ['@type' => 'FAQPage', 'mainEntity' => $faq];

    $head = '<meta charset="utf-8">' . "\n" . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
        . '<title>' . e($title) . "</title>\n"
        . '<meta name="description" content="' . e($desc) . "\">\n"
        . '<link rel="canonical" href="' . e($url) . "\">\n"
        . '<meta property="og:type" content="article">' . "\n" . '<meta property="og:locale" content="vi_VN">' . "\n"
        . '<meta property="og:site_name" content="' . SITE_NAME . "\">\n"
        . '<meta property="og:title" content="' . e($a['title']) . "\">\n"
        . '<meta property="og:description" content="' . e($desc) . "\">\n"
        . '<meta property="og:url" content="' . e($url) . "\">\n"
        . '<meta property="og:image" content="' . e($img) . "\">\n"
        . '<meta property="article:published_time" content="' . e(iso_date($pub)) . "\">\n"
        . '<meta property="article:modified_time" content="' . e(iso_date($a['updated_at'])) . "\">\n"
        . '<meta name="twitter:card" content="summary_large_image">' . "\n"
        . tpl('head-common.html')
        . '<script type="application/ld+json">' . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . "</script>\n";

    $tocHtml = '';
    if (count($toc) >= 3) {
        $tocHtml = '<nav aria-label="Mục lục" class="ar-toc"><p style="margin: 0 0 10px; font-weight: 800; font-size: 17px">Nội dung bài viết</p><ol>';
        // H2 đánh số; H3 là mục con (không đánh số) nằm trong H2 phía trên
        $open = false; $sub = false;
        foreach ($toc as $t) {
            $link = '<a href="#' . e($t['id']) . '">' . e($t['text']) . '</a>';
            if ($t['level'] === 'h3' && $open) {
                if (!$sub) { $tocHtml .= '<ul>'; $sub = true; }
                $tocHtml .= '<li>' . $link . '</li>';
                continue;
            }
            if ($sub) { $tocHtml .= '</ul>'; $sub = false; }
            if ($open) $tocHtml .= '</li>';
            $tocHtml .= '<li>' . $link; $open = true;
        }
        if ($sub) $tocHtml .= '</ul>';
        if ($open) $tocHtml .= '</li>';
        $tocHtml .= '</ol></nav>';
    }
    $cover = $a['cover'] !== '' ? '<img src="' . e($a['cover']) . '" alt="' . e($a['cover_alt']) . '" fetchpriority="high" style="display: block; width: 100%; aspect-ratio: 16 / 9; object-fit: cover; border-radius: 24px; background: #E5F3EA">' : '';
    $who = '<a href="/doi-ngu-bac-si#' . e($a['author_key']) . '" style="font-weight: 700; color: #0D3B2C">' . e($author['name']) . '</a>';
    $revHtml = $rev ? ' · Thẩm định: <a href="/doi-ngu-bac-si#' . e($a['reviewer_key']) . '" style="font-weight: 700; color: #0D3B2C">' . e($rev['name']) . '</a>' . (!empty($a['reviewed_at']) ? ' ngày ' . vn_date($a['reviewed_at']) : '') : '';
    $updated = $a['updated_at'] && strtotime($a['updated_at']) - strtotime($pub) > 86400 ? ' · Cập nhật ' . vn_date($a['updated_at']) : '';

    $related = published_articles(3, $a['slug'], $a['category']);
    if (count($related) < 3) {
        foreach (published_articles(6, $a['slug']) as $x) { if (count($related) >= 3) break; if (!in_array($x['slug'], array_column($related, 'slug'), true)) $related[] = $x; }
    }
    $relHtml = '';
    if ($related) {
        $relHtml = '<section aria-labelledby="lien-quan" style="max-width: 1180px; margin: 0 auto; padding: 8px 24px 80px; display: flex; flex-direction: column; gap: 28px">'
            . '<h2 id="lien-quan" style="margin: 0; padding-left: 20px; border-left: 4px solid #E8722A; ' . F . '; font-weight: 800; font-size: 30px">Bài viết liên quan</h2>'
            . '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 28px">' . implode('', array_map(fn($x) => card_html($x, false), $related)) . '</div></section>';
    }

    $main = '<nav aria-label="Breadcrumb" style="max-width: 1180px; margin: 0 auto; padding: 20px 24px 0; font-size: 14px; color: #56635D">'
        . '<a href="/" style="display: inline-block; padding: 12px 0; color: #087A3A; font-weight: 600">Trang chủ</a><span aria-hidden="true" style="margin: 0 8px">/</span>'
        . '<a href="/kien-thuc-chinh-nha" style="display: inline-block; padding: 12px 0; color: #087A3A; font-weight: 600">Kiến thức chỉnh nha</a><span aria-hidden="true" style="margin: 0 8px">/</span>'
        . '<span aria-current="page">' . e($a['title']) . '</span></nav>'
        . '<main id="noi-dung"><article style="max-width: 800px; margin: 0 auto; padding: 32px 24px 56px; display: flex; flex-direction: column; gap: 24px">'
        . '<header style="display: flex; flex-direction: column; gap: 16px">'
        . '<a href="/kien-thuc-chinh-nha" style="align-self: flex-start; padding: 8px 16px; border-radius: 999px; background: #E5F3EA; color: #087A3A; font-weight: 700; font-size: 14px; text-decoration: none">' . e($cat) . '</a>'
        . '<h1 class="ar-h1" style="margin: 0; ' . F . '; font-weight: 800; font-size: 44px; line-height: 1.18; letter-spacing: -0.5px; color: #0D3B2C">' . e($a['title']) . '</h1>'
        . ($a['excerpt'] !== '' ? '<p style="margin: 0; font-size: 19px; line-height: 1.7; color: #56635D">' . e($a['excerpt']) . '</p>' : '')
        . '<div style="display: flex; align-items: center; gap: 14px; padding-top: 6px">'
        . '<img src="' . e(doctor_img(isset(DOCTORS[$a['author_key']]) ? $a['author_key'] : DEFAULT_REVIEWER)) . '" alt="' . e($author['name']) . '" width="52" height="52" style="width: 52px; height: 52px; border-radius: 50%; object-fit: cover; object-position: top; background: #E5F3EA">'
        . '<p style="margin: 0; font-size: 15px; line-height: 1.6; color: #56635D">Viết bởi ' . $who . $revHtml . '<br>Đăng ngày ' . vn_date($pub) . $updated . ' · ' . $mins . ' phút đọc</p></div>'
        . '</header>' . $cover . $tocHtml
        . '<div class="ar-body">' . $body . '</div>'
        . '<aside style="padding: 18px 22px; border-radius: 16px; background: #F4EDE3; font-size: 14px; line-height: 1.6; color: #56635D">Bài viết mang tính tham khảo, không thay thế cho việc thăm khám trực tiếp. Tình trạng của mỗi bé khác nhau, bố mẹ nên đưa bé đến bác sĩ để được tư vấn chính xác. Xem <a href="/mien-tru-trach-nhiem" style="color: #087A3A; font-weight: 600">Miễn trừ trách nhiệm</a>.</aside>'
        . '<section aria-label="Tác giả" style="display: flex; gap: 18px; align-items: center; padding: 22px; border-radius: 24px; background: #FFFFFF; border: 1px solid #EBE2D5">'
        . '<img src="' . e(doctor_img(isset(DOCTORS[$a['author_key']]) ? $a['author_key'] : DEFAULT_REVIEWER)) . '" alt="' . e($author['name']) . '" width="84" height="84" loading="lazy" style="flex: 0 0 84px; width: 84px; height: 84px; border-radius: 50%; object-fit: cover; object-position: top; background: #E5F3EA">'
        . '<div style="display: flex; flex-direction: column; gap: 4px"><span style="font-size: 13px; font-weight: 700; color: #E8722A; letter-spacing: 0.5px">TÁC GIẢ</span>'
        . '<span style="' . F . '; font-weight: 800; font-size: 19px">' . e($author['name']) . '</span><span style="font-size: 15px; color: #56635D">' . e($author['job']) . ' tại Nha Khoa Trẻ</span>'
        . '<a href="/doi-ngu-bac-si#' . e($a['author_key']) . '" style="font-size: 15px; font-weight: 700; color: #087A3A">Xem hồ sơ bác sĩ</a></div></section>'
        . '<section style="border-radius: 28px; background: #087A3A; color: #FFFFFF; padding: 36px 32px; display: flex; flex-direction: column; gap: 14px">'
        . '<h2 style="margin: 0; ' . F . '; font-weight: 800; font-size: 26px; line-height: 1.3">Bố mẹ muốn bác sĩ xem răng cho bé?</h2>'
        . '<p style="margin: 0; font-size: 16px; line-height: 1.7; color: #E5F3EA">Đặt lịch để bác sĩ Nha Khoa Trẻ thăm khám và tư vấn thời điểm can thiệp phù hợp cho bé.</p>'
        . '<div style="display: flex; flex-wrap: wrap; gap: 12px"><a href="/dat-lich-tu-van" style="padding: 14px 26px; border-radius: 999px; background: #F69018; color: #FFFFFF; font-weight: 700; text-decoration: none">Đặt lịch tư vấn</a>'
        . '<a href="tel:0901334334" style="padding: 14px 26px; border-radius: 999px; border: 2px solid rgba(255,255,255,.7); color: #FFFFFF; font-weight: 700; text-decoration: none">Gọi 0901.334.334</a></div></section>'
        . '</article>' . $relHtml . '</main>';

    return "<!doctype html>\n<html lang=\"vi\">\n<head>\n" . $head . "</head>\n<body>\n" . tpl('body-top.html') . $main . "\n" . tpl('body-bottom.html');
}

function render_sitemap(): void {
    $today = date('Y-m-d');
    $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach (PAGES as $key => $p) {
        if (!empty($p['noindex'])) continue;
        $f = is_file(GEN_DIR . "/$key.html") ? GEN_DIR . "/$key.html" : PUBLIC_DIR . "/$key.html";
        $x .= '  <url><loc>' . SITE_URL . ($p['path'] === '/' ? '/' : $p['path']) . '</loc><lastmod>' . date('Y-m-d', filemtime($f)) . "</lastmod></url>\n";
    }
    foreach (published_articles() as $a) {
        $x .= '  <url><loc>' . SITE_URL . '/bai-viet/' . e($a['slug']) . '</loc><lastmod>' . date('Y-m-d', strtotime($a['updated_at'])) . "</lastmod></url>\n";
    }
    $x .= "</urlset>\n";
    gen_write('sitemap.xml', $x);
}

// Xuất lại toàn bộ: gọi sau mỗi lần đăng/sửa/gỡ bài hoặc sửa SEO trang
function publish_all(): void {
    if (!is_dir(GEN_DIR . '/bai-viet')) mkdir(GEN_DIR . '/bai-viet', 0755, true);
    $keep = [];
    foreach (published_articles() as $a) {
        gen_write('bai-viet/' . $a['slug'] . '.html', render_article($a));
        $keep[$a['slug'] . '.html'] = 1;
    }
    foreach (glob(GEN_DIR . '/bai-viet/*.html') as $f) if (!isset($keep[basename($f)])) unlink($f);
    // Bài đã xóa: tạo dấu để Nginx chuyển hướng 301 về /kien-thuc-chinh-nha
    $gone = GEN_DIR . '/da-xoa/bai-viet';
    if (!is_dir($gone)) mkdir($gone, 0755, true);
    $want = [];
    foreach (db()->query('SELECT slug FROM gone_slugs')->fetchAll(PDO::FETCH_COLUMN) as $s) if (!isset($keep[$s . '.html'])) $want[$s] = 1;
    foreach (glob($gone . '/*') as $f) if (!isset($want[basename($f)])) unlink($f);
    foreach ($want as $s => $_) if (!is_file("$gone/$s")) touch("$gone/$s");
    render_pages();
    render_sitemap();
}
