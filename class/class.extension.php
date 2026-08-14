<?php

class Extensions
{
    /** Trần kích thước đầu vào — không có trần thì một request dựng được câu IN
     *  hàng nghìn placeholder hoặc bắt server chèn hàng nghìn dòng một lượt. */
    private const MAX_CHECK_IDS = 500;
    private const MAX_ADD_PRODUCTS = 100;

    /** Chống dò key: quá ngần này lần xác thực hỏng trong cửa sổ thì chặn IP. */
    private const AUTH_FAIL_LIMIT = 20;
    private const AUTH_FAIL_WINDOW = 600;

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

        self::audit_credential_access('2fa');
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

        self::audit_credential_access('cookies');
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

        self::audit_credential_access('login');
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
            // Kiểm is_object trước: mảng chứa chuỗi/số sẽ sinh warning PHP 8
            // "Attempt to read property on string" nếu đọc thẳng ->id.
            if (!is_object($order) || empty($order->id)) {
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

    public static function add_products(): array
    {
        $conn = db();
        $auth = self::authenticate($conn);
        if (!$auth || !self::has_permission($auth, 'add', 'products')) {
            return self::denied();
        }

        $data = json_decode($_POST['data'] ?? '', true);
        if (!is_array($data) || empty($data)) {
            return ['success' => false, 'message' => 'Dữ liệu không đúng định dạng.'];
        }

        // Hỗ trợ cả 2 dạng: 1 sản phẩm (object phẳng, có key 'id'/'title'...) hoặc
        // nhiều sản phẩm (mảng object) — phân biệt bằng array_is_list: JSON array
        // luôn decode ra PHP list (key số 0,1,2...), JSON object thì không.
        $is_batch = array_is_list($data);
        $products = $is_batch ? $data : [$data];

        if (count($products) > self::MAX_ADD_PRODUCTS) {
            return ['success' => false, 'message' => 'Tối đa ' . self::MAX_ADD_PRODUCTS . ' sản phẩm mỗi lần.'];
        }

        // Ngữ cảnh dùng chung cho cả lô. Bản cũ tra lại team của author, danh
        // sách type và site_id CHO TỪNG SẢN PHẨM: lô 50 sản phẩm tốn ~250 query
        // trong khi phần lớn là hỏi đi hỏi lại cùng một câu.
        $context = [
            'team_id' => $auth['team_id'],
            'types' => self::get_types_cached(),
            'sites' => [],
            'existing_skus' => array_flip(self::existing_skus(
                $conn,
                array_values(array_filter(array_map(
                    static fn($p) => is_array($p) ? trim((string) ($p['id'] ?? '')) : '',
                    $products
                )))
            )),
        ];

        $results = [];
        foreach ($products as $product) {
            $results[] = self::add_single_product($conn, $auth['id'], is_array($product) ? $product : [], $context);
        }

        if (!$is_batch) {
            return $results[0];
        }

        $success_count = count(array_filter($results, fn($r) => $r['success']));
        return [
            'success' => $success_count > 0,
            'message' => "$success_count/" . count($results) . ' sản phẩm đã thêm thành công.',
            'data' => $results,
        ];
    }

    /**
     * Validate + insert 1 sản phẩm vào `posts`. Dùng chung cho cả luồng thêm
     * 1 sản phẩm và thêm hàng loạt (add_products() gọi lặp lại hàm này).
     */
    private static function add_single_product(\mysqli $conn, int $authors_id, array $data, array &$context): array
    {
        $sku = trim((string) ($data['id'] ?? ''));
        $title = trim((string) ($data['title'] ?? ''));
        $type_id = (int) ($data['type'] ?? 0);
        $site = trim((string) ($data['site'] ?? ''));
        $shop_id = trim((string) ($data['shop']['id'] ?? ''));
        $image_main = $data['images']['main'] ?? '';

        if ($sku === '' || $title === '' || $type_id === 0 || $site === '' || $shop_id === '' || empty($image_main)) {
            return ['success' => false, 'sku' => $sku ?: null, 'message' => 'Thiếu dữ liệu bắt buộc.'];
        }

        try {
            // 1. Loại sản phẩm phải khớp bảng `type`.
            $author_team = $context['team_id'];
            if (!isset($context['types'][$type_id])) {
                return ['success' => false, 'sku' => $sku, 'message' => 'Loại sản phẩm không hợp lệ.'];
            }

            // 2. Site phải tồn tại (etsy.com/amazon.com/ebay.com...). Cả lô thường
            //    cùng một site nên tra một lần rồi dùng lại.
            if (!array_key_exists($site, $context['sites'])) {
                $site_row = $conn->execute_query('SELECT ID FROM site WHERE name = ? LIMIT 1', [$site])->fetch_assoc();
                $context['sites'][$site] = $site_row ? (int) $site_row['ID'] : 0;
            }
            $site_id = $context['sites'][$site];
            if (!$site_id) {
                return ['success' => false, 'sku' => $sku, 'message' => 'Site không hợp lệ.'];
            }

            // 3. Chặn thêm trùng — đã tra sẵn cả lô bằng MỘT câu IN ở add_products().
            //    UNIQUE(sku) vẫn là chốt cuối, xem nhánh bắt lỗi 1062 bên dưới.
            if (isset($context['existing_skus'][$sku])) {
                return ['success' => false, 'sku' => $sku, 'message' => 'Sản phẩm đã tồn tại.'];
            }

            // 4. Lấy hoặc tạo mới store theo slug (tên shop viết thường).
            //    store.team_id = 0 là store dùng chung, > 0 là store riêng của 1 team.
            $store_slug = strtolower($shop_id);
            $store_row = $conn->execute_query('SELECT ID, team_id FROM store WHERE slug = ? LIMIT 1', [$store_slug])->fetch_assoc();
            if ($store_row) {
                // Store riêng của team khác thì không được dùng
                $store_team = (int) $store_row['team_id'];
                if ($store_team > 0 && $store_team !== $author_team) {
                    return ['success' => false, 'sku' => $sku, 'message' => 'Store thuộc team khác.'];
                }
                $store_id = (int) $store_row['ID'];
            } else {
                // Extension tạo store ở chế độ dùng chung (team_id = 0) để tránh lặp dữ liệu;
                // admin có thể gán về 1 team trong menu Stores nếu muốn dùng riêng.
                $conn->execute_query(
                    'INSERT INTO store (name, slug, site_id, team_id) VALUES (?, ?, ?, 0)',
                    [$shop_id, $store_slug, $site_id]
                );
                $store_id = (int) $conn->insert_id;
                if (!$store_id) {
                    return ['success' => false, 'sku' => $sku, 'message' => 'Không thể tạo store.'];
                }
            }

            // 5. Lưu sản phẩm — tags không có cột riêng nên gộp vào metadata (JSON).
            //    variants là object lồng trong $data (không phải chuỗi JSON) — encode
            //    lại trước khi lưu. Optional: không có thì lưu NULL.
            $variant_data = isset($data['variants']) ? json_encode($data['variants']) : null;

            // `posts.badge` chỉ varchar(20). Extension gửi nguyên textContent của nhãn
            // trên trang nguồn — có giao diện trả về nhiều dòng/nhiều nhãn — nên INSERT
            // văng "Data too long" và sản phẩm KHÔNG vào được (30/07/2026, 7 lần trong
            // php_errors.log). Cắt ở server để mọi client đều an toàn, không chỉ trông
            // chờ bản extension mới.
            $badge = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($data['badge'] ?? ''))), 0, 20);

