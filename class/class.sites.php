<?php
/**
 * Sites — nghiệp vụ tập hợp cho bảng `site` (menu Sites): danh sách, bộ lọc, xóa.
 * Cùng khuôn với class.categories.php / class.products.php.
 *
 * LƯU Ý phạm vi dữ liệu: `site` là dữ liệu DÙNG CHUNG toàn hệ thống (etsy.com,
 * amazon.com...), không có cột team_id và được posts/accounts/store của mọi team
 * tham chiếu. Vì vậy chỉ áp trục ROLE, không scope theo team — nếu chia theo team
 * thì các bản ghi đang trỏ tới site sẽ mồ côi.
 */
class Sites
{
    /**
     * Dữ liệu cho DataTables của trang Sites.
     *
     * @return array{draw:int,recordsTotal:int,recordsFiltered:int,data:array}
     */
    public static function get_sites(): array
    {
        $params = get_datatable_params(['ID', 'name', 'slug']);
        if (!checkRoles('view', 'sites')) {
            return ['draw' => $params['draw'], 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []];
        }

        $conn = db();
        $where = '';
        if ($params['searchValue'] !== '') {
            $esc = $conn->real_escape_string($params['searchValue']);
            $where = " WHERE (s.name LIKE '%$esc%' OR s.slug LIKE '%$esc%')";
        }

        $totalRecords  = (int)$conn->query('SELECT COUNT(*) FROM site')->fetch_row()[0];
        $totalFiltered = (int)$conn->query("SELECT COUNT(*) FROM site s $where")->fetch_row()[0];

        $sql = "SELECT s.ID, s.name, s.slug, s.logo, s.system_prompt, s.developer_prompt, s.custom_fields,
                       (SELECT COUNT(*) FROM posts p WHERE p.site_id = s.ID)     AS products_count,
                       (SELECT COUNT(*) FROM accounts a WHERE a.site_id = s.ID)  AS accounts_count,
                       (SELECT COUNT(*) FROM store st WHERE st.site_id = s.ID)   AS stores_count
                FROM site s
                $where
                ORDER BY s.{$params['orderColumn']} {$params['orderDir']}
                LIMIT {$params['start']}, {$params['length']}";
        $rs = $conn->query($sql);

        $data = [];
        while ($row = $rs->fetch_assoc()) {
            $fields = json_decode($row['custom_fields'] ?? '', true);
            $data[] = [
                'id'             => (int)$row['ID'],
                'name'           => $row['name'],
                'slug'           => $row['slug'],
                'logo'           => (string)($row['logo'] ?? ''),
                'products_count' => (int)$row['products_count'],
                'accounts_count' => (int)$row['accounts_count'],
                'stores_count'   => (int)$row['stores_count'],
                'fields_count'   => is_array($fields) ? count($fields) : 0,
                'has_prompt'     => trim((string)($row['system_prompt'] ?? '')) !== ''
                                    || trim((string)($row['developer_prompt'] ?? '')) !== '',
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
     * Cờ quyền để frontend dựng nút. Site không thuộc team nên không có filter team.
     *
     * @return array{perms:array{add:bool,edit:bool,delete:bool}}
     */
    public static function get_sites_filters(): array
    {
        if (!checkRoles('view', 'sites')) {
            return ['status' => 'error', 'message' => 'You do not have permission to view sites.'];
        }
        return [
            'perms' => [
                'add'    => checkRoles('add', 'sites'),
                'edit'   => checkRoles('edit', 'sites'),
                'delete' => checkRoles('delete', 'sites'),
            ],
        ];
    }

    /**
     * Xóa site. Chặn nếu còn sản phẩm / tài khoản / store đang tham chiếu.
     *
     * @return array{status:string,deleted?:int,message?:string}
     */
    public static function delete_sites(): array
    {
        if (!checkRoles('delete', 'sites')) {
            return ['status' => 'error', 'message' => 'You do not have permission to delete sites.'];
        }
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            return ['status' => 'error', 'message' => 'Missing site list.'];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        if (empty($ids)) {
            return ['status' => 'error', 'message' => 'Invalid site list.'];
        }

        $conn = db();
        $idsStr = implode(',', $ids);

        // Site đang được dùng thì không cho xóa (posts/accounts/store trỏ tới site_id)
        $inUse = $conn->query("SELECT s.name,
                (SELECT COUNT(*) FROM posts p WHERE p.site_id = s.ID)    AS p,
                (SELECT COUNT(*) FROM accounts a WHERE a.site_id = s.ID) AS a,
                (SELECT COUNT(*) FROM store st WHERE st.site_id = s.ID)  AS st
            FROM site s WHERE s.ID IN ($idsStr)
            HAVING p > 0 OR a > 0 OR st > 0 LIMIT 1")->fetch_assoc();
        if ($inUse) {
            return ['status' => 'error', 'message' => sprintf(
                'Site "%s" is still used by %s products, %s accounts and %s stores.',
                $inUse['name'], number_format((int)$inUse['p']),
                number_format((int)$inUse['a']), number_format((int)$inUse['st'])
            )];
        }

        if (!$conn->query("DELETE FROM site WHERE ID IN ($idsStr)")) {
            return ['status' => 'error', 'message' => 'Delete failed: ' . $conn->error];
        }
        return ['status' => 'success', 'deleted' => $conn->affected_rows];
    }
}
