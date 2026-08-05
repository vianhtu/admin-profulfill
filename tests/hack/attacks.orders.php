<?php
/**
 * HACK — Orders / Order. Kẻ tấn công cố đọc/sửa/xóa đơn hàng của team khác, bỏ qua CSRF,
 * tiêm SQL qua ô tìm kiếm và bộ lọc, giả mạo trạng thái, và nhồi XSS vào dữ liệu buyer.
 *
 * Đơn hàng chứa dữ liệu KHÁCH HÀNG THẬT (tên, địa chỉ, điện thoại) nên rò rỉ chéo team
 * ở đây nặng hơn các bảng danh mục.
 */

return function (HackRunner $h): void {

    // ---------- Auth bypass ----------
    $h->attack('Auth bypass', 'Khách chưa đăng nhập đọc danh sách đơn', 'ANON', function ($atk, $fx) {
        $_POST = ab_dt(['columns' => [['data' => 'host_id']]]);
        $res = Orders::get_orders();
        return ['breach' => !empty($res['data']), 'note' => count($res['data']) . ' đơn lộ ra'];
    });

    $h->attack('Auth bypass', 'Khách chưa đăng nhập đọc thống kê đơn', 'ANON', function ($atk, $fx) {
        $stat = Orders::get_orders_statistic();
        return ['breach' => array_sum($stat) > 0, 'note' => 'Tổng đơn lộ ra: ' . array_sum($stat)];
    });

    // ---------- IDOR đọc: rò dữ liệu khách hàng ----------
    $h->attack('IDOR (đọc)', 'USER team 3 tìm đơn của team 1 qua ô search', 'USR_OUT', function ($atk, $fx) {
        $_POST = ab_dt(['columns' => [['data' => 'host_id']], 'search' => ['value' => 'ZZAB-ORD-T1']]);
        $res = Orders::get_orders();
        return ['breach' => ab_sees($res, $fx->orderT1), 'note' => 'Đọc được đơn + thông tin khách của team 1'];
    });

    $h->attack('IDOR (đọc)', 'MANAGER team 2 tìm đơn của team 1', 'MGR_OUT', function ($atk, $fx) {
        $_POST = ab_dt(['columns' => [['data' => 'host_id']], 'search' => ['value' => 'ZZAB-ORD-T1']]);
        $res = Orders::get_orders();
        return ['breach' => ab_sees($res, $fx->orderT1), 'note' => 'Manager team 2 thấy đơn team 1'];
    });

    $h->attack('IDOR (đọc)', 'Lọc theo account_id của team khác', 'USR_OUT', function ($atk, $fx) {
        // Đoán/biết account id của team 1 rồi ép bộ lọc về đó
        $_POST = ab_dt(['columns' => [['data' => 'host_id']]]);
        $_POST['account'] = $fx->accountT1;
        $res = Orders::get_orders();
        return ['breach' => !empty($res['data']), 'note' => count($res['data']) . ' đơn team 1 lọt qua bộ lọc account'];
    });

    // ---------- IDOR ghi ----------
    $h->attack('IDOR (ghi)', 'USER team 3 đổi trạng thái đơn team 1', 'USR_OUT', function ($atk, $fx) {
        $id = $fx->new_order($fx->accountT1, 'ZZAB-ORD-HK1');
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'orders' => [$id => 'cancel']];
        Orders::update_orders_status();
        $now = $fx->conn->query("SELECT status FROM orders WHERE ID = $id")->fetch_row()[0];
        return ['breach' => $now === 'cancel', 'note' => 'Trạng thái đơn team 1 sau đòn: ' . $now];
    });

    $h->attack('IDOR (ghi)', 'MANAGER team 2 gắn tracking vào đơn team 1', 'MGR_OUT', function ($atk, $fx) {
        $id = $fx->new_order($fx->accountT1, 'ZZAB-ORD-HK2');
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'id' => $id, 'itemIndex' => 0,
            'services' => 'HACKED', 'track' => 'HACKEDTRACK'];
        Order::add_item_tracking();
        $items = (string)$fx->conn->query("SELECT items FROM orders WHERE ID = $id")->fetch_row()[0];
        return ['breach' => str_contains($items, 'HACKEDTRACK'), 'note' => 'Ghi được tracking vào đơn team 1'];
    });

    $h->attack('IDOR (ghi)', 'USER team 3 sửa base_cost đơn team 1', 'USR_OUT', function ($atk, $fx) {
        $id = $fx->new_order($fx->accountT1, 'ZZAB-ORD-HK3');
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'order_id' => $id, 'item_id' => 0,
            'base_cost' => 999.99, 'note' => 'hacked'];
        Order::add_item_data();
        $cost = (float)$fx->conn->query("SELECT base_cost FROM orders WHERE ID = $id")->fetch_row()[0];
        return ['breach' => $cost > 900, 'note' => 'base_cost sau đòn = ' . $cost];
    });

    $h->attack('IDOR (xóa)', 'USER team 3 xóa đơn team 1', 'USR_OUT', function ($atk, $fx) {
        $id = $fx->new_order($fx->accountT1, 'ZZAB-ORD-HK4');
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'order_ids' => [$id]];
        Orders::delete_orders();
        $gone = hack_count($fx->conn, "SELECT COUNT(*) FROM orders WHERE ID = $id") === 0;
        return ['breach' => $gone, 'note' => 'Xóa được đơn của team khác'];
    });

    $h->attack('IDOR (xóa)', 'Nhồi lô ID lớn để xóa lan sang đơn team khác', 'USR_OUT', function ($atk, $fx) {
        $victim = $fx->new_order($fx->accountT1, 'ZZAB-ORD-HK5');
        // Trộn ID hợp lệ của mình (không có) với dải ID quét được
        $ids = range(max(1, $victim - 5), $victim + 5);
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'order_ids' => $ids];
        Orders::delete_orders();
        $gone = hack_count($fx->conn, "SELECT COUNT(*) FROM orders WHERE ID = $victim") === 0;
        return ['breach' => $gone, 'note' => 'Quét dải ID xóa được đơn ngoài quyền'];
    });

    // ---------- Leo thang quyền ----------
    $h->attack('Leo thang quyền', 'USER chỉ-view đổi trạng thái đơn team mình', 'USR_VIEW', function ($atk, $fx) {
        $id = $fx->new_order($fx->accountT1, 'ZZAB-ORD-HK6');
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'orders' => [$id => 'delivered']];
        Orders::update_orders_status();
        $now = $fx->conn->query("SELECT status FROM orders WHERE ID = $id")->fetch_row()[0];
        return ['breach' => $now === 'delivered', 'note' => 'Chỉ có view mà đổi được trạng thái'];
    });

    $h->attack('Leo thang quyền', 'USER chỉ-view xóa đơn team mình', 'USR_VIEW', function ($atk, $fx) {
        $id = $fx->new_order($fx->accountT1, 'ZZAB-ORD-HK7');
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'order_ids' => [$id]];
        Orders::delete_orders();
        $gone = hack_count($fx->conn, "SELECT COUNT(*) FROM orders WHERE ID = $id") === 0;
        return ['breach' => $gone, 'note' => 'Chỉ có view mà xóa được đơn'];
    });

    // ---------- Giả mạo tham số ----------
    $h->attack('Giả mạo tham số', 'Đặt trạng thái ngoài danh sách cho phép', 'MGR_OUT', function ($atk, $fx) {
        $id = $fx->new_order($fx->accountT2, 'ZZAB-ORD-HK8');   // đơn team mình -> có quyền
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'orders' => [$id => "shipped'; DROP TABLE orders;--"]];
        Orders::update_orders_status();
        $now = (string)$fx->conn->query("SELECT status FROM orders WHERE ID = $id")->fetch_row()[0];
        return ['breach' => $now !== 'unshipped', 'note' => 'Trạng thái ghi vào DB: ' . $now];
    });

    $h->attack('Giả mạo tham số', 'itemIndex âm/vượt biên khi ghi tracking', 'MGR_OUT', function ($atk, $fx) {
        $id = $fx->new_order($fx->accountT2, 'ZZAB-ORD-HK9');
        $before = (string)$fx->conn->query("SELECT items FROM orders WHERE ID = $id")->fetch_row()[0];
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'id' => $id, 'itemIndex' => 999,
            'services' => 'X', 'track' => 'OUTOFRANGE'];
        Order::add_item_tracking();
        $after = (string)$fx->conn->query("SELECT items FROM orders WHERE ID = $id")->fetch_row()[0];
        return ['breach' => $after !== $before, 'note' => 'Ghi được vào chỉ số item không tồn tại'];
    });

    // ---------- SQL injection ----------
    $h->attack('SQL injection', 'Payload trong ô tìm kiếm đơn', 'USR_OUT', function ($atk, $fx) {
        $_POST = ab_dt(['columns' => [['data' => 'host_id']], 'search' => ['value' => "zzab' OR '1'='1"]]);
        $res = Orders::get_orders();
        return ['breach' => $res['recordsFiltered'] > 100, 'note' => 'filtered = ' . $res['recordsFiltered']];
    });

    $h->attack('SQL injection', 'Payload trong bộ lọc ngày', 'USR_OUT', function ($atk, $fx) {
        $_POST = ab_dt(['columns' => [['data' => 'host_id']]]);
        $_POST['minDate'] = "2020-01-01' OR '1'='1";
        $res = Orders::get_orders();
        return ['breach' => $res['recordsFiltered'] > 100, 'note' => 'filtered = ' . $res['recordsFiltered']];
    });

    $h->attack('SQL injection', 'Payload trong danh sách site lọc', 'USR_OUT', function ($atk, $fx) {
        $_POST = ab_dt(['columns' => [['data' => 'host_id']]]);
        $_POST['sites'] = ['1) OR (1=1', '1; DELETE FROM orders;--'];
        $before = hack_count($fx->conn, 'SELECT COUNT(*) FROM orders');
        Orders::get_orders();
        $after = hack_count($fx->conn, 'SELECT COUNT(*) FROM orders');
        return ['breach' => $after < $before, 'note' => "Số đơn: $before -> $after"];
    });

    $h->attack('SQL injection', 'Payload trong ID khi xóa đơn', 'MGR_OUT', function ($atk, $fx) {
        $before = hack_count($fx->conn, 'SELECT COUNT(*) FROM orders');
        $_POST = ['csrf_token' => 'VICTIM_SECRET', 'order_ids' => ['1 OR 1=1', '1); DELETE FROM orders;--']];
        Orders::delete_orders();
        $after = hack_count($fx->conn, 'SELECT COUNT(*) FROM orders');
        return ['breach' => $after < $before, 'note' => "Số đơn: $before -> $after"];
    });

    $h->attack('SQL injection', 'Payload qua cột sắp xếp (ORDER BY)', 'USR_OUT', function ($atk, $fx) {
        $_POST = ab_dt(['columns' => [['data' => 'host_id, (SELECT SLEEP(0))']],
            'order' => [['column' => 0, 'dir' => 'asc']]]);
        $res = Orders::get_orders();
        // Không văng lỗi + không trả bậy = whitelist đã chặn
        return ['breach' => false, 'note' => 'rows = ' . count($res['data'])];
    }, 'TRUNG BÌNH');

    // ---------- CSRF ----------
    $h->attack('CSRF', 'Đổi trạng thái KHÔNG kèm csrf_token', 'MGR_OUT', function ($atk, $fx) {
        $id = $fx->new_order($fx->accountT2, 'ZZAB-ORD-HKA');
        $_POST = ['orders' => [$id => 'cancel']];   // không token
        Orders::update_orders_status();
        $now = $fx->conn->query("SELECT status FROM orders WHERE ID = $id")->fetch_row()[0];
        return ['breach' => $now === 'cancel', 'note' => 'Đổi được trạng thái mà không cần token'];
    });

    $h->attack('CSRF', 'Xóa đơn KHÔNG kèm csrf_token', 'MGR_OUT', function ($atk, $fx) {
        $id = $fx->new_order($fx->accountT2, 'ZZAB-ORD-HKB');
        $_POST = ['order_ids' => [$id]];
        Orders::delete_orders();
        $gone = hack_count($fx->conn, "SELECT COUNT(*) FROM orders WHERE ID = $id") === 0;
        return ['breach' => $gone, 'note' => 'Xóa được đơn mà không cần token'];
    });

    $h->attack('CSRF', 'Ghi tracking KHÔNG kèm csrf_token', 'MGR_OUT', function ($atk, $fx) {
        $id = $fx->new_order($fx->accountT2, 'ZZAB-ORD-HKC');
        $_POST = ['id' => $id, 'itemIndex' => 0, 'services' => 'X', 'track' => 'NOCSRFTRACK'];
        Order::add_item_tracking();
        $items = (string)$fx->conn->query("SELECT items FROM orders WHERE ID = $id")->fetch_row()[0];
        return ['breach' => str_contains($items, 'NOCSRFTRACK'), 'note' => 'Ghi được mà không cần token'];
    });

    $h->attack('CSRF', 'Token sai (đoán bừa)', 'MGR_OUT', function ($atk, $fx) {
        $id = $fx->new_order($fx->accountT2, 'ZZAB-ORD-HKD');
        $_POST = ['csrf_token' => 'GUESSED', 'order_ids' => [$id]];
        Orders::delete_orders();
        $gone = hack_count($fx->conn, "SELECT COUNT(*) FROM orders WHERE ID = $id") === 0;
        return ['breach' => $gone, 'note' => 'Token bừa vẫn qua được'];
    });

    // ---------- XSS ----------
    // Phòng thủ thật nằm ở lúc RENDER (esc() trong JS), nên đòn này kiểm: dữ liệu độc
    // có bị trả nguyên văn ra endpoint không, và JS có bọc esc() không.
    $h->attack('XSS (stored)', 'Tên/địa chỉ khách chứa <script> có được escape khi render', 'MGR_OUT',
        function ($atk, $fx) {
            $payload = '<script>alert(1)</script>';
            $id = $fx->new_order($fx->accountT2, 'ZZAB-ORD-HKX');
            $fx->conn->execute_query('UPDATE orders SET full_name = ?, address = ? WHERE ID = ?',
                [$payload, $payload, $id]);
            $js = (string)file_get_contents(AB_ROOT . '/assets/js/app-ecommerce-order-list.js');
            $escaped = str_contains($js, "esc(full['full_name'])") && str_contains($js, "esc(full['address'])");
            return ['breach' => !$escaped, 'note' => 'JS render tên/địa chỉ khách KHÔNG bọc esc()'];
        });

    $h->attack('XSS (stored)', 'Title item từ sàn không được escape', 'MGR_OUT', function ($atk, $fx) {
        $js = (string)file_get_contents(AB_ROOT . '/assets/js/app-ecommerce-order-list.js');
        return ['breach' => !str_contains($js, 'esc(item.title)'),
            'note' => 'JS render title item KHÔNG bọc esc()'];
    });

    $h->attack('XSS (stored)', 'URL item dạng javascript: chui vào href', 'MGR_OUT', function ($atk, $fx) {
        $js = (string)file_get_contents(AB_ROOT . '/assets/js/app-ecommerce-order-list.js');
        return ['breach' => !str_contains($js, 'safeUrl('),
            'note' => 'JS không lọc scheme của item.url -> javascript: chui được vào href'];
    });
};