            try {
                $conn->execute_query(
                    "INSERT INTO posts (author_id, date, title, status, sku, images, type_id, site_id, store_id, badge, description, metadata, variantdata)
                        VALUES (?, NOW(), ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $authors_id,
                        $title,
                        $sku,
                        json_encode($data['images'] ?? []),
                        $type_id,
                        $site_id,
                        $store_id,
                        $badge,
                        $data['description'] ?? '',
                        json_encode(['tags' => $data['tags'] ?? []]),
                        $variant_data,
                    ]
                );
            } catch (\mysqli_sql_exception $e) {
                // sku có UNIQUE index — race condition giữa bước check (3) và insert này
                // sẽ rơi vào đây thay vì lọt qua thành 2 bản ghi trùng.
                if ($e->getCode() === 1062) {
                    return ['success' => false, 'sku' => $sku, 'message' => 'Sản phẩm đã tồn tại.'];
                }
                throw $e;
            }

            // Nhớ lại trong phạm vi lô: payload gửi trùng sku hai lần thì lần sau
            // bị chặn ngay, khỏi đợi UNIQUE ném lỗi.
            $context['existing_skus'][$sku] = true;

            return ['success' => true, 'sku' => $sku, 'data' => ['id' => (int) $conn->insert_id]];
        } catch (\mysqli_sql_exception $e) {
            error_log('[Extensions::add_single_product] ' . $e->getMessage());
            return ['success' => false, 'sku' => $sku, 'message' => 'Đã xảy ra lỗi hệ thống, vui lòng thử lại sau.'];
        }
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
        $auth = self::authenticate(db());
        if (!$auth || !self::has_permission($auth, 'view', 'products')) {
            return self::denied();
        }

        $request_data = json_decode($_POST['data'] ?? '', true);
        if (!is_array($request_data) || empty($request_data['ids']) || !is_array($request_data['ids'])) {
            return ['success' => false, 'message' => 'Dữ liệu không đúng định dạng.'];
        }

        $ids = array_values(array_unique(array_map('strval', $request_data['ids'])));
        if (count($ids) > self::MAX_CHECK_IDS) {
            return ['success' => false, 'message' => 'Tối đa ' . self::MAX_CHECK_IDS . ' mã sản phẩm mỗi lần.'];
        }
        if (empty($ids)) {
            // Luôn trả đúng hình dạng {products:{}} — bản cũ trả mảng rỗng nên
            // client phải tự đoán, dễ hiểu nhầm là lỗi.
            return ['success' => true, 'data' => ['products' => new \stdClass()]];
        }

        // ID sản phẩm marketplace được lưu ở posts.sku. key/email chỉ dùng để xác minh
        // quyền gọi API (đã check ở authenticate/has_permission), không lọc theo
        // author_id — kiểm tồn tại trên toàn bộ sản phẩm, không riêng author này.
        try {
            // Key là sku, value là object rỗng — chỗ để sau này gắn thêm dữ liệu
            // (status, title...) mà không phải đổi lại cấu trúc response.
            $existing = ['products' => new \stdClass()];
            foreach (self::existing_skus(db(), $ids) as $sku) {
                $existing['products']->{$sku} = new \stdClass();
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

    /**
     * Truy vấn danh sách sản phẩm (toàn bộ cột của `posts`) của author đang gọi API
     * (author_id suy ra từ key/email xác thực). Các điều kiện type_id/site_id/store_id/
     * date_from/date_to/offset_from/offset_to đều optional, chỉ áp dụng khi được gửi.
     */
    public static function get_products(): array
    {
        $auth = self::authenticate(db());
        if (!$auth || !self::has_permission($auth, 'view', 'products')) {
            return self::denied();
        }

        $conn = db();
        $where = ['author_id = ?'];
        $params = [$auth['id']];

        try {
            if (!empty($_POST['type_id'])) {
                $where[] = 'type_id = ?';
                $params[] = (int) $_POST['type_id'];
            }
            if (!empty($_POST['site'])) {
                // Giống add_products(): nhận tên site dạng text (vd "etsy.com"), tự tra site_id.
                $site_row = $conn->execute_query('SELECT ID FROM site WHERE name = ? LIMIT 1', [trim((string) $_POST['site'])])->fetch_assoc();
                if (!$site_row) {
                    return ['success' => false, 'message' => 'Site không hợp lệ.'];
                }
                $where[] = 'site_id = ?';
                $params[] = (int) $site_row['ID'];
            }
            if (!empty($_POST['store_id'])) {
                $where[] = 'store_id = ?';
                $params[] = (int) $_POST['store_id'];
            }
            if (!empty($_POST['date_from'])) {
                $where[] = '`date` >= ?';
                $params[] = trim((string) $_POST['date_from']);
            }
            if (!empty($_POST['date_to'])) {
                $where[] = '`date` <= ?';
                $params[] = trim((string) $_POST['date_to']);
            }

            // offset_from/offset_to xác định 1 khoảng trong kết quả, vd offset_from=0&offset_to=50
            // lấy 50 bản ghi đầu. Không gửi thì mặc định 100 bản ghi đầu; giới hạn tối đa 500/lần
            // để tránh 1 request kéo cả bảng.
            $offset_from = max(0, (int) ($_POST['offset_from'] ?? 0));
            $offset_to = (int) ($_POST['offset_to'] ?? 0);
            $limit = $offset_to > $offset_from ? min($offset_to - $offset_from, 500) : 100;

            // Liệt kê cột thay cho `SELECT *` để biết chính xác API trả những gì
            // (thêm cột mới vào bảng không tự động lọt ra ngoài). `description`
            // và `variantdata` nằm trong hợp đồng — bên gọi cần cả hai.
            // Lưu ý dung lượng: `variantdata` có dòng tới 38KB, nhân 100 dòng
            // mặc định là vài MB mỗi lần gọi, nên hãy dùng offset_from/offset_to
            // để lấy từng khoảng nhỏ thay vì kéo hết một lượt.
            $sql = 'SELECT ID, author_id, date, updated_at, title, description, status, sku, images,
                           type_id, site_id, store_id, badge, metadata, variantdata
                    FROM posts WHERE ' . implode(' AND ', $where) . ' ORDER BY ID DESC LIMIT ? OFFSET ?';
            $params[] = $limit;
            $params[] = $offset_from;

            $result = $conn->execute_query($sql, $params);
            return ['success' => true, 'data' => $result->fetch_all(MYSQLI_ASSOC)];
        } catch (\mysqli_sql_exception $e) {
            return self::db_error(__FUNCTION__, $e);
        }
    }

    /**
     * Cổng cho nhóm endpoint quản lý ACCOUNT (2FA/cookies/mật khẩu/đơn hàng).
     *
     * XÁC THỰC BẰNG KEY CỦA TEAM, VÀ ĐÓ LÀ CÓ CHỦ Ý (chốt 13/08/2026): giữ được
     * `team.key` nghĩa là toàn quyền trong phạm vi team đó — không kiểm role,
     * không kiểm cấp. Đừng "sửa" thành key theo người.
     *
     * Ranh giới duy nhất là TEAM: `get_account_by_id()` luôn lọc
     * `accounts.team_id = <team của key>`, nên key của team này không chạm được
     * account của team khác. Giữ nguyên điều kiện đó ở mọi endpoint thêm sau này.
     *
     * Đổi lại, khoá này có sức công phá lớn nhất hệ thống (một key = 317 account
     * của FOX TEAM, kèm mật khẩu/cookies/2FA giải mã sẵn), nên ở đây có thêm:
     *  - chống dò khoá (đếm số lần hỏng theo IP thật, xem ghi chú Cloudflare),
     *  - ghi log mọi lần truy cập để còn lần ra khi có sự cố.
     * Team key phải luôn đủ dài (32 hex) và thu hồi bằng cách đổi `team.key`.
     */
    private static function check_condition(\mysqli $conn): array
    {
        $id = trim($_POST['id'] ?? '');
        $site = trim($_POST['site'] ?? '');
        $key = trim($_POST['key'] ?? '');

        if (!$id || !$site || !$key) {
            return ['success' => false, 'message' => 'Missing parameters'];
        }

        if (self::is_throttled()) {
            return ['success' => false, 'message' => 'Too many failed attempts. Try again later.'];
        }

        $team_id = self::check_team_key($conn, $key);
        if (!$team_id) {
            self::record_auth_failure();
            return ['success' => false, 'message' => 'Invalid team key'];
        }

        return ['success' => true, 'id' => $id, 'site' => $site, 'team_id' => $team_id];
    }

    /**
     * Vết truy cập thông tin đăng nhập của account. Ghi ra log lỗi PHP (đã bị
     * nginx chặn không cho tải qua HTTP từ 13/08/2026).
     */
    private static function audit_credential_access(string $what): void
    {
        error_log(sprintf(
            '[Extensions::audit] %s account=%s site=%s ip=%s ua=%s',
            $what,
            trim((string) ($_POST['id'] ?? '?')),
            trim((string) ($_POST['site'] ?? '?')),
            $_SERVER['REMOTE_ADDR'] ?? '-',
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? '-'), 0, 120)
        ));
    }

    private static function check_team_key(\mysqli $conn, string $key): int
    {
        if ($key === '') {
            return 0;
        }

        try {
            // status = 1: team ngừng hoạt động thì key của nó hết hiệu lực với API
            $result = $conn->execute_query('SELECT ID FROM team WHERE `key` = ? AND status = 1 LIMIT 1', [$key]);
            return (int) ($result->fetch_assoc()['ID'] ?? 0);
        } catch (\mysqli_sql_exception) {
            return 0;
        }
    }

    /**
     * Xác thực người gọi API extension và lấy luôn ngữ cảnh phân quyền.
     *
     * Trả về `['id','team_id','level','roles']` hoặc null nếu không hợp lệ.
     * Ba điều kiện, thiếu một là hỏng cả luật phân quyền:
     *  - key + email khớp `authors`;
     *  - `authors.status = 2` (Active). Bản cũ KHÔNG kiểm cột này, nên khoá tài
     *    khoản của người nghỉ việc mà key vẫn dùng được — quyền API không thu
     *    hồi được theo tài khoản;
     *  - team còn hoạt động (`team.status = 1`); team_id = 0 thì không có gì để khoá.
     */
    private static function authenticate(\mysqli $conn): ?array
    {
        $email = trim($_POST['email'] ?? '');
        $key = trim($_POST['key'] ?? '');

        if ($key === '' || $email === '') {
            return null;
        }

        if (self::is_throttled()) {
            return null;
        }

        try {
            $result = $conn->execute_query(
                'SELECT a.ID, a.team_id, r.slug AS level_slug, r.roles
                 FROM authors a
                 LEFT JOIN team t ON t.ID = a.team_id
                 LEFT JOIN roles_permissions r ON r.ID = a.level
                 WHERE a.`key` = ? AND a.`email` = ? AND a.status = 2
                   AND (a.team_id = 0 OR t.status = 1)
                 LIMIT 1',
                [$key, $email]
            );
            $row = $result->fetch_assoc();
        } catch (\mysqli_sql_exception) {
            return null;
        }

        if (!$row) {
            self::record_auth_failure();
            return null;
        }

        $roles = json_decode((string) ($row['roles'] ?? ''), true);

        return [
            'id' => (int) $row['ID'],
            'team_id' => (int) $row['team_id'],
            'level' => (string) ($row['level_slug'] ?? ''),
            'roles' => is_array($roles) ? $roles : [],
        ];
    }

    /**
     * API không được là cửa sau đi vòng qua phân quyền. Hai điều kiện:
     *
     *  - CẤP: từ `user` trở lên. `customer` là khách, không phải người làm việc
     *    trong hệ thống, nên dù có key vẫn bị từ chối (chốt 13/08/2026).
     *  - ROLE: phải có ĐÚNG cờ hành động trên menu tương ứng — `view` để đọc,
     *    `add` để thêm (chốt 13/08/2026), đúng như `checkRoles()` bên giao diện.
     *    Hệ quả: vai nào chỉ được cấp `products.view` thì đọc được nhưng KHÔNG
     *    import được; muốn import phải bật cờ `add` cho vai đó trong menu
     *    Roles & Permissions.
     *
     * Chỉ admin bỏ qua role, giống `checkRoles()`; manager vẫn phải có quyền.
     */
    private static function has_permission(array $auth, string $action, string $menu): bool
    {
        $rank = LEVEL_RANK[$auth['level']] ?? 0;
        if ($rank < LEVEL_RANK['user']) {
            return false;
        }

        if ($auth['level'] === 'admin') {
            return true;
        }

        return !empty($auth['roles'][$menu][$action]);
    }

    /** Thông báo chung cho mọi trường hợp bị từ chối — không tiết lộ lý do cho bên gọi. */
    private static function denied(): array
    {
        return ['success' => false, 'message' => 'Bạn không có quyền thực hiện thao tác này.'];
    }

    /* ------------------------------------------------------------------ */
    /* Chống dò key                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Đếm số lần xác thực hỏng theo IP. Dữ liệu thật từng có key dài 3–4 ký tự,
     * mà endpoint thì không giới hạn số lần thử — dò key là chuyện vài phút.
     * Đếm bằng file trong thư mục tạm của hệ thống: không đụng schema, không
     * cần APCu (server hiện không có).
     */
    private static function throttle_file(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        return sys_get_temp_dir() . '/pff-ext-auth-' . sha1($ip) . '.json';
    }

    private static function is_throttled(): bool
    {
        $file = self::throttle_file();
        if (!is_readable($file)) {
            return false;
        }
        $state = json_decode((string) @file_get_contents($file), true);
        if (!is_array($state)) {
            return false;
        }
        if (($state['at'] ?? 0) < time() - self::AUTH_FAIL_WINDOW) {
            return false; // hết cửa sổ, tính lại từ đầu
        }
        return ($state['fails'] ?? 0) >= self::AUTH_FAIL_LIMIT;
    }

    private static function record_auth_failure(): void
    {
        $file = self::throttle_file();
        $state = json_decode((string) @file_get_contents($file), true);
        $fresh = !is_array($state) || ($state['at'] ?? 0) < time() - self::AUTH_FAIL_WINDOW;
        @file_put_contents($file, json_encode([
            'fails' => $fresh ? 1 : (int) $state['fails'] + 1,
            'at' => $fresh ? time() : (int) $state['at'],
        ]), LOCK_EX);
    }

    /**
     * Những sku đã có trong `posts`, tra bằng MỘT câu cho cả danh sách.
     * `posts.sku` có UNIQUE index nên đây là tra khoá, không quét bảng.
     *
     * @param string[] $skus
     * @return string[]
     */
    private static function existing_skus(\mysqli $conn, array $skus): array
    {
        $skus = array_values(array_unique(array_filter($skus, static fn($s) => $s !== '')));
        if (empty($skus)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($skus), '?'));
        try {
            $rows = $conn->execute_query("SELECT sku FROM posts WHERE sku IN ($placeholders)", $skus)
                ->fetch_all(MYSQLI_ASSOC);
        } catch (\mysqli_sql_exception $e) {
            error_log('[Extensions::existing_skus] ' . $e->getMessage());
            return [];
        }

        return array_column($rows, 'sku');
    }

    /**
     * Danh mục cho extension. Category DÙNG CHUNG toàn hệ thống nên mọi author đều
     * nhận cùng một danh sách (xem ghi chú mô hình ở class.categories.php).
     *
     * Cache qua APCu nếu có (TTL 1 giờ). Server hiện KHÔNG cài apcu nên phải có
     * thêm bộ nhớ tạm trong một request: thiếu nó thì nhập lô 100 sản phẩm sẽ
     * chạy lại đúng câu truy vấn này 100 lần.
     */
    private static function get_types_cached(): array
    {
        static $memo = null;
        if ($memo !== null) {
            return $memo;
        }

        $cache_key = 'pff_types_v3';
        $use_cache = function_exists('apcu_fetch');

        if ($use_cache) {
            $cached = apcu_fetch($cache_key, $found);
            if ($found) {
                return $memo = $cached;
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

        return $memo = $types;
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
