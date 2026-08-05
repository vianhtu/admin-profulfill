<?php
/**
 * AB TEST — Products (bảng danh sách, thao tác hàng loạt) và Product (form 1 sản phẩm).
 *
 * Luật cần thỏa: admin toàn hệ thống; manager toàn team; user chỉ dòng của chính mình;
 * mọi thao tác ghi còn phải có role tương ứng.
 */

return function (AbRunner $r): void {

    // ---------- Products: đọc ----------
    $r->add('Products — đọc', 'Thấy sản phẩm của TEAM 1 trong bảng', function ($a, $fx) {
        $_POST = ab_dt(['search' => ['value' => 'ZZAB']]);
        return ab_sees(Products::get_products(), $fx->postT1);
    })->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'USR_T1', 'USR_T1_V');

    $r->add('Products — đọc', 'Thấy sản phẩm của TEAM 2 trong bảng', function ($a, $fx) {
        $_POST = ab_dt(['search' => ['value' => 'ZZAB']]);
        return ab_sees(Products::get_products(), $fx->postT2);
    })->allow('ADMIN', 'MGR_T2', 'USR_T2');

    // Oracle độc lập: đếm lại bằng SQL viết riêng trong test. Extension vẫn đang import
    // liên tục nên đếm TRƯỚC và SAU rồi chấp nhận nếu kết quả nằm trong khoảng đó.
    $r->add('Products — đọc', 'Thống kê không đếm sản phẩm ngoài phạm vi', function ($a, $fx) {
        $oracle = function () use ($a, $fx): int {
            if ($a->roles === []) {
                return 0;
            }
            if ($a->level === 'admin') {
                return (int)$fx->conn->query('SELECT COUNT(*) FROM posts')->fetch_row()[0];
            }
            if ($a->level === 'manager') {
                return (int)$fx->conn->query('SELECT COUNT(*) FROM posts p JOIN authors au ON au.ID = p.author_id
                    WHERE au.team_id = ' . $a->team)->fetch_row()[0];
            }
            return (int)$fx->conn->query('SELECT COUNT(*) FROM posts WHERE author_id = ' . $a->uid)->fetch_row()[0];
        };
        $before = $oracle();
        $actual = (int)Products::get_products_statistic()['total_items'];
        $after  = $oracle();
        return $actual >= min($before, $after) && $actual <= max($before, $after);
    })->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'USR_T1', 'USR_T1_V', 'MGR_T2', 'USR_T2', 'USR_T3', 'NOROLE');

    $r->add('Products — đọc', 'Bộ lọc không lộ user của team khác', function ($a, $fx) {
        $_POST = [];
        $opt = Products::get_products_filters();
        if (($opt['status'] ?? '') === 'error') {
            return false;
        }
        $ids = array_map('intval', array_keys($opt['authors'] ?? []));
        if ($a->level === 'admin') {
            return true;
        }
        if (empty($ids)) {
            return true;
        }
        $bad = (int)$fx->conn->query('SELECT COUNT(*) FROM authors WHERE ID IN (' . implode(',', $ids) . ')
            AND team_id <> ' . $a->team)->fetch_row()[0];
        return $bad === 0;
    })->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'USR_T1', 'USR_T1_V', 'MGR_T2', 'USR_T2', 'USR_T3');

    // ---------- Products: ghi hàng loạt ----------
    $r->add('Products — ghi hàng loạt', 'Đổi status sản phẩm TEAM 1', function ($a, $fx) {
        $_POST = ['ids' => [$fx->new_post(AB_UID_T1)], 'status' => 'inactive', 'csrf_token' => 'ABTEST'];
        return Products::update_products_status();
    })->allow('ADMIN', 'MGR_T1', 'USR_T1');

    $r->add('Products — ghi hàng loạt', 'Đổi status sản phẩm TEAM 2', function ($a, $fx) {
        $_POST = ['ids' => [$fx->new_post(AB_UID_T2)], 'status' => 'inactive', 'csrf_token' => 'ABTEST'];
        return Products::update_products_status();
    })->allow('ADMIN', 'MGR_T2', 'USR_T2');

    $r->add('Products — ghi hàng loạt', 'Đổi category sản phẩm TEAM 1', function ($a, $fx) {
        $_POST = ['ids' => [$fx->new_post(AB_UID_T1)], 'type_id' => $fx->catMine, 'csrf_token' => 'ABTEST'];
        return Products::update_products_type();
    })->allow('ADMIN', 'MGR_T1', 'USR_T1');

    $r->add('Products — ghi hàng loạt', 'Đổi sang category không tồn tại (giả mạo)', function ($a, $fx) {
        $_POST = ['ids' => [$fx->new_post($a->uid)], 'type_id' => 999999, 'csrf_token' => 'ABTEST'];
        return Products::update_products_type();
    })->allow(/* không ai được phép */);

    $r->add('Products — ghi hàng loạt', 'Xóa sản phẩm TEAM 1', function ($a, $fx) {
        $_POST = ['ids' => [$fx->new_post(AB_UID_T1)], 'csrf_token' => 'ABTEST'];
        return Products::delete_products();
    })->allow('ADMIN', 'MGR_T1', 'USR_T1');

    $r->add('Products — ghi hàng loạt', 'Xóa sản phẩm TEAM 2', function ($a, $fx) {
        $_POST = ['ids' => [$fx->new_post(AB_UID_T2)], 'csrf_token' => 'ABTEST'];
        return Products::delete_products();
    })->allow('ADMIN', 'MGR_T2', 'USR_T2');

    // ---------- Product: form 1 sản phẩm ----------
    $r->add('Product — form', 'Mở form sửa sản phẩm TEAM 1',
        fn($a, $fx) => Product::get_product($fx->postT1) !== null
    )->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'USR_T1', 'USR_T1_V');

    $r->add('Product — form', 'Xem ảnh sản phẩm TEAM 2', function ($a, $fx) {
        $_POST = ['id' => $fx->postT2];
        return Product::get_product_images();
    })->allow('ADMIN', 'MGR_T2', 'USR_T2');

    $r->add('Product — form', 'Sửa sản phẩm TEAM 1', function ($a, $fx) {
        $id = $fx->new_post(AB_UID_T1);
        $_POST = ab_product_post($fx, ['id' => $id, 'author_id' => AB_UID_T1]);
        return Product::save_product();
    })->allow('ADMIN', 'MGR_T1', 'USR_T1');

    $r->add('Product — form', 'Thêm sản phẩm mới', function ($a, $fx) {
        $_POST = ab_product_post($fx, ['id' => 0, 'author_id' => $a->uid,
            'sku' => 'ZZAB-NEW-' . bin2hex(random_bytes(4))]);
        return Product::save_product();
    })->allow('ADMIN', 'MGR_T1', 'USR_T1', 'MGR_T2', 'USR_T2', 'USR_T3');

    // Chỉ tính là "được phép" khi CHỦ SỞ HỮU ĐỔI THẬT: với user thường, author_id giả mạo
    // bị bỏ qua (ép về chính họ) nên lệnh lưu vẫn thành công — đó KHÔNG phải vượt quyền.
    $r->add('Product — form', 'Gán sản phẩm cho người TEAM KHÁC', function ($a, $fx) {
        $target = $a->team === 2 ? AB_UID_T1 : AB_UID_T2;
        $id = $fx->new_post($a->uid);
        // Chủ mới ở team khác -> phải dùng store dùng chung mới hợp lệ
        $_POST = ab_product_post($fx, ['id' => $id, 'author_id' => $target,
            'store_id' => $fx->storeShared]);
        $res = Product::save_product();
        if (($res['status'] ?? '') === 'error') {
            return false;
        }
        $now = (int)$fx->conn->query("SELECT author_id FROM posts WHERE ID = $id")->fetch_row()[0];
        return $now === $target;
    })->allow('ADMIN');

    $r->add('Product — form', 'Dùng store RIÊNG của team 2 cho sản phẩm team 1', function ($a, $fx) {
        $id = $fx->new_post(AB_UID_T1);
        $_POST = ab_product_post($fx, ['id' => $id, 'author_id' => AB_UID_T1,
            'store_id' => $fx->storeT2]);
        return Product::save_product();
    })->allow(/* không ai — kể cả admin: store riêng team 2 không hợp lệ cho chủ team 1 */);

    // ---------- Lớp giao diện: fragment có render ra control hay không ----------
    $r->add('Products — giao diện', 'Form THÊM sản phẩm hiện ra',
        fn($a, $fx) => ab_render('app-ecommerce-product-add.php') !== ''
    )->allow('ADMIN', 'MGR_T1', 'USR_T1', 'MGR_T2', 'USR_T2', 'USR_T3');

    $r->add('Products — giao diện', 'Form SỬA sản phẩm TEAM 1 hiện ra',
        fn($a, $fx) => ab_render('app-ecommerce-product-add.php', ['id' => $fx->postT1]) !== ''
    )->allow('ADMIN', 'MGR_T1', 'USR_T1');

    $r->add('Products — giao diện', 'Form SỬA sản phẩm TEAM 2 hiện ra',
        fn($a, $fx) => ab_render('app-ecommerce-product-add.php', ['id' => $fx->postT2]) !== ''
    )->allow('ADMIN', 'MGR_T2', 'USR_T2');

    $r->add('Products — giao diện', 'Có ô chọn Manager (gán chủ sở hữu)',
        fn($a, $fx) => ab_has(ab_render('app-ecommerce-product-add.php'), 'id="product_author"')
    )->allow('ADMIN', 'MGR_T1', 'MGR_T2');

    $r->add('Products — giao diện', 'HTML form không lộ username của team khác', function ($a, $fx) {
        $html = ab_render('app-ecommerce-product-add.php');
        if ($html === '' || $a->level === 'admin') {
            return true;  // không mở được form, hoặc admin vốn được thấy tất cả
        }
        $rs = $fx->conn->query('SELECT username FROM authors WHERE team_id <> ' . $a->team);
        $foreign = [];
        while ($row = $rs->fetch_row()) {
            if (trim((string)$row[0]) !== '') {
                $foreign[] = $row[0];
            }
        }
        return $foreign ? ab_lacks($html, ...$foreign) : true;
    })->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'USR_T1', 'USR_T1_V', 'MGR_T2', 'USR_T2', 'USR_T3', 'NOROLE');

    $r->add('Products — giao diện', 'Bảng danh sách render được',
        fn($a, $fx) => ab_has(ab_render('app-ecommerce-product-list.php'), 'datatables-products')
    )->allow('ADMIN', 'MGR_T1', 'MGR_T1_V', 'USR_T1', 'USR_T1_V', 'MGR_T2', 'USR_T2', 'USR_T3');

    $r->add('Product — form', 'Dùng category dùng chung (phải được)', function ($a, $fx) {
        $id = $fx->new_post($a->uid);
        $_POST = ab_product_post($fx, ['id' => $id, 'author_id' => $a->uid,
            'type_id' => $fx->catShared]);
        return Product::save_product();
    })->allow('ADMIN', 'MGR_T1', 'USR_T1', 'MGR_T2', 'USR_T2', 'USR_T3');
};

/** $_POST đầy đủ cho form sản phẩm, cho phép ghi đè từng field. */
function ab_product_post(AbFixtures $fx, array $over = []): array
{
    $siteId = (int)$fx->conn->query('SELECT ID FROM site ORDER BY ID LIMIT 1')->fetch_row()[0];
    return array_merge([
        'csrf_token' => 'ABTEST',
        'id'         => 0,
        'title'      => 'ZZAB product',
        'sku'        => 'ZZAB-' . bin2hex(random_bytes(4)),
        'description'=> '',
        'status'     => 'pending',
        'badge'      => '',
        'type_id'    => $fx->catShared,
        'site_id'    => $siteId,
        'store_id'   => $fx->storeShared,
        'images'     => json_encode(['http://x/a.jpg']),
        'tags'       => '[]',
    ], $over);
}
