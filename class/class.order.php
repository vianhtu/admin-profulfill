<?php
class Order
{
    public static function update_item(int $orderId, int $itemIndex, callable $callback): array
    {
        $conn = db();

        // 1. Validate dữ liệu cơ bản
        if ($orderId <= 0 || $itemIndex < 0) {
            return ['status' => 'error', 'message' => 'Dữ liệu không hợp lệ.'];
        }

        // 2. Kiểm tra quyền truy cập bản ghi
        $validOrders = Orders::check_orders_ownership($conn, [$orderId]);
        if (empty($validOrders[$orderId])) {
            return ['status' => 'error', 'message' => 'Đơn hàng không tồn tại hoặc bạn không có quyền chỉnh sửa.'];
        }

        $order = $validOrders[$orderId];

        // 3. Giải mã JSON
        $items = json_decode($order['items'], true);
        if (!is_array($items)) {
            return ['status' => 'error', 'message' => 'Dữ liệu danh sách sản phẩm bị lỗi định dạng JSON.'];
        }

        // 4. Kiểm tra Index
        if (!isset($items[$itemIndex])) {
            return ['status' => 'error', 'message' => 'Không tìm thấy sản phẩm tại vị trí yêu cầu.'];
        }

        // 5. Thực thi logic riêng biệt được truyền vào thông qua Callback
        // Callback sẽ thay đổi trực tiếp mảng $items (qua tham chiếu &$items) và trả về thông số SQL bổ sung
        $actionResult = $callback($items, $order);

        if (isset($actionResult['error'])) {
            return ['status' => 'error', 'message' => $actionResult['error']];
        }

        // 6. Chuẩn bị SQL update
        $updatedJson = json_encode($items, JSON_UNESCAPED_UNICODE);

        // Lấy các câu lệnh SET bổ sung và tham số bind từ callback (nếu có)
        $sqlSet = $actionResult['sql_set'] ?? '';
        $extraBinds = $actionResult['bind_params'] ?? [];

        $updateSql = "UPDATE orders SET items = ? {$sqlSet} WHERE id = ?";

        // Ghép các tham số lại theo đúng thứ tự: [ JSON, ...Các tham số SET bổ sung, OrderID ]
        $bindParams = array_merge([$updatedJson], $extraBinds, [$orderId]);

        // 7. Thực thi truy vấn
        $updateStmt = $conn->prepare($updateSql);
        if (!$updateStmt) {
            return ['status' => 'error', 'message' => 'Lỗi chuẩn bị cập nhật dữ liệu.'];
        }

        $success = $updateStmt->execute($bindParams);
        $updateStmt->close();

        if ($success) {
            $response = [
                'status' => 'success',
                'message' => $actionResult['success_message'] ?? 'Cập nhật thành công!'
            ];

            if (isset($actionResult['response_data'])) {
                $response['data'] = $actionResult['response_data'];
            }

            return $response;
        }

        return ['status' => 'error', 'message' => 'Không thể lưu dữ liệu mới.'];
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
                    ? 'Cập nhật thành công! Đơn hàng đã chuyển sang trạng thái Shipped.'
                    : 'Cập nhật thông tin tracking thành công!'
            ];

            // Thêm câu lệnh cập nhật status nếu tất cả đã gửi
            if ($allShipped) {
                $result['sql_set'] = ", status = 'shipped', fulfill_date = NOW()";
            }

            return $result;
        });
    }
}