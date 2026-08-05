<?php
/**
 * Orders — nghiệp vụ tập hợp cho bảng `orders` (menu Orders): danh sách, thống kê,
 * cập nhật trạng thái / xóa hàng loạt. Cùng khuôn với class.products.php.
 *
 * Phạm vi dữ liệu đi qua bảng `accounts` (đơn thuộc account, account thuộc team):
 *   - admin   = mọi team;
 *   - manager = account thuộc team mình (accounts.team_id);
 *   - user    = chỉ account được gán cho mình (accounts_authors.author_id).
 */
class Orders
{
    /**
     * Cột sắp xếp được của bảng Orders: tên cột DataTables => biểu thức SQL.
     * Cột nào không có ở đây thì bên JS phải để orderable: false.
     */
    private const SORT_MAP = [
        'host_id'       => 'orders.host_id',
        'purchase_date' => 'orders.purchase_date',
        'ship_date'     => 'orders.ship_date',
        'delivery_date' => 'orders.delivery_date',
        'total_price'   => 'orders.total_price',
        'status'        => 'orders.status',
        'ID'            => 'orders.ID',
    ];

    /** Trạng thái đơn hợp lệ — dùng chung cho update + thống kê. */
    public const STATUSES = ['unshipped', 'cancel', 'shipped', 'replace', 'refund', 'delivered'];

    /**
     * Lọc danh sách order ID về đúng phạm vi dữ liệu + role của user hiện tại.
     * Dùng trước mọi thao tác ghi/xóa — không tin danh sách ID gửi lên.
     *
     * @param int[] $orderIds
     * @param string[] $action role cần có trên menu orders (edit/delete)
     * @return array<int,array> các đơn hợp lệ, key = order ID để tra O(1)
     */
    public static function check_orders_ownership(mysqli $conn, array $orderIds, array $action = ['edit']): array {
        $validIds = array_values(array_unique(array_filter(array_map('intval', $orderIds), fn($id) => $id > 0)));
        if (empty($validIds)) {
            return [];
        }

        // Trục ROLE: chỉ admin bỏ qua (checkRoles đã tự xử lý admin)
        if (!checkRoles($action, 'orders')) {
            return [];
        }

        // Trục PHẠM VI DỮ LIỆU: qua accounts (team) và accounts_authors (user)
        $sql = "SELECT o.* FROM orders o
            INNER JOIN accounts a ON o.account_id = a.ID";

        $placeholders = implode(',', array_fill(0, count($validIds), '?'));
        $whereClauses = ["o.ID IN ($placeholders)"];
        $bindParams = $validIds;

        if (!is_admin()) {
            $whereClauses[] = "a.team_id = ?";
            $bindParams[] = (int)$_SESSION['auth']['team'];

            if (is_user()) {
                $sql .= " INNER JOIN accounts_authors aa ON a.ID = aa.account_id";
                $whereClauses[] = "aa.author_id = ?";
                $bindParams[] = (int)$_SESSION['auth']['user_id'];
            }
        }

        $sql .= " WHERE " . implode(" AND ", $whereClauses);

        $validOrders = [];
        foreach ($conn->execute_query($sql, $bindParams) as $row) {
            $validOrders[$row['ID']] = $row;
        }
        return $validOrders;
    }

    /**
     * Điều kiện phân quyền dữ liệu dùng chung cho danh sách + thống kê.
     * Query gọi tới phải JOIN accounts (và accounts_authors với level user).
     */
    private static function get_base_auth_conditions(): array {
        $whereClauses = [];
        $bindParams = [];

        if (!is_admin()) {
            $whereClauses[] = "accounts.team_id = ?";
            $bindParams[] = (int)$_SESSION['auth']['team'];

            if (is_user()) {
                $whereClauses[] = "accounts_authors.author_id = ?";
                $bindParams[] = (int)$_SESSION['auth']['user_id'];
            }
        }

        return ['where' => $whereClauses, 'params' => $bindParams];
    }

    /**
     * Dữ liệu cho DataTables của trang Orders (server-side).
     *
     * @return array{draw:int,recordsTotal:int,recordsFiltered:int,data:array}
     */
    public static function get_orders(): array {
        $params = get_datatable_params(array_keys(self::SORT_MAP), 'purchase_date');
        if (!checkRoles('view', 'orders')) {
            return ['draw' => $params['draw'], 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []];
        }

        $conn = db();
        $auth = self::get_base_auth_conditions();
        $whereClauses = $auth['where'];
        $bindParams = $auth['params'];

        // Tổng số bản ghi trong phạm vi được phép thấy (trước khi lọc)
        $whereAuth = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';
        $countSql = "SELECT COUNT(DISTINCT orders.ID) AS cnt FROM orders
                 INNER JOIN accounts ON accounts.ID = orders.account_id
                 LEFT JOIN accounts_authors ON accounts_authors.account_id = accounts.ID
                 $whereAuth";
        $totalRecords = (int)$conn->execute_query($countSql, $bindParams)->fetch_assoc()['cnt'];

        // Ô tìm kiếm
        if ($params['searchValue'] !== '') {
            $whereClauses[] = "(orders.host_id LIKE ?
            OR orders.full_name LIKE ?
            OR orders.phone LIKE ?
            OR orders.all_item_titles LIKE ?
            OR orders.all_item_ids LIKE ?
            OR orders.all_item_skus LIKE ?)";
            $searchParam = "%" . $params['searchValue'] . "%";
            array_push($bindParams, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam);
        }

