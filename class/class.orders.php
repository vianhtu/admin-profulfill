<?php
class Orders
{
    /**
     * Kiểm tra quyền sở hữu và phân quyền chỉnh sửa đối với danh sách đơn hàng
     * * @param mysqli $conn Đối tượng kết nối MySQLi
     * @param int[] $orderIds Mảng chứa danh sách ID các đơn hàng cần kiểm tra
     * @param array $action Quyền cần kiểm tra (mặc định là ['edit'])
     * @return array Trả về mảng danh sách các đơn hàng hợp lệ (key là order_id để dễ truy xuất)
     */
    public static function check_orders_ownership(mysqli $conn, array $orderIds, array $action = ['edit']): array {
        // 1. Lọc và làm sạch mảng ID (ép kiểu int và chỉ lấy số dương)
        $validIds = array_filter(array_map('intval', $orderIds), fn($id) => $id > 0);

        // Nếu mảng rỗng sau khi lọc, trả về mảng rỗng ngay lập tức để tiết kiệm tài nguyên
        if (empty($validIds)) {
            return [];
        }

        // 2. Kiểm tra role cơ bản trên module orders
        if (!is_admin()) {
            if (!checkRoles($action, 'orders')) {
                return [];
            }
        }

        // 3. Xây dựng câu lệnh SQL phân quyền dựa trên cấu trúc table
        $sql = "SELECT o.* FROM orders o
            INNER JOIN accounts a ON o.account_id = a.ID";

        // Tạo các dấu '?' tương ứng với số lượng ID hợp lệ cho mệnh đề IN
        $placeholders = implode(',', array_fill(0, count($validIds), '?'));
        $whereClauses = ["o.id IN ($placeholders)"];

        // Khởi tạo mảng bind tham số bằng chính danh sách ID đã lọc
        // Hàm array_values đảm bảo key của mảng là tuần tự (0, 1, 2...)
        $bindParams = array_values($validIds);

        // Áp dụng phân quyền theo cấp bậc nếu không phải Admin
        if (!is_admin()) {
            // Phân quyền cấp Team
            $whereClauses[] = "a.team_id = ?";
            $bindParams[] = (int)$_SESSION['auth']['team'];

            // Phân quyền cấp User (Nhân viên) dựa trên bảng accounts_authors
            if (is_user()) {
                $sql .= " INNER JOIN accounts_authors aa ON a.ID = aa.account_id";
                $whereClauses[] = "aa.author_id = ?";
                $bindParams[] = (int)$_SESSION['auth']['user_id'];
            }
        }

        // Nối các điều kiện WHERE lại với nhau
        $sql .= " WHERE " . implode(" AND ", $whereClauses);

        // 4. Thực thi truy vấn an toàn bằng Prepared Statement
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            // Log lỗi hoặc xử lý exception tại đây nếu cần
            return [];
        }

        // Tận dụng tính năng của PHP 8+ truyền thẳng mảng vào execute()
        $stmt->execute($bindParams);
        $result = $stmt->get_result();

        $validOrders = [];
        while ($row = $result->fetch_assoc()) {
            // Sử dụng chính ID của đơn hàng làm Key của mảng
            // Việc này giúp bạn dễ dàng tìm kiếm o(1) khi xử lý logic ở bên ngoài
            $validOrders[$row['id']] = $row;
        }

        $stmt->close();

