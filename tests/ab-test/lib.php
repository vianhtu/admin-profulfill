<?php
/**
 * AB TEST — khung kiểm thử phân quyền theo ma trận ACTOR x CHỨC NĂNG.
 *
 * Ý tưởng: mỗi chức năng được gọi lại đúng như web gọi, nhưng lần lượt dưới danh nghĩa
 * của nhiều "actor" (admin, manager, user, các mức role, và người của team khác).
 * Mỗi ô trong ma trận có KỲ VỌNG khai báo sẵn (được phép / phải bị chặn), nên kết quả
 * tự chấm được: cho qua trong khi phải chặn = LỖ HỔNG, chặn trong khi phải cho = CHẶN NHẦM.
 *
 * CHỈ CHẠY ĐƯỢC Ở CLI — xem chặn ở dưới. Không bao giờ được để file này gọi qua web
 * vì nó giả lập session của người khác.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const AB_ROOT = __DIR__ . '/../..';

require_once AB_ROOT . '/config.php';
require_once AB_ROOT . '/functions.php';
foreach (['products', 'product', 'categories', 'category', 'sites', 'site', 'stores', 'store', 'extension'] as $abClass) {
    require_once AB_ROOT . "/class/class.$abClass.php";
}

/**
 * Một vai người dùng dùng để chạy thử: cấp bậc + team + bộ role của menu đang test.
 */
final class AbActor
{
    public function __construct(
        public string $key,
        public string $label,
        public string $level,
        public int $uid,
        public int $team,
        /** @var string[] danh sách role của menu đang test: view/add/edit/delete */
        public array $roles
    ) {}

    /** Nạp actor này vào $_SESSION đúng như lúc đăng nhập thật. */
    public function apply(string $menu): void
    {
        $roles = [];
        foreach ($this->roles as $r) {
            $roles[$r] = '1';
        }
        $_SESSION['auth'] = [
            'user'    => 'abtest_' . $this->key,
            'ua'      => 'abtest',
            't'       => time(),
            'user_id' => $this->uid,
            'team'    => $this->team,
            'level'   => $this->level,
            // Cấp role cho MỌI menu để chỉ còn 1 biến thay đổi là phạm vi dữ liệu
            'roles'   => $roles ? array_fill_keys(
                ['products', 'categories', 'sites', 'store', 'orders'], $roles
            ) : [],
        ];
        $_SESSION['csrf_token'] = 'ABTEST';
        $_SESSION['count_cache'] = [];
        unset($_SESSION['products_stats_' . $this->uid]);
        $_REQUEST['menu'] = $menu;
        $_POST = [];
        $_GET  = [];
    }
}

/**
 * Bộ actor chuẩn. uid phải là author CÓ THẬT vì nhiều chỗ tra authors.team_id
 * (vd. kiểm tra category/store theo team của chủ sở hữu).
 */
function ab_actors(): array
{
    $all = ['view', 'add', 'edit', 'delete'];
    return [
        new AbActor('ADMIN',    'ADMIN',              'admin',   AB_UID_T1, 1, $all),
        new AbActor('MGR_T1',   'MANAGER T1 đủ role', 'manager', AB_UID_T1, 1, $all),
        new AbActor('MGR_T1_V', 'MANAGER T1 chỉ view','manager', AB_UID_T1, 1, ['view']),
        new AbActor('USR_T1',   'USER T1 đủ role',    'user',    AB_UID_T1, 1, $all),
        new AbActor('USR_T1_V', 'USER T1 chỉ view',   'user',    AB_UID_T1, 1, ['view']),
        new AbActor('MGR_T2',   'MANAGER T2 đủ role', 'manager', AB_UID_T2, 2, $all),
        new AbActor('USR_T2',   'USER T2 đủ role',    'user',    AB_UID_T2, 2, $all),
        new AbActor('USR_T3',   'USER T3 đủ role',    'user',    AB_UID_T3, 3, $all),
        new AbActor('NOROLE',   'USER không role',    'user',    AB_UID_T1, 1, []),
    ];
}

/**
 * Dữ liệu tạm cho 1 lần chạy: sản phẩm/category/store/site của từng team.
 * Mọi bản ghi đều mang tiền tố ZZAB và bị xóa sạch khi kết thúc (kể cả khi lỗi).
 */
final class AbFixtures
{
    public mysqli $conn;
    public int $catShared = 0;
    public int $catMine   = 0;   // do USR_T3 tạo -> dùng để test luật "người tạo sửa được"
    public int $storeShared = 0;
    public int $storeT2   = 0;   // store riêng của team 2
    public int $site      = 0;
    public int $postT1    = 0;
    public int $postT2    = 0;
    private array $temp = [];

