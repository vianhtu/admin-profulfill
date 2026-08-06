<?php
/**
 * HACK — bộ kiểm thử TẤN CÔNG (red-team) cho admin-profulfill.
 *
 * Khác AB TEST: AB TEST xác minh luật đã khai báo có đúng không. HACK đóng vai KẺ TẤN
 * CÔNG và chủ động thử các kỹ thuật khai thác — SQL injection, IDOR (đọc/ghi bản ghi
 * ngoài quyền qua ID giả), giả mạo tham số, leo thang quyền, bỏ qua CSRF/đăng nhập,
 * rò rỉ dữ liệu, type juggling — để tìm lỗ hổng THẬT trong code và truy vấn.
 *
 * Nguyên tắc chấm: MỌI đòn tấn công đều PHẢI bị chặn. Đòn nào lọt = LỖ HỔNG.
 *
 * CHỈ CHẠY Ở CLI. Dùng lại actor/fixture/render của AB TEST.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../ab-test/lib.php';

/**
 * Các "kẻ tấn công" chuẩn — từ ngoài vào trong theo mức độ tin cậy.
 */
function hack_attackers(): array
{
    $all = ['view', 'add', 'edit', 'delete'];
    return [
        // Không đăng nhập
        'ANON'      => new AbActor('ANON', 'Khách vãng lai (chưa đăng nhập)', 'user', 0, 0, []),
        // Có tài khoản user, đủ role, nhưng thuộc TEAM KHÁC (team 3) — muốn với sang team 1/2
        'USR_OUT'   => new AbActor('USR_OUT', 'USER team 3 (đủ role)', 'user', AB_UID_T3, 3, $all),
        // Có tài khoản manager, đủ role, team khác (team 2)
        'MGR_OUT'   => new AbActor('MGR_OUT', 'MANAGER team 2 (đủ role)', 'manager', AB_UID_T2, 2, $all),
        // Có tài khoản trong đúng team nhưng CHỈ có quyền view — muốn leo lên ghi/xóa
        'USR_VIEW'  => new AbActor('USR_VIEW', 'USER team 1 (chỉ view)', 'user', AB_UID_T1, 1, ['view']),
        // Admin THƯỜNG (không phải super admin): mô hình "kẻ nội gián đã có quyền cao nhất
        // mà UI cấp được" — mục tiêu duy nhất còn lại là chiếm quyền SUPER ADMIN.
        'ADM_PLAIN' => new AbActor('ADM_PLAIN', 'ADMIN thường (không phải super admin)', 'admin', AB_UID_T1, 1, $all),
    ];
}

/** Áp attacker + reset $_POST/$_GET, đặt menu để checkRoles đọc đúng. */
function hack_as(AbActor $atk, string $menu): void
{
    if ($atk->key === 'ANON') {
        // Chưa đăng nhập: session rỗng, không có auth
        $_SESSION = [];
        $_SESSION['csrf_token'] = 'VICTIM';   // token của nạn nhân, kẻ tấn công không biết
        $_REQUEST = ['menu' => $menu];
        $_POST = [];
        $_GET = [];
        return;
    }
    $atk->apply($menu);
    // Giả lập: kẻ tấn công KHÔNG biết csrf_token thật của nạn nhân
    $_SESSION['csrf_token'] = 'VICTIM_SECRET';
}

/**
 * Một đòn tấn công: chạy dưới danh nghĩa 1 attacker, tự quyết định có "lọt" (breach) không.
 */
final class HackAttack
{
    public string $verdict = '';   // BLOCKED | BREACH | ERROR
    public string $evidence = '';

    public function __construct(
        public string $category,
        public string $technique,
        public string $attacker,
        /** @var callable(AbActor, AbFixtures): array{breach:bool,note?:string} */
        public $exploit,
        public string $severity = 'CAO'
    ) {}
}

/**
 * Chạy các đòn tấn công và in báo cáo kiểu pentest.
 */
final class HackRunner
{
    /** @var HackAttack[] */
    private array $attacks = [];

    public function __construct(private string $menu, private AbFixtures $fx) {}

