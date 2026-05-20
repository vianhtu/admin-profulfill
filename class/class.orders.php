<?php
class Orders
{
    public static function get_orders(): array {
        $allowedCols = ['ID', 'status', 'purchase_date', 'delivery_date', 'ship_date'];
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
            OR orders.all_item_ids LIKE ?)";

            $searchParam = "%" . $params['searchValue'] . "%";
            // Thêm 4 tham số tìm kiếm vào mảng
            array_push(
                $bindParams,
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
        $sql = "SELECT orders.*,
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
                "full_name"        => $row['full_name'],
                "address"          => $row['address'],
                "phone"            => $row['phone'],
                "total_price"      => $row['total_price'],
                "items"            => $row['items'],
                "status"           => $row['status'],
                "site_id"          => $row['site_id'],
                "account_name"     => $row['account_name']
            ];
        }

        return [
            "draw"            => $params['draw'],
            "recordsTotal"    => $totalRecords,
            "recordsFiltered" => $totalFiltered,
            "data"            => $data
        ];
    }
}