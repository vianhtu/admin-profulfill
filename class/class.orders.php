<?php
class Orders
{
    public static function get_orders(): array {
        $allowedCols = ['ID', 'status', 'purchase_date', 'delivery_date', 'ship_date'];
        // Lấy tham số từ DataTables
        $params = getDataTableParams($allowedCols);
        if(!checkRoles('view', 'orders')){
            return [
                "draw"            => $params['draw'],
                "recordsTotal"    => 0,
                "recordsFiltered" => 0,
                "data"            => []
            ];
        }

        $conn = db();

        // Tổng số bản ghi
        $totalRecords = $conn->query("SELECT COUNT(ID) AS cnt FROM orders")->fetch_assoc()['cnt'];

        // Điều kiện lọc
        $whereClauses = [];
        if ($params['searchValue'] !== '') {
            $searchEsc = $conn->real_escape_string($params['searchValue']);
            $whereClauses[] = "(orders.host_id LIKE '%$searchEsc%' 
            OR orders.full_name LIKE '%$searchEsc%' 
            OR orders.phone LIKE '%$searchEsc%' 
            OR orders.items LIKE '%$searchEsc%')";
        }
        $where = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

        // Tổng số bản ghi sau khi lọc
        $totalFiltered = $conn->query("SELECT COUNT(ID) AS cnt FROM orders $where")->fetch_assoc()['cnt'];

        // Lấy dữ liệu
        $sql = "SELECT orders.*, accounts.site_id, accounts.name AS account_name, accounts.email AS account_email
            FROM orders
            INNER JOIN accounts ON accounts.ID = orders.account_id
            $where
            ORDER BY orders.{$params['orderColumn']} {$params['orderDir']}
            LIMIT {$params['start']}, {$params['length']}";
        $rs = $conn->query($sql);

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

        return [
            "draw"            => $params['draw'],
            "recordsTotal"    => $totalRecords,
            "recordsFiltered" => $totalFiltered,
            "data"            => $data
        ];
    }
}