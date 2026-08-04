<?php
/**
 * Category — thao tác trên MỘT category của bảng `type` (form Add/Edit).
 * Cùng khuôn với class.product.php: phần danh sách nằm ở class.categories.php.
 */
class Category
{
    /**
     * Lấy 1 category để đổ vào form Edit.
     * Trả null nếu không tồn tại, không có quyền xem, hoặc ngoài phạm vi team.
     */
    public static function get_category(int $id): ?array
    {
        if ($id <= 0 || !checkRoles('view', 'categories')) {
            return null;
        }
        $conn = db();
        if (empty(Categories::check_categories_ownership($conn, [$id]))) {
            return null;
        }

        $stmt = $conn->prepare('SELECT ID, name, team_id, user_prompt FROM `type` WHERE ID = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }

        return $row;
    }

    /**
     * Team được phép gán cho category.
     * Admin gán cho team nào cũng được; còn lại luôn là team của chính mình.
     * Trả 0 nếu lựa chọn không hợp lệ.
     */
    private static function resolve_team_id(int $requested): int
    {
        $ownTeam = (int)($_SESSION['auth']['team'] ?? 0);
        if (!is_admin()) {
            // Bỏ qua giá trị gửi lên, luôn ép về team của user
            return $ownTeam;
        }
        if ($requested <= 0) {
            return $ownTeam;
        }
        $res = db()->query('SELECT ID FROM team WHERE ID = ' . $requested . ' LIMIT 1');
        return ($res && $res->fetch_row()) ? $requested : 0;
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

        if (!checkRoles($isEdit ? 'edit' : 'add', 'categories')) {
            return ['status' => 'error',
                'message' => 'You do not have permission to ' . ($isEdit ? 'edit' : 'add') . ' categories.'];
        }
        if (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
            return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
        }
        if ($isEdit && empty(Categories::check_categories_ownership($conn, [$id]))) {
            return ['status' => 'error', 'message' => 'Category not found or not in your scope.'];
        }

        $name   = trim((string)($_POST['name'] ?? ''));
        $prompt = trim((string)($_POST['user_prompt'] ?? ''));
        if ($name === '') {
            return ['status' => 'error', 'message' => 'Please enter the category name.'];
        }

        $teamId = self::resolve_team_id((int)($_POST['team_id'] ?? 0));
        if ($teamId <= 0) {
            return ['status' => 'error', 'message' => 'Please select a valid team.'];
        }

        // Tên chỉ cần duy nhất TRONG TEAM (UNIQUE KEY uniq_team_name),
        // hai team khác nhau được phép có category trùng tên.
        $stmt = $conn->prepare('SELECT ID FROM `type` WHERE name = ? AND team_id = ? AND ID <> ? LIMIT 1');
        $stmt->bind_param('sii', $name, $teamId, $id);
        $stmt->execute();
        if ($stmt->get_result()->fetch_row()) {
            $stmt->close();
            return ['status' => 'error', 'message' => 'This category name already exists in this team.'];
        }
        $stmt->close();
        $conn->begin_transaction();
        try {
            if ($isEdit) {
                $stmt = $conn->prepare('UPDATE `type` SET name = ?, team_id = ?, user_prompt = ? WHERE ID = ?');
                $stmt->bind_param('sisi', $name, $teamId, $prompt, $id);
            } else {
                $stmt = $conn->prepare('INSERT INTO `type` (name, team_id, user_prompt) VALUES (?, ?, ?)');
                $stmt->bind_param('sis', $name, $teamId, $prompt);
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
