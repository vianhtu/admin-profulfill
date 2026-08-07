<?php
/**
 * Team — nghiệp vụ MỘT bản ghi team (form Add/Edit trong offcanvas của trang Teams).
 * Cùng khuôn với class.store.php / class.site.php.
 *
 * Admin-only (chốt 05/08/2026). Cột `key` (UNIQUE, dùng nội bộ) tự sinh random khi
 * tạo và KHÔNG cho sửa qua form — không nhận từ request, không trả về client.
 */
class Team
{
    /**
     * Thêm/sửa team. POST: id (0 = thêm mới), name, status (0/1).
     *
     * @return array{status:string,id?:int,message?:string}
     */
    public static function save_team(): array
    {
        if (!check_csrf()) {
            return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
        }
        if (!is_admin()) {
            return ['status' => 'error', 'message' => 'Only an admin can manage teams.'];
        }

        $id     = (int)($_POST['id'] ?? 0);
        $name   = trim((string)($_POST['name'] ?? ''));
        $status = (int)($_POST['status'] ?? 1) === 1 ? 1 : 0;

        if ($name === '') {
            return ['status' => 'error', 'message' => 'Team name is required.'];
        }
        if (mb_strlen($name) > 100) {
            return ['status' => 'error', 'message' => 'Team name must be 100 characters or fewer.'];
        }

        $conn = db();

        // Tên team không được trùng (schema chỉ UNIQUE cột key nên kiểm ở tầng code)
        $dup = $conn->execute_query('SELECT ID FROM team WHERE name = ? AND ID <> ? LIMIT 1', [$name, $id])->fetch_row();
        if ($dup) {
            return ['status' => 'error', 'message' => 'A team with this name already exists.'];
        }

        try {
            if ($id > 0) {
                // Team ĐANG XÓA DỞ (status = 2) thì không cho sửa: câu UPDATE dưới đây ép
                // status về 0/1, tức là xóa mất dấu "đang dở" — tệ nhất là bật lại Active
                // giữa chừng, mở cửa cho một team đã mất nửa dữ liệu (chốt 07/08/2026).
                $dangXoa = $conn->execute_query(
                    'SELECT status FROM team WHERE ID = ? LIMIT 1', [$id])->fetch_row();
                if ($dangXoa && (int)$dangXoa[0] === Teams::STATUS_DELETING) {
                    return ['status' => 'error',
                        'message' => 'This team is being deleted. Finish or resume that first.'];
                }
                // Sửa: chỉ name/status — không đụng tới key
                $conn->execute_query('UPDATE team SET name = ?, status = ? WHERE ID = ?', [$name, $status, $id]);
                if ($conn->execute_query('SELECT ID FROM team WHERE ID = ?', [$id])->fetch_row() === null) {
                    return ['status' => 'error', 'message' => 'Team not found.'];
                }
                return ['status' => 'success', 'id' => $id];
            }

            // Thêm mới: key tự sinh — UNIQUE trong DB chống trùng, trùng (cực hiếm) thì sinh lại
            for ($attempt = 0; $attempt < 3; $attempt++) {
                try {
                    $key = bin2hex(random_bytes(16));
                    $conn->execute_query('INSERT INTO team (name, `key`, status) VALUES (?, ?, ?)', [$name, $key, $status]);
                    return ['status' => 'success', 'id' => $conn->insert_id];
                } catch (\mysqli_sql_exception $e) {
                    // 1062 = trùng UNIQUE key -> thử sinh key khác, lỗi khác thì ném tiếp
                    if ($e->getCode() !== 1062) {
                        throw $e;
                    }
                }
            }
            return ['status' => 'error', 'message' => 'Could not generate a unique team key. Please try again.'];
        } catch (\mysqli_sql_exception $e) {
            return ['status' => 'error', 'message' => 'Save failed: ' . $e->getMessage()];
        }
    }
}
