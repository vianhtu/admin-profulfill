<?php

/**
 * Số liệu cho trang Dashboards — trang đáp mặc định sau khi đăng nhập.
 *
 * Ba nguyên tắc, đừng phá:
 *
 * 1. PHẠM VI DÙNG LẠI HELPER SẴN CÓ. Mọi câu đếm trên `posts` đi qua
 *    `Products::get_base_auth_conditions()` — admin thấy mọi team, manager thấy
 *    team mình, user chỉ thấy dòng mình. Đừng viết bản lọc thứ hai ở đây.
 *
 * 2. WIDGET NÀO CŨNG PHẢI QUA ROLE. Trang thì mở cho mọi người đăng nhập (giống
 *    trang Account), nhưng từng khối số liệu chỉ được tính khi `checkRoles()`
 *    cho phép — không có quyền thì KHÔNG trả dữ liệu về, chứ không phải trả rồi
 *    để JS giấu đi.
 *
 * 3. CHỈ CACHE THỨ ĐÁNG CACHE. Đo trên production 13/08/2026 với 1.066.013 dòng:
 *      - GROUP BY status toàn bảng ......... 146 ms  -> cache
 *      - GROUP BY author_id toàn bảng ......  94 ms  -> cache
 *      - đếm pending quá hạn ...............  66 ms  -> cache
 *      - chuỗi 14 ngày (có index date) .....   5 ms  -> chạy thẳng
 *      - đếm theo 1 author + ngày ..........   0,6 ms -> chạy thẳng
 *    Vì vậy KHÔNG cần thêm index ghép (author_id, date) như dự tính ban đầu.
 */
class Dashboard
{
    /** Số liệu tổng hợp sống trong ngần này giây trước khi tính lại. */
    private const CACHE_TTL = 600;

    /** Ngưỡng coi một sản phẩm là "đứng" ở trạng thái pending. */
    private const STALE_DAYS = 7;

    public static function stats(): array
    {
        $scope = self::scope();
        $data = [
            'scope' => [
                'level' => $scope['level'],
                'username' => (string) ($_SESSION['auth']['user'] ?? ''),
                'team_name' => self::team_name($scope['team']),
            ],
            'generated_at' => date('c'),
        ];

        if (checkRoles('view', 'products')) {
            $data['products'] = self::products_block($scope);
        }
        if (checkRoles('view', 'orders')) {
            $data['orders'] = self::orders_block($scope);
        }
        // Bảng thành viên: chỉ người quản lý mới có nghĩa, và chỉ trong phạm vi
        // của mình. Luật CẤP vẫn áp — manager không được thấy tài khoản admin.
        if ($scope['is_admin'] || $scope['is_manager']) {
            $data['members'] = self::members_block($scope);
        }
        if ($scope['is_admin']) {
            $data['teams'] = self::teams_block();
            $data['security'] = self::security_block();
        }

        $data['alerts'] = self::alerts($data, $scope);

        return ['status' => 'success', 'data' => $data];
    }

    /* ------------------------------------------------------------------ */
    /* Phạm vi                                                             */
    /* ------------------------------------------------------------------ */

    private static function scope(): array
    {
        return [
            'uid' => (int) ($_SESSION['auth']['user_id'] ?? 0),
            'team' => (int) ($_SESSION['auth']['team'] ?? 0),
            'level' => strtolower((string) ($_SESSION['auth']['level'] ?? '')),
            'is_admin' => is_admin(),
            'is_manager' => is_manager(),
        ];
    }

    /** Mệnh đề WHERE/JOIN giới hạn `posts` theo đúng phạm vi người đang xem. */
    private static function posts_scope(): array
    {
        [$join, $where] = Products::get_base_auth_conditions('posts');
        return [$join, $where !== '' ? " AND $where" : ''];
    }

    private static function team_name(int $team_id): string
    {
        if ($team_id <= 0) {
            return '';
        }
        try {
            $row = db()->execute_query('SELECT name FROM team WHERE ID = ? LIMIT 1', [$team_id])->fetch_assoc();
            return (string) ($row['name'] ?? '');
        } catch (mysqli_sql_exception) {
            return '';
        }
    }

    /* ------------------------------------------------------------------ */
    /* Sản phẩm                                                            */
    /* ------------------------------------------------------------------ */

