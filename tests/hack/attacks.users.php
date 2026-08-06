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

    // Luật CẤP (chốt 06/08/2026): user THẤY đồng nghiệp ngang cấp cùng team là ĐÚNG.
    // Điều phải chặn là NHÌN LÊN TRÊN — thấy tài khoản cấp cao hơn.
    $h->attack('Rò rỉ dữ liệu', 'USER thường moi được tài khoản ADMIN cùng team', 'USR_VIEW', function ($atk, $fx) {
        $adm = $fx->new_user((int)$atk->team, $fx->admin_level());
        $_POST = ab_dt(['columns' => [['data' => 'username']]]);
        return ['breach' => ab_sees(Users::get_users(), $adm),
                'note' => 'Danh sách lộ tài khoản admin -> lộ luôn mục tiêu tấn công'];
    });

    $h->attack('Rò rỉ dữ liệu', 'USER thường moi được tài khoản MANAGER cùng team', 'USR_VIEW', function ($atk, $fx) {
        $mgr = $fx->new_user((int)$atk->team, $fx->level_id('manager'));
        $_POST = ab_dt(['columns' => [['data' => 'username']]]);
        return ['breach' => ab_sees(Users::get_users(), $mgr),
                'note' => 'Cấp user không được nhìn lên cấp manager'];
    });

    $h->attack('Rò rỉ dữ liệu', 'Lọc theo team khác để moi user team đó', 'USR_OUT', function ($atk, $fx) {
        $id = $fx->new_user(1);
        $_POST = ab_dt(['columns' => [['data' => 'username']]]);
        $_POST['team'] = 1;
        return ['breach' => ab_sees(Users::get_users(), $id), 'note' => 'Bộ lọc team vượt được phạm vi'];
    });

    // ---------- IDOR ----------
    // Manager ĐƯỢC xóa người cùng team (luật chốt 05/08/2026) nên đòn phải nhắm ra
    // NGOÀI team mới đo đúng ranh giới.
    $h->attack('IDOR (xóa)', 'Manager xóa tài khoản của TEAM KHÁC', 'MGR_OUT', function ($atk, $fx) {
        $id = $fx->new_user(1);   // team 1, attacker là manager team 2
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'ids' => [$id]];
        Users::delete_users();
        return ['breach' => hack_count($fx->conn, "SELECT COUNT(*) FROM authors WHERE ID = $id") === 0,
            'note' => 'Xóa được user của team khác'];
    });

    $h->attack('IDOR (xóa)', 'Manager xóa tài khoản ADMIN cùng team', 'MGR_OUT', function ($atk, $fx) {
        $id = $fx->new_user((int)$atk->team, $fx->admin_level());
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'ids' => [$id]];
        Users::delete_users();
        return ['breach' => hack_count($fx->conn, "SELECT COUNT(*) FROM authors WHERE ID = $id") === 0,
            'note' => 'Manager xóa được tài khoản admin -> hạ gục quản trị viên'];
    }, 'NGHIÊM TRỌNG');

    $h->attack('IDOR (xóa)', 'Trộn ID team khác vào lô xóa', 'MGR_OUT', function ($atk, $fx) {
        $mine  = $fx->new_user((int)$atk->team);
        $other = $fx->new_user(1);
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'ids' => [$mine, $other]];
        Users::delete_users();
        return ['breach' => hack_count($fx->conn, "SELECT COUNT(*) FROM authors WHERE ID = $other") === 0,
            'note' => 'Lô hỗn hợp xóa lan sang user team khác'];
    });

    $h->attack('Leo thang quyền', 'User chỉ-view xóa người cùng team', 'USR_VIEW', function ($atk, $fx) {
        $id = $fx->new_user((int)$atk->team);
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'ids' => [$id]];
        Users::delete_users();
        return ['breach' => hack_count($fx->conn, "SELECT COUNT(*) FROM authors WHERE ID = $id") === 0,
            'note' => 'Chỉ có view mà xóa được user'];
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

    // ---------- Avatar ----------
    // Ảnh do người dùng tải lên: đường dẫn phải bị whitelist khi LƯU, nếu không kẻ tấn
    // công trỏ avatar vào file bất kỳ (config.php) hoặc URL ngoài để nhúng nội dung lạ.
    $h->attack('Avatar', 'Nhét đường dẫn tùy ý vào cột avatar', 'MGR_OUT', function ($atk, $fx) {
        $id = $fx->new_user((int)$atk->team);
        $row = $fx->conn->query("SELECT username, email, level FROM authors WHERE ID = $id")->fetch_assoc();
        $bad = 0;
        foreach (['uploads/avatars/../../config.php', '../../config.php', 'http://evil.test/x.png',
                  '/etc/passwd', "uploads/avatars/x.png\0.php"] as $payload) {
            $_POST = ['csrf_token' => 'VICTIM_SECRET', 'id' => $id, 'username' => $row['username'],
                'email' => $row['email'], 'password' => '', 'level' => (int)$row['level'],
                'team_id' => (int)$atk->team, 'status' => 2, 'avatar' => $payload];
            User::save_user();
            $now = (string)($fx->conn->query("SELECT IFNULL(avatar,'') FROM authors WHERE ID = $id")->fetch_row()[0] ?? '');
            if ($now === $payload) {
                $bad++;
            }
        }
        return ['breach' => $bad > 0, 'note' => "$bad/5 payload ghi được vào cột avatar"];
    }, 'NGHIÊM TRỌNG');

    $h->attack('Avatar', 'valid_avatar() nhận đường dẫn ngoài uploads/avatars', 'MGR_OUT',
        function ($atk, $fx) {
            $bad = [];
            foreach (['uploads/sites/a.png', 'uploads/avatars/../x.png', 'avatars/a.png',
                      'uploads/avatars/a.php', 'uploads/avatars/a.png.php'] as $p) {
                if (User::valid_avatar($p)) {
                    $bad[] = $p;
                }
            }
            return ['breach' => !empty($bad), 'note' => 'Chấp nhận nhầm: ' . implode(', ', $bad)];
        });

    $h->attack('Avatar', 'Upload avatar cho user ngoài phạm vi', 'MGR_OUT', function ($atk, $fx) {
        $id = $fx->new_user(1);   // team 1, attacker manager team 2
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'id' => $id];
        $_FILES = [];
        $res = User::upload_avatar();
        // Không có file nên không thể 'success'; đo đúng thứ tự chặn: quyền phải bị từ
        // chối TRƯỚC khi tới bước đọc file
        return ['breach' => ($res['message'] ?? '') !== 'You do not have permission to edit this user.',
            'note' => 'Thông báo: ' . ($res['message'] ?? '?')];
    });

    $h->attack('Avatar', 'Ảnh render trong bảng không bọc esc()', 'MGR_OUT', function ($atk, $fx) {
        $js = (string)file_get_contents(AB_ROOT . '/assets/js/app-user-list.js');
        return ['breach' => !str_contains($js, 'esc(avatarUrl(full[\'avatar\']))'),
            'note' => 'src ảnh chưa escape -> thoát được thuộc tính HTML'];
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

    // ---------- Chiếm quyền SUPER ADMIN ----------
    // Kẻ tấn công ở đây là ADMIN THẬT, chỉ thiếu đúng một thứ: không nằm trong SUPER_ADMINS.
    // Mọi nút trên UI đã trong tay, nên đích duy nhất còn lại là leo lên cấp tối cao.
    $h->attack('Super admin', 'Admin thường sửa tài khoản admin khác (IDOR thẳng vào endpoint)',
        'ADM_PLAIN', function ($atk, $fx) {
            $id  = $fx->new_user((int)$atk->team, $fx->admin_level());
            $row = $fx->conn->query("SELECT username, email FROM authors WHERE ID = $id")->fetch_assoc();
            $_POST = ['csrf_token' => $_SESSION['csrf_token'], 'id' => $id,
                'username' => $row['username'], 'email' => $row['email'], 'password' => '',
                'level' => $fx->admin_level(), 'team_id' => (int)$atk->team, 'status' => 3];
            User::save_user();
            $now = (int)$fx->conn->query("SELECT status FROM authors WHERE ID = $id")->fetch_row()[0];
            return ['breach' => $now === 3, 'note' => 'Sửa được tài khoản ngang cấp admin'];
        }, 'NGHIÊM TRỌNG');

    $h->attack('Super admin', 'Đổi tên một tài khoản thành fox1990 để chiếm danh tính tối cao',
        'ADM_PLAIN', function ($atk, $fx) {
            // Không đụng fox1990 thật: dựng user ZZAB rồi thử đổi TÊN NÓ thành fox1990.
            $id   = $fx->new_user((int)$atk->team);
            $ten  = SUPER_ADMINS[0];
            $_POST = ['csrf_token' => $_SESSION['csrf_token'], 'id' => $id, 'username' => $ten,
                'email' => 'zzab_' . bin2hex(random_bytes(3)) . '@zzab.test', 'password' => '',
                'level' => (int)$fx->conn->query("SELECT level FROM authors WHERE ID = $id")->fetch_row()[0],
                'team_id' => (int)$atk->team, 'status' => 2];
            User::save_user();
            $now = (string)$fx->conn->query("SELECT username FROM authors WHERE ID = $id")->fetch_row()[0];
            // Trùng tên đăng nhập phải bị chặn — nếu lọt thì có HAI fox1990, và
            // is_super_admin() so theo tên sẽ trao quyền tối cao cho kẻ tấn công.
            return ['breach' => strtolower($now) === strtolower($ten),
                'note' => 'Tên sau khi thử đổi: ' . $now];
        }, 'NGHIÊM TRỌNG');

    $h->attack('Super admin', 'Tạo tài khoản mới mang tên fox1990', 'ADM_PLAIN', function ($atk, $fx) {
        $ten = SUPER_ADMINS[0];
        $_POST = ['csrf_token' => $_SESSION['csrf_token'], 'id' => 0, 'username' => $ten,
            'email' => 'zzab_' . bin2hex(random_bytes(3)) . '@zzab.test', 'password' => 'zzabzzab',
            'level' => $fx->admin_level(), 'team_id' => (int)$atk->team, 'status' => 2];
        $res = User::save_user();
        $n = (int)$fx->conn->execute_query(
            'SELECT COUNT(*) FROM authors WHERE username = ?', [$ten])->fetch_row()[0];
        if ($n > 1 && !empty($res['id'])) {   // dọn ngay nếu lỡ tạo được
            $fx->conn->query('DELETE FROM authors WHERE ID = ' . (int)$res['id']);
        }
        return ['breach' => $n > 1, 'note' => 'Số tài khoản mang tên ' . $ten . ': ' . $n];
    }, 'NGHIÊM TRỌNG');

    $h->attack('Super admin', 'Nhồi tham số để tự nhận là super admin', 'ADM_PLAIN', function ($atk, $fx) {
        // is_super_admin() chỉ được đọc SESSION. Nếu nó lỡ đọc $_POST/$_GET/$_REQUEST thì
        // kẻ tấn công tự phong mình chỉ bằng một tham số.
        $_POST['super'] = 1;      $_POST['is_super_admin'] = 1;
        $_GET['user']   = SUPER_ADMINS[0];
        $_REQUEST['user'] = SUPER_ADMINS[0];
        return ['breach' => is_super_admin(), 'note' => 'is_super_admin() nghe theo tham số request'];
    }, 'NGHIÊM TRỌNG');

    $h->attack('Super admin', 'Hạ cấp role Admin để vô hiệu hóa cấp tối cao', 'ADM_PLAIN',
        function ($atk, $fx) {
            $adm = (int)$fx->conn->query("SELECT ID FROM roles_permissions WHERE slug='admin' LIMIT 1")->fetch_row()[0];
            $_POST = ['csrf_token' => $_SESSION['csrf_token'], 'id' => $adm, 'role_name' => 'Admin',
                'level' => 'user', 'permissions' => [], 'csrf_token' => $_SESSION['csrf_token']];
            Role::save_role();
            $slug = (string)$fx->conn->execute_query(
                'SELECT slug FROM roles_permissions WHERE ID = ?', [$adm])->fetch_row()[0];
            if ($slug !== 'admin') {   // khôi phục ngay nếu thủng — đây là role Admin THẬT
                $fx->conn->execute_query("UPDATE roles_permissions SET slug='admin' WHERE ID = ?", [$adm]);
            }
            return ['breach' => $slug !== 'admin', 'note' => 'slug sau đòn: ' . $slug];
        }, 'NGHIÊM TRỌNG');
};
