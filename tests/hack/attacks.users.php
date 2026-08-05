<?php
/**
 * HACK — Users / User. Đây là màn hình nhạy cảm nhất về LEO THANG QUYỀN: ai sửa được
 * `level` là chiếm được toàn hệ thống. Trọng tâm các đòn:
 *   - tự nâng quyền / nâng quyền cho đồng phạm lên admin;
 *   - đọc trộm danh sách user + email + LƯƠNG của team khác;
 *   - sửa/xóa tài khoản ngoài phạm vi (IDOR);
 *   - đổi mật khẩu người khác để chiếm tài khoản.
 *
 * AN TOÀN: mọi đòn ghi/xóa đều nhắm vào user ZZAB do fixture tạo, không đụng tài khoản thật.
 */

return function (HackRunner $h): void {

    // ---------- Auth bypass ----------
    $h->attack('Auth bypass', 'Khách chưa đăng nhập đọc danh sách user', 'ANON', function ($atk, $fx) {
        $_POST = ab_dt(['columns' => [['data' => 'username']]]);
        $res = Users::get_users();
        return ['breach' => !empty($res['data']), 'note' => count($res['data']) . ' user lộ ra'];
    });

    $h->attack('Auth bypass', 'Khách chưa đăng nhập tạo tài khoản', 'ANON', function ($atk, $fx) {
        $u = 'ZZABFIXANON' . bin2hex(random_bytes(3));
        $_POST = ['csrf_token' => 'VICTIM', 'id' => 0, 'username' => $u, 'email' => $u . '@zzab.test',
            'password' => 'zzabzzab', 'level' => $fx->admin_level(), 'team_id' => 1, 'status' => 2];
        User::save_user();
        return ['breach' => hack_count($fx->conn, "SELECT COUNT(*) FROM authors WHERE username = '$u'") > 0,
            'note' => 'Khách vãng lai tạo được tài khoản'];
    }, 'NGHIÊM TRỌNG');

    // ---------- Leo thang quyền ----------
    $h->attack('Leo thang quyền', 'Manager tạo tài khoản cấp ADMIN', 'MGR_OUT', function ($atk, $fx) {
        $u = 'ZZABFIXESC' . bin2hex(random_bytes(3));
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'id' => 0, 'username' => $u, 'email' => $u . '@zzab.test',
            'password' => 'zzabzzab', 'level' => $fx->admin_level(), 'team_id' => (int)$atk->team, 'status' => 2];
        User::save_user();
        return ['breach' => hack_count($fx->conn, "SELECT COUNT(*) FROM authors WHERE username = '$u'") > 0,
            'note' => 'Manager tự tạo được admin mới'];
    }, 'NGHIÊM TRỌNG');

    $h->attack('Leo thang quyền', 'Manager nâng user cùng team lên ADMIN', 'MGR_OUT', function ($atk, $fx) {
        $id = $fx->new_user((int)$atk->team);
        $row = $fx->conn->query("SELECT username, email FROM authors WHERE ID = $id")->fetch_assoc();
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'id' => $id, 'username' => $row['username'],
            'email' => $row['email'], 'password' => '', 'level' => $fx->admin_level(),
            'team_id' => (int)$atk->team, 'status' => 2];
        User::save_user();
        $now = (int)$fx->conn->query("SELECT level FROM authors WHERE ID = $id")->fetch_row()[0];
        return ['breach' => $now === $fx->admin_level(), 'note' => 'level sau đòn = ' . $now];
    }, 'NGHIÊM TRỌNG');

    $h->attack('Leo thang quyền', 'User chỉ-view tạo tài khoản mới', 'USR_VIEW', function ($atk, $fx) {
        $u = 'ZZABFIXVIEW' . bin2hex(random_bytes(3));
        $lvl = (int)$fx->conn->query("SELECT ID FROM roles_permissions WHERE slug <> 'admin' ORDER BY ID LIMIT 1")->fetch_row()[0];
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'id' => 0, 'username' => $u, 'email' => $u . '@zzab.test',
            'password' => 'zzabzzab', 'level' => $lvl, 'team_id' => (int)$atk->team, 'status' => 2];
        User::save_user();
        return ['breach' => hack_count($fx->conn, "SELECT COUNT(*) FROM authors WHERE username = '$u'") > 0,
            'note' => 'Chỉ có view mà tạo được user'];
    });

    $h->attack('Leo thang quyền', 'Sửa tài khoản ADMIN của người khác', 'MGR_OUT', function ($atk, $fx) {
        $id = $fx->new_user((int)$atk->team, $fx->admin_level());
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'id' => $id, 'username' => 'ZZABFIXHACKED',
            'email' => 'zzabfixhacked@zzab.test', 'password' => 'newpass123',
            'level' => $fx->admin_level(), 'team_id' => (int)$atk->team, 'status' => 2];
        User::save_user();
        $now = (string)$fx->conn->query("SELECT username FROM authors WHERE ID = $id")->fetch_row()[0];
        return ['breach' => $now === 'ZZABFIXHACKED', 'note' => 'Sửa được tài khoản admin'];
    }, 'NGHIÊM TRỌNG');

    // ---------- Chiếm tài khoản ----------
    $h->attack('Chiếm tài khoản', 'Đổi mật khẩu user của TEAM KHÁC', 'USR_OUT', function ($atk, $fx) {
        $id = $fx->new_user(1);   // user team 1, attacker ở team 3
        $before = (string)$fx->conn->query("SELECT pass FROM authors WHERE ID = $id")->fetch_row()[0];
        $row = $fx->conn->query("SELECT username, email, level FROM authors WHERE ID = $id")->fetch_assoc();
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'id' => $id, 'username' => $row['username'],
            'email' => $row['email'], 'password' => 'takeover123', 'level' => (int)$row['level'],
            'team_id' => 1, 'status' => 2];
        User::save_user();
        $after = (string)$fx->conn->query("SELECT pass FROM authors WHERE ID = $id")->fetch_row()[0];
        return ['breach' => $after !== $before, 'note' => 'Đổi được mật khẩu người team khác'];
    }, 'NGHIÊM TRỌNG');

    $h->attack('Chiếm tài khoản', 'Đổi email user khác (để cướp qua quên mật khẩu)', 'MGR_OUT',
        function ($atk, $fx) {
            $id = $fx->new_user(1);   // team 1, attacker manager team 2
            $row = $fx->conn->query("SELECT username, level FROM authors WHERE ID = $id")->fetch_assoc();
            $_POST = ['csrf_token' => 'VICTIM_SECRET', 'id' => $id, 'username' => $row['username'],
                'email' => 'attacker@zzab.test', 'password' => '', 'level' => (int)$row['level'],
                'team_id' => 1, 'status' => 2];
            User::save_user();
            $now = (string)$fx->conn->query("SELECT email FROM authors WHERE ID = $id")->fetch_row()[0];
            return ['breach' => $now === 'attacker@zzab.test', 'note' => 'Email sau đòn: ' . $now];
        }, 'NGHIÊM TRỌNG');

    // ---------- Rò rỉ dữ liệu ----------
    $h->attack('Rò rỉ dữ liệu', 'USER thường đọc được LƯƠNG', 'USR_VIEW', function ($atk, $fx) {
        $_POST = ab_dt(['columns' => [['data' => 'username']]]);
        $rows = Users::get_users()['data'] ?? [];
        foreach ($rows as $row) {
            if (array_key_exists('wage', $row)) {
                return ['breach' => true, 'note' => 'Endpoint trả field wage cho level user'];
            }
        }
        return ['breach' => false];
    });

    $h->attack('Rò rỉ dữ liệu', 'USER thường thấy đồng đội cùng team', 'USR_VIEW', function ($atk, $fx) {
        $fx->new_user((int)$atk->team);
        $_POST = ab_dt(['columns' => [['data' => 'username']]]);
        $rows = Users::get_users()['data'] ?? [];
        return ['breach' => count($rows) > 1, 'note' => 'Thấy ' . count($rows) . ' dòng (phải đúng 1)'];
    });

    $h->attack('Rò rỉ dữ liệu', 'Lọc theo team khác để moi user team đó', 'USR_OUT', function ($atk, $fx) {
        $id = $fx->new_user(1);
        $_POST = ab_dt(['columns' => [['data' => 'username']]]);
        $_POST['team'] = 1;
        return ['breach' => ab_sees(Users::get_users(), $id), 'note' => 'Bộ lọc team vượt được phạm vi'];
    });

    // ---------- IDOR ----------
    $h->attack('IDOR (xóa)', 'Non-admin xóa tài khoản người khác', 'MGR_OUT', function ($atk, $fx) {
        $id = $fx->new_user((int)$atk->team);
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'ids' => [$id]];
        Users::delete_users();
        return ['breach' => hack_count($fx->conn, "SELECT COUNT(*) FROM authors WHERE ID = $id") === 0,
            'note' => 'Non-admin xóa được user'];
    });

    // ---------- SQL injection ----------
    $h->attack('SQL injection', 'Payload trong ô tìm kiếm user', 'USR_OUT', function ($atk, $fx) {
        $_POST = ab_dt(['columns' => [['data' => 'username']], 'search' => ['value' => "zzab' OR '1'='1"]]);
        $res = Users::get_users();
        return ['breach' => ($res['recordsFiltered'] ?? 0) > 1, 'note' => 'filtered = ' . ($res['recordsFiltered'] ?? 0)];
    });

    $h->attack('SQL injection', 'Payload trong bộ lọc level/status', 'USR_OUT', function ($atk, $fx) {
        $_POST = ab_dt(['columns' => [['data' => 'username']]]);
        $_POST['level'] = '1 OR 1=1';
        $_POST['status'] = '2 OR 1=1';
        $res = Users::get_users();
        return ['breach' => ($res['recordsFiltered'] ?? 0) > 1, 'note' => 'filtered = ' . ($res['recordsFiltered'] ?? 0)];
    });

    $h->attack('SQL injection', 'Payload trong ID khi xóa', 'MGR_OUT', function ($atk, $fx) {
        $before = hack_count($fx->conn, 'SELECT COUNT(*) FROM authors');
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'ids' => ['1 OR 1=1', '1); DELETE FROM authors;--']];
        Users::delete_users();
        $after = hack_count($fx->conn, 'SELECT COUNT(*) FROM authors');
        return ['breach' => $after < $before, 'note' => "Số user: $before -> $after"];
    });

    // ---------- CSRF ----------
    $h->attack('CSRF', 'Lưu user KHÔNG kèm csrf_token', 'MGR_OUT', function ($atk, $fx) {
        $u = 'ZZABFIXNOCSRF' . bin2hex(random_bytes(3));
        $_POST = ['id' => 0, 'username' => $u, 'email' => $u . '@zzab.test', 'password' => 'zzabzzab',
            'level' => 1, 'team_id' => (int)$atk->team, 'status' => 2];
        User::save_user();
        return ['breach' => hack_count($fx->conn, "SELECT COUNT(*) FROM authors WHERE username = '$u'") > 0,
            'note' => 'Tạo được user mà không cần token'];
    });

    // ---------- XSS ----------
    $h->attack('XSS (stored)', 'username/email không được escape khi render', 'MGR_OUT', function ($atk, $fx) {
        $js = (string)file_get_contents(AB_ROOT . '/assets/js/app-user-list.js');
        $ok = str_contains($js, 'function esc(')
            && str_contains($js, "esc(full['email'])")
            && str_contains($js, 'esc(name)');
        return ['breach' => !$ok, 'note' => 'JS render username/email KHÔNG bọc esc()'];
    });

    // ---------- Tài khoản bị khóa ----------
    $h->attack('Tài khoản bị khóa', 'User status Inactive vẫn dùng được phiên đang mở', 'MGR_OUT',
        function ($atk, $fx) {
            $before = (int)$fx->conn->query('SELECT status FROM authors WHERE ID = ' . (int)$atk->uid)->fetch_row()[0];
            $fx->conn->query('UPDATE authors SET status = 3 WHERE ID = ' . (int)$atk->uid);
            $blocked = current_team_blocked();
            $fx->conn->query("UPDATE authors SET status = $before WHERE ID = " . (int)$atk->uid);
            return ['breach' => !$blocked, 'note' => 'Tài khoản bị khóa vẫn chạy tiếp phiên'];
        }, 'NGHIÊM TRỌNG');

    $h->attack('Tài khoản bị khóa', 'Đăng nhập lại bằng tài khoản Inactive', 'MGR_OUT', function ($atk, $fx) {
        $before = (int)$fx->conn->query('SELECT status FROM authors WHERE ID = ' . (int)$atk->uid)->fetch_row()[0];
        $fx->conn->query('UPDATE authors SET status = 3 WHERE ID = ' . (int)$atk->uid);
        $u = get_user_data((int)$atk->uid);
        $fx->conn->query("UPDATE authors SET status = $before WHERE ID = " . (int)$atk->uid);
        // auth.php chặn khi user_status != 2
        return ['breach' => (int)($u['user_status'] ?? 2) === 2,
            'note' => 'user_status trả về: ' . var_export($u['user_status'] ?? null, true)];
    });
};
