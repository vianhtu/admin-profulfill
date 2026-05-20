<?php
class Orders
{
    public static function get_orders(): array {
        $allowedCols = ['ID', 'status', 'purchase_date', 'delivery_date', 'ship_date'];
        $params = getDataTableParams($allowedCols);
        $conn = db();

        $whereClauses = [];
        $bindTypes = "";
        $bindParams = [];

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

            // Phân quyền theo Team
            $team_id = $_SESSION['auth']['team'];
            $whereClauses[] = "accounts.team_id = ?";
            $bindTypes .= "i";
            $bindParams[] = $team_id;

            // Phân quyền theo Manager/User
            if (is_user()) {
                $user_id = $_SESSION['auth']['user_id'];
                $whereClauses[] = "accounts_authors.author_id = ?";
                $bindTypes .= "i";
                $bindParams[] = $user_id;
            }
        }

        // 2. Tính tổng số bản ghi MÀ USER ĐƯỢC PHÉP THẤY (Trước khi tìm kiếm)
        $whereAuth = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';
        $countSql = "SELECT COUNT(DISTINCT orders.ID) AS cnt FROM orders 
                 INNER JOIN accounts ON accounts.ID = orders.account_id
                 LEFT JOIN accounts_authors ON accounts_authors.account_id = accounts.ID 
                 $whereAuth";

        $stmt = $conn->prepare($countSql);
        if (!empty($whereClauses)) {
            $stmt->bind_param($bindTypes, ...$bindParams);
        }
        $stmt->execute();
        $totalRecords = $stmt->get_result()->fetch_assoc()['cnt'];
        $stmt->close();

        // 3. Xử lý ô tìm kiếm (Search Value)
        if ($params['searchValue'] !== '') {
            $whereClauses[] = "(orders.host_id LIKE ? 
            OR orders.full_name LIKE ? 
            OR orders.phone LIKE ? 
            OR orders.items LIKE ?)";

            $searchParam = "%" . $params['searchValue'] . "%";
            $bindTypes .= "ssss";
            $bindParams[] = $searchParam;
            $bindParams[] = $searchParam;
            $bindParams[] = $searchParam;
            $bindParams[] = $searchParam;
        }
        $whereAll = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

        // 4. Tính tổng số bản ghi SAU KHI LỌC SEARCH
        $filterSql = "SELECT COUNT(DISTINCT orders.ID) AS cnt FROM orders 
                  INNER JOIN accounts ON accounts.ID = orders.account_id
                  LEFT JOIN accounts_authors ON accounts_authors.account_id = accounts.ID 
                  $whereAll";

        $stmt = $conn->prepare($filterSql);
        if (!empty($whereClauses)) {
            $stmt->bind_param($bindTypes, ...$bindParams);
        }
        $stmt->execute();
        $totalFiltered = $stmt->get_result()->fetch_assoc()['cnt'];
        $stmt->close();

        // 5. Kiểm tra an toàn cho cấu trúc Sắp xếp (Order By) và Phân trang (Limit)
        $orderColumn = in_array($params['orderColumn'], $allowedCols) ? $params['orderColumn'] : 'ID';
        $orderDir = strtoupper($params['orderDir']) === 'DESC' ? 'DESC' : 'ASC';

        $start = filter_var($params['start'], FILTER_VALIDATE_INT) !== false ? (int)$params['start'] : 0;
        $length = filter_var($params['length'], FILTER_VALIDATE_INT) !== false ? (int)$params['length'] : 10;

        // 6. Lấy dữ liệu thực tế
        $sql = "SELECT orders.*, accounts.site_id, accounts.name AS account_name, accounts.email AS account_email
        FROM orders
        INNER JOIN accounts ON accounts.ID = orders.account_id
        LEFT JOIN accounts_authors ON accounts_authors.account_id = accounts.ID
        $whereAll
        GROUP BY orders.ID
        ORDER BY orders.{$orderColumn} {$orderDir}
        LIMIT ?, ?";

        // Thêm tham số cho LIMIT
        $bindTypes .= "ii";
        $bindParams[] = $start;
        $bindParams[] = $length;

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($bindTypes, ...$bindParams);
        $stmt->execute();
        $rs = $stmt->get_result();

        $data = [];
        while ($row = $rs->fetch_assoc()) {
            $data[] = [
                "id"               => $row['ID'],
                "host_id"          => $row['host_id'],
                "purchase_date"    => $row['purchase_date'],
                "delivery_date"    => $row['delivery_date'],
                "ship_date"        => $row['ship_date'],
                "full_name"        => $row['full_name'],
                "phone"            => $row['phone'],
                "street_address_1" => $row['street_address_1'],
                "street_address_2" => $row['street_address_2'],
                "city"             => $row['city'],
                "state"            => $row['state'],
                "zip_code"         => $row['zip_code'],
                "country"          => $row['country'],
                "total_price"      => $row['total_price'],
                "items"            => $row['items'],
                "status"           => $row['status'],
                "site_id"          => $row['site_id'],
                "account_name"     => $row['account_name'],
            ];
        }
        $stmt->close();

        return [
            "draw"            => $params['draw'],
            "recordsTotal"    => $totalRecords,
            "recordsFiltered" => $totalFiltered,
            "data"            => $data
        ];
    }
}