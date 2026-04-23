<?php
class Extensions
{
    public static function get_account_by_id(): array
    {
        $conn = db();
        $id = isset($_POST['id']) ? trim((string)$_POST['id']) : null;
        $key = isset($_POST['key']) ? trim((string)$_POST['key']) : null;

        if (empty($key) || empty($id)) {
            return ['success' => false, 'message' => 'Missing parameters'];
        }

        // Kiểm tra team_id qua key
        $team_id = self::check_team_key($conn, $key);

        if ($team_id === 0) {
            return ['success' => false, 'message' => 'Invalid team key'];
        }

        // Truy vấn lấy dữ liệu account
        $sql = "SELECT ID, user_id, email, password 
            FROM accounts 
            WHERE user_id = ? AND team_id = ? 
            LIMIT 1";

        try {
            $stmt = $conn->prepare($sql);
            // "ii" vì ID và team_id đều là kiểu bigint (integer)
            $stmt->bind_param("si", $id, $team_id);
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