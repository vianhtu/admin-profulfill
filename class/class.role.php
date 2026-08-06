<?php
/**
 * Role — MỘT vai trò (form Add/Edit).
 *
 * Đây là nơi đặt các chốt chống tự nâng quyền. Ba thứ TUYỆT ĐỐI không được nhận bừa từ
 * request (xem class.roles.php để hiểu vì sao):
 *
 *   - `slug`  : chính là CẤP (admin/manager/user/customer) mà `is_admin()`/`is_manager()`
 *               đọc. Chỉ nhận cấp CÙNG HÀNG hoặc THẤP HƠN người đang thao tác; ngoài phạm
 *               vi thì ép về giá trị an toàn, KHÔNG được tin.
 *   - `permissions` : non-admin chỉ cấp lại được đúng những ô mình đang có.
 *   - `id`    : phải kiểm quyền theo dòng, không tin id gửi lên.
 */
declare(strict_types=1);

final class Role
{
    /** Thêm mới hoặc sửa một role. */
    public static function save_role(): array
    {
        if (!check_csrf()) {
            return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
        }

        $id      = (int)($_POST['id'] ?? 0);
        $name    = trim((string)($_POST['role_name'] ?? ''));
        $isEdit  = $id > 0;

        if (!Roles::can_manage($isEdit ? 'edit' : 'add')) {
            return ['status' => 'error', 'message' => 'You do not have permission to manage roles.'];
        }
        if ($name === '') {
            return ['status' => 'error', 'message' => 'Role name is required.'];
        }
        if (mb_strlen($name) > 50) {
            return ['status' => 'error', 'message' => 'Role name must be 50 characters or fewer.'];
        }

        $conn = db();

        // --- Dòng đang sửa: kiểm quyền THEO DÒNG, không tin id gửi lên ---
        $current = null;
        if ($isEdit) {
            $r = $conn->execute_query(
                'SELECT ID, name, slug FROM roles_permissions WHERE ID = ? LIMIT 1', [$id])->fetch_assoc();
            if (!$r) {
                return ['status' => 'error', 'message' => 'Role not found.'];
            }
            $current = ['id' => (int)$r['ID'], 'name' => $r['name'], 'slug' => $r['slug']];
            if (!Roles::can_edit_row($current)) {
                return ['status' => 'error', 'message' => 'You do not have permission to edit this role.'];
            }
        }

        // --- CẤP (slug): trường nguy hiểm nhất bảng ---
        // Nhận cấp gửi lên NẾU nằm trong phạm vi được phép gán (cùng cấp hoặc thấp hơn).
        // Ngoài phạm vi thì ÉP về giá trị an toàn chứ không báo lỗi — đúng luật "tham số
        // không được phép dùng thì bỏ qua, không tin".
        $allowed = Roles::allowed_levels();
        $slug = (string)($_POST['level'] ?? '');
        if (!isset($allowed[$slug])) {
            $slug = $isEdit ? (string)$current['slug'] : 'user';
        }
        // Sửa role: không được nâng cấp nó lên cao hơn cấp CŨ nếu cấp cũ ngoài tầm mình
        if ($isEdit && !isset($allowed[(string)$current['slug']])) {
            $slug = (string)$current['slug'];
        }
        // Không cho hạ cấp role `admin` xuống thứ khác — sẽ không còn ai là admin
        if ($isEdit && $current['slug'] === 'admin' && $slug !== 'admin') {
            return ['status' => 'error', 'message' => 'The Admin role level cannot be changed.'];
        }

        // --- Trùng tên (so theo ID để đổi tên được, khác bản cũ vốn keyed theo name) ---
        $dup = $conn->execute_query(
            'SELECT ID FROM roles_permissions WHERE name = ? AND ID <> ? LIMIT 1',
            [$name, $id])->fetch_row();
        if ($dup) {
            return ['status' => 'error', 'message' => 'A role with this name already exists.'];
        }

        // --- Quyền được cấp: lọc qua những gì bản thân đang có ---
        $perms = self::sanitize_permissions($_POST['permissions'] ?? []);

        $rolesJson = json_encode($perms, JSON_UNESCAPED_UNICODE);

        if ($isEdit) {
            $conn->execute_query(
                'UPDATE roles_permissions SET name = ?, slug = ?, roles = ? WHERE ID = ?',
                [$name, $slug, $rolesJson, $id]);
            return ['status' => 'success', 'id' => $id, 'message' => 'Role updated.'];
        }

        // slug NOT NULL và không có default + sql_mode STRICT -> phải truyền, nếu không
        // INSERT văng lỗi (đúng lỗi khiến "Add Permission" bản cũ chưa bao giờ chạy được).
        $conn->execute_query(
            'INSERT INTO roles_permissions (name, slug, roles) VALUES (?, ?, ?)',
            [$name, $slug, $rolesJson]);

        return ['status' => 'success', 'id' => (int)$conn->insert_id, 'message' => 'Role created.'];
    }

