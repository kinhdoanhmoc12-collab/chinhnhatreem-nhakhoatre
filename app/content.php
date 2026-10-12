<?php
// Lọc HTML bài viết, xử lý ảnh tải lên
require_once __DIR__ . '/db.php';

const ALLOWED_TAGS = [
    'p' => [], 'h2' => ['id'], 'h3' => ['id'], 'h4' => ['id'], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [],
    'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'br' => [], 'hr' => [],
    'a' => ['href', 'title', 'target', 'rel'], 'img' => ['src', 'alt', 'width', 'height'],
    'figure' => [], 'figcaption' => [], 'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => [], 'td' => [],
];
const RENAME_TAGS = ['b' => 'strong', 'i' => 'em', 'h1' => 'h2', 'div' => 'p'];

function clean_html(string $html): string {
    $html = trim($html);
    if ($html === '') return '';
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    $root = $doc->getElementById('__root');
    if (!$root) return '';
    clean_node($doc, $root);
    $out = '';
    foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    // bỏ đoạn rỗng
    $out = preg_replace('#<(p|h[2-4])>(\s|&nbsp;|<br>|<strong>\s*</strong>)*</\1>#u', '', $out);
    $out = preg_replace('#<(h[2-4]) id="[^"]*">(\s|&nbsp;|<br>)*</\1>#u', '', $out);
    return trim($out);
}

function clean_node(DOMDocument $doc, DOMNode $node): void {
    for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
        $c = $node->childNodes->item($i);
        if ($c instanceof DOMComment) { $node->removeChild($c); continue; }
        if (!($c instanceof DOMElement)) continue;
        $tag = strtolower($c->nodeName);
        if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'svg', 'math', 'meta', 'link', 'noscript', 'template'], true)) {
            $node->removeChild($c); continue;
        }
        clean_node($doc, $c);
        if (isset(RENAME_TAGS[$tag])) {
            $new = $doc->createElement(RENAME_TAGS[$tag]);
            while ($c->firstChild) $new->appendChild($c->firstChild);
            $node->replaceChild($new, $c);
            $c = $new; $tag = RENAME_TAGS[$tag];
        }
        // Tiêu đề vốn đã đậm: bỏ thẻ in đậm thừa bên trong (thường do dán từ nơi khác)
        if ($tag === 'strong' && preg_match('/^h[2-4]$/', strtolower($node->nodeName))) {
            while ($c->firstChild) $node->insertBefore($c->firstChild, $c);
            $node->removeChild($c);
            continue;
        }
        if (!isset(ALLOWED_TAGS[$tag])) {
            // giữ nội dung, bỏ thẻ (span, font...)
            while ($c->firstChild) $node->insertBefore($c->firstChild, $c);
            $node->removeChild($c);
            continue;
        }
        $allowed = ALLOWED_TAGS[$tag];
        foreach (iterator_to_array($c->attributes) as $attr) {
            if (!in_array($attr->name, $allowed, true)) $c->removeAttribute($attr->name);
        }
        if ($tag === 'a') {
            $href = trim($c->getAttribute('href'));
            if (!preg_match('#^(https?://|/|\#|mailto:|tel:)#i', $href)) { $c->removeAttribute('href'); }
            $ext = preg_match('#^(https?:)?//#i', $href) && !preg_match('#^(https?:)?//(www\.)?chinhnhatreem\.com(/|$)#i', $href);
            if ($ext) { $c->setAttribute('target', '_blank'); $c->setAttribute('rel', 'noopener'); }
            else { $c->removeAttribute('target'); $c->removeAttribute('rel'); }
        }
        if ($tag === 'img') {
            $src = trim($c->getAttribute('src'));
            if (!preg_match('#^(/uploads/|/images/)[a-z0-9\-_/]+\.(webp|jpg|jpeg|png)$#i', $src)) { $node->removeChild($c); continue; }
            $c->setAttribute('loading', 'lazy');
            if (!$c->hasAttribute('alt')) $c->setAttribute('alt', '');
        }
    }
}

// Gắn id cho H2/H3 để làm mục lục
function add_heading_ids(string $html, array &$toc): string {
    $used = [];
    return preg_replace_callback('#<(h2|h3)(?: id="[^"]*")?>(.*?)</\1>#su', function ($m) use (&$toc, &$used) {
        $text = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES, 'UTF-8'));
        if ($text === '') return ''; // tiêu đề rỗng: bỏ hẳn
        $id = slugify($text, 8) ?: 'muc';
        $base = $id; $k = 2;
        while (isset($used[$id])) $id = $base . '-' . $k++;
        $used[$id] = 1;
        // Mục "Tài liệu tham khảo / Nguồn" không cần đưa vào mục lục
        if (!preg_match('/^(tài liệu tham khảo|nguồn tham khảo|nguồn)$/iu', $text)) $toc[] = ['level' => $m[1], 'id' => $id, 'text' => $text];
        return '<' . $m[1] . ' id="' . $id . '">' . $m[2] . '</' . $m[1] . '>';
    }, $html);
}

function save_upload(array $f, string $nameHint): array {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return ['err' => 'Chưa chọn ảnh hoặc ảnh lỗi khi tải lên.'];
    if ($f['size'] > 10 * 1024 * 1024) return ['err' => 'Ảnh quá lớn (tối đa 10 MB).'];
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) return ['err' => 'Chỉ nhận ảnh JPG, PNG hoặc WebP.'];
    if ($info[0] * $info[1] > 40000000) return ['err' => 'Ảnh có kích thước quá lớn.'];
    $img = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($f['tmp_name']),
        IMAGETYPE_PNG => @imagecreatefrompng($f['tmp_name']),
        IMAGETYPE_WEBP => @imagecreatefromwebp($f['tmp_name']),
    };
    if (!$img) return ['err' => 'Không đọc được ảnh.'];
    // Xoay đúng chiều ảnh chụp điện thoại
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $ex = @exif_read_data($f['tmp_name']);
        $o = $ex['Orientation'] ?? 1;
        if ($o == 3) $img = imagerotate($img, 180, 0); elseif ($o == 6) $img = imagerotate($img, -90, 0); elseif ($o == 8) $img = imagerotate($img, 90, 0);
    }
    $w = imagesx($img); $h = imagesy($img);
    if ($w > 1600) {
        $nh = (int)round($h * 1600 / $w);
        $r = imagecreatetruecolor(1600, $nh);
        imagealphablending($r, false); imagesavealpha($r, true);
        imagecopyresampled($r, $img, 0, 0, 0, 0, 1600, $nh, $w, $h);
        imagedestroy($img); $img = $r; $w = 1600; $h = $nh;
    }
    $dir = UPLOAD_DIR . '/' . date('Y/m');
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $base = slugify($nameHint, 8) ?: 'anh';
    $name = $base; $k = 2;
    while (file_exists("$dir/$name.webp")) $name = $base . '-' . $k++;
    imagewebp($img, "$dir/$name.webp", 82);
    imagedestroy($img);
    return ['url' => '/uploads/' . date('Y/m') . "/$name.webp", 'w' => $w, 'h' => $h];
}

function word_count(string $html): int {
    $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
    return $t === '' ? 0 : count(explode(' ', $t));
}
