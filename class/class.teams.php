<?php
/**
 * Teams — nghiệp vụ tập hợp cho bảng `team` (menu Teams): danh sách, xóa.
 * Cùng khuôn với class.stores.php / class.orders.php.
 *
 * Mô hình sở hữu (chốt với người dùng 05/08/2026): menu ADMIN-ONLY — không đi qua
 * roles_permissions; mọi endpoint tự kiểm is_admin().
 *
 * XÓA TEAM = XÓA DÂY CHUYỀN (chốt 05/08/2026, thay luật "chặn khi còn tham chiếu"):
 * xóa sạch mọi thứ DÙNG RIÊNG của team, GIỮ NGUYÊN mọi thứ dùng chung.
 *
 *   XÓA  : users của team + token đăng nhập; accounts của team + toàn bộ bảng con của
 *          account (finance/seller/links/proxy/files/authors) + đơn hàng của account đó;
 *          sản phẩm của các user đó + bảng con của sản phẩm; store RIÊNG của team.
 *   GIỮ  : site, category (`type`), store DÙNG CHUNG (team_id = 0), roles_permissions,
 *          và các bảng tra cứu toàn hệ thống. Cột `created_by` trỏ tới user vừa xóa
 *          được đưa về 0 (bản ghi thành "chỉ admin sửa") thay vì xóa bản ghi dùng chung.
 *
 * Khối lượng có thể rất lớn (một team có thể có hàng trăm nghìn sản phẩm) nên tiến trình
 * chạy THEO LÔ, mỗi request làm một phần rồi trả tiến độ cho client gọi tiếp — không bọc
 * một transaction khổng lồ, và đứt giữa chừng thì gọi lại vẫn chạy tiếp được.
 */
class Teams
{
    /**
     * Cột sắp xếp được: tên cột DataTables => biểu thức SQL.
     * Cột nào không có ở đây thì bên JS phải để orderable: false.
     */
    private const SORT_MAP = [
        'name'    => 't.name',
        'members' => 'members',
        'status'  => 't.status',
        'ID'      => 't.ID',
    ];

    /** Số dòng xóa mỗi câu lệnh (giữ transaction ngắn, không khóa bảng lâu). */
    private const PURGE_CHUNK = 1000;
    /** Trần số dòng xử lý trong 1 request — vượt thì trả 'partial' để client gọi tiếp. */
    private const PURGE_BUDGET = 20000;

    /**
     * Bảng con của ACCOUNT — xóa theo account_id. Bảng/cột không tồn tại sẽ bị bỏ qua
     * (xem col_exists), nên thêm bảng mới vào đây là an toàn kể cả khi chưa có trên DB.
     */
    private const ACCOUNT_CHILDREN = [
        'accounts_finance', 'accounts_seller', 'accounts_links', 'accounts_proxy',
        'accounts_files', 'accounts_authors', 'accounts_relationships',
    ];

    /** Bảng con của SẢN PHẨM — xóa theo post_id. */
    private const POST_CHILDREN = [
        'accounts_relationships', 'download_relationships', 'amazon_listings',
    ];

    /** Bảng con của ĐƠN HÀNG — xóa theo order_id. */
    private const ORDER_CHILDREN = ['order_relationships'];

    /**
     * Bảng con của USER — xóa theo author_id.
     * `salary` KHÔNG nằm ở đây: là dữ liệu kế toán nên được giữ lại, chỉ chụp tên người
     * vào `username_snapshot` trước khi xóa (cột người của nó cũng tên `authors`, không
     * phải `author_id`, nên trước đây đứng trong danh sách này mà chẳng bao giờ chạy).
     */
    private const AUTHOR_CHILDREN = [
        'author_remember_tokens', 'accounts_authors',
    ];

    /**
     * Bảng DÙNG CHUNG có cột người tạo: không xóa bản ghi, chỉ gỡ liên kết về 0
     * (bản ghi trở thành "chỉ admin sửa" theo đúng luật sở hữu sẵn có).
     */
    private const SHARED_CREATED_BY = ['site', 'type'];

