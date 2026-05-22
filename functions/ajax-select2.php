<?php
function getCommonFilterData(): array {
    $results = ['results' => [], 'pagination' => ['more' => false]];
    $type = $_POST['type'] ?? '';

    if (!is_internal() || empty($type)) {
        return $results;
    }

    $auth = $_SESSION['auth'] ?? [];
    $level = $auth['level'] ?? '';
    $q = trim($_POST['q'] ?? '');
    $folow_val = (int)($_POST['folow_val'] ?? 0);

    $page = max(1, intval($_POST['page'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;
    $like = "%{$q}%";

    // CẤU HÌNH TẬP TRUNG: Thêm bảng mới chỉ cần khai báo thêm ở đây
    $config = [
        'accounts' => [
            'base_sql'     => "SELECT a.id, CONCAT(s.name, ' (', a.name, ')') AS text FROM accounts a JOIN site s ON a.site_id = s.id LEFT JOIN accounts_authors aa ON a.id = aa.account_id",
            'search_where' => "(a.name LIKE ? OR a.email LIKE ? OR a.user_id LIKE ?)",
            'search_count' => 3, // Số lượng dấu ? trong search_where
            'order'        => "a.site_id ASC",
            'auth_handler' => function(string $level, array $auth, int $folow_val) {
                return match($level) {
                    'admin'   => $folow_val > 0 ? ["a.team_id = ?", [$folow_val]] : [[], []],
                    'manager' => ["a.team_id = ?", [(int)($auth['team'] ?? 0)]],
                    'user'    => ["a.team_id = ? AND aa.author_id = ?", [(int)($auth['team'] ?? 0), (int)($auth['user_id'] ?? 0)]],
                    default   => [[], []]
                };
            }
        ],
        'authors' => [
            'base_sql'     => "SELECT a.id, CONCAT(t.name, ' (', a.username, ')') AS text FROM authors a JOIN team t ON a.team_id = t.id",
            'search_where' => "(a.email LIKE ? OR a.username LIKE ?)",
            'search_count' => 2,
            'order'        => "a.team_id ASC",
            'auth_handler' => function(string $level, array $auth, int $folow_val) {
                return match($level) {
                    'admin'   => $folow_val > 0 ? ["a.team_id = ?", [$folow_val]] : [[], []],
                    'manager' => ["a.team_id = ?", [(int)($auth['team'] ?? 0)]],
                    'user'    => ["a.ID = ?", [(int)($auth['user_id'] ?? 0)]],
                    default   => [[], []]
                };
            }
        ],
        'shipping' => [
            'base_sql'     => "SELECT id, name AS text FROM shipping_services",
            'search_where' => "name LIKE ?",
            'search_count' => 1,
            'order'        => "id ASC",
            'auth_handler' => fn() => [[], []] // Không phân quyền
        ]
    ];

    if (!isset($config[$type])) {
        return $results;
    }

    $cfg = $config[$type];

    // 1. Khởi tạo tham số tìm kiếm (LIKE)
    $whereClauses = [$cfg['search_where']];
    $params = array_fill(0, $cfg['search_count'], $like);

    // 2. Xử lý phân quyền tự động qua Handler mẫu
    [$authWhere, $authParams] = $cfg['auth_handler']($level, $auth, $folow_val);
    if (!empty($authWhere)) {
        $whereClauses[] = $authWhere;
        $params = [...$params, ...$authParams];
    }

    // 3. Xây dựng câu SQL hoàn chỉnh
    $sql = "{$cfg['base_sql']} WHERE " . implode(" AND ", $whereClauses) . " ORDER BY {$cfg['order']} LIMIT ?, ?";

    // Push tham số phân trang vào cuối mảng
    $params[] = $offset;
    $params[] = $perPage;

    // 4. Thực thi (Kiểu chuẩn Modern PHP 8.x)
    $conn = db();
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $results;
    }

    // Trực tiếp execute với mảng tham số, không cần bind_param() nữa!
    $stmt->execute($params);
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return [
        'results' => $items,
        'pagination' => ['more' => count($items) === $perPage]
    ];
}