<?php
/**
 * Store — thao tác trên MỘT shop của bảng `store` (form Add/Edit).
 * Cùng khuôn với class.site.php / class.category.php.
 *
 * Store dùng chung giữa các team nên mọi thao tác ghi đều yêu cầu admin
 * (xem Stores::can_manage()).
 */
class Store
{
    /**
     * Lấy 1 store để đổ vào form Edit. Ai có quyền xem cũng đọc được vì dữ liệu dùng chung.
     */
    public static function get_store(int $id): ?array
    {
        if ($id <= 0 || !checkRoles('view', 'store')) {
            return null;
        }
        $conn = db();
        $stmt = $conn->prepare('SELECT ID, name, slug, status, site_id FROM store WHERE ID = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
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

        // Dùng chung giữa các team -> chỉ admin được thêm/sửa
        if (!Stores::can_manage()) {
            return ['status' => 'error',
                'message' => 'Stores are shared between teams; only an admin can change them.'];
        }
        if (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
            return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
        }
        if ($isEdit && !$conn->query('SELECT ID FROM store WHERE ID = ' . $id . ' LIMIT 1')->fetch_row()) {
            return ['status' => 'error', 'message' => 'Store not found.'];
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

        $slug = self::make_slug((string)($_POST['slug'] ?? '')) ?: self::make_slug($name);
        if ($slug === '') {
            return ['status' => 'error', 'message' => 'Please enter a valid slug.'];
        }

        // slug là UNIQUE toàn bảng — đây chính là cơ chế chống lặp dữ liệu giữa các team
        $stmt = $conn->prepare('SELECT ID FROM store WHERE slug = ? AND ID <> ? LIMIT 1');
        $stmt->bind_param('si', $slug, $id);
        $stmt->execute();
        if ($stmt->get_result()->fetch_row()) {
            $stmt->close();
            return ['status' => 'error', 'message' => 'This store already exists (same slug).'];
        }
        $stmt->close();

        if ($isEdit) {
            $stmt = $conn->prepare('UPDATE store SET name = ?, slug = ?, site_id = ?, status = ? WHERE ID = ?');
            $stmt->bind_param('ssiii', $name, $slug, $siteId, $status, $id);
        } else {
            $stmt = $conn->prepare('INSERT INTO store (name, slug, site_id, status) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('ssii', $name, $slug, $siteId, $status);
        }
        if (!$stmt->execute()) {
            return ['status' => 'error', 'message' => 'Save failed: ' . $conn->error];
        }
        $newId = $isEdit ? $id : (int)$conn->insert_id;
        $stmt->close();

        return ['status' => $isEdit ? 'updated' : 'inserted', 'id' => $newId];
    }
}
