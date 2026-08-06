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
 * XÓA (chốt 05/08/2026): admin xóa mọi user; manager có role delete xóa người CÙNG TEAM
 * (trừ tài khoản admin); không ai tự xóa mình. Sản phẩm đứng tên user phải được xử lý dứt
 * khoát ngay trong luồng xóa — bàn giao cho người cùng team, HOẶC chọn None để xóa luôn
 * sản phẩm (chạy theo lô, có tiến trình). Liên kết account chỉ là phân công nên tự gỡ,
 * KHÔNG chặn xóa.
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

    /** So san pham chuyen giao moi cau UPDATE (giu transaction ngan). */
    private const TRANSFER_CHUNK = 1000;
    /** Tran so dong xu ly trong 1 request - vuot thi tra 'partial' de client goi tiep. */
    private const TRANSFER_BUDGET = 20000;

    /**
     * Dem truoc he qua khi xoa user + danh sach nguoi co the nhan ban giao. Chi DOC.
     *
     * @return array{status:string,username?:string,products?:int,accounts?:int,candidates?:array,message?:string}
     */
    public static function get_delete_preview(): array
    {
        if (!check_csrf()) {
            return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            return ['status' => 'error', 'message' => 'Missing user.'];
        }

        $conn = db();
        $row = $conn->execute_query(
            'SELECT ID, username, team_id, level FROM authors WHERE ID = ? LIMIT 1', [$id])->fetch_assoc();
        if (!$row) {
            return ['status' => 'error', 'message' => 'User not found.'];
        }
        if (!self::can_delete_row($id, (int)$row['team_id'], (int)$row['level'], $conn)) {
            return ['status' => 'error', 'message' => 'You do not have permission to delete this user.'];
        }

        // Nguoi nhan ban giao phai CUNG TEAM de du lieu o lai dung team da lam ra no
        $candidates = [];
        $rs = $conn->execute_query(
            'SELECT ID, username FROM authors WHERE team_id = ? AND ID <> ? ORDER BY username',
            [(int)$row['team_id'], $id]);
        while ($c = $rs->fetch_assoc()) {
            $candidates[] = ['id' => (int)$c['ID'], 'username' => $c['username']];
        }

        return [
            'status'     => 'success',
            'username'   => $row['username'],
            'products'   => (int)$conn->execute_query(
                'SELECT COUNT(*) FROM posts WHERE author_id = ?', [$id])->fetch_row()[0],
            'accounts'   => (int)$conn->execute_query(
                'SELECT COUNT(*) FROM accounts_authors WHERE author_id = ?', [$id])->fetch_row()[0],
            'candidates' => $candidates,
        ];
    }

    /**
     * Xoa MOT user. Admin xoa duoc moi user; manager co role delete xoa duoc nguoi CUNG
     * TEAM (tru tai khoan admin). Khong ai tu xoa minh.
     *
     * San pham dung ten user phai duoc xu ly ro rang qua POST transfer_to:
     *   - <id nguoi cung team> : ban giao lai cho ho;
     *   - 'none'              : XOA LUON san pham (kem bang con cua san pham).
     * Ca hai deu chay theo lo, het ngan sach thi tra 'partial' de client goi tiep.
     * Lien ket account chi la phan cong nguoi phu trach nen TU GO, khong chan xoa.
     *
     * @return array{status:string,deleted?:int,transferred?:int,left?:int,message?:string}
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
        if (count($ids) > 1) {
            return ['status' => 'error', 'message' => 'Users can only be deleted one at a time.'];
        }
        $id = $ids[0];
        if ($id === self::own_id()) {
            return ['status' => 'error', 'message' => 'You cannot delete your own account.'];
        }

        $conn = db();
        $row = $conn->execute_query(
            'SELECT ID, username, team_id, level FROM authors WHERE ID = ? LIMIT 1', [$id])->fetch_assoc();
        if (!$row) {
            return ['status' => 'error', 'message' => 'User not found.'];
        }
        // Kiem quyen tren chinh dong do - khong tin ID gui len
        if (!self::can_delete_row($id, (int)$row['team_id'], (int)$row['level'], $conn)) {
            return ['status' => 'error',
                'message' => 'This user is in another team or is an admin account. Only an admin can delete them.'];
        }

        $products = (int)$conn->execute_query(
            'SELECT COUNT(*) FROM posts WHERE author_id = ?', [$id])->fetch_row()[0];

        $transferred = 0;
        $removed = 0;
        if ($products > 0) {
            // 'none' = khong ban giao, xoa luon san pham. Phai doc chuoi TRUOC khi ep (int)
            // vi (int)'none' = 0 se lan sang nhanh "chua chon".
            $toRaw = trim((string)($_POST['transfer_to'] ?? ''));
            if ($toRaw === '' || $toRaw === '0') {
                return ['status' => 'error',
                    'message' => 'This user still owns ' . number_format($products)
                        . ' products. Choose someone to hand them over to, or choose None to delete them.'];
            }

            if ($toRaw === 'none') {
                // Xoa san pham cua rieng user nay, theo lo, kem bang con cua san pham
                try {
                    $budget = self::TRANSFER_BUDGET;
                    while ($budget > 0) {
                        $ids = [];
                        $rs = $conn->execute_query(
                            'SELECT ID FROM posts WHERE author_id = ? LIMIT ' . self::TRANSFER_CHUNK, [$id]);
                        while ($row2 = $rs->fetch_row()) {
                            $ids[] = (int)$row2[0];
                        }
                        if (!$ids) {
                            break;
                        }
                        $idsStr = implode(',', $ids);
                        foreach (['accounts_relationships', 'download_relationships', 'amazon_listings'] as $child) {
                            $conn->query("DELETE FROM `$child` WHERE post_id IN ($idsStr)");
                        }
                        $conn->query("DELETE FROM posts WHERE ID IN ($idsStr)");
                        $n = $conn->affected_rows;
                        $removed += $n;
                        $budget -= max($n, 1);
                    }
                } catch (\mysqli_sql_exception $e) {
                    return ['status' => 'error', 'message' => 'Removing products failed: ' . $e->getMessage()];
                }
            } else {
                $to = (int)$toRaw;
                if ($to === $id) {
                    return ['status' => 'error', 'message' => 'Choose a different user to hand over to.'];
                }
                // Nguoi nhan phai CUNG TEAM - neu khong, san pham nhay sang team khac va store
                // rieng dang gan se thanh khong hop le voi chu moi.
                $dest = $conn->execute_query(
                    'SELECT ID FROM authors WHERE ID = ? AND team_id = ? LIMIT 1',
                    [$to, (int)$row['team_id']])->fetch_row();
                if (!$dest) {
                    return ['status' => 'error', 'message' => 'The chosen user is not in the same team.'];
                }

                try {
                    $budget = self::TRANSFER_BUDGET;
                    while ($budget > 0) {
                        $conn->execute_query(
                            'UPDATE posts SET author_id = ? WHERE author_id = ? LIMIT ' . self::TRANSFER_CHUNK,
                            [$to, $id]);
                        $n = $conn->affected_rows;
                        $transferred += $n;
                        $budget -= max($n, 1);
                        if ($n < self::TRANSFER_CHUNK) {
                            break;
                        }
                    }
                } catch (\mysqli_sql_exception $e) {
                    return ['status' => 'error', 'message' => 'Hand-over failed: ' . $e->getMessage()];
                }
            }

            // Con san pham chua xu ly -> giu nguyen user, bao client goi tiep
            $left = (int)$conn->execute_query(
                'SELECT COUNT(*) FROM posts WHERE author_id = ?', [$id])->fetch_row()[0];
            if ($left > 0) {
                return ['status' => 'partial', 'transferred' => $transferred,
                    'removed' => $removed, 'left' => $left];
            }
        }

        try {
            // Lien ket account chi la phan cong -> go, khong chan xoa
            $conn->execute_query('DELETE FROM author_remember_tokens WHERE author_id = ?', [$id]);
            $conn->execute_query('DELETE FROM accounts_authors WHERE author_id = ?', [$id]);
            $conn->execute_query('DELETE FROM authors WHERE ID = ?', [$id]);
            $deleted = $conn->affected_rows;
        } catch (\mysqli_sql_exception $e) {
            return ['status' => 'error', 'message' => 'Delete failed: ' . $e->getMessage()];
        }

        return ['status' => 'success', 'deleted' => $deleted,
            'transferred' => $transferred, 'removed' => $removed];
    }
}
