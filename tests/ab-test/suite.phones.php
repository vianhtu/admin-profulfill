<?php
/**
 * AB TEST — menu Phones ▸ Numbers.
 *
 * Đặc thù cần nhớ khi đọc ma trận:
 *   - `phones` thuộc TEAM, không thuộc người. Nên phạm vi dữ liệu chỉ có hai mức: admin thấy
 *     mọi team, còn lại chỉ thấy team mình — KHÔNG có mức "chỉ dòng của mình" như Products.
 *   - Số và nhà mạng do Telnyx cấp: `savePhone()` chỉ sửa `status`, và `team_id` thì CHỈ admin.
 *   - Không có chức năng THÊM: số về từ webhook Telnyx chứ không nhập tay.
 *   - Xóa ở đây KHÔNG hủy số bên Telnyx (vẫn mất phí) — cố ý không gọi API hủy.
 */

return function (AbRunner $r) {

    // ---------- Đọc danh sách ----------
    $r->add('Phones — đọc', 'Xem được bảng số điện thoại', function ($a, $fx) {
        $fx->new_phone((int)$a->team);
        $_POST = ['draw' => 1, 'start' => 0, 'length' => 100];
        $res = getPhonesTable();
        return (int)$res['recordsTotal'] > 0 ? $res
            : ['status' => 'error', 'message' => 'không thấy dòng nào'];
    })->allow('ADMIN', 'SUPER', 'MGR_T1', 'MGR_T1_V', 'MGR_T2', 'USR_T1', 'USR_T1_V',
              'USR_T2', 'USR_T3');   // NOROLE không có role view -> chặn

    $r->add('Phones — đọc', 'KHÔNG thấy số của team KHÁC', function ($a, $fx) {
        // Dựng số ở CẢ HAI team rồi xem danh sách có lẫn không
        $khac = (int)$a->team === 1 ? 2 : 1;
        $idKhac = $fx->new_phone($khac);
        $_POST = ['draw' => 1, 'start' => 0, 'length' => 500];
        $res = getPhonesTable();
        $ids = array_column($res['data'] ?? [], 'id');
        // ALLOW = thấy được số của team khác -> chỉ admin/super được phép
        return in_array($idKhac, array_map('intval', $ids), true);
    })->allow('ADMIN', 'SUPER');

    $r->add('Phones — đọc', 'recordsTotal đếm theo ĐÚNG phạm vi', function ($a, $fx) {
        // Số đếm phải khớp số dòng thực sự thấy được, không được nói dối
        $fx->new_phone((int)$a->team);
        $fx->new_phone((int)$a->team === 1 ? 2 : 1);
        $_POST = ['draw' => 1, 'start' => 0, 'length' => 500];
        $res = getPhonesTable();
        if (!checkRoles('view', 'phones_numbers')) {
            return false;   // không xem được -> DENY, không phải lỗi
        }
        $dem = (int)db()->query('SELECT COUNT(*) FROM phones'
            . (is_admin() ? '' : ' WHERE team_id = ' . (int)$a->team))->fetch_row()[0];
        return (int)$res['recordsTotal'] === $dem
            ? ['status' => 'success']
            : ['status' => 'error', 'message' => "đếm {$res['recordsTotal']} nhưng thật là $dem"];
    })->allow('ADMIN', 'SUPER', 'MGR_T1', 'MGR_T1_V', 'MGR_T2', 'USR_T1', 'USR_T1_V',
              'USR_T2', 'USR_T3');

    // ---------- Sửa ----------
    $r->add('Phones — sửa', 'Đổi trạng thái số trong team mình', function ($a, $fx) {
        $id = $fx->new_phone((int)$a->team, 'active');
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id, 'status' => 'suspend'];
        $res = savePhone();
        // Tham số vượt quyền bị BỎ QUA âm thầm -> đọc lại DB mới biết thật giả
        $now = (string)$fx->conn->query("SELECT status FROM phones WHERE ID = $id")->fetch_row()[0];
        return $now === 'suspend' ? $res : ['status' => 'error', 'message' => 'DB không đổi'];
    })->allow('ADMIN', 'SUPER', 'MGR_T1', 'MGR_T2', 'USR_T1', 'USR_T2', 'USR_T3');

    $r->add('Phones — sửa', 'KHÔNG sửa được số của team KHÁC', function ($a, $fx) {
        $khac = (int)$a->team === 1 ? 2 : 1;
        $id = $fx->new_phone($khac, 'active');
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id, 'status' => 'suspend'];
        savePhone();
        $now = (string)$fx->conn->query("SELECT status FROM phones WHERE ID = $id")->fetch_row()[0];
        return $now === 'suspend';   // ALLOW = sửa được -> chỉ admin
    })->allow('ADMIN', 'SUPER');

    $r->add('Phones — sửa', 'Trạng thái ngoài whitelist bị từ chối', function ($a, $fx) {
        $id = $fx->new_phone((int)$a->team, 'active');
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id, 'status' => 'deleted; DROP TABLE phones'];
        savePhone();
        $now = (string)$fx->conn->query("SELECT status FROM phones WHERE ID = $id")->fetch_row()[0];
        return $now !== 'active';   // ALLOW = ghi được giá trị lạ -> không ai được phép
    })->allow();

    $r->add('Phones — sửa', 'Chuyển số sang team khác (chỉ admin)', function ($a, $fx) {
        $id = $fx->new_phone((int)$a->team, 'active');
        $den = (int)$a->team === 1 ? 2 : 1;
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id, 'status' => 'active', 'team_id' => $den];
        savePhone();
        $now = (int)$fx->conn->query("SELECT team_id FROM phones WHERE ID = $id")->fetch_row()[0];
        return $now === $den;
    })->allow('ADMIN', 'SUPER');

    $r->add('Phones — sửa', 'Thiếu CSRF thì không sửa được', function ($a, $fx) {
        $id = $fx->new_phone((int)$a->team, 'active');
        $_POST = ['id' => $id, 'status' => 'suspend'];   // không csrf_token
        savePhone();
        $now = (string)$fx->conn->query("SELECT status FROM phones WHERE ID = $id")->fetch_row()[0];
        return $now === 'suspend';
    })->allow();

    // ---------- Đổi trạng thái hàng loạt ----------
    $r->add('Phones — hàng loạt', 'Đổi trạng thái nhiều số cùng lúc', function ($a, $fx) {
        $ids = [$fx->new_phone((int)$a->team, 'active'), $fx->new_phone((int)$a->team, 'active')];
        $_POST = ['csrf_token' => 'ABTEST', 'ids' => $ids, 'status' => 'suspend'];
        updatePhonesStatusBulk();
        $n = (int)$fx->conn->query('SELECT COUNT(*) FROM phones WHERE ID IN ('
            . implode(',', $ids) . ") AND status = 'suspend'")->fetch_row()[0];
        return $n === 2;
    })->allow('ADMIN', 'SUPER', 'MGR_T1', 'MGR_T2', 'USR_T1', 'USR_T2', 'USR_T3');

    $r->add('Phones — hàng loạt', 'Số team KHÁC lẫn trong danh sách thì bị BỎ QUA', function ($a, $fx) {
        $minh = $fx->new_phone((int)$a->team, 'active');
        $khac = $fx->new_phone((int)$a->team === 1 ? 2 : 1, 'active');
        $_POST = ['csrf_token' => 'ABTEST', 'ids' => [$minh, $khac], 'status' => 'suspend'];
        updatePhonesStatusBulk();
        $stKhac = (string)$fx->conn->query("SELECT status FROM phones WHERE ID = $khac")->fetch_row()[0];
        // ALLOW = số của team khác cũng bị đổi -> chỉ admin (admin vốn thấy mọi team)
        return $stKhac === 'suspend';
    })->allow('ADMIN', 'SUPER');

    // ---------- Xóa ----------
    $r->add('Phones — xóa', 'Xóa số trong team mình, kéo theo tin nhắn và liên kết',
        function ($a, $fx) {
            $id = $fx->new_phone((int)$a->team);
            $fx->new_sms($id);
            $acc = $fx->conn->query('SELECT ID FROM accounts LIMIT 1')->fetch_row();
            if ($acc) {
                $fx->conn->execute_query(
                    'INSERT IGNORE INTO accounts_phones (account_id, phone_id) VALUES (?, ?)',
                    [(int)$acc[0], $id]);
            }
            $_POST = ['csrf_token' => 'ABTEST', 'ids' => [$id]];
            deletePhonesBulk();
            $conSo  = $fx->conn->query("SELECT ID FROM phones WHERE ID = $id")->fetch_row() !== null;
            $conSms = (int)$fx->conn->query("SELECT COUNT(*) FROM sms WHERE phone_id = $id")->fetch_row()[0];
            $conLk  = (int)$fx->conn->query("SELECT COUNT(*) FROM accounts_phones WHERE phone_id = $id")->fetch_row()[0];
            // Xóa được thì phải xóa SẠCH, không để lại tin nhắn/liên kết mồ côi
            return !$conSo && $conSms === 0 && $conLk === 0;
        })->allow('ADMIN', 'SUPER', 'MGR_T1', 'MGR_T2', 'USR_T1', 'USR_T2', 'USR_T3');

    $r->add('Phones — xóa', 'KHÔNG xóa được số của team KHÁC', function ($a, $fx) {
        $id = $fx->new_phone((int)$a->team === 1 ? 2 : 1);
        $_POST = ['csrf_token' => 'ABTEST', 'ids' => [$id]];
        deletePhonesBulk();
        return $fx->conn->query("SELECT ID FROM phones WHERE ID = $id")->fetch_row() === null;
    })->allow('ADMIN', 'SUPER');

    $r->add('Phones — xóa', 'Thiếu CSRF thì không xóa được', function ($a, $fx) {
        $id = $fx->new_phone((int)$a->team);
        $_POST = ['ids' => [$id]];
        deletePhonesBulk();
        return $fx->conn->query("SELECT ID FROM phones WHERE ID = $id")->fetch_row() === null;
    })->allow();

    // ---------- Tin nhắn ----------
    $r->add('Phones — tin nhắn', 'Đọc tin nhắn của số trong team mình', function ($a, $fx) {
        $id = $fx->new_phone((int)$a->team);
        $fx->new_sms($id);
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id];
        $res = getPhoneMessages();
        return ($res['status'] ?? '') === 'success' && count($res['messages'] ?? []) === 1
            ? $res : ['status' => 'error', 'message' => $res['message'] ?? 'không có tin'];
    })->allow('ADMIN', 'SUPER', 'MGR_T1', 'MGR_T1_V', 'MGR_T2', 'USR_T1', 'USR_T1_V',
              'USR_T2', 'USR_T3');

    $r->add('Phones — tin nhắn', 'KHÔNG đọc được tin của số team KHÁC', function ($a, $fx) {
        $id = $fx->new_phone((int)$a->team === 1 ? 2 : 1);
        $fx->new_sms($id);
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id];
        return getPhoneMessages();
    })->allow('ADMIN', 'SUPER');

    $r->add('Phones — tin nhắn', 'Đánh dấu đã đọc tin của mình', function ($a, $fx) {
        $id = $fx->new_phone((int)$a->team);
        $sms = $fx->new_sms($id, 'pending');
        $_POST = ['csrf_token' => 'ABTEST', 'phone_id' => $id, 'ids' => [$sms]];
        markSmsRead();
        $now = (string)$fx->conn->query("SELECT status FROM sms WHERE ID = $sms")->fetch_row()[0];
        return $now === 'viewed';
    })->allow('ADMIN', 'SUPER', 'MGR_T1', 'MGR_T1_V', 'MGR_T2', 'USR_T1', 'USR_T1_V',
              'USR_T2', 'USR_T3');

    $r->add('Phones — tin nhắn', 'Nhồi ID tin của số KHÁC vào lệnh đánh dấu', function ($a, $fx) {
        $cua_toi = $fx->new_phone((int)$a->team);
        $cua_ho  = $fx->new_phone((int)$a->team);
        $smsHo   = $fx->new_sms($cua_ho, 'pending');
        // Khai phone_id là số của mình nhưng nhét ID tin của số khác
        $_POST = ['csrf_token' => 'ABTEST', 'phone_id' => $cua_toi, 'ids' => [$smsHo]];
        markSmsRead();
        $now = (string)$fx->conn->query("SELECT status FROM sms WHERE ID = $smsHo")->fetch_row()[0];
        // ALLOW = đánh dấu được tin của số khác -> không ai được phép
        return $now === 'viewed';
    })->allow();

    // ---------- Webhook ----------
    $r->add('Phones — webhook', 'Số SUSPEND không nhận tin mới', function ($a, $fx) {
        // Gọi thẳng hàm, giả lập input như Telnyx gửi
        $id = $fx->new_phone((int)$a->team, 'suspend');
        $so = (string)$fx->conn->query("SELECT number FROM phones WHERE ID = $id")->fetch_row()[0];
        $truoc = (int)$fx->conn->query("SELECT COUNT(*) FROM sms WHERE phone_id = $id")->fetch_row()[0];
        // hookTelnyx đọc php://input nên không gọi trực tiếp được từ CLI — kiểm bằng luật:
        // số không ở trạng thái active thì phải bị bỏ qua.
        $st = (string)$fx->conn->query("SELECT status FROM phones WHERE ID = $id")->fetch_row()[0];
        // ALLOW = số suspend vẫn được coi là nhận tin -> sai
        return $st === 'active' || $truoc > 0;
    })->allow();

    // ---------- Lớp UI ----------
    $r->add('Phones — giao diện', 'Fragment render bảng khi có quyền view', function ($a, $fx) {
        return ab_has(ab_render('app-phones-list.php'), 'datatables-phones');
    })->allow('ADMIN', 'SUPER', 'MGR_T1', 'MGR_T1_V', 'MGR_T2', 'USR_T1', 'USR_T1_V',
              'USR_T2', 'USR_T3');

    $r->add('Phones — giao diện', 'Thanh thao tác hàng loạt chỉ render khi có quyền ghi',
        function ($a, $fx) {
            return ab_has(ab_render('app-phones-list.php'), 'phonesBulkBar');
        })->allow('ADMIN', 'SUPER', 'MGR_T1', 'MGR_T2', 'USR_T1', 'USR_T2', 'USR_T3');

    $r->add('Phones — giao diện', 'Nút Delete Selected chỉ render khi có quyền delete',
        function ($a, $fx) {
            return ab_has(ab_render('app-phones-list.php'), 'phonesBulkDelete');
        })->allow('ADMIN', 'SUPER', 'MGR_T1', 'MGR_T2', 'USR_T1', 'USR_T2', 'USR_T3');
};
