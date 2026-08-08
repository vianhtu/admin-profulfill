<?php
/**
 * AB TEST — menu Phones ▸ SMS.
 *
 * Đặc thù cần nhớ khi đọc ma trận:
 *   - `sms` KHÔNG có cột team; nó bám theo `phones.team_id` qua `phone_id`. Nên mọi phép kiểm
 *     phạm vi ở đây thực chất là kiểm cái JOIN đó — bỏ JOIN là lộ tin của mọi team.
 *   - Chỉ hai mức phạm vi: admin thấy mọi team, còn lại chỉ team mình. Không có mức
 *     "chỉ dòng của mình" vì tin nhắn không thuộc về một người cụ thể nào.
 *   - Không có chức năng THÊM: tin về từ webhook Telnyx chứ không nhập tay.
 *   - Nội dung tin do NGƯỜI LẠ soạn (không cần tài khoản) nên phần XSS nằm ở bộ HACK.
 */

return function (AbRunner $r) {

    // ---------- Đọc danh sách ----------
    $r->add('SMS — đọc', 'Xem được bảng tin nhắn', function ($a, $fx) {
        $fx->new_sms($fx->new_phone((int)$a->team));
        $_POST = ['draw' => 1, 'start' => 0, 'length' => 100];
        $res = getSmsTable();
        return (int)$res['recordsTotal'] > 0 ? $res
            : ['status' => 'error', 'message' => 'không thấy dòng nào'];
    })->allow('ADMIN', 'SUPER', 'MGR_T1', 'MGR_T1_V', 'MGR_T2', 'USR_T1', 'USR_T1_V',
              'USR_T2', 'USR_T3');   // NOROLE không có role view -> chặn

    $r->add('SMS — đọc', 'KHÔNG thấy tin của team KHÁC', function ($a, $fx) {
        $khac = (int)$a->team === 1 ? 2 : 1;
        $sms = $fx->new_sms($fx->new_phone($khac));
        $_POST = ['draw' => 1, 'start' => 0, 'length' => 500];
        $res = getSmsTable();
        $ids = array_map('intval', array_column($res['data'] ?? [], 'id'));
        // ALLOW = thấy được tin của team khác -> chỉ admin/super được phép
        return in_array($sms, $ids, true);
    })->allow('ADMIN', 'SUPER');

    $r->add('SMS — đọc', 'recordsTotal đếm theo ĐÚNG phạm vi', function ($a, $fx) {
        $fx->new_sms($fx->new_phone((int)$a->team));
        $fx->new_sms($fx->new_phone((int)$a->team === 1 ? 2 : 1));
        $_POST = ['draw' => 1, 'start' => 0, 'length' => 500];
        $res = getSmsTable();
        if (!checkRoles('view', 'phones_sms')) {
            return false;   // không xem được -> DENY, không phải lỗi
        }
        $dem = (int)db()->query('SELECT COUNT(*) FROM sms INNER JOIN phones p ON p.ID = sms.phone_id'
            . (is_admin() ? '' : ' WHERE p.team_id = ' . (int)$a->team))->fetch_row()[0];
        return (int)$res['recordsTotal'] === $dem
            ? ['status' => 'success']
            : ['status' => 'error', 'message' => "đếm {$res['recordsTotal']} nhưng thật là $dem"];
    })->allow('ADMIN', 'SUPER', 'MGR_T1', 'MGR_T1_V', 'MGR_T2', 'USR_T1', 'USR_T1_V',
              'USR_T2', 'USR_T3');

    $r->add('SMS — đọc', 'Lọc theo phone_id của team KHÁC không lộ tin', function ($a, $fx) {
        $sms = $fx->new_sms($fx->new_phone((int)$a->team === 1 ? 2 : 1));
        $pid = (int)$fx->conn->query("SELECT phone_id FROM sms WHERE ID = $sms")->fetch_row()[0];
        // Nhồi thẳng phone_id của team khác vào ô lọc
        $_POST = ['draw' => 1, 'start' => 0, 'length' => 500, 'phone_id' => $pid];
        $res = getSmsTable();
        return count($res['data'] ?? []) > 0;   // ALLOW = lọc ra được -> chỉ admin
    })->allow('ADMIN', 'SUPER');

    // ---------- Đổi trạng thái đọc ----------
    $r->add('SMS — sửa', 'Đánh dấu đã đọc tin trong team mình', function ($a, $fx) {
        $sms = $fx->new_sms($fx->new_phone((int)$a->team), 'pending');
        $_POST = ['csrf_token' => 'ABTEST', 'ids' => [$sms], 'status' => 'viewed'];
        $res = updateSmsStatusBulk();
        // Tham số vượt quyền bị BỎ QUA âm thầm -> đọc lại DB mới biết thật giả
        $now = (string)$fx->conn->query("SELECT status FROM sms WHERE ID = $sms")->fetch_row()[0];
        return $now === 'viewed' ? $res : ['status' => 'error', 'message' => 'DB không đổi'];
    })->allow('ADMIN', 'SUPER', 'MGR_T1', 'MGR_T2', 'USR_T1', 'USR_T2', 'USR_T3');

    $r->add('SMS — sửa', 'Đánh dấu CHƯA đọc trở lại', function ($a, $fx) {
        $sms = $fx->new_sms($fx->new_phone((int)$a->team), 'viewed');
        $_POST = ['csrf_token' => 'ABTEST', 'ids' => [$sms], 'status' => 'pending'];
        updateSmsStatusBulk();
        $now = (string)$fx->conn->query("SELECT status FROM sms WHERE ID = $sms")->fetch_row()[0];
        return $now === 'pending';
    })->allow('ADMIN', 'SUPER', 'MGR_T1', 'MGR_T2', 'USR_T1', 'USR_T2', 'USR_T3');

    $r->add('SMS — sửa', 'KHÔNG sửa được tin của team KHÁC', function ($a, $fx) {
        $sms = $fx->new_sms($fx->new_phone((int)$a->team === 1 ? 2 : 1), 'pending');
        $_POST = ['csrf_token' => 'ABTEST', 'ids' => [$sms], 'status' => 'viewed'];
        updateSmsStatusBulk();
        $now = (string)$fx->conn->query("SELECT status FROM sms WHERE ID = $sms")->fetch_row()[0];
        return $now === 'viewed';   // ALLOW = sửa được -> chỉ admin
    })->allow('ADMIN', 'SUPER');

    $r->add('SMS — sửa', 'Trạng thái ngoài whitelist bị từ chối', function ($a, $fx) {
        $sms = $fx->new_sms($fx->new_phone((int)$a->team), 'pending');
        $_POST = ['csrf_token' => 'ABTEST', 'ids' => [$sms],
                  'status' => "x' , text='hacked"];
        updateSmsStatusBulk();
        $row = $fx->conn->query("SELECT status, text FROM sms WHERE ID = $sms")->fetch_assoc();
        // ALLOW = ghi được giá trị lạ hoặc chèn được sang cột khác -> không ai được phép
        return $row['status'] !== 'pending' || $row['text'] !== 'ZZAB test message';
    })->allow();

    $r->add('SMS — sửa', 'Thiếu CSRF thì không đổi được trạng thái', function ($a, $fx) {
        $sms = $fx->new_sms($fx->new_phone((int)$a->team), 'pending');
        $_POST = ['ids' => [$sms], 'status' => 'viewed'];   // không csrf_token
        updateSmsStatusBulk();
        $now = (string)$fx->conn->query("SELECT status FROM sms WHERE ID = $sms")->fetch_row()[0];
        return $now === 'viewed';
    })->allow();

    // ---------- Hàng loạt ----------
    $r->add('SMS — hàng loạt', 'Đổi trạng thái nhiều tin cùng lúc', function ($a, $fx) {
        $p = $fx->new_phone((int)$a->team);
        $ids = [$fx->new_sms($p, 'pending'), $fx->new_sms($p, 'pending')];
        $_POST = ['csrf_token' => 'ABTEST', 'ids' => $ids, 'status' => 'viewed'];
        updateSmsStatusBulk();
        $n = (int)$fx->conn->query('SELECT COUNT(*) FROM sms WHERE ID IN ('
            . implode(',', $ids) . ") AND status = 'viewed'")->fetch_row()[0];
        return $n === 2;
    })->allow('ADMIN', 'SUPER', 'MGR_T1', 'MGR_T2', 'USR_T1', 'USR_T2', 'USR_T3');

    $r->add('SMS — hàng loạt', 'Tin team KHÁC lẫn trong danh sách thì bị BỎ QUA',
        function ($a, $fx) {
            $minh = $fx->new_sms($fx->new_phone((int)$a->team), 'pending');
            $khac = $fx->new_sms($fx->new_phone((int)$a->team === 1 ? 2 : 1), 'pending');
            $_POST = ['csrf_token' => 'ABTEST', 'ids' => [$minh, $khac], 'status' => 'viewed'];
            updateSmsStatusBulk();
            $st = (string)$fx->conn->query("SELECT status FROM sms WHERE ID = $khac")->fetch_row()[0];
            // ALLOW = tin của team khác cũng bị đổi -> chỉ admin (admin vốn thấy mọi team)
            return $st === 'viewed';
        })->allow('ADMIN', 'SUPER');

    // ---------- Xóa ----------
    $r->add('SMS — xóa', 'Xóa tin trong team mình', function ($a, $fx) {
        $sms = $fx->new_sms($fx->new_phone((int)$a->team));
        $_POST = ['csrf_token' => 'ABTEST', 'ids' => [$sms]];
        deleteSmsBulk();
        return $fx->conn->query("SELECT ID FROM sms WHERE ID = $sms")->fetch_row() === null;
    })->allow('ADMIN', 'SUPER', 'MGR_T1', 'MGR_T2', 'USR_T1', 'USR_T2', 'USR_T3');

    $r->add('SMS — xóa', 'Xóa tin KHÔNG đụng tới số điện thoại', function ($a, $fx) {
        $p = $fx->new_phone((int)$a->team);
        $sms = $fx->new_sms($p);
        $_POST = ['csrf_token' => 'ABTEST', 'ids' => [$sms]];
        deleteSmsBulk();
        $conSo = $fx->conn->query("SELECT ID FROM phones WHERE ID = $p")->fetch_row() !== null;
        $mat = $fx->conn->query("SELECT ID FROM sms WHERE ID = $sms")->fetch_row() === null;
        // Vai không xóa được thì cả hai vế đều "không xảy ra" -> trả false = DENY đúng luật
        return $mat && $conSo;
    })->allow('ADMIN', 'SUPER', 'MGR_T1', 'MGR_T2', 'USR_T1', 'USR_T2', 'USR_T3');

    $r->add('SMS — xóa', 'KHÔNG xóa được tin của team KHÁC', function ($a, $fx) {
        $sms = $fx->new_sms($fx->new_phone((int)$a->team === 1 ? 2 : 1));
        $_POST = ['csrf_token' => 'ABTEST', 'ids' => [$sms]];
        deleteSmsBulk();
        return $fx->conn->query("SELECT ID FROM sms WHERE ID = $sms")->fetch_row() === null;
    })->allow('ADMIN', 'SUPER');

    $r->add('SMS — xóa', 'Thiếu CSRF thì không xóa được', function ($a, $fx) {
        $sms = $fx->new_sms($fx->new_phone((int)$a->team));
        $_POST = ['ids' => [$sms]];
        deleteSmsBulk();
        return $fx->conn->query("SELECT ID FROM sms WHERE ID = $sms")->fetch_row() === null;
    })->allow();

    // ---------- Ô lọc ----------
    $r->add('SMS — bộ lọc', 'Danh sách số trong ô lọc chỉ có số của team mình',
        function ($a, $fx) {
            $khac = $fx->new_phone((int)$a->team === 1 ? 2 : 1);
            // Ô lọc nạp theo yêu cầu (select2 ajax) nên phải tìm ĐÚNG số đó, không thể
            // trông vào việc nó lọt vào 30 dòng đầu
            $_POST = ['q' => '+1900ZZAB'];
            $res = getSmsPhones();
            $ids = array_map('intval', array_column($res['results'] ?? [], 'id'));
            // ALLOW = ô lọc liệt kê cả số của team khác -> chỉ admin
            return in_array($khac, $ids, true);
        })->allow('ADMIN', 'SUPER');

    $r->add('SMS — bộ lọc', 'Giải nghĩa id số của team KHÁC cho ô lọc dựng sẵn',
        function ($a, $fx) {
            // Nhãn của ô lọc lấy bằng `id=` — đường này cũng phải chịu ràng buộc team,
            // không thì đọc được số điện thoại của team khác chỉ bằng cách đoán ID
            $khac = $fx->new_phone((int)$a->team === 1 ? 2 : 1);
            $_POST = ['id' => $khac];
            $res = getSmsPhones();
            return count($res['results'] ?? []) > 0;
        })->allow('ADMIN', 'SUPER');

    $r->add('SMS — bộ lọc', 'Ô lọc trả về số của chính team mình', function ($a, $fx) {
        $minh = $fx->new_phone((int)$a->team);
        $_POST = ['id' => $minh];
        $res = getSmsPhones();
        return count($res['results'] ?? []) === 1;
    })->allow('ADMIN', 'SUPER', 'MGR_T1', 'MGR_T1_V', 'MGR_T2', 'USR_T1', 'USR_T1_V',
              'USR_T2', 'USR_T3');

    // ---------- Lớp UI ----------
    $r->add('SMS — giao diện', 'Fragment render bảng khi có quyền view', function ($a, $fx) {
        return ab_has(ab_render('app-phones-sms.php'), 'datatables-sms');
    })->allow('ADMIN', 'SUPER', 'MGR_T1', 'MGR_T1_V', 'MGR_T2', 'USR_T1', 'USR_T1_V',
              'USR_T2', 'USR_T3');

    $r->add('SMS — giao diện', 'Thanh thao tác hàng loạt chỉ render khi có quyền ghi',
        function ($a, $fx) {
            return ab_has(ab_render('app-phones-sms.php'), 'smsBulkBar');
        })->allow('ADMIN', 'SUPER', 'MGR_T1', 'MGR_T2', 'USR_T1', 'USR_T2', 'USR_T3');

    $r->add('SMS — giao diện', 'Nút Delete Selected chỉ render khi có quyền delete',
        function ($a, $fx) {
            return ab_has(ab_render('app-phones-sms.php'), 'smsBulkDelete');
        })->allow('ADMIN', 'SUPER', 'MGR_T1', 'MGR_T2', 'USR_T1', 'USR_T2', 'USR_T3');
};
