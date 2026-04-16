<?php
function getCommonFilterData(): array {
    $conn = db();
    $results = ['results' => [], 'pagination' => ['more' => false]];
    $type = $_POST['type'] ?? '';
    if (!is_internal() || empty($type)) {
        return $results;
    }

    $auth = $_SESSION['auth'];
    $level = $auth['level'] ?? '';
    $q    = isset($_POST['q']) ? trim($_POST['q']) : '';
    $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
    $perPage = 20;
    $offset  = ($page - 1) * $perPage;

    $config = [
        'accounts' => [
            'base_sql' => "SELECT a.id, CONCAT(s.name, ' (', a.name, ')') AS text FROM accounts a JOIN site s ON a.site_id = s.id",
            'search_where' => "(a.name LIKE ? OR a.email LIKE ? OR a.user_id LIKE ?)",
            'order' => "a.site_id ASC",
            'alias' => 'a'
        ]
    ];

    if (!isset($config[$type])) return $results;

    $cfg = $config[$type];
    $whereClauses = [$cfg['search_where']];

    $like = "%{$q}%";
    $params = [];
    $types = "";

    // Phân quyền dữ liệu
    if ($type === 'accounts') {
        $params = [$like, $like, $like];
        $types = "sss";
        if ($level == "manager") {
            $whereClauses[] = "a.team_id = ?";
            $params[] = (int)($auth['team'] ?? 0);
            $types .= "i";
        } elseif ($level == "user") {
            $whereClauses[] = "a.team_id = ?";
            $whereClauses[] = "a.author_id = ?";
            $params[] = (int)($auth['team'] ?? 0);
            $params[] = (int)($auth['user_id'] ?? 0);
            $types .= "ii";
        }
    }

    $sql = $cfg['base_sql'] . " WHERE " . implode(" AND ", $whereClauses);
    $sql .= " ORDER BY {$cfg['order']} LIMIT ?, ?";

    $params[] = $offset;
    $params[] = $perPage;
    $types .= "ii";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return $results; // Check lỗi prepare SQL

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return [
        'results' => $items,
        'pagination' => ['more' => count($items) === $perPage]
    ];
}