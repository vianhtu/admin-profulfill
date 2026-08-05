<?php
/**
 * AB TEST — Users / User.
 *
 * Luật (chốt 05/08/2026):
 *   - Phạm vi: admin = mọi team; manager = user cùng team; user = chỉ chính mình.
 *   - Lương/bảo hiểm: chỉ admin và manager thấy (endpoint không trả field cho level user).
 *   - Thêm: admin hoặc manager có role add; non-admin bị ép về team của mình và
 *     KHÔNG được gán nhóm quyền admin.
 *   - Sửa: admin mọi user; manager chỉ user cùng team và không phải tài khoản admin.
 *   - Xóa: CHỈ admin, chặn khi user còn sản phẩm/account, và không tự xóa mình.
 *
 * AN TOÀN: mọi phép thử ghi/xóa đều thao tác trên user ZZAB do $fx->new_user() tạo ra,
 * tuyệt đối không đụng tài khoản thật.
 */

return function (AbRunner $r): void {

    // ---------- Đọc ----------
    // Level `user` chỉ thấy chính mình -> USR_T2 KHÔNG được thấy đồng đội mới tạo
    $r->add('Users — đọc', 'Thấy user của TEAM 2', function ($a, $fx) {
        $id = $fx->new_user(2);
        $_POST = ab_dt(['columns' => [['data' => 'username']]]);
        return ab_sees(Users::get_users(), $id);
    })->allow('ADMIN', 'MGR_T2');

    $r->add('Users — đọc', 'Thấy user của TEAM 1', function ($a, $fx) {
        $id = $fx->new_user(1);
        $_POST = ab_dt(['columns' => [['data' => 'username']]]);
        return ab_sees(Users::get_users(), $id);
    })->allow('ADMIN', 'MGR_T1', 'MGR_T1_V');

    $r->add('Users — đọc', 'Level user chỉ thấy đúng 1 dòng (chính mình)', function ($a, $fx) {
        $fx->new_user((int)$a->team);   // thêm đồng đội -> người khác phải thấy nhiều hơn 1
        $_POST = ab_dt(['columns' => [['data' => 'username']]]);
        $rows = Users::get_users()['data'] ?? [];
        return count($rows) === 1 && (int)$rows[0]['id'] === (int)$a->uid;
    })->allow('USR_T1', 'USR_T1_V', 'USR_T2', 'USR_T3');

    $r->add('Users — đọc', 'Lương xuất hiện trong dữ liệu trả về', function ($a, $fx) {
        $_POST = ab_dt(['columns' => [['data' => 'username']]]);
        $rows = Users::get_users()['data'] ?? [];
        return !empty($rows) && array_key_exists('wage', $rows[0]);
    })->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'MGR_T2');

    $r->add('Users — đọc', 'Bộ lọc + cờ quyền trả về được', function ($a, $fx) {
        $res = Users::get_users_filters();
        return ($res['status'] ?? '') === 'error' ? $res : isset($res['perms']['add']);
    })->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'USR_T1', 'USR_T1_V', 'MGR_T2', 'USR_T2', 'USR_T3');

    $r->add('Users — đọc', 'Select nhóm quyền KHÔNG chứa nhóm admin (non-admin)', function ($a, $fx) {
        $res = Users::get_users_filters();
        if (($res['status'] ?? '') === 'error') {
            return false;
        }
        // ALLOW = có nhóm admin trong danh sách -> chỉ admin mới được phép thấy
        return isset($res['roles'][$fx->admin_level()]);
    })->allow('ADMIN');

    // ---------- Ghi ----------
    $r->add('Users — ghi', 'Thêm user mới', function ($a, $fx) {
        $u = 'ZZABFIX' . bin2hex(random_bytes(4));
        $_POST = ['csrf_token' => 'ABTEST', 'id' => 0, 'username' => $u, 'email' => $u . '@zzab.test',
            'password' => 'zzabzzab', 'level' => 0, 'team_id' => (int)$a->team, 'status' => 2];
        $_POST['level'] = (int)db()->query("SELECT ID FROM roles_permissions WHERE slug <> 'admin' ORDER BY ID LIMIT 1")->fetch_row()[0];
        return User::save_user();
    })->allow('ADMIN', 'MGR_T1', 'MGR_T2');

    $r->add('Users — ghi', 'User mới của non-admin bị ép về TEAM của họ', function ($a, $fx) {
        $u = 'ZZABFIX' . bin2hex(random_bytes(4));
        $lvl = (int)db()->query("SELECT ID FROM roles_permissions WHERE slug <> 'admin' ORDER BY ID LIMIT 1")->fetch_row()[0];
        // Cố tình gửi team khác để xem server có bị lừa không
        $_POST = ['csrf_token' => 'ABTEST', 'id' => 0, 'username' => $u, 'email' => $u . '@zzab.test',
            'password' => 'zzabzzab', 'level' => $lvl, 'team_id' => 3, 'status' => 2];
        $res = User::save_user();
        if (($res['status'] ?? '') === 'error') {
            return $res;
        }
        $team = (int)$fx->conn->query('SELECT team_id FROM authors WHERE ID = ' . (int)$res['id'])->fetch_row()[0];
        // admin gửi 3 thì đúng là team 3; người khác phải bị ép về team của mình
        return ($a->level === 'admin' ? $team === 3 : $team === (int)$a->team)
            ? $res : ['status' => 'error', 'message' => 'team_id ghi vào = ' . $team];
    })->allow('ADMIN', 'MGR_T1', 'MGR_T2');

    $r->add('Users — ghi', 'Gán nhóm quyền ADMIN cho user mới', function ($a, $fx) {
        $u = 'ZZABFIX' . bin2hex(random_bytes(4));
        $_POST = ['csrf_token' => 'ABTEST', 'id' => 0, 'username' => $u, 'email' => $u . '@zzab.test',
            'password' => 'zzabzzab', 'level' => $fx->admin_level(), 'team_id' => (int)$a->team, 'status' => 2];
        return User::save_user();
    })->allow('ADMIN');

    $r->add('Users — ghi', 'Sửa user cùng team (không phải admin)', function ($a, $fx) {
        $id = $fx->new_user((int)$a->team);
        $row = $fx->conn->query("SELECT username, email, level FROM authors WHERE ID = $id")->fetch_assoc();
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id, 'username' => $row['username'],
            'email' => $row['email'], 'password' => '', 'level' => (int)$row['level'],
            'team_id' => (int)$a->team, 'status' => 3];
        $res = User::save_user();
        if (($res['status'] ?? '') === 'error') {
            return $res;
        }
        $now = (int)$fx->conn->query("SELECT status FROM authors WHERE ID = $id")->fetch_row()[0];
        return $now === 3 ? $res : ['status' => 'error', 'message' => 'DB không đổi'];
    })->allow('ADMIN', 'MGR_T1', 'MGR_T2');

    $r->add('Users — ghi', 'Sửa user của TEAM 2', function ($a, $fx) {
        $id = $fx->new_user(2);
        $row = $fx->conn->query("SELECT username, email, level FROM authors WHERE ID = $id")->fetch_assoc();
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id, 'username' => $row['username'],
            'email' => $row['email'], 'password' => '', 'level' => (int)$row['level'],
            'team_id' => 2, 'status' => 3];
        return User::save_user();
    })->allow('ADMIN', 'MGR_T2');

    $r->add('Users — ghi', 'Sửa tài khoản cấp ADMIN', function ($a, $fx) {
        $id = $fx->new_user((int)$a->team, $fx->admin_level());
        $row = $fx->conn->query("SELECT username, email FROM authors WHERE ID = $id")->fetch_assoc();
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id, 'username' => $row['username'],
            'email' => $row['email'], 'password' => '', 'level' => $fx->admin_level(),
            'team_id' => (int)$a->team, 'status' => 3];
        return User::save_user();
    })->allow('ADMIN');

    $r->add('Users — ghi', 'Nâng user thường lên ADMIN', function ($a, $fx) {
        $id = $fx->new_user((int)$a->team);
        $row = $fx->conn->query("SELECT username, email FROM authors WHERE ID = $id")->fetch_assoc();
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id, 'username' => $row['username'],
            'email' => $row['email'], 'password' => '', 'level' => $fx->admin_level(),
            'team_id' => (int)$a->team, 'status' => 2];
        User::save_user();
        $now = (int)$fx->conn->query("SELECT level FROM authors WHERE ID = $id")->fetch_row()[0];
        // ALLOW = nâng được lên admin -> chỉ admin mới được phép
        return $now === $fx->admin_level();
    })->allow('ADMIN');

    $r->add('Users — ghi', 'Mật khẩu quá ngắn bị từ chối', function ($a, $fx) {
        $u = 'ZZABFIX' . bin2hex(random_bytes(4));
        $lvl = (int)db()->query("SELECT ID FROM roles_permissions WHERE slug <> 'admin' ORDER BY ID LIMIT 1")->fetch_row()[0];
        $_POST = ['csrf_token' => 'ABTEST', 'id' => 0, 'username' => $u, 'email' => $u . '@zzab.test',
            'password' => '123', 'level' => $lvl, 'team_id' => (int)$a->team, 'status' => 2];
        $res = User::save_user();
        // Phải bị chặn với MỌI actor
        return ($res['status'] ?? '') !== 'error';
    })->allow();

    $r->add('Users — ghi', 'Thiếu CSRF thì không lưu được', function ($a, $fx) {
        $u = 'ZZABFIX' . bin2hex(random_bytes(4));
        $_POST = ['id' => 0, 'username' => $u, 'email' => $u . '@zzab.test',
            'password' => 'zzabzzab', 'level' => 1, 'team_id' => (int)$a->team, 'status' => 2];
        User::save_user();
        return $fx->conn->query("SELECT ID FROM authors WHERE username = '$u'")->fetch_row() !== null;
    })->allow();

    // ---------- Xóa ----------
    $r->add('Users — xóa', 'Xóa user không có dữ liệu', function ($a, $fx) {
        $id = $fx->new_user((int)$a->team);
        $_POST = ['csrf_token' => 'ABTEST', 'ids' => [$id]];
        $res = Users::delete_users();
        $gone = $fx->conn->query("SELECT ID FROM authors WHERE ID = $id")->fetch_row() === null;
        return $gone ? $res : ['status' => 'error', 'message' => 'User vẫn còn'];
    })->allow('ADMIN');

    $r->add('Users — xóa', 'CHẶN xóa user còn sản phẩm đứng tên', function ($a, $fx) {
        $id = $fx->new_user((int)$a->team);
        $fx->new_post($id, 'ZZAB-P-OWN');
        $_POST = ['csrf_token' => 'ABTEST', 'ids' => [$id]];
        Users::delete_users();
        // ALLOW = xóa được dù còn sản phẩm -> không ai được phép
        return $fx->conn->query("SELECT ID FROM authors WHERE ID = $id")->fetch_row() === null;
    })->allow();

    $r->add('Users — xóa', 'KHÔNG tự xóa chính mình', function ($a, $fx) {
        $_POST = ['csrf_token' => 'ABTEST', 'ids' => [(int)$a->uid]];
        $res = Users::delete_users();
        $still = $fx->conn->query('SELECT ID FROM authors WHERE ID = ' . (int)$a->uid)->fetch_row() !== null;
        return !$still;
    })->allow();

    $r->add('Users — xóa', 'Thiếu CSRF thì không xóa được', function ($a, $fx) {
        $id = $fx->new_user((int)$a->team);
        $_POST = ['ids' => [$id]];
        Users::delete_users();
        return $fx->conn->query("SELECT ID FROM authors WHERE ID = $id")->fetch_row() === null;
    })->allow();

    // ---------- Lớp giao diện ----------
    $r->add('Users — giao diện', 'Trang danh sách hiện ra',
        fn($a, $fx) => ab_render('app-user-list.php') !== ''
    )->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'USR_T1', 'USR_T1_V', 'MGR_T2', 'USR_T2', 'USR_T3');

    $r->add('Users — giao diện', 'Có cột Salary trong bảng',
        fn($a, $fx) => ab_has(ab_render('app-user-list.php'), '<th>Salary</th>')
    )->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'MGR_T2');

    $r->add('Users — giao diện', 'Có ô lọc theo Team (chỉ admin)',
        fn($a, $fx) => ab_has(ab_render('app-user-list.php'), 'user_team')
    )->allow('ADMIN');

    $r->add('Users — giao diện', 'Modal xóa chỉ render cho admin',
        fn($a, $fx) => ab_has(ab_render('app-user-list.php'), 'deleteUserModal')
    )->allow('ADMIN');
};
