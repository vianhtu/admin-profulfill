<?php
/**
 * Category — thao tác trên MỘT category của bảng `type` (form Add/Edit).
 * Cùng khuôn với class.site.php: phần danh sách nằm ở class.categories.php.
 *
 * Category dùng chung toàn hệ thống — xem ghi chú mô hình ở class.categories.php.
 */
class Category
{
    /**
     * Lấy 1 category để đổ vào form Edit. Dùng chung nên ai có quyền xem cũng đọc được.
     */
    public static function get_category(int $id): ?array
    {
        if ($id <= 0 || !checkRoles('view', 'categories')) {
            return null;
        }
        $conn = db();
        $stmt = $conn->prepare('SELECT ID, name, user_prompt, created_by FROM `type` WHERE ID = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * Thêm mới / cập nhật 1 category.
     *
     * @return array{status:string,id?:int,message?:string}
     */
    public static function save_category(): array
    {
        $conn = db();
        $id = (int)($_POST['id'] ?? 0);
        $isEdit = $id > 0;

        if (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
            return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
        }
        // Thêm mới: theo role add. Sửa: admin, hoặc chính người đã thêm category đó.
        if ($isEdit) {
            $current = $conn->query('SELECT ID, created_by FROM `type` WHERE ID = ' . $id . ' LIMIT 1')->fetch_assoc();
            if (!$current) {
                return ['status' => 'error', 'message' => 'Category not found.'];
            }
            if (!Categories::can_edit_row((int)$current['created_by'])) {
                return ['status' => 'error',
                    'message' => 'Categories are shared by every team; you can only change a category you added yourself.'];
            }
        } elseif (!Categories::can_add()) {
            return ['status' => 'error', 'message' => 'You do not have permission to add categories.'];
        }

        $name   = trim((string)($_POST['name'] ?? ''));
        $prompt = trim((string)($_POST['user_prompt'] ?? ''));
        if ($name === '') {
            return ['status' => 'error', 'message' => 'Please enter the category name.'];
        }

        // Dùng chung nên tên phải duy nhất TOÀN HỆ THỐNG (UNIQUE KEY uniq_team_name với
        // team_id luôn = 0) — đây là cơ chế chống lặp danh mục giữa các team.
        $stmt = $conn->prepare('SELECT ID FROM `type` WHERE name = ? AND ID <> ? LIMIT 1');
        $stmt->bind_param('si', $name, $id);
        $stmt->execute();
        if ($stmt->get_result()->fetch_row()) {
            $stmt->close();
            return ['status' => 'error', 'message' => 'This category name already exists.'];
        }
        $stmt->close();

        $conn->begin_transaction();
        try {
            if ($isEdit) {
                $stmt = $conn->prepare('UPDATE `type` SET name = ?, user_prompt = ? WHERE ID = ?');
                $stmt->bind_param('ssi', $name, $prompt, $id);
            } else {
                // team_id = 0: dùng chung. created_by để sau này chính họ sửa được.
                $uid = (int)($_SESSION['auth']['user_id'] ?? 0);
                $stmt = $conn->prepare('INSERT INTO `type` (name, team_id, user_prompt, created_by) VALUES (?, 0, ?, ?)');
                $stmt->bind_param('ssi', $name, $prompt, $uid);
            }
            if (!$stmt->execute()) {
                throw new Exception($conn->error);
            }
            $newId = $isEdit ? $id : (int)$conn->insert_id;
            $stmt->close();

            $conn->commit();
            return ['status' => $isEdit ? 'updated' : 'inserted', 'id' => $newId];
        } catch (\Throwable $e) {
            $conn->rollback();
            return ['status' => 'error', 'message' => 'Save failed: ' . $e->getMessage()];
        }
    }
}
