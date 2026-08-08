<?php
/**
 * HACK — menu Phones ▸ SMS.
 *
 * Mặt tấn công riêng của trang này: nội dung tin nhắn là dữ liệu do NGƯỜI LẠ soạn — kẻ tấn
 * công chỉ cần nhắn tin tới số của nạn nhân, không cần bất kỳ tài khoản nào trong hệ thống.
 * Nghĩa là stored XSS ở đây rẻ hơn mọi trang khác, và tin nhắn thường chứa mã OTP.
 *
 * `sms` không có cột team — phạm vi bám theo `phones.team_id` qua JOIN, nên đòn chính là
 * tìm đường lách cái JOIN đó.
 *
 * Mọi đòn đụng dữ liệu THẬT đều đọc lại DB rồi trả nguyên trạng trong cùng phép thử.
 */

return function (HackRunner $h) {

    // ---------- Với sang team khác ----------
    $h->attack('SMS', 'Đọc tin nhắn của team khác', 'USR_OUT', function ($atk, $fx) {
        $sms = $fx->new_sms($fx->new_phone(1));   // team 1; kẻ tấn công ở team 3
        $_POST = ['draw' => 1, 'start' => 0, 'length' => 500];
        $res = getSmsTable();
        $ids = array_map('intval', array_column($res['data'] ?? [], 'id'));
        return ['breach' => in_array($sms, $ids, true),
            'note' => 'Đọc được tin của team 1 — tin nhắn chứa mã OTP'];
    }, 'NGHIÊM TRỌNG');

    $h->attack('SMS', 'Nhồi phone_id của team khác vào ô lọc', 'USR_OUT', function ($atk, $fx) {
        $so  = $fx->new_phone(1);
        $sms = $fx->new_sms($so);
        $_POST = ['draw' => 1, 'start' => 0, 'length' => 500, 'phone_id' => $so];
        $res = getSmsTable();
        $ids = array_map('intval', array_column($res['data'] ?? [], 'id'));
        return ['breach' => in_array($sms, $ids, true),
            'note' => 'Ô lọc bỏ qua ràng buộc team'];
    }, 'NGHIÊM TRỌNG');

    $h->attack('SMS', 'Xóa tin nhắn của team khác', 'MGR_OUT', function ($atk, $fx) {
        $sms = $fx->new_sms($fx->new_phone(1));
        $_POST = ['csrf_token' => $_SESSION['csrf_token'], 'ids' => [$sms]];
        deleteSmsBulk();
        return ['breach' => $fx->conn->query("SELECT ID FROM sms WHERE ID = $sms")->fetch_row() === null,
            'note' => 'Xóa được tin của team khác — phá bằng chứng của người ta'];
    }, 'NGHIÊM TRỌNG');

    $h->attack('SMS', 'Đánh dấu đã đọc tin của team khác', 'MGR_OUT', function ($atk, $fx) {
        $sms = $fx->new_sms($fx->new_phone(1), 'pending');
        $_POST = ['csrf_token' => $_SESSION['csrf_token'], 'ids' => [$sms], 'status' => 'viewed'];
        updateSmsStatusBulk();
        $now = (string)$fx->conn->query("SELECT status FROM sms WHERE ID = $sms")->fetch_row()[0];
        return ['breach' => $now === 'viewed',
            'note' => 'Giấu được tin chưa đọc của team khác'];
    }, 'NGHIÊM TRỌNG');

    $h->attack('SMS', 'Ô lọc liệt kê số của team khác', 'USR_OUT', function ($atk, $fx) {
        $so = $fx->new_phone(1);
        $res = getSmsFilters();
        $ids = array_map('intval', array_column($res['phones'] ?? [], 'id'));
        return ['breach' => in_array($so, $ids, true),
            'note' => 'Ô lọc lộ danh sách số của team khác'];
    });

    // ---------- Trộn ID ----------
    $h->attack('SMS', 'Trộn tin team khác vào lệnh xóa hàng loạt', 'MGR_OUT',
        function ($atk, $fx) {
            $minh = $fx->new_sms($fx->new_phone((int)$atk->team));
            $nan  = $fx->new_sms($fx->new_phone(1));
            $_POST = ['csrf_token' => $_SESSION['csrf_token'], 'ids' => [$minh, $nan]];
            deleteSmsBulk();
            return ['breach' => $fx->conn->query("SELECT ID FROM sms WHERE ID = $nan")->fetch_row() === null,
                'note' => 'ID lạ trong lô cũng bị xóa theo'];
        }, 'NGHIÊM TRỌNG');

    // ---------- Giả mạo tham số / SQLi ----------
    $h->attack('SMS', 'Nhét câu SQL vào tham số status', 'USR_VIEW', function ($atk, $fx) {
        $sms = $fx->new_sms($fx->new_phone((int)$atk->team), 'pending');
        $_POST = ['csrf_token' => $_SESSION['csrf_token'], 'ids' => [$sms],
            'status' => "viewed' , text = 'HACKED"];
        updateSmsStatusBulk();
        $r = $fx->conn->query("SELECT status, text FROM sms WHERE ID = $sms")->fetch_assoc();
        return ['breach' => $r['status'] !== 'pending' || $r['text'] !== 'ZZAB test message',
            'note' => 'status=' . $r['status'] . ' text=' . $r['text']];
    }, 'NGHIÊM TRỌNG');

    $h->attack('SMS', 'SQLi qua tham số lọc phone_id', 'USR_VIEW', function ($atk, $fx) {
        $_POST = ['draw' => 1, 'start' => 0, 'length' => 10,
            'phone_id' => '1 OR 1=1', 'sms_status' => "pending' OR '1'='1"];
        try {
            $res = getSmsTable();
        } catch (\Throwable $e) {
            return ['breach' => true, 'note' => 'Truy vấn vỡ vì tham số: ' . $e->getMessage()];
        }
        // Kết quả vẫn phải nằm trong phạm vi team; ép kiểu int + whitelist mới là cách chặn
        $ngoai = 0;
        foreach ($res['data'] ?? [] as $d) {
            $t = (int)$fx->conn->query('SELECT team_id FROM phones WHERE ID = '
                . (int)$d['phone_id'])->fetch_row()[0];
            if ($t !== (int)$atk->team) {
                $ngoai++;
            }
        }
        return ['breach' => $ngoai > 0, 'note' => "$ngoai dòng ngoài phạm vi lọt ra"];
    }, 'NGHIÊM TRỌNG');

    // ---------- CSRF ----------
    $h->attack('SMS', 'Xóa tin bằng CSRF (không biết token)', 'USR_VIEW', function ($atk, $fx) {
        $sms = $fx->new_sms($fx->new_phone((int)$atk->team));
        $_POST = ['csrf_token' => 'DOAN-BUA', 'ids' => [$sms]];
        deleteSmsBulk();
        return ['breach' => $fx->conn->query("SELECT ID FROM sms WHERE ID = $sms")->fetch_row() === null,
            'note' => 'Xóa được mà không có CSRF token'];
    }, 'NGHIÊM TRỌNG');

    $h->attack('SMS', 'Đổi trạng thái bằng CSRF', 'USR_VIEW', function ($atk, $fx) {
        $sms = $fx->new_sms($fx->new_phone((int)$atk->team), 'pending');
        $_POST = ['csrf_token' => 'DOAN-BUA', 'ids' => [$sms], 'status' => 'viewed'];
        updateSmsStatusBulk();
        $now = (string)$fx->conn->query("SELECT status FROM sms WHERE ID = $sms")->fetch_row()[0];
        return ['breach' => $now === 'viewed', 'note' => 'Đổi được mà không có CSRF token'];
    }, 'NGHIÊM TRỌNG');

    // ---------- Vai chỉ có view mà đòi ghi ----------
    $h->attack('SMS', 'Vai chỉ có VIEW mà xóa tin', 'USR_VIEW', function ($atk, $fx) {
        $sms = $fx->new_sms($fx->new_phone((int)$atk->team));
        $_POST = ['csrf_token' => $_SESSION['csrf_token'], 'ids' => [$sms]];
        deleteSmsBulk();
        return ['breach' => $fx->conn->query("SELECT ID FROM sms WHERE ID = $sms")->fetch_row() === null,
            'note' => 'Chỉ có role view mà xóa được'];
    }, 'NGHIÊM TRỌNG');

    $h->attack('SMS', 'Vai chỉ có VIEW mà đổi trạng thái', 'USR_VIEW', function ($atk, $fx) {
        $sms = $fx->new_sms($fx->new_phone((int)$atk->team), 'pending');
        $_POST = ['csrf_token' => $_SESSION['csrf_token'], 'ids' => [$sms], 'status' => 'viewed'];
        updateSmsStatusBulk();
        $now = (string)$fx->conn->query("SELECT status FROM sms WHERE ID = $sms")->fetch_row()[0];
        return ['breach' => $now === 'viewed', 'note' => 'Chỉ có role view mà ghi được'];
    }, 'NGHIÊM TRỌNG');

    $h->attack('SMS', 'Không có role nào vẫn đọc được bảng', 'NOROLE', function ($atk, $fx) {
        $fx->new_sms($fx->new_phone((int)$atk->team));
        $_POST = ['draw' => 1, 'start' => 0, 'length' => 100];
        $res = getSmsTable();
        return ['breach' => count($res['data'] ?? []) > 0,
            'note' => 'Trả về ' . count($res['data'] ?? []) . ' dòng'];
    }, 'NGHIÊM TRỌNG');

    // ---------- XSS ----------
    $h->attack('SMS', 'Nội dung tin nhắn render không bọc esc()', 'USR_VIEW',
        function ($atk, $fx) {
            $js = (string)file_get_contents(AB_ROOT . '/assets/js/app-phones-sms-list.js');
            // `text` và `from` là nội dung TIN NHẮN ĐẾN — do người lạ soạn, không cần tài khoản
            return ['breach' => !str_contains($js, "esc(full['from'])")
                    || !str_contains($js, 'esc(t)'),
                'note' => 'Field free-text nhét thẳng vào HTML'];
        }, 'NGHIÊM TRỌNG');

    $h->attack('SMS', 'Fragment echo thẳng nội dung tin ra HTML', 'USR_VIEW',
        function ($atk, $fx) {
            $fr = (string)file_get_contents(
                AB_ROOT . '/html/vertical-menu-template-no-customizer/app-phones-sms.php');
            // Bản cũ render timeline bằng PHP, echo thẳng $value['text'] — stored XSS thuần túy
            return ['breach' => (bool)preg_match('/<\?=\s*\$\w+\[[\'"](?:text|from_number|number|name)[\'"]\]/', $fr),
                'note' => 'Fragment vẫn echo field tin nhắn không escape'];
        }, 'NGHIÊM TRỌNG');
};
