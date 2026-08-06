<?php
/**
 * AB TEST — điểm chạy.
 *
 *   php tests/ab-test/run.php Products     # bảng danh sách + thao tác hàng loạt + form
 *   php tests/ab-test/run.php Category
 *   php tests/ab-test/run.php Sites
 *   php tests/ab-test/run.php Store
 *   php tests/ab-test/run.php all
 *
 * Tên viết hoa/thường, số ít/số nhiều đều nhận (Products = Product = products).
 * Mã thoát khác 0 nếu có ô sai -> dùng được trong script tự động.
 */

require_once __DIR__ . '/lib.php';

// Author CÓ THẬT của từng team — nhiều chỗ tra authors.team_id nên không dùng id giả được.
define('AB_UID_T1', ab_pick_author(1));
define('AB_UID_T2', ab_pick_author(2));
define('AB_UID_T3', ab_pick_author(3));

/** Lấy 1 author bất kỳ của team; nếu team đó chưa có ai thì lấy tạm author team 1. */
function ab_pick_author(int $team): int
{
    $row = db()->query("SELECT ID FROM authors WHERE team_id = $team ORDER BY ID LIMIT 1")->fetch_row();
    if ($row) {
        return (int)$row[0];
    }
    return (int)db()->query('SELECT ID FROM authors ORDER BY ID LIMIT 1')->fetch_row()[0];
}

$suites = [
    'products'   => ['file' => 'suite.products.php',   'menu' => 'products',   'title' => 'Products / Product'],
    'categories' => ['file' => 'suite.categories.php', 'menu' => 'categories', 'title' => 'Categories / Category'],
    'sites'      => ['file' => 'suite.sites.php',      'menu' => 'sites',      'title' => 'Sites / Site'],
    'stores'     => ['file' => 'suite.stores.php',     'menu' => 'store',      'title' => 'Stores / Store'],
    'orders'     => ['file' => 'suite.orders.php',     'menu' => 'orders',     'title' => 'Orders / Order'],
    'teams'      => ['file' => 'suite.teams.php',      'menu' => 'teams',      'title' => 'Teams / Team (admin-only)'],
    'users'      => ['file' => 'suite.users.php',      'menu' => 'users',      'title' => 'Users / User'],
    'roles'      => ['file' => 'suite.roles.php',      'menu' => 'roles-permissions', 'title' => 'Roles / Role (phân quyền)'],
    'account'    => ['file' => 'suite.account.php',    'menu' => 'account',    'title' => 'Account (hồ sơ cá nhân)'],
];
// Tên gọi tắt: số ít, tiếng Việt, tên menu
$alias = [
    'product' => 'products', 'category' => 'categories', 'danhmuc' => 'categories',
    'site' => 'sites', 'store' => 'stores', 'shop' => 'stores', 'sanpham' => 'products',
    'order' => 'orders', 'donhang' => 'orders', 'team' => 'teams', 'nhom' => 'teams',
    'user' => 'users', 'nguoidung' => 'users', 'author' => 'users', 'authors' => 'users',
    'taikhoan' => 'account', 'profile' => 'account', 'hoso' => 'account',
];

$arg = strtolower(trim($argv[1] ?? 'all'));
$arg = $alias[$arg] ?? $arg;

if ($arg === 'all') {
    $run = array_keys($suites);
} elseif (isset($suites[$arg])) {
    $run = [$arg];
} else {
    echo "Không có bộ test '$arg'. Chọn: " . implode(', ', array_keys($suites)) . ", all\n";
    exit(2);
}

$actors = ab_actors();
$fx = new AbFixtures();
// Dọn dữ liệu tạm kể cả khi chạy lỗi giữa chừng
register_shutdown_function(fn() => $fx->cleanup());

echo "\nAB TEST — profulfill admin\n";
echo 'Ngày: ' . date('Y-m-d H:i:s') . ' | Actor: ' . count($actors) . "\n";
foreach ($actors as $a) {
    printf("  %-9s %-22s level=%-8s team=%d role=%s\n", $a->key, $a->label, $a->level, $a->team,
        $a->roles ? implode('+', $a->roles) : '(không có)');
}

$totalBad = 0;
foreach ($run as $key) {
    $s = $suites[$key];
    $runner = new AbRunner($s['menu'], $fx);
    (require __DIR__ . '/' . $s['file'])($runner);
    $runner->run($actors);
    $totalBad += $runner->report($actors, $s['title']);
}

$fx->cleanup();
echo $totalBad === 0
    ? "KẾT LUẬN: tất cả các bộ test đều đạt.\n\n"
    : "KẾT LUẬN: còn $totalBad ô sai — xem phần CHI TIẾT CẦN XỬ LÝ ở trên.\n\n";
exit($totalBad === 0 ? 0 : 1);
