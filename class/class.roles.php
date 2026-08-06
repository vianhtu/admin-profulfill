<?php
/**
 * Roles — TẬP HỢP vai trò (bảng danh sách, lọc, xóa).
 *
 * ĐÂY LÀ BẢNG NGUY HIỂM NHẤT HỆ THỐNG: nó định nghĩa quyền của mọi người khác, và cột
 * `slug` chính là thứ `is_admin()`/`is_manager()` đọc — ai ghi được `slug = 'admin'` là
 * thành admin ngay. Mọi thay đổi ở đây phải giữ đúng 3 điều:
 *
 *   1. KHÔNG ai cấp được quyền mà bản thân không có (chống tự nâng quyền).
 *   2. KHÔNG ai sửa được role của CHÍNH MÌNH (trừ admin — admin vốn đã toàn quyền).
 *   3. Role `admin` không bao giờ bị xóa; chỉ admin mới sửa được nó.
 *
 * Quyền vào menu (chốt 06/08/2026): admin FULL; manager phải được cấp role mới vào;
 * vai `user` không bao giờ — giống luật của menu Users.
 */
declare(strict_types=1);

final class Roles
{
    /** Cột DataTables -> biểu thức SQL. Cột hiển thị tên thì sort theo tên. */
    private const SORT_MAP = [
        'name'  => 'rp.name',
        'level' => 'rp.slug',
        'count' => 'authors_count',
    ];

    /** Cấp (slug) hợp lệ. `admin` chỉ admin mới gán được — xem allowed_levels(). */
    public const LEVELS = [
        'admin'    => 'Admin',
        'manager'  => 'Manager',
        'user'     => 'User',
        'customer' => 'Customer',
    ];

    /**
     * Thứ bậc CẤP: Admin > Manager > User > Customer (chốt 06/08/2026) — GIỐNG Users.
     * Người cấp thấp không được THẤY role cấp cao hơn: danh sách lộ có role admin là lộ
     * luôn mục tiêu tấn công, và nhìn một dòng mà mọi nút đều khóa thì rất khó hiểu.
     */
    private const LEVEL_RANK = ['admin' => 4, 'manager' => 3, 'user' => 2, 'customer' => 1];

    /** Hành động con của mỗi menu trong JSON roles. */
    public const ACTIONS = ['view', 'add', 'edit', 'delete'];

    // ---------------------------------------------------------------- quyền

    /**
     * Vào được menu này không. Admin toàn quyền; manager phải có role; vai user thì không
     * — quản trị phân quyền là việc của nhân sự cấp cao.
     */
    public static function can_manage(string $action = 'view'): bool
    {
        return is_admin() || (is_manager() && checkRoles($action, 'roles-permissions'));
    }

    public static function can_add(): bool
    {
        return self::can_manage('add');
    }

    /** Có quyền xóa bất kỳ (quyết định nút Delete cấp trang). */
    public static function can_delete_any(): bool
    {
        return self::can_manage('delete');
    }

    /**
     * ID role của chính người đang đăng nhập. Session chỉ giữ `level` = SLUG (admin/manager/
     * user) chứ không giữ ID, nên phải đọc `authors.level`. Cache theo request — trang danh
     * sách gọi hàm này 2 lần cho MỖI dòng.
     */
    private static function own_level_id(): int
    {
        $uid = (int)($_SESSION['auth']['user_id'] ?? 0);
        if ($uid <= 0) {
            return 0;
        }
        if (!isset($GLOBALS['__access_cache']['own_level'][$uid])) {
            $row = db()->execute_query(
                'SELECT level FROM authors WHERE ID = ? LIMIT 1', [$uid])->fetch_row();
            $GLOBALS['__access_cache']['own_level'][$uid] = (int)($row[0] ?? 0);
        }
        return (int)$GLOBALS['__access_cache']['own_level'][$uid];
    }

    /**
     * Sửa được dòng này không.
     * - Role `admin`: chỉ admin (sửa cũng vô hại vì admin bỏ qua roles, nhưng manager thì
     *   tuyệt đối không được đụng).
     * - Role của CHÍNH MÌNH: non-admin không được sửa — đây là đường tự nâng quyền ngắn nhất.
     */
    public static function can_edit_row(array $row): bool
    {
        if (!self::can_manage('edit')) {
            return false;
        }
        // Chỉ sửa được role có cấp THẤP HƠN mình (nên role của chính mình cũng loại luôn,
        // vì nó ngang cấp với mình).
        if (!self::level_thap_hon($row)) {
            return false;
        }
        return (int)($row['id'] ?? 0) !== self::own_level_id();
    }