    /** Bảng có tồn tại trong DB không. */
    private static function table_exists(mysqli $conn, string $table): bool
    {
        static $cache = [];
        return $cache[$table] ??= (bool)$conn->execute_query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        )->fetch_row();
    }

    /**
     * Cặp bảng.cột có tồn tại không — dùng trước MỌI câu xóa để tiến trình không văng
     * khi schema thiếu bảng phụ (đã dính vụ store_teams bị khai tử).
     */
    private static function col_exists(mysqli $conn, string $table, string $col): bool
    {
        static $cache = [];
        $k = "$table.$col";
        return $cache[$k] ??= (bool)$conn->execute_query(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $col]
        )->fetch_row();
    }

    /**
     * Dữ liệu cho DataTables của trang Teams (server-side).
     *
     * @return array{draw:int,recordsTotal:int,recordsFiltered:int,data:array}
     */
    public static function get_teams(): array
    {
        $params = get_datatable_params(array_keys(self::SORT_MAP), 'name');
        if (!is_admin()) {
            return ['draw' => $params['draw'], 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []];
        }

        $conn = db();

        $whereClauses = [];
        if ($params['searchValue'] !== '') {
            $esc = $conn->real_escape_string($params['searchValue']);
            $whereClauses[] = "t.name LIKE '%$esc%'";
        }
        $where = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

        $totalRecords  = (int)$conn->query('SELECT COUNT(*) FROM team t')->fetch_row()[0];
        $totalFiltered = (int)$conn->query("SELECT COUNT(*) FROM team t $where")->fetch_row()[0];

        $sql = "SELECT t.ID, t.name, t.`key`, t.status,
                       (SELECT COUNT(*) FROM authors a WHERE a.team_id = t.ID) AS members
                FROM team t
                $where
                ORDER BY " . build_order_by($params, self::SORT_MAP, 't.ID') . "
                LIMIT {$params['start']}, {$params['length']}";
        $rs = $conn->query($sql);

        $data = [];
        while ($row = $rs->fetch_assoc()) {
            $data[] = [
                'id'         => (int)$row['ID'],
                'name'       => $row['name'],
                // Key là credential extension — chỉ trả ở đây vì trang admin-only,
                // hiển thị qua nút View Key chứ không in thẳng ra bảng
                'key'        => $row['key'],
                'status'     => (int)$row['status'],
                'members'    => (int)$row['members'],
                // Trang admin-only nên quyền mọi dòng như nhau — vẫn trả cờ theo
                // từng dòng đúng quy ước chung để JS dựng nút từ cờ.
                'can_edit'   => true,
                'can_delete' => true,
            ];
        }

        return [
            'draw'            => $params['draw'],
            'recordsTotal'    => $totalRecords,
            'recordsFiltered' => $totalFiltered,
            'data'            => $data,
        ];
    }

    /** Đọc team_id từ request, kèm kiểm quyền + CSRF. Trả lỗi hoặc id hợp lệ. */
    private static function purge_input(): array
    {
        if (!check_csrf()) {
            return ['error' => 'Invalid CSRF token.'];
        }
        if (!is_admin()) {
            return ['error' => 'Only an admin can delete teams.'];
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            return ['error' => 'Missing team.'];
        }
        if ($id === (int)($_SESSION['auth']['team'] ?? 0)) {
            // Tự xóa team của chính mình sẽ xóa luôn tài khoản đang đăng nhập
            return ['error' => 'You cannot delete the team you belong to.'];
        }
        $row = db()->execute_query('SELECT ID, status FROM team WHERE ID = ? LIMIT 1', [$id])->fetch_assoc();
        if (!$row) {
            return ['error' => 'Team not found.'];
        }
        return ['id' => $id, 'active' => (int)$row['status'] === 1];
    }

    /**
     * Rào chắn hai bước: team phải được tắt (Inactive) trước khi xóa hoặc sáp nhập.
     * Tắt team đã đá mọi thành viên ra và chặn API của họ, nên admin có cơ hội quan sát
     * xem có gì vỡ không TRƯỚC khi thao tác không thể hoàn tác.
     */
    private static function require_inactive(array $in): ?array
    {
        if (!empty($in['active'])) {
            return ['status' => 'error',
                'message' => 'Deactivate this team first, then delete it. That way its members lose access '
                    . 'before anything is removed.'];
        }
        return null;
    }

    /**
     * Đếm trước những gì sẽ bị xóa — dùng để hiện bảng xác nhận và tính tổng cho thanh
     * tiến trình. Chỉ ĐỌC, không đụng dữ liệu.
     *
     * @return array{status:string,counts?:array,total?:int,name?:string,message?:string}
     */
    public static function get_purge_preview(): array
    {
        $in = self::purge_input();
        if (isset($in['error'])) {
            return ['status' => 'error', 'message' => $in['error']];
        }
        $id = $in['id'];
        $conn = db();

        $name = (string)$conn->execute_query('SELECT name FROM team WHERE ID = ?', [$id])->fetch_row()[0];
        $one = fn(string $sql) => (int)$conn->execute_query($sql, [$id])->fetch_row()[0];

        $counts = [
            'members'  => $one('SELECT COUNT(*) FROM authors WHERE team_id = ?'),
            'accounts' => $one('SELECT COUNT(*) FROM accounts WHERE team_id = ?'),
            'products' => $one('SELECT COUNT(*) FROM posts p INNER JOIN authors a ON a.ID = p.author_id WHERE a.team_id = ?'),
            'orders'   => $one('SELECT COUNT(*) FROM orders o INNER JOIN accounts ac ON ac.ID = o.account_id WHERE ac.team_id = ?'),
            'stores'   => $one('SELECT COUNT(*) FROM store WHERE team_id = ?'),
            // Tệp đính kèm của account (ảnh/tài liệu) — logo site KHÔNG nằm trong nhóm này
            'files'    => self::col_exists($conn, 'accounts_files', 'file_id')
                ? $one('SELECT COUNT(DISTINCT af.file_id) FROM accounts_files af
                        INNER JOIN accounts ac ON ac.ID = af.account_id WHERE ac.team_id = ?')
                : 0,
            // Cấu hình riêng của team: khóa API (options) và số điện thoại SMS (phones)
            'settings' => self::col_exists($conn, 'options', 'team_id')
                ? $one('SELECT COUNT(*) FROM options WHERE team_id = ?') : 0,
            'phones'   => self::col_exists($conn, 'phones', 'team_id')
                ? $one('SELECT COUNT(*) FROM phones WHERE team_id = ?') : 0,
        ];

        // Team khác để sáp nhập sang (thay vì xóa sạch)
        $targets = [];
        $rs = $conn->execute_query('SELECT ID, name FROM team WHERE ID <> ? ORDER BY name', [$id]);
        while ($t = $rs->fetch_assoc()) {
            $targets[] = ['id' => (int)$t['ID'], 'name' => $t['name']];
        }

        return [
            'status'  => 'success',
            'name'    => $name,
            'counts'  => $counts,
            'total'   => array_sum($counts),
            // Team còn Active thì chưa cho xóa/sáp nhập — UI dựa vào cờ này để khóa nút
            'active'  => !empty($in['active']),
            'targets' => $targets,
        ];
    }

    /**
     * SÁP NHẬP team: chuyển toàn bộ thành viên, account và store riêng sang team khác rồi
     * xóa team rỗng. Dùng khi tổ chức lại nhân sự — KHÔNG mất dữ liệu nào.
     *
     * Sản phẩm không phải đụng tới: phạm vi tính theo `authors.team_id` nên chúng đi theo
     * chủ sở hữu. Đơn hàng cũng vậy vì bám theo `accounts.team_id`. Thành viên bị đổi team
     * nên phiên đang mở của họ tự bị đá ra (xem access_block_reason).
     *
     * @return array{status:string,moved?:array,message?:string}
     */
    public static function merge_team(): array
    {
        $in = self::purge_input();
        if (isset($in['error'])) {
            return ['status' => 'error', 'message' => $in['error']];
        }
        if ($blocked = self::require_inactive($in)) {
            return $blocked;
        }
        $id = $in['id'];
        $to = (int)($_POST['target_team'] ?? 0);
        if ($to <= 0 || $to === $id) {
            return ['status' => 'error', 'message' => 'Choose a different team to merge into.'];
        }

        $conn = db();
        if (!$conn->execute_query('SELECT ID FROM team WHERE ID = ? LIMIT 1', [$to])->fetch_row()) {
            return ['status' => 'error', 'message' => 'The target team does not exist.'];
        }

        try {
            $moved = [];
            foreach (['authors' => 'members', 'accounts' => 'accounts', 'store' => 'stores',
                      'phones' => 'phones'] as $table => $label) {
                if (!self::col_exists($conn, $table, 'team_id')) {
                    continue;
                }
                $conn->execute_query("UPDATE `$table` SET team_id = ? WHERE team_id = ?", [$to, $id]);
                $moved[$label] = $conn->affected_rows;
            }

            // options = khóa API theo team. Team NHẬN đang hoạt động nên cấu hình của họ là
            // chính: khóa trùng tên của team bị sáp nhập bị bỏ, phần còn lại mới chuyển sang.
            if (self::col_exists($conn, 'options', 'team_id')) {
                $conn->execute_query(
                    'DELETE FROM options WHERE team_id = ?
                     AND name IN (SELECT name FROM (SELECT name FROM options WHERE team_id = ?) x)',
                    [$id, $to]);
                $conn->execute_query('UPDATE options SET team_id = ? WHERE team_id = ?', [$to, $id]);
                $moved['settings'] = $conn->affected_rows;
            }
            // Thành viên đã sang team mới -> thu hồi token để họ đăng nhập lại đúng team
            $conn->execute_query(
                'DELETE FROM author_remember_tokens WHERE author_id IN (SELECT ID FROM authors WHERE team_id = ?)',
                [$to]);
            $conn->execute_query('DELETE FROM team WHERE ID = ?', [$id]);
        } catch (\mysqli_sql_exception $e) {
            return ['status' => 'error', 'message' => 'Merge failed: ' . $e->getMessage()];
        }

        return ['status' => 'success', 'moved' => $moved];
    }

    /**
     * Chạy MỘT chặng của tiến trình xóa dây chuyền (tối đa PURGE_BUDGET dòng).
     *
     * Client gọi lặp lại tới khi nhận `finished` = true. Thứ tự cố định con -> cha để
     * đứt giữa chừng vẫn gọi lại được: sản phẩm -> đơn hàng -> tệp của account ->
     * bảng con account -> account -> bảng con user -> user -> store riêng ->
     * gỡ created_by -> xóa team.
     *
     * @return array{status:string,finished?:bool,stage?:string,deleted?:int,message?:string}
     */
    public static function purge_team(): array
    {
        $in = self::purge_input();
        if (isset($in['error'])) {
            return ['status' => 'error', 'message' => $in['error']];
        }
        if ($blocked = self::require_inactive($in)) {
            return $blocked;
        }
        $id = $in['id'];
        $conn = db();
        $budget = self::PURGE_BUDGET;
        $deleted = 0;

        try {
            // --- 1. Sản phẩm của các user trong team (kèm bảng con của sản phẩm) ---
            while ($budget > 0) {
                $ids = [];
                $rs = $conn->execute_query(
                    'SELECT p.ID FROM posts p INNER JOIN authors a ON a.ID = p.author_id
                     WHERE a.team_id = ? LIMIT ' . self::PURGE_CHUNK, [$id]);
                while ($row = $rs->fetch_row()) {
                    $ids[] = (int)$row[0];
                }
                if (!$ids) {
                    break;
                }
                $idsStr = implode(',', $ids);
                foreach (self::POST_CHILDREN as $child) {
                    if (self::col_exists($conn, $child, 'post_id')) {
                        $conn->query("DELETE FROM `$child` WHERE post_id IN ($idsStr)");
                    }
                }
                $conn->query("DELETE FROM posts WHERE ID IN ($idsStr)");
                $n = $conn->affected_rows;
                $deleted += $n;
                $budget -= max($n, 1);
            }
            if ($budget <= 0) {
                return ['status' => 'partial', 'stage' => 'Products', 'deleted' => $deleted];
            }

            // --- 2. Đơn hàng của các account trong team ---
            // MariaDB KHÔNG cho LIMIT trong DELETE nhiều bảng -> lấy ID theo lô rồi xóa theo ID
            while ($budget > 0) {
                $ids = [];
                $rs = $conn->execute_query(
                    'SELECT o.ID FROM orders o INNER JOIN accounts ac ON ac.ID = o.account_id
                     WHERE ac.team_id = ? LIMIT ' . self::PURGE_CHUNK, [$id]);
                while ($row = $rs->fetch_row()) {
                    $ids[] = (int)$row[0];
                }
                if (!$ids) {
                    break;
                }
                $idsStr = implode(',', $ids);
                foreach (self::ORDER_CHILDREN as $child) {
                    if (self::col_exists($conn, $child, 'order_id')) {
                        $conn->query("DELETE FROM `$child` WHERE order_id IN ($idsStr)");
                    }
                }
                $conn->query("DELETE FROM orders WHERE ID IN ($idsStr)");
                $n = $conn->affected_rows;
                $deleted += $n;
                $budget -= max($n, 1);
            }
            if ($budget <= 0) {
                return ['status' => 'partial', 'stage' => 'Orders', 'deleted' => $deleted];
            }

            // --- 3. Tệp riêng của account (bản ghi `files` + file vật lý trên đĩa) ---
            // LƯU Ý: bảng `files` dùng chung cho cả logo site (type = 'sites'), nên CHỈ
            // được xóa file có liên kết trong accounts_files tới account của team này.
            // Gỡ liên kết tới đâu xóa file tới đó -> đứt giữa chừng gọi lại vẫn tìm được
            // phần còn sót (nếu xóa hết liên kết trước thì mất dấu, thành file mồ côi).
            if (self::col_exists($conn, 'accounts_files', 'file_id')) {
                while ($budget > 0) {
                    $fileIds = [];
                    $rs = $conn->execute_query(
                        'SELECT DISTINCT af.file_id FROM accounts_files af
                         INNER JOIN accounts ac ON ac.ID = af.account_id
                         WHERE ac.team_id = ? LIMIT ' . self::PURGE_CHUNK, [$id]);
                    while ($row = $rs->fetch_row()) {
                        $fileIds[] = (int)$row[0];
                    }
                    if (!$fileIds) {
                        break;
                    }
                    foreach ($fileIds as $fid) {
                        // Gỡ liên kết của riêng team này trước
                        $conn->execute_query(
                            'DELETE af FROM accounts_files af
                             INNER JOIN accounts ac ON ac.ID = af.account_id
                             WHERE af.file_id = ? AND ac.team_id = ?', [$fid, $id]);
                        // Còn account khác dùng file này thì giữ lại bản ghi files
                        $stillUsed = $conn->execute_query(
                            'SELECT 1 FROM accounts_files WHERE file_id = ? LIMIT 1', [$fid])->fetch_row();
                        if ($stillUsed) {
                            continue;
                        }
                        // Dùng lại helper sẵn có: xóa bản ghi + file trên đĩa
                        if (function_exists('deletePhysicalFile')) {
                            deletePhysicalFile($conn, $fid);
                        } else {
                            $conn->execute_query('DELETE FROM files WHERE ID = ?', [$fid]);
                        }
                        $deleted++;
                        $budget--;
                    }
                }
                if ($budget <= 0) {
                    return ['status' => 'partial', 'stage' => 'Files', 'deleted' => $deleted];
                }
            }

            // --- 4. Bảng con của account, rồi account ---
            foreach (self::ACCOUNT_CHILDREN as $child) {
                if (!self::col_exists($conn, $child, 'account_id')) {
                    continue;
                }
                $conn->execute_query(
                    "DELETE c FROM `$child` c INNER JOIN accounts ac ON ac.ID = c.account_id
                     WHERE ac.team_id = ?", [$id]);
                $deleted += $conn->affected_rows;
            }
            $conn->execute_query('DELETE FROM accounts WHERE team_id = ?', [$id]);
            $deleted += $conn->affected_rows;

            // --- 5. Bảng con của user, rồi user ---
            foreach (self::AUTHOR_CHILDREN as $child) {
                if (!self::col_exists($conn, $child, 'author_id')) {
                    continue;
                }
                $conn->execute_query(
                    "DELETE c FROM `$child` c INNER JOIN authors a ON a.ID = c.author_id
                     WHERE a.team_id = ?", [$id]);
                $deleted += $conn->affected_rows;
            }
            // Bảng lương được GIỮ (dữ liệu kế toán, giống luật xóa từng user) nhưng cột
            // `authors` sắp trỏ vào khoảng không -> chụp tên lại trước khi xóa người.
            if (self::col_exists($conn, 'salary', 'username_snapshot')) {
                $conn->execute_query(
                    'UPDATE salary s INNER JOIN authors a ON a.ID = s.authors
                     SET s.username_snapshot = a.username
                     WHERE a.team_id = ? AND (s.username_snapshot IS NULL OR s.username_snapshot = \'\')',
                    [$id]);
            }
            $conn->execute_query('DELETE FROM authors WHERE team_id = ?', [$id]);
            $deleted += $conn->affected_rows;

            // --- 6. Store RIÊNG của team (store dùng chung team_id = 0 giữ nguyên) ---
            // Sản phẩm của team KHÁC còn trỏ tới store này -> chuyển Inactive + gỡ liên kết,
            // giống luật đã chốt ở trang Stores, thay vì để trỏ vào bản ghi không còn tồn tại.
            $storeIds = [];
            $rs = $conn->execute_query('SELECT ID FROM store WHERE team_id = ?', [$id]);
            while ($row = $rs->fetch_row()) {
                $storeIds[] = (int)$row[0];
            }
            if ($storeIds) {
                $storeStr = implode(',', $storeIds);
                do {
                    $conn->query("UPDATE posts SET status = 'inactive', store_id = 0
                                  WHERE store_id IN ($storeStr) LIMIT " . self::PURGE_CHUNK);
                    $n = $conn->affected_rows;
                    $budget -= max($n, 1);
                } while ($n === self::PURGE_CHUNK && $budget > 0);
                if ($budget <= 0) {
                    return ['status' => 'partial', 'stage' => 'Stores', 'deleted' => $deleted];
                }
                $conn->query("DELETE FROM store WHERE ID IN ($storeStr)");
                $deleted += $conn->affected_rows;
            }

            // --- 6b. Cấu hình riêng của team: khóa API (options) và số điện thoại (phones).
            // options chứa openai_key/gemini_key của team -> xóa hẳn, không để secret nằm
            // lại trong DB trỏ vào team không còn tồn tại.
            foreach (['options', 'phones'] as $table) {
                if (self::col_exists($conn, $table, 'team_id')) {
                    $conn->execute_query("DELETE FROM `$table` WHERE team_id = ?", [$id]);
                    $deleted += $conn->affected_rows;
                }
            }

            // --- 7. Dữ liệu DÙNG CHUNG: giữ bản ghi, chỉ gỡ người tạo về 0 ---
            // (bản ghi trở thành "chỉ admin sửa" — không xóa vì team khác đang dùng)
            foreach (self::SHARED_CREATED_BY as $table) {
                if (self::table_exists($conn, $table) && self::col_exists($conn, $table, 'created_by')) {
                    $conn->query("UPDATE `$table` SET created_by = 0
                                  WHERE created_by NOT IN (SELECT ID FROM authors)");
                }
            }

            // --- 8. Xóa team ở bước cuối: đứt giữa chừng thì gọi lại vẫn dọn tiếp được ---
            $conn->execute_query('DELETE FROM team WHERE ID = ?', [$id]);
            $deleted += $conn->affected_rows;
        } catch (\mysqli_sql_exception $e) {
            return ['status' => 'error', 'message' => 'Purge failed: ' . $e->getMessage()];
        }

        return ['status' => 'success', 'finished' => true, 'stage' => 'Done', 'deleted' => $deleted];
    }
}
