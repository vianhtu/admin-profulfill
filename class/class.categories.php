<?php
/**
 * Categories — nghiệp vụ tập hợp cho bảng `type` (menu Category):
 * bảng danh sách, bộ lọc, xóa hàng loạt và phân quyền dữ liệu theo team.
 * Cùng khuôn với class.products.php / class.orders.php.
 *
 * Phạm vi dữ liệu: admin = mọi team; còn lại = category thuộc team của mình.
 * Category KHÔNG dùng chung giữa các team — mỗi team có bộ category riêng
 * (tên chỉ cần duy nhất trong phạm vi team, xem UNIQUE KEY uniq_team_name).
 */
class Categories
{
    /**
     * Điều kiện phân quyền dữ liệu cho bảng `type`.
     * Trả về chuỗi WHERE (rỗng nếu không giới hạn).
     */
    private static function get_base_auth_conditions(string $alias = 't'): string
    {
        $team = get_current_team_scope_id();
        if ($team <= 0) {
            return '';
        }
        return "$alias.team_id = $team";
    }

    /**
     * Lọc danh sách type ID về đúng phạm vi dữ liệu của user hiện tại.
     *
     * @param int[] $ids
     * @return int[]
     */
    public static function check_categories_ownership(mysqli $conn, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        if (empty($ids) || is_admin()) {
            return $ids;
        }
        $where = self::get_base_auth_conditions();
        if ($where === '') {
            return [];
        }
        $idsStr = implode(',', $ids);
        $res = $conn->query("SELECT t.ID FROM `type` t WHERE t.ID IN ($idsStr) AND $where");
        $allowed = [];
        while ($row = $res->fetch_row()) {
            $allowed[] = (int)$row[0];
        }
        return $allowed;
    }

    /**
     * Dữ liệu cho DataTables của trang Category.
     *
     * @return array{draw:int,recordsTotal:int,recordsFiltered:int,data:array}
     */
    /** Cột sắp xếp được của bảng Category (xem build_order_by()). */
    private const SORT_MAP = [
        'name'           => 't.name',
        'teams'          => 'tm.name',
        'products_count' => 'products_count',
        'ID'             => 't.ID',
    ];

    public static function get_categories(): array
    {
        $params = get_datatable_params(array_keys(self::SORT_MAP), 'name');
        if (!checkRoles('view', 'categories')) {
            return ['draw' => $params['draw'], 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []];
        }

        $conn = db();
        $whereClauses = [];
        $scopeWhere = self::get_base_auth_conditions();
        if ($scopeWhere !== '') {
            $whereClauses[] = $scopeWhere;
        }

        // Tìm kiếm theo tên
        if ($params['searchValue'] !== '') {
            $esc = $conn->real_escape_string($params['searchValue']);
            $whereClauses[] = "t.name LIKE '%$esc%'";
        }

        // Lọc theo team — chỉ admin (người khác đã bị scope giới hạn sẵn)
        $filterTeam = (int)($_POST['team'] ?? 0);
        if ($filterTeam > 0 && is_admin()) {
            $whereClauses[] = "t.team_id = $filterTeam";
        }

        $totalRecords = (int)$conn->query('SELECT COUNT(*) FROM `type` t'
            . ($scopeWhere !== '' ? " WHERE $scopeWhere" : ''))->fetch_row()[0];

        $where = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';
        $totalFiltered = (int)$conn->query("SELECT COUNT(*) FROM `type` t $where")->fetch_row()[0];

        $sql = "SELECT t.ID, t.name, t.user_prompt,
                       (SELECT COUNT(*) FROM posts p WHERE p.type_id = t.ID) AS products_count,
                       tm.name AS team_names
                FROM `type` t
                LEFT JOIN team tm ON tm.ID = t.team_id
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
                'teams'          => $row['team_names'] ?? '',
                'products_count' => (int)$row['products_count'],
                'has_prompt'     => $prompt !== '',
                'prompt_preview' => mb_substr($prompt, 0, 120),
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
     * Option cho bộ lọc + cờ quyền để frontend ẩn/hiện nút.
     *
     * @return array{teams:array,perms:array{add:bool,edit:bool,delete:bool,filter_team:bool}}
     */
    public static function get_categories_filters(): array
    {
        if (!checkRoles('view', 'categories')) {
            return ['status' => 'error', 'message' => 'You do not have permission to view categories.'];
        }
        return [
            'teams' => is_admin() ? get_all_teams() : [],
            'perms' => [
                'add'         => checkRoles('add', 'categories'),
                'edit'        => checkRoles('edit', 'categories'),
                'delete'      => checkRoles('delete', 'categories'),
                'filter_team' => is_admin(),
            ],
        ];
    }

    /**
     * Xóa category. Chặn nếu còn sản phẩm đang dùng để không làm hỏng dữ liệu.
     *
     * @return array{status:string,deleted?:int,message?:string}
     */
    public static function delete_categories(): array
    {
        if (!checkRoles('delete', 'categories')) {
            return ['status' => 'error', 'message' => 'You do not have permission to delete categories.'];
        }
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            return ['status' => 'error', 'message' => 'Missing category list.'];
        }

        $conn = db();
        $ids = self::check_categories_ownership($conn, $ids);
        if (empty($ids)) {
            return ['status' => 'error', 'message' => 'No permitted categories in selection.'];
        }
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
