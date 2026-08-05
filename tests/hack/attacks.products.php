<?php
/**
 * HACK — Products / Product. Trục chính là PHẠM VI DỮ LIỆU theo team/chủ sở hữu:
 * kẻ tấn công cố đọc/sửa/xóa sản phẩm của team khác và giả mạo chủ sở hữu.
 */

// $_POST đầy đủ cho form sản phẩm (dùng riêng cho HACK để không phụ thuộc suite AB TEST).
if (!function_exists('hack_product_post')) {
    function hack_product_post(AbFixtures $fx, array $over = []): array
    {
        $siteId = (int)$fx->conn->query('SELECT ID FROM site ORDER BY ID LIMIT 1')->fetch_row()[0];
        return array_merge([
            'csrf_token' => 'VICTIM_SECRET', 'id' => 0, 'title' => 'ZZAB product',
            'sku' => 'ZZAB-' . bin2hex(random_bytes(4)), 'description' => '', 'status' => 'pending',
            'badge' => '', 'type_id' => $fx->catShared, 'site_id' => $siteId,
            'store_id' => $fx->storeShared, 'images' => json_encode(['http://x/a.jpg']), 'tags' => '[]',
        ], $over);
    }
}

return function (HackRunner $h): void {

    // ===== Auth bypass =====
    $h->attack('Auth bypass', 'Khách chưa đăng nhập đọc bảng sản phẩm', 'ANON', function ($atk, $fx) {
        $_POST = ab_dt(['skip_authors' => 1]);
        $res = Products::get_products();
        return ['breach' => $res['recordsTotal'] > 0, 'note' => 'recordsTotal = ' . $res['recordsTotal']];
    });

    $h->attack('Auth bypass', 'Khách chưa đăng nhập xem ảnh sản phẩm', 'ANON', function ($atk, $fx) {
        $_POST = ['id' => $fx->postT1];
        $res = Product::get_product_images();
        return ['breach' => ($res['status'] ?? '') === 'success', 'note' => 'Lấy được ảnh khi chưa đăng nhập'];
    });

    // ===== IDOR đọc =====
    $h->attack('IDOR (đọc)', 'USER team 3 mở form sửa sản phẩm team 1', 'USR_OUT', function ($atk, $fx) {
        $p = Product::get_product($fx->postT1);
        return ['breach' => $p !== null, 'note' => 'Đọc được sản phẩm team 1'];
    });

    $h->attack('IDOR (đọc)', 'MANAGER team 2 xem ảnh sản phẩm team 1', 'MGR_OUT', function ($atk, $fx) {
        $_POST = ['id' => $fx->postT1];
        $res = Product::get_product_images();
        return ['breach' => ($res['status'] ?? '') === 'success', 'note' => 'Xem được ảnh sản phẩm team 1'];
    });

    // ===== IDOR ghi/xóa =====
    $h->attack('IDOR (ghi)', 'USER team 3 đổi status sản phẩm team 1', 'USR_OUT', function ($atk, $fx) {
        $id = $fx->new_post(AB_UID_T1);
        $_POST = ['ids' => [$id], 'status' => 'inactive'];
        Products::update_products_status();
        $st = $fx->conn->query("SELECT status FROM posts WHERE ID = $id")->fetch_row()[0];
        return ['breach' => $st === 'inactive', 'note' => 'Status sản phẩm team 1 bị đổi thành ' . $st];
    });

    $h->attack('IDOR (xóa)', 'MANAGER team 2 xóa sản phẩm team 1', 'MGR_OUT', function ($atk, $fx) {
        $id = $fx->new_post(AB_UID_T1);
        $_POST = ['ids' => [$id]];
        Products::delete_products();
        $gone = hack_count($fx->conn, "SELECT COUNT(*) FROM posts WHERE ID = $id") === 0;
        return ['breach' => $gone, 'note' => 'Sản phẩm team 1 bị xóa'];
    });

    // ===== Giả mạo chủ sở hữu =====
    $h->attack('Giả mạo tham số', 'USER gán sản phẩm mình cho người team khác', 'USR_OUT', function ($atk, $fx) {
        $id = $fx->new_post($atk->uid);
        $_POST = hack_product_post($fx, ['id' => $id, 'author_id' => AB_UID_T1, 'store_id' => $fx->storeShared]);
        Product::save_product();
        $owner = (int)$fx->conn->query("SELECT author_id FROM posts WHERE ID = $id")->fetch_row()[0];
        return ['breach' => $owner === AB_UID_T1, 'note' => 'author_id sau đòn đánh = ' . $owner];
    });

    $h->attack('Giả mạo tham số', 'USER gửi team để nhảy sang team khác (bảng)', 'USR_OUT', function ($atk, $fx) {
        // Non-admin gửi POST[team] -> get_current_team_scope_id phải bỏ qua
        $_POST = ab_dt(['skip_authors' => 1, 'team' => 1]);
        $res = Products::get_products();
        $mine = hack_count($fx->conn, 'SELECT COUNT(*) FROM posts WHERE author_id = ' . $atk->uid);
        return ['breach' => $res['recordsTotal'] > $mine,
                'note' => 'Thấy ' . $res['recordsTotal'] . ' sản phẩm (chỉ nên ' . $mine . ')'];
    });

    // ===== Leo thang quyền =====
    $h->attack('Leo thang quyền', 'USER chỉ-view xóa sản phẩm của chính mình', 'USR_VIEW', function ($atk, $fx) {
        $id = $fx->new_post($atk->uid);
        $_POST = ['ids' => [$id]];
        $res = Products::delete_products();
        return ['breach' => ($res['status'] ?? '') === 'success', 'note' => 'Xóa được dù chỉ có quyền view'];
    });

    // ===== SQL injection =====
    $h->attack('SQL injection', 'Payload trong ô tìm kiếm', 'USR_OUT', function ($atk, $fx) {
        $_POST = ab_dt(['skip_authors' => 1, 'search' => ['value' => "zzab_x' OR '1'='1"]]);
        $res = Products::get_products();
        $mine = hack_count($fx->conn, 'SELECT COUNT(*) FROM posts WHERE author_id = ' . $atk->uid);
        return ['breach' => $res['recordsTotal'] > $mine && $res['recordsFiltered'] > 0 && $res['recordsFiltered'] > $mine,
                'note' => 'filtered = ' . $res['recordsFiltered']];
    });

    $h->attack('SQL injection', 'Payload trong danh sách ID khi xóa', 'USR_OUT', function ($atk, $fx) {
        $before = hack_count($fx->conn, 'SELECT COUNT(*) FROM posts');
        // Kèm token hợp lệ để đòn đánh chạm tới SQL thật (không bị CSRF chặn trước)
        $_POST = ['ids' => ['1 OR 1=1', '1); DELETE FROM posts;--'], 'csrf_token' => 'VICTIM_SECRET'];
        Products::delete_products();
        $after = hack_count($fx->conn, 'SELECT COUNT(*) FROM posts');
        return ['breach' => $after < $before - 1, 'note' => "Số sản phẩm: $before -> $after"];
    });

    // ===== CSRF =====
    $h->attack('CSRF', 'Xóa hàng loạt KHÔNG kèm csrf_token', 'USR_OUT', function ($atk, $fx) {
        $id = $fx->new_post($atk->uid);   // của chính mình -> loại yếu tố IDOR
        $_POST = ['ids' => [$id]];        // KHÔNG token
        Products::delete_products();
        $gone = hack_count($fx->conn, "SELECT COUNT(*) FROM posts WHERE ID = $id") === 0;
        return ['breach' => $gone, 'note' => 'Xóa được mà không cần csrf_token'];
    });

    $h->attack('CSRF', 'Đổi status hàng loạt KHÔNG kèm csrf_token', 'USR_OUT', function ($atk, $fx) {
        $id = $fx->new_post($atk->uid);
        $_POST = ['ids' => [$id], 'status' => 'inactive'];
        Products::update_products_status();
        $st = $fx->conn->query("SELECT status FROM posts WHERE ID = $id")->fetch_row()[0];
        return ['breach' => $st === 'inactive', 'note' => 'Đổi được status mà không cần csrf_token'];
    });
};
