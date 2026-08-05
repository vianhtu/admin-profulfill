<?php
/**
 * AB TEST — Sites / Site.
 * Luật: dùng chung toàn hệ thống, không có cột team. Thêm theo role add; sửa = admin
 * hoặc chính người đã thêm (site.created_by); xóa chỉ admin, chặn khi còn dữ liệu dùng.
 */

return function (AbRunner $r): void {

    $r->add('Site — đọc', 'Thấy site trong bảng', function ($a, $fx) {
        $_POST = ab_dt(['search' => ['value' => 'zzab-site']]);
        return ab_sees(Sites::get_sites(), $fx->site);
    })->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'USR_T1', 'USR_T1_V', 'MGR_T2', 'USR_T2', 'USR_T3');

    $r->add('Site — đọc', 'Mở form sửa (đọc 1 site)',
        fn($a, $fx) => Site::get_site($fx->site) !== null
    )->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'USR_T1', 'USR_T1_V', 'MGR_T2', 'USR_T2', 'USR_T3');

    $r->add('Site — ghi', 'Thêm site mới', function ($a, $fx) {
        $slug = 'zzab-new-' . bin2hex(random_bytes(4));
        $_POST = ['csrf_token' => 'ABTEST', 'id' => 0, 'name' => $slug . '.com', 'slug' => $slug,
            'logo' => '', 'system_prompt' => '', 'developer_prompt' => '', 'fields' => '[]'];
        return Site::save_site();
    })->allow('ADMIN', 'MGR_T1', 'USR_T1', 'MGR_T2', 'USR_T2', 'USR_T3');

    $r->add('Site — ghi', 'Thêm trùng slug site đã có', function ($a, $fx) {
        $_POST = ['csrf_token' => 'ABTEST', 'id' => 0, 'name' => 'x.com', 'slug' => 'zzab-site-com',
            'logo' => '', 'system_prompt' => '', 'developer_prompt' => '', 'fields' => '[]'];
        return Site::save_site();
    })->allow(/* không ai */);

    $r->add('Site — ghi', 'Sửa site do NGƯỜI KHÁC tạo', function ($a, $fx) {
        $id = $fx->new_site(AB_UID_T3);
        $row = $fx->conn->query("SELECT name, slug FROM site WHERE ID = $id")->fetch_assoc();
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id, 'name' => $row['name'], 'slug' => $row['slug'],
            'logo' => '', 'system_prompt' => 'x', 'developer_prompt' => '', 'fields' => '[]'];
        return Site::save_site();
    })->allow('ADMIN', 'USR_T3'); // USR_T3 là người tạo

    $r->add('Site — ghi', 'Sửa site do CHÍNH MÌNH tạo', function ($a, $fx) {
        $id = $fx->new_site($a->uid);
        $row = $fx->conn->query("SELECT name, slug FROM site WHERE ID = $id")->fetch_assoc();
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id, 'name' => $row['name'], 'slug' => $row['slug'],
            'logo' => '', 'system_prompt' => 'x', 'developer_prompt' => '', 'fields' => '[]'];
        return Site::save_site();
    })->allow('ADMIN', 'MGR_T1', 'USR_T1', 'MGR_T2', 'USR_T2', 'USR_T3');

    $r->add('Site — ghi', 'Sửa site cũ (created_by = 0)', function ($a, $fx) {
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $fx->site, 'name' => 'zzab-site.com',
            'slug' => 'zzab-site-com', 'logo' => '', 'system_prompt' => 'x',
            'developer_prompt' => '', 'fields' => '[]'];
        return Site::save_site();
    })->allow('ADMIN');

    $r->add('Site — ghi', 'Upload logo', function ($a, $fx) {
        $_POST = ['csrf_token' => 'ABTEST'];
        $_FILES = [];
        $res = Site::upload_logo();
        // Không gửi file nên chắc chắn lỗi; chỉ phân biệt "không có quyền" với lỗi khác
        return str_contains((string)($res['message'] ?? ''), 'permission') ? ['status' => 'error'] : ['status' => 'ok'];
    })->allow('ADMIN', 'MGR_T1', 'USR_T1', 'MGR_T2', 'USR_T2', 'USR_T3');

    $r->add('Site — xóa', 'Xóa site rỗng', function ($a, $fx) {
        $_POST = ['ids' => [$fx->new_site($a->uid)], 'csrf_token' => 'ABTEST'];
        return Sites::delete_sites();
    })->allow('ADMIN');

    $r->add('Site — xóa', 'Xóa site ĐANG CÓ store/sản phẩm', function ($a, $fx) {
        $siteId = (int)$fx->conn->query('SELECT ID FROM site ORDER BY ID LIMIT 1')->fetch_row()[0];
        $_POST = ['ids' => [$siteId], 'csrf_token' => 'ABTEST'];
        return Sites::delete_sites();
    })->allow(/* không ai — kể cả admin */);

    // ---------- Lớp giao diện ----------
    $r->add('Site — giao diện', 'Form THÊM hiện ra',
        fn($a, $fx) => ab_render('app-ecommerce-site-add.php') !== ''
    )->allow('ADMIN', 'MGR_T1', 'USR_T1', 'MGR_T2', 'USR_T2', 'USR_T3');

    $r->add('Site — giao diện', 'Form SỬA site cũ (created_by = 0)',
        fn($a, $fx) => ab_render('app-ecommerce-site-add.php', ['id' => $fx->site]) !== ''
    )->allow('ADMIN');

    $r->add('Site — giao diện', 'Form THÊM có khung upload logo',
        fn($a, $fx) => ab_has(ab_render('app-ecommerce-site-add.php'), 'dropzone-logo')
    )->allow('ADMIN', 'MGR_T1', 'USR_T1', 'MGR_T2', 'USR_T2', 'USR_T3');

    $r->add('Site — giao diện', 'Non-admin thấy thông báo "dùng chung"',
        fn($a, $fx) => ab_has(ab_render('app-ecommerce-site-list.php'), 'shared by the whole system')
    )->allow('MGR_T1', 'MGR_T1_V', 'USR_T1', 'USR_T1_V', 'MGR_T2', 'USR_T2', 'USR_T3');

    $r->add('Site — giao diện', 'Bảng danh sách render được',
        fn($a, $fx) => ab_has(ab_render('app-ecommerce-site-list.php'), 'datatables-sites')
    )->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'USR_T1', 'USR_T1_V', 'MGR_T2', 'USR_T2', 'USR_T3');

    $r->add('Site — quyền UI', 'Cờ quyền trả về đúng (add/delete)', function ($a, $fx) {
        $res = Sites::get_sites_filters();
        if (($res['status'] ?? '') === 'error') {
            return false;
        }
        $p = $res['perms'];
        $canAdd = $a->level === 'admin' || in_array('add', $a->roles, true);
        return $p['add'] === $canAdd && $p['delete'] === ($a->level === 'admin');
    })->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'USR_T1', 'USR_T1_V', 'MGR_T2', 'USR_T2', 'USR_T3');
};
