<?php
/**
 * HACK — điểm chạy bộ kiểm thử tấn công.
 *
 *   php tests/hack/run.php Sites
 *   php tests/hack/run.php Products | Category | Store | all
 *
 * Mã thoát khác 0 nếu có đòn tấn công LỌT (lỗ hổng) hoặc làm code văng lỗi.
 */

require_once __DIR__ . '/lib.php';

define('AB_UID_T1', hack_pick_author(1));
define('AB_UID_T2', hack_pick_author(2));
define('AB_UID_T3', hack_pick_author(3));

function hack_pick_author(int $team): int
{
    $row = db()->query("SELECT ID FROM authors WHERE team_id = $team ORDER BY ID LIMIT 1")->fetch_row();
    return $row ? (int)$row[0] : (int)db()->query('SELECT ID FROM authors ORDER BY ID LIMIT 1')->fetch_row()[0];
}

$suites = [
    'products'   => ['file' => 'attacks.products.php',   'menu' => 'products',   'title' => 'Products / Product'],
    'categories' => ['file' => 'attacks.categories.php', 'menu' => 'categories', 'title' => 'Categories / Category'],
    'sites'      => ['file' => 'attacks.sites.php',      'menu' => 'sites',      'title' => 'Sites / Site'],
    'stores'     => ['file' => 'attacks.stores.php',     'menu' => 'store',      'title' => 'Stores / Store'],
    'orders'     => ['file' => 'attacks.orders.php',     'menu' => 'orders',     'title' => 'Orders / Order'],
    'teams'      => ['file' => 'attacks.teams.php',      'menu' => 'teams',      'title' => 'Teams / Team (admin-only)'],
    'users'      => ['file' => 'attacks.users.php',     'menu' => 'users',      'title' => 'Users / User'],
    'roles'      => ['file' => 'attacks.roles.php',     'menu' => 'roles-permissions', 'title' => 'Roles / Role (phân quyền)'],
    'upload'     => ['file' => 'attacks.upload.php',     'menu' => 'sites',      'title' => 'Upload file (logo site)'],
    'account'    => ['file' => 'attacks.account.php',   'menu' => 'account',    'title' => 'Account (hồ sơ cá nhân)'],
];
$alias = ['product' => 'products', 'category' => 'categories', 'danhmuc' => 'categories',
    'site' => 'sites', 'store' => 'stores', 'shop' => 'stores', 'sanpham' => 'products',
    'order' => 'orders', 'donhang' => 'orders', 'team' => 'teams', 'nhom' => 'teams',
    'user' => 'users', 'nguoidung' => 'users', 'author' => 'users', 'authors' => 'users',
    'uploadfile' => 'upload', 'file' => 'upload', 'logo' => 'upload',
    'taikhoan' => 'account', 'profile' => 'account', 'hoso' => 'account'];

$arg = strtolower(trim($argv[1] ?? 'all'));
$arg = $alias[$arg] ?? $arg;
$run = $arg === 'all' ? array_keys($suites) : (isset($suites[$arg]) ? [$arg] : null);
if ($run === null) {
    echo "Không có bộ HACK '$arg'. Chọn: " . implode(', ', array_keys($suites)) . ", all\n";
    exit(2);
}

$fx = new AbFixtures();
register_shutdown_function(fn() => $fx->cleanup());

echo "\nHACK — red-team profulfill admin\n";
echo 'Ngày: ' . date('Y-m-d H:i:s') . "\nKẻ tấn công:\n";
foreach (hack_attackers() as $a) {
    echo '  ' . mb_str_pad($a->key, 10) . $a->label . "\n";
}

$total = 0;
foreach ($run as $key) {
    $s = $suites[$key];
    $h = new HackRunner($s['menu'], $fx);
    (require __DIR__ . '/' . $s['file'])($h);
    $h->run();
    $total += $h->report($s['title']);
}

$fx->cleanup();
echo $total === 0
    ? "KẾT LUẬN: không khai thác được lỗ hổng nào. Hệ thống trụ vững trước các đòn đã thử.\n\n"
    : "KẾT LUẬN: còn $total lỗ hổng/lỗi — xem phần CẦN VÁ NGAY ở trên.\n\n";
exit($total === 0 ? 0 : 1);