        // Lọc theo khoảng ngày mua — so sánh range trực tiếp trên cột để index dùng được
        $minDate = (string)($_POST['minDate'] ?? '');
        $maxDate = (string)($_POST['maxDate'] ?? '');
        if ($minDate !== '') {
            $whereClauses[] = "orders.purchase_date >= ?";
            $bindParams[] = $minDate . ' 00:00:00';
        }
        if ($maxDate !== '') {
            $whereClauses[] = "orders.purchase_date <= ?";
            $bindParams[] = $maxDate . ' 23:59:59';
        }

        // Lọc theo account (select2 ajax, single)
        $filterAccount = (int)($_POST['account'] ?? 0);
        if ($filterAccount > 0) {
            $whereClauses[] = "orders.account_id = ?";
            $bindParams[] = $filterAccount;
        }

        // Lọc theo sites (select2 multiple)
        $filterSites = $_POST['sites'] ?? [];
        if (!empty($filterSites) && is_array($filterSites)) {
            $siteIds = array_values(array_filter(array_map('intval', $filterSites), fn($v) => $v > 0));
            if (!empty($siteIds)) {
                $whereClauses[] = 'accounts.site_id IN (' . implode(',', $siteIds) . ')';
            }
        }

        $whereAll = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

        // Tổng số bản ghi sau khi lọc
        $filterSql = "SELECT COUNT(DISTINCT orders.ID) AS cnt FROM orders
                  INNER JOIN accounts ON accounts.ID = orders.account_id
                  LEFT JOIN accounts_authors ON accounts_authors.account_id = accounts.ID
                  $whereAll";
        $totalFiltered = (int)$conn->execute_query($filterSql, $bindParams)->fetch_assoc()['cnt'];

        // Lấy dữ liệu trang hiện tại — ORDER BY qua SORT_MAP + khóa phụ ID
        $sql = "SELECT orders.*, NOW() AS server_date,
            accounts.site_id, accounts.name AS account_name, accounts.email AS account_email
        FROM orders
        INNER JOIN accounts ON accounts.ID = orders.account_id
        LEFT JOIN accounts_authors ON accounts_authors.account_id = accounts.ID
        $whereAll
        GROUP BY orders.ID
        ORDER BY " . build_order_by($params, self::SORT_MAP, 'orders.ID') . "
        LIMIT ?, ?";

        $dataParams = $bindParams;
        $dataParams[] = $params['start'];
        $dataParams[] = $params['length'];
        $rs = $conn->execute_query($sql, $dataParams);

        // Quyền hàng loạt tính 1 lần: phạm vi dữ liệu đã lọc sẵn ở WHERE nên mọi dòng
        // trong trang có cùng quyền role; vẫn trả cờ theo TỪNG dòng đúng quy ước chung
        // để JS dựng nút từ cờ đó.
        $canEdit = checkRoles('edit', 'orders');
        $canDelete = checkRoles('delete', 'orders');

        $data = [];
        foreach ($rs as $row) {
            $data[] = [
                "id"            => (int)$row['ID'],
                "host_id"       => $row['host_id'],
                "purchase_date" => $row['purchase_date'],
                "delivery_date" => $row['delivery_date'],
                "ship_date"     => $row['ship_date'],
                "fulfill_date"  => $row['fulfill_date'],
                "full_name"     => $row['full_name'],
                "address"       => $row['address'],
                "phone"         => $row['phone'],
                "total_price"   => $row['total_price'],
                "base_cost"     => $row['base_cost'],
                "items"         => $row['items'],
                "status"        => $row['status'],
                "site_id"       => (int)$row['site_id'],
                "account_name"  => $row['account_name'],
                "server_date"   => $row['server_date'],
                "can_edit"      => $canEdit,
                "can_delete"    => $canDelete,
            ];
        }

