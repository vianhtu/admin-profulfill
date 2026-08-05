<?php
/**
 * HACK — Stores / Store (chung + riêng). Kẻ tấn công cố chạm store riêng của team khác,
 * chiếm store dùng chung, và giả mạo Owner.
 */

return function (HackRunner $h): void {

    $h->attack('Auth bypass', 'Khách chưa đăng nhập đọc store', 'ANON', function ($atk, $fx) {
        $_POST = ab_dt();
        $res = Stores::get_stores();
        return ['breach' => !empty($res['data']), 'note' => count($res['data']) . ' dòng'];
    });

    $h->attack('IDOR (đọc)', 'USER team 3 mở form sửa store riêng team 2', 'USR_OUT', function ($atk, $fx) {
        return ['breach' => Store::get_store($fx->storeT2) !== null, 'note' => 'Đọc được store riêng team 2'];
    });

    $h->attack('IDOR (đọc)', 'Select2 rò store riêng team 2 cho team 3', 'USR_OUT', function ($atk, $fx) {
        $_POST = ['q' => 'ZZAB Store T2', 'page' => 1];
        $items = get_stores_select_options()['items'] ?? [];
        return ['breach' => count($items) > 0, 'note' => count($items) . ' store lọt vào select'];
    });

    $h->attack('IDOR (ghi)', 'USER team 3 sửa store riêng team 2', 'USR_OUT', function ($atk, $fx) {
        $row = $fx->conn->query("SELECT name, slug, site_id FROM store WHERE ID = {$fx->storeT2}")->fetch_assoc();
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'id' => $fx->storeT2, 'name' => 'hacked',
            'slug' => $row['slug'], 'site_id' => (int)$row['site_id'], 'status' => 0];
        Store::save_store();
        $name = $fx->conn->query("SELECT name FROM store WHERE ID = {$fx->storeT2}")->fetch_row()[0];
        return ['breach' => $name === 'hacked', 'note' => 'Tên store team 2 sau đòn đánh: ' . $name];
    });

    $h->attack('IDOR (ghi)', 'Non-admin sửa store DÙNG CHUNG', 'MGR_OUT', function ($atk, $fx) {
        $id = $fx->new_store(0);
        $row = $fx->conn->query("SELECT name, slug, site_id FROM store WHERE ID = $id")->fetch_assoc();
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'id' => $id, 'name' => 'hacked',
            'slug' => $row['slug'], 'site_id' => (int)$row['site_id'], 'status' => 0, 'team_id' => 0];
        Store::save_store();
        $name = $fx->conn->query("SELECT name FROM store WHERE ID = $id")->fetch_row()[0];
        return ['breach' => $name === 'hacked', 'note' => 'Store dùng chung bị non-admin sửa'];
    });

    $h->attack('Giả mạo tham số', 'Non-admin gán store về team khác (team_id)', 'USR_OUT', function ($atk, $fx) {
        $id = $fx->new_store($atk->team);   // của team mình
        $row = $fx->conn->query("SELECT name, slug, site_id FROM store WHERE ID = $id")->fetch_assoc();
        // Cố chuyển sang team 1
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'id' => $id, 'name' => $row['name'],
            'slug' => $row['slug'], 'site_id' => (int)$row['site_id'], 'status' => 1, 'team_id' => 1];
        Store::save_store();
        $team = (int)$fx->conn->query("SELECT team_id FROM store WHERE ID = $id")->fetch_row()[0];
        return ['breach' => $team === 1, 'note' => 'team_id sau đòn đánh = ' . $team . ' (phải giữ ' . $atk->team . ')'];
    });

    $h->attack('Leo thang quyền', 'USER chỉ-view xóa store riêng team mình', 'USR_VIEW', function ($atk, $fx) {
        $_POST = ['ids' => [$fx->new_store($atk->team)], 'csrf_token' => 'VICTIM_SECRET'];
        $res = Stores::delete_stores();
        return ['breach' => ($res['status'] ?? '') === 'success', 'note' => 'Xóa được dù chỉ view'];
    });

    $h->attack('SQL injection', 'Payload trong ô tìm kiếm', 'USR_OUT', function ($atk, $fx) {
        $_POST = ab_dt(['search' => ['value' => "zzab_x' OR '1'='1"]]);
        $res = Stores::get_stores();
        // Kẻ tấn công team 3 chỉ được thấy store chung + store team 3; nếu injection thì thấy cả 5000+
        return ['breach' => $res['recordsFiltered'] > 100, 'note' => 'filtered = ' . $res['recordsFiltered']];
    });

    $h->attack('SQL injection', 'Payload trong ID khi xóa', 'MGR_OUT', function ($atk, $fx) {
        $before = hack_count($fx->conn, 'SELECT COUNT(*) FROM store');
        $_POST = ['ids' => ['1 OR 1=1', '1); DELETE FROM store;--'], 'csrf_token' => 'VICTIM_SECRET'];
        Stores::delete_stores();
        $after = hack_count($fx->conn, 'SELECT COUNT(*) FROM store');
        return ['breach' => $after < $before, 'note' => "Số store: $before -> $after"];
    });

    $h->attack('CSRF', 'Xóa store KHÔNG kèm csrf_token', 'MGR_OUT', function ($atk, $fx) {
        $victim = $fx->new_store($atk->team);
        $_POST = ['ids' => [$victim]];   // không token
        Stores::delete_stores();
        $gone = hack_count($fx->conn, "SELECT COUNT(*) FROM store WHERE ID = $victim") === 0;
        return ['breach' => $gone, 'note' => 'Xóa được store mà không cần csrf_token'];
    });
};