        return $validOrders;
    }
    public static function get_orders(): array {
        $allowedCols = ['ID', 'status', 'purchase_date', 'delivery_date', 'ship_date', 'total_price'];
        $params = getDataTableParams($allowedCols);
        $conn = db();

        $whereClauses = [];
        $bindParams = []; // Chỉ cần một mảng phẳng chứa giá trị, không cần chuỗi "ssii" nữa!

        // 1. Kiểm tra phân quyền (Role)
        if (!is_admin()) {
            if (!checkRoles('view', 'orders')) {
                return [
                    "draw" => $params['draw'],
                    "recordsTotal" => 0,
                    "recordsFiltered" => 0,
                    "data" => []
                ];
            }

            // Phân quyền theo Team (Tự động hiểu INT nếu bạn truyền int)
            $whereClauses[] = "accounts.team_id = ?";
            $bindParams[] = (int)$_SESSION['auth']['team'];

            // Phân quyền theo Manager/User
            if (is_user()) {
                $whereClauses[] = "accounts_authors.author_id = ?";
                $bindParams[] = (int)$_SESSION['auth']['user_id'];
            }
        }

        // 2. Tính tổng số bản ghi MÀ USER ĐƯỢC PHÉP THẤY (Trước khi tìm kiếm)
        $whereAuth = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';
        $countSql = "SELECT COUNT(DISTINCT orders.ID) AS cnt FROM orders 
                 INNER JOIN accounts ON accounts.ID = orders.account_id
                 LEFT JOIN accounts_authors ON accounts_authors.account_id = accounts.ID 
                 $whereAuth";

        // PHP 8.2+: Thực thi trực tiếp và lấy kết quả chỉ với 1 dòng
        $totalRecords = $conn->execute_query($countSql, $bindParams)->fetch_assoc()['cnt'];

        // 3. Xử lý ô tìm kiếm (Search Value)
        if ($params['searchValue'] !== '') {
            $whereClauses[] = "(orders.host_id LIKE ? 
            OR orders.full_name LIKE ? 
            OR orders.phone LIKE ? 
            OR orders.all_item_titles LIKE ?
            OR orders.all_item_ids LIKE ?
            OR orders.all_item_skus LIKE ?)";

            $searchParam = "%" . $params['searchValue'] . "%";
            // Thêm 4 tham số tìm kiếm vào mảng
            array_push(
                $bindParams,
                $searchParam,
                $searchParam,
                $searchParam,
                $searchParam,
                $searchParam,
                $searchParam
            );
        }
        $whereAll = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

        // 4. Tính tổng số bản ghi SAU KHI LỌC SEARCH
        $filterSql = "SELECT COUNT(DISTINCT orders.ID) AS cnt FROM orders 
                  INNER JOIN accounts ON accounts.ID = orders.account_id
                  LEFT JOIN accounts_authors ON accounts_authors.account_id = accounts.ID 
                  $whereAll";

        $totalFiltered = $conn->execute_query($filterSql, $bindParams)->fetch_assoc()['cnt'];

        // 5. Kiểm tra an toàn cho cấu trúc Sắp xếp và Phân trang (Giữ nguyên Whitelist)
        $orderColumn = in_array($params['orderColumn'], $allowedCols) ? $params['orderColumn'] : 'ID';
        $orderDir = strtoupper($params['orderDir']) === 'DESC' ? 'DESC' : 'ASC';

        $start = filter_var($params['start'], FILTER_VALIDATE_INT) !== false ? (int)$params['start'] : 0;
        $length = filter_var($params['length'], FILTER_VALIDATE_INT) !== false ? (int)$params['length'] : 10;

        // 6. Lấy dữ liệu thực tế
        $sql = "SELECT orders.*, NOW() AS server_date,
            accounts.site_id, accounts.name AS account_name, accounts.email AS account_email
        FROM orders
        INNER JOIN accounts ON accounts.ID = orders.account_id
        LEFT JOIN accounts_authors ON accounts_authors.account_id = accounts.ID
        $whereAll
        GROUP BY orders.ID
        ORDER BY orders.{$orderColumn} {$orderDir}
        LIMIT ?, ?";

        // Tạo mảng riêng cho câu lệnh lấy data vì có thêm 2 tham số LIMIT ở cuối
        $dataParams = $bindParams;
        $dataParams[] = $start;
        $dataParams[] = $length;

        $rs = $conn->execute_query($sql, $dataParams);

        $data = [];
        // PHP 8.0+: Bạn có thể dùng foreach trực tiếp trên mysqli_result rất mượt mà
        foreach ($rs as $row) {
            $data[] = [
                "id"               => $row['ID'],
                "host_id"          => $row['host_id'],
                "purchase_date"    => $row['purchase_date'],
                "delivery_date"    => $row['delivery_date'],
                "ship_date"        => $row['ship_date'],
                "fulfill_date"     => $row['fulfill_date'],
                "full_name"        => $row['full_name'],
                "address"          => $row['address'],
                "phone"            => $row['phone'],
                "total_price"      => $row['total_price'],
                "base_cost"        => $row['base_cost'],
                "items"            => $row['items'],
                "status"           => $row['status'],
                "site_id"          => $row['site_id'],
                "account_name"     => $row['account_name'],
                "server_date"      => $row['server_date'],
            ];
        }

        return [
            "draw"            => $params['draw'],
            "recordsTotal"    => $totalRecords,
            "recordsFiltered" => $totalFiltered,
            "data"            => $data
        ];
    }

    public static function update_orders_status(): array
    {
        $conn = db();

        // 1. Nhận dữ liệu mảng orders từ POST (Cấu trúc: [order_id => status])
        $ordersData = $_POST['orders'] ?? [];

        if (!is_array($ordersData) || empty($ordersData)) {
            return ['status' => 'error', 'message' => 'Không tìm thấy dữ liệu đơn hàng cần cập nhật.'];
        }

        // 2. Lấy danh sách ID để kiểm tra quyền sở hữu hàng loạt (Bulk Check)
        $orderIds = array_keys($ordersData);
        $validOrders = self::check_orders_ownership($conn, $orderIds);

        // Nếu không có đơn hàng nào hợp lệ/có quyền chỉnh sửa
        if (empty($validOrders)) {
            return ['status' => 'error', 'message' => 'Bạn không có quyền chỉnh sửa các đơn hàng này hoặc đơn hàng không tồn tại.'];
        }

        // 3. Khởi tạo mảng lưu các thông tin cập nhật hợp lệ để build SQL
        // Định nghĩa các trạng thái hợp lệ được phép cập nhật để chống lừa đảo dữ liệu (Tampering)
        $allowedStatuses = ['unshipped', 'cancel', 'refund', 'shipped', 'replace', 'delivered'];
        $successCount = 0;
        $errors = [];

        // Sử dụng Transaction để đảm bảo tính toàn vẹn dữ liệu nếu cập nhật nhiều dòng cùng lúc
        $conn->begin_transaction();
        try {
            // Chuẩn bị sẵn khung câu lệnh SQL bằng Prepared Statement
            $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
            if (!$stmt) {
                throw new Exception("Lỗi khởi tạo câu lệnh cập nhật.");
            }

            // 4. Lặp qua danh sách dữ liệu gửi lên, chỉ xử lý những ID đã được chứng thực hợp lệ
            foreach ($ordersData as $orderId => $status) {
                $orderId = (int)$orderId;
                $status = trim((string)$status);

                // Kiểm tra: ID phải có quyền sửa VÀ trạng thái phải nằm trong danh mục cho phép
                if (!isset($validOrders[$orderId])) {
                    $errors[] = "Đơn hàng #{$orderId}: Không có quyền truy cập.";
                    continue;
                }

                if (!in_array($status, $allowedStatuses, true)) {
                    $errors[] = "Đơn hàng #{$orderId}: Trạng thái '{$status}' không hợp lệ.";
                    continue;
                }

                // Thực thi cập nhật bằng cách truyền tham số trực tiếp (PHP 8.4)
                $stmt->execute([$status, $orderId]);
                $successCount++;
            }

            $stmt->close();
            $conn->commit(); // Lưu tất cả thay đổi vào DB

        } catch (Exception $e) {
            $conn->rollback(); // Hoàn tác nếu có bất kỳ lỗi hệ thống nào xảy ra
            return ['status' => 'error', 'message' => 'Hệ thống gặp sự cố: ' . $e->getMessage()];
        }

        // 5. Trả về kết quả phản hồi dựa trên số lượng xử lý thành công
        if ($successCount === 0) {
            return [
                'status' => 'error',
                'message' => 'Không có đơn hàng nào được cập nhật thành công.',
                'errors' => $errors
            ];
        }

        return [
            'status' => 'success',
            'message' => "Đã cập nhật thành công {$successCount} đơn hàng.",
            'details' => [
                'success_count' => $successCount,
                'failed_count' => count($errors),
                'errors' => $errors
            ]
        ];
    }

    public static function delete_orders()
    {

    }
}