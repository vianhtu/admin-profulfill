<?php
/**
 * AB TEST — trang Account (`?menu=account`).
 *
 * Trang này cố tình đi NGƯỢC luật chung ở đúng một điểm: hồ sơ của CHÍNH MÌNH thì ai cũng
 * xem/sửa được, KHÔNG đòi role nào — vì đây là đường duy nhất để đổi mật khẩu. Bộ này canh
 * cả hai vế:
 *   - vế MỞ  : mọi vai, kể cả NOROLE, phải xem và sửa được hồ sơ của mình;
 *   - vế CHẶT: hồ sơ NGƯỜI KHÁC phải theo đủ 3 trục như bảng Users (role view × team × cấp),
 *              và không đường ghi nào chạm được tới người khác.
 *
 * Mọi ca đụng tài khoản THẬT (các actor dùng author có thật) đều đọc lại DB rồi trả giá trị
 * cũ về ngay trong cùng phép thử.
 */

return function (AbRunner $r) {

    // ---------- Xem hồ sơ ----------
    $r->add('Account — xem', 'Xem hồ sơ của CHÍNH MÌNH', function ($a, $fx) {
        $_POST = ['id' => 0];
        $res = Account::get_profile();
        if (($res['status'] ?? '') !== 'success') {
            return $res;
        }
        // Phải đúng người đang đăng nhập, không phải ai khác
        return (int)$res['user']['id'] === (int)$a->uid && !empty($res['user']['is_me']);
    })->allow('ADMIN', 'SUPER', 'MGR_T1', 'MGR_T1_V', 'MGR_T2', 'USR_T1', 'USR_T1_V',
              'USR_T2', 'USR_T3', 'NOROLE');

    $r->add('Account — xem', 'API key CHỈ chủ tài khoản nhận được', function ($a, $fx) {
        $_POST = ['id' => 0];
        $res = Account::get_profile();
        return ($res['status'] ?? '') === 'success' && array_key_exists('api_key', $res['user']);
    })->allow('ADMIN', 'SUPER', 'MGR_T1', 'MGR_T1_V', 'MGR_T2', 'USR_T1', 'USR_T1_V',
              'USR_T2', 'USR_T3', 'NOROLE');

    $r->add('Account — xem', 'Hồ sơ người khác KHÔNG kèm API key', function ($a, $fx) {
        $id = $fx->new_user((int)$a->team);
        $_POST = ['id' => $id];
        $res = Account::get_profile();
        if (($res['status'] ?? '') !== 'success') {
            return false;   // không xem được thì cũng chẳng lộ gì -> DENY, không phải lỗ hổng
        }
        // ALLOW = có api_key của người khác -> không ai được phép, kể cả admin/super
        return array_key_exists('api_key', $res['user']);
    })->allow();

    $r->add('Account — xem', 'Xem hồ sơ người CÙNG TEAM cấp thấp hơn', function ($a, $fx) {
        $id = $fx->new_user((int)$a->team, $fx->customer_level());
        $_POST = ['id' => $id];
        return ($res = Account::get_profile())['status'] === 'success' ? $res : $res;
    })->allow('ADMIN', 'SUPER', 'MGR_T1', 'MGR_T1_V', 'MGR_T2', 'USR_T1', 'USR_T1_V',
              'USR_T2', 'USR_T3');   // NOROLE thiếu role `view` -> chặn

    $r->add('Account — xem', 'Xem hồ sơ tài khoản cấp ADMIN', function ($a, $fx) {
        $id = $fx->new_user((int)$a->team, $fx->admin_level());
        $_POST = ['id' => $id];
        return Account::get_profile();
        // Trục CẤP: chỉ admin (và super) đứng ngang/trên admin mới thấy
    })->allow('ADMIN', 'SUPER');

    $r->add('Account — xem', 'Xem hồ sơ người TEAM KHÁC', function ($a, $fx) {
        $id = $fx->new_user(2);
        $_POST = ['id' => $id];
        return Account::get_profile();
    })->allow('ADMIN', 'SUPER', 'MGR_T2', 'USR_T2');   // team 2 là team của chính họ

    // ---------- Sửa hồ sơ ----------
    $r->add('Account — sửa', 'Đổi email của chính mình', function ($a, $fx) {
        $cu  = (string)$fx->conn->execute_query(
            'SELECT email FROM authors WHERE ID = ?', [$a->uid])->fetch_row()[0];
        $moi = 'zzabacc' . bin2hex(random_bytes(3)) . '@zzab.test';
        $_POST = ['csrf_token' => 'ABTEST', 'username' => (string)$fx->conn->execute_query(
            'SELECT username FROM authors WHERE ID = ?', [$a->uid])->fetch_row()[0], 'email' => $moi];
        $res = Account::save_profile();
        $now = (string)$fx->conn->execute_query(
            'SELECT email FROM authors WHERE ID = ?', [$a->uid])->fetch_row()[0];
        $fx->conn->execute_query('UPDATE authors SET email = ? WHERE ID = ?', [$cu, $a->uid]);
        return $now === $moi ? $res : ['status' => 'error', 'message' => 'DB không đổi'];
    })->allow('ADMIN', 'SUPER', 'MGR_T1', 'MGR_T1_V', 'MGR_T2', 'USR_T1', 'USR_T1_V',
              'USR_T2', 'USR_T3', 'NOROLE');

    $r->add('Account — sửa', 'Thiếu CSRF thì không ghi được', function ($a, $fx) {
        $_POST = ['username' => 'ZZABNOCSRF', 'email' => 'zzabnocsrf@zzab.test'];   // không csrf_token
        return Account::save_profile();
    })->allow();

    $r->add('Account — sửa', 'Nhồi `id` để sửa người khác', function ($a, $fx) {
        $nan = $fx->new_user((int)$a->team);
        $row = $fx->conn->query("SELECT username, email FROM authors WHERE ID = $nan")->fetch_assoc();
        // save_profile() KHÔNG đọc `id`, nên nó ghi lên dòng của CHÍNH ACTOR — mà actor
        // mượn author CÓ THẬT. Chụp và trả lại dòng đó, đừng chỉ lo cho nạn nhân
        // (bỏ sót vế này đã đổi tên fox1990 ngày 06/08/2026).
        $toi = $fx->conn->execute_query(
            'SELECT username, email FROM authors WHERE ID = ? LIMIT 1', [$a->uid])->fetch_assoc();
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $nan, 'user_id' => $nan,
                  'username' => 'ZZABTAKEN' . bin2hex(random_bytes(3)), 'email' => 'zzabtaken@zzab.test'];
        Account::save_profile();
        $now = (string)$fx->conn->query("SELECT username FROM authors WHERE ID = $nan")->fetch_row()[0];
        $fx->conn->execute_query('UPDATE authors SET username = ?, email = ? WHERE ID = ?',
            [$toi['username'], $toi['email'], $a->uid]);
        // ALLOW = tên nạn nhân đã đổi -> không ai được phép
        return $now !== $row['username'];
    })->allow();

    $r->add('Account — sửa', 'Chiếm email của người khác', function ($a, $fx) {
        $nan = $fx->new_user((int)$a->team);
        $mail = (string)$fx->conn->query("SELECT email FROM authors WHERE ID = $nan")->fetch_row()[0];
        $cu = (string)$fx->conn->execute_query(
            'SELECT email FROM authors WHERE ID = ?', [$a->uid])->fetch_row()[0];
        $_POST = ['csrf_token' => 'ABTEST', 'username' => (string)$fx->conn->execute_query(
            'SELECT username FROM authors WHERE ID = ?', [$a->uid])->fetch_row()[0], 'email' => $mail];
        Account::save_profile();
        $now = (string)$fx->conn->execute_query(
            'SELECT email FROM authors WHERE ID = ?', [$a->uid])->fetch_row()[0];
        if ($now !== $cu) {
            $fx->conn->execute_query('UPDATE authors SET email = ? WHERE ID = ?', [$cu, $a->uid]);
        }
        return $now === $mail;   // ALLOW = chiếm được email -> không ai được phép
    })->allow();

    // ---------- Mật khẩu ----------
    $r->add('Account — mật khẩu', 'Sai mật khẩu hiện tại thì bị từ chối', function ($a, $fx) {
        $_POST = ['csrf_token' => 'ABTEST', 'current_password' => 'sai-be-bet-khong-dung',
                  'new_password' => 'zzabmoi123', 'confirm_password' => 'zzabmoi123'];
        return Account::change_password();
    })->allow();

    $r->add('Account — mật khẩu', 'Hai ô mật khẩu mới không khớp thì bị từ chối', function ($a, $fx) {
        $_POST = ['csrf_token' => 'ABTEST', 'current_password' => 'zzabzzab',
                  'new_password' => 'zzabmoi123', 'confirm_password' => 'zzabmoi999'];
        return Account::change_password();
    })->allow();

    $r->add('Account — mật khẩu', 'Mật khẩu mới quá ngắn thì bị từ chối', function ($a, $fx) {
        $_POST = ['csrf_token' => 'ABTEST', 'current_password' => 'zzabzzab',
                  'new_password' => 'abc', 'confirm_password' => 'abc'];
        return Account::change_password();
    })->allow();

    $r->add('Account — mật khẩu', 'Thiếu CSRF thì không đổi được', function ($a, $fx) {
        $_POST = ['current_password' => 'zzabzzab', 'new_password' => 'zzabmoi123',
                  'confirm_password' => 'zzabmoi123'];
        return Account::change_password();
    })->allow();

    // ---------- Thiết bị ghi nhớ ----------
    $r->add('Account — thiết bị', 'Chỉ thấy thiết bị CỦA MÌNH', function ($a, $fx) {
        $nan = $fx->new_user((int)$a->team);
        $fx->conn->execute_query(
            'INSERT INTO author_remember_tokens (author_id, selector, validator_hash,
                 user_agent_hash, expires_at, created_at)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), NOW())',
            [$nan, 'zzab' . bin2hex(random_bytes(4)), str_repeat('a', 64), str_repeat('b', 64)]);
        $res = Account::get_devices();
        $fx->conn->execute_query('DELETE FROM author_remember_tokens WHERE author_id = ?', [$nan]);
        // ALLOW = danh sách lọt thiết bị của người khác -> không ai được phép
        return ($res['status'] ?? '') === 'success' && count($res['devices']) > 0
            && !empty(array_filter($res['devices'], fn($d) => str_starts_with($d['tag'], 'bbbb')));
    })->allow();

    $r->add('Account — thiết bị', 'Thu hồi thiết bị của NGƯỜI KHÁC', function ($a, $fx) {
        $nan = $fx->new_user((int)$a->team);
        $fx->conn->execute_query(
            'INSERT INTO author_remember_tokens (author_id, selector, validator_hash,
                 user_agent_hash, expires_at, created_at)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), NOW())',
            [$nan, 'zzab' . bin2hex(random_bytes(4)), str_repeat('a', 64), str_repeat('c', 64)]);
        $tid = (int)$fx->conn->insert_id;
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $tid];
        Account::revoke_device();
        $con = $fx->conn->execute_query(
            'SELECT id FROM author_remember_tokens WHERE id = ?', [$tid])->fetch_row() !== null;
        $fx->conn->execute_query('DELETE FROM author_remember_tokens WHERE author_id = ?', [$nan]);
        return !$con;   // ALLOW = xóa được thiết bị người khác -> không ai được phép
    })->allow();

    // ---------- API key ----------
    $r->add('Account — API key', 'Tạo lại API key của chính mình', function ($a, $fx) {
        $cu = (string)$fx->conn->execute_query(
            'SELECT `key` FROM authors WHERE ID = ?', [$a->uid])->fetch_row()[0];
        $_POST = ['csrf_token' => 'ABTEST'];
        $res = Account::regenerate_key();
        $now = (string)$fx->conn->execute_query(
            'SELECT `key` FROM authors WHERE ID = ?', [$a->uid])->fetch_row()[0];
        $fx->conn->execute_query('UPDATE authors SET `key` = ? WHERE ID = ?', [$cu, $a->uid]);
        return ($res['status'] ?? '') === 'success' && strlen($now) === 32 && $now !== $cu;
    })->allow('ADMIN', 'SUPER', 'MGR_T1', 'MGR_T1_V', 'MGR_T2', 'USR_T1', 'USR_T1_V',
              'USR_T2', 'USR_T3', 'NOROLE');

    $r->add('Account — API key', 'Đổi API key của người khác qua tham số id', function ($a, $fx) {
        $nan = $fx->new_user((int)$a->team);
        $cu = (string)$fx->conn->query("SELECT `key` FROM authors WHERE ID = $nan")->fetch_row()[0];
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $nan];
        Account::regenerate_key();
        $now = (string)$fx->conn->query("SELECT `key` FROM authors WHERE ID = $nan")->fetch_row()[0];
        return $now !== $cu;   // ALLOW = đổi được key người khác -> không ai được phép
    })->allow();

    // ---------- Lớp UI ----------
    // ab_render() GHI ĐÈ $_GET bằng tham số thứ hai — đặt $_GET trước khi gọi là vô nghĩa,
    // fragment sẽ thấy mảng rỗng và render trang của chính mình (đã dính đúng bẫy này).
    $r->add('Account — giao diện', 'Trang của mình render form sửa + đổi mật khẩu',
        function ($a, $fx) {
            $html = ab_render('app-account.php', []);
            return ab_has($html, 'accProfileSave', 'accPasswordSave', 'accDeviceRows');
        })->allow('ADMIN', 'SUPER', 'MGR_T1', 'MGR_T1_V', 'MGR_T2', 'USR_T1', 'USR_T1_V',
                  'USR_T2', 'USR_T3', 'NOROLE');

    $r->add('Account — giao diện', 'Trang người khác KHÔNG render đường ghi nào',
        function ($a, $fx) {
            $id = $fx->new_user((int)$a->team, $fx->customer_level());
            $html = ab_render('app-account.php', ['id' => $id]);
            if (!ab_has($html, 'acc-username')) {
                return false;   // không xem được -> DENY (đúng với vai thiếu role view)
            }
            // ALLOW = trang chỉ-đọc vẫn lòi ra nút ghi -> không được phép ở bất kỳ vai nào
            return !ab_lacks($html, 'accProfileSave', 'accPasswordSave',
                'acc-avatar-file', 'acc-api-key');
        })->allow();
};
