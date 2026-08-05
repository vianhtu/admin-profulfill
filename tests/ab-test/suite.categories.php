<?php
/**
 * AB TEST — Categories / Category.
 * Luật: dùng chung toàn hệ thống. Thêm theo role add; sửa = admin hoặc chính người tạo
 * (kèm role edit); xóa chỉ admin và chặn khi còn sản phẩm dùng.
 */

return function (AbRunner $r): void {

    $r->add('Category — đọc', 'Thấy category dùng chung', function ($a, $fx) {
        $_POST = ab_dt(['search' => ['value' => 'ZZAB']]);
        return ab_sees(Categories::get_categories(), $fx->catShared);
    })->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'USR_T1', 'USR_T1_V', 'MGR_T2', 'USR_T2', 'USR_T3');

    $r->add('Category — đọc', 'Mở form sửa (đọc 1 category)',
        fn($a, $fx) => Category::get_category($fx->catShared) !== null
    )->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'USR_T1', 'USR_T1_V', 'MGR_T2', 'USR_T2', 'USR_T3');

    $r->add('Category — ghi', 'Thêm category mới', function ($a, $fx) {
        $_POST = ['csrf_token' => 'ABTEST', 'id' => 0,
            'name' => 'ZZAB new ' . bin2hex(random_bytes(4)), 'user_prompt' => ''];
        return Category::save_category();
    })->allow('ADMIN', 'MGR_T1', 'USR_T1', 'MGR_T2', 'USR_T2', 'USR_T3');

    $r->add('Category — ghi', 'Thêm trùng tên category đã có', function ($a, $fx) {
        $_POST = ['csrf_token' => 'ABTEST', 'id' => 0, 'name' => 'ZZAB Category', 'user_prompt' => ''];
        return Category::save_category();
    })->allow(/* không ai — tên phải duy nhất toàn hệ thống */);

    $r->add('Category — ghi', 'Sửa category do NGƯỜI KHÁC tạo', function ($a, $fx) {
        $id = $fx->new_category(AB_UID_T3);
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id,
            'name' => 'ZZAB edited ' . bin2hex(random_bytes(4)), 'user_prompt' => ''];
        return Category::save_category();
    })->allow('ADMIN', 'USR_T3'); // USR_T3 chính là người tạo

    $r->add('Category — ghi', 'Sửa category do CHÍNH MÌNH tạo', function ($a, $fx) {
        $id = $fx->new_category($a->uid);
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $id,
            'name' => 'ZZAB mine ' . bin2hex(random_bytes(4)), 'user_prompt' => ''];
        return Category::save_category();
    })->allow('ADMIN', 'MGR_T1', 'USR_T1', 'MGR_T2', 'USR_T2', 'USR_T3');

    $r->add('Category — ghi', 'Sửa category cũ (created_by = 0)', function ($a, $fx) {
        $_POST = ['csrf_token' => 'ABTEST', 'id' => $fx->catShared,
            'name' => 'ZZAB Category', 'user_prompt' => 'x'];
        return Category::save_category();
    })->allow('ADMIN');

    $r->add('Category — ghi', 'Sửa bằng CSRF token sai', function ($a, $fx) {
        $_POST = ['csrf_token' => 'SAI', 'id' => $fx->catShared, 'name' => 'ZZAB hack'];
        return Category::save_category();
    })->allow(/* không ai */);

    $r->add('Category — xóa', 'Xóa category rỗng (không có sản phẩm)', function ($a, $fx) {
        $_POST = ['ids' => [$fx->new_category($a->uid)], 'csrf_token' => 'ABTEST'];
        return Categories::delete_categories();
    })->allow('ADMIN');

    $r->add('Category — xóa', 'Xóa category ĐANG CÓ sản phẩm', function ($a, $fx) {
        $_POST = ['ids' => [$fx->catShared], 'csrf_token' => 'ABTEST'];
        return Categories::delete_categories();
    })->allow(/* không ai — kể cả admin */);

    // ---------- Lớp giao diện ----------
    $r->add('Category — giao diện', 'Form THÊM hiện ra',
        fn($a, $fx) => ab_render('app-ecommerce-category-add.php') !== ''
    )->allow('ADMIN', 'MGR_T1', 'USR_T1', 'MGR_T2', 'USR_T2', 'USR_T3');

    $r->add('Category — giao diện', 'Form SỬA category do NGƯỜI KHÁC tạo', function ($a, $fx) {
        return ab_render('app-ecommerce-category-add.php', ['id' => $fx->catMine]) !== '';
    })->allow('ADMIN', 'USR_T3'); // catMine do USR_T3 tạo

    $r->add('Category — giao diện', 'Form SỬA category cũ (created_by = 0)',
        fn($a, $fx) => ab_render('app-ecommerce-category-add.php', ['id' => $fx->catShared]) !== ''
    )->allow('ADMIN');

    $r->add('Category — giao diện', 'Form KHÔNG còn ô chọn Team (đã dùng chung)',
        fn($a, $fx) => ab_lacks(ab_render('app-ecommerce-category-add.php'), 'category_team')
    )->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'USR_T1', 'USR_T1_V', 'MGR_T2', 'USR_T2', 'USR_T3', 'NOROLE');

    $r->add('Category — giao diện', 'Non-admin thấy thông báo "dùng chung"',
        fn($a, $fx) => ab_has(ab_render('app-ecommerce-category-list.php'), 'shared by every team')
    )->allow('MGR_T1', 'MGR_T1_V', 'USR_T1', 'USR_T1_V', 'MGR_T2', 'USR_T2', 'USR_T3');

    $r->add('Category — quyền UI', 'Cờ quyền trả về đúng (add/delete)', function ($a, $fx) {
        $res = Categories::get_categories_filters();
        if (($res['status'] ?? '') === 'error') {
            return false;
        }
        $p = $res['perms'];
        $canAdd    = $a->level === 'admin' || in_array('add', $a->roles, true);
        $canDelete = $a->level === 'admin';
        return $p['add'] === $canAdd && $p['delete'] === $canDelete;
    })->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'USR_T1', 'USR_T1_V', 'MGR_T2', 'USR_T2', 'USR_T3');
};
