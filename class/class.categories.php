<?php
/**
 * Categories — nghiệp vụ tập hợp cho bảng `type` (menu Category):
 * bảng danh sách, xóa hàng loạt và phân quyền.
 * Cùng khuôn với class.sites.php.
 *
 * Mô hình dữ liệu (đổi 2026-08-05): category DÙNG CHUNG toàn hệ thống, mọi team đều
 * thấy và dùng được — không còn chia theo team nữa (cột `type`.team_id giữ lại và luôn
 * = 0 để sau này muốn quay lại mô hình riêng/chung như store thì không phải đổi bảng).
 * Quyền: thêm theo role add; sửa thì admin hoặc chính người đã thêm; xóa chỉ admin.
 */
class Categories
{
    /**
     * Thêm category mới: ai có role add cũng làm được (thêm là bổ sung dữ liệu mới,
     * không đụng tới category đang chạy của người khác).
     */
    public static function can_add(): bool
    {
        return is_admin() || checkRoles('add', 'categories');
    }

    /**
     * Xóa category: CHỈ ADMIN — category dùng chung nên xóa là ảnh hưởng mọi team.
     */
    public static function can_manage(): bool
    {
        return is_admin();
    }

    /**
     * Sửa 1 category cụ thể: admin sửa mọi category; người khác chỉ sửa được category
     * DO CHÍNH MÌNH THÊM (type.created_by) và vẫn phải có role edit.
     * Category cũ (created_by = 0) chỉ admin sửa được.
     */
    public static function can_edit_row(int $createdBy): bool
    {
        if (is_admin()) {
            return true;
        }
        $uid = (int)($_SESSION['auth']['user_id'] ?? 0);
        return $createdBy > 0 && $uid > 0 && $createdBy === $uid && checkRoles('edit', 'categories');
    }

    /** Cột sắp xếp được của bảng Category (xem build_order_by()). */
    private const SORT_MAP = [
        'name'           => 't.name',
        'products_count' => 'products_count',
        'ID'             => 't.ID',
    ];

    /**
     * Dữ liệu cho DataTables của trang Category.
     *
     * @return array{draw:int,recordsTotal:int,recordsFiltered:int,data:array}
     */
    public static function get_categories(): array
    {
        $params = get_datatable_params(array_keys(self::SORT_MAP), 'name');
        if (!checkRoles('view', 'categories')) {
            return ['draw' => $params['draw'], 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []];
        }

        $conn = db();
        $where = '';
        // Tìm kiếm theo tên (dùng chung nên không còn giới hạn phạm vi team)
        if ($params['searchValue'] !== '') {
            $esc = $conn->real_escape_string($params['searchValue']);
            $where = " WHERE t.name LIKE '%$esc%'";
        }

        $totalRecords  = (int)$conn->query('SELECT COUNT(*) FROM `type`')->fetch_row()[0];
        $totalFiltered = (int)$conn->query("SELECT COUNT(*) FROM `type` t $where")->fetch_row()[0];

        $sql = "SELECT t.ID, t.name, t.user_prompt, t.created_by,
                       (SELECT COUNT(*) FROM posts p WHERE p.type_id = t.ID) AS products_count
                FROM `type` t
                $where
                ORDER BY " . build_order_by($params, self::SORT_MAP, 't.ID') . "
                LIMIT {$params['start']}, {$params['length']}";
        $rs = $conn->query($sql);

        $data = [];
        while ($row = $rs->fetch_assoc()) {
            $prompt = (string)($row['user_prompt'] ?? '');
            $data[] = [
                'id'             => (int)$row['ID'],
                'name'           => $row['name'],
                'products_count' => (int)$row['products_count'],
                'has_prompt'     => $prompt !== '',
                'prompt_preview' => mb_substr($prompt, 0, 120),
                // Quyền theo từng dòng: sửa được category do chính mình thêm, xóa chỉ admin
                'can_edit'       => self::can_edit_row((int)$row['created_by']),
                'can_delete'     => self::can_manage(),
            ];
        }

        return [
            'draw'            => $params['draw'],
            'recordsTotal'    => $totalRecords,
            'recordsFiltered' => $totalFiltered,
            'data'            => $data,
        ];
    }

    /**
     * Cờ quyền cấp trang (nút Add / Delete Selected). Category dùng chung nên không còn
     * bộ lọc theo team. Quyền sửa/xóa từng dòng nằm ở can_edit/can_delete trong get_categories().
     *
     * @return array{perms:array{add:bool,delete:bool,is_admin:bool}}
     */
    public static function get_categories_filters(): array
    {
        if (!checkRoles('view', 'categories')) {
            return ['status' => 'error', 'message' => 'You do not have permission to view categories.'];
        }
        return [
            'perms' => [
                'add'      => self::can_add(),
                'delete'   => self::can_manage(),
                'is_admin' => is_admin(),
            ],
        ];
    }

    /**
     * Xóa category. Chỉ admin, và chặn nếu còn sản phẩm đang dùng để không làm hỏng dữ liệu.
     *
     * @return array{status:string,deleted?:int,message?:string}
     */
    public static function delete_categories(): array
    {
        if (!check_csrf()) {
            return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
        }
        if (!self::can_manage()) {
            return ['status' => 'error',
                'message' => 'Categories are shared by every team; only an admin can delete them.'];
        }
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            return ['status' => 'error', 'message' => 'Missing category list.'];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        if (empty($ids)) {
            return ['status' => 'error', 'message' => 'Invalid category list.'];
        }

        $conn = db();
        $idsStr = implode(',', $ids);

        // Còn sản phẩm dùng category thì không cho xóa
        $inUse = $conn->query("SELECT t.name, COUNT(p.ID) AS c FROM `type` t
            JOIN posts p ON p.type_id = t.ID WHERE t.ID IN ($idsStr) GROUP BY t.ID LIMIT 1")->fetch_assoc();
        if ($inUse) {
            return ['status' => 'error',
                'message' => 'Category "' . $inUse['name'] . '" is used by ' . number_format((int)$inUse['c']) . ' products.'];
        }

        $conn->begin_transaction();
        try {
            $conn->query("DELETE FROM `type` WHERE ID IN ($idsStr)");
            $deleted = $conn->affected_rows;
            $conn->commit();
            return ['status' => 'success', 'deleted' => $deleted];
        } catch (\Throwable $e) {
            $conn->rollback();
            return ['status' => 'error', 'message' => 'Delete failed: ' . $e->getMessage()];
        }
    }
}