    /**
     * Lọc JSON quyền gửi lên:
     *   - chỉ giữ menu có thật trong menuArgs() và hành động menu đó thực sự hỗ trợ
     *     (chống nhét menu lạ / hành động lạ vào JSON);
     *   - non-admin chỉ cấp lại được đúng ô mình đang bật (chống tự nâng quyền).
     */
    private static function sanitize_permissions(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $valid     = self::valid_menu_actions();
        $grantable = Roles::grantable();   // null = admin, không giới hạn

        $out = [];
        foreach ($raw as $menu => $acts) {
            $menu = (string)$menu;
            if (!isset($valid[$menu]) || !is_array($acts)) {
                continue;
            }
            $on = [];
            foreach (Roles::ACTIONS as $a) {
                if (empty($acts[$a])) {
                    continue;
                }
                if (!in_array($a, $valid[$menu], true)) {
                    continue;   // menu này không hỗ trợ hành động đó
                }
                if ($grantable !== null && !in_array($a, $grantable[$menu] ?? [], true)) {
                    continue;   // bản thân không có -> không cho đi được
                }
                $on[$a] = 'on';
            }
            if ($on) {
                $out[$menu] = $on;
            }
        }
        return $out;
    }

    /**
     * Bản đồ menu hợp lệ -> hành động menu đó hỗ trợ, rút thẳng từ menuArgs() để form và
     * phần kiểm phía server không bao giờ lệch nhau.
     *
     * @return array<string,string[]>
     */
    public static function valid_menu_actions(): array
    {
        $out = [];
        foreach (menuArgs() as $item) {
            if (!empty($item['sub'])) {
                foreach ($item['sub'] as $key => $sub) {
                    if (!empty($sub['roles'])) {
                        $out[(string)$key] = array_values($sub['roles']);
                    }
                }
                continue;
            }
            // Menu admin_only (Teams) không đi qua roles_permissions -> không đưa vào form
            if (!empty($item['roles']) && empty($item['admin_only'])) {
                $out[(string)$item['link']] = array_values($item['roles']);
            }
        }
        return $out;
    }

    /** Đọc một role để đổ vào form Edit. */
    public static function get_role(): array
    {
        if (!Roles::can_manage('view')) {
            return ['status' => 'error', 'message' => 'You do not have permission to view roles.'];
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            return ['status' => 'error', 'message' => 'Missing role.'];
        }
        $r = db()->execute_query(
            'SELECT ID, name, slug, roles FROM roles_permissions WHERE ID = ? LIMIT 1',
            [$id])->fetch_assoc();
        if (!$r) {
            return ['status' => 'error', 'message' => 'Role not found.'];
        }
        $row = ['id' => (int)$r['ID'], 'name' => $r['name'], 'slug' => $r['slug']];

        return [
            'status'      => 'success',
            'id'          => $row['id'],
            'role_name'   => $r['name'],
            'level'       => $r['slug'],
            'permissions' => json_decode((string)$r['roles'], true) ?: [],
            // JS khóa form khi không được sửa dòng này (nút Edit đã khóa sẵn, đây là lớp 2)
            'can_edit'    => Roles::can_edit_row($row),
        ];
    }
}
