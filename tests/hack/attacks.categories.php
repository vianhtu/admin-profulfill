<?php
/**
 * HACK — Categories / Category (dùng chung + luật người-tạo-mới-sửa).
 */

return function (HackRunner $h): void {

    $h->attack('Auth bypass', 'Khách chưa đăng nhập đọc category', 'ANON', function ($atk, $fx) {
        $_POST = ab_dt();
        $res = Categories::get_categories();
        return ['breach' => !empty($res['data']), 'note' => count($res['data']) . ' dòng'];
    });

    $h->attack('IDOR (ghi)', 'Sửa category do NGƯỜI KHÁC tạo', 'MGR_OUT', function ($atk, $fx) {
        $victim = $fx->new_category(AB_UID_T1);
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'id' => $victim, 'name' => 'ZZAB hacked ' . bin2hex(random_bytes(3)),
            'user_prompt' => 'pwned'];
        Category::save_category();
        $p = $fx->conn->query("SELECT user_prompt FROM `type` WHERE ID = $victim")->fetch_row()[0];
        return ['breach' => $p === 'pwned', 'note' => 'Sửa được category của người khác'];
    });

    $h->attack('Leo thang quyền', 'USER chỉ-view thêm category', 'USR_VIEW', function ($atk, $fx) {
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'id' => 0, 'name' => 'ZZAB esc ' . bin2hex(random_bytes(3)),
            'user_prompt' => ''];
        $res = Category::save_category();
        return ['breach' => ($res['status'] ?? '') !== 'error', 'note' => 'Thêm được dù chỉ view'];
    });

    $h->attack('Giả mạo tham số', 'Gửi created_by để chiếm category cũ', 'USR_OUT', function ($atk, $fx) {
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'id' => $fx->catShared, 'created_by' => $atk->uid,
            'name' => 'ZZAB Category', 'user_prompt' => 'pwned'];
        Category::save_category();
        $p = $fx->conn->query("SELECT user_prompt FROM `type` WHERE ID = {$fx->catShared}")->fetch_row()[0];
        return ['breach' => $p === 'pwned', 'note' => 'Chiếm được category cũ nhờ giả mạo created_by'];
    });

    $h->attack('SQL injection', 'Payload trong ô tìm kiếm', 'USR_OUT', function ($atk, $fx) {
        $_POST = ab_dt(['search' => ['value' => "zzab_x' OR '1'='1"]]);
        $res = Categories::get_categories();
        return ['breach' => $res['recordsFiltered'] > 1, 'note' => 'filtered = ' . $res['recordsFiltered']];
    });

    $h->attack('SQL injection', 'Payload trong ID khi xóa', 'MGR_OUT', function ($atk, $fx) {
        $before = hack_count($fx->conn, 'SELECT COUNT(*) FROM `type`');
        $_POST = ['ids' => ['1 OR 1=1', '1); DELETE FROM `type`;--'], 'csrf_token' => 'VICTIM_SECRET'];
        Categories::delete_categories();
        $after = hack_count($fx->conn, 'SELECT COUNT(*) FROM `type`');
        return ['breach' => $after < $before, 'note' => "Số category: $before -> $after"];
    });

    $h->attack('CSRF', 'Xóa category KHÔNG kèm csrf_token', 'MGR_OUT', function ($atk, $fx) {
        $victim = $fx->new_category($atk->uid);
        $_POST = ['ids' => [$victim]];   // không token
        Categories::delete_categories();
        $gone = hack_count($fx->conn, "SELECT COUNT(*) FROM `type` WHERE ID = $victim") === 0;
        return ['breach' => $gone, 'note' => 'Xóa được mà không cần csrf_token'];
    });

    $h->attack('Phá toàn vẹn', 'Xóa category đang có sản phẩm (admin cũng phải bị chặn)', 'MGR_OUT',
        function ($atk, $fx) {
            // Gán sản phẩm vào category rồi thử xóa dưới quyền cao nhất mà attacker có
            $cat = $fx->new_category($atk->uid);
            $fx->conn->query("UPDATE posts SET type_id = $cat WHERE ID = {$fx->postT2}");
            $_POST = ['ids' => [$cat], 'csrf_token' => 'VICTIM_SECRET'];
            $res = Categories::delete_categories();
            $fx->conn->query("UPDATE posts SET type_id = {$fx->catShared} WHERE ID = {$fx->postT2}");
            $gone = hack_count($fx->conn, "SELECT COUNT(*) FROM `type` WHERE ID = $cat") === 0;
            return ['breach' => $gone, 'note' => 'Xóa được category đang có sản phẩm -> mồ côi dữ liệu'];
        });
};