    private static function products_block(array $scope): array
    {
        [$join, $where] = self::posts_scope();

        // Một câu cho cả 4 mốc thời gian — rẻ hơn 4 lần quét riêng.
        $sql = "SELECT
                    COUNT(*) AS total,
                    SUM(posts.date >= CURDATE()) AS today,
                    SUM(posts.date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND posts.date < CURDATE()) AS yesterday,
                    SUM(posts.date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) AS d7,
                    SUM(posts.date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND posts.date < DATE_SUB(CURDATE(), INTERVAL 7 DAY)) AS d7_prev,
                    SUM(posts.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS d30
                FROM posts $join WHERE 1 = 1 $where";

        $kpi = self::row($sql);

        return [
            'total' => (int) ($kpi['total'] ?? 0),
            'today' => (int) ($kpi['today'] ?? 0),
            'yesterday' => (int) ($kpi['yesterday'] ?? 0),
            'd7' => (int) ($kpi['d7'] ?? 0),
            'd7_prev' => (int) ($kpi['d7_prev'] ?? 0),
            'd30' => (int) ($kpi['d30'] ?? 0),
            'series' => self::daily_series(),
            'pipeline' => self::pipeline($scope),
            'stale_pending' => self::stale_pending($scope),
        ];
    }

    /** 14 ngày gần nhất, đã điền ngày trống bằng 0 để biểu đồ không nhảy cóc. */
    private static function daily_series(): array
    {
        [$join, $where] = self::posts_scope();
        $rows = self::rows(
            "SELECT DATE(posts.date) AS d, COUNT(*) AS n
             FROM posts $join
             WHERE posts.date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) $where
             GROUP BY DATE(posts.date)"
        );

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[$row['d']] = (int) $row['n'];
        }

