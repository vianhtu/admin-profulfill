<?php
/**
 * HACK — Roles & Permissions (red-team chủ động).
 *
 * Bảng này định nghĩa quyền của MỌI người và cột `slug` chính là thứ `is_admin()` đọc,
 * nên một lỗ hổng ở đây là chiếm toàn hệ thống. Mọi đòn dưới đây đều nhắm một đích:
 * biến kẻ tấn công thành admin, hoặc cho nó quyền nó không có.
 *
 * LƯU Ý KHI ĐỌC KẾT QUẢ: tham số giả mạo bị BỎ QUA chứ không báo lỗi, nên endpoint trả
 * `success` KHÔNG có nghĩa là thủng — phải ĐỌC LẠI DB. Mọi đòn ở đây đều chấm bằng DB.
 */

return function (HackRunner $h): void {

    $slugOf = fn($fx, int $id): string => (string)($fx->conn->execute_query(
        'SELECT slug FROM roles_permissions WHERE ID = ? LIMIT 1', [$id])->fetch_row()[0] ?? '');

    $newRole = function ($fx, string $slug = 'user'): int {
        $n = 'ZZABHACKROLE' . bin2hex(random_bytes(4));
        $fx->conn->execute_query(
            'INSERT INTO roles_permissions (name, slug, roles) VALUES (?, ?, "{}")', [$n, $slug]);
        return (int)$fx->conn->insert_id;
    };

    // ---------- Leo thang qua cột slug ----------
    $h->attack('Leo thang quyền', 'Tạo role cấp admin bằng level=admin', 'MGR_FULL',
        function ($atk, $fx) use ($slugOf) {
            $n = 'ZZABHACKROLE' . bin2hex(random_bytes(4));
            $_POST = ['id' => 0, 'role_name' => $n, 'level' => 'admin',
                      'permissions' => [], 'csrf_token' => 'HACK'];
            $res = Role::save_role();
            $id = (int)($res['id'] ?? 0);
            $breach = $id > 0 && $slugOf($fx, $id) === 'admin';
            return ['breach' => $breach,
                    'note' => 'slug lưu thực tế: ' . ($id ? $slugOf($fx, $id) : '(không tạo được)')];
        });

    $h->attack('Leo thang quyền', 'Nâng cấp role sẵn có lên admin qua sửa', 'MGR_FULL',
        function ($atk, $fx) use ($slugOf, $newRole) {
            $id = $newRole($fx);
            $_POST = ['id' => $id, 'role_name' => 'ZZABHACKUP', 'level' => 'admin',
                      'permissions' => [], 'csrf_token' => 'HACK'];
            Role::save_role();
            return ['breach' => $slugOf($fx, $id) === 'admin', 'note' => 'slug sau khi sửa: ' . $slugOf($fx, $id)];
        });

    $h->attack('Leo thang quyền', 'Sửa chính role mình đang mang', 'MGR_FULL',
        function ($atk, $fx) {
            $my = (int)$fx->conn->execute_query(
                'SELECT level FROM authors WHERE ID = ? LIMIT 1', [$atk->uid])->fetch_row()[0];
            $truoc = (string)$fx->conn->execute_query(
                'SELECT roles FROM roles_permissions WHERE ID = ?', [$my])->fetch_row()[0];
            $_POST = ['id' => $my, 'role_name' => 'ZZABSELFUP', 'level' => 'manager',
                      'permissions' => ['users' => ['view' => 'on', 'delete' => 'on']],
                      'csrf_token' => 'HACK'];
            Role::save_role();
            $sau = (string)$fx->conn->execute_query(
                'SELECT roles FROM roles_permissions WHERE ID = ?', [$my])->fetch_row()[0];
            return ['breach' => $truoc !== $sau, 'note' => 'role của chính mình bị sửa'];
        });

    $h->attack('Leo thang quyền', 'Cấp quyền mà bản thân KHÔNG có', 'MGR_FULL',
        function ($atk, $fx) {
            $n = 'ZZABHACKROLE' . bin2hex(random_bytes(4));
            $_POST = ['id' => 0, 'role_name' => $n, 'level' => 'user', 'csrf_token' => 'HACK',
                      'permissions' => ['products' => ['view' => 'on', 'add' => 'on', 'edit' => 'on', 'delete' => 'on'],
                                        'orders'   => ['delete' => 'on']]];
            $res = Role::save_role();
            $id = (int)($res['id'] ?? 0);
            if (!$id) {
                return ['breach' => false, 'note' => 'không tạo được role'];
            }
            $json = json_decode((string)$fx->conn->execute_query(
                'SELECT roles FROM roles_permissions WHERE ID = ?', [$id])->fetch_row()[0], true) ?: [];
            $mine = (array)($_SESSION['auth']['roles'] ?? []);
            $thua = [];
            foreach ($json as $menu => $acts) {
                foreach (array_keys($acts) as $act) {
                    if (empty($mine[$menu][$act])) {
                        $thua[] = "$menu:$act";
                    }
                }
            }
            return ['breach' => $thua !== [], 'note' => $thua ? 'cấp được quyền không có: ' . implode(',', $thua)
                                                              : 'chỉ cấp lại đúng quyền mình có'];
        });

    $h->attack('Leo thang quyền', 'Nhét menu admin-only (teams) vào JSON quyền', 'MGR_FULL',
        function ($atk, $fx) {
            $n = 'ZZABHACKROLE' . bin2hex(random_bytes(4));
            $_POST = ['id' => 0, 'role_name' => $n, 'level' => 'user', 'csrf_token' => 'HACK',
                      'permissions' => ['teams' => ['view' => 'on', 'add' => 'on', 'delete' => 'on']]];
            $res = Role::save_role();
            $id = (int)($res['id'] ?? 0);
            if (!$id) {
                return ['breach' => false, 'note' => 'không tạo được role'];
            }
            $json = json_decode((string)$fx->conn->execute_query(
                'SELECT roles FROM roles_permissions WHERE ID = ?', [$id])->fetch_row()[0], true) ?: [];
            return ['breach' => isset($json['teams']), 'note' => 'menu admin-only chui vào roles_permissions'];
        });

    $h->attack('Leo thang quyền', 'Nhét menu không tồn tại vào JSON quyền', 'MGR_FULL',
        function ($atk, $fx) {
            $n = 'ZZABHACKROLE' . bin2hex(random_bytes(4));
            $_POST = ['id' => 0, 'role_name' => $n, 'level' => 'user', 'csrf_token' => 'HACK',
                      'permissions' => ['zzab_menu_ma' => ['view' => 'on'], '*' => ['delete' => 'on']]];
            $res = Role::save_role();
            $id = (int)($res['id'] ?? 0);
            if (!$id) {
                return ['breach' => false, 'note' => 'không tạo được role'];
            }
            $json = json_decode((string)$fx->conn->execute_query(
                'SELECT roles FROM roles_permissions WHERE ID = ?', [$id])->fetch_row()[0], true) ?: [];
            return ['breach' => isset($json['zzab_menu_ma']) || isset($json['*']),
                    'note' => 'menu ma lọt vào JSON: ' . json_encode(array_keys($json))];
        });

    // ---------- Phá hệ thống ----------
    $h->attack('Phá hệ thống', 'Xóa role admin (mất hết quyền quản trị)', 'MGR_FULL',
        function ($atk, $fx) {
            $adm = (int)$fx->conn->query("SELECT ID FROM roles_permissions WHERE slug='admin' LIMIT 1")->fetch_row()[0];
            $_POST = ['id' => $adm, 'csrf_token' => 'HACK'];
            Roles::delete_role();
            $mat = $fx->conn->execute_query(
                'SELECT ID FROM roles_permissions WHERE ID = ?', [$adm])->fetch_row() === null;
            return ['breach' => $mat, 'note' => 'role admin bị xóa -> không còn ai quản trị được'];
        });

    $h->attack('Phá hệ thống', 'Hạ cấp role admin xuống user', 'MGR_FULL',
        function ($atk, $fx) use ($slugOf) {
            $adm = (int)$fx->conn->query("SELECT ID FROM roles_permissions WHERE slug='admin' LIMIT 1")->fetch_row()[0];
            $_POST = ['id' => $adm, 'role_name' => 'Admin', 'level' => 'user',
                      'permissions' => [], 'csrf_token' => 'HACK'];
            Role::save_role();
            return ['breach' => $slugOf($fx, $adm) !== 'admin', 'note' => 'slug role admin: ' . $slugOf($fx, $adm)];
        });

    $h->attack('Phá hệ thống', 'Xóa role còn người dùng, bỏ trống role thay thế', 'MGR_FULL',
        function ($atk, $fx) use ($newRole) {
            $id = $newRole($fx);
            $uid = $fx->new_user((int)$atk->team);
            $fx->conn->execute_query('UPDATE authors SET level = ? WHERE ID = ?', [$id, $uid]);
            $_POST = ['id' => $id, 'csrf_token' => 'HACK'];
            Roles::delete_role();
            $moCoi = (int)$fx->conn->query(
                'SELECT COUNT(*) FROM authors WHERE level NOT IN (SELECT ID FROM roles_permissions)')->fetch_row()[0];
            $fx->conn->execute_query('DELETE FROM authors WHERE ID = ?', [$uid]);
            $fx->conn->execute_query('DELETE FROM roles_permissions WHERE ID = ?', [$id]);
            return ['breach' => $moCoi > 0, 'note' => "$moCoi dòng authors.level mồ côi"];
        });

    // ---------- CSRF / IDOR / SQLi / XSS ----------
    $h->attack('CSRF', 'Lưu role không kèm csrf_token', 'MGR_FULL', function ($atk, $fx) {
        $n = 'ZZABHACKCSRF' . bin2hex(random_bytes(3));
        $_POST = ['id' => 0, 'role_name' => $n, 'level' => 'user', 'permissions' => []];
        Role::save_role();
        $co = $fx->conn->execute_query(
            'SELECT ID FROM roles_permissions WHERE name = ?', [$n])->fetch_row() !== null;
        return ['breach' => $co, 'note' => 'tạo được role dù thiếu CSRF'];
    });

    $h->attack('CSRF', 'Xóa role không kèm csrf_token', 'MGR_FULL',
        function ($atk, $fx) use ($newRole) {
            $id = $newRole($fx);
            $_POST = ['id' => $id];
            Roles::delete_role();
            $mat = $fx->conn->execute_query(
                'SELECT ID FROM roles_permissions WHERE ID = ?', [$id])->fetch_row() === null;
            $fx->conn->execute_query('DELETE FROM roles_permissions WHERE ID = ?', [$id]);
            return ['breach' => $mat, 'note' => 'xóa được dù thiếu CSRF'];
        });

    $h->attack('IDOR', 'Đọc role cấp admin qua id gửi thẳng', 'MGR_FULL', function ($atk, $fx) {
        $adm = (int)$fx->conn->query("SELECT ID FROM roles_permissions WHERE slug='admin' LIMIT 1")->fetch_row()[0];
        $_POST = ['id' => $adm, 'csrf_token' => 'HACK'];
        $res = Role::get_role();
        // Đọc được là chấp nhận (nút Edit đã khóa) NHƯNG cờ can_edit phải là false
        return ['breach' => ($res['status'] ?? '') === 'success' && !empty($res['can_edit']),
                'note' => 'can_edit trên role admin: ' . var_export($res['can_edit'] ?? null, true)];
    });

    $h->attack('SQLi', 'Cột sort giả mạo kèm câu DROP', 'MGR_FULL', function ($atk, $fx) {
        $truoc = (int)$fx->conn->query('SELECT COUNT(*) FROM roles_permissions')->fetch_row()[0];
        $_POST = ab_dt(['columns' => [['data' => "name; DROP TABLE roles_permissions--"]],
                        'order' => [['column' => 0, 'dir' => 'asc']]]);
        try {
            Roles::get_roles();
        } catch (\Throwable $e) {
            return ['breach' => false, 'note' => 'văng lỗi nhưng không thực thi: ' . substr($e->getMessage(), 0, 40)];
        }
        $sau = (int)$fx->conn->query('SELECT COUNT(*) FROM roles_permissions')->fetch_row()[0];
        return ['breach' => $sau !== $truoc, 'note' => "role trước $truoc / sau $sau"];
    });

    $h->attack('SQLi', 'Bộ lọc level nhét câu SQL', 'MGR_FULL', function ($atk, $fx) {
        $truoc = (int)$fx->conn->query('SELECT COUNT(*) FROM roles_permissions')->fetch_row()[0];
        $_POST = ab_dt(['columns' => [['data' => 'name']]]);
        $_POST['level'] = "user' OR '1'='1";
        try {
            $res = Roles::get_roles();
        } catch (\Throwable $e) {
            return ['breach' => false, 'note' => 'bị chặn: ' . substr($e->getMessage(), 0, 40)];
        }
        $sau = (int)$fx->conn->query('SELECT COUNT(*) FROM roles_permissions')->fetch_row()[0];
        return ['breach' => $sau !== $truoc, 'note' => 'giá trị lạ bị bỏ qua, không lọc bừa'];
    });

    $h->attack('XSS', 'Tên role nhét thẻ script', 'MGR_FULL', function ($atk, $fx) {
        $payload = 'ZZABX<img src=x onerror=alert(1)>';
        $_POST = ['id' => 0, 'role_name' => $payload, 'level' => 'user',
                  'permissions' => [], 'csrf_token' => 'HACK'];
        $res = Role::save_role();
        $id = (int)($res['id'] ?? 0);
        if (!$id) {
            return ['breach' => false, 'note' => 'không tạo được role'];
        }
        // JS render phải bọc esc() — kiểm file có gọi esc quanh name
        $js = (string)@file_get_contents(AB_ROOT . '/assets/js/app-access-permission.js');
        $coEsc = str_contains($js, "esc(full['name'])");
        $fx->conn->execute_query('DELETE FROM roles_permissions WHERE ID = ?', [$id]);
        return ['breach' => !$coEsc, 'note' => $coEsc ? 'JS bọc esc() khi render tên role'
                                                      : 'JS render tên role KHÔNG escape'];
    });
};
