<?php
/**
 * Users — nghiệp vụ tập hợp cho bảng `authors` (menu Users): danh sách, lọc, xóa.
 * Cùng khuôn với class.stores.php / class.teams.php.
 *
 * PHẠM VI DỮ LIỆU (chốt 05/08/2026):
 *   - admin   : mọi user, mọi team;
 *   - manager : chỉ user cùng team (`authors.team_id`);
 *   - user    : chỉ chính mình.
 *
 * LƯƠNG/BẢO HIỂM (`wage`, `insurance`) là dữ liệu nhạy cảm: chỉ admin và manager được
 * xem — endpoint KHÔNG trả field này cho level user (không chỉ ẩn ở giao diện).
 *
 * XÓA: chỉ admin, và CHẶN khi user còn sản phẩm/account đứng tên — phải chuyển giao
 * trước (cùng luật với Category/Site: xóa bản ghi cha có tham chiếu thì từ chối).
 */
class Users
{
    /**
     * Cột sắp xếp được: tên cột DataTables => biểu thức SQL.
     * Cột hiển thị TÊN (role/team) sắp xếp theo tên chứ không theo id.
     */
    private const SORT_MAP = [
        'username' => 'authors.username',
        'email'    => 'authors.email',
        'level'    => 'r.name',
        'team_id'  => 'tm.name',
        'wage'     => 'authors.wage',
        'status'   => 'authors.status',
        'date'     => 'authors.date',
        'ID'       => 'authors.ID',
    ];

    /** Trạng thái hợp lệ của user (theo quy ước sẵn có của trang). */
    public const STATUSES = [1 => 'Pending', 2 => 'Active', 3 => 'Inactive'];
    /** Chỉ user ở trạng thái này mới đăng nhập được. */
    public const STATUS_ACTIVE = 2;

    /**
     * Cột `avatar` đã có trên DB chưa. Quy ước triển khai của dự án là deploy CODE
     * trước, đổi DB sau — nên code phải chạy được với cả schema cũ lẫn mới.
     */
    public static function has_avatar_column(): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        try {
            $has = (bool)db()->query(
                "SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'authors'
                   AND COLUMN_NAME = 'avatar' LIMIT 1")->fetch_row();
        } catch (\mysqli_sql_exception) {
            $has = false;
        }
        return $has;
    }

    private static function own_team(): int
    {
        return (int)($_SESSION['auth']['team'] ?? 0);
    }

    private static function own_id(): int
    {
        return (int)($_SESSION['auth']['user_id'] ?? 0);
    }

    /** Lương/bảo hiểm chỉ admin và manager được nhìn. */
    public static function can_see_salary(): bool
    {
        return is_admin() || is_manager();
    }

    /**
     * Điều kiện SQL giới hạn các user được phép NHÌN THẤY.
     * Trả chuỗi rỗng khi admin (không giới hạn).
     */
    private static function scope_where(): string
    {
        if (is_admin()) {
            return '';
        }
        if (is_manager()) {
            return 'authors.team_id = ' . self::own_team();
        }
        return 'authors.ID = ' . self::own_id();
    }

    /** Danh sách ID của các nhóm quyền cấp admin — dùng chặn leo thang quyền. */
    public static function admin_level_ids(mysqli $conn): array
    {
        static $ids = null;
        if ($ids !== null) {
            return $ids;
        }
        $ids = [];
        $rs = $conn->query("SELECT ID FROM roles_permissions WHERE slug = 'admin'");
        while ($row = $rs->fetch_row()) {
            $ids[] = (int)$row[0];
        }
        return $ids;
    }

    /** Được thêm user mới không. */
    public static function can_add(): bool
    {
        return is_admin() || (is_manager() && checkRoles('add', 'users'));
    }

    /**
     * Được sửa 1 user cụ thể không.
     * - admin: mọi user;
     * - manager: user CÙNG TEAM và KHÔNG phải tài khoản admin (chặn leo thang quyền);
     * - user: không sửa được ai (kể cả chính mình — đổi role/team là leo quyền).
     */
    public static function can_edit_row(int $teamId, int $level, mysqli $conn): bool
    {
        if (is_admin()) {
            return true;
        }
        if (!is_manager() || !checkRoles('edit', 'users')) {
            return false;
        }
        return $teamId === self::own_team() && !in_array($level, self::admin_level_ids($conn), true);
    }

    /**
     * Được xóa 1 user cụ thể không.
     * - admin: mọi user;
     * - manager (có role delete): user CÙNG TEAM và KHÔNG phải tài khoản admin;
     * - user: không.
     * Không ai tự xóa chính mình.
     */
    public static function can_delete_row(int $userId, int $teamId, int $level, mysqli $conn): bool
    {
        if ($userId === self::own_id()) {
            return false;
        }
        if (is_admin()) {
            return true;
        }
        if (!is_manager() || !checkRoles('delete', 'users')) {
            return false;
        }
        return $teamId === self::own_team() && !in_array($level, self::admin_level_ids($conn), true);
    }

    /** Còn ai xóa được không — dùng để quyết định có render modal xóa hay không. */
    public static function can_delete_any(): bool
    {
        return is_admin() || (is_manager() && checkRoles('delete', 'users'));
    }

    /**
     * Dữ liệu cho DataTables của trang Users.
     *
     * @return array{draw:int,recordsTotal:int,recordsFiltered:int,data:array}
     */
    public static function get_users(): array
    {
        $params = get_datatable_params(array_keys(self::SORT_MAP), 'username');
        if (!checkRoles('view', 'users')) {
            return ['draw' => $params['draw'], 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []];
        }

        $conn = db();
        $scope = self::scope_where();
        $whereClauses = $scope !== '' ? [$scope] : [];

        if ($params['searchValue'] !== '') {
            $esc = $conn->real_escape_string($params['searchValue']);
            $whereClauses[] = "(authors.email LIKE '%$esc%' OR authors.username LIKE '%$esc%')";
        }

        // Lọc theo nhóm quyền
        $filterLevel = (int)($_POST['level'] ?? 0);
        if ($filterLevel > 0) {
            $whereClauses[] = 'authors.level = ' . $filterLevel;
        }
        // Lọc theo trạng thái
        $filterStatus = (int)($_POST['status'] ?? 0);
        if (isset(self::STATUSES[$filterStatus])) {
            $whereClauses[] = 'authors.status = ' . $filterStatus;
        }
        // Lọc theo team — chỉ admin (non-admin đã bị scope giới hạn nên bỏ qua tham số họ gửi)
        $filterTeam = (int)($_POST['team'] ?? 0);
        if ($filterTeam > 0 && is_admin()) {
            $whereClauses[] = 'authors.team_id = ' . $filterTeam;
        }

        $where = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';
        $scopeWhere = $scope !== '' ? " WHERE $scope" : '';

        $join = 'LEFT JOIN roles_permissions r ON r.ID = authors.level
                 LEFT JOIN team tm ON tm.ID = authors.team_id';

        // Tổng số tính theo phạm vi được phép xem, không phải toàn bảng
        $totalRecords  = (int)$conn->query("SELECT COUNT(*) FROM authors $scopeWhere")->fetch_row()[0];
        $totalFiltered = (int)$conn->query("SELECT COUNT(*) FROM authors $join $where")->fetch_row()[0];

        $avatarCol = self::has_avatar_column() ? 'authors.avatar,' : "'' AS avatar,";
        $sql = "SELECT authors.ID, authors.username, authors.email, authors.status, authors.date,
                       authors.team_id, authors.level, authors.wage, authors.insurance, $avatarCol
                       r.name AS role_name, tm.name AS team_name
                FROM authors
                $join
                $where
                ORDER BY " . build_order_by($params, self::SORT_MAP, 'authors.ID') . "
                LIMIT {$params['start']}, {$params['length']}";
        $rs = $conn->query($sql);

        $showSalary = self::can_see_salary();
        $data = [];
        while ($row = $rs->fetch_assoc()) {
            $teamId = (int)$row['team_id'];
            $level  = (int)$row['level'];
            $item = [
                'id'         => (int)$row['ID'],
                'username'   => $row['username'],
                'email'      => $row['email'],
                'status'     => (int)$row['status'],
                'date'       => $row['date'],
                'team_id'    => $teamId,
                'team_name'  => (string)($row['team_name'] ?? ''),
                'level'      => $level,
                'role_name'  => (string)($row['role_name'] ?? ''),
                'avatar'     => (string)($row['avatar'] ?? ''),
                'can_edit'   => self::can_edit_row($teamId, $level, $conn),
                'can_delete' => self::can_delete_row((int)$row['ID'], $teamId, $level, $conn),
            ];
            // Lương chỉ gửi cho người được phép — không dựa vào giao diện để giấu
            if ($showSalary) {
                $item['wage']      = formatCurrencyVND($row['wage']);
                $item['insurance'] = formatCurrencyVND($row['insurance']);
            }
            $data[] = $item;
        }

        return [
            'draw'            => $params['draw'],
            'recordsTotal'    => $totalRecords,
            'recordsFiltered' => $totalFiltered,
            'data'            => $data,
        ];
    }

    /**
     * Option bộ lọc + cờ quyền chung của trang Users.
     *
     * @return array{roles:array,teams:array,statuses:array,perms:array}
     */
    public static function get_users_filters(): array
    {
        if (!checkRoles('view', 'users')) {
            return ['status' => 'error', 'message' => 'You do not have permission to view users.'];
        }
        $conn = db();
        // Dùng thẳng bảng roles_permissions, KHÔNG dùng get_all_roles(): hàm đó chèn thêm
        // mục giả `0 => Admin` (di sản cũ) — chọn phải mục đó thì lưu luôn báo "Invalid role".
        $roles = get_data_map('roles_permissions', 'name');
        if (!is_admin()) {
            // Manager không được cấp quyền admin cho ai -> không đưa nhóm quyền admin vào select
            foreach (self::admin_level_ids($conn) as $adminId) {
                unset($roles[$adminId]);
            }
        }

        // Admin chọn được mọi team; người khác chỉ có đúng team của mình (select bị khóa,
        // nhưng vẫn cần option để hiển thị đúng tên thay vì ô trống).
        if (is_admin()) {
            $teams = get_data_map('team', 'name');
        } else {
            $own = self::own_team();
            $name = $own > 0 ? get_field_by_id('team', 'name', $own) : '';
            $teams = $own > 0 ? [$own => ['title' => (string)$name]] : [];
        }

        return [
            'roles'    => $roles,
            'teams'    => $teams,
            'statuses' => self::STATUSES,
            'perms'    => [
                'add'         => self::can_add(),
                'is_admin'    => is_admin(),
                'see_salary'  => self::can_see_salary(),
                'filter_team' => is_admin(),
                'own_team'    => self::own_team(),
            ],
        ];
    }

