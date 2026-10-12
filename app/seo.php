<?php
// Chấm điểm SEO / GEO cho bài viết, chia theo nhóm tiêu chí
require_once __DIR__ . '/content.php';

const SEO_GROUPS = [
    'kw'   => 'Từ khóa',
    'meta' => 'Tiêu đề & mô tả',
    'body' => 'Nội dung & dễ đọc',
    'img'  => 'Hình ảnh',
    'link' => 'Liên kết',
    'eeat' => 'Uy tín & an toàn y khoa',
    'geo'  => 'GEO – để AI trích dẫn đúng',
];

// Nguồn y khoa uy tín (dẫn nguồn tới các trang này được tính là trích dẫn chuyên môn)
const TRUSTED_SOURCES = ['aaoinfo.org', 'aapd.org', 'ada.org', 'mouthhealthy.org', 'who.int', 'nhs.uk', 'ncbi.nlm.nih.gov', 'pubmed', 'cochranelibrary.com', 'nature.com', 'sciencedirect.com', 'wiley.com', 'moh.gov.vn', 'kcb.vn', 'bvrhmtw.vn', 'rhmhcm.vn', 'mayoclinic.org', 'clevelandclinic.org', 'cdc.gov', 'nih.gov', 'bos.org.uk', 'wfo.org'];

// Phiếu kiểm duyệt y khoa: bác sĩ thẩm định phải xác nhận đủ trước khi đăng
const REVIEW_CHECKLIST = [
    'accurate' => 'Thông tin y khoa chính xác, phù hợp hướng dẫn chuyên môn hiện hành.',
    'numbers'  => 'Độ tuổi, thời gian điều trị, số liệu đều có nguồn hoặc đúng với thực tế phòng khám.',
    'noclaim'  => 'Không hứa hẹn kết quả, không cam kết khỏi 100%, không so sánh hạ thấp nơi khác.',
    'consent'  => 'Ảnh bệnh nhân, ảnh trước – sau (nếu có) đã được gia đình đồng ý cho đăng.',
    'advice'   => 'Bài có khuyến nghị bố mẹ đưa bé đến bác sĩ thăm khám trực tiếp, không tự điều trị.',
];

// Từ ngữ hứa hẹn tuyệt đối: dễ vi phạm quy định quảng cáo y tế và làm giảm uy tín
const ABSOLUTE_WORDS = ['cam kết 100%', '100% hiệu quả', 'hiệu quả 100%', 'chắc chắn khỏi', 'khỏi hoàn toàn', 'kết quả vĩnh viễn', 'không đau chút nào', 'hoàn toàn không đau', 'không hề đau', 'tốt nhất', 'số 1', 'số một', 'rẻ nhất', 'thần kỳ', 'thần tốc', 'đảm bảo thành công', 'không bao giờ tái phát'];
const GENERIC_ANCHORS = ['bấm vào đây', 'click vào đây', 'click here', 'tại đây', 'xem tại đây', 'ở đây', 'link'];

function norm_vi(string $s): string {
    $s = mb_strtolower(trim(html_entity_decode(strip_tags($s), ENT_QUOTES, 'UTF-8')));
    return preg_replace('/\s+/u', ' ', $s);
}

