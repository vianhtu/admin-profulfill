<?php

class Extensions
{
    public static function get_account_by_id(array $fields = ['ID']): array
    {
        $conn = db();
        $res = self::check_condition($conn);
        if (!$res['success']) {
            return $res;
        }

        ['id' => $id, 'site' => $site, 'team_id' => $team_id] = $res;

        // Chỉ cho phép tên cột hợp lệ (chữ/số/gạch dưới) để tránh SQL injection
        // nếu sau này có nơi gọi hàm này với $fields lấy từ input người dùng.
        $safe_fields = array_values(array_filter(
            $fields,
            fn($f) => is_string($f) && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $f)
        ));
        if (empty($safe_fields)) {
            $safe_fields = ['ID'];
        }
        $select_clause = implode(', ', array_map(fn($f) => "a.$f", $safe_fields));

        $sql = "SELECT $select_clause
            FROM accounts a
            INNER JOIN site s ON a.site_id = s.ID
            WHERE (a.user_id = ? OR a.email = ?) AND a.team_id = ? AND s.slug = ?
            LIMIT 1";

        try {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ssis', $id, $id, $team_id, $site);
            $stmt->execute();
            $account = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$account) {
                return ['success' => false, 'message' => 'Account not found'];
            }
            return ['success' => true, 'data' => $account];
        } catch (\mysqli_sql_exception $e) {
            return self::db_error(__FUNCTION__, $e);
        }
    }

    public static function get_account_2fa(): array
    {
        $account = self::get_account_by_id(['2fa']);
        if (!$account['success']) {
            return $account;
        }

        $secret = $account['data']['2fa'] ?? '';
        if (empty($secret)) {
            return ['success' => false, 'message' => 'Secret key is empty'];
        }

        $secret = decrypt($secret);
        if ($secret === false) {
            return ['success' => false, 'message' => 'Không thể giải mã secret key'];
        }

        return ['success' => true, 'code' => self::getTOTPCode($secret)];
    }

    public static function get_account_orders(): array
    {
        $account = self::get_account_by_id();
        if (!$account['success']) {
            return $account;
        }

        try {
            $result = db()->execute_query(
                "SELECT ID, host_id, status, items FROM orders WHERE account_id = ? AND status IN ('unshipped', 'shipped')",
                [$account['data']['ID']]
            );
            return ['success' => true, 'data' => $result->fetch_all(MYSQLI_ASSOC)];
        } catch (\mysqli_sql_exception $e) {
            return self::db_error(__FUNCTION__, $e);
        }
    }

    /**
     * Sinh mã OTP 6 số (TOTP/HMAC-SHA1, chu kỳ 30s, secret Base32) — chuẩn Google Authenticator.
     */
    private static function getTOTPCode(string $secret): string
    {
        $secret = str_replace(' ', '', strtoupper($secret));
        $base32chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

        $bits = '';
        foreach (str_split($secret) as $char) {
            $pos = strpos($base32chars, $char);
            if ($pos !== false) {
                $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
            }
        }

        $binary_secret = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $binary_secret .= chr(bindec($byte));
            }
        }

        $time_step = (int) floor(time() / 30);
        $time_binary = pack('N*', 0) . pack('N*', $time_step);
        $hash = hash_hmac('sha1', $time_binary, $binary_secret, true);

        // Dynamic truncation (RFC 4226) để lấy 4 byte từ vị trí do byte cuối chỉ định.
        $offset = ord($hash[19]) & 0xf;
        $otp = (ord($hash[$offset]) & 0x7f) << 24
            | (ord($hash[$offset + 1]) & 0xff) << 16
            | (ord($hash[$offset + 2]) & 0xff) << 8
            | (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string) ($otp % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function get_account_cookies(): array
    {
        $account = self::get_account_by_id(['cookies']);
        if (!$account['success']) {
            return $account;
        }

        $cookies = $account['data']['cookies'] ?? '';
        if (empty($cookies)) {
            return ['success' => false, 'message' => 'Cookies is empty'];
        }

        $cookies = decrypt($cookies);
        if ($cookies === false) {
            return ['success' => false, 'message' => 'Không thể giải mã cookies'];
        }

        return ['success' => true, 'cookies' => $cookies];
    }

    public static function get_account_login(): array
    {
        $account = self::get_account_by_id(['password', 'email', 'user_id', 'custom_fields']);
        if (!$account['success']) {
            return $account;
        }

        $password = $account['data']['password'] ?? '';
        $email = $account['data']['email'] ?? '';
        if (empty($password) || empty($email)) {
            return ['success' => false, 'message' => 'Password is empty'];
        }

        $password = decrypt($password);
        if ($password === false) {
            return ['success' => false, 'message' => 'Không thể giải mã password'];
        }

        return [
            'success' => true,
            'password' => $password,
            'email' => $email,
            'user_id' => $account['data']['user_id'] ?? '',
            'custom_fields' => json_decode($account['data']['custom_fields'] ?? '{}', true),
        ];
    }

    public static function add_account_orders(): array
    {
        $account = self::get_account_by_id();
        if (!$account['success']) {
            return $account;
        }
        $account_id = $account['data']['ID'];

        $data_json = $_POST['data'] ?? null;
        if (!$data_json) {
            return ['success' => false, 'message' => 'No selling data provided'];
        }

        $orders = json_decode($data_json);
        if (!is_array($orders)) {
            return ['success' => false, 'message' => 'Invalid data format'];
        }

        $placeholders = [];
        $values = [];
        foreach ($orders as $order) {
            // Bỏ qua đơn hàng thiếu ID — không có gì để đối chiếu/định danh trong DB.
            if (empty($order->id)) {
                continue;
            }

            $price = $order->financials->orderEarnings ?? 0;
            if (is_string($price)) {
                $price = (float) preg_replace('/[^\d.]/', '', $price);
            }

            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            array_push(
                $values,
                $account_id,
                $order->id,
                json_encode($order->items ?? []),
                'unshipped',
                $order->timeline->buyerPaidDate ?? null,
                $order->timeline->shipByDate ?? null,
                $order->timeline->estimatedDelivery ?? null,
                $price,
                $order->buyerInfo->name ?? '',
                $order->buyerInfo->phone ?? '',
                $order->buyerInfo->fullAddress ?? ''
            );
        }

        if (empty($values)) {
            return ['success' => true, 'message' => 'Orders processed'];
        }

        try {
            db()->execute_query(
                'INSERT IGNORE INTO orders (
                    account_id, host_id, items, status, purchase_date, ship_date, delivery_date,
                    total_price, full_name, phone, address
                ) VALUES ' . implode(', ', $placeholders),
                $values
            );
            return ['success' => true, 'message' => 'Orders processed'];
        } catch (\mysqli_sql_exception $e) {
            return self::db_error(__FUNCTION__, $e);
        }
    }

    /**
     * TODO: chưa triển khai — mới chỉ kiểm tra quyền (authors key), chưa insert
     * sản phẩm vào bảng `posts`. Cần xác nhận cách map images/type_id/site_id/store_id
     * trước khi viết phần lưu DB.
     */
    public static function add_products(): array
    {
        $authors_id = self::check_authors_key(db());
        if (!$authors_id) {
            return ['success' => false, 'message' => 'Bạn không có quyền thêm sản phẩm.'];
        }

        return ['success' => false, 'message' => 'Chức năng chưa được triển khai.'];
    }

    public static function update_account_finance(): array
    {
        $account = self::get_account_by_id();
        if (!$account['success']) {
            return $account;
        }
        $account_id = $account['data']['ID'];

        $financial_json = $_POST['financial_data'] ?? null;
        if (!$financial_json) {
            return ['success' => false, 'message' => 'No financial data provided'];
        }

        $data = json_decode($financial_json, true);
        if (!$data) {
            return ['success' => false, 'message' => 'Invalid JSON format'];
        }

        $available = (float) str_replace(['$', ','], '', $data['available'] ?? '0');
        $hold = (float) str_replace(['$', ','], '', $data['hold'] ?? '0');
        $processing = (float) str_replace(['$', ','], '', $data['processing'] ?? '0');

        // Tổng phí subscription của tháng hiện tại. Payout thường là số âm nên lấy trị tuyệt đối.
        $month_key = date('Y-n');
        $total_fees = 0.0;
        foreach ($data['fees'][$month_key] ?? [] as $fee) {
            $total_fees += (float) $fee;
        }
        $total_fees = abs($total_fees);

        $first_day_of_month = date('Y-m-01');
        $conn = db();

        try {
            $existing = $conn->execute_query(
                'SELECT ID FROM accounts_finance WHERE account_id = ? AND `date` = ? LIMIT 1',
                [$account_id, $first_day_of_month]
            )->fetch_assoc();

            if ($existing) {
                $conn->execute_query(
                    'UPDATE accounts_finance
                        SET available_funds = ?, processing = ?, on_hold = ?, subscription_fee = ?, sys_date = NOW()
                        WHERE ID = ?',
                    [$available, $processing, $hold, $total_fees, $existing['ID']]
                );
            } else {
                $conn->execute_query(
                    'INSERT INTO accounts_finance (account_id, available_funds, processing, on_hold, subscription_fee, sys_date, `date`)
                        VALUES (?, ?, ?, ?, ?, NOW(), ?)',
                    [$account_id, $available, $processing, $hold, $total_fees, $first_day_of_month]
                );
            }

            return ['success' => true, 'message' => 'Finance data updated for ' . date('M Y')];
        } catch (\mysqli_sql_exception $e) {
            return self::db_error(__FUNCTION__, $e);
        }
    }

    public static function update_account_seller(): array
    {
        $account = self::get_account_by_id();
        if (!$account['success']) {
            return $account;
        }
        $account_id = $account['data']['ID'];

        $data_json = $_POST['data'] ?? null;
        if (!$data_json) {
            return ['success' => false, 'message' => 'No selling data provided'];
        }

        $data = json_decode($data_json, true);
        if (!$data) {
            return ['success' => false, 'message' => 'Invalid JSON format'];
        }
        $normalized_json = json_encode($data);
        $first_day_of_month = date('Y-m-01');
        $conn = db();

        try {
            $existing = $conn->execute_query(
                'SELECT ID FROM accounts_seller WHERE account_id = ? AND `date` = ? LIMIT 1',
                [$account_id, $first_day_of_month]
            )->fetch_assoc();

            if ($existing) {
                $conn->execute_query(
                    'UPDATE accounts_seller SET data = ?, sys_date = NOW() WHERE ID = ?',
                    [$normalized_json, $existing['ID']]
                );
            } else {
                $conn->execute_query(
                    'INSERT INTO accounts_seller (account_id, data, sys_date, `date`) VALUES (?, ?, NOW(), ?)',
                    [$account_id, $normalized_json, $first_day_of_month]
                );
            }

            return ['success' => true, 'message' => 'Selling data updated for ' . date('M Y')];
        } catch (\mysqli_sql_exception $e) {
            return self::db_error(__FUNCTION__, $e);
        }
    }

    public static function update_account_cookies(): array
    {
        $account = self::get_account_by_id();
        if (!$account['success']) {
            return $account;
        }
        $account_id = $account['data']['ID'];

        $raw_cookies = $_POST['cookies'] ?? null;
        if (!$raw_cookies) {
            return ['success' => false, 'message' => 'No cookies data provided'];
        }

        $data = json_decode($raw_cookies, true);
        if (!$data) {
            return ['success' => false, 'message' => 'Invalid JSON format'];
        }

        $user_agent = $data['user_agent'] ?? null;
        $encrypted_cookies = encrypt($raw_cookies);

        try {
            db()->execute_query(
                'UPDATE accounts SET cookies = ?, user_agent = ? WHERE ID = ?',
                [$encrypted_cookies, $user_agent, $account_id]
            );
            return ['success' => true, 'message' => 'Cookies updated successfully'];
        } catch (\mysqli_sql_exception $e) {
            return self::db_error(__FUNCTION__, $e);
        }
    }

    public static function check_products_exist(): array
    {
        $authors_id = self::check_authors_key(db());
        if (!$authors_id) {
            return ['success' => false, 'message' => 'Bạn không có quyền kiểm tra sản phẩm.'];
        }

        $request_data = json_decode($_POST['data'] ?? '', true);
        if (!is_array($request_data) || empty($request_data['ids']) || !is_array($request_data['ids'])) {
            return ['success' => false, 'message' => 'Dữ liệu không đúng định dạng.'];
        }

        $ids = array_values(array_unique(array_map('strval', $request_data['ids'])));
        if (empty($ids)) {
            return ['success' => true, 'data' => []];
        }

        // ID sản phẩm marketplace được lưu ở posts.sku. key/email chỉ dùng để xác minh
        // quyền gọi API (đã check ở check_authors_key), không lọc theo author_id —
        // kiểm tra tồn tại trên toàn bộ sản phẩm, không riêng của author này.
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $result = db()->execute_query(
                "SELECT sku FROM posts WHERE sku IN ($placeholders)",
                $ids
            );

            // Key là sku, value là object rỗng — chỗ để sau này gắn thêm dữ liệu
            // (status, title...) mà không phải đổi lại cấu trúc response.
            $existing = [];
            foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
                $existing['products'][$row['sku']] = new \stdClass();
            }

            // Extension tự quyết định khi nào cần đồng bộ lại types (bảng gần như
            // tĩnh) — chỉ tính/trả khi client chủ động xin qua need_types.
            if (!empty($request_data['need_types'])) {
                $existing['types'] = self::get_types_cached();
            }

            return ['success' => true, 'data' => $existing];
        } catch (\mysqli_sql_exception $e) {
            return self::db_error(__FUNCTION__, $e);
        }
    }

    private static function check_condition(\mysqli $conn): array
    {
        $id = trim($_POST['id'] ?? '');
        $site = trim($_POST['site'] ?? '');
        $key = trim($_POST['key'] ?? '');

        if (!$id || !$site || !$key) {
            return ['success' => false, 'message' => 'Missing parameters'];
        }

        $team_id = self::check_team_key($conn, $key);
        if (!$team_id) {
            return ['success' => false, 'message' => 'Invalid team key'];
        }

        return ['success' => true, 'id' => $id, 'site' => $site, 'team_id' => $team_id];
    }

    private static function check_team_key(\mysqli $conn, string $key): int
    {
        if ($key === '') {
            return 0;
        }

        try {
            $result = $conn->execute_query('SELECT ID FROM team WHERE `key` = ? LIMIT 1', [$key]);
            return (int) ($result->fetch_assoc()['ID'] ?? 0);
        } catch (\mysqli_sql_exception) {
            return 0;
        }
    }

    private static function check_authors_key(\mysqli $conn): int
    {
        $email = trim($_POST['email'] ?? '');
        $key = trim($_POST['key'] ?? '');

        if ($key === '' || $email === '') {
            return 0;
        }

        try {
            $result = $conn->execute_query('SELECT ID FROM authors WHERE `key` = ? AND `email` = ? LIMIT 1', [$key, $email]);
            return (int) ($result->fetch_assoc()['ID'] ?? 0);
        } catch (\mysqli_sql_exception) {
            return 0;
        }
    }

    /**
     * Lấy danh sách type (ID => name) có cache qua APCu (TTL 1 giờ) — bảng `type`
     * gần như không đổi, tránh query lại mỗi lần extension gọi check-listings.
     * Server không có extension apcu thì tự fallback về query DB bình thường.
     */
    private static function get_types_cached(): array
    {
        $cache_key = 'pff_types_v1';
        $use_cache = function_exists('apcu_fetch');

        if ($use_cache) {
            $cached = apcu_fetch($cache_key, $found);
            if ($found) {
                return $cached;
            }
        }

        $types = [];
        try {
            $result = db()->execute_query('SELECT ID, name FROM type');
            foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
                $types[$row['ID']] = $row['name'];
            }
        } catch (\mysqli_sql_exception) {
            return [];
        }

        if ($use_cache) {
            apcu_store($cache_key, $types, 3600);
        }

        return $types;
    }

    /**
     * Ghi log chi tiết lỗi DB ra server, chỉ trả thông báo chung cho client —
     * tránh lộ tên bảng/cột/câu truy vấn cho bên gọi (extension).
     */
    private static function db_error(string $context, \mysqli_sql_exception $e): array
    {
        error_log("[Extensions::$context] " . $e->getMessage());
        return ['success' => false, 'message' => 'Đã xảy ra lỗi hệ thống, vui lòng thử lại sau.'];
    }
}
