<?php
/**
 * CHỐT LIÊN KẾT SCHEMA — chạy: php tests/schema-links.php
 *
 * Vì sao cần: phần lớn tham chiếu trong DB này KHÔNG có FK thật. Thêm một bảng mới có cột
 * `team_id`/`author_id`/... thì mọi luồng xóa & chuyển giao đang có bỗng thành THIẾU, mà
 * không có gì báo lỗi — code vẫn chạy, vẫn trả "success", chỉ là bỏ sót lặng lẽ.
 *
 * Chốt này làm 2 việc:
 *   1. Quét information_schema, so với SỔ ĐĂNG KÝ bên dưới. Cột chưa khai báo => TRƯỢT,
 *      buộc người thêm bảng phải chốt luật rồi khai vào đây.
 *   2. Đếm dòng MỒ CÔI của từng liên kết nội bộ — bắt được cả trường hợp luật đã khai
 *      nhưng code thực thi sai/thiếu.
 *
 * Xem CLAUDE.md mục 6 (quét cả DB trước khi code xóa/chuyển giao/sáp nhập).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../config.php';

/**
 * SỔ ĐĂNG KÝ. Khóa = '<bảng>.<cột>'.
 *   'ref'  = bảng được trỏ tới; null = KHÔNG phải liên kết nội bộ (id của bên thứ ba) nên
 *            không đếm mồ côi.
 *   'rule' = luật đã chốt khi bản ghi cha bị xóa / chuyển giao.
 *
 * THÊM BẢNG MỚI: chốt luật với người dùng TRƯỚC, cài vào code luồng xóa/chuyển tương ứng,
 * rồi mới khai ở đây. Khai vào đây mà không sửa code = tự bịt mắt mình.
 */