    /**
     * @param callable(AbActor, AbFixtures): array{breach:bool,note?:string} $exploit
     *        trả breach=true nghĩa là đòn tấn công THÀNH CÔNG (tức có lỗ hổng).
     */
    public function attack(string $category, string $technique, string $attackerKey,
                           callable $exploit, string $severity = 'CAO'): void
    {
        $this->attacks[] = new HackAttack($category, $technique, $attackerKey, $exploit, $severity);
    }

    public function run(): void
    {
        $attackers = hack_attackers();
        foreach ($this->attacks as $atk) {
            $actor = $attackers[$atk->attacker] ?? null;
            if (!$actor) {
                $atk->verdict = 'ERROR';
                $atk->evidence = 'Không có attacker ' . $atk->attacker;
                continue;
            }
            hack_as($actor, $this->menu);
            try {
                $res = ($atk->exploit)($actor, $this->fx);
                $atk->verdict = !empty($res['breach']) ? 'BREACH' : 'BLOCKED';
                $atk->evidence = (string)($res['note'] ?? '');
            } catch (\Throwable $e) {
                // Ngoại lệ = truy vấn/đòn đánh làm code văng lỗi: đáng ngờ, không coi là an toàn
                $atk->verdict = 'ERROR';
                $atk->evidence = get_class($e) . ': ' . $e->getMessage();
            }
        }
    }

    public function report(string $title): int
    {
        echo "\n" . str_repeat('=', 100) . "\n";
        echo "HACK REPORT: $title\n";
        echo str_repeat('=', 100) . "\n\n";

        $group = null;
        $breach = 0;
        $errors = 0;
        foreach ($this->attacks as $a) {
            if ($a->category !== $group) {
                $group = $a->category;
                echo "\n▍ $group\n";
            }
            if ($a->verdict === 'BLOCKED') {
                $mark = '  [ CHẶN  ]';
            } elseif ($a->verdict === 'BREACH') {
                $mark = '  [ THỦNG!]';
                $breach++;
            } else {
                $mark = '  [ LỖI   ]';
                $errors++;
            }
            $atkName = hack_attackers()[$a->attacker]->label ?? $a->attacker;
            printf("%s %s\n", $mark, $a->technique);
            printf("            attacker: %s\n", $atkName);
            if ($a->evidence !== '' && $a->verdict !== 'BLOCKED') {
                printf("            → %s\n", $a->evidence);
            }
        }

        $total = count($this->attacks);
        echo "\n" . str_repeat('-', 100) . "\n";
        echo "Chú thích:  [ CHẶN ] đòn tấn công bị chặn (tốt)   "
           . "[ THỦNG! ] LỖ HỔNG — khai thác thành công   [ LỖI ] code văng lỗi khi bị đánh\n";
        echo "Tổng đòn: $total | Chặn: " . ($total - $breach - $errors)
           . " | THỦNG: $breach | Lỗi: $errors\n";

        if ($breach || $errors) {
            echo "\n★ CẦN VÁ NGAY\n" . str_repeat('-', 60) . "\n";
            foreach ($this->attacks as $a) {
                if ($a->verdict === 'BREACH') {
                    echo "  [LỖ HỔNG/{$a->severity}] {$a->technique}\n"
                       . "      Kẻ tấn công: " . (hack_attackers()[$a->attacker]->label ?? $a->attacker) . "\n"
                       . ($a->evidence !== '' ? "      Bằng chứng: {$a->evidence}\n" : '');
                } elseif ($a->verdict === 'ERROR') {
                    echo "  [LỖI CODE] {$a->technique}\n      → {$a->evidence}\n";
                }
            }
        } else {
            echo "\n✔ Không khai thác được lỗ hổng nào.\n";
        }
        echo "\n";
        return $breach + $errors;
    }
}

/** Đếm số bản ghi khớp 1 câu WHERE — dùng làm bằng chứng "rò rỉ / xóa lố". */
function hack_count(mysqli $conn, string $sql): int
{
    return (int)$conn->query($sql)->fetch_row()[0];
}
