<?php
/**
 * Stores — nghiệp vụ tập hợp cho bảng `store` (menu Stores): danh sách, lọc, xóa.
 * Cùng khuôn với class.sites.php / class.categories.php.
 *
 * LƯU Ý mô hình dữ liệu: mỗi bản ghi là MỘT shop có thật trên sàn (vd. etsy.com/0FineArtPrint).
 * Store có 2 chế độ sở hữu, quyết định bởi cột `store.team_id`:
 *   - team_id = 0  -> DÙNG CHUNG: mọi team đều thấy và dùng được, CHỈ ADMIN được sửa/xóa
 *                     (một team sửa sẽ ảnh hưởng tất cả team khác).
 *   - team_id = N  -> DÙNG RIÊNG của team N: chỉ team đó (và admin) thấy, sửa, xóa.
 * Mỗi store chỉ thuộc tối đa 1 team. Slug vẫn là UNIQUE toàn bảng nên cùng một shop
 * không bao giờ bị nhập 2 lần, dù ở chế độ chung hay riêng.
 */
class Stores
{
    /**
     * Team của user đang đăng nhập (0 nếu chưa gán team).
     */
    private static function own_team(): int
    {
        return (int)($_SESSION['auth']['team'] ?? 0);
    }

    /**
     * Store chưa gán team -> dữ liệu dùng chung toàn hệ thống.
     */
    public static function is_shared(int $teamId): bool
    {
        return $teamId <= 0;
    }

    /**
     * Được thêm store mới không. Non-admin thêm được nhưng store luôn thuộc team của họ.
     */
    public static function can_add(): bool
    {
        return is_admin() || checkRoles('add', 'store');
    }

    /**
     * Được sửa 1 store cụ thể không: admin toàn quyền, còn lại chỉ store riêng của team mình.
     */
    public static function can_edit_row(int $teamId): bool
    {
        if (is_admin()) {
            return true;
        }
        if (self::is_shared($teamId)) {
            return false; // dữ liệu dùng chung -> chỉ admin
        }
        return $teamId === self::own_team() && checkRoles('edit', 'store');
    }

    /**
     * Được xóa 1 store cụ thể không (cùng luật với sửa, nhưng theo role delete).
     */
    public static function can_delete_row(int $teamId): bool
    {
        if (is_admin()) {
            return true;
        }
        if (self::is_shared($teamId)) {
            return false;
        }
        return $teamId === self::own_team() && checkRoles('delete', 'store');
    }

    /**
     * Còn store nào có thể xóa được không — dùng để quyết định hiện nút Delete Selected.
     */
    public static function can_delete_any(): bool
    {
        return is_admin() || (self::own_team() > 0 && checkRoles('delete', 'store'));
    }

    /**
     * Điều kiện SQL giới hạn các store được phép NHÌN THẤY / SỬ DỤNG:
     * store dùng chung (team_id = 0) + store riêng của team đang xét.
     * Trả về chuỗi rỗng khi admin không lọc theo team (thấy tất cả).
     *
     * @param string $alias alias của bảng store trong câu SQL
     */
    public static function scope_condition(string $alias = 't'): string
    {
        $team = get_current_team_scope_id();
        if (is_admin() && $team <= 0) {
            return '';
        }
        return "$alias.team_id IN (0, $team)";
    }

    /**
     * Dữ liệu cho DataTables của trang Stores.
     *
     * @return array{draw:int,recordsTotal:int,recordsFiltered:int,data:array}
     */
    public static function get_stores(): array
    {
        $params = get_datatable_params(['ID', 'name', 'slug', 'status']);
        if (!checkRoles('view', 'store')) {
            return ['draw' => $params['draw'], 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []];
        }

        $conn = db();

        // Phạm vi dữ liệu: admin thấy tất cả (kèm bộ lọc team tùy chọn),
        // các role khác chỉ thấy store dùng chung + store riêng của team mình.
        if (is_admin()) {
            $filterTeam = $_POST['team'] ?? '';
            $scope = $filterTeam !== '' ? 't.team_id = ' . (int)$filterTeam : '';
        } else {
            $scope = 't.team_id IN (0, ' . self::own_team() . ')';
        }

        $whereClauses = $scope ? [$scope] : [];

        if ($params['searchValue'] !== '') {
            $esc = $conn->real_escape_string($params['searchValue']);
            $whereClauses[] = "(t.name LIKE '%$esc%' OR t.slug LIKE '%$esc%')";
        }
        // Lọc theo sàn
        $filterSite = (int)($_POST['site'] ?? 0);
        if ($filterSite > 0) {
            $whereClauses[] = "t.site_id = $filterSite";
        }
        // Lọc theo trạng thái (NULL coi như Active)
        $filterStatus = $_POST['status'] ?? '';
        if ($filterStatus === '1') {
            $whereClauses[] = '(t.status = 1 OR t.status IS NULL)';
        } elseif ($filterStatus === '0') {
            $whereClauses[] = 't.status = 0';
        }

        $where = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';
        // Tổng số tính theo phạm vi được phép xem, không phải toàn bảng
        $scopeWhere    = $scope ? " WHERE $scope" : '';
        $totalRecords  = (int)$conn->query("SELECT COUNT(*) FROM store t $scopeWhere")->fetch_row()[0];
        $totalFiltered = (int)$conn->query("SELECT COUNT(*) FROM store t $where")->fetch_row()[0];

        $sql = "SELECT t.ID, t.name, t.slug, t.status, t.site_id, t.team_id,
                       s.name AS site_name, s.logo AS site_logo,
                       tm.name AS team_name,
                       (SELECT COUNT(*) FROM posts p WHERE p.store_id = t.ID) AS products_count
                FROM store t
                LEFT JOIN site s ON s.ID = t.site_id
                LEFT JOIN team tm ON tm.ID = t.team_id
                $where
                ORDER BY t.{$params['orderColumn']} {$params['orderDir']}
                LIMIT {$params['start']}, {$params['length']}";
        $rs = $conn->query($sql);

        $data = [];
        while ($row = $rs->fetch_assoc()) {
            $teamId = (int)$row['team_id'];
            $data[] = [
                'id'             => (int)$row['ID'],
                'name'           => $row['name'],
                'slug'           => $row['slug'],
                'status'         => $row['status'] === null ? 1 : (int)$row['status'],
                'site_name'      => (string)($row['site_name'] ?? ''),
                'site_logo'      => (string)($row['site_logo'] ?? ''),
                'team_id'        => $teamId,
                'team_name'      => (string)($row['team_name'] ?? ''),
                'products_count' => (int)$row['products_count'],
                // Quyền tính theo TỪNG dòng: dùng chung -> chỉ admin, dùng riêng -> team sở hữu
                'can_edit'       => self::can_edit_row($teamId),
                'can_delete'     => self::can_delete_row($teamId),
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
     * Option bộ lọc + cờ quyền chung của trang.
     * Quyền sửa/xóa thật sự nằm ở từng dòng (can_edit / can_delete trong get_stores()).
     *
     * @return array{sites:array,teams:array,perms:array{add:bool,delete:bool,is_admin:bool}}
     */
    public static function get_stores_filters(): array
    {
        if (!checkRoles('view', 'store')) {
            return ['status' => 'error', 'message' => 'You do not have permission to view stores.'];
        }
        return [
            'sites' => get_all_sites(),
            // Lọc theo team chỉ dành cho admin — role khác vốn chỉ thấy team mình
            'teams' => is_admin() ? get_data_map('team', 'name') : [],
            'perms' => [
                'add'      => self::can_add(),
                'delete'   => self::can_delete_any(),
                'is_admin' => is_admin(),
            ],
        ];
    }

    /**
     * Xóa store. Chặn nếu không đủ quyền với dòng đó, hoặc còn sản phẩm đang dùng.
     *
     * @return array{status:string,deleted?:int,message?:string}
     */
    public static function delete_stores(): array
    {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            return ['status' => 'error', 'message' => 'Missing store list.'];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        if (empty($ids)) {
            return ['status' => 'error', 'message' => 'Invalid store list.'];
        }
        if (!self::can_delete_any()) {
            return ['status' => 'error', 'message' => 'You do not have permission to delete stores.'];
        }

        $conn = db();
        $idsStr = implode(',', $ids);

        // Kiểm tra quyền trên từng dòng — không tin danh sách ID gửi lên
        $rs = $conn->query("SELECT ID, team_id FROM store WHERE ID IN ($idsStr)");
        $allowed = [];
        $denied  = 0;
        while ($row = $rs->fetch_assoc()) {
            if (self::can_delete_row((int)$row['team_id'])) {
                $allowed[] = (int)$row['ID'];
            } else {
                $denied++;
            }
        }
        if ($denied > 0) {
            return ['status' => 'error', 'message' => $denied . ' of the selected stores are shared or belong to another team. Only an admin can delete those.'];
        }
        if (empty($allowed)) {
            return ['status' => 'error', 'message' => 'Store not found.'];
        }
        $idsStr = implode(',', $allowed);

        $inUse = $conn->query("SELECT t.name, COUNT(p.ID) AS c FROM store t
            JOIN posts p ON p.store_id = t.ID WHERE t.ID IN ($idsStr) GROUP BY t.ID LIMIT 1")->fetch_assoc();
        if ($inUse) {
            return ['status' => 'error',
                'message' => 'Store "' . $inUse['name'] . '" is used by ' . number_format((int)$inUse['c']) . ' products.'];
        }

        if (!$conn->query("DELETE FROM store WHERE ID IN ($idsStr)")) {
            return ['status' => 'error', 'message' => 'Delete failed: ' . $conn->error];
        }
        return ['status' => 'success', 'deleted' => $conn->affected_rows];
    }
}
