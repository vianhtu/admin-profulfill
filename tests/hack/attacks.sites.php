<?php
/**
 * HACK — Sites / Site. Site dùng chung: kẻ tấn công cố SỬA/XÓA site không phải của mình,
 * chèn SQL, giả mạo quyền sở hữu, bỏ qua CSRF, và làm hỏng dữ liệu qua upload.
 */

return function (HackRunner $h): void {

    // ===== 1. Bỏ qua đăng nhập =====
    $h->attack('Auth bypass', 'Khách chưa đăng nhập đọc bảng site', 'ANON', function ($atk, $fx) {
        $_POST = ab_dt();
        $res = Sites::get_sites();
        return ['breach' => !empty($res['data']),
                'note' => 'Trả về ' . count($res['data']) . ' dòng cho người chưa đăng nhập'];
    });

    $h->attack('Auth bypass', 'Khách chưa đăng nhập thêm site', 'ANON', function ($atk, $fx) {
        $_POST = ['csrf_token' => 'VICTIM', 'id' => 0, 'name' => 'zzab-anon.com',
            'slug' => 'zzab-anon-' . bin2hex(random_bytes(3)), 'logo' => '',
            'system_prompt' => '', 'developer_prompt' => '', 'fields' => '[]'];
        $res = Site::save_site();
        return ['breach' => ($res['status'] ?? '') !== 'error',
                'note' => 'Tạo được site khi chưa đăng nhập'];
    });

    // ===== 2. IDOR — sửa/xóa bản ghi ngoài quyền =====
    $h->attack('IDOR (ghi)', 'USER team khác sửa site do NGƯỜI KHÁC tạo', 'USR_OUT', function ($atk, $fx) {
        $victim = $fx->new_site(AB_UID_T1);  // do người team 1 tạo
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'id' => $victim, 'name' => 'hacked.com',
            'slug' => 'zzab-hacked-' . bin2hex(random_bytes(3)), 'logo' => '',
            'system_prompt' => 'pwned', 'developer_prompt' => '', 'fields' => '[]'];
        $res = Site::save_site();
        $name = $fx->conn->query("SELECT name FROM site WHERE ID = $victim")->fetch_row()[0];
        return ['breach' => $name === 'hacked.com', 'note' => 'Tên site sau đòn đánh: ' . $name];
    });

    $h->attack('IDOR (xóa)', 'MANAGER team khác xóa site qua ID giả', 'MGR_OUT', function ($atk, $fx) {
        $victim = $fx->new_site(AB_UID_T1);
        $_POST = ['ids' => [$victim], 'csrf_token' => 'VICTIM_SECRET'];
        Sites::delete_sites();
        $still = hack_count($fx->conn, "SELECT COUNT(*) FROM site WHERE ID = $victim");
        return ['breach' => $still === 0, 'note' => 'Site nạn nhân đã bị xóa'];
    });

    // ===== 3. Leo thang quyền =====
    $h->attack('Leo thang quyền', 'USER chỉ-view thực hiện thêm site', 'USR_VIEW', function ($atk, $fx) {
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'id' => 0, 'name' => 'zzab-esc.com',
            'slug' => 'zzab-esc-' . bin2hex(random_bytes(3)), 'logo' => '',
            'system_prompt' => '', 'developer_prompt' => '', 'fields' => '[]'];
        $res = Site::save_site();
        return ['breach' => ($res['status'] ?? '') !== 'error', 'note' => 'Thêm được site dù chỉ có quyền view'];
    });

    $h->attack('Leo thang quyền', 'USER chỉ-view xóa site', 'USR_VIEW', function ($atk, $fx) {
        $_POST = ['ids' => [$fx->new_site(AB_UID_T1)], 'csrf_token' => 'VICTIM_SECRET'];
        $res = Sites::delete_sites();
        return ['breach' => ($res['status'] ?? '') === 'success', 'note' => 'Xóa được site dù chỉ có quyền view'];
    });

    // ===== 4. Giả mạo quyền sở hữu (created_by) =====
    $h->attack('Giả mạo tham số', 'Gửi kèm created_by để chiếm site cũ', 'USR_OUT', function ($atk, $fx) {
        // Site cũ created_by = 0; kẻ tấn công cố gán created_by = mình rồi sửa
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'id' => $fx->site, 'created_by' => $atk->uid,
            'name' => 'zzab-site.com', 'slug' => 'zzab-site-com', 'logo' => '',
            'system_prompt' => 'pwned', 'developer_prompt' => '', 'fields' => '[]'];
        $res = Site::save_site();
        $prompt = $fx->conn->query("SELECT system_prompt FROM site WHERE ID = {$fx->site}")->fetch_row()[0];
        return ['breach' => $prompt === 'pwned', 'note' => 'Sửa được site cũ nhờ giả mạo created_by'];
    });

    // ===== 5. SQL injection =====
    $h->attack('SQL injection', 'Payload trong ô tìm kiếm (get_sites)', 'USR_OUT', function ($atk, $fx) {
        // Nếu injectable, "OR 1=1" sẽ trả toàn bộ site; nếu escape đúng thì khớp 0 dòng
        $_POST = ab_dt(['search' => ['value' => "zzab_nothing' OR '1'='1"]]);
        $res = Sites::get_sites();
        return ['breach' => $res['recordsFiltered'] > 1,
                'note' => 'recordsFiltered = ' . $res['recordsFiltered'] . ' (đáng lẽ 0)'];
    });

    $h->attack('SQL injection', 'Payload trong cột sắp xếp (ORDER BY)', 'USR_OUT', function ($atk, $fx) {
        // get_datatable_params whitelist cột -> payload rơi về mặc định, không được chèn
        $_POST = ab_dt(['columns' => [['data' => 'name)); DROP TABLE site;--']]]);
        $res = Sites::get_sites();
        return ['breach' => false, 'note' => 'Chạy ' . count($res['data']) . ' dòng, không lỗi'];
    });

    $h->attack('SQL injection', 'Payload trong danh sách ID khi xóa', 'MGR_OUT', function ($atk, $fx) {
        $before = hack_count($fx->conn, 'SELECT COUNT(*) FROM site');
        $_POST = ['ids' => ['1 OR 1=1', '1); DELETE FROM site; --'], 'csrf_token' => 'VICTIM_SECRET'];
        Sites::delete_sites();
        $after = hack_count($fx->conn, 'SELECT COUNT(*) FROM site');
        return ['breach' => $after < $before, 'note' => "Số site: $before -> $after"];
    });

    // ===== 6. CSRF =====
    $h->attack('CSRF', 'Xóa site KHÔNG kèm csrf_token', 'MGR_OUT', function ($atk, $fx) {
        // Kẻ tấn công CSRF không đọc được token của nạn nhân -> không gửi token.
        // delete_sites phải từ chối; nếu nó xóa thật thì là lỗ hổng CSRF.
        $victim = $fx->new_site(AB_UID_T2);   // của chính team attacker để loại yếu tố IDOR
        $_POST = ['ids' => [$victim]];        // KHÔNG có csrf_token
        Sites::delete_sites();
        $gone = hack_count($fx->conn, "SELECT COUNT(*) FROM site WHERE ID = $victim") === 0;
        return ['breach' => $gone, 'note' => 'Xóa được site mà không cần csrf_token'];
    });

    // ===== 7. Type juggling / mass assignment =====
    $h->attack('Type juggling', 'Gửi ids là chuỗi thay vì mảng', 'MGR_OUT', function ($atk, $fx) {
        $before = hack_count($fx->conn, 'SELECT COUNT(*) FROM site');
        $_POST = ['ids' => '1', 'csrf_token' => 'VICTIM_SECRET'];
        Sites::delete_sites();
        $after = hack_count($fx->conn, 'SELECT COUNT(*) FROM site');
        return ['breach' => $after < $before, 'note' => "Số site: $before -> $after"];
    });

    // ===== 8. Upload — chèn web-shell (key ĐÚNG là 'file'; bộ 'upload' phủ đầy đủ hơn) =====
    $h->attack('Upload', 'Upload PHP giả dạng logo', 'USR_OUT', function ($atk, $fx) {
        $tmp = tempnam(sys_get_temp_dir(), 'zzab');
        file_put_contents($tmp, "<?php echo 'pwned'; ?>");
        $_POST = ['csrf_token' => 'VICTIM_SECRET'];
        $_FILES = ['file' => ['name' => 'shell.php', 'type' => 'image/png', 'tmp_name' => $tmp,
            'error' => 0, 'size' => filesize($tmp)]];
        $res = Site::upload_logo();
        @unlink($tmp);
        // Qua validation = lọt (dù ghi file fail ở CLI). Chặn đúng nếu bị từ chối vì đuôi/MIME.
        $m = (string)($res['message'] ?? '');
        $passed = ($res['status'] ?? '') !== 'error' || str_contains($m, 'Failed to save') || str_contains($m, 'Uploaded file not found');
        return ['breach' => $passed, 'note' => json_encode($res, JSON_UNESCAPED_UNICODE)];
    });
};