const LINKS = [
    // ---- trỏ tới team ----
    'accounts.team_id'  => ['ref' => 'team',    'rule' => 'purge: xóa theo team | merge: chuyển sang team nhận'],
    'authors.team_id'   => ['ref' => 'team',    'rule' => 'purge: xóa theo team | merge: chuyển sang team nhận'],
    'store.team_id'     => ['ref' => 'team',    'rule' => 'purge: xóa store riêng (team_id=0 là dùng chung, giữ) | merge: chuyển'],
    'options.team_id'   => ['ref' => 'team',    'rule' => 'purge: xóa (chứa openai_key/gemini_key) | merge: trùng tên thì GIỮ của team nhận'],
    'phones.team_id'    => ['ref' => 'team',    'rule' => 'purge: xóa (sms xóa trước) | merge: chuyển sang team nhận'],
    'type.team_id'      => ['ref' => 'team',    'rule' => 'luôn = 0, category là dùng chung — không đụng khi xóa team'],

    // ---- trỏ tới authors (user) ----
    'posts.author_id'                  => ['ref' => 'authors', 'rule' => 'xóa user: bàn giao cho người khác, hoặc chọn None thì xóa sạch'],
    'accounts_authors.author_id'       => ['ref' => 'authors', 'rule' => 'xóa user: xóa liên kết (không chặn xóa)'],
    'author_remember_tokens.author_id' => ['ref' => 'authors', 'rule' => 'xóa user: xóa theo'],
    'download.author_id'               => ['ref' => 'authors', 'rule' => 'GIỮ — lịch sử công việc, không phải sở hữu'],
    'exports.authors_id'               => ['ref' => 'authors', 'rule' => 'GIỮ — lịch sử công việc, không phải sở hữu'],
    'salary.authors'                   => ['ref' => 'authors', 'rule' => 'GIỮ — kế toán; chụp tên vào username_snapshot trước khi xóa'],
    'files.user_id'                    => ['ref' => 'authors', 'rule' => 'xóa file không còn ai dùng; file đang dùng thì giữ, gỡ người upload về 0'],
    'options.authors_id'               => ['ref' => 'authors', 'rule' => 'luôn = 0 (cấu hình thuộc team, không thuộc người) — không đụng'],
    'site.created_by'                  => ['ref' => 'authors', 'rule' => 'về 0 -> bản ghi thành "chỉ admin sửa"'],
    'type.created_by'                  => ['ref' => 'authors', 'rule' => 'về 0 -> bản ghi thành "chỉ admin sửa"'],
    'accounts.user_id'                 => ['ref' => 'authors', 'rule' => 'CHƯA CHỐT — cột cũ, 6/317 dòng có giá trị và đều đã mồ côi'],

    // ---- trỏ tới accounts ----
    'accounts_authors.account_id'      => ['ref' => 'accounts', 'rule' => 'xóa account: xóa theo'],
    'accounts_files.account_id'        => ['ref' => 'accounts', 'rule' => 'xóa account: xóa theo (kèm file nếu không ai dùng)'],
    'accounts_finance.account_id'      => ['ref' => 'accounts', 'rule' => 'xóa account: xóa theo'],
    'accounts_links.account_id'        => ['ref' => 'accounts', 'rule' => 'xóa account: xóa theo'],
    'accounts_proxy.account_id'        => ['ref' => 'accounts', 'rule' => 'xóa account: xóa theo'],
    'accounts_relationships.account_id'=> ['ref' => 'accounts', 'rule' => 'xóa account: xóa theo'],
    'accounts_seller.account_id'       => ['ref' => 'accounts', 'rule' => 'xóa account: xóa theo'],
    'orders.account_id'                => ['ref' => 'accounts', 'rule' => 'xóa account: xóa đơn theo'],
    'exports.accounts_id'              => ['ref' => 'accounts', 'rule' => 'GIỮ — lịch sử công việc'],

    // ---- trỏ tới posts ----
    'accounts_relationships.post_id'   => ['ref' => 'posts', 'rule' => 'xóa sản phẩm: xóa theo'],
    'download_relationships.post_id'   => ['ref' => 'posts', 'rule' => 'xóa sản phẩm: xóa theo'],
    'amazon_listings.post_id'          => ['ref' => 'posts', 'rule' => 'xóa sản phẩm: xóa theo'],

    // ---- trỏ tới site / type / store ----
    'posts.site_id'    => ['ref' => 'site',  'rule' => 'CHẶN xóa site khi còn sản phẩm dùng'],
    'posts.type_id'    => ['ref' => 'type',  'rule' => 'CHẶN xóa category khi còn sản phẩm dùng'],
    'posts.store_id'   => ['ref' => 'store', 'rule' => 'xóa store: sản phẩm chuyển inactive + store_id = 0'],
    'accounts.site_id' => ['ref' => 'site',  'rule' => 'CHẶN xóa site khi còn account dùng'],
    'store.site_id'    => ['ref' => 'site',  'rule' => 'CHẶN xóa site khi còn store dùng'],
    'proxys.site_id'   => ['ref' => 'site',  'rule' => 'CHẶN xóa site khi còn proxy dùng'],
    'exports.site_id'  => ['ref' => 'site',  'rule' => 'CHƯA CHỐT — xóa Site không kiểm exports (CLAUDE.md mục 9)'],
    'exports.type_id'  => ['ref' => 'type',  'rule' => 'CHƯA CHỐT — xóa Category không kiểm exports (CLAUDE.md mục 9)'],

    // ---- còn lại ----
    'accounts_files.file_id'           => ['ref' => 'files',         'rule' => 'xóa file: gỡ liên kết; file đang gắn account thì không xóa'],
    'accounts_proxy.proxys_id'         => ['ref' => 'proxys',        'rule' => 'xóa proxy: gỡ liên kết'],
    'accounts_links.link_id'           => ['ref' => null,            'rule' => 'id nội bộ của bản ghi link, không trỏ bảng khác'],
    'sms.phone_id'                     => ['ref' => 'phones',        'rule' => 'xóa phone (kể cả khi purge team): xóa tin nhắn theo'],
    'phones.carrier_id'                => ['ref' => 'phone_carrier', 'rule' => 'nhà mạng là danh mục tĩnh, không xóa'],
    'download.exports_id'              => ['ref' => 'exports',       'rule' => 'xóa bản export: xóa download theo'],
    'download_relationships.download_id'=> ['ref' => 'download',     'rule' => 'xóa download: xóa theo'],
    'amazon_listings.download_id'      => ['ref' => 'download',      'rule' => 'xóa download: xóa theo'],
    'orders.host_id'                   => ['ref' => null,            'rule' => 'ID đơn bên sàn (Etsy), không phải liên kết nội bộ'],
];

