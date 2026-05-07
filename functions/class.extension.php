<?php

class Extensions
{
    public static function get_account_by_id(array $fields = ['ID']): array
    {
        $conn = db();
        $id = isset($_POST['id']) ? trim((string)$_POST['id']) : null;
        $site = isset($_POST['site']) ? trim((string)$_POST['site']) : null;
        $key = isset($_POST['key']) ? trim((string)$_POST['key']) : null;

        if (empty($key) || empty($id) || empty($site)) {
            return ['success' => false, 'message' => 'Missing parameters'];
        }

        // Kiểm tra team_id qua key
        $team_id = self::check_team_key($conn, $key);

        if ($team_id === 0) {
            return ['success' => false, 'message' => 'Invalid team key'];
        }

        // Truy vấn lấy dữ liệu account
        $select_clause = implode(', ', array_map(fn($f) => "a.$f", $fields));
        $sql = "SELECT $select_clause 
            FROM accounts a
            INNER JOIN site s ON a.site_id = s.ID
            WHERE (a.user_id = ? OR a.email = ?) AND a.team_id = ? AND s.slug = ?
            LIMIT 1";

        try {
            $stmt = $conn->prepare($sql);
            // "ii" vì ID và team_id đều là kiểu bigint (integer)
            $stmt->bind_param("ssis", $id, $id, $team_id, $site);
            $stmt->execute();
            $result = $stmt->get_result();
            $account = $result->fetch_assoc();

            if ($account) {
                return ['success' => true, 'data' => $account];
            } else {
                return ['success' => false, 'message' => 'Account not found'];
            }

        } catch (mysqli_sql_exception $e) {
            return [
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ];
        } finally {
            $stmt?->close();
        }
    }

    public static function get_account_2fa(): array
    {
        $arg = self::get_account_by_id(['2fa']);
        return $arg['success'] ? ['success' => true, 'code' => self::getTOTPCode($arg['data'])]: $arg;
    }


    /**
     * Tạo mã 2FA (6 số) từ Secret Key
     */
    private static function getTOTPCode(string $secret): string
    {
        // 1. Loại bỏ khoảng trắng và chuyển về chữ hoa
        $secret = str_replace(' ', '', strtoupper($secret));

        // 2. Bảng mã Base32
        $base32chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";

        // 3. Giải mã Base32 (Giải thuật thủ công để không phụ thuộc thư viện)
        $binarySecret = "";
        foreach (str_split($secret) as $char) {
            if (str_contains($base32chars, $char)) {
                $binarySecret .= str_pad(decbin(strpos($base32chars, $char)), 5, '0', STR_PAD_LEFT);
            }
        }

        $binarySecret = str_split($binarySecret, 8);
        $binarySecretString = "";
        foreach ($binarySecret as $bin) {
            if (strlen($bin) === 8) {
                $binarySecretString .= chr(bindec($bin));
            }
        }

        // 4. Tính toán chu kỳ thời gian (mỗi 30 giây)
        $timeStep = floor(time() / 30);
        $timeBinary = pack('N*', 0) . pack('N*', $timeStep);

        // 5. Tạo mã Hash HMAC-SHA1
        $hash = hash_hmac('sha1', $timeBinary, $binarySecretString, true);

        // 6. Kỹ thuật Dynamic Truncation để lấy 4 bytes
        $offset = ord($hash[19]) & 0xf;
        $otp = (
            (ord($hash[$offset + 0]) & 0x7f) << 24 |
            (ord($hash[$offset + 1]) & 0xff) << 16 |
            (ord($hash[$offset + 2]) & 0xff) << 8 |
            (ord($hash[$offset + 3]) & 0xff)
        );

        // 7. Lấy 6 số cuối cùng
        return str_pad((string)($otp % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function update_account_finance(): array
    {
        // 1. Kiểm tra tài khoản qua key và id (user_id)
        $account_res = self::get_account_by_id();
        if (!$account_res['success']) {
            return $account_res;
        }

        $conn = db();
        $account_id = $account_res['data']['ID'];
        $financial_json = $_POST['financial_data'] ?? null;

        if (!$financial_json) {
            return ['success' => false, 'message' => 'No financial data provided'];
        }

        $data = json_decode($financial_json, true);
        if (!$data) {
            return ['success' => false, 'message' => 'Invalid JSON format'];
        }

        // 2. Xử lý số liệu (Loại bỏ dấu $ và ép kiểu về float)
        $available  = (float) str_replace(['$', ','], '', $data['available'] ?? '0');
        $hold       = (float) str_replace(['$', ','], '', $data['hold'] ?? '0');
        $processing = (float) str_replace(['$', ','], '', $data['processing'] ?? '0');

        // 3. Tính tổng subscription_fee cho tháng hiện tại
        $current_month_key = date('Y-n'); // Ví dụ: 2026-4 (không có số 0 ở tháng theo JSON của bạn)
        $total_fees = 0;
        if (isset($data['fees'][$current_month_key])) {
            foreach ($data['fees'][$current_month_key] as $val) {
                $total_fees += (float) $val;
            }
        }
        // Vì payout thường là số âm (-2), nếu bạn muốn lấy giá trị tuyệt đối thì dùng abs($total_fees)
        $total_fees = abs($total_fees);

        // 4. Chuẩn bị ngày tháng (Lưu dưới dạng ngày đầu tháng: 2026-04-01)
        $first_day_of_month = date('Y-m-01');

        // 5. Kiểm tra tồn tại bản ghi của tháng này chưa
        $sql_check = "SELECT ID FROM accounts_finance WHERE account_id = ? AND `date` = ? LIMIT 1";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bind_param("is", $account_id, $first_day_of_month);
        $stmt_check->execute();
        $existing = $stmt_check->get_result()->fetch_assoc();
        $stmt_check->close();

        try {
            if ($existing) {
                // UPDATE
                $sql = "UPDATE accounts_finance 
                    SET available_funds = ?, processing = ?, on_hold = ?, subscription_fee = ?, sys_date = NOW() 
                    WHERE ID = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ddddi", $available, $processing, $hold, $total_fees, $existing['ID']);
            } else {
                // INSERT
                $sql = "INSERT INTO accounts_finance (account_id, available_funds, processing, on_hold, subscription_fee, sys_date, `date`) 
                    VALUES (?, ?, ?, ?, ?, NOW(), ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("idddds", $account_id, $available, $processing, $hold, $total_fees, $first_day_of_month);
            }

            if ($stmt->execute()) {
                $res = ['success' => true, 'message' => 'Finance data updated for ' . date('M Y')];
            } else {
                $res = ['success' => false, 'message' => 'Update failed'];
            }
            $stmt->close();
            return $res;

        } catch (mysqli_sql_exception $e) {
            return ['success' => false, 'message' => 'DB Error: ' . $e->getMessage()];
        }
    }

    private static function check_team_key($conn, string $key): int
    {
        if (empty($key)) {
            return 0;
        }

        $sql = "SELECT ID FROM team WHERE `key` = ? LIMIT 1";

        try {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $key);
            $stmt->execute();
            $result = $stmt->get_result();

            $id = 0;
            if ($row = $result->fetch_assoc()) {
                $id = (int)$row['ID'];
            }

            $stmt->close();

            return $id;

        } catch (mysqli_sql_exception $e) {
            return 0;
        }
    }
}