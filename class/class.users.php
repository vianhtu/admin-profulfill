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
    /**
     * Thứ bậc CẤP: Admin > Manager > User > Customer (chốt 06/08/2026).
     * Người cấp thấp không thấy người cấp cao hơn — chỉ ngang hàng hoặc thấp hơn.
     * Thứ hạng đi theo LEVEL gán cho role, không theo tên role.
     */

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
    /**
     * Phạm vi dữ liệu của trang Users. Ba trục cộng dồn, phải thoả CẢ BA:
     *   ROLE (checkRoles) × PHẠM VI (team/dòng của mình) × CẤP (không nhìn lên trên).
     *
     * Trục CẤP (chốt 06/08/2026): Admin > Manager > User > Customer — người cấp thấp KHÔNG
     * thấy người cấp cao hơn. Danh sách lộ ai là admin là lộ luôn mục tiêu tấn công.
     * Lọc ngay trong SQL, KHÔNG lọc sau khi lấy về: lọc ở PHP làm sai số đếm phân trang.
     */
    /**
     * Public vì trang Account (?menu=account&id=N) phải dùng ĐÚNG điều kiện này để quyết
     * ai được xem hồ sơ ai — hai nơi tự viết luật riêng là kiểu gì cũng lệch.
     */
    public static function scope_where(): string
    {
        if (is_admin()) {
            return '';
        }
        // Chỉ thấy người CÙNG TEAM và có thứ hạng KHÔNG CAO HƠN mình
        $cond = 'authors.team_id = ' . self::own_team();
        $ids = self::levels_above(db());
        if ($ids) {
            $cond .= ' AND authors.level NOT IN (' . implode(',', $ids) . ')';
        }
        return $cond;
    }

    /**
     * ID các role có thứ hạng CAO HƠN người đang đăng nhập.
     *
     * Thứ hạng lấy theo LEVEL được gán cho role (`roles_permissions.slug`), không theo tên
     * role — nên role tự đặt tên gì cũng xếp đúng bậc của level nó mang.
     */
    private static function levels_above(mysqli $conn): array
    {
        return levels_above_ids($conn);   // bản dùng chung ở config.php
    }

    /**
     * ID các role có cấp THẤP HƠN mình — điều kiện để được tạo/sửa/xóa một user
     * (chốt 06/08/2026, cùng luật với menu Roles): không đụng người NGANG CẤP.
     * Admin quản manager/user/customer; manager quản user/customer.
     */
    public static function manageable_level_ids(mysqli $conn): array
    {
        $myRank = own_level_rank();
        $sieu   = is_super_admin();   // super admin đụng được cả admin khác
        $ids = [];
        $rs = $conn->query('SELECT ID, slug FROM roles_permissions');
        while ($r = $rs->fetch_assoc()) {
            if ($sieu || (LEVEL_RANK[$r['slug']] ?? 9) < $myRank) {
                $ids[] = (int)$r['ID'];
            }
        }
        return $ids;
    }

    /**
     * Được thêm user mới không.
     * KHÔNG chốt theo cấp cụ thể: hễ có role `add` và còn cấp nào THẤP HƠN mình để gán
     * thì thêm được. Nhờ vậy cấp `user` cũng quản được `customer` (cấp thấp nhất) mà
     * không phải nới luật riêng — hạng nào cũng chỉ với xuống được, không với ngang.
     */
    public static function can_add(): bool
    {
        if (!is_admin() && !checkRoles('add', 'users')) {
            return false;
        }
        return self::manageable_level_ids(db()) !== [];
    }

    /**
     * Được sửa 1 user cụ thể không.
     * - admin: mọi user;
     * - chính mình: luôn được, không cần role (chỉ sửa được thông tin cá nhân);
     * - non-admin có role `edit`: user CÙNG TEAM;
     * - mọi vai: chỉ cấp THẤP HƠN mình, không đụng người ngang cấp (super admin ngoại lệ).
     * Không chốt theo cấp: `user` có role vẫn sửa được `customer` trong team mình.
     */
    public static function can_edit_row(int $userId, int $teamId, int $level, mysqli $conn): bool
    {
        // Sửa CHÍNH MÌNH: luôn được, mọi cấp, và KHÔNG cần role `edit` — đổi mật khẩu hay
        // email của bản thân không phải là "quản lý người dùng" (chốt 06/08/2026). Trước đó
        // luật ngang cấp vô tình chặn luôn chính mình, mà app không có trang profile nào ->
        // không ai đổi nổi mật khẩu của mình. Các trường nguy hiểm (role/team/status/lương)
        // bị khóa riêng trong save_user(), không dựa vào chốt này.
        if ($userId > 0 && $userId === self::own_id()) {
            return true;
        }
        // Chỉ sửa được người có cấp THẤP HƠN mình — người ngang cấp cũng không đụng được
        if (!in_array($level, self::manageable_level_ids($conn), true)) {
            return false;
        }
        if (is_admin()) {
            return true;
        }
        if (!checkRoles('edit', 'users')) {
            return false;
        }
        return $teamId === self::own_team();
    }

    /**
     * Được xóa 1 user cụ thể không.
     * - admin: mọi user;
     * - non-admin có role `delete`: user CÙNG TEAM, cấp thấp hơn mình;
     * Không ai tự xóa chính mình.
     */
    public static function can_delete_row(int $userId, int $teamId, int $level, mysqli $conn): bool
    {
        if ($userId === self::own_id()) {
            return false;
        }
        if (!in_array($level, self::manageable_level_ids($conn), true)) {
            return false;   // ngang cấp hoặc cao hơn -> không đụng
        }
        if (is_admin()) {
            return true;
        }
        if (!checkRoles('delete', 'users')) {
            return false;
        }
        return $teamId === self::own_team();
    }

    /**
     * Dòng này có phải tài khoản cấp `customer` không.
     * Customer là cấp THẤP NHẤT và chỉ là khách, không phải người làm việc trong hệ thống,
     * nên xóa thì xóa thẳng — không chặn vì còn liên kết, không bàn giao, không dọn tham
     * chiếu (chốt 06/08/2026). Đây là NGOẠI LỆ có chủ ý của luật "quét hết liên kết trước
     * khi xóa": các bảng trỏ vào họ được phép mồ côi.
     */
    private static function la_customer(int $level, mysqli $conn): bool
    {
        $slug = $conn->execute_query(
            'SELECT slug FROM roles_permissions WHERE ID = ? LIMIT 1', [$level])->fetch_row();
        return $slug !== null && (string)$slug[0] === 'customer';
    }

    /** Còn ai xóa được không — dùng để quyết định có render modal xóa hay không. */
    public static function can_delete_any(): bool
    {
        if (!is_admin() && !checkRoles('delete', 'users')) {
            return false;
        }
        return self::manageable_level_ids(db()) !== [];
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
                'can_edit'   => self::can_edit_row((int)$row['ID'], $teamId, $level, $conn),
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
        // Bỏ MỌI cấp cao hơn mình, không riêng cấp admin: dòng của họ đã bị scope_where()
        // lọc khỏi bảng nên để lại trong ô lọc chỉ là lựa chọn luôn ra 0 kết quả, và còn
        // lộ đang tồn tại những cấp nào ở trên (luật NHÌN — chỉ thấy ngang hàng trở xuống).
        foreach (self::levels_above($conn) as $id) {
            unset($roles[$id]);
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
                // JS khóa Role/Team/Status khi mở form sửa CHÍNH MÌNH — save_user() từ chối
                // đổi 3 trường đó của bản thân, khóa trước cho khỏi bấm Save mới báo lỗi.
                'my_id'       => self::own_id(),
                // Role được phép GÁN: chỉ cấp thấp hơn mình. JS lọc select Role theo đây.
                'assignable_roles' => array_values(self::manageable_level_ids(db())),
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

        // Customer: xoa thang, khong hoi ban giao -> khong can danh sach nguoi nhan.
        $orphan = self::la_customer((int)$row['level'], $conn);

        // Nguoi nhan ban giao phai CUNG TEAM de du lieu o lai dung team da lam ra no.
        // Truc CAP: non-admin khong duoc nhin thay tai khoan admin nen cung khong duoc thay
        // ho trong danh sach nhan ban giao (xem scope_where).
        $hideAdmin = '';
        if (!is_admin()) {
            $above = self::levels_above($conn);
            if ($above) {
                $hideAdmin = ' AND level NOT IN (' . implode(',', $above) . ')';
            }
        }
        $candidates = [];
        if (!$orphan) {
            $rs = $conn->execute_query(
                "SELECT ID, username FROM authors WHERE team_id = ? AND ID <> ?$hideAdmin ORDER BY username",
                [(int)$row['team_id'], $id]);
            while ($c = $rs->fetch_assoc()) {
                $candidates[] = ['id' => (int)$c['ID'], 'username' => $c['username']];
            }
        }

        return [
            'status'     => 'success',
            'username'   => $row['username'],
            'products'   => (int)$conn->execute_query(
                'SELECT COUNT(*) FROM posts WHERE author_id = ?', [$id])->fetch_row()[0],
            'accounts'   => (int)$conn->execute_query(
                'SELECT COUNT(*) FROM accounts_authors WHERE author_id = ?', [$id])->fetch_row()[0],
            'candidates' => $candidates,
            // Customer: xoa thang, khong hoi ban giao. JS doc co nay de an o chon nguoi nhan
            // va hien canh bao du lieu se thanh mo coi.
            'orphan'     => $orphan,
            // Thong ke DAY DU moi bang tro toi nguoi nay + so phan cua tung bang
            'stats'      => self::delete_stats($id, $conn, $orphan),
        ];
    }

    /**
     * Thong ke MOI bang tro toi user sap bi xoa, kem SO PHAN cua tung bang.
     *
     * De nguoi bam Delete thay het thu sap mat, thay vi chi 2 con so nhu truoc. Danh sach
     * nay lay tu lan quet information_schema 06/08/2026 (moi cot ten %author%/%user%/
     * created_by/%_by) va PHAI khop voi cleanup_user_refs() + delete_users(). Them bang moi
     * co cot tro toi authors thi khai ca o day, o cleanup_user_refs() va o tests/schema-links.php
     * — thieu mot noi la nguoi dung khong thay duoc thu ho sap mat.
     *
     * fate: 'transfer' = BAT BUOC ban giao cho nguoi cung team (khong co duong xoa theo)
     *       'removed' = xoa theo
     *       'unlink'  = giu ban ghi, go lien ket ve 0
     *       'kept'    = giu nguyen (lich su cong viec / ke toan)
     *       'orphan'  = bo lai, khong don (chi voi cap customer)
     *
     * @return list<array{key:string,label:string,n:int,fate:string}>
     */
    private static function delete_stats(int $id, mysqli $conn, bool $orphan): array
    {
        $dem = static function (string $sql) use ($conn, $id): int {
            try {
                return (int)$conn->execute_query($sql, [$id])->fetch_row()[0];
            } catch (\mysqli_sql_exception) {
                return 0;   // bang chua ton tai tren moi truong nay -> coi nhu 0
            }
        };

        // File cua nguoi nay: cai con duoc dung o cho khac thi GIU, chi go nguoi upload
        $fileDung = $dem('SELECT COUNT(*) FROM files f WHERE f.user_id = ?
            AND (EXISTS (SELECT 1 FROM accounts_files af WHERE af.file_id = f.ID)
                 OR EXISTS (SELECT 1 FROM site s WHERE s.logo = f.storage_path))');
        $fileRieng = $dem('SELECT COUNT(*) FROM files WHERE user_id = ?') - $fileDung;

        $rows = [
            ['posts', 'Products they own',
                $dem('SELECT COUNT(*) FROM posts WHERE author_id = ?'), 'transfer'],
            ['accounts_authors', 'Account assignments',
                $dem('SELECT COUNT(*) FROM accounts_authors WHERE author_id = ?'), 'transfer'],
            ['author_remember_tokens', 'Remembered devices',
                $dem('SELECT COUNT(*) FROM author_remember_tokens WHERE author_id = ?'), 'removed'],
            ['files_own', 'Files only they use',
                max($fileRieng, 0), 'removed'],
            ['options', 'Personal API settings',
                $dem('SELECT COUNT(*) FROM options WHERE authors_id = ?'), 'removed'],
            ['files_shared', 'Files still used elsewhere',
                $fileDung, 'unlink'],
            ['site', 'Sites they added',
                $dem('SELECT COUNT(*) FROM site WHERE created_by = ?'), 'unlink'],
            ['type', 'Categories they added',
                $dem('SELECT COUNT(*) FROM type WHERE created_by = ?'), 'unlink'],
            ['salary', 'Salary records',
                $dem('SELECT COUNT(*) FROM salary WHERE authors = ?'), 'kept'],
            ['exports', 'Export jobs',
                $dem('SELECT COUNT(*) FROM exports WHERE authors_id = ?'), 'kept'],
            ['download', 'Downloads',
                $dem('SELECT COUNT(*) FROM download WHERE author_id = ?'), 'kept'],
        ];

        $out = [];
        foreach ($rows as [$key, $label, $n, $fate]) {
            if ($n === 0 && $key !== 'posts') {
                continue;   // khong co gi thi khong lam ron mat nguoi doc
            }
            // Cap customer: xoa thang, moi lien ket deu bo lai (tru phien dang nhap)
            if ($orphan && $key !== 'author_remember_tokens') {
                $fate = 'orphan';
            }
            $out[] = ['key' => $key, 'label' => $label, 'n' => $n, 'fate' => $fate];
        }
        return $out;
    }

    /**
     * Don cac tham chieu con lai tro toi user sap bi xoa, de khong de lai du lieu mo coi.
     *
     * Quy tac theo tung loai (chot 05/08/2026):
     *   - `files`  : CHI xoa file khong con ai dung. File dang gan account (accounts_files)
     *                hoac dang la logo cua site thi GIU, chi go nguoi upload ve 0 — xoa
     *                thang theo nguoi upload se lam mat 108 tep account va 20 logo site.
     *   - `site.created_by`, `type.created_by` : ve 0 (ban ghi thanh "chi admin sua"),
     *                dung luat "dung chung + nguoi tao" va khop voi xoa team.
     *   - `download`, `exports` : GIU — day la lich su cong viec, khong phai so huu.
     *   - `salary` : GIU — du lieu ke toan, khong xoa theo nguoi.
     *
     * @return array{files_deleted:int,files_kept:int}
     */
    private static function cleanup_user_refs(mysqli $conn, int $id): array
    {
        $deletedFiles = 0;
        if (self::has_table($conn, 'files')) {
            // File cua user va KHONG con ai dung -> xoa ca ban ghi lan file tren dia
            $rs = $conn->execute_query(
                'SELECT f.ID FROM files f
                 WHERE f.user_id = ?
                   AND NOT EXISTS (SELECT 1 FROM accounts_files af WHERE af.file_id = f.ID)
                   AND NOT EXISTS (SELECT 1 FROM site s WHERE s.logo = f.storage_path)',
                [$id]);
            $ids = [];
            while ($row = $rs->fetch_row()) {
                $ids[] = (int)$row[0];
            }
            foreach ($ids as $fid) {
                if (function_exists('deletePhysicalFile')) {
                    deletePhysicalFile($conn, $fid);
                } else {
                    $conn->execute_query('DELETE FROM files WHERE ID = ?', [$fid]);
                }
                $deletedFiles++;
            }
            // File con dang duoc dung -> giu lai, chi go nguoi upload
            $conn->execute_query('UPDATE files SET user_id = 0 WHERE user_id = ?', [$id]);
        }
        $keptFiles = $conn->affected_rows;

        // Du lieu dung chung: nguoi tao mat -> ve 0, ban ghi thanh chi admin sua duoc
        // Cau hinh RIENG cua nguoi nay (options.authors_id > 0). Hom nay moi dong options
        // deu co authors_id = 0 (cau hinh thuoc team) nen cau nay khong dung gi ca — nhung
        // cot van ton tai, va bang nay chua openai_key/gemini_key dang PLAINTEXT. Bo sot
        // dung kieu nay tung lam xoa team xong secret van nam lai trong DB (06/08/2026).
        // Xoa phong thu o day de khong bao gio phai phat hien lai bang cach do.
        $conn->execute_query('DELETE FROM options WHERE authors_id = ?', [$id]);

        foreach (['site', 'type'] as $table) {
            $conn->execute_query("UPDATE `$table` SET created_by = 0 WHERE created_by = ?", [$id]);
        }

        // Bang luong duoc GIU lai (du lieu ke toan) nhung `authors` se tro vao khoang khong
        // -> chup ten nguoi vao username_snapshot TRUOC khi xoa, de con tra cuu duoc sau nay.
        if (self::has_column($conn, 'salary', 'username_snapshot')) {
            $conn->execute_query(
                'UPDATE salary s
                 INNER JOIN authors a ON a.ID = s.authors
                 SET s.username_snapshot = a.username
                 WHERE s.authors = ? AND (s.username_snapshot IS NULL OR s.username_snapshot = \'\')',
                [$id]);
        }

        return ['files_deleted' => $deletedFiles, 'files_kept' => max($keptFiles, 0)];
    }

    /** Bang co ton tai khong (schema con vai bang phu da bi khai tu). */
    private static function has_table(mysqli $conn, string $table): bool
    {
        return (bool)$conn->execute_query(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1', [$table])->fetch_row();
    }

    /**
     * Cot co ton tai khong — deploy CODE truoc, doi DB sau (quy uoc cua du an) nen code
     * phai chay duoc voi ca schema cu lan moi.
     */
    private static function has_column(mysqli $conn, string $table, string $col): bool
    {
        static $cache = [];
        $k = "$table.$col";
        return $cache[$k] ??= (bool)$conn->execute_query(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $col])->fetch_row();
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

        // Customer la khach, khong phai nguoi lam viec: xoa thang, KHONG chan vi con lien
        // ket, KHONG hoi ban giao, KHONG don tham chieu — cac bang tro vao ho duoc phep mo
        // coi (chot 06/08/2026). Ngoai le CO CHU Y cua luat "quet het lien ket truoc khi xoa".
        $orphan = self::la_customer((int)$row['level'], $conn);

        $products = $orphan ? 0 : (int)$conn->execute_query(
            'SELECT COUNT(*) FROM posts WHERE author_id = ?', [$id])->fetch_row()[0];

        // Lien ket account: BAN GIAO BAT BUOC (chot 06/08/2026, doi tu 'xoa theo').
        // Mot account san khong con ai phu trach la mat quyen truy cap vao no, nen phai co
        // nguoi nhan that — KHONG co lua chon 'none' nhu san pham.
        $accounts = $orphan ? 0 : (int)$conn->execute_query(
            'SELECT COUNT(*) FROM accounts_authors WHERE author_id = ?', [$id])->fetch_row()[0];
        $nguoiNhan = 0;   // >0 khi da chon duoc nguoi nhan hop le

        // BAN GIAO BAT BUOC cho CA HAI (chot 06/08/2026 — truoc do san pham con co lua
        // chon 'None' de xoa luon). Xoa mot user gio khong bao gio lam mat du lieu: moi thu
        // cua ho sang nguoi khac. Muon don san pham rac thi nhan het roi xoa o menu Products.
        if ($products > 0 || $accounts > 0) {
            $raw = trim((string)($_POST['transfer_to'] ?? ''));
            if ($raw === '' || $raw === '0' || $raw === 'none') {
                $co = [];
                if ($products > 0) {
                    $co[] = number_format($products) . ' product(s)';
                }
                if ($accounts > 0) {
                    $co[] = number_format($accounts) . ' account assignment(s)';
                }
                return ['status' => 'error',
                    'message' => 'This user still has ' . implode(' and ', $co)
                        . '. Choose someone in the same team to take them over.'];
            }
        }

        $transferred = 0;
        $removed = 0;
        if ($products > 0) {
            $to = (int)trim((string)($_POST['transfer_to'] ?? ''));
            // Nguoi nhan da bat buoc co o chot tren, day chi con xac minh danh tinh
            if ($to > 0) {
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
                $nguoiNhan = $to;   // dung lai cho ca phan giao account ben duoi

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
            // Token dang nhap luon phai di theo: do la thong tin xac thuc, khong phai lien
            // ket du lieu — giu lai chi de rac va roi ro, ke ca voi customer.
            $conn->execute_query('DELETE FROM author_remember_tokens WHERE author_id = ?', [$id]);
            if (!$orphan) {
                if ($accounts > 0) {
                    // Nguoi nhan chua duoc xac minh o nhanh san pham (user khong co san pham
                    // nao) -> xac minh o day, van bat buoc CUNG TEAM.
                    if ($nguoiNhan === 0) {
                        $nguoiNhan = (int)trim((string)($_POST['transfer_to'] ?? ''));
                        if ($nguoiNhan === $id || $nguoiNhan <= 0) {
                            return ['status' => 'error',
                                'message' => 'Choose a different user to hand over to.'];
                        }
                        $ok = $conn->execute_query(
                            'SELECT ID FROM authors WHERE ID = ? AND team_id = ? LIMIT 1',
                            [$nguoiNhan, (int)$row['team_id']])->fetch_row();
                        if (!$ok) {
                            return ['status' => 'error',
                                'message' => 'The chosen user is not in the same team.'];
                        }
                    }
                    // PRIMARY KEY la (account_id, author_id): neu nguoi nhan DA phu trach
                    // dung account do thi UPDATE se dung khoa trung. Bo dong se trung truoc
                    // (nguoi nhan giu lai ban ghi cua ho), roi moi chuyen phan con lai.
                    $conn->execute_query(
                        'DELETE aa FROM accounts_authors aa
                         JOIN accounts_authors bb
                           ON bb.account_id = aa.account_id AND bb.author_id = ?
                         WHERE aa.author_id = ?', [$nguoiNhan, $id]);
                    $conn->execute_query(
                        'UPDATE accounts_authors SET author_id = ? WHERE author_id = ?',
                        [$nguoiNhan, $id]);
                }
                self::cleanup_user_refs($conn, $id);
            }
            $conn->execute_query('DELETE FROM authors WHERE ID = ?', [$id]);
            $deleted = $conn->affected_rows;
        } catch (\mysqli_sql_exception $e) {
            return ['status' => 'error', 'message' => 'Delete failed: ' . $e->getMessage()];
        }

        return ['status' => 'success', 'deleted' => $deleted,
            'transferred' => $transferred, 'removed' => $removed];
    }
}