function other_articles(int $id): array {
    try {
        $st = db()->prepare('SELECT id, title, seo_title, meta_desc, focus_kw FROM articles WHERE id <> ? AND deleted_at IS NULL');
        $st->execute([$id]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

// Trả về ['score', 'words', 'checks'=>[[level, text, group]], 'groups'=>[key=>[label, passed, total]]]
function seo_check(array $a, ?array $others = null): array {
    $kw = norm_vi($a['focus_kw'] ?? '');
    $title = trim($a['title'] ?? '');
    $seoTitle = trim(($a['seo_title'] ?? '') ?: ($title !== '' ? $title . ' | ' . SITE_NAME : ''));
    $desc = trim($a['meta_desc'] ?? '');
    $slug = trim($a['slug'] ?? '') ?: slugify($title, 12);
    $html = $a['content'] ?? '';
    $text = norm_vi($html);
    $words = word_count($html);
    $others = $others ?? other_articles((int)($a['id'] ?? 0));
    $c = [];
    // $ok: đạt; $w: trọng số; $warnOnly: chưa đạt chỉ là gợi ý (màu cam)
    $add = function (string $g, bool $ok, string $good, string $bad, int $w = 1, bool $warnOnly = false) use (&$c) {
        $c[] = ['g' => $g, 'lv' => $ok ? 'ok' : ($warnOnly ? 'warn' : 'bad'), 't' => $ok ? $good : $bad, 'w' => $w, 'ok' => $ok];
    };
    $has = fn(string $hay) => $kw !== '' && str_contains(norm_vi($hay), $kw);

    // ---- Từ khóa ----
    $add('kw', $kw !== '', 'Đã đặt từ khóa chính: "' . ($a['focus_kw'] ?? '') . '".', 'Chưa đặt từ khóa chính (ví dụ: "niềng răng cho trẻ 7 tuổi").', 3);
    if ($kw !== '') {
        $add('kw', $has($title), 'Tiêu đề bài (H1) có chứa từ khóa.', 'Tiêu đề bài (H1) chưa chứa từ khóa chính.', 2);
        preg_match('#<p[^>]*>(.*?)</p>#su', $html, $m);
        $add('kw', $has($m[1] ?? ''), 'Từ khóa xuất hiện ngay đoạn mở đầu.', 'Nên nhắc từ khóa trong đoạn đầu tiên.', 1);
        preg_match_all('#<h[23][^>]*>(.*?)</h[23]>#su', $html, $hs);
        $inH = array_filter($hs[1], fn($h) => $has($h));
        $add('kw', count($inH) > 0, 'Từ khóa có trong ít nhất 1 tiêu đề phụ H2/H3.', 'Nên đưa từ khóa (hoặc biến thể gần) vào ít nhất 1 tiêu đề phụ H2/H3.', 1, true);
        $n = substr_count($text, $kw);
        $density = $words ? round($n * count(explode(' ', $kw)) * 100 / $words, 1) : 0;
        $add('kw', $density >= 0.5 && $density <= 2.5, "Mật độ từ khóa $density% (chuẩn 0,5–2,5%).",
            $density < 0.5 ? "Mật độ từ khóa $density%, hơi thấp. Nhắc từ khóa tự nhiên thêm vài lần." : "Mật độ từ khóa $density%, quá dày. Bớt lặp lại để tránh bị coi là nhồi từ khóa.", 1, true);
        $dupKw = array_filter($others, fn($o) => norm_vi($o['focus_kw'] ?? '') === $kw);
        $add('kw', !$dupKw, 'Từ khóa chưa dùng cho bài nào khác.', 'Từ khóa này đã dùng cho bài "' . (reset($dupKw)['title'] ?? '') . '". Hai bài cùng từ khóa sẽ tranh thứ hạng với nhau; nên chọn từ khóa khác hoặc gộp bài.', 2);
    }

    // ---- Tiêu đề & mô tả ----
    $tl = mb_strlen($seoTitle);
    $add('meta', $tl >= 30 && $tl <= 60, "Tiêu đề SEO dài $tl ký tự (chuẩn 30–60).", "Tiêu đề SEO dài $tl ký tự, nên trong khoảng 30–60 để không bị Google cắt.", 2);
    if ($kw !== '') {
        $add('meta', $has($seoTitle), 'Tiêu đề SEO có chứa từ khóa.', 'Tiêu đề SEO chưa chứa từ khóa chính.', 2);
        $pos = mb_strpos(norm_vi($seoTitle), $kw);
        $add('meta', $pos !== false && $pos <= 20, 'Từ khóa nằm ở đầu tiêu đề SEO.', 'Nên đưa từ khóa lên đầu tiêu đề SEO để nổi bật trên Google.', 1, true);
    }
    $add('meta', mb_strlen($title) > 0 && mb_strlen($title) <= 70, 'Tiêu đề bài gọn (' . mb_strlen($title) . ' ký tự).', 'Tiêu đề bài nên ngắn hơn 70 ký tự để dễ đọc.', 1, true);
    $dl = mb_strlen($desc);
    $add('meta', $dl >= 120 && $dl <= 160, "Mô tả dài $dl ký tự (chuẩn 120–160).", $dl === 0 ? 'Chưa viết mô tả (meta description).' : "Mô tả dài $dl ký tự, nên trong khoảng 120–160.", 2);
    if ($kw !== '') $add('meta', $has($desc), 'Mô tả có chứa từ khóa.', 'Mô tả chưa chứa từ khóa chính.', 1);
    $dupT = array_filter($others, fn($o) => norm_vi($o['seo_title'] ?: $o['title'] . ' | ' . SITE_NAME) === norm_vi($seoTitle));
    $add('meta', !$dupT, 'Tiêu đề SEO không trùng bài khác.', 'Tiêu đề SEO đang trùng với một bài khác. Mỗi trang cần tiêu đề riêng.', 2);
    if ($desc !== '') {
        $dupD = array_filter($others, fn($o) => $o['meta_desc'] !== '' && norm_vi($o['meta_desc']) === norm_vi($desc));
        $add('meta', !$dupD, 'Mô tả không trùng bài khác.', 'Mô tả đang trùng với một bài khác.', 1);
    }
    $sw = $slug === '' ? 0 : count(explode('-', $slug));
    $add('meta', $slug !== '' && $sw <= 8, 'Đường dẫn ngắn gọn: /bai-viet/' . $slug, 'Đường dẫn nên ngắn, tối đa khoảng 8 từ.', 1, true);
    if ($kw !== '') $add('meta', str_contains($slug, slugify($kw, 20)), 'Đường dẫn có chứa từ khóa.', 'Đường dẫn chưa chứa từ khóa chính.', 1, true);

    // ---- Nội dung & dễ đọc ----
    $add('body', $words >= 800, "Bài viết dài $words từ (khuyến nghị từ 800 từ).", "Bài viết mới có $words từ. Nên viết từ 800 từ trở lên để đủ sâu.", 2, $words >= 500);
    $h2 = preg_match_all('#<h2[\s>]#i', $html);
    $add('body', $h2 >= 2, "Có $h2 tiêu đề phụ H2.", 'Nên chia bài thành ít nhất 2 mục bằng tiêu đề H2.', 2);
    $add('body', !preg_match('#<h1[\s>]#i', $html), 'Không có H1 thừa trong nội dung.', 'Nội dung có thẻ H1. Trang chỉ được có 1 H1 (là tiêu đề bài).', 1);
    preg_match('#<h([23])[\s>]#i', $html, $fh);
    $add('body', !$fh || $fh[1] === '2', 'Thứ bậc tiêu đề đúng (H2 trước, H3 sau).', 'Tiêu đề phụ đầu tiên đang là H3. Nên dùng H2 cho mục lớn, H3 cho mục con bên trong.', 1);
    preg_match_all('#<p[^>]*>(.*?)</p>#su', $html, $ps);
    $longP = 0;
    foreach ($ps[1] as $p) if (word_count($p) > 120) $longP++;
    $add('body', $longP === 0, 'Các đoạn văn ngắn gọn, dễ đọc trên điện thoại.', "Có $longP đoạn dài hơn 120 từ. Nên tách thành đoạn ngắn 2–4 câu.", 1, true);
    $plain = norm_vi(preg_replace('#</(p|li|h[1-6]|blockquote|td|th)>#i', '. ', $html));
    $sent = preg_split('/[.!?…:;]+(\s|$)/u', $plain, -1, PREG_SPLIT_NO_EMPTY);
    $avg = $sent ? round($words / max(1, count($sent))) : 0;
    $add('body', $avg <= 25, "Câu văn trung bình $avg từ, dễ đọc.", "Câu văn trung bình $avg từ, hơi dài. Nên viết câu ngắn dưới 25 từ cho bố mẹ dễ hiểu.", 1, true);
    $add('body', (bool)preg_match('#<(ul|ol)[\s>]#i', $html), 'Có danh sách gạch đầu dòng.', 'Nên có ít nhất 1 danh sách (dấu hiệu, các bước…). Google và AI dễ trích dẫn nội dung dạng danh sách.', 1, true);
    $add('body', mb_strlen(trim($a['excerpt'] ?? '')) >= 40, 'Đã có tóm tắt ngắn.', 'Chưa có tóm tắt (hiện dưới tiêu đề ở danh sách bài).', 1, true);

    // ---- Hình ảnh ----
    preg_match_all('#<img[^>]*>#i', $html, $imgs);
    $ni = count($imgs[0]); $noAlt = 0; $kwAlt = false;
    foreach ($imgs[0] as $im) {
        if (!preg_match('#alt="([^"]{5,})"#u', $im, $al)) $noAlt++;
        elseif ($has($al[1])) $kwAlt = true;
    }
    $add('img', ($a['cover'] ?? '') !== '', 'Đã có ảnh đại diện.', 'Chưa có ảnh đại diện (hiện ở danh sách bài và khi chia sẻ Facebook/Zalo).', 2);
    if (($a['cover'] ?? '') !== '') $add('img', mb_strlen(trim($a['cover_alt'] ?? '')) >= 5, 'Ảnh đại diện có mô tả.', 'Ảnh đại diện chưa có mô tả (alt).', 1);
    $add('img', $ni > 0, "Có $ni ảnh trong bài.", 'Nên có ít nhất 1 ảnh minh họa trong nội dung.', 1, true);
    if ($ni) $add('img', $noAlt === 0, 'Tất cả ảnh đều có mô tả (alt).', "$noAlt ảnh chưa có mô tả (alt). Bấm vào ảnh để thêm mô tả.", 2);
    if ($ni && $kw !== '') $add('img', $kwAlt || $has($a['cover_alt'] ?? ''), 'Có ảnh mà mô tả chứa từ khóa.', 'Nên có ít nhất 1 ảnh có mô tả chứa từ khóa (viết tự nhiên).', 1, true);
    if ($words >= 600) $add('img', $ni >= floor($words / 500), 'Số ảnh cân đối với độ dài bài.', 'Bài dài nhưng ít ảnh. Nên có khoảng 1 ảnh mỗi 400–500 từ.', 1, true);

    // ---- Liên kết ----
    preg_match_all('#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#isu', $html, $links, PREG_SET_ORDER);
    $int = 0; $ext = 0; $cta = false; $generic = 0;
    foreach ($links as $l) {
        $h = $l[1];
        if (preg_match('#^(/|https?://(www\.)?chinhnhatreem\.com)#i', $h)) { $int++; if (str_contains($h, 'dat-lich')) $cta = true; }
        elseif (preg_match('#^https?://#i', $h)) $ext++;
        if (in_array(norm_vi($l[2]), GENERIC_ANCHORS, true)) $generic++;
    }
    $add('link', $int >= 2, "Có $int liên kết nội bộ.", "Mới có $int liên kết nội bộ. Nên dẫn tới ít nhất 2 trang khác trên web (bài liên quan, Hỏi đáp, Đặt lịch…).", 2);
    $add('link', $ext >= 1, "Có $ext liên kết tới nguồn bên ngoài.", 'Nên dẫn nguồn uy tín (ví dụ Hiệp hội Chỉnh nha Hoa Kỳ – AAO). Giúp Google và AI tin nội dung hơn.', 2, true);
    $add('link', $cta, 'Có liên kết tới trang Đặt lịch tư vấn.', 'Nên có 1 liên kết tới /dat-lich-tu-van để bố mẹ đặt lịch ngay khi đọc xong.', 1, true);
    $add('link', $generic === 0, 'Chữ của liên kết mô tả rõ trang đích.', "Có $generic liên kết dùng chữ chung chung (\"tại đây\", \"bấm vào đây\"). Nên dùng chữ mô tả trang đích, ví dụ \"lịch tái khám khi niềng\".", 1, true);

    // ---- Uy tín y khoa & GEO ----
    $add('eeat', isset(DOCTORS[$a['author_key'] ?? '']), 'Có bác sĩ tác giả.', 'Chưa chọn bác sĩ tác giả.', 2);
    $add('eeat', isset(DOCTORS[$a['reviewer_key'] ?? '']), 'Có bác sĩ thẩm định nội dung y khoa.', 'Chưa chọn bác sĩ thẩm định.', 2);
    preg_match_all('#<h[23][^>]*>(.*?)</h[23]>\s*<p[^>]*>(.*?)</p>#su', $html, $qa, PREG_SET_ORDER);
    $qa = array_values(array_filter($qa, fn($x) => str_ends_with(trim(html_entity_decode(strip_tags($x[1]), ENT_QUOTES, 'UTF-8')), '?')));
    $add('eeat', count($qa) > 0, 'Có tiêu đề dạng câu hỏi (dễ được AI trích dẫn).', 'Nên có ít nhất 1 tiêu đề phụ dạng câu hỏi, trả lời ngay câu đầu bên dưới.', 1, true);
    if ($qa) {
        $longAns = array_filter($qa, fn($x) => word_count($x[2]) > 60);
        $add('eeat', !$longAns, 'Câu trả lời ngay dưới các câu hỏi ngắn gọn (dưới 60 từ).', 'Đoạn ngay dưới tiêu đề câu hỏi nên trả lời thẳng trong 1–2 câu (dưới 60 từ), giải thích chi tiết ở đoạn sau.', 1, true);
    }
    $hits = array_values(array_filter(ABSOLUTE_WORDS, fn($w) => str_contains($text, $w)));
    $add('eeat', !$hits, 'Không có từ hứa hẹn tuyệt đối.', 'Kiểm tra lại các cụm: "' . implode('", "', array_slice($hits, 0, 4)) . '". Nội dung y khoa nên tránh hứa hẹn tuyệt đối hoặc so sánh "nhất" (dễ vi phạm quy định quảng cáo y tế). Nếu dùng đúng nghĩa thì bỏ qua gợi ý này.', 2, true);

    // ---- An toàn y khoa (YMYL) ----
    $add('eeat', (bool)preg_match('/thăm khám|đi khám|đưa bé (đến|đi) (khám|gặp)|bác sĩ (đánh giá|kiểm tra|thăm khám)|khám trực tiếp/u', $text),
        'Có khuyến nghị bố mẹ đưa bé đi khám trực tiếp.', 'Nên có câu khuyến nghị đưa bé đến bác sĩ thăm khám trực tiếp. Nội dung y khoa không thay thế việc khám.', 2);
    $add('eeat', !preg_match('/\d+\s*(mg|ml|mcg|viên\s*\/\s*ngày|lần\s*\/\s*ngày)/u', $text),
        'Không có liều thuốc trong bài.', 'Bài có nhắc liều thuốc. Bác sĩ thẩm định cần kiểm tra kỹ; nên ghi "dùng theo chỉ định của bác sĩ".', 1, true);
    $trusted = 0;
    foreach ($links as $l) foreach (TRUSTED_SOURCES as $dm) if (stripos($l[1], $dm) !== false) { $trusted++; break; }
    $hasPct = (bool)preg_match('/\d+([.,]\d+)?\s*%/u', $text);
    if ($hasPct) $add('eeat', $trusted > 0, 'Số liệu % có dẫn nguồn chuyên môn.', 'Bài có số liệu % nhưng chưa dẫn nguồn chuyên môn. Cần ghi rõ nguồn (AAO, WHO, nghiên cứu…).', 2);
    if (!empty($a['reviewed_at'])) {
        $age = (time() - strtotime($a['reviewed_at'])) / 86400;
        $add('eeat', $age <= 365, 'Bài được thẩm định trong 12 tháng gần đây.', 'Bài đã thẩm định hơn 12 tháng trước. Nên rà soát lại nội dung y khoa và cập nhật.', 2, true);
    }

    // ---- GEO: để AI tóm tắt và trích dẫn đúng ----
    $firstP = $ps[1][0] ?? '';
    $fw = word_count($firstP);
    $add('geo', $fw > 0 && $fw <= 60, 'Đoạn mở đầu trả lời ngắn gọn (' . $fw . ' từ).', 'Đoạn mở đầu nên trả lời thẳng câu hỏi chính trong 1–3 câu (dưới 60 từ). AI thường trích nguyên đoạn này.', 2);
    $intro = norm_vi(implode(' ', array_slice($ps[1], 0, 2)));
    $add('geo', (bool)preg_match('/\blà\b/u', $intro), 'Phần mở đầu có câu định nghĩa rõ ("… là …").', 'Nên có 1 câu định nghĩa rõ ở phần mở đầu, ví dụ "Chỉnh nha can thiệp là …". AI ưu tiên trích câu định nghĩa.', 1, true);
    $nums = preg_match_all('/\d+([.,–-]\d+)?\s*(%|tuổi|tháng|năm|tuần|ngày|giờ|mm|lần|ca)\b/u', $text);
    $add('geo', $nums >= 2, "Có $nums số liệu cụ thể (tuổi, thời gian, %…).", 'Nên có số liệu cụ thể (độ tuổi, thời gian điều trị, tần suất tái khám…). Câu có số liệu dễ được AI trích dẫn hơn.', 1, true);
    $add('geo', $trusted > 0, "Có $trusted nguồn y khoa uy tín được dẫn.", 'Nên dẫn ít nhất 1 nguồn y khoa uy tín (AAO, WHO, Bộ Y tế, nghiên cứu trên PubMed…). Đây là tín hiệu quan trọng nhất với nội dung y tế.', 3);
    $qn = count($qa);
    $add('geo', $qn >= 3, "Có $qn câu hỏi – trả lời (tự tạo Schema hỏi đáp).", "Mới có $qn tiêu đề dạng câu hỏi. Nên có từ 3 câu hỏi bố mẹ hay hỏi, mỗi câu trả lời ngay bên dưới.", 1, true);
    $add('geo', (bool)preg_match('#<(table|ol)[\s>]#i', $html), 'Có bảng so sánh hoặc danh sách các bước.', 'Nên có 1 bảng so sánh (vd: các phương pháp) hoặc danh sách đánh số các bước. AI dễ tóm tắt dạng này.', 1, true);
    $add('geo', str_contains($text, 'nha khoa trẻ'), 'Bài có nhắc tên Nha Khoa Trẻ.', 'Nên nhắc tên "Nha Khoa Trẻ" ít nhất 1 lần một cách tự nhiên, để AI gắn nội dung với phòng khám.', 1, true);
    $secs = preg_split('#<h2[\s>]#i', $html);
    array_shift($secs);
    $thin = count(array_filter($secs, fn($x) => word_count(preg_replace('#^[^>]*>.*?</h2>#su', '', $x)) < 40));
    if ($secs) $add('geo', $thin === 0, 'Mỗi mục H2 đều có nội dung đầy đủ.', "Có $thin mục H2 quá ngắn (dưới 40 từ). Mỗi mục nên giải thích đủ ý.", 1, true);
    $add('geo', (bool)preg_match('/khi nào (cần|nên)|dấu hiệu (cảnh báo|cần)|cần đi khám (ngay|sớm)/u', $text), 'Có nêu khi nào cần đưa bé đi khám.', 'Nên có 1 mục "Khi nào cần đưa bé đi khám?". Đây là câu bố mẹ hay hỏi AI nhất.', 1, true);

    // ---- Tổng hợp ----
    // Điểm SEO: 5 nhóm đầu. Điểm GEO: uy tín y khoa + GEO
    $tot = ['seo' => 0, 'geo' => 0]; $got = ['seo' => 0, 'geo' => 0]; $groups = [];
    foreach (SEO_GROUPS as $k => $l) $groups[$k] = [$l, 0, 0];
    foreach ($c as $x) {
        $side = in_array($x['g'], ['eeat', 'geo'], true) ? 'geo' : 'seo';
        $tot[$side] += $x['w'];
        if ($x['ok']) $got[$side] += $x['w']; elseif ($x['lv'] === 'warn') $got[$side] += $x['w'] * 0.5;
        $groups[$x['g']][2]++; if ($x['ok']) $groups[$x['g']][1]++;
    }
    $seoScore = $tot['seo'] ? (int)round($got['seo'] * 100 / $tot['seo']) : 0;
    $geoScore = $tot['geo'] ? (int)round($got['geo'] * 100 / $tot['geo']) : 0;
    $order = ['bad' => 0, 'warn' => 1, 'ok' => 2];
    usort($c, fn($p, $q) => [array_search($p['g'], array_keys(SEO_GROUPS)), $order[$p['lv']]] <=> [array_search($q['g'], array_keys(SEO_GROUPS)), $order[$q['lv']]]);
    return ['score' => $seoScore, 'geo' => $geoScore, 'words' => $words,
        'checks' => array_map(fn($x) => [$x['lv'], $x['t'], $x['g']], $c), 'groups' => $groups];
}

function page_seo_check(string $title, string $desc): array {
    $c = [];
    $tl = mb_strlen($title); $dl = mb_strlen($desc);
    $c[] = [$tl >= 30 && $tl <= 60 ? 'ok' : 'bad', "Tiêu đề $tl ký tự (chuẩn 30–60)."];
    $c[] = [$dl >= 120 && $dl <= 160 ? 'ok' : 'bad', "Mô tả $dl ký tự (chuẩn 120–160)."];
    $c[] = [str_contains($title, 'Nha Khoa Trẻ') ? 'ok' : 'warn', str_contains($title, 'Nha Khoa Trẻ') ? 'Tiêu đề có tên thương hiệu.' : 'Nên giữ "| Nha Khoa Trẻ" ở cuối tiêu đề.'];
    $c[] = [str_contains(mb_strtolower($title . ' ' . $desc), 'chỉnh nha') || str_contains(mb_strtolower($title . ' ' . $desc), 'niềng răng') ? 'ok' : 'warn', 'Tiêu đề hoặc mô tả nên có cụm "chỉnh nha" / "niềng răng".'];
    return $c;
}

// HTML hiển thị danh sách tiêu chí theo nhóm (dùng chung cho trang soạn bài)
function seo_checks_html(array $s): string {
    $out = '';
    foreach (SEO_GROUPS as $k => $label) {
        if ($k === 'kw') $out .= '<li class="part">Tiêu chí SEO – xếp hạng trên Google</li>';
        if ($k === 'eeat') $out .= '<li class="part">Tiêu chí GEO – được AI (ChatGPT, Gemini, Google AI) trích dẫn</li>';
        $items = array_filter($s['checks'], fn($x) => $x[2] === $k);
        if (!$items) continue;
        [$l, $p, $t] = $s['groups'][$k];
        $cls = $p === $t ? 'green' : ($p >= $t / 2 ? 'orange' : 'red');
        $out .= '<li class="grp"><span>' . e($l) . '</span><span class="score ' . $cls . '">' . $p . '/' . $t . '</span></li>';
        foreach ($items as $x) $out .= '<li class="' . $x[0] . '">' . e($x[1]) . '</li>';
    }
    return $out;
}
