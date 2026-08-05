<?php
/**
 * Stores — nghiệp vụ tập hợp cho bảng `store` (menu Stores): danh sách, lọc, xóa.
 * Cùng khuôn với class.sites.php / class.categories.php.
 *
 * LƯU Ý mô hình dữ liệu: mỗi bản ghi là MỘT shop có thật trên sàn (vd. etsy.com/0FineArtPrint).
 * Store DÙNG CHUNG giữa các team để tránh lặp dữ liệu — mọi team đều nhìn thấy và
 * dùng được. Chính vì dùng chung nên CHỈ ADMIN được sửa/xóa: một team sửa sẽ ảnh
 * hưởng tất cả team khác.
 */
class Stores
{
    /**
     * Chỉ admin mới được thay đổi store (dữ liệu dùng chung).
     */
    public static function can_manage(): bool
    {
        return is_admin();
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
        $whereClauses = [];

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
        $totalRecords  = (int)$conn->query('SELECT COUNT(*) FROM store')->fetch_row()[0];
        $totalFiltered = (int)$conn->query("SELECT COUNT(*) FROM store t $where")->fetch_row()[0];

        $sql = "SELECT t.ID, t.name, t.slug, t.status, t.site_id,
                       s.name AS site_name, s.logo AS site_logo,
                       (SELECT COUNT(*) FROM posts p WHERE p.store_id = t.ID) AS products_count
                FROM store t
                LEFT JOIN site s ON s.ID = t.site_id
                $where
                ORDER BY t.{$params['orderColumn']} {$params['orderDir']}
                LIMIT {$params['start']}, {$params['length']}";
        $rs = $conn->query($sql);

        $data = [];
        while ($row = $rs->fetch_assoc()) {
            $data[] = [
                'id'             => (int)$row['ID'],
                'name'           => $row['name'],
                'slug'           => $row['slug'],
                'status'         => $row['status'] === null ? 1 : (int)$row['status'],
                'site_name'      => (string)($row['site_name'] ?? ''),
                'site_logo'      => (string)($row['site_logo'] ?? ''),
                'products_count' => (int)$row['products_count'],
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
     * Option bộ lọc + cờ quyền. Store dùng chung nên add/edit/delete chỉ dành cho admin,
     * bất kể role có bật hay không.
     *
     * @return array{sites:array,perms:array{add:bool,edit:bool,delete:bool}}
     */
    public static function get_stores_filters(): array
    {
        if (!checkRoles('view', 'store')) {
            return ['status' => 'error', 'message' => 'You do not have permission to view stores.'];
        }
        return [
            'sites' => get_all_sites(),
            'perms' => [
                'add'    => self::can_manage(),
                'edit'   => self::can_manage(),
                'delete' => self::can_manage(),
            ],
        ];
    }

    /**
     * Xóa store. Chặn nếu còn sản phẩm đang dùng.
     *
     * @return array{status:string,deleted?:int,message?:string}
     */
    public static function delete_stores(): array
    {
        if (!self::can_manage()) {
            return ['status' => 'error', 'message' => 'Stores are shared between teams; only an admin can delete them.'];
        }
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            return ['status' => 'error', 'message' => 'Missing store list.'];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        if (empty($ids)) {
            return ['status' => 'error', 'message' => 'Invalid store list.'];
        }

        $conn = db();
        $idsStr = implode(',', $ids);

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
