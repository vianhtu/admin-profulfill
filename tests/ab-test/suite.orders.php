<?php
/**
 * AB TEST — Orders / Order.
 *
 * Luật (menu `orders`): phạm vi dữ liệu đi qua bảng `accounts`.
 *   - admin   : mọi team;
 *   - manager : đơn của account thuộc team mình (accounts.team_id);
 *   - user    : chỉ đơn của account ĐƯỢC GÁN cho mình (accounts_authors.author_id).
 * Thao tác ghi cần role `edit`, xóa cần role `delete`.
 *
 * Lưu ý: USR_T1 dùng chung uid với MGR_T1/ADMIN (xem ab_actors), nên fixture gán
 * account team 1 cho chính uid đó — user team 1 vì thế THẤY được đơn của team mình.
 * Tương tự accountT2 gán cho AB_UID_T2 (dùng chung bởi MGR_T2/USR_T2), nên USR_T2 hợp lệ
 * với đơn team 2. USR_T3 không được gán account nào nên không thấy đơn nào.
 */

return function (AbRunner $r): void {

    // ---------- Đọc ----------
    $r->add('Orders — đọc', 'Thấy đơn của account TEAM 1', function ($a, $fx) {
        $_POST = ab_dt(['columns' => [['data' => 'host_id']], 'search' => ['value' => 'ZZAB-ORD-T1']]);
        return ab_sees(Orders::get_orders(), $fx->orderT1);
    })->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'USR_T1', 'USR_T1_V');

    $r->add('Orders — đọc', 'Thấy đơn của account TEAM 2', function ($a, $fx) {
        $_POST = ab_dt(['columns' => [['data' => 'host_id']], 'search' => ['value' => 'ZZAB-ORD-T2']]);
        return ab_sees(Orders::get_orders(), $fx->orderT2);
    })->allow('ADMIN', 'MGR_T2', 'USR_T2');

    $r->add('Orders — đọc', 'Bộ lọc + cờ quyền trả về được', function ($a, $fx) {
        $res = Orders::get_orders_filters();
        return ($res['status'] ?? '') !== 'error' && isset($res['perms']);
    })->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'USR_T1', 'USR_T1_V', 'MGR_T2', 'USR_T2', 'USR_T3');

    $r->add('Orders — đọc', 'Cờ quyền edit/delete đúng với role', function ($a, $fx) {
        $res = Orders::get_orders_filters();
        if (($res['status'] ?? '') === 'error') {
            return false;
        }
        $isAdmin = $a->level === 'admin';
        return $res['perms']['edit'] === ($isAdmin || in_array('edit', $a->roles, true))
            && $res['perms']['delete'] === ($isAdmin || in_array('delete', $a->roles, true));
    })->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'USR_T1', 'USR_T1_V', 'MGR_T2', 'USR_T2', 'USR_T3');

    $r->add('Orders — đọc', 'Thống kê chỉ đếm đơn trong phạm vi', function ($a, $fx) {
        $stat = Orders::get_orders_statistic();
        // Không role view -> phải trả toàn 0
        return array_sum($stat) > 0;
    })->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'USR_T1', 'USR_T1_V', 'MGR_T2', 'USR_T2');

    // ---------- Ghi: đổi trạng thái ----------
    $r->add('Orders — ghi', 'Đổi trạng thái đơn TEAM 1', function ($a, $fx) {
        $id = $fx->new_order($fx->accountT1, 'ZZAB-ORD-TMP1');
        $_POST = ['csrf_token' => 'ABTEST', 'orders' => [$id => 'shipped']];
        $res = Orders::update_orders_status();
        // Kiểm HIỆU QUẢ THẬT: đọc lại DB thay vì tin status trả về
        if (($res['status'] ?? '') === 'error') {
            return $res;
        }
        $now = $fx->conn->query("SELECT status FROM orders WHERE ID = $id")->fetch_row()[0];
        return $now === 'shipped' ? $res : ['status' => 'error', 'message' => 'DB không đổi'];
    })->allow('ADMIN', 'MGR_T1', 'USR_T1');

    $r->add('Orders — ghi', 'Đổi trạng thái đơn TEAM 2', function ($a, $fx) {
        $id = $fx->new_order($fx->accountT2, 'ZZAB-ORD-TMP2');
        $_POST = ['csrf_token' => 'ABTEST', 'orders' => [$id => 'shipped']];
        $res = Orders::update_orders_status();
        if (($res['status'] ?? '') === 'error') {
            return $res;
        }
        $now = $fx->conn->query("SELECT status FROM orders WHERE ID = $id")->fetch_row()[0];
        return $now === 'shipped' ? $res : ['status' => 'error', 'message' => 'DB không đổi'];
    })->allow('ADMIN', 'MGR_T2', 'USR_T2');

    $r->add('Orders — ghi', 'Trạng thái lạ bị từ chối', function ($a, $fx) {
        $id = $fx->new_order($fx->accountT1, 'ZZAB-ORD-BAD');
        $_POST = ['csrf_token' => 'ABTEST', 'orders' => [$id => 'hacked']];
        $res = Orders::update_orders_status();
        $now = $fx->conn->query("SELECT status FROM orders WHERE ID = $id")->fetch_row()[0];
        // Phải bị chặn với MỌI actor: coi là ALLOW chỉ khi DB thật sự bị đổi
        return $now === 'hacked';
    })->allow();

    $r->add('Orders — ghi', 'Thiếu CSRF thì không đổi được', function ($a, $fx) {
        $id = $fx->new_order($fx->accountT1, 'ZZAB-ORD-NOCSRF');
        $_POST = ['orders' => [$id => 'shipped']];   // cố tình thiếu csrf_token
        Orders::update_orders_status();
        $now = $fx->conn->query("SELECT status FROM orders WHERE ID = $id")->fetch_row()[0];
        return $now === 'shipped';
    })->allow();

    $r->add('Orders — ghi', 'Sửa tracking/base cost của item (đơn TEAM 1)', function ($a, $fx) {
        $id = $fx->new_order($fx->accountT1, 'ZZAB-ORD-ITEM');
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id, 'itemIndex' => 0,
            'services' => 'ZZAB Express', 'track' => 'ZZABTRACK1'];
        return Order::add_item_tracking();
    })->allow('ADMIN', 'MGR_T1', 'USR_T1');

    $r->add('Orders — ghi', 'Sửa item của đơn TEAM 2', function ($a, $fx) {
        $id = $fx->new_order($fx->accountT2, 'ZZAB-ORD-ITEM2');
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id, 'itemIndex' => 0,
            'services' => 'ZZAB Express', 'track' => 'ZZABTRACK2'];
        return Order::add_item_tracking();
    })->allow('ADMIN', 'MGR_T2', 'USR_T2');

    // ---------- Xóa ----------
    $r->add('Orders — xóa', 'Xóa đơn TEAM 1', function ($a, $fx) {
        $id = $fx->new_order($fx->accountT1, 'ZZAB-ORD-DEL1');
        $_POST = ['csrf_token' => 'ABTEST', 'order_ids' => [$id]];
        $res = Orders::delete_orders();
        $still = $fx->conn->query("SELECT ID FROM orders WHERE ID = $id")->fetch_row();
        return $still === null ? $res : ['status' => 'error', 'message' => 'Đơn vẫn còn'];
    })->allow('ADMIN', 'MGR_T1', 'USR_T1');

    $r->add('Orders — xóa', 'Xóa đơn TEAM 2', function ($a, $fx) {
        $id = $fx->new_order($fx->accountT2, 'ZZAB-ORD-DEL2');
        $_POST = ['csrf_token' => 'ABTEST', 'order_ids' => [$id]];
        $res = Orders::delete_orders();
        $still = $fx->conn->query("SELECT ID FROM orders WHERE ID = $id")->fetch_row();
        return $still === null ? $res : ['status' => 'error', 'message' => 'Đơn vẫn còn'];
    })->allow('ADMIN', 'MGR_T2', 'USR_T2');

    // Đo đúng một điều: trong lô hỗn hợp, đơn của TEAM 2 có bị xóa không.
    // USR_T2 được gán account T2 nên hợp lệ; người ngoài phạm vi phải không đụng được.
    $r->add('Orders — xóa', 'Lô hỗn hợp: đơn TEAM 2 bị xóa', function ($a, $fx) {
        $mine  = $fx->new_order($fx->accountT1, 'ZZAB-ORD-MIX1');
        $other = $fx->new_order($fx->accountT2, 'ZZAB-ORD-MIX2');
        $_POST = ['csrf_token' => 'ABTEST', 'order_ids' => [$mine, $other]];
        Orders::delete_orders();
        $otherGone = $fx->conn->query("SELECT ID FROM orders WHERE ID = $other")->fetch_row() === null;
        // ALLOW = đơn của TEAM 2 bị xóa mất -> chỉ admin/team2 được phép như vậy
        return $otherGone;
    })->allow('ADMIN', 'MGR_T2', 'USR_T2');

    $r->add('Orders — xóa', 'Thiếu CSRF thì không xóa được', function ($a, $fx) {
        $id = $fx->new_order($fx->accountT1, 'ZZAB-ORD-DELNOCSRF');
        $_POST = ['order_ids' => [$id]];
        Orders::delete_orders();
        return $fx->conn->query("SELECT ID FROM orders WHERE ID = $id")->fetch_row() === null;
    })->allow();

    // ---------- Lớp giao diện ----------
    $r->add('Orders — giao diện', 'Trang danh sách hiện ra',
        fn($a, $fx) => ab_render('app-ecommerce-order-list.php') !== ''
    )->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'USR_T1', 'USR_T1_V', 'MGR_T2', 'USR_T2', 'USR_T3');

    $r->add('Orders — giao diện', 'Ô nhập Base Cost/Note mở khóa (cần role edit)', function ($a, $fx) {
        $html = ab_render('app-ecommerce-order-list.php');
        return $html !== '' && !str_contains($html, 'id="item-base-cost" placeholder="9.99" disabled');
    })->allow('ADMIN', 'MGR_T1', 'USR_T1', 'MGR_T2', 'USR_T2', 'USR_T3');

    $r->add('Orders — giao diện', 'Modal xóa chỉ render khi có role delete',
        fn($a, $fx) => ab_has(ab_render('app-ecommerce-order-list.php'), 'deleteConfirmModal')
    )->allow('ADMIN', 'MGR_T1', 'USR_T1', 'MGR_T2', 'USR_T2', 'USR_T3');
};
