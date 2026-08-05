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

    // ---------- Xóa dây chuyền ----------
    $r->add('Teams — xóa', 'Xem trước những gì sẽ bị xóa', function ($a, $fx) {
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $fx->new_team()];
        $res = Teams::get_purge_preview();
        return ($res['status'] ?? '') === 'error' ? $res : isset($res['counts']['members']);
    })->allow('ADMIN');

    $r->add('Teams — xóa', 'Xóa team rỗng', function ($a, $fx) {
        $id = $fx->new_team();
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id];
        $res = Teams::purge_team();
        $gone = $fx->conn->query("SELECT ID FROM team WHERE ID = $id")->fetch_row() === null;
        return $gone ? $res : ['status' => 'error', 'message' => 'Team vẫn còn'];
    })->allow('ADMIN');

    $r->add('Teams — xóa', 'Xóa team KÉO THEO user + account + đơn hàng của team đó', function ($a, $fx) {
        [$teamId, $authorId, $accountId, $orderId, $postId] = $fx->new_team_with_data();
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $teamId];
        $res = Teams::purge_team();
        if (($res['status'] ?? '') === 'error') {
            return $res;
        }
        // Kiểm HIỆU QUẢ THẬT: mọi thứ dùng riêng phải sạch
        $left = 0;
        $left += (int)$fx->conn->query("SELECT COUNT(*) FROM team WHERE ID = $teamId")->fetch_row()[0];
        $left += (int)$fx->conn->query("SELECT COUNT(*) FROM authors WHERE ID = $authorId")->fetch_row()[0];
        $left += (int)$fx->conn->query("SELECT COUNT(*) FROM accounts WHERE ID = $accountId")->fetch_row()[0];
        $left += (int)$fx->conn->query("SELECT COUNT(*) FROM orders WHERE ID = $orderId")->fetch_row()[0];
        $left += (int)$fx->conn->query("SELECT COUNT(*) FROM posts WHERE ID = $postId")->fetch_row()[0];
        return $left === 0 ? $res : ['status' => 'error', 'message' => "Còn sót $left bản ghi"];
    })->allow('ADMIN');

    // files dùng chung bảng cho cả tệp account lẫn logo site -> phải xóa đúng phần của
    // account, tuyệt đối không đụng file type='sites'.
    $r->add('Teams — xóa', 'Xóa tệp của account nhưng GIỮ logo site', function ($a, $fx) {
        [$teamId, , $accountId] = $fx->new_team_with_data();
        $accFile  = $fx->new_file('accounts');
        $siteFile = $fx->new_file('sites');
        $fx->conn->execute_query(
            'INSERT IGNORE INTO accounts_files (account_id, file_id) VALUES (?, ?)', [$accountId, $accFile]);

        $_POST = ['csrf_token' => 'ABTEST', 'id' => $teamId];
        $res = Teams::purge_team();
        if (($res['status'] ?? '') === 'error') {
            return $res;
        }
        $accGone = $fx->conn->query("SELECT ID FROM files WHERE ID = $accFile")->fetch_row() === null;
        $siteKept = $fx->conn->query("SELECT ID FROM files WHERE ID = $siteFile")->fetch_row() !== null;
        return ($accGone && $siteKept)
            ? $res
            : ['status' => 'error', 'message' => 'accGone=' . var_export($accGone, true) . ' siteKept=' . var_export($siteKept, true)];
    })->allow('ADMIN');

    $r->add('Teams — xóa', 'GIỮ dữ liệu dùng chung (site/category/store chung)', function ($a, $fx) {
        [$teamId] = $fx->new_team_with_data();
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $teamId];
        $res = Teams::purge_team();
        // Bị chặn -> trả nguyên lỗi để chấm DENY; nếu không, actor không có quyền sẽ
        // "đạt" một cách giả tạo chỉ vì purge không hề chạy.
        if (($res['status'] ?? '') === 'error') {
            return $res;
        }
        // 3 bản ghi dùng chung của fixture phải còn nguyên sau khi xóa team
        $kept = (int)$fx->conn->query("SELECT COUNT(*) FROM site WHERE ID = {$fx->site}")->fetch_row()[0]
            + (int)$fx->conn->query("SELECT COUNT(*) FROM `type` WHERE ID = {$fx->catShared}")->fetch_row()[0]
            + (int)$fx->conn->query("SELECT COUNT(*) FROM store WHERE ID = {$fx->storeShared}")->fetch_row()[0];
        return $kept === 3 ? $res : ['status' => 'error', 'message' => "Dữ liệu dùng chung chỉ còn $kept/3"];
    })->allow('ADMIN');

    // AN TOÀN: dùng team ZZAB nháp làm "team của tôi" thay vì team thật — nếu guard hỏng
    // thì chỉ mất bản ghi nháp, không đụng dữ liệu sống.
    $r->add('Teams — xóa', 'KHÔNG cho xóa team của chính mình', function ($a, $fx) {
        $id = $fx->new_team();
        $save = $_SESSION['auth']['team'] ?? 0;
        $_SESSION['auth']['team'] = $id;
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id];
        Teams::purge_team();
        $_SESSION['auth']['team'] = $save;
        $still = $fx->conn->query("SELECT ID FROM team WHERE ID = $id")->fetch_row() !== null;
        // ALLOW = team của chính mình bị xóa -> không ai được phép
        return !$still;
    })->allow();

    $r->add('Teams — xóa', 'Thiếu CSRF thì không xóa được', function ($a, $fx) {
        $id = $fx->new_team();
        $_POST = ['id' => $id];
        Teams::purge_team();
        return $fx->conn->query("SELECT ID FROM team WHERE ID = $id")->fetch_row() === null;
    })->allow();

    // ---------- Team ngừng hoạt động (status = 0) ----------
    // Luật: team Inactive thì chặn hoàn toàn — login, phiên đang mở, cookie nhớ đăng nhập,
    // ajax, và API extension. ADMIN không bị chặn (nếu không sẽ tự nhốt).

    $r->add('Teams — khóa team', 'team_is_active() báo đúng trạng thái', function ($a, $fx) {
        $id = $fx->new_team();
        $fx->conn->query("UPDATE team SET status = 0 WHERE ID = $id");
        $off = team_is_active($id);
        $fx->conn->query("UPDATE team SET status = 1 WHERE ID = $id");
        // ALLOW = hàm báo team Inactive vẫn đang hoạt động -> sai, không ai được vậy
        return $off;
    })->allow();

    $r->add('Teams — khóa team', 'Phiên của team Inactive bị chặn (admin miễn)', function ($a, $fx) {
        $team = (int)$a->team;
        if ($team <= 0) {
            return false;
        }
        $before = (int)$fx->conn->query("SELECT status FROM team WHERE ID = $team")->fetch_row()[0];
        $fx->conn->query("UPDATE team SET status = 0 WHERE ID = $team");
        $blocked = current_team_blocked();
        $fx->conn->query("UPDATE team SET status = $before WHERE ID = $team");
        // ALLOW = KHÔNG bị chặn -> chỉ admin mới được phép lọt qua
        return !$blocked;
    })->allow('ADMIN');

    $r->add('Teams — khóa team', 'Key extension của team Inactive hết hiệu lực', function ($a, $fx) {
        $id = $fx->new_team();
        $key = (string)$fx->conn->query("SELECT `key` FROM team WHERE ID = $id")->fetch_row()[0];
        $fx->conn->query("UPDATE team SET status = 0 WHERE ID = $id");
        $found = $fx->conn->execute_query(
            'SELECT ID FROM team WHERE `key` = ? AND status = 1 LIMIT 1', [$key])->fetch_row();
        // ALLOW = key vẫn tra ra team -> thủng, không ai được phép
        return $found !== null;
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
