<?php
/**
 * AB TEST — Stores / Store.
 * Luật: team_id = 0 là store dùng chung (chỉ admin sửa/xóa); team_id = N là store riêng
 * của team N (team đó tự sửa/xóa theo role). Thêm mới luôn thuộc team của người thêm.
 */

return function (AbRunner $r): void {

    $r->add('Store — đọc', 'Thấy store DÙNG CHUNG', function ($a, $fx) {
        $_POST = ab_dt(['search' => ['value' => 'ZZAB Store Shared']]);
        return ab_sees(Stores::get_stores(), $fx->storeShared);
    })->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'USR_T1', 'USR_T1_V', 'MGR_T2', 'USR_T2', 'USR_T3');

    $r->add('Store — đọc', 'Thấy store RIÊNG của team 2', function ($a, $fx) {
        $_POST = ab_dt(['search' => ['value' => 'ZZAB Store T2']]);
        return ab_sees(Stores::get_stores(), $fx->storeT2);
    })->allow('ADMIN', 'MGR_T2', 'USR_T2');

    $r->add('Store — đọc', 'Mở form sửa store riêng của team 2',
        fn($a, $fx) => Store::get_store($fx->storeT2) !== null
    )->allow('ADMIN', 'MGR_T2', 'USR_T2');

    $r->add('Store — đọc', 'Select store trong form Products không lộ store team khác', function ($a, $fx) {
        $_POST = ['q' => 'ZZAB Store T2', 'page' => 1];
        $items = get_stores_select_options()['items'] ?? [];
        return count($items) > 0;
    })->allow('ADMIN', 'MGR_T2', 'USR_T2');

    $r->add('Store — ghi', 'Thêm store mới', function ($a, $fx) {
        $slug = 'zzab-add-' . bin2hex(random_bytes(4));
        $siteId = (int)$fx->conn->query('SELECT ID FROM site ORDER BY ID LIMIT 1')->fetch_row()[0];
        $_POST = ['csrf_token' => 'ABTEST', 'id' => 0, 'name' => 'ZZAB ' . $slug,
            'slug' => $slug, 'site_id' => $siteId, 'status' => 1, 'team_id' => 0];
        return Store::save_store();
    })->allow('ADMIN', 'MGR_T1', 'USR_T1', 'MGR_T2', 'USR_T2', 'USR_T3');

    $r->add('Store — ghi', 'Store mới của non-admin phải thuộc TEAM của họ', function ($a, $fx) {
        $slug = 'zzab-own-' . bin2hex(random_bytes(4));
        $siteId = (int)$fx->conn->query('SELECT ID FROM site ORDER BY ID LIMIT 1')->fetch_row()[0];
        // Cố tình gửi team_id = 0 (dùng chung) để xem server có bị lừa không
        $_POST = ['csrf_token' => 'ABTEST', 'id' => 0, 'name' => 'ZZAB ' . $slug,
            'slug' => $slug, 'site_id' => $siteId, 'status' => 1, 'team_id' => 0];
        $res = Store::save_store();
        if (($res['status'] ?? '') === 'error') {
            return false;
        }
        $team = (int)$fx->conn->query('SELECT team_id FROM store WHERE ID = ' . (int)$res['id'])->fetch_row()[0];
        // admin gửi 0 thì đúng là dùng chung; người khác phải bị ép về team của mình
        return $a->level === 'admin' ? $team === 0 : $team === $a->team;
    })->allow('ADMIN', 'MGR_T1', 'USR_T1', 'MGR_T2', 'USR_T2', 'USR_T3');

    $r->add('Store — ghi', 'Sửa store DÙNG CHUNG', function ($a, $fx) {
        $id = $fx->new_store(0);
        $row = $fx->conn->query("SELECT name, slug, site_id FROM store WHERE ID = $id")->fetch_assoc();
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id, 'name' => $row['name'],
            'slug' => $row['slug'], 'site_id' => (int)$row['site_id'], 'status' => 0, 'team_id' => 0];
        return Store::save_store();
    })->allow('ADMIN');

    $r->add('Store — ghi', 'Sửa store RIÊNG của team mình', function ($a, $fx) {
        $id = $fx->new_store($a->team);
        $row = $fx->conn->query("SELECT name, slug, site_id FROM store WHERE ID = $id")->fetch_assoc();
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id, 'name' => $row['name'],
            'slug' => $row['slug'], 'site_id' => (int)$row['site_id'], 'status' => 0];
        return Store::save_store();
    })->allow('ADMIN', 'MGR_T1', 'USR_T1', 'MGR_T2', 'USR_T2', 'USR_T3');

    $r->add('Store — ghi', 'Sửa store riêng của TEAM KHÁC', function ($a, $fx) {
        $row = $fx->conn->query("SELECT name, slug, site_id FROM store WHERE ID = {$fx->storeT2}")->fetch_assoc();
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $fx->storeT2, 'name' => $row['name'],
            'slug' => $row['slug'], 'site_id' => (int)$row['site_id'], 'status' => 0];
        return Store::save_store();
    })->allow('ADMIN', 'MGR_T2', 'USR_T2');

    $r->add('Store — xóa', 'Xóa store DÙNG CHUNG (không có sản phẩm)', function ($a, $fx) {
        $_POST = ['ids' => [$fx->new_store(0)], 'csrf_token' => 'ABTEST'];
        return Stores::delete_stores();
    })->allow('ADMIN');

    $r->add('Store — xóa', 'Xóa store RIÊNG của team mình', function ($a, $fx) {
        $_POST = ['ids' => [$fx->new_store($a->team)], 'csrf_token' => 'ABTEST'];
        return Stores::delete_stores();
    })->allow('ADMIN', 'MGR_T1', 'USR_T1', 'MGR_T2', 'USR_T2', 'USR_T3');

    // Store còn sản phẩm của TEAM 2 đang dùng: người team 1 không được xóa (làm hỏng dữ
    // liệu team khác), admin và chính người team 2 thì được — sản phẩm chuyển Inactive.
    $r->add('Store — xóa', 'Xóa store còn sản phẩm của TEAM 2', function ($a, $fx) {
        $id = $fx->new_store($a->team);
        $fx->conn->query("UPDATE posts SET store_id = $id WHERE ID = {$fx->postT2}");
        $_POST = ['ids' => [$id], 'csrf_token' => 'ABTEST'];
        $res = Stores::delete_stores();
        $fx->conn->query("UPDATE posts SET store_id = {$fx->storeShared}, status = 'pending' WHERE ID = {$fx->postT2}");
        return $res;
    })->allow('ADMIN', 'MGR_T2', 'USR_T2');

    $r->add('Store — quyền UI', 'Cờ quyền trả về đúng (add/delete)', function ($a, $fx) {
        $res = Stores::get_stores_filters();
        if (($res['status'] ?? '') === 'error') {
            return false;
        }
        $p = $res['perms'];
        $canAdd = $a->level === 'admin' || in_array('add', $a->roles, true);
        $canDel = $a->level === 'admin' || ($a->team > 0 && in_array('delete', $a->roles, true));
        return $p['add'] === $canAdd && $p['delete'] === $canDel;
    })->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'USR_T1', 'USR_T1_V', 'MGR_T2', 'USR_T2', 'USR_T3');
};
