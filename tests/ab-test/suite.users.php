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

    // ---------- Đổi quyền / trạng thái ----------
    // Thu hồi quyền phải có hiệu lực NGAY, không đợi người dùng tự đăng xuất
    /**
     * Đặt session khớp ĐÚNG dữ liệu thật trong DB. Actor mặc định là danh tính tổng hợp
     * (bộ role tự dựng) nên nếu không đồng bộ trước, phép thử sẽ "đạt" chỉ vì lệch sẵn
     * chứ không phải vì việc đổi quyền — đo sai điều cần đo.
     */
    $syncSession = function ($uid, $fx) {
        $row = $fx->conn->execute_query(
            'SELECT rl.slug, rl.roles FROM authors a
             LEFT JOIN roles_permissions rl ON rl.ID = a.level WHERE a.ID = ? LIMIT 1', [$uid])->fetch_assoc();
        $_SESSION['auth']['level'] = (string)($row['slug'] ?? '');
        $_SESSION['auth']['roles'] = (array)json_decode((string)($row['roles'] ?? '[]'), true);
        reset_access_cache();
    };

    $r->add('Users — quyền', 'Phiên bị đá ra khi NHÓM QUYỀN bị đổi', function ($a, $fx) use ($syncSession) {
        $syncSession((int)$a->uid, $fx);
        if (current_team_blocked()) {
            return ['status' => 'error', 'message' => 'Đã bị chặn từ trước khi đổi -> phép thử vô nghĩa'];
        }
        $orig = (int)$fx->conn->query('SELECT level FROM authors WHERE ID = ' . (int)$a->uid)->fetch_row()[0];
        $other = (int)$fx->conn->query(
            "SELECT ID FROM roles_permissions WHERE ID <> $orig ORDER BY ID LIMIT 1")->fetch_row()[0];
        $fx->conn->query("UPDATE authors SET level = $other WHERE ID = " . (int)$a->uid);
        reset_access_cache();
        $blocked = current_team_blocked();
        $fx->conn->query("UPDATE authors SET level = $orig WHERE ID = " . (int)$a->uid);
        reset_access_cache();
        // ALLOW = KHÔNG bị chặn -> không ai được phép giữ quyền cũ
        return !$blocked;
    })->allow();

    $r->add('Users — quyền', 'Phiên bị đá ra khi NỘI DUNG nhóm quyền bị sửa', function ($a, $fx) use ($syncSession) {
        $syncSession((int)$a->uid, $fx);
        if (current_team_blocked()) {
            return ['status' => 'error', 'message' => 'Đã bị chặn từ trước khi sửa -> phép thử vô nghĩa'];
        }
        $lvl = (int)$fx->conn->query('SELECT level FROM authors WHERE ID = ' . (int)$a->uid)->fetch_row()[0];
        $before = (string)($fx->conn->query("SELECT roles FROM roles_permissions WHERE ID = $lvl")->fetch_row()[0] ?? '{}');
        $fx->conn->execute_query('UPDATE roles_permissions SET roles = ? WHERE ID = ?',
            ['{"zzab_changed":{"view":"on"}}', $lvl]);
        reset_access_cache();
        $blocked = current_team_blocked();
        $fx->conn->execute_query('UPDATE roles_permissions SET roles = ? WHERE ID = ?', [$before, $lvl]);
        reset_access_cache();
        return !$blocked;
    })->allow();

    $r->add('Users — quyền', 'KHÔNG tự đổi trạng thái của chính mình', function ($a, $fx) {
        $row = $fx->conn->query(
            'SELECT username, email, level, team_id, status FROM authors WHERE ID = ' . (int)$a->uid)->fetch_assoc();
        $_POST = ['csrf_token' => 'ABTEST', 'id' => (int)$a->uid, 'username' => $row['username'],
            'email' => $row['email'], 'password' => '', 'level' => (int)$row['level'],
            'team_id' => (int)$row['team_id'], 'status' => 3];
        User::save_user();
        $now = (int)$fx->conn->query('SELECT status FROM authors WHERE ID = ' . (int)$a->uid)->fetch_row()[0];
        if ($now !== (int)$row['status']) {
            $fx->conn->query("UPDATE authors SET status = {$row['status']} WHERE ID = " . (int)$a->uid);
        }
        // ALLOW = tự đổi được trạng thái -> không ai được phép
        return $now === 3;
    })->allow();

    // ---------- Chuyển team ----------
    $r->add('Users — chuyển team', 'Xem trước hệ quả khi chuyển team', function ($a, $fx) {
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $fx->new_user(1), 'team_id' => 2];
        $res = Users::get_move_preview();
        return ($res['status'] ?? '') === 'error' ? $res : isset($res['counts']['products']);
    })->allow('ADMIN');

    $r->add('Users — chuyển team', 'Chuyển user sang team khác', function ($a, $fx) {
        $id = $fx->new_user(1);
        $row = $fx->conn->query("SELECT username, email, level FROM authors WHERE ID = $id")->fetch_assoc();
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id, 'username' => $row['username'],
            'email' => $row['email'], 'password' => '', 'level' => (int)$row['level'],
            'team_id' => 2, 'status' => 2];
        $res = User::save_user();
        if (($res['status'] ?? '') === 'error') {
            return $res;
        }
        $now = (int)$fx->conn->query("SELECT team_id FROM authors WHERE ID = $id")->fetch_row()[0];
        return $now === 2 ? $res : ['status' => 'error', 'message' => 'team_id vẫn là ' . $now];
    })->allow('ADMIN');

    // Chuyển team phải gỡ ràng buộc chỉ đúng với team CŨ, nếu không sản phẩm trỏ store
    // riêng team cũ sẽ không sửa được nữa ("store does not belong to the owner's team")
    $r->add('Users — chuyển team', 'Gỡ liên kết account + store riêng của team cũ', function ($a, $fx) {
        $id = $fx->new_user(1);
        $acc = $fx->new_account(1, $id);
        $store = $fx->new_store(1);
        $post = $fx->new_post($id, 'ZZAB-P-MOVE');
        $fx->conn->query("UPDATE posts SET store_id = $store WHERE ID = $post");

        $row = $fx->conn->query("SELECT username, email, level FROM authors WHERE ID = $id")->fetch_assoc();
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id, 'username' => $row['username'],
            'email' => $row['email'], 'password' => '', 'level' => (int)$row['level'],
            'team_id' => 2, 'status' => 2];
        $res = User::save_user();
        if (($res['status'] ?? '') === 'error') {
            return $res;
        }
        $links = (int)$fx->conn->query(
            "SELECT COUNT(*) FROM accounts_authors WHERE author_id = $id AND account_id = $acc")->fetch_row()[0];
        $storeId = (int)$fx->conn->query("SELECT store_id FROM posts WHERE ID = $post")->fetch_row()[0];
        return ($links === 0 && $storeId === 0)
            ? $res : ['status' => 'error', 'message' => "links=$links store_id=$storeId"];
    })->allow('ADMIN');

    // Team đích phải KHÁC team của actor, nếu không "chuyển" là không đổi gì và phép thử
    // tự đạt một cách giả tạo (USR_T3 vốn đã ở team 3).
    $r->add('Users — chuyển team', 'Non-admin đổi được team của người khác', function ($a, $fx) {
        $target = (int)$a->team === 3 ? 2 : 3;
        $id = $fx->new_user((int)$a->team);
        $row = $fx->conn->query("SELECT username, email, level FROM authors WHERE ID = $id")->fetch_assoc();
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id, 'username' => $row['username'],
            'email' => $row['email'], 'password' => '', 'level' => (int)$row['level'],
            'team_id' => $target, 'status' => 2];
        User::save_user();
        $now = (int)$fx->conn->query("SELECT team_id FROM authors WHERE ID = $id")->fetch_row()[0];
        // ALLOW = team bị đổi thật -> chỉ admin mới được phép
        return $now === $target;
    })->allow('ADMIN');

    $r->add('Users — chuyển team', 'Phiên bị đá ra khi team trong DB đổi', function ($a, $fx) {
        $team = (int)$a->team;
        if ($team <= 0) {
            return false;
        }
        $target = $team === 3 ? 2 : 3;
        $fx->conn->query("UPDATE authors SET team_id = $target WHERE ID = " . (int)$a->uid);
        $blocked = current_team_blocked();
        $fx->conn->query("UPDATE authors SET team_id = $team WHERE ID = " . (int)$a->uid);
        // ALLOW = KHÔNG bị chặn -> không ai được phép (kể cả admin)
        return !$blocked;
    })->allow();

    // ---------- Xóa ----------
    $r->add('Users — xóa', 'Xóa user cùng team (không có dữ liệu)', function ($a, $fx) {
        $id = $fx->new_user((int)$a->team);
        $_POST = ['csrf_token' => 'ABTEST', 'ids' => [$id]];
        $res = Users::delete_users();
        $gone = $fx->conn->query("SELECT ID FROM authors WHERE ID = $id")->fetch_row() === null;
        return $gone ? $res : ['status' => 'error', 'message' => 'User vẫn còn'];
    })->allow('ADMIN', 'MGR_T1', 'MGR_T2');

    $r->add('Users — xóa', 'Xóa user của TEAM 2', function ($a, $fx) {
        $id = $fx->new_user(2);
        $_POST = ['csrf_token' => 'ABTEST', 'ids' => [$id]];
        $res = Users::delete_users();
        $gone = $fx->conn->query("SELECT ID FROM authors WHERE ID = $id")->fetch_row() === null;
        return $gone ? $res : ['status' => 'error', 'message' => 'User vẫn còn'];
    })->allow('ADMIN', 'MGR_T2');

    // Manager không được xóa tài khoản admin ngay cả khi cùng team (chặn leo ngang)
    $r->add('Users — xóa', 'Xóa tài khoản ADMIN cùng team', function ($a, $fx) {
        $id = $fx->new_user((int)$a->team, $fx->admin_level());
        $_POST = ['csrf_token' => 'ABTEST', 'ids' => [$id]];
        Users::delete_users();
        return $fx->conn->query("SELECT ID FROM authors WHERE ID = $id")->fetch_row() === null;
    })->allow('ADMIN');

    $r->add('Users — xóa', 'CHẶN xóa user còn sản phẩm khi KHÔNG bàn giao', function ($a, $fx) {
        $id = $fx->new_user((int)$a->team);
        $fx->new_post($id, 'ZZAB-P-OWN');
        $_POST = ['csrf_token' => 'ABTEST', 'ids' => [$id]];   // thiếu transfer_to
        Users::delete_users();
        // ALLOW = xóa được dù còn sản phẩm và chưa bàn giao -> không ai được phép
        return $fx->conn->query("SELECT ID FROM authors WHERE ID = $id")->fetch_row() === null;
    })->allow();

    $r->add('Users — xóa', 'Bàn giao sản phẩm rồi xóa', function ($a, $fx) {
        $id   = $fx->new_user((int)$a->team);
        $heir = $fx->new_user((int)$a->team);
        $post = $fx->new_post($id, 'ZZAB-P-HAND');
        $_POST = ['csrf_token' => 'ABTEST', 'ids' => [$id], 'transfer_to' => $heir];
        $res = Users::delete_users();
        if (($res['status'] ?? '') === 'error') {
            return $res;
        }
        $owner = (int)$fx->conn->query("SELECT author_id FROM posts WHERE ID = $post")->fetch_row()[0];
        $gone  = $fx->conn->query("SELECT ID FROM authors WHERE ID = $id")->fetch_row() === null;
        return ($gone && $owner === $heir)
            ? $res : ['status' => 'error', 'message' => "gone=" . var_export($gone, true) . " owner=$owner"];
    })->allow('ADMIN', 'MGR_T1', 'MGR_T2');

    // transfer_to = 'none' -> không bàn giao, xóa luôn sản phẩm kèm bảng con
    $r->add('Users — xóa', 'Chọn None thì xóa luôn sản phẩm', function ($a, $fx) {
        $id = $fx->new_user((int)$a->team);
        $post = $fx->new_post($id, 'ZZAB-P-NONE');
        $_POST = ['csrf_token' => 'ABTEST', 'ids' => [$id], 'transfer_to' => 'none'];
        $res = Users::delete_users();
        if (($res['status'] ?? '') === 'error') {
            return $res;
        }
        $userGone = $fx->conn->query("SELECT ID FROM authors WHERE ID = $id")->fetch_row() === null;
        $postGone = $fx->conn->query("SELECT ID FROM posts WHERE ID = $post")->fetch_row() === null;
        return ($userGone && $postGone)
            ? $res : ['status' => 'error', 'message' => 'user=' . var_export($userGone, true)
                . ' post=' . var_export($postGone, true)];
    })->allow('ADMIN', 'MGR_T1', 'MGR_T2');

    // 'none' phải là lựa chọn CÓ Ý THỨC — gửi rỗng/0 vẫn phải bị chặn
    $r->add('Users — xóa', 'transfer_to rỗng vẫn bị chặn (None phải chọn rõ)', function ($a, $fx) {
        $id = $fx->new_user((int)$a->team);
        $fx->new_post($id, 'ZZAB-P-EMPTY');
        $_POST = ['csrf_token' => 'ABTEST', 'ids' => [$id], 'transfer_to' => ''];
        Users::delete_users();
        return $fx->conn->query("SELECT ID FROM authors WHERE ID = $id")->fetch_row() === null;
    })->allow();

    $r->add('Users — xóa', 'Người nhận bàn giao KHÁC TEAM bị từ chối', function ($a, $fx) {
        $team  = (int)$a->team;
        $other = $team === 3 ? 2 : 3;
        $id    = $fx->new_user($team);
        $heir  = $fx->new_user($other);
        $fx->new_post($id, 'ZZAB-P-CROSS');
        $_POST = ['csrf_token' => 'ABTEST', 'ids' => [$id], 'transfer_to' => $heir];
        Users::delete_users();
        // ALLOW = xóa được dù người nhận khác team -> không ai được phép
        return $fx->conn->query("SELECT ID FROM authors WHERE ID = $id")->fetch_row() === null;
    })->allow();

    $r->add('Users — xóa', 'Liên kết account KHÔNG chặn xóa (tự gỡ)', function ($a, $fx) {
        $id  = $fx->new_user((int)$a->team);
        $acc = $fx->new_account((int)$a->team, $id);
        $_POST = ['csrf_token' => 'ABTEST', 'ids' => [$id]];
        $res = Users::delete_users();
        if (($res['status'] ?? '') === 'error') {
            return $res;
        }
        $links = (int)$fx->conn->query(
            "SELECT COUNT(*) FROM accounts_authors WHERE author_id = $id")->fetch_row()[0];
        return $links === 0 ? $res : ['status' => 'error', 'message' => "còn $links liên kết"];
    })->allow('ADMIN', 'MGR_T1', 'MGR_T2');

    $r->add('Users — xóa', 'Xem trước dữ liệu + danh sách người nhận', function ($a, $fx) {
        $id = $fx->new_user((int)$a->team);
        $fx->new_user((int)$a->team);
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id];
        $res = Users::get_delete_preview();
        return ($res['status'] ?? '') === 'error' ? $res : !empty($res['candidates']);
    })->allow('ADMIN', 'MGR_T1', 'MGR_T2');

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

    // Modal xóa render cho ai còn xóa được ai: admin và manager có role delete
    $r->add('Users — giao diện', 'Modal xóa được render',
        fn($a, $fx) => ab_has(ab_render('app-user-list.php'), 'deleteUserModal')
    )->allow('ADMIN', 'MGR_T1', 'MGR_T2');
};
