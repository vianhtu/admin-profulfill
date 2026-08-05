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
    /**
     * Cột sắp xếp được của bảng Sites (xem build_order_by()). Các cột đếm sắp xếp theo
     * số thật, Prompt/Fields theo biểu thức tương ứng với giá trị đang hiển thị.
     */
    private const SORT_MAP = [
        'name'           => 's.name',
        'slug'           => 's.slug',
        'products_count' => 'products_count',
        'accounts_count' => 'accounts_count',
        'stores_count'   => 'stores_count',
        'has_prompt'     => "(TRIM(COALESCE(s.system_prompt, '')) <> '' OR TRIM(COALESCE(s.developer_prompt, '')) <> '')",
        'fields_count'   => "COALESCE(JSON_LENGTH(NULLIF(s.custom_fields, '')), 0)",
        'ID'             => 's.ID',
    ];

    public static function get_sites(): array
    {
        $params = get_datatable_params(array_keys(self::SORT_MAP), 'name');
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

        $sql = "SELECT s.ID, s.name, s.slug, s.logo, s.system_prompt, s.developer_prompt, s.custom_fields, s.created_by,
                       (SELECT COUNT(*) FROM posts p WHERE p.site_id = s.ID)     AS products_count,
                       (SELECT COUNT(*) FROM accounts a WHERE a.site_id = s.ID)  AS accounts_count,
                       (SELECT COUNT(*) FROM store st WHERE st.site_id = s.ID)   AS stores_count
                FROM site s
                $where
                ORDER BY " . build_order_by($params, self::SORT_MAP, 's.ID') . "
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
                // Quyền tính theo từng dòng: sửa được site do chính mình thêm, xóa thì chỉ admin
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
     * Thêm site mới: ai có role add cũng làm được. Site chưa tồn tại thì thêm vào là
     * bổ sung dữ liệu, không đụng tới cái đang chạy của team khác.
     */
    public static function can_add(): bool
    {
        return is_admin() || checkRoles('add', 'sites');
    }

    /**
     * Xóa site: CHỈ ADMIN. Site dùng chung toàn hệ thống, không có cột team —
     * xóa 1 site là ảnh hưởng sản phẩm, account, store của mọi team.
     */
    public static function can_manage(): bool
    {
        return is_admin();
    }

    /**
     * Sửa 1 site cụ thể: admin sửa mọi site; người khác chỉ sửa được SITE DO CHÍNH MÌNH
     * THÊM (site.created_by), và vẫn phải có role edit. Site cũ (created_by = 0) hoặc do
     * người khác tạo thì chỉ admin đụng vào được.
     */
    public static function can_edit_row(int $createdBy): bool
    {
        if (is_admin()) {
            return true;
        }
        $uid = (int)($_SESSION['auth']['user_id'] ?? 0);
        return $createdBy > 0 && $uid > 0 && $createdBy === $uid && checkRoles('edit', 'sites');
    }

    /**
     * Cờ quyền cấp trang (nút Add / Delete Selected). Site không thuộc team nên không có
     * filter team. Quyền sửa/xóa từng dòng nằm ở can_edit/can_delete trong get_sites().
     *
     * @return array{perms:array{add:bool,delete:bool,is_admin:bool}}
     */
    public static function get_sites_filters(): array
    {
        if (!checkRoles('view', 'sites')) {
            return ['status' => 'error', 'message' => 'You do not have permission to view sites.'];
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
     * Xóa site. Chặn nếu còn sản phẩm / tài khoản / store đang tham chiếu.
     *
     * @return array{status:string,deleted?:int,message?:string}
     */
    public static function delete_sites(): array
    {
        if (!self::can_manage()) {
            return ['status' => 'error',
                'message' => 'Sites are shared by the whole system; only an admin can delete them.'];
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