    public function __construct()
    {
        $this->conn = db();
        $this->cleanup();

        $c = $this->conn;
        $c->query("INSERT INTO `type` (name, team_id, user_prompt, created_by) VALUES ('ZZAB Category', 0, '', 0)");
        $this->catShared = (int)$c->insert_id;
        $c->query("INSERT INTO `type` (name, team_id, user_prompt, created_by) VALUES ('ZZAB Category Mine', 0, '', " . AB_UID_T3 . ')');
        $this->catMine = (int)$c->insert_id;

        $c->query("INSERT INTO site (name, slug, created_by) VALUES ('zzab-site.com', 'zzab-site-com', 0)");
        $this->site = (int)$c->insert_id;

        $siteId = (int)$c->query('SELECT ID FROM site ORDER BY ID LIMIT 1')->fetch_row()[0];
        $c->query("INSERT INTO store (name, slug, site_id, status, team_id) VALUES ('ZZAB Store Shared', 'zzab-store-shared', $siteId, 1, 0)");
        $this->storeShared = (int)$c->insert_id;
        $c->query("INSERT INTO store (name, slug, site_id, status, team_id) VALUES ('ZZAB Store T2', 'zzab-store-t2', $siteId, 1, 2)");
        $this->storeT2 = (int)$c->insert_id;

        $this->postT1 = $this->new_post(AB_UID_T1, 'ZZAB-P-T1');
        $this->postT2 = $this->new_post(AB_UID_T2, 'ZZAB-P-T2');
    }

    /** Tạo 1 sản phẩm mới cho chủ sở hữu chỉ định (dùng cho các test ghi/xóa). */
    public function new_post(int $authorId, string $skuPrefix = 'ZZAB-TMP'): int
    {
        $sku = $skuPrefix . '-' . bin2hex(random_bytes(4));
        $siteId = (int)$this->conn->query('SELECT ID FROM site ORDER BY ID LIMIT 1')->fetch_row()[0];
        $this->conn->execute_query(
            "INSERT INTO posts (author_id, date, title, status, sku, images, type_id, site_id, store_id)
             VALUES (?, NOW(), 'ZZAB product', 'pending', ?, '{\"main\":\"http://x/a.jpg\"}', ?, ?, ?)",
            [$authorId, $sku, $this->catShared, $siteId, $this->storeShared]
        );
        return (int)$this->conn->insert_id;
    }

    /** Tạo 1 store tạm để test sửa/xóa. */
    public function new_store(int $teamId): int
    {
        $siteId = (int)$this->conn->query('SELECT ID FROM site ORDER BY ID LIMIT 1')->fetch_row()[0];
        $slug = 'zzab-tmp-' . bin2hex(random_bytes(4));
        $this->conn->execute_query(
            'INSERT INTO store (name, slug, site_id, status, team_id) VALUES (?, ?, ?, 1, ?)',
            ['ZZAB ' . $slug, $slug, $siteId, $teamId]
        );
        return (int)$this->conn->insert_id;
    }

    /** Tạo 1 category tạm do người chỉ định tạo. */
    public function new_category(int $createdBy): int
    {
        $name = 'ZZAB Cat ' . bin2hex(random_bytes(4));
        $this->conn->execute_query(
            'INSERT INTO `type` (name, team_id, user_prompt, created_by) VALUES (?, 0, \'\', ?)',
            [$name, $createdBy]
        );
        return (int)$this->conn->insert_id;
    }

    /** Tạo 1 site tạm do người chỉ định tạo. */
    public function new_site(int $createdBy): int
    {
        $slug = 'zzab-tmp-' . bin2hex(random_bytes(4));
        $this->conn->execute_query(
            'INSERT INTO site (name, slug, created_by) VALUES (?, ?, ?)',
            [$slug . '.com', $slug, $createdBy]
        );
        return (int)$this->conn->insert_id;
    }

    /** Xóa toàn bộ dữ liệu ZZAB — gọi cả khi chạy lỗi giữa chừng. */
    public function cleanup(): void
    {
        $c = $this->conn;
        $c->query("DELETE FROM accounts_relationships WHERE post_id IN (SELECT ID FROM posts WHERE sku LIKE 'ZZAB%')");
        $c->query("DELETE FROM posts WHERE sku LIKE 'ZZAB%'");
        $c->query("DELETE FROM `type` WHERE name LIKE 'ZZAB%'");
        $c->query("DELETE FROM store WHERE name LIKE 'ZZAB%' OR slug LIKE 'zzab-%'");
        $c->query("DELETE FROM site WHERE slug LIKE 'zzab-%'");
    }
}

/**
 * 1 phép thử: chạy $action dưới danh nghĩa từng actor, so với danh sách được phép.
 */
final class AbCheck
{
    /** @var string[] key của các actor ĐƯỢC PHÉP; còn lại phải bị chặn */
    public array $allow = [];

    public function __construct(
        public string $group,
        public string $name,
        /** @var callable(AbActor, AbFixtures): mixed */
        public $action
    ) {}

    /** Khai báo actor nào được phép làm việc này. Không liệt kê = phải bị chặn. */
    public function allow(string ...$keys): self
    {
        $this->allow = $keys;
        return $this;
    }
}

/**
 * Chuyển giá trị trả về của hàm nghiệp vụ thành ALLOW/DENY.
 * Quy ước trong dự án: mảng có status='error' hoặc null/false = bị chặn.
 */
