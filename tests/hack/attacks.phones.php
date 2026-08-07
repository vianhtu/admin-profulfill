<?php
/**
 * HACK — menu Phones ▸ Numbers.
 *
 * Mặt tấn công của menu này khác các menu khác ở hai chỗ:
 *   - `phones` thuộc TEAM, nên đòn chính là với sang team khác (đọc, sửa, xóa, moi tin nhắn).
 *   - Có một cửa CÔNG KHAI không cần đăng nhập: webhook Telnyx (`hook-telnyx`). Nó chỉ được
 *     bảo vệ bằng `team.key` trên query string, nên phải đánh thẳng vào đó.
 *
 * Mọi đòn đụng dữ liệu THẬT đều đọc lại DB rồi trả nguyên trạng trong cùng phép thử.
 */

return function (HackRunner $h) {

    // ---------- Với sang team khác ----------
    $h->attack('Phones', 'Đọc danh sách số của team khác', 'USR_OUT', function ($atk, $fx) {
        $nan = $fx->new_phone(1);   // team 1; kẻ tấn công ở team 3
        $_POST = ['draw' => 1, 'start' => 0, 'length' => 500];
        $res = getPhonesTable();
        $ids = array_map('intval', array_column($res['data'] ?? [], 'id'));
        return ['breach' => in_array($nan, $ids, true),
            'note' => 'Thấy được số của team 1 trong danh sách'];
    }, 'NGHIÊM TRỌNG');

    $h->attack('Phones', 'Sửa trạng thái số của team khác', 'MGR_OUT', function ($atk, $fx) {
        $nan = $fx->new_phone(1, 'active');
        $_POST = ['csrf_token' => $_SESSION['csrf_token'], 'id' => $nan, 'status' => 'suspend'];
        savePhone();
        $now = (string)$fx->conn->query("SELECT status FROM phones WHERE ID = $nan")->fetch_row()[0];
        return ['breach' => $now === 'suspend', 'note' => 'status sau đòn: ' . $now];
    }, 'NGHIÊM TRỌNG');

    $h->attack('Phones', 'Xóa số của team khác', 'MGR_OUT', function ($atk, $fx) {
        $nan = $fx->new_phone(1);
        $_POST = ['csrf_token' => $_SESSION['csrf_token'], 'ids' => [$nan]];
        deletePhonesBulk();
        return ['breach' => $fx->conn->query("SELECT ID FROM phones WHERE ID = $nan")->fetch_row() === null,
            'note' => 'Xóa được số của team khác'];
    }, 'NGHIÊM TRỌNG');

    $h->attack('Phones', 'Moi tin nhắn của số team khác', 'USR_OUT', function ($atk, $fx) {
        $nan = $fx->new_phone(1);
        $fx->new_sms($nan);
        $_POST = ['csrf_token' => $_SESSION['csrf_token'], 'id' => $nan];
        $res = getPhoneMessages();
        return ['breach' => ($res['status'] ?? '') === 'success',
            'note' => 'Đọc được ' . count($res['messages'] ?? []) . ' tin của team khác — '
                . 'tin nhắn chứa mã OTP'];
    }, 'NGHIÊM TRỌNG');

    // ---------- Leo quyền qua tham số ----------
    $h->attack('Phones', 'Non-admin nhồi team_id để chiếm số về team mình', 'MGR_OUT',
        function ($atk, $fx) {
            $nan = $fx->new_phone(1, 'active');
            $_POST = ['csrf_token' => $_SESSION['csrf_token'], 'id' => $nan,
                'status' => 'active', 'team_id' => (int)$atk->team];
            savePhone();
            $now = (int)$fx->conn->query("SELECT team_id FROM phones WHERE ID = $nan")->fetch_row()[0];
            return ['breach' => $now === (int)$atk->team,
                'note' => 'Kéo được số của team 1 về team ' . $atk->team];
        }, 'NGHIÊM TRỌNG');

    $h->attack('Phones', 'Nhét giá trị lạ vào cột status', 'USR_VIEW', function ($atk, $fx) {
        $id = $fx->new_phone((int)$atk->team, 'active');
        $_POST = ['csrf_token' => $_SESSION['csrf_token'], 'id' => $id,
            'status' => "x' , number='+10000000000"];
        savePhone();
        $r = $fx->conn->query("SELECT status, number FROM phones WHERE ID = $id")->fetch_assoc();
        return ['breach' => $r['status'] !== 'active' || !str_starts_with($r['number'], '+1900ZZAB'),
            'note' => 'status=' . $r['status'] . ' number=' . $r['number']];
    }, 'NGHIÊM TRỌNG');

    $h->attack('Phones', 'Trộn số team khác vào lệnh đổi trạng thái hàng loạt', 'MGR_OUT',
        function ($atk, $fx) {
            $minh = $fx->new_phone((int)$atk->team, 'active');
            $nan  = $fx->new_phone(1, 'active');
            $_POST = ['csrf_token' => $_SESSION['csrf_token'], 'ids' => [$minh, $nan],
                'status' => 'suspend'];
            updatePhonesStatusBulk();
            $now = (string)$fx->conn->query("SELECT status FROM phones WHERE ID = $nan")->fetch_row()[0];
            return ['breach' => $now === 'suspend',
                'note' => 'Số của team khác cũng bị đổi theo'];
        }, 'NGHIÊM TRỌNG');

    $h->attack('Phones', 'Đánh dấu đã đọc tin của số khác bằng cách nhồi ID', 'USR_VIEW',
        function ($atk, $fx) {
            $cua_toi = $fx->new_phone((int)$atk->team);
            $cua_ho  = $fx->new_phone(1);
            $sms     = $fx->new_sms($cua_ho, 'pending');
            $_POST = ['csrf_token' => $_SESSION['csrf_token'],
                'phone_id' => $cua_toi, 'ids' => [$sms]];
            markSmsRead();
            $now = (string)$fx->conn->query("SELECT status FROM sms WHERE ID = $sms")->fetch_row()[0];
            return ['breach' => $now === 'viewed', 'note' => 'Đánh dấu được tin của số khác'];
        });

    // ---------- CSRF ----------
    $h->attack('Phones', 'Xóa số bằng CSRF (không biết token)', 'USR_VIEW', function ($atk, $fx) {
        $id = $fx->new_phone((int)$atk->team);
        $_POST = ['csrf_token' => 'DOAN-BUA', 'ids' => [$id]];
        deletePhonesBulk();
        return ['breach' => $fx->conn->query("SELECT ID FROM phones WHERE ID = $id")->fetch_row() === null,
            'note' => 'Xóa được mà không có CSRF token'];
    }, 'NGHIÊM TRỌNG');

    $h->attack('Phones', 'Đổi trạng thái hàng loạt bằng CSRF', 'USR_VIEW', function ($atk, $fx) {
        $id = $fx->new_phone((int)$atk->team, 'active');
        $_POST = ['csrf_token' => 'DOAN-BUA', 'ids' => [$id], 'status' => 'suspend'];
        updatePhonesStatusBulk();
        $now = (string)$fx->conn->query("SELECT status FROM phones WHERE ID = $id")->fetch_row()[0];
        return ['breach' => $now === 'suspend', 'note' => 'Đổi được mà không có CSRF token'];
    }, 'NGHIÊM TRỌNG');

    // ---------- Vai chỉ có view mà đòi ghi ----------
    $h->attack('Phones', 'Vai chỉ có VIEW mà xóa số', 'USR_VIEW', function ($atk, $fx) {
        $id = $fx->new_phone((int)$atk->team);
        $_POST = ['csrf_token' => $_SESSION['csrf_token'], 'ids' => [$id]];
        deletePhonesBulk();
        return ['breach' => $fx->conn->query("SELECT ID FROM phones WHERE ID = $id")->fetch_row() === null,
            'note' => 'Chỉ có role view mà xóa được'];
    }, 'NGHIÊM TRỌNG');

    // ---------- Cửa công khai: webhook ----------
    $h->attack('Phones', 'Webhook nhận tin với key SAI', 'ANON', function ($atk, $fx) {
        $id = $fx->new_phone(1, 'active');
        $so = (string)$fx->conn->query("SELECT number FROM phones WHERE ID = $id")->fetch_row()[0];
        $_GET = ['key' => 'khong-phai-key-that-' . bin2hex(random_bytes(4))];
        $res = hookTelnyx();
        $n = (int)$fx->conn->query("SELECT COUNT(*) FROM sms WHERE phone_id = $id")->fetch_row()[0];
        $_GET = [];
        return ['breach' => $n > 0 || ($res['status'] ?? '') === 'success',
            'note' => 'Trả về: ' . ($res['message'] ?? '?')];
    }, 'NGHIÊM TRỌNG');

    $h->attack('Phones', 'Webhook không có key', 'ANON', function ($atk, $fx) {
        $_GET = [];
        $res = hookTelnyx();
        return ['breach' => ($res['status'] ?? '') === 'success',
            'note' => 'Trả về: ' . ($res['message'] ?? '?')];
    }, 'NGHIÊM TRỌNG');

    // ---------- XSS ----------
    $h->attack('Phones', 'Nội dung SMS render không bọc esc()', 'USR_VIEW', function ($atk, $fx) {
        $js = (string)file_get_contents(AB_ROOT . '/assets/js/app-phones-list.js');
        // latest_sms_text và text trong modal là nội dung TIN NHẮN ĐẾN — do người lạ soạn
        return ['breach' => !str_contains($js, "esc(full['latest_sms_text'])")
                || !str_contains($js, 'esc(m.text)') || !str_contains($js, 'esc(m.from)'),
            'note' => 'Field free-text nhét thẳng vào HTML'];
    }, 'NGHIÊM TRỌNG');
};
