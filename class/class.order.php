<?php
class Order
{
    /**
     * Kiểm tra quyền sở hữu và phân quyền chỉnh sửa đối với một đơn hàng cụ thể
     * * @param mysqli $conn Đối tượng kết nối MySQLi
     * @param int $orderId ID của đơn hàng cần kiểm tra
     * @param array $action Quyền cần kiểm tra (mặc định là 'edit')
     * @return array|null Trả về mảng dữ liệu đơn hàng nếu hợp lệ, ngược lại trả về null
     */
    public static function check_order_ownership(mysqli $conn, int $orderId, array $action = ['edit']): ?array {
        // 1. Kiểm tra role cơ bản trên module orders
        if (!is_admin()) {
            if (!checkRoles($action, 'orders')) {
                return null;
            }
        }

        // 2. Xây dựng câu lệnh SQL phân quyền dựa trên cấu trúc table
        $sql = "SELECT o.* FROM orders o
            INNER JOIN accounts a ON o.account_id = a.ID";

        $whereClauses = ["o.id = ?"];
        $bindParams = [$orderId];

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

        $sql .= " WHERE " . implode(" AND ", $whereClauses) . " LIMIT 1";

        // 3. Thực thi truy vấn an toàn bằng Prepared Statement
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->execute($bindParams);
        $result = $stmt->get_result();
        $order = $result->fetch_assoc();
        $stmt->close();

        return $order ? $order : null;
    }
    public static function add_tracking(): array {
        $conn = db();

        // 1. Nhận và lọc dữ liệu đầu vào từ AJAX POST
        $orderId  = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $itemIndex = isset($_POST['itemIndex']) ? (int)$_POST['itemIndex'] : -1;
        $services = isset($_POST['services']) ? trim((string)$_POST['services']) : '';
        $track    = isset($_POST['track']) ? trim((string)$_POST['track']) : '';

        if ($orderId <= 0 || $itemIndex < 0) {
            return ['status' => 'error', 'message' => 'Dữ liệu không hợp lệ.'];
        }

        // 2. Gọi function dùng chung để kiểm tra quyền truy cập bản ghi
        $order = self::check_order_ownership($conn, $orderId);

        if (!$order) {
            return ['status' => 'error', 'message' => 'Đơn hàng không tồn tại hoặc bạn không có quyền chỉnh sửa.'];
        }

        // 3. Giải mã chuỗi JSON items
        $items = json_decode($order['items'], true);
        if (!is_array($items)) {
            return ['status' => 'error', 'message' => 'Dữ liệu danh sách sản phẩm bị lỗi định dạng JSON.'];
        }

        // 4. KIỂM TRA TRỰC TIẾP QUA INDEX
        if (!isset($items[$itemIndex])) {
            return ['status' => 'error', 'message' => 'Không tìm thấy sản phẩm tại vị trí yêu cầu.'];
        }

        // Tiến hành gán thẳng dữ liệu mới vào vị trí index đó
        $items[$itemIndex]['services'] = $services;
        $items[$itemIndex]['track'] = $track;

        // 5. Kiểm tra xem TẤT CẢ các sản phẩm trong đơn đã có mã tracking chưa
        $allShipped = true;
        foreach ($items as $item) {
            // Nếu có bất kỳ một item nào chưa có trường 'track' hoặc trường 'track' bị rỗng/khoảng trắng
            if (!isset($item['track']) || trim((string)$item['track']) === '') {
                $allShipped = false;
                break; // Chỉ cần 1 sản phẩm thiếu là dừng kiểm tra
            }
        }

        // 6. Chuẩn bị câu lệnh SQL cập nhật dữ liệu linh hoạt
        $updatedJson = json_encode($items, JSON_UNESCAPED_UNICODE);

        if ($allShipped) {
            // Nếu tất cả đã có tracking, cập nhật đồng thời cả JSON items và cột status thành 'shipped'
            $updateSql = "UPDATE orders SET items = ?, status = 'shipped' WHERE id = ?";
            $bindParams = [$updatedJson, $orderId];
        } else {
            // Nếu vẫn còn sản phẩm thiếu tracking, chỉ cập nhật trường items
            $updateSql = "UPDATE orders SET items = ? WHERE id = ?";
            $bindParams = [$updatedJson, $orderId];
        }

        // 7. Thực thi truy vấn vào MySQL
        $updateStmt = $conn->prepare($updateSql);
        if (!$updateStmt) {
            return ['status' => 'error', 'message' => 'Lỗi chuẩn bị cập nhật dữ liệu.'];
        }

        $success = $updateStmt->execute($bindParams);
        $updateStmt->close();

        if ($success) {
            return [
                'status' => 'success',
                'message' => $allShipped
                    ? 'Cập nhật thành công! Đơn hàng đã chuyển sang trạng thái Shipped.'
                    : 'Cập nhật thông tin tracking thành công!',
                'data' => [
                    'services' => $services,
                    'track' => $track,
                    'order_status' => $allShipped ? 'shipped' : $order['status']
                ]
            ];
        }

        return ['status' => 'error', 'message' => 'Không thể lưu dữ liệu tracking mới.'];
    }
}