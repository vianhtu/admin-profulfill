<?php
/**
 * HACK — trang Account (`?menu=account`).
 *
 * Đây là mặt tấn công RỘNG NHẤT của cả hệ thống: nó cố tình mở cho MỌI người đăng nhập,
 * không đòi role nào. Kẻ tấn công chỉ cần một tài khoản bất kỳ — kể cả tài khoản không được
 * cấp một menu nào — là đã đứng trước cửa. Nên mọi đòn dưới đây đánh từ vai thấp nhất có thể.
 *
 * Mọi đòn đụng tài khoản THẬT đều đọc lại DB rồi trả giá trị cũ về ngay trong cùng phép thử.
 */

return function (HackRunner $h) {

    // ---------- Với sang hồ sơ người khác ----------
    $h->attack('Account', 'Đọc hồ sơ người TEAM KHÁC bằng cách nhồi id', 'USR_OUT',
        function ($atk, $fx) {
            $nan = $fx->new_user(1);   // team 1, kẻ tấn công ở team 3
            $_POST = ['id' => $nan];
            $res = Account::get_profile();
            return ['breach' => ($res['status'] ?? '') === 'success',
                'note' => 'Đọc được hồ sơ team khác: ' . ($res['user']['username'] ?? '-')];
        }, 'NGHIÊM TRỌNG');

    $h->attack('Account', 'Đọc hồ sơ tài khoản ADMIN', 'MGR_OUT', function ($atk, $fx) {
        $nan = $fx->new_user((int)$atk->team, $fx->admin_level());
        $_POST = ['id' => $nan];
        $res = Account::get_profile();
        return ['breach' => ($res['status'] ?? '') === 'success',
            'note' => 'Manager đọc được hồ sơ admin -> lộ email/tên đăng nhập mục tiêu'];
    }, 'NGHIÊM TRỌNG');

    $h->attack('Account', 'Moi API key của người khác', 'MGR_OUT', function ($atk, $fx) {
        $nan = $fx->new_user((int)$atk->team, $fx->customer_level());
        $_POST = ['id' => $nan];
        $res = Account::get_profile();
        // Xem được là đúng luật (cùng team, cấp thấp hơn) — nhưng KHÔNG được kèm key.
        // Key + email là đủ để đẩy dữ liệu qua API extension dưới danh nghĩa người ta.
        return ['breach' => ($res['status'] ?? '') === 'success'
            && array_key_exists('api_key', $res['user']),
            'note' => 'Hồ sơ người khác trả kèm api_key'];
    }, 'NGHIÊM TRỌNG');

    $h->attack('Account', 'Moi lương người khác khi không có quyền xem lương', 'USR_VIEW',
        function ($atk, $fx) {
            $nan = $fx->new_user((int)$atk->team, $fx->customer_level());
            $_POST = ['id' => $nan];
            $res = Account::get_profile();
            return ['breach' => ($res['status'] ?? '') === 'success'
                && array_key_exists('wage', $res['user']),
                'note' => 'Vai user (không được xem lương) vẫn nhận được wage của người khác'];
        }, 'NGHIÊM TRỌNG');

    // ---------- Ghi đè lên người khác ----------
    $h->attack('Account', 'Nhồi id/user_id để ghi lên hồ sơ người khác', 'USR_VIEW',
        function ($atk, $fx) {
            $nan = $fx->new_user((int)$atk->team);
            $ten = (string)$fx->conn->query("SELECT username FROM authors WHERE ID = $nan")->fetch_row()[0];
            $_POST = ['csrf_token' => $_SESSION['csrf_token'], 'id' => $nan, 'user_id' => $nan,
                'author_id' => $nan, 'ID' => $nan,
                'username' => 'ZZABPWN' . bin2hex(random_bytes(3)), 'email' => 'zzabpwn@zzab.test'];
            Account::save_profile();
            $now = (string)$fx->conn->query("SELECT username FROM authors WHERE ID = $nan")->fetch_row()[0];
            return ['breach' => $now !== $ten, 'note' => 'Tên nạn nhân sau đòn: ' . $now];
        }, 'NGHIÊM TRỌNG');

    $h->attack('Account', 'Nhét cột ngoài luồng vào form hồ sơ (level/team/wage/status)',
        'USR_VIEW', function ($atk, $fx) {
            $me = $fx->conn->execute_query(
                'SELECT username, email, level, team_id, status, wage FROM authors WHERE ID = ? LIMIT 1',
                [$atk->uid])->fetch_assoc();
            $_POST = ['csrf_token' => $_SESSION['csrf_token'],
                'username' => $me['username'], 'email' => $me['email'],
                // save_profile() chỉ dựng câu UPDATE từ username/email/avatar — mấy cái này
                // phải rơi vào hư không, không được lọt vào SET
                'level' => $fx->admin_level(), 'team_id' => 1, 'status' => 3,
                'wage' => 999999999, 'insurance' => 999999999];
            Account::save_profile();
            $sau = $fx->conn->execute_query(
                'SELECT level, team_id, status, wage FROM authors WHERE ID = ? LIMIT 1',
                [$atk->uid])->fetch_assoc();
            $doi = (int)$sau['level'] !== (int)$me['level']
                || (int)$sau['team_id'] !== (int)$me['team_id']
                || (int)$sau['status'] !== (int)$me['status']
                || (float)$sau['wage'] !== (float)$me['wage'];
            if ($doi) {   // tài khoản THẬT -> trả nguyên trạng ngay
                $fx->conn->execute_query(
                    'UPDATE authors SET level = ?, team_id = ?, status = ?, wage = ? WHERE ID = ?',
                    [(int)$me['level'], (int)$me['team_id'], (int)$me['status'],
                     (float)$me['wage'], $atk->uid]);
            }
            return ['breach' => $doi, 'note' => 'Một trong 4 cột ngoài luồng đã bị ghi'];
        }, 'NGHIÊM TRỌNG');

    $h->attack('Account', 'Nhét đường dẫn tùy ý vào cột avatar', 'USR_VIEW', function ($atk, $fx) {
        $me = $fx->conn->execute_query(
            'SELECT username, email, avatar FROM authors WHERE ID = ? LIMIT 1',
            [$atk->uid])->fetch_assoc();
        $_POST = ['csrf_token' => $_SESSION['csrf_token'], 'username' => $me['username'],
            'email' => $me['email'], 'avatar' => '../../config.php'];
        Account::save_profile();
        $now = (string)$fx->conn->execute_query(
            'SELECT avatar FROM authors WHERE ID = ?', [$atk->uid])->fetch_row()[0];
        if ($now !== (string)$me['avatar']) {
            $fx->conn->execute_query('UPDATE authors SET avatar = ? WHERE ID = ?',
                [(string)$me['avatar'], $atk->uid]);
        }
        return ['breach' => $now === '../../config.php', 'note' => 'avatar sau đòn: ' . $now];
    }, 'NGHIÊM TRỌNG');

    // ---------- Mật khẩu ----------
    $h->attack('Account', 'Đổi mật khẩu mà KHÔNG biết mật khẩu hiện tại', 'USR_VIEW',
        function ($atk, $fx) {
            $cu = (string)$fx->conn->execute_query(
                'SELECT pass FROM authors WHERE ID = ?', [$atk->uid])->fetch_row()[0];
            $_POST = ['csrf_token' => $_SESSION['csrf_token'], 'current_password' => '',
                'new_password' => 'ZZABchiemquyen1', 'confirm_password' => 'ZZABchiemquyen1'];
            Account::change_password();
            $now = (string)$fx->conn->execute_query(
                'SELECT pass FROM authors WHERE ID = ?', [$atk->uid])->fetch_row()[0];
            if ($now !== $cu) {   // tài khoản THẬT -> trả hash cũ về ngay
                $fx->conn->execute_query('UPDATE authors SET pass = ? WHERE ID = ?', [$cu, $atk->uid]);
            }
            return ['breach' => $now !== $cu, 'note' => 'Đổi được mật khẩu mà không cần mật khẩu cũ'];
        }, 'NGHIÊM TRỌNG');

    $h->attack('Account', 'Đổi mật khẩu bằng CSRF (không biết token)', 'USR_VIEW',
        function ($atk, $fx) {
            $cu = (string)$fx->conn->execute_query(
                'SELECT pass FROM authors WHERE ID = ?', [$atk->uid])->fetch_row()[0];
            // Kẻ tấn công dụ nạn nhân submit form từ site khác -> không có token thật
            $_POST = ['csrf_token' => 'DOAN-BUA', 'current_password' => 'zzabzzab',
                'new_password' => 'ZZABcsrf12345', 'confirm_password' => 'ZZABcsrf12345'];
            Account::change_password();
            $now = (string)$fx->conn->execute_query(
                'SELECT pass FROM authors WHERE ID = ?', [$atk->uid])->fetch_row()[0];
            if ($now !== $cu) {
                $fx->conn->execute_query('UPDATE authors SET pass = ? WHERE ID = ?', [$cu, $atk->uid]);
            }
            return ['breach' => $now !== $cu, 'note' => 'Đổi được mật khẩu mà không có CSRF token'];
        }, 'NGHIÊM TRỌNG');

    // ---------- Thiết bị & API key ----------
    $h->attack('Account', 'Thu hồi thiết bị của người khác bằng id đoán được', 'USR_VIEW',
        function ($atk, $fx) {
            $nan = $fx->new_user((int)$atk->team);
            $fx->conn->execute_query(
                'INSERT INTO author_remember_tokens (author_id, selector, validator_hash,
                     user_agent_hash, expires_at, created_at)
                 VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), NOW())',
                [$nan, 'zzab' . bin2hex(random_bytes(4)), str_repeat('a', 64), str_repeat('d', 64)]);
            $tid = (int)$fx->conn->insert_id;
            $_POST = ['csrf_token' => $_SESSION['csrf_token'], 'id' => $tid];
            Account::revoke_device();
            $con = $fx->conn->execute_query(
                'SELECT id FROM author_remember_tokens WHERE id = ?', [$tid])->fetch_row() !== null;
            $fx->conn->execute_query('DELETE FROM author_remember_tokens WHERE author_id = ?', [$nan]);
            return ['breach' => !$con, 'note' => 'Xóa được phiên ghi nhớ của người khác (DoS)'];
        });

    $h->attack('Account', 'Đổi API key người khác để chặn extension của họ', 'USR_VIEW',
        function ($atk, $fx) {
            $nan = $fx->new_user((int)$atk->team);
            $cu = (string)$fx->conn->query("SELECT `key` FROM authors WHERE ID = $nan")->fetch_row()[0];
            $_POST = ['csrf_token' => $_SESSION['csrf_token'], 'id' => $nan, 'author_id' => $nan];
            Account::regenerate_key();
            $now = (string)$fx->conn->query("SELECT `key` FROM authors WHERE ID = $nan")->fetch_row()[0];
            return ['breach' => $now !== $cu, 'note' => 'key của nạn nhân bị đổi'];
        }, 'NGHIÊM TRỌNG');

    $h->attack('Account', 'Gọi mọi endpoint account khi CHƯA đăng nhập', 'ANON',
        function ($atk, $fx) {
            $_POST = ['csrf_token' => 'VICTIM', 'id' => 1, 'username' => 'ZZABANON',
                'email' => 'zzabanon@zzab.test', 'current_password' => 'x',
                'new_password' => 'ZZABanon12345', 'confirm_password' => 'ZZABanon12345'];
            $lot = [];
            foreach (['get_profile', 'save_profile', 'change_password', 'get_devices',
                      'revoke_device', 'revoke_all_devices', 'regenerate_key'] as $fn) {
                $res = Account::$fn();
                if (($res['status'] ?? '') === 'success') {
                    $lot[] = $fn;
                }
            }
            return ['breach' => $lot !== [], 'note' => 'Khách vãng lai gọi lọt: ' . implode(', ', $lot)];
        }, 'NGHIÊM TRỌNG');

    // ---------- XSS ----------
    $h->attack('Account', 'Tên đăng nhập chứa script không được escape khi render', 'USR_VIEW',
        function ($atk, $fx) {
            $js = (string)file_get_contents(AB_ROOT . '/assets/js/app-account.js');
            // Bảng thiết bị dựng bằng template string -> mọi field phải qua esc()
            return ['breach' => !str_contains($js, 'esc(d.tag)')
                || !str_contains($js, 'function esc('),
                'note' => 'app-account.js nhét field vào HTML mà không bọc esc()'];
        });
};