        $series = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-$i day"));
            $series[] = ['date' => $day, 'value' => $byDay[$day] ?? 0];
        }
        return $series;
    }

    /**
     * Phân bố theo trạng thái. Gom bằng LOWER() vì dữ liệu thật đang lẫn hoa
     * thường (`Listed` với `pending`/`schedule`) — không gom thì một trạng thái
     * hiện thành hai cột.
     */
    private static function pipeline(array $scope): array
    {
        $build = function (): array {
            [$join, $where] = self::posts_scope();
            $rows = self::rows(
                "SELECT LOWER(posts.status) AS s, COUNT(*) AS n
                 FROM posts $join WHERE 1 = 1 $where
                 GROUP BY LOWER(posts.status) ORDER BY n DESC"
            );
            return array_map(fn($r) => ['status' => $r['s'], 'count' => (int) $r['n']], $rows);
        };

        // Người thường chỉ đếm trên dòng của mình nên rẻ, tính thẳng cho tươi.
        return $scope['is_admin'] || $scope['is_manager']
            ? self::cached('dash_pipeline', $scope, $build)
            : $build();
    }

    private static function stale_pending(array $scope): int
    {
        $build = function (): int {
            [$join, $where] = self::posts_scope();
            $row = self::row(
                "SELECT COUNT(*) AS n FROM posts $join
                 WHERE LOWER(posts.status) = 'pending'
                   AND posts.date < DATE_SUB(CURDATE(), INTERVAL " . self::STALE_DAYS . " DAY) $where"
            );
            return (int) ($row['n'] ?? 0);
        };

        return $scope['is_admin'] || $scope['is_manager']
            ? (int) self::cached('dash_stale', $scope, $build)
            : $build();
    }

    /* ------------------------------------------------------------------ */
    /* Thành viên                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Ai đang làm, ai đã ngừng. Cột `last_at` mới là thứ đáng nhìn nhất.
     * Luật CẤP: không liệt kê người ở cấp CAO HƠN người đang xem.
     */
    private static function members_block(array $scope): array
    {
        return self::cached('dash_members', $scope, function () use ($scope) {
            $conn = db();
            $conds = [];
            $params = [];

            if (!$scope['is_admin']) {
                $conds[] = 'a.team_id = ?';
                $params[] = $scope['team'];
            }

            $above = levels_above_ids($conn);
            if (!empty($above)) {
                $conds[] = 'a.level NOT IN (' . implode(',', array_map('intval', $above)) . ')';
            }

            $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

            $rows = self::rows(
                "SELECT a.ID, a.username, r.name AS role_name, r.slug AS level,
                        COUNT(p.ID) AS total,
                        SUM(p.date >= CURDATE()) AS today,
                        SUM(p.date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) AS d7,
                        SUM(p.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS d30,
                        MAX(p.date) AS last_at
                 FROM authors a
                 LEFT JOIN posts p ON p.author_id = a.ID
                 LEFT JOIN roles_permissions r ON r.ID = a.level
                 $where
                 GROUP BY a.ID
                 ORDER BY total DESC",
                $params
            );

            return array_map(static fn($r) => [
                'id' => (int) $r['ID'],
                'username' => $r['username'],
                'role_name' => $r['role_name'] ?? '',
                'level' => $r['level'] ?? '',
                'total' => (int) $r['total'],
                'today' => (int) $r['today'],
                'd7' => (int) $r['d7'],
                'd30' => (int) $r['d30'],
                'last_at' => $r['last_at'],
            ], $rows);
        });
    }

    /* ------------------------------------------------------------------ */
    /* Đơn hàng                                                            */
    /* ------------------------------------------------------------------ */

    /** `orders` gắn với team qua `accounts.team_id`, không có cột team riêng. */
    private static function orders_block(array $scope): array
    {
        $where = '';
        $params = [];
        if (!$scope['is_admin']) {
            $where = 'WHERE ac.team_id = ?';
            $params[] = $scope['team'];
        }

        $rows = self::rows(
            "SELECT o.status, COUNT(*) AS n, COALESCE(SUM(o.total_price), 0) AS tien
             FROM orders o INNER JOIN accounts ac ON ac.ID = o.account_id
             $where GROUP BY o.status",
            $params
        );

        $out = ['by_status' => [], 'unshipped' => 0, 'unshipped_value' => 0.0, 'total' => 0];
        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $count = (int) $row['n'];
            $value = (float) $row['tien'];
            $out['by_status'][] = ['status' => $status, 'count' => $count, 'value' => $value];
            $out['total'] += $count;
            if ($status === 'unshipped') {
                $out['unshipped'] = $count;
                $out['unshipped_value'] = $value;
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Chỉ admin                                                           */
    /* ------------------------------------------------------------------ */

    private static function teams_block(): array
    {
        return self::cached('dash_teams', ['team' => 0, 'uid' => 0], function () {
            $rows = self::rows(
                "SELECT t.ID, t.name, t.status,
                        (SELECT COUNT(*) FROM authors a WHERE a.team_id = t.ID) AS members,
                        (SELECT COUNT(*) FROM posts p
                          INNER JOIN authors a2 ON a2.ID = p.author_id
                          WHERE a2.team_id = t.ID) AS products
                 FROM team t ORDER BY products DESC"
            );
            return array_map(static fn($r) => [
                'id' => (int) $r['ID'],
                'name' => $r['name'],
                'active' => (int) $r['status'] === 1,
                'members' => (int) $r['members'],
                'products' => (int) $r['products'],
            ], $rows);
        });
    }

    /**
     * Sức khoẻ phân quyền + dấu vết truy cập API. Đây là những con số mà nếu
     * khác 0 thì phải xử lý ngay, nên để thẳng trên dashboard của admin.
     */
    private static function security_block(): array
    {
        $one = static function (string $sql): int {
            $row = self::row($sql);
            return (int) (reset($row) ?: 0);
        };

        return [
            'inactive_with_key' => $one("SELECT COUNT(*) FROM authors WHERE `key` <> '' AND status <> 2"),
            'weak_keys' => $one("SELECT COUNT(*) FROM authors WHERE `key` <> '' AND CHAR_LENGTH(`key`) < 32"),
            'teams_with_key' => $one("SELECT COUNT(*) FROM team WHERE `key` <> ''"),
            'credential_reads_24h' => self::count_log_lines('[Extensions::audit]'),
            'php_errors_24h' => self::count_log_lines('PHP Fatal error'),
        ];
    }

    /** Đếm dòng log trong 24h. Log đã bị nginx chặn tải qua HTTP từ 13/08/2026. */
    private static function count_log_lines(string $needle): int
    {
        $file = ROOT_DIR . '/php_errors.log';
        if (!is_readable($file) || filesize($file) > 8 * 1024 * 1024) {
            return 0;
        }
        $today = date('d-M-Y');
        $yesterday = date('d-M-Y', strtotime('-1 day'));
        $count = 0;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (!str_contains($line, $needle)) {
                continue;
            }
            if (str_contains($line, $today) || str_contains($line, $yesterday)) {
                $count++;
            }
        }
        return $count;
    }

    /* ------------------------------------------------------------------ */
    /* Cảnh báo                                                            */
    /* ------------------------------------------------------------------ */

    private static function alerts(array $data, array $scope): array
    {
        $alerts = [];

        $stale = $data['products']['stale_pending'] ?? 0;
        if ($stale > 0) {
            $alerts[] = [
                'level' => $stale > 1000 ? 'crit' : 'warn',
                'count' => $stale,
                'text' => 'sản phẩm pending quá ' . self::STALE_DAYS . ' ngày chưa lên sàn',
                'link' => '?menu=products&ProductStatus=pending',
            ];
        }

        if (!empty($data['members'])) {
            $idle = array_filter(
                $data['members'],
                static fn($m) => $m['total'] > 0 && $m['d30'] === 0
            );
            if ($idle) {
                $alerts[] = [
                    'level' => 'warn',
                    'count' => count($idle),
                    'text' => 'thành viên không nhập gì trong 30 ngày nhưng vẫn giữ quyền',
                    'link' => '?menu=users',
                ];
            }
        }

        $unshipped = $data['orders']['unshipped'] ?? 0;
        if ($unshipped > 0) {
            $alerts[] = [
                'level' => 'warn',
                'count' => $unshipped,
                'text' => 'đơn chờ gửi',
                'link' => '?menu=orders&OrderStatus=unshipped',
            ];
        }

        if ($scope['is_admin']) {
            $security = $data['security'] ?? [];
            foreach ([
                'inactive_with_key' => 'tài khoản đã khoá nhưng vẫn giữ key API',
                'weak_keys' => 'key API ngắn hơn 32 ký tự',
                'php_errors_24h' => 'lỗi PHP trong 24 giờ',
            ] as $key => $text) {
                if (!empty($security[$key])) {
                    $alerts[] = ['level' => 'crit', 'count' => $security[$key], 'text' => $text, 'link' => ''];
                }
            }

            $empty_teams = array_filter($data['teams'] ?? [], static fn($t) => $t['products'] === 0);
            if ($empty_teams) {
                $alerts[] = [
                    'level' => 'info',
                    'count' => count($empty_teams),
                    'text' => 'team chưa nhập sản phẩm nào',
                    'link' => '?menu=teams',
                ];
            }
        }

        return $alerts;
    }

    /* ------------------------------------------------------------------ */
    /* Hạ tầng nhỏ: cache + truy vấn                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Cache trong bảng `options` (không thêm bảng mới, và đã nằm sẵn trong luồng
     * dọn dữ liệu khi xoá team). Khoá gồm cả phạm vi để số của team này không
     * rơi sang team khác.
     */
    private static function cached(string $key, array $scope, callable $build)
    {
        $team = (int) ($scope['team'] ?? 0);
        $owner = ($scope['is_admin'] ?? false) ? 0 : $team;
        $name = $key . '_' . (($scope['is_admin'] ?? false) ? 'all' : "t$team");

        $raw = getOption($name, $owner, 0, null);
        if (is_string($raw) && $raw !== '') {
            $cached = json_decode($raw, true);
            if (is_array($cached) && ($cached['at'] ?? 0) > time() - self::CACHE_TTL) {
                return $cached['v'];
            }
        }

        $value = $build();
        self::put_option($name, $owner, json_encode(['at' => time(), 'v' => $value]));
        return $value;
    }

    private static function put_option(string $name, int $team, string $value): void
    {
        try {
            $conn = db();
            $conn->execute_query(
                'UPDATE options SET value = ? WHERE name = ? AND team_id = ? AND authors_id = 0',
                [$value, $name, $team]
            );
            if ($conn->affected_rows === 0) {
                $conn->execute_query(
                    'INSERT INTO options (team_id, authors_id, name, value) VALUES (?, 0, ?, ?)',
                    [$team, $name, $value]
                );
            }
        } catch (mysqli_sql_exception $e) {
            error_log('[Dashboard::put_option] ' . $e->getMessage());
        }
    }

    private static function rows(string $sql, array $params = []): array
    {
        try {
            return db()->execute_query($sql, $params)->fetch_all(MYSQLI_ASSOC);
        } catch (mysqli_sql_exception $e) {
            error_log('[Dashboard] ' . $e->getMessage());
            return [];
        }
    }

    private static function row(string $sql, array $params = []): array
    {
        $rows = self::rows($sql, $params);
        return $rows[0] ?? [];
    }
}
