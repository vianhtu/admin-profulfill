<?php
class Order
{
    public static function update_item(int $orderId, int $itemIndex, callable $callback): array
    {
        $conn = db();

        // 1. CSRF: mọi handler ghi đều phải kiểm — JS gửi kèm window.csrfToken
        if (!check_csrf()) {
            return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
        }

        // 2. Validate dữ liệu cơ bản
        if ($orderId <= 0 || $itemIndex < 0) {
            return ['status' => 'error', 'message' => 'Invalid data.'];
        }

        // 3. Kiểm tra role edit + phạm vi dữ liệu trên bản ghi
        $validOrders = Orders::check_orders_ownership($conn, [$orderId]);
        if (empty($validOrders[$orderId])) {
            return ['status' => 'error', 'message' => 'Order not found or you do not have permission to edit it.'];
        }

        $order = $validOrders[$orderId];

        // 4. Giải mã JSON
        $items = json_decode($order['items'], true);
        if (!is_array($items)) {
            return ['status' => 'error', 'message' => 'Order items data is malformed.'];
        }

        // 5. Kiểm tra Index
        if (!isset($items[$itemIndex])) {
            return ['status' => 'error', 'message' => 'Item not found at the requested position.'];
        }

        // 6. Thực thi logic riêng biệt được truyền vào thông qua Callback
        // Callback sẽ thay đổi trực tiếp mảng $items (qua tham chiếu &$items) và trả về thông số SQL bổ sung
        $actionResult = $callback($items, $order);

        if (isset($actionResult['error'])) {
            return ['status' => 'error', 'message' => $actionResult['error']];
        }

        // 7. Chuẩn bị SQL update
        $updatedJson = json_encode($items, JSON_UNESCAPED_UNICODE);

        // Lấy các câu lệnh SET bổ sung và tham số bind từ callback (nếu có)
        $sqlSet = $actionResult['sql_set'] ?? '';
        $extraBinds = $actionResult['bind_params'] ?? [];

        $updateSql = "UPDATE orders SET items = ? {$sqlSet} WHERE id = ?";

        // Ghép các tham số lại theo đúng thứ tự: [ JSON, ...Các tham số SET bổ sung, OrderID ]
        $bindParams = array_merge([$updatedJson], $extraBinds, [$orderId]);

        // 8. Thực thi truy vấn
        $updateStmt = $conn->prepare($updateSql);
        if (!$updateStmt) {
            return ['status' => 'error', 'message' => 'Failed to prepare the update statement.'];
        }

        $success = $updateStmt->execute($bindParams);
        $updateStmt->close();

        if ($success) {
            $response = [
                'status' => 'success',
                'message' => $actionResult['success_message'] ?? 'Updated successfully!'
            ];

            if (isset($actionResult['response_data'])) {
                $response['data'] = $actionResult['response_data'];
            }

            return $response;
        }

        return ['status' => 'error', 'message' => 'Failed to save the changes.'];
    }

    public static function add_item_data(): array
    {
        // 1. Nhận và lọc dữ liệu đầu vào
        $itemIndex = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT) ?? -1;
        $orderId   = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT) ?? 0;
        $base_cost = filter_input(INPUT_POST, 'base_cost', FILTER_VALIDATE_FLOAT) ?? 0.0;
        $note      = isset($_POST['note']) ? trim((string)$_POST['note']) : '';

        // 2. Gọi hàm dùng chung và truyền logic riêng vào
        return self::update_item($orderId, $itemIndex, function (&$items) use ($itemIndex, $base_cost, $note) {

            // Cập nhật item
            $items[$itemIndex]['cost'] = $base_cost;
            $items[$itemIndex]['note'] = $note;

            // Tính tổng tiền
            $total_cost = 0;
            foreach ($items as $item) {
                if (!empty($item['cost'])) {
                    $total_cost += (float)$item['cost'];
                }
            }

            // Trả về cấu trúc SQL bổ sung
            return [
                'sql_set' => ', base_cost = ?',
                'bind_params' => [$total_cost]
            ];
        });
    }

    public static function add_item_tracking(): array
    {
        // 1. Nhận và lọc dữ liệu đầu vào
        $orderId   = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $itemIndex = isset($_POST['itemIndex']) ? (int)$_POST['itemIndex'] : -1;
        $services  = isset($_POST['services']) ? trim((string)$_POST['services']) : '';
        $track     = isset($_POST['track']) ? trim((string)$_POST['track']) : '';

        // 2. Gọi hàm dùng chung và truyền logic riêng vào
        return self::update_item($orderId, $itemIndex, function (&$items, $order) use ($itemIndex, $services, $track) {

            // Cập nhật item
            $items[$itemIndex]['services'] = $services;
            $items[$itemIndex]['track']    = $track;

            // Kiểm tra xem tất cả đã có mã tracking chưa
            $allShipped = true;
            foreach ($items as $item) {
                if (!isset($item['track']) || trim((string)$item['track']) === '') {
                    $allShipped = false;
                    break;
                }
            }

            // Khởi tạo kết quả trả về
            $result = [
                'bind_params' => [],
                'response_data' => [
                    'services' => $services,
                    'track' => $track,
                    'order_status' => $allShipped ? 'shipped' : $order['status']
                ],
                'success_message' => $allShipped
                    ? 'Tracking saved! The order has been marked as Shipped.'
                    : 'Tracking information saved!'
            ];

            // Thêm câu lệnh cập nhật status nếu tất cả đã gửi
            if ($allShipped) {
                $result['sql_set'] = ", status = 'shipped', fulfill_date = NOW()";
            }

            return $result;
        });
    }
}