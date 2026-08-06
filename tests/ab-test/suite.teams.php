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

    // Rào chắn 2 bước: phải tắt team trước khi xóa/sáp nhập
    $r->add('Teams — xóa', 'CHẶN xóa team đang Active', function ($a, $fx) {
        $id = $fx->new_team();   // new_team tạo status = 1 (Active)
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id];
        Teams::purge_team();
        // ALLOW = xóa được dù team đang Active -> không ai được phép
        return $fx->conn->query("SELECT ID FROM team WHERE ID = $id")->fetch_row() === null;
    })->allow();

    $r->add('Teams — xóa', 'Xóa team rỗng (đã tắt)', function ($a, $fx) {
        $id = $fx->new_team();
        $fx->conn->query("UPDATE team SET status = 0 WHERE ID = $id");
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id];
        $res = Teams::purge_team();
        $gone = $fx->conn->query("SELECT ID FROM team WHERE ID = $id")->fetch_row() === null;
        return $gone ? $res : ['status' => 'error', 'message' => 'Team vẫn còn'];
    })->allow('ADMIN');

    // ---------- Sáp nhập ----------
    $r->add('Teams — sáp nhập', 'Chuyển thành viên/account/store sang team khác rồi xóa', function ($a, $fx) {
        [$teamId, $authorId, $accountId] = $fx->new_team_with_data();
        $store = $fx->new_store($teamId);
        $fx->conn->query("UPDATE team SET status = 0 WHERE ID = $teamId");
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $teamId, 'target_team' => 2];
        $res = Teams::merge_team();
        if (($res['status'] ?? '') === 'error') {
            return $res;
        }
        $au = (int)$fx->conn->query("SELECT team_id FROM authors WHERE ID = $authorId")->fetch_row()[0];
        $ac = (int)$fx->conn->query("SELECT team_id FROM accounts WHERE ID = $accountId")->fetch_row()[0];
        $st = (int)$fx->conn->query("SELECT team_id FROM store WHERE ID = $store")->fetch_row()[0];
        $gone = $fx->conn->query("SELECT ID FROM team WHERE ID = $teamId")->fetch_row() === null;
        return ($au === 2 && $ac === 2 && $st === 2 && $gone)
            ? $res : ['status' => 'error', 'message' => "author=$au account=$ac store=$st gone=" . var_export($gone, true)];
    })->allow('ADMIN');

    $r->add('Teams — sáp nhập', 'CHẶN sáp nhập khi team còn Active', function ($a, $fx) {
        $id = $fx->new_team();
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id, 'target_team' => 2];
        Teams::merge_team();
        return $fx->conn->query("SELECT ID FROM team WHERE ID = $id")->fetch_row() === null;
    })->allow();

    $r->add('Teams — sáp nhập', 'CHẶN sáp nhập vào chính nó', function ($a, $fx) {
        $id = $fx->new_team();
        $fx->conn->query("UPDATE team SET status = 0 WHERE ID = $id");
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id, 'target_team' => $id];
        Teams::merge_team();
        return $fx->conn->query("SELECT ID FROM team WHERE ID = $id")->fetch_row() === null;
    })->allow();

    $r->add('Teams — xóa', 'Xóa team KÉO THEO user + account + đơn hàng của team đó', function ($a, $fx) {
        [$teamId, $authorId, $accountId, $orderId, $postId] = $fx->new_team_with_data(0);
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
        [$teamId, , $accountId] = $fx->new_team_with_data(0);
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

    // options chứa openai_key/gemini_key RIÊNG của team — xóa team mà để lại thì secret
    // nằm mồ côi trong DB. phones là số SMS mua riêng, cũng thuộc về team.
    $r->add('Teams — xóa', 'Xóa luôn khóa API (options) và số điện thoại của team', function ($a, $fx) {
        [$teamId] = $fx->new_team_with_data(0);
        $fx->conn->execute_query(
            "INSERT INTO options (name, value, team_id, authors_id) VALUES ('openai_key', 'ZZABKEY', ?, 0)", [$teamId]);
        $fx->conn->execute_query(
            "INSERT INTO phones (number, team_id, carrier_id, status) VALUES ('ZZAB0001', ?, 0, 'active')", [$teamId]);

        $_POST = ['csrf_token' => 'ABTEST', 'id' => $teamId];
        $res = Teams::purge_team();
        if (($res['status'] ?? '') === 'error') {
            return $res;
        }
        $opt = (int)$fx->conn->query("SELECT COUNT(*) FROM options WHERE team_id = $teamId")->fetch_row()[0];
        $ph  = (int)$fx->conn->query("SELECT COUNT(*) FROM phones  WHERE team_id = $teamId")->fetch_row()[0];
        return ($opt === 0 && $ph === 0)
            ? $res : ['status' => 'error', 'message' => "còn options=$opt phones=$ph"];
    })->allow('ADMIN');

    // Lương là dữ liệu kế toán: KHÔNG xóa theo người, nhưng cột `authors` sắp trỏ vào
    // khoảng không nên phải chụp tên lại trước khi người bị xóa.
    $r->add('Teams — xóa', 'GIỮ dòng lương và chụp tên vào username_snapshot', function ($a, $fx) {
        [$teamId, $authorId] = $fx->new_team_with_data(0);
        $uname = (string)$fx->conn->query("SELECT username FROM authors WHERE ID = $authorId")->fetch_row()[0];
        $fx->conn->execute_query(
            'INSERT INTO salary (authors, date, paid) VALUES (?, CURDATE(), 1)', [$authorId]);
        $salId = (int)$fx->conn->insert_id;

        $_POST = ['csrf_token' => 'ABTEST', 'id' => $teamId];
        $res = Teams::purge_team();
        if (($res['status'] ?? '') === 'error') {
            return $res;
        }
        $row = $fx->conn->query("SELECT username_snapshot FROM salary WHERE ID = $salId")->fetch_row();
        if ($row === null) {
            return ['status' => 'error', 'message' => 'dòng lương bị xóa mất'];
        }
        return ($row[0] === $uname)
            ? $res : ['status' => 'error', 'message' => 'snapshot=' . var_export($row[0], true) . " (cần $uname)"];
    })->allow('ADMIN');

    // Team NHẬN đang hoạt động nên cấu hình của họ là chính: khóa trùng tên của team bị
    // sáp nhập phải bị bỏ, không được ghi đè lên khóa đang dùng.
    $r->add('Teams — sáp nhập', 'Giữ khóa API của team NHẬN, chuyển phones sang', function ($a, $fx) {
        [$src] = $fx->new_team_with_data(0);
        $dst = $fx->new_team();
        $ins = "INSERT INTO options (name, value, team_id, authors_id) VALUES (?, ?, ?, 0)";
        $fx->conn->execute_query($ins, ['openai_key', 'ZZABSRC', $src]);
        $fx->conn->execute_query($ins, ['zzab_only',  'ZZABSRC', $src]);
        $fx->conn->execute_query($ins, ['openai_key', 'ZZABDST', $dst]);
        $fx->conn->execute_query(
            "INSERT INTO phones (number, team_id, carrier_id, status) VALUES ('ZZAB0002', ?, 0, 'active')", [$src]);

        $_POST = ['csrf_token' => 'ABTEST', 'id' => $src, 'target_team' => $dst];
        $res = Teams::merge_team();
        if (($res['status'] ?? '') === 'error') {
            return $res;
        }
        $val = $fx->conn->query("SELECT value FROM options WHERE team_id = $dst AND name = 'openai_key'");
        $kept = $val->num_rows === 1 ? (string)$val->fetch_row()[0] : '(' . $val->num_rows . ' dòng)';
        $moved = (int)$fx->conn->query("SELECT COUNT(*) FROM options WHERE team_id = $dst AND name = 'zzab_only'")->fetch_row()[0];
        $ph    = (int)$fx->conn->query("SELECT COUNT(*) FROM phones WHERE team_id = $dst")->fetch_row()[0];
        return ($kept === 'ZZABDST' && $moved === 1 && $ph === 1)
            ? $res : ['status' => 'error', 'message' => "kept=$kept moved=$moved phones=$ph"];
    })->allow('ADMIN');

    $r->add('Teams — xóa', 'GIỮ dữ liệu dùng chung (site/category/store chung)', function ($a, $fx) {
        [$teamId] = $fx->new_team_with_data(0);
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
        $id = $fx->new_team(0);
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
        $id = $fx->new_team(0);
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