function ab_verdict(mixed $res): string
{
    if ($res === null || $res === false) {
        return 'DENY';
    }
    if (is_array($res) && ($res['status'] ?? '') === 'error') {
        return 'DENY';
    }
    if (is_array($res) && array_key_exists('status', $res) && $res['status'] === 'partial') {
        return 'ALLOW';
    }
    return 'ALLOW';
}

/** Sản phẩm/bản ghi X có xuất hiện trong danh sách mà actor nhìn thấy không. */
function ab_sees(array $table, int $id): bool
{
    foreach ($table['data'] ?? [] as $row) {
        if ((int)($row['id'] ?? 0) === $id) {
            return true;
        }
    }
    return false;
}

/** Tham số DataTables tối thiểu cho các hàm get_*_table. */
function ab_dt(array $extra = []): array
{
    return array_merge([
        'draw' => 1, 'start' => 0, 'length' => 200,
        'order' => [['column' => 0, 'dir' => 'asc']],
        'columns' => [['data' => 'name']],
        'search' => ['value' => ''],
    ], $extra);
}

/**
 * Chạy ma trận và in báo cáo.
 */
final class AbRunner
{
    /** @var AbCheck[] */
    private array $checks = [];
    private array $results = [];
    private array $errors  = [];

    public function __construct(private string $menu, private AbFixtures $fx) {}

    public function add(string $group, string $name, callable $action): AbCheck
    {
        $c = new AbCheck($group, $name, $action);
        $this->checks[] = $c;
        return $c;
    }

    public function run(array $actors): void
    {
        foreach ($this->checks as $i => $check) {
            foreach ($actors as $actor) {
                $actor->apply($this->menu);
                try {
                    $res = ($check->action)($actor, $this->fx);
                    $verdict = is_bool($res) ? ($res ? 'ALLOW' : 'DENY') : ab_verdict($res);
                    $detail = is_array($res) ? (string)($res['message'] ?? '') : '';
                } catch (\Throwable $e) {
                    $verdict = 'ERROR';
                    $detail = get_class($e) . ': ' . $e->getMessage();
                    $this->errors[] = [$check->name, $actor->key, $detail];
                }
                $expected = in_array($actor->key, $check->allow, true) ? 'ALLOW' : 'DENY';
                $this->results[$i][$actor->key] = [
                    'expected' => $expected,
                    'actual'   => $verdict,
                    'detail'   => $detail,
                ];
            }
        }
    }

    /** Ký hiệu 1 ô cho gọn bảng. */
    private static function mark(string $expected, string $actual): string
    {
        if ($actual === 'ERROR') {
            return 'E';
        }
        if ($expected === $actual) {
            return $actual === 'ALLOW' ? 'o' : '-';
        }
        return $expected === 'DENY' ? '!' : 'x';
    }

    public function report(array $actors, string $title): int
    {
        $w = 46;
        $line = str_repeat('=', $w + count($actors) * 10);
        echo "\n$line\nAB TEST: $title\n$line\n\n";
        printf("%-{$w}s", 'CHỨC NĂNG');
        foreach ($actors as $a) {
            printf('%-10s', $a->key);
        }
        echo "\n" . str_repeat('-', $w + count($actors) * 10) . "\n";

        $group = null;
        $fails = [];
        foreach ($this->checks as $i => $check) {
            if ($check->group !== $group) {
                $group = $check->group;
                echo "\n[$group]\n";
            }
            $name = mb_strimwidth($check->name, 0, $w - 2, '…');
            printf("%-{$w}s", '  ' . $name);
            foreach ($actors as $a) {
                $r = $this->results[$i][$a->key];
                $m = self::mark($r['expected'], $r['actual']);
                printf('%-10s', $m);
                if ($m === '!' || $m === 'x' || $m === 'E') {
                    $fails[] = [$m, $check->name, $a->label, $r['detail']];
                }
            }
            echo "\n";
        }

        $total = count($this->checks) * count($actors);
        $bad   = count($fails);
        echo "\n" . str_repeat('-', $w + count($actors) * 10) . "\n";
        echo "Chú thích:  o = được phép (đúng)   - = bị chặn (đúng)   "
           . "! = LỖ HỔNG (phải chặn mà lại cho)   x = CHẶN NHẦM   E = LỖI\n";
        echo "Tổng: $total ô | Đạt: " . ($total - $bad) . " | Sai: $bad\n";

        if ($fails) {
            echo "\nCHI TIẾT CẦN XỬ LÝ\n" . str_repeat('-', 60) . "\n";
            foreach ($fails as [$m, $name, $who, $detail]) {
                $tag = $m === '!' ? 'LỖ HỔNG ' : ($m === 'x' ? 'CHẶN NHẦM' : 'LỖI     ');
                echo "  [$tag] $name — $who" . ($detail !== '' ? "\n              → $detail" : '') . "\n";
            }
        } else {
            echo "\nKhông phát hiện lỗ hổng nào.\n";
        }
        echo "\n";
        return $bad;
    }
}
