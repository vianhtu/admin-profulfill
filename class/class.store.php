<?php
/**
 * Store — thao tác trên MỘT shop của bảng `store` (form Add/Edit).
 * Cùng khuôn với class.site.php / class.category.php.
 *
 * Sở hữu xem Stores: team_id = 0 là dùng chung (chỉ admin sửa được),
 * team_id = N là dùng riêng của team N (team đó tự sửa, admin vẫn toàn quyền).
 */
class Store
{
    /**
     * Lấy 1 store để đổ vào form Edit.
     * Chỉ trả về store nằm trong phạm vi nhìn thấy: dùng chung + store riêng của team.
     */
    public static function get_store(int $id): ?array
    {
        if ($id <= 0 || !checkRoles('view', 'store')) {
            return null;
        }
        $conn = db();
        $stmt = $conn->prepare('SELECT ID, name, slug, status, site_id, team_id FROM store WHERE ID = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        $row['team_id'] = (int)$row['team_id'];
        // Store riêng của team khác thì coi như không tồn tại
        if (!is_admin() && !Stores::is_shared($row['team_id']) && $row['team_id'] !== (int)($_SESSION['auth']['team'] ?? 0)) {
            return null;
        }
        // status NULL (dữ liệu cũ) coi như Active
        $row['status'] = $row['status'] === null ? 1 : (int)$row['status'];
        return $row;
    }

    /**
     * Chuẩn hóa slug shop: chữ thường, bỏ khoảng trắng — khớp cách extension sinh slug
     * (strtolower của tên shop) để không tạo bản ghi trùng.
     */
    private static function make_slug(string $text): string
    {
        return strtolower(trim($text));
    }

    /**
     * Thêm mới / cập nhật 1 store.
     *
     * @return array{status:string,id?:int,message?:string}
     */
    public static function save_store(): array
    {
        $conn = db();
        $id = (int)($_POST['id'] ?? 0);
        $isEdit = $id > 0;

        if (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
            return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
        }

        // Quyền: thêm mới theo role, sửa theo chủ sở hữu của chính dòng đó
        if ($isEdit) {
            $current = $conn->query('SELECT ID, team_id FROM store WHERE ID = ' . $id . ' LIMIT 1')->fetch_assoc();
            if (!$current) {
                return ['status' => 'error', 'message' => 'Store not found.'];
            }
            if (!Stores::can_edit_row((int)$current['team_id'])) {
                return ['status' => 'error',
                    'message' => 'This store is shared or belongs to another team; only an admin can change it.'];
            }
        } elseif (!Stores::can_add()) {
            return ['status' => 'error', 'message' => 'You do not have permission to add stores.'];
        }

        $name   = trim((string)($_POST['name'] ?? ''));
        $siteId = (int)($_POST['site_id'] ?? 0);
        $status = (int)($_POST['status'] ?? 1) === 0 ? 0 : 1;
        if ($name === '') {
            return ['status' => 'error', 'message' => 'Please enter the store name.'];
        }
        if ($siteId <= 0 || !$conn->query('SELECT ID FROM site WHERE ID = ' . $siteId . ' LIMIT 1')->fetch_row()) {
            return ['status' => 'error', 'message' => 'Please select a valid site.'];
        }

        // Team sở hữu: chỉ admin được chọn (0 = dùng chung cho mọi team).
        // Role khác luôn tạo/giữ store trong team của mình, bỏ qua giá trị gửi lên.
        if (is_admin()) {
            $teamId = (int)($_POST['team_id'] ?? 0);
            if ($teamId > 0 && !$conn->query('SELECT ID FROM team WHERE ID = ' . $teamId . ' LIMIT 1')->fetch_row()) {
                return ['status' => 'error', 'message' => 'Please select a valid team.'];
            }
        } else {
            $teamId = (int)($_SESSION['auth']['team'] ?? 0);
            if ($teamId <= 0) {
                return ['status' => 'error', 'message' => 'Your account is not assigned to a team.'];
            }
        }

        $slug = self::make_slug((string)($_POST['slug'] ?? '')) ?: self::make_slug($name);
        if ($slug === '') {
            return ['status' => 'error', 'message' => 'Please enter a valid slug.'];
        }

        // slug là UNIQUE toàn bảng — cùng một shop không bao giờ bị nhập 2 lần,
        // kể cả khi một bản là dùng chung còn bản kia là của riêng team.
        $stmt = $conn->prepare('SELECT s.ID, s.team_id, tm.name AS team_name
            FROM store s LEFT JOIN team tm ON tm.ID = s.team_id
            WHERE s.slug = ? AND s.ID <> ? LIMIT 1');
        $stmt->bind_param('si', $slug, $id);
        $stmt->execute();
        $dup = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($dup) {
            $dupTeam = (int)$dup['team_id'];
            if (Stores::is_shared($dupTeam)) {
                $msg = 'This store already exists as a shared store — use that one instead of adding it again.';
            } elseif ($dupTeam === $teamId) {
                $msg = 'This store already exists in your team.';
            } else {
                $msg = 'This store already exists' . (is_admin() ? ' in team "' . $dup['team_name'] . '"' : ' and belongs to another team')
                    . '. Ask an admin to share it if you need to use it.';
            }
            return ['status' => 'error', 'message' => $msg];
        }

        if ($isEdit) {
            $stmt = $conn->prepare('UPDATE store SET name = ?, slug = ?, site_id = ?, status = ?, team_id = ? WHERE ID = ?');
            $stmt->bind_param('ssiiii', $name, $slug, $siteId, $status, $teamId, $id);
        } else {
            $stmt = $conn->prepare('INSERT INTO store (name, slug, site_id, status, team_id) VALUES (?, ?, ?, ?, ?)');
            $stmt->bind_param('ssiii', $name, $slug, $siteId, $status, $teamId);
        }
        if (!$stmt->execute()) {
            return ['status' => 'error', 'message' => 'Save failed: ' . $conn->error];
        }
        $newId = $isEdit ? $id : (int)$conn->insert_id;
        $stmt->close();

        return ['status' => $isEdit ? 'updated' : 'inserted', 'id' => $newId];
    }
}