$conn = db();
$fail = 0;
$warn = 0;

// ---------- 1. Quét schema, so với sổ đăng ký ----------
$found = [];
$rs = $conn->query(
    "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME NOT LIKE '%\_bak\_%'
       AND (COLUMN_NAME LIKE '%\_id' OR COLUMN_NAME IN ('authors', 'created_by'))
     ORDER BY TABLE_NAME, COLUMN_NAME");
while ($row = $rs->fetch_row()) {
    $found[] = $row[0] . '.' . $row[1];
}

$new   = array_diff($found, array_keys(LINKS));
$stale = array_diff(array_keys(LINKS), $found);

echo "== CHỐT LIÊN KẾT SCHEMA ==\n";
echo 'Cột liên kết trong DB: ' . count($found) . ' | đã khai báo: ' . count(LINKS) . "\n\n";

if ($new) {
    $fail += count($new);
    echo "[TRƯỢT] " . count($new) . " cột liên kết CHƯA KHAI BÁO:\n";
    foreach ($new as $k) {
        echo "    $k\n";
    }
    echo "  => Mỗi cột trên là một đường dữ liệu mồ côi. Chốt luật với người dùng (xóa theo /\n";
    echo "     giữ lại / chuyển sang / gỡ về 0), sửa luồng xóa & chuyển giao tương ứng, rồi\n";
    echo "     khai vào LINKS trong file này.\n\n";
}

if ($stale) {
    $warn += count($stale);
    echo "[CẢNH BÁO] " . count($stale) . " mục khai thừa (cột không còn trong DB):\n";
    foreach ($stale as $k) {
        echo "    $k\n";
    }
    echo "\n";
}

// ---------- 2. Đếm dòng mồ côi của các liên kết đã khai ----------
echo "-- Dòng mồ côi (trỏ tới bản ghi không còn tồn tại) --\n";
$orphans = 0;
foreach (LINKS as $key => $meta) {
    if ($meta['ref'] === null || !in_array($key, $found, true)) {
        continue;
    }
    [$table, $col] = explode('.', $key, 2);
    try {
        $n = (int)$conn->query(
            "SELECT COUNT(*) FROM `$table` t
             WHERE t.`$col` > 0
               AND NOT EXISTS (SELECT 1 FROM `{$meta['ref']}` r WHERE r.ID = t.`$col`)")->fetch_row()[0];
    } catch (Throwable $e) {
        echo sprintf("  [LỖI] %-36s %s\n", $key, $e->getMessage());
        $fail++;
        continue;
    }
    if ($n > 0) {
        $orphans += $n;
        $warn++;
        echo sprintf("  %-36s %6d dòng mồ côi  — %s\n", $key, $n, $meta['rule']);
    }
}
if ($orphans === 0) {
    echo "  (không có)\n";
}

echo "\n";
if ($fail) {
    echo "KẾT LUẬN: TRƯỢT — $fail vấn đề phải xử lý trước khi deploy.\n";
    exit(1);
}
echo $warn
    ? "KẾT LUẬN: đạt, nhưng có $warn cảnh báo ở trên cần rà.\n"
    : "KẾT LUẬN: mọi cột liên kết đều đã khai báo và không có dòng mồ côi.\n";
exit(0);
