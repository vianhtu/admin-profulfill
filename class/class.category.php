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

        // Các team đang dùng chung category này
        $res = $conn->query('SELECT team_id FROM type_teams WHERE type_id = ' . (int)$id);
        $row['team_ids'] = [];
        while ($t = $res->fetch_row()) {
            $row['team_ids'][] = (int)$t[0];
        }
        return $row;
    }

    /**
     * Danh sách team được phép gán cho category.
     * Admin chọn team nào cũng được; còn lại chỉ gán được team của chính mình.
     *
     * @param int[] $requested
     * @return int[] Rỗng nghĩa là lựa chọn không hợp lệ
     */
    private static function resolve_team_ids(array $requested): array
    {
        $ownTeam = (int)($_SESSION['auth']['team'] ?? 0);
        $requested = array_values(array_unique(array_filter(array_map('intval', $requested), fn($v) => $v > 0)));

        if (!is_admin()) {
            // Bỏ qua mọi team khác, luôn ép về team của user
            return $ownTeam > 0 ? [$ownTeam] : [];
        }
        if (empty($requested)) {
            return $ownTeam > 0 ? [$ownTeam] : [];
        }
        $ids = implode(',', $requested);
        $res = db()->query("SELECT ID FROM team WHERE ID IN ($ids)");
        $valid = [];
        while ($r = $res->fetch_row()) {
            $valid[] = (int)$r[0];
        }
        return $valid;
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

        $teamIds = self::resolve_team_ids((array)json_decode($_POST['team_ids'] ?? '[]', true));
        if (empty($teamIds)) {
            return ['status' => 'error', 'message' => 'Please select at least one valid team.'];
        }

        // name là UNIQUE — chặn trùng với category khác
        $stmt = $conn->prepare('SELECT ID FROM `type` WHERE name = ? AND ID <> ? LIMIT 1');
        $stmt->bind_param('si', $name, $id);
        $stmt->execute();
        if ($stmt->get_result()->fetch_row()) {
            $stmt->close();
            return ['status' => 'error', 'message' => 'This category name already exists.'];
        }
        $stmt->close();

        $ownerTeam = $teamIds[0];
        $conn->begin_transaction();
        try {
            if ($isEdit) {
                $stmt = $conn->prepare('UPDATE `type` SET name = ?, user_prompt = ? WHERE ID = ?');
                $stmt->bind_param('ssi', $name, $prompt, $id);
            } else {
                $stmt = $conn->prepare('INSERT INTO `type` (name, team_id, user_prompt) VALUES (?, ?, ?)');
                $stmt->bind_param('sis', $name, $ownerTeam, $prompt);
            }
            if (!$stmt->execute()) {
                throw new Exception($conn->error);
            }
            $newId = $isEdit ? $id : (int)$conn->insert_id;
            $stmt->close();

            // Đồng bộ team dùng chung
            $conn->query('DELETE FROM type_teams WHERE type_id = ' . $newId);
            foreach ($teamIds as $tid) {
                $conn->query("INSERT IGNORE INTO type_teams (type_id, team_id) VALUES ($newId, $tid)");
            }

            $conn->commit();
            return ['status' => $isEdit ? 'updated' : 'inserted', 'id' => $newId];
        } catch (\Throwable $e) {
            $conn->rollback();
            return ['status' => 'error', 'message' => 'Save failed: ' . $e->getMessage()];
        }
    }
}