    /** Xóa được dòng này không. Role `admin` KHÔNG bao giờ xóa được, kể cả bởi admin. */
    public static function can_delete_row(array $row): bool
    {
        if (!self::can_manage('delete')) {
            return false;
        }
        if (!self::level_thap_hon($row)) {
            return false;   // bao gồm cả role cấp admin và role ngang cấp mình
        }
        return (int)($row['id'] ?? 0) !== self::own_level_id();
    }

    /** Thứ hạng của chính người đang đăng nhập. */
    private static function own_rank(): int
    {
        return self::LEVEL_RANK[(string)($_SESSION['auth']['level'] ?? '')] ?? 0;
    }

    /**
     * Các cấp người đang đăng nhập được phép NHÌN THẤY (ngang hàng hoặc thấp hơn).
     * Khác allowed_levels() — cái đó là cấp được phép GÁN, chặt hơn nhiều.
     */
    public static function visible_levels(): array
    {
        if (is_admin()) {
            return self::LEVELS;
        }
        $rank = self::own_rank();
        return array_filter(self::LEVELS, fn($l, $slug) => (self::LEVEL_RANK[$slug] ?? 0) <= $rank,
            ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Cấp được phép TẠO/SỬA/XÓA (chốt 06/08/2026): **CHỈ THẤP HƠN mình**, không phải
     * cùng cấp. Admin quản được manager/user/customer nhưng KHÔNG đụng role cấp admin;
     * manager quản được user/customer nhưng không đụng role cấp manager.
     *
     * KHÁC visible_levels() (cùng cấp hoặc thấp hơn) — nhìn thấy đồng cấp là được, nhưng
     * SỬA đồng cấp thì không: sửa role ngang hàng là đường vòng để tự nâng quyền cho nhóm
     * mình, và để một người xóa mất quyền của người ngang vai.
     */
    public static function allowed_levels(): array
    {
        if (is_super_admin()) {
            return self::LEVELS;   // tài khoản tối cao: gán được cả cấp admin
        }
        $rank = self::own_rank();
        return array_filter(self::LEVELS,
            fn($l, $slug) => (self::LEVEL_RANK[$slug] ?? 9) < $rank, ARRAY_FILTER_USE_BOTH);
    }

    /** Dòng này có cấp THẤP HƠN mình không — điều kiện để được sửa/xóa. */
    private static function level_thap_hon(array $row): bool
    {
        return isset(self::allowed_levels()[(string)($row['slug'] ?? '')]);
    }

    /**
     * Quyền mà người đang đăng nhập được phép CẤP cho role khác: không ai cho đi thứ mình
     * không có. Admin cho được tất cả; manager chỉ cho lại đúng những ô mình đang bật.
     *
     * @return array<string,string[]>|null null = không giới hạn (admin)
     */
    public static function grantable(): ?array
    {
        if (is_admin()) {
            return null;
        }
        $mine = (array)($_SESSION['auth']['roles'] ?? []);
        $out = [];
        foreach ($mine as $menu => $acts) {
            $on = [];
            foreach (self::ACTIONS as $a) {
                if (!empty($acts[$a])) {
                    $on[] = $a;
                }
            }
            if ($on) {
                $out[(string)$menu] = $on;
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------- danh sách

    /** Bảng danh sách server-side. */
    public static function get_roles(): array
    {
        $params = get_datatable_params(array_keys(self::SORT_MAP), 'name');
        if (!self::can_manage('view')) {
            return ['draw' => $params['draw'], 'recordsTotal' => 0,
                    'recordsFiltered' => 0, 'data' => []];
        }
        $conn = db();

        // Trục CẤP: không nhìn lên trên. Lọc trong SQL để số đếm phân trang không sai.
        $scope = ''; $scopeArgs = [];
        if (!is_admin()) {
            $vis = array_keys(self::visible_levels());
            $scope = 'rp.slug IN (' . implode(',', array_fill(0, count($vis), '?')) . ')';
            $scopeArgs = $vis;
        }
        // recordsTotal phải đếm TRONG phạm vi, nếu không dòng "Showing X of Y" nói dối
        $total = (int)$conn->execute_query(
            'SELECT COUNT(rp.ID) FROM roles_permissions rp' . ($scope ? " WHERE $scope" : ''),
            $scopeArgs)->fetch_row()[0];

        $cond = [];
        $args = [];
        if ($scope !== '') {
            $cond[] = $scope;
            foreach ($scopeArgs as $v) { $args[] = $v; }
        }
        if ($params['searchValue'] !== '') {
            $cond[] = '(rp.name LIKE ? OR rp.slug LIKE ?)';
            $like   = '%' . $params['searchValue'] . '%';
            $args[] = $like;
            $args[] = $like;
        }
        $level = (string)($_POST['level'] ?? '');
        if ($level !== '' && isset(self::visible_levels()[$level])) {
            $cond[] = 'rp.slug = ?';
            $args[] = $level;
        }
        // Lọc theo "đang có người dùng hay không" — dùng EXISTS để không phải HAVING sau GROUP BY
        $assigned = (string)($_POST['assigned'] ?? '');
        if ($assigned === 'yes' || $assigned === 'no') {
            $cond[] = ($assigned === 'yes' ? '' : 'NOT ')
                . 'EXISTS (SELECT 1 FROM authors a2 WHERE a2.level = rp.ID)';
        }
        $where = $cond ? ' WHERE ' . implode(' AND ', $cond) : '';

        $filtered = (int)$conn->execute_query(
            "SELECT COUNT(rp.ID) FROM roles_permissions rp $where", $args)->fetch_row()[0];

        $order = build_order_by($params, self::SORT_MAP, 'rp.ID');
        $limit = 'LIMIT ' . (int)$params['start'] . ', ' . (int)$params['length'];

        $rs = $conn->execute_query(
            "SELECT rp.ID, rp.name, rp.slug, rp.roles, COUNT(a.ID) AS authors_count
             FROM roles_permissions rp
             LEFT JOIN authors a ON a.level = rp.ID
             $where
             GROUP BY rp.ID, rp.name, rp.slug, rp.roles
             ORDER BY $order $limit", $args);

        $data = [];
        while ($r = $rs->fetch_assoc()) {
            $row = [
                'id'    => (int)$r['ID'],
                'name'  => $r['name'],
                'slug'  => $r['slug'],
                'level' => self::LEVELS[$r['slug']] ?? $r['slug'],
                'count' => (int)$r['authors_count'],
                // Tóm tắt quyền để hiện badge, không phải đổ cả JSON ra bảng
                'menus' => self::summarize((string)$r['roles']),
            ];
            // Cờ quyền theo TỪNG DÒNG — JS dựng nút từ cờ này (nút lẻ thiếu quyền thì KHÓA)
            $row['can_edit']   = self::can_edit_row($row);
            $row['can_delete'] = self::can_delete_row($row);
            $data[] = $row;
        }

        return ['draw' => $params['draw'], 'recordsTotal' => $total,
                'recordsFiltered' => $filtered, 'data' => $data];
    }

    /** Đếm số menu đang được bật trong JSON roles. */
    private static function summarize(string $json): int
    {
        $r = json_decode($json, true);
        if (!is_array($r)) {
            return 0;
        }
        $n = 0;
        foreach ($r as $acts) {
            if (is_array($acts) && array_filter($acts)) {
                $n++;
            }
        }
        return $n;
    }

    /** Dữ liệu dựng filter + cờ quyền cấp trang cho JS. */
    public static function get_roles_filters(): array
    {
        if (!self::can_manage('view')) {
            return ['status' => 'error', 'message' => 'You do not have permission to view roles.'];
        }
        return [
            'levels' => self::visible_levels(),
            'perms'  => [
                'add'        => self::can_add(),
                'delete_any' => self::can_delete_any(),
                'is_admin'   => is_admin(),
                'my_level'   => self::own_level_id(),
            ],
        ];
    }

    // ---------------------------------------------------------------- xóa

    /** Đọc + kiểm dòng đích cho thao tác xóa. */
    private static function delete_target(): array
    {
        if (!check_csrf()) {
            return ['error' => 'Invalid CSRF token.'];
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            return ['error' => 'Missing role.'];
        }
        $r = db()->execute_query(
            'SELECT ID, name, slug FROM roles_permissions WHERE ID = ? LIMIT 1', [$id])->fetch_assoc();
        if (!$r) {
            return ['error' => 'Role not found.'];
        }
        $row = ['id' => (int)$r['ID'], 'name' => $r['name'], 'slug' => $r['slug']];
        if (!self::can_delete_row($row)) {
            return ['error' => 'You do not have permission to delete this role.'];
        }
        return ['row' => $row];
    }

    /**
     * Xem trước khi xóa: bao nhiêu người đang mang role này + danh sách role thay thế.
     * Chốt 06/08/2026: CHO xóa nhưng BẮT chọn role thay thế, không để `authors.level` mồ côi.
     */
    public static function get_delete_preview(): array
    {
        $t = self::delete_target();
        if (isset($t['error'])) {
            return ['status' => 'error', 'message' => $t['error']];
        }
        $conn = db();
        $id = $t['row']['id'];

        $users = (int)$conn->execute_query(
            'SELECT COUNT(*) FROM authors WHERE level = ?', [$id])->fetch_row()[0];

        // Role thay thế: mọi role khác mà người đang thao tác được phép gán
        $targets = [];
        $rs = $conn->execute_query(
            'SELECT ID, name, slug FROM roles_permissions WHERE ID <> ? ORDER BY name', [$id]);
        while ($r = $rs->fetch_assoc()) {
            if (!isset(self::allowed_levels()[$r['slug']])) {
                continue;   // chỉ chuyển người sang role cùng cấp hoặc thấp hơn
            }
            $targets[] = ['id' => (int)$r['ID'], 'name' => $r['name'],
                          'slug' => $r['slug'],
                          'level' => self::LEVELS[$r['slug']] ?? $r['slug']];
        }
        // Xếp CẤP THẤP NHẤT LÊN ĐẦU: ô chọn mặc định lấy option đầu, để Admin đứng đầu thì
        // ai bấm Delete mà không đổi là vô tình nâng cả nhóm người lên Admin.
        usort($targets, fn($a, $b) =>
            (self::LEVEL_RANK[$a['slug']] ?? 9) <=> (self::LEVEL_RANK[$b['slug']] ?? 9)
            ?: strcasecmp($a['name'], $b['name']));

        return ['status' => 'success', 'name' => $t['row']['name'],
                'users' => $users, 'targets' => $targets];
    }

    /** Xóa role, chuyển người đang mang nó sang role thay thế trước. */
    public static function delete_role(): array
    {
        $t = self::delete_target();
        if (isset($t['error'])) {
            return ['status' => 'error', 'message' => $t['error']];
        }
        $conn = db();
        $id = $t['row']['id'];

        $users = (int)$conn->execute_query(
            'SELECT COUNT(*) FROM authors WHERE level = ?', [$id])->fetch_row()[0];

        $moved = 0;
        if ($users > 0) {
            $to = (int)($_POST['replacement'] ?? 0);
            if ($to <= 0) {
                return ['status' => 'error',
                        'message' => "$users user(s) still use this role. Choose a replacement role."];
            }
            if ($to === $id) {
                return ['status' => 'error', 'message' => 'Choose a different replacement role.'];
            }
            $rep = $conn->execute_query(
                'SELECT ID, slug FROM roles_permissions WHERE ID = ? LIMIT 1', [$to])->fetch_assoc();
            if (!$rep) {
                return ['status' => 'error', 'message' => 'Replacement role not found.'];
            }
            // Non-admin không được đẩy người sang role quyền cao hơn vai `user`
            if (!isset(self::allowed_levels()[$rep['slug']])) {
                return ['status' => 'error',
                        'message' => 'You can only move users to a role at your level or below.'];
            }
            $conn->execute_query('UPDATE authors SET level = ? WHERE level = ?', [$to, $id]);
            $moved = $conn->affected_rows;
        }

        $conn->execute_query('DELETE FROM roles_permissions WHERE ID = ?', [$id]);

        return ['status' => 'success', 'moved' => $moved,
                'message' => $moved > 0
                    ? "Role deleted. $moved user(s) moved to the replacement role."
                    : 'Role deleted.'];
    }
}