        return [
            "draw"            => $params['draw'],
            "recordsTotal"    => $totalRecords,
            "recordsFiltered" => $totalFiltered,
            "data"            => $data,
        ];
    }

    /**
     * Option bộ lọc + cờ quyền chung của trang Orders.
     * Trang này KHÔNG dùng filter của Products — role Orders và Products độc lập.
     *
     * @return array{sites:array,perms:array{edit:bool,delete:bool,is_admin:bool}}
     */
    public static function get_orders_filters(): array {
        if (!checkRoles('view', 'orders')) {
            return ['status' => 'error', 'message' => 'You do not have permission to view orders.'];
        }
        return [
            'sites' => get_all_sites(),
            'perms' => [
                'edit'     => checkRoles('edit', 'orders'),
                'delete'   => checkRoles('delete', 'orders'),
                'is_admin' => is_admin(),
            ],
        ];
    }

    public static function get_orders_statistic(): array {
        // Lớp code: tự kiểm role dù fragment đã gate — trả 0 hết, không chạy query
        if (!checkRoles('view', 'orders')) {
            return array_fill_keys(self::STATUSES, 0);
        }
        $conn = db();
        $auth = self::get_base_auth_conditions();
        $whereSql = $auth['where'] ? ' WHERE ' . implode(' AND ', $auth['where']) : '';

        // JOIN vì điều kiện phân quyền (team_id, author_id) nằm ở bảng khác
        $sql = "SELECT orders.status, COUNT(DISTINCT orders.ID) AS total_count
            FROM orders
            INNER JOIN accounts ON accounts.ID = orders.account_id
            LEFT JOIN accounts_authors ON accounts_authors.account_id = accounts.ID
            $whereSql
            GROUP BY orders.status";
        $rs = $conn->execute_query($sql, $auth['params']);

        $statistics = array_fill_keys(self::STATUSES, 0);
        foreach ($rs as $row) {
            $statistics[$row['status']] = (int)$row['total_count'];
        }
        return $statistics;
    }

    /**
     * Engine lõi xử lý hàng loạt (update status / delete).
     * ID ngoài phạm vi quyền bị loại và báo lại, không chặn cả lô.
     */
    private static function process_orders(string $action, array $inputData, array $role = ['edit']): array
    {
        $conn = db();

        if (empty($inputData)) {
            return ['status' => 'error', 'message' => 'No orders to process.'];
        }

        // Update gửi map [id => status], delete gửi mảng [id1, id2]
        $isAssoc = array_keys($inputData) !== range(0, count($inputData) - 1);
        $orderIds = $isAssoc ? array_keys($inputData) : array_values($inputData);

        // Trục role + phạm vi dữ liệu — không tin danh sách ID gửi lên
        $validOrders = self::check_orders_ownership($conn, $orderIds, $role);
        if (empty($validOrders)) {
            return ['status' => 'error', 'message' => 'You do not have permission to modify these orders, or they do not exist.'];
        }

        $successCount = 0;
        $errors = [];
        $processedOrders = [];

        $conn->begin_transaction();
        try {
            $sql = match ($action) {
                'update' => "UPDATE orders SET status = ? WHERE id = ?",
                'delete' => "DELETE FROM orders WHERE id = ?",
                default => throw new Exception("Unsupported action '{$action}'.")
            };
            $stmt = $conn->prepare($sql);

            foreach ($orderIds as $orderId) {
                $orderId = (int)$orderId;
                if (!isset($validOrders[$orderId])) {
                    $errors[] = "Order #{$orderId}: access denied.";
                    continue;
                }

                if ($action === 'update') {
                    $status = trim((string)($inputData[$orderId] ?? ''));
                    if (!in_array($status, self::STATUSES, true)) {
                        $errors[] = "Order #{$orderId}: invalid status '{$status}'.";
                        continue;
                    }
                    $stmt->execute([$status, $orderId]);
                    $processedOrders[$orderId] = $status;
                } elseif ($action === 'delete') {
                    $stmt->execute([$orderId]);
                    $processedOrders[$orderId] = 'deleted';
                }

                $successCount++;
            }

            $stmt->close();
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            return ['status' => 'error', 'message' => 'Processing failed: ' . $e->getMessage()];
        }

        if ($successCount === 0) {
            return ['status' => 'error', 'message' => 'No orders were processed.', 'errors' => $errors];
        }

        $verb = $action === 'delete' ? 'Deleted' : 'Updated';
        return [
            'status' => 'success',
            'message' => "{$verb} {$successCount} order(s).",
            'details' => [
                'success_count' => $successCount,
                'failed_count' => count($errors),
                'errors' => $errors,
            ],
            'orders' => $processedOrders,
        ];
    }

    /**
     * API: cập nhật trạng thái (1 hoặc nhiều đơn). POST orders = [id => status].
     */
    public static function update_orders_status(): array
    {
        if (!check_csrf()) {
            return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
        }
        $ordersData = $_POST['orders'] ?? [];
        if (!is_array($ordersData) || empty($ordersData)) {
            return ['status' => 'error', 'message' => 'Missing order list.'];
        }
        return self::process_orders('update', $ordersData);
    }

    /**
     * API: xóa đơn hàng (1 hoặc nhiều). POST order_ids = [id1, id2].
     */
    public static function delete_orders(): array
    {
        if (!check_csrf()) {
            return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
        }
        $orderIds = $_POST['order_ids'] ?? [];
        if (!is_array($orderIds) || empty($orderIds)) {
            return ['status' => 'error', 'message' => 'Missing order list.'];
        }
        return self::process_orders('delete', $orderIds, ['delete']);
    }
}