    /**
     * Đếm trước hệ quả khi CHUYỂN user sang team khác — dùng cho hộp xác nhận.
     * Chỉ ĐỌC. Chỉ admin (chỉ admin mới đổi được team).
     *
     * @return array{status:string,counts?:array,from?:string,to?:string,message?:string}
     */
    public static function get_move_preview(): array
    {
        if (!check_csrf()) {
            return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
        }
        if (!is_admin()) {
            return ['status' => 'error', 'message' => 'Only an admin can move users between teams.'];
        }
        $id = (int)($_POST['id'] ?? 0);
        $to = (int)($_POST['team_id'] ?? 0);
        if ($id <= 0) {
            return ['status' => 'error', 'message' => 'Missing user.'];
        }

        $conn = db();
        $row = $conn->execute_query(
            'SELECT username, team_id FROM authors WHERE ID = ? LIMIT 1', [$id])->fetch_assoc();
        if (!$row) {
            return ['status' => 'error', 'message' => 'User not found.'];
        }
        $from = (int)$row['team_id'];

        return [
            'status'   => 'success',
            'username' => $row['username'],
            'from'     => $from > 0 ? (string)get_field_by_id('team', 'name', $from) : '(no team)',
            'to'       => $to > 0 ? (string)get_field_by_id('team', 'name', $to) : '(no team)',
            'counts'   => [
                // Sản phẩm đi theo tác giả -> đổi luôn tầm nhìn của cả 2 team
                'products' => (int)$conn->execute_query(
                    'SELECT COUNT(*) FROM posts WHERE author_id = ?', [$id])->fetch_row()[0],
                // Liên kết tới account của team CŨ sẽ bị gỡ
                'accounts' => (int)$conn->execute_query(
                    'SELECT COUNT(*) FROM accounts_authors aa
                     INNER JOIN accounts ac ON ac.ID = aa.account_id
                     WHERE aa.author_id = ? AND ac.team_id = ?', [$id, $from])->fetch_row()[0],
                // Sản phẩm đang trỏ store RIÊNG của team cũ -> sẽ bị gỡ liên kết store
                'stores'   => (int)$conn->execute_query(
                    'SELECT COUNT(*) FROM posts p INNER JOIN store s ON s.ID = p.store_id
                     WHERE p.author_id = ? AND s.team_id <> 0 AND s.team_id = ?', [$id, $from])->fetch_row()[0],
            ],
        ];
    }

    /**
     * Dọn dẹp sau khi user được chuyển từ team $from sang team mới.
     *
     * Sản phẩm ĐI THEO người (phạm vi tính bằng authors.team_id) nên không phải chuyển gì,
     * nhưng những ràng buộc chỉ đúng với team CŨ thì phải gỡ:
     *   - liên kết tới account của team cũ (accounts_authors);
     *   - store RIÊNG của team cũ mà sản phẩm đang trỏ tới (nếu không, lần sửa sau sẽ
     *     báo "This store does not belong to the owner's team");
     *   - token nhớ đăng nhập, để user phải đăng nhập lại với team mới.
     *
     * @return array{accounts:int,stores:int}
     */
    public static function cleanup_after_move(mysqli $conn, int $userId, int $from): array
    {
        $conn->execute_query(
            'DELETE aa FROM accounts_authors aa
             INNER JOIN accounts ac ON ac.ID = aa.account_id
             WHERE aa.author_id = ? AND ac.team_id = ?', [$userId, $from]);
        $accounts = $conn->affected_rows;

        $conn->execute_query(
            'UPDATE posts p INNER JOIN store s ON s.ID = p.store_id
             SET p.store_id = 0
             WHERE p.author_id = ? AND s.team_id <> 0 AND s.team_id = ?', [$userId, $from]);
        $stores = $conn->affected_rows;

        $conn->execute_query('DELETE FROM author_remember_tokens WHERE author_id = ?', [$userId]);

        return ['accounts' => $accounts, 'stores' => $stores];
    }

    /**
     * Xóa user. Admin xóa được mọi user; manager có role delete xóa được người CÙNG TEAM
     * (trừ tài khoản admin). Chặn khi user còn sản phẩm hoặc account đứng tên.
     *
     * @return array{status:string,deleted?:int,message?:string}
     */
    public static function delete_users(): array
    {
        if (!check_csrf()) {
            return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
        }
        if (!self::can_delete_any()) {
            return ['status' => 'error', 'message' => 'You do not have permission to delete users.'];
        }

        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            return ['status' => 'error', 'message' => 'Missing user list.'];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        if (empty($ids)) {
            return ['status' => 'error', 'message' => 'Invalid user list.'];
        }
        if (in_array(self::own_id(), $ids, true)) {
            return ['status' => 'error', 'message' => 'You cannot delete your own account.'];
        }

        $conn = db();
        $idsStr = implode(',', $ids);

        // Kiểm quyền trên TỪNG dòng — không tin danh sách ID gửi lên
        $blocked = [];
        $denied  = 0;
        $rs = $conn->query("SELECT a.ID, a.username, a.team_id, a.level,
                (SELECT COUNT(*) FROM posts p WHERE p.author_id = a.ID) AS products,
                (SELECT COUNT(*) FROM accounts_authors aa WHERE aa.author_id = a.ID) AS accounts
             FROM authors a WHERE a.ID IN ($idsStr)");
        $found = 0;
        while ($row = $rs->fetch_assoc()) {
            $found++;
            if (!self::can_delete_row((int)$row['ID'], (int)$row['team_id'], (int)$row['level'], $conn)) {
                $denied++;
                continue;
            }
            $parts = [];
            if ((int)$row['products'] > 0) {
                $parts[] = $row['products'] . ' products';
            }
            if ((int)$row['accounts'] > 0) {
                $parts[] = $row['accounts'] . ' accounts';
            }
            if ($parts) {
                $blocked[] = $row['username'] . ' (' . implode(', ', $parts) . ')';
            }
        }
        if ($found === 0) {
            return ['status' => 'error', 'message' => 'User not found.'];
        }
        if ($denied > 0) {
            return ['status' => 'error',
                'message' => $denied . ' of the selected users are in another team or are admin accounts.'
                    . ' Only an admin can delete those.'];
        }
        if ($blocked) {
            return ['status' => 'error',
                'message' => 'Cannot delete: ' . implode('; ', $blocked)
                    . '. Reassign their data to another user first.'];
        }

        try {
            $conn->query("DELETE FROM author_remember_tokens WHERE author_id IN ($idsStr)");
            $conn->query("DELETE FROM accounts_authors WHERE author_id IN ($idsStr)");
            $conn->query("DELETE FROM authors WHERE ID IN ($idsStr)");
            $deleted = $conn->affected_rows;
        } catch (\mysqli_sql_exception $e) {
            return ['status' => 'error', 'message' => 'Delete failed: ' . $e->getMessage()];
        }

        return ['status' => 'success', 'deleted' => $deleted];
    }
}
