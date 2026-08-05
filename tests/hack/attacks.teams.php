<?php
/**
 * HACK — Teams / Team. Menu ADMIN-ONLY, nên trọng tâm là: người có ĐỦ ROLE nhưng không
 * phải admin có leo vào được không, và `key` của team (credential extension dùng để đẩy
 * dữ liệu vào hệ thống) có bị rò/ghi đè không.
 *
 * Rò `team.key` là nghiêm trọng: ai có key có thể gọi các endpoint extension-* nhân danh team.
 */

return function (HackRunner $h): void {

    // ---------- Auth bypass ----------
    $h->attack('Auth bypass', 'Khách chưa đăng nhập đọc danh sách team', 'ANON', function ($atk, $fx) {
        $_POST = ab_dt();
        $res = Teams::get_teams();
        return ['breach' => !empty($res['data']), 'note' => count($res['data']) . ' team lộ ra'];
    });

    $h->attack('Auth bypass', 'Khách chưa đăng nhập tạo team', 'ANON', function ($atk, $fx) {
        $name = 'ZZAB Team ANON ' . bin2hex(random_bytes(3));
        $_POST = ['csrf_token' => 'VICTIM', 'id' => 0, 'name' => $name, 'status' => 1];
        Team::save_team();
        $made = hack_count($fx->conn, "SELECT COUNT(*) FROM team WHERE name = '" . $fx->conn->real_escape_string($name) . "'") > 0;
        return ['breach' => $made, 'note' => 'Khách vãng lai tạo được team'];
    });

    // ---------- Leo thang quyền: đủ role nhưng không phải admin ----------
    $h->attack('Leo thang quyền', 'MANAGER đủ 4 role đọc danh sách team', 'MGR_OUT', function ($atk, $fx) {
        $_POST = ab_dt();
        $res = Teams::get_teams();
        return ['breach' => !empty($res['data']), 'note' => count($res['data']) . ' team lộ cho manager'];
    });

    $h->attack('Leo thang quyền', 'MANAGER đủ 4 role tạo team mới', 'MGR_OUT', function ($atk, $fx) {
        $name = 'ZZAB Team MGR ' . bin2hex(random_bytes(3));
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'id' => 0, 'name' => $name, 'status' => 1];
        Team::save_team();
        $made = hack_count($fx->conn, "SELECT COUNT(*) FROM team WHERE name = '" . $fx->conn->real_escape_string($name) . "'") > 0;
        return ['breach' => $made, 'note' => 'Manager tạo được team dù menu là admin-only'];
    });

    $h->attack('Leo thang quyền', 'USER đủ 4 role đổi tên team khác', 'USR_OUT', function ($atk, $fx) {
        $id = $fx->teamEmpty;
        $before = (string)$fx->conn->query("SELECT name FROM team WHERE ID = $id")->fetch_row()[0];
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'id' => $id, 'name' => 'ZZAB Hacked Name', 'status' => 1];
        Team::save_team();
        $after = (string)$fx->conn->query("SELECT name FROM team WHERE ID = $id")->fetch_row()[0];
        return ['breach' => $after !== $before, 'note' => 'Tên team sau đòn: ' . $after];
    });

    $h->attack('Leo thang quyền', 'USER đủ 4 role xóa team', 'USR_OUT', function ($atk, $fx) {
        $id = $fx->new_team();
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'ids' => [$id]];
        Teams::delete_teams();
        $gone = hack_count($fx->conn, "SELECT COUNT(*) FROM team WHERE ID = $id") === 0;
        return ['breach' => $gone, 'note' => 'Non-admin xóa được team'];
    });

    $h->attack('Leo thang quyền', 'Fragment Teams render cho non-admin', 'MGR_OUT', function ($atk, $fx) {
        $html = ab_render('app-teams-list.php');
        return ['breach' => $html !== '', 'note' => 'Fragment trả về ' . strlen($html) . ' ký tự HTML'];
    });

    $h->attack('Leo thang quyền', 'Menu Teams hiện trên sidebar của non-admin', 'MGR_OUT', function ($atk, $fx) {
        ob_start();
        renderMenu('products');
        $html = (string)ob_get_clean();
        return ['breach' => str_contains($html, 'menu=teams'), 'note' => 'Link menu=teams lọt vào sidebar'];
    }, 'THẤP');

    // ---------- Rò rỉ / ghi đè team.key ----------
    $h->attack('Rò rỉ dữ liệu', 'Non-admin lấy được `key` của team qua endpoint danh sách', 'MGR_OUT',
        function ($atk, $fx) {
            $_POST = ab_dt();
            $res = Teams::get_teams();
            foreach ($res['data'] ?? [] as $row) {
                if (!empty($row['key'])) {
                    return ['breach' => true, 'note' => 'key lộ cho non-admin'];
                }
            }
            return ['breach' => false];
        });

    $h->attack('Giả mạo tham số', 'Ghi đè `key` của team qua form save', 'MGR_OUT', function ($atk, $fx) {
        $id = $fx->teamEmpty;
        $before = (string)$fx->conn->query("SELECT `key` FROM team WHERE ID = $id")->fetch_row()[0];
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'id' => $id, 'name' => 'ZZAB K', 'status' => 1,
            'key' => 'ATTACKER_KEY'];
        Team::save_team();
        $after = (string)$fx->conn->query("SELECT `key` FROM team WHERE ID = $id")->fetch_row()[0];
        return ['breach' => $after !== $before, 'note' => 'key bị ghi đè -> chiếm được kênh extension'];
    });

    // ---------- Toàn vẹn dữ liệu ----------
    $h->attack('Toàn vẹn dữ liệu', 'Xóa team ĐANG có thành viên (tạo user mồ côi)', 'MGR_OUT',
        function ($atk, $fx) {
            $_POST = ['csrf_token' => 'VICTIM_SECRET', 'ids' => [1]];
            Teams::delete_teams();
            $gone = hack_count($fx->conn, 'SELECT COUNT(*) FROM team WHERE ID = 1') === 0;
            return ['breach' => $gone, 'note' => 'Team 1 (có thành viên thật) bị xóa'];
        });

    // ---------- SQL injection ----------
    $h->attack('SQL injection', 'Payload trong ô tìm kiếm team', 'MGR_OUT', function ($atk, $fx) {
        $_POST = ab_dt(['search' => ['value' => "zzab' OR '1'='1"]]);
        $res = Teams::get_teams();
        return ['breach' => !empty($res['data']), 'note' => 'filtered = ' . ($res['recordsFiltered'] ?? 0)];
    });

    $h->attack('SQL injection', 'Payload trong ID khi xóa team', 'MGR_OUT', function ($atk, $fx) {
        $before = hack_count($fx->conn, 'SELECT COUNT(*) FROM team');
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'ids' => ['1 OR 1=1', '1); DELETE FROM team;--']];
        Teams::delete_teams();
        $after = hack_count($fx->conn, 'SELECT COUNT(*) FROM team');
        return ['breach' => $after < $before, 'note' => "Số team: $before -> $after"];
    });

    $h->attack('SQL injection', 'Payload trong tên team khi lưu', 'MGR_OUT', function ($atk, $fx) {
        $before = hack_count($fx->conn, 'SELECT COUNT(*) FROM team');
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'id' => 0,
            'name' => "ZZAB'); DROP TABLE team;--", 'status' => 1];
        Team::save_team();
        $after = hack_count($fx->conn, 'SELECT COUNT(*) FROM team');
        return ['breach' => $after < $before, 'note' => "Số team: $before -> $after"];
    });

    // ---------- CSRF (chạy dưới danh nghĩa quyền cao nhất để cô lập đúng lớp CSRF) ----------
    // Non-admin đã bị chặn bởi lớp quyền nên không chứng minh được CSRF; ở đây kiểm bằng
    // cách đọc thẳng code: handler phải gọi check_csrf() TRƯỚC khi ghi.
    $h->attack('CSRF', 'Handler save/delete team thiếu check_csrf()', 'MGR_OUT', function ($atk, $fx) {
        $teams = (string)file_get_contents(AB_ROOT . '/class/class.teams.php');
        $team  = (string)file_get_contents(AB_ROOT . '/class/class.team.php');
        $ok = str_contains($teams, 'check_csrf()') && str_contains($team, 'check_csrf()');
        return ['breach' => !$ok, 'note' => 'Thiếu check_csrf() ở handler ghi/xóa team'];
    });

    // ---------- XSS ----------
    $h->attack('XSS (stored)', 'Tên team chứa <script> không được escape khi render', 'MGR_OUT',
        function ($atk, $fx) {
            $js = (string)file_get_contents(AB_ROOT . '/assets/js/app-teams-list.js');
            $ok = str_contains($js, 'function esc(') && str_contains($js, 'esc(name)');
            return ['breach' => !$ok, 'note' => 'JS render tên team KHÔNG bọc esc()'];
        });

    $h->attack('XSS (stored)', 'Tên team nhồi vào data-* của nút Edit/View Key', 'MGR_OUT',
        function ($atk, $fx) {
            $js = (string)file_get_contents(AB_ROOT . '/assets/js/app-teams-list.js');
            // data-name/data-key phải đi qua esc() nếu không sẽ thoát khỏi dấu nháy thuộc tính
            $ok = str_contains($js, 'data-name="${esc(full[\'name\'])}"')
                && str_contains($js, 'data-key="${esc(full[\'key\'])}"');
            return ['breach' => !$ok, 'note' => 'data-name/data-key chưa bọc esc() -> thoát được thuộc tính HTML'];
        });
};
