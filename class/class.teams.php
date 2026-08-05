<?php
/**
 * Teams — nghiệp vụ tập hợp cho bảng `team` (menu Teams): danh sách, xóa.
 * Cùng khuôn với class.stores.php / class.orders.php.
 *
 * Mô hình sở hữu (chốt với người dùng 05/08/2026): menu ADMIN-ONLY — không đi qua
 * roles_permissions; mọi endpoint tự kiểm is_admin(). Team là bản ghi cha bị
 * authors/accounts/store/store_teams tham chiếu -> CHẶN xóa khi còn tham chiếu
 * (giống Category/Site), không gỡ liên kết tự động.
 */
class Teams
{
    /**
     * Cột sắp xếp được: tên cột DataTables => biểu thức SQL.
     * Cột nào không có ở đây thì bên JS phải để orderable: false.
     */
    private const SORT_MAP = [
        'name'    => 't.name',
        'members' => 'members',
        'status'  => 't.status',
        'ID'      => 't.ID',
    ];

    /** Các bảng còn tham chiếu team thì chặn xóa: bảng => cột team id. */
    private const REF_TABLES = [
        'members'  => ['authors', 'team_id'],
        'accounts' => ['accounts', 'team_id'],
        'stores'   => ['store', 'team_id'],
        'shared stores' => ['store_teams', 'team_id'],
    ];

    /**
     * Dữ liệu cho DataTables của trang Teams (server-side).
     *
     * @return array{draw:int,recordsTotal:int,recordsFiltered:int,data:array}
     */
    public static function get_teams(): array
    {
        $params = get_datatable_params(array_keys(self::SORT_MAP), 'name');
        if (!is_admin()) {
            return ['draw' => $params['draw'], 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []];
        }

        $conn = db();

        $whereClauses = [];
        if ($params['searchValue'] !== '') {
            $esc = $conn->real_escape_string($params['searchValue']);
            $whereClauses[] = "t.name LIKE '%$esc%'";
        }
        $where = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

        $totalRecords  = (int)$conn->query('SELECT COUNT(*) FROM team t')->fetch_row()[0];
        $totalFiltered = (int)$conn->query("SELECT COUNT(*) FROM team t $where")->fetch_row()[0];

        $sql = "SELECT t.ID, t.name, t.`key`, t.status,
                       (SELECT COUNT(*) FROM authors a WHERE a.team_id = t.ID) AS members
                FROM team t
                $where
                ORDER BY " . build_order_by($params, self::SORT_MAP, 't.ID') . "
                LIMIT {$params['start']}, {$params['length']}";
        $rs = $conn->query($sql);

        $data = [];
        while ($row = $rs->fetch_assoc()) {
            $data[] = [
                'id'         => (int)$row['ID'],
                'name'       => $row['name'],
                // Key là credential extension — chỉ trả ở đây vì trang admin-only,
                // hiển thị qua nút View Key chứ không in thẳng ra bảng
                'key'        => $row['key'],
                'status'     => (int)$row['status'],
                'members'    => (int)$row['members'],
                // Trang admin-only nên quyền mọi dòng như nhau — vẫn trả cờ theo
                // từng dòng đúng quy ước chung để JS dựng nút từ cờ.
                'can_edit'   => true,
                'can_delete' => true,
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
     * Xóa team (1 hoặc nhiều). Chặn khi team còn được tham chiếu ở bất kỳ bảng nào
     * (members/accounts/stores) — người dùng phải dời dữ liệu sang team khác trước.
     *
     * @return array{status:string,deleted?:int,message?:string}
     */
    public static function delete_teams(): array
    {
        if (!check_csrf()) {
            return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
        }
        if (!is_admin()) {
            return ['status' => 'error', 'message' => 'Only an admin can delete teams.'];
        }

        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            return ['status' => 'error', 'message' => 'Missing team list.'];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        if (empty($ids)) {
            return ['status' => 'error', 'message' => 'Invalid team list.'];
        }

        $conn = db();
        $idsStr = implode(',', $ids);

        // Lớp dữ liệu: team còn tham chiếu thì từ chối xóa, báo rõ vướng ở đâu
        $blocked = [];
        foreach (self::REF_TABLES as $label => [$table, $col]) {
            $rs = $conn->query("SELECT $col AS tid, COUNT(*) AS cnt FROM `$table` WHERE $col IN ($idsStr) GROUP BY $col");
            while ($row = $rs->fetch_assoc()) {
                $blocked[(int)$row['tid']][] = $row['cnt'] . ' ' . $label;
            }
        }
        if (!empty($blocked)) {
            $names = [];
            $rs = $conn->query('SELECT ID, name FROM team WHERE ID IN (' . implode(',', array_keys($blocked)) . ')');
            while ($row = $rs->fetch_assoc()) {
                $names[] = $row['name'] . ' (' . implode(', ', $blocked[(int)$row['ID']]) . ')';
            }
            return ['status' => 'error',
                'message' => 'Cannot delete: ' . implode('; ', $names) . '. Move or unlink them first.'];
        }

        try {
            $conn->query("DELETE FROM team WHERE ID IN ($idsStr)");
            $deleted = $conn->affected_rows;
        } catch (\mysqli_sql_exception $e) {
            return ['status' => 'error', 'message' => 'Delete failed: ' . $e->getMessage()];
        }

        if ($deleted === 0) {
            return ['status' => 'error', 'message' => 'Team not found.'];
        }
        return ['status' => 'success', 'deleted' => $deleted];
    }
}
