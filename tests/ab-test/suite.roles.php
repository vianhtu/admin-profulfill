<?php
/**
 * AB TEST — Roles / Role (menu Roles & Permissions).
 *
 * ĐÂY LÀ BẢNG NGUY HIỂM NHẤT HỆ THỐNG: nó định nghĩa quyền của mọi người khác, và cột
 * `slug` chính là thứ `is_admin()`/`is_manager()` đọc.
 *
 * Luật (chốt 06/08/2026):
 *   - Vào menu: admin FULL; manager phải được cấp role; vai `user` KHÔNG BAO GIỜ, kể cả
 *     khi được cấp đủ 4 quyền — đó là điểm khác các bộ khác.
 *   - Thứ bậc: Admin > Manager > User > Customer. Chỉ thấy và chỉ gán được cấp CÙNG HÀNG
 *     hoặc THẤP HƠN.
 *   - Không ai cấp được quyền mà bản thân không có.
 *   - Không ai sửa role của CHÍNH MÌNH (trừ admin).
 *   - Role `admin` không bao giờ bị xóa, kể cả bởi admin.
 */

return function (AbRunner $r): void {

    /** Tạo role ZZAB dùng một lần, trả ID. */
    $newRole = function ($fx, string $slug = 'user', array $roles = []): int {
        $n = 'ZZABROLE' . bin2hex(random_bytes(4));
        $fx->conn->execute_query(
            'INSERT INTO roles_permissions (name, slug, roles) VALUES (?, ?, ?)',
            [$n, $slug, json_encode($roles)]);
        return (int)$fx->conn->insert_id;
    };

    // ---------- Đọc ----------
    $r->add('Roles — đọc', 'Thấy danh sách role', function ($a, $fx) use ($newRole) {
        $id = $newRole($fx);
        $_POST = ab_dt(['columns' => [['data' => 'name']]]);
        return ab_sees(Roles::get_roles(), $id);
    })->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'MGR_T2');

    // Vai `user` bị chặn TUYỆT ĐỐI — quản trị phân quyền là việc của nhân sự cấp cao
    $r->add('Roles — đọc', 'Vai user có đủ 4 quyền VẪN không vào được menu', function ($a, $fx) {
        return Roles::can_manage('view');
    })->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'MGR_T2');

    // Trục CẤP: lộ có role admin là lộ luôn mục tiêu tấn công
    $r->add('Roles — đọc', 'KHÔNG thấy role cấp admin (chỉ admin thấy)', function ($a, $fx) {
        $adm = (int)$fx->conn->query("SELECT ID FROM roles_permissions WHERE slug='admin' LIMIT 1")->fetch_row()[0];
        $_POST = ab_dt(['columns' => [['data' => 'name']], 'length' => 100]);
        return ab_sees(Roles::get_roles(), $adm);
    })->allow('ADMIN');

    $r->add('Roles — đọc', 'recordsTotal cũng đếm trong phạm vi cấp', function ($a, $fx) {
        $_POST = ab_dt(['columns' => [['data' => 'name']], 'length' => 100]);
        $res = Roles::get_roles();
        if (!Roles::can_manage('view')) {
            return ['status' => 'error', 'message' => 'không vào được menu'];
        }
        // "Showing X of Y" không được nói dối: Y phải bằng số dòng thực sự thấy được
        return (int)$res['recordsTotal'] === count($res['data']);
    })->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'MGR_T2');

    $r->add('Roles — đọc', 'Ô lọc chỉ liệt kê cấp nhìn thấy được', function ($a, $fx) {
        $res = Roles::get_roles_filters();
        if (($res['status'] ?? '') === 'error') {
            return $res;
        }
        $lv = array_keys($res['levels'] ?? []);
        return is_admin() ? in_array('admin', $lv, true) : !in_array('admin', $lv, true);
    })->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'MGR_T2');

    // ---------- Thêm ----------
    $r->add('Roles — thêm', 'Tạo role mới', function ($a, $fx) {
        $_POST = ['id' => 0, 'role_name' => 'ZZABROLE' . bin2hex(random_bytes(4)),
                  'level' => 'user', 'permissions' => [], 'csrf_token' => 'ABTEST'];
        return Role::save_role();
    })->allow('ADMIN', 'MGR_T1', 'MGR_T2');

    // slug NOT NULL không default + sql_mode STRICT -> INSERT thiếu slug là văng lỗi.
    // Đây chính là lỗi khiến "Add Permission" bản cũ chưa bao giờ chạy được.
    $r->add('Roles — thêm', 'Role mới có slug hợp lệ trong DB', function ($a, $fx) {
        $n = 'ZZABROLE' . bin2hex(random_bytes(4));
        $_POST = ['id' => 0, 'role_name' => $n, 'level' => 'user',
                  'permissions' => [], 'csrf_token' => 'ABTEST'];
        $res = Role::save_role();
        if (($res['status'] ?? '') !== 'success') {
            return $res;
        }
        $slug = $fx->conn->execute_query(
            'SELECT slug FROM roles_permissions WHERE ID = ?', [$res['id']])->fetch_row()[0];
        return $slug !== '' && $slug !== null;
    })->allow('ADMIN', 'MGR_T1', 'MGR_T2');

    // ---------- Chống leo thang quyền ----------
    $r->add('Roles — leo thang', 'KHÔNG tạo được role cấp admin (gửi level=admin bị ép)', function ($a, $fx) {
        $n = 'ZZABROLE' . bin2hex(random_bytes(4));
        $_POST = ['id' => 0, 'role_name' => $n, 'level' => 'admin',
                  'permissions' => [], 'csrf_token' => 'ABTEST'];
        $res = Role::save_role();
        if (($res['status'] ?? '') !== 'success') {
            return $res;
        }
        // Tham số giả mạo bị BỎ QUA chứ không báo lỗi -> phải ĐỌC LẠI DB mới biết thật giả
        $slug = $fx->conn->execute_query(
            'SELECT slug FROM roles_permissions WHERE ID = ?', [$res['id']])->fetch_row()[0];
        return $slug === 'admin';
        // Luật CẤP (chốt 06/08/2026): chỉ quản được cấp THẤP HƠN -> admin thường KHÔNG
        // tạo được role cấp admin. Chỉ SUPER ADMIN (fox1990) mới lập được.
    })->only_super();

    $r->add('Roles — leo thang', 'KHÔNG sửa được role của CHÍNH MÌNH', function ($a, $fx) {
        $myLevel = (int)$fx->conn->execute_query(
            'SELECT level FROM authors WHERE ID = ? LIMIT 1', [$a->uid])->fetch_row()[0];
        // Gửi ĐÚNG cấp hiện tại: nếu hardcode 'user' thì admin bị chặn vì lý do khác
        // ("The Admin role level cannot be changed") chứ không phải vì luật sửa-role-mình.
        $mySlug = (string)$fx->conn->execute_query(
            'SELECT slug FROM roles_permissions WHERE ID = ?', [$myLevel])->fetch_row()[0];
        // ADMIN được phép sửa role của chính mình -> phép thử sẽ ĐỔI TÊN role Admin THẬT
        // (2 người đang mang). Chụp lại tên/roles gốc và khôi phục ngay sau khi thử.
        $goc = $fx->conn->execute_query(
            'SELECT name, roles FROM roles_permissions WHERE ID = ?', [$myLevel])->fetch_assoc();
        $_POST = ['id' => $myLevel, 'role_name' => 'ZZABSELF' . bin2hex(random_bytes(3)),
                  'level' => $mySlug, 'permissions' => [], 'csrf_token' => 'ABTEST'];
        $res = Role::save_role();
        $fx->conn->execute_query(
            'UPDATE roles_permissions SET name = ?, roles = ? WHERE ID = ?',
            [$goc['name'], $goc['roles'], $myLevel]);
        return $res;
        // Role của chính mình luôn NGANG CẤP với mình, mà luật chỉ cho quản cấp THẤP HƠN
        // -> không ai sửa được role của mình, kể cả admin.
    })->allow();

    $r->add('Roles — leo thang', 'KHÔNG cấp được quyền mà bản thân không có', function ($a, $fx) {
        $n = 'ZZABROLE' . bin2hex(random_bytes(4));
        $_POST = ['id' => 0, 'role_name' => $n, 'level' => 'user', 'csrf_token' => 'ABTEST',
                  'permissions' => ['teams' => ['view' => 'on', 'delete' => 'on'],
                                    'users' => ['delete' => 'on']]];
        $res = Role::save_role();
        if (($res['status'] ?? '') !== 'success') {
            return $res;
        }
        $json = json_decode((string)$fx->conn->execute_query(
            'SELECT roles FROM roles_permissions WHERE ID = ?', [$res['id']])->fetch_row()[0], true) ?: [];
        // `teams` là menu admin_only, KHÔNG đi qua roles_permissions -> không ai cấp được
        return !isset($json['teams']) ? ['status' => 'error', 'message' => 'teams bị lọc đúng']
                                      : true;
    })->allow();   // KHÔNG actor nào được phép cấp menu teams

    $r->add('Roles — leo thang', 'Cấp gán được là cùng cấp hoặc thấp hơn', function ($a, $fx) {
        $lv = array_keys(Roles::allowed_levels());
        if (!Roles::can_manage('view')) {
            return ['status' => 'error', 'message' => 'không vào được menu'];
        }
        // Chỉ quản được cấp THẤP HƠN: admin không có 'admin', manager không có 'manager'.
        // SUPER ADMIN là ngoại lệ duy nhất — được cả cấp admin.
        if (is_super_admin()) {
            return in_array('admin', $lv, true);
        }
        $cam = is_admin() ? 'admin' : 'manager';
        return !in_array($cam, $lv, true) && $lv !== [];
    })->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'MGR_T2');

    // ---------- Sửa ----------
    $r->add('Roles — sửa', 'Sửa role thường', function ($a, $fx) use ($newRole) {
        $id = $newRole($fx);
        $_POST = ['id' => $id, 'role_name' => 'ZZABEDIT' . bin2hex(random_bytes(3)),
                  'level' => 'user', 'permissions' => [], 'csrf_token' => 'ABTEST'];
        return Role::save_role();
    })->allow('ADMIN', 'MGR_T1', 'MGR_T2');

    $r->add('Roles — sửa', 'KHÔNG hạ cấp được role admin', function ($a, $fx) {
        $adm = (int)$fx->conn->query("SELECT ID FROM roles_permissions WHERE slug='admin' LIMIT 1")->fetch_row()[0];
        $_POST = ['id' => $adm, 'role_name' => 'Admin', 'level' => 'user',
                  'permissions' => [], 'csrf_token' => 'ABTEST'];
        Role::save_role();
        $slug = $fx->conn->execute_query(
            'SELECT slug FROM roles_permissions WHERE ID = ?', [$adm])->fetch_row()[0];
        return $slug !== 'admin';   // true = đã hạ được = LỖ HỔNG
    })->allow();

    // ---------- Xóa ----------
    $r->add('Roles — xóa', 'Xóa role không ai dùng', function ($a, $fx) use ($newRole) {
        $id = $newRole($fx);
        $_POST = ['id' => $id, 'csrf_token' => 'ABTEST'];
        $res = Roles::delete_role();
        if (($res['status'] ?? '') !== 'success') {
            return $res;
        }
        return $fx->conn->execute_query(
            'SELECT ID FROM roles_permissions WHERE ID = ?', [$id])->fetch_row() === null;
    })->allow('ADMIN', 'MGR_T1', 'MGR_T2');

    $r->add('Roles — xóa', 'KHÔNG xóa được role admin (kể cả admin)', function ($a, $fx) {
        $adm = (int)$fx->conn->query("SELECT ID FROM roles_permissions WHERE slug='admin' LIMIT 1")->fetch_row()[0];
        $_POST = ['id' => $adm, 'csrf_token' => 'ABTEST'];
        Roles::delete_role();
        return $fx->conn->execute_query(
            'SELECT ID FROM roles_permissions WHERE ID = ?', [$adm])->fetch_row() === null;
    })->allow();

    $r->add('Roles — xóa', 'CHẶN xóa role còn người dùng khi chưa chọn role thay thế',
        function ($a, $fx) use ($newRole) {
            $id = $newRole($fx);
            $uid = $fx->new_user((int)$a->team);
            $fx->conn->execute_query('UPDATE authors SET level = ? WHERE ID = ?', [$id, $uid]);
            $_POST = ['id' => $id, 'csrf_token' => 'ABTEST'];
            $res = Roles::delete_role();
            $conNguyen = $fx->conn->execute_query(
                'SELECT ID FROM roles_permissions WHERE ID = ?', [$id])->fetch_row() !== null;
            // Dọn: trả user về role cũ rồi xóa role rác
            $fx->conn->execute_query('DELETE FROM authors WHERE ID = ?', [$uid]);
            $fx->conn->execute_query('DELETE FROM roles_permissions WHERE ID = ?', [$id]);
            return ($res['status'] ?? '') === 'success' || !$conNguyen;   // true = xóa được = SAI
        })->allow();

    $r->add('Roles — xóa', 'Thiếu CSRF thì không xóa được', function ($a, $fx) use ($newRole) {
        $id = $newRole($fx);
        $_POST = ['id' => $id];   // thiếu csrf_token
        Roles::delete_role();
        $conNguyen = $fx->conn->execute_query(
            'SELECT ID FROM roles_permissions WHERE ID = ?', [$id])->fetch_row() !== null;
        $fx->conn->execute_query('DELETE FROM roles_permissions WHERE ID = ?', [$id]);
        return !$conNguyen;   // true = đã xóa dù thiếu CSRF = LỖ HỔNG
    })->allow();

    // ---------- Giao diện ----------
    $r->add('Roles — giao diện', 'Trang danh sách hiện ra', function ($a, $fx) {
        $_REQUEST['menu'] = 'roles-permissions';
        $html = ab_render('app-access-permission.php', ['menu' => 'roles-permissions']);
        return str_contains($html, 'datatables-permissions');
    })->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'MGR_T2');

    $r->add('Roles — giao diện', 'Ma trận quyền KHÔNG có menu admin-only (Teams)', function ($a, $fx) {
        $_REQUEST['menu'] = 'roles-permissions';
        $html = ab_render('app-access-permission.php', ['menu' => 'roles-permissions']);
        if (!str_contains($html, 'datatables-permissions')) {
            return ['status' => 'error', 'message' => 'không vào được trang'];
        }
        return str_contains($html, 'data-menu="teams"');   // true = lộ = SAI
    })->allow();

    $r->add('Roles — giao diện', 'Mọi ô tick quyền đều có aria-label', function ($a, $fx) {
        $_REQUEST['menu'] = 'roles-permissions';
        $html = ab_render('app-access-permission.php', ['menu' => 'roles-permissions']);
        if (!str_contains($html, 'perm-box')) {
            return ['status' => 'error', 'message' => 'không vào được trang'];
        }
        $tong = substr_count($html, 'class="perm-box"');
        $coNhan = substr_count($html, 'class="perm-box" aria-label=');
        return $tong > 0 && $tong === $coNhan;
    })->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'MGR_T2');
};
