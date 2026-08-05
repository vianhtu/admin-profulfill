<?php
/**
 * AB TEST — Teams / Team.
 *
 * Luật (chốt 05/08/2026): menu ADMIN-ONLY, KHÔNG đi qua roles_permissions.
 * Vì vậy mọi phép thử ở đây chỉ ADMIN được phép — kể cả actor có đủ 4 role
 * (MGR_T1/USR_T1...) cũng phải bị chặn. Đây chính là điểm khác các bộ khác.
 *
 * Team là bản ghi cha: còn authors/accounts/store tham chiếu thì KHÔNG được xóa.
 */

return function (AbRunner $r): void {

    // ---------- Đọc ----------
    $r->add('Teams — đọc', 'Thấy danh sách team', function ($a, $fx) {
        $_POST = ab_dt(['search' => ['value' => 'ZZAB Team']]);
        return ab_sees(Teams::get_teams(), $fx->teamEmpty);
    })->allow('ADMIN');

    $r->add('Teams — đọc', 'Có role đủ 4 quyền vẫn KHÔNG xem được (admin-only)', function ($a, $fx) {
        $_POST = ab_dt();
        $res = Teams::get_teams();
        return !empty($res['data']);
    })->allow('ADMIN');

    // ---------- Ghi ----------
    $r->add('Teams — ghi', 'Thêm team mới', function ($a, $fx) {
        $name = 'ZZAB Team ' . bin2hex(random_bytes(4));
        $_POST = ['csrf_token' => 'ABTEST', 'id' => 0, 'name' => $name, 'status' => 1];
        $res = Team::save_team();
        if (($res['status'] ?? '') === 'error') {
            return $res;
        }
        // Kiểm hiệu quả thật + key phải được sinh tự động
        $row = $fx->conn->query('SELECT `key` FROM team WHERE ID = ' . (int)$res['id'])->fetch_row();
        return ($row && strlen((string)$row[0]) >= 16) ? $res : ['status' => 'error', 'message' => 'Key không được sinh'];
    })->allow('ADMIN');

    $r->add('Teams — ghi', 'Sửa tên team', function ($a, $fx) {
        $id = $fx->new_team();
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id, 'name' => 'ZZAB Team Renamed ' . $id, 'status' => 0];
        $res = Team::save_team();
        if (($res['status'] ?? '') === 'error') {
            return $res;
        }
        $now = $fx->conn->query("SELECT name FROM team WHERE ID = $id")->fetch_row()[0];
        return str_contains($now, 'Renamed') ? $res : ['status' => 'error', 'message' => 'DB không đổi'];
    })->allow('ADMIN');

    $r->add('Teams — ghi', 'Không sửa được `key` qua form', function ($a, $fx) {
        $id = $fx->new_team();
        $before = $fx->conn->query("SELECT `key` FROM team WHERE ID = $id")->fetch_row()[0];
        // Cố tình gửi kèm key để xem server có nhận không
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id, 'name' => 'ZZAB Team K ' . $id,
            'status' => 1, 'key' => 'ZZABHACKEDKEY'];
        Team::save_team();
        $after = $fx->conn->query("SELECT `key` FROM team WHERE ID = $id")->fetch_row()[0];
        // ALLOW = key BỊ đổi (tức là thủng) -> không actor nào được phép
        return $after !== $before;
    })->allow();

    $r->add('Teams — ghi', 'Tên trùng bị từ chối', function ($a, $fx) {
        $name = $fx->conn->query("SELECT name FROM team WHERE ID = {$fx->teamEmpty}")->fetch_row()[0];
        $_POST = ['csrf_token' => 'ABTEST', 'id' => 0, 'name' => $name, 'status' => 1];
        $res = Team::save_team();
        // Phải bị chặn với mọi actor (admin cũng không được tạo trùng)
        return ($res['status'] ?? '') !== 'error';
    })->allow();

    $r->add('Teams — ghi', 'Thiếu CSRF thì không lưu được', function ($a, $fx) {
        $name = 'ZZAB Team NoCsrf ' . bin2hex(random_bytes(4));
        $_POST = ['id' => 0, 'name' => $name, 'status' => 1];   // thiếu csrf_token
        Team::save_team();
        return $fx->conn->query("SELECT ID FROM team WHERE name = '" . $fx->conn->real_escape_string($name) . "'")->fetch_row() !== null;
    })->allow();

    // ---------- Xóa ----------
    $r->add('Teams — xóa', 'Xóa team rỗng (không ai tham chiếu)', function ($a, $fx) {
        $id = $fx->new_team();
        $_POST = ['csrf_token' => 'ABTEST', 'ids' => [$id]];
        $res = Teams::delete_teams();
        $gone = $fx->conn->query("SELECT ID FROM team WHERE ID = $id")->fetch_row() === null;
        return $gone ? $res : ['status' => 'error', 'message' => 'Team vẫn còn'];
    })->allow('ADMIN');

    $r->add('Teams — xóa', 'CHẶN xóa team còn thành viên', function ($a, $fx) {
        // team 1 luôn có authors thật -> phải bị từ chối kể cả với admin
        $_POST = ['csrf_token' => 'ABTEST', 'ids' => [1]];
        $res = Teams::delete_teams();
        $still = $fx->conn->query('SELECT ID FROM team WHERE ID = 1')->fetch_row() !== null;
        // ALLOW = team thật bị xóa mất -> không ai được phép
        return !$still;
    })->allow();

    $r->add('Teams — xóa', 'Thiếu CSRF thì không xóa được', function ($a, $fx) {
        $id = $fx->new_team();
        $_POST = ['ids' => [$id]];
        Teams::delete_teams();
        return $fx->conn->query("SELECT ID FROM team WHERE ID = $id")->fetch_row() === null;
    })->allow();

    // ---------- Lớp giao diện ----------
    $r->add('Teams — giao diện', 'Trang Teams hiện ra (chỉ admin)',
        fn($a, $fx) => ab_render('app-teams-list.php') !== ''
    )->allow('ADMIN');

    $r->add('Teams — giao diện', 'Có form Add/Edit + modal View Key',
        fn($a, $fx) => ab_has(ab_render('app-teams-list.php'), 'offcanvasTeam', 'viewKeyModal')
    )->allow('ADMIN');

    $r->add('Teams — giao diện', 'Menu Teams KHÔNG render cho non-admin', function ($a, $fx) {
        ob_start();
        renderMenu('teams');
        $html = (string)ob_get_clean();
        return str_contains($html, 'menu=teams');
    })->allow('ADMIN');
};
