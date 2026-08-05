<?php
/**
 * User — nghiệp vụ MỘT bản ghi `authors` (form Add/Edit trong offcanvas trang Users).
 * Cùng khuôn với class.team.php / class.store.php.
 *
 * Chống LEO THANG QUYỀN (quan trọng nhất ở màn hình này):
 *   - non-admin KHÔNG được gán nhóm quyền cấp admin cho bất kỳ ai;
 *   - non-admin KHÔNG được đổi team (bị ép về team của chính họ);
 *   - manager chỉ đụng được user cùng team và không phải tài khoản admin;
 *   - không ai tự hạ/nâng quyền chính mình qua màn hình này.
 * Mọi tham số không được phép dùng đều bị BỎ QUA (ép về giá trị hợp lệ), không tin request.
 */
class User
{
    /**
     * Thêm/sửa user. POST: id (0 = thêm), username, email, password, team_id, level,
     * status, wage, insurance. Sửa mà để trống password = giữ nguyên mật khẩu cũ.
     *
     * @return array{status:string,id?:int,message?:string}
     */
    public static function save_user(): array
    {
        if (!check_csrf()) {
            return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
        }

        $id = (int)($_POST['id'] ?? 0);
        $isNew = $id <= 0;

        if ($isNew ? !Users::can_add() : !checkRoles('edit', 'users')) {
            return ['status' => 'error', 'message' => 'You do not have permission to manage users.'];
        }

        $conn = db();
        $adminLevels = Users::admin_level_ids($conn);

        $username = trim((string)($_POST['username'] ?? ''));
        $email    = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $level    = (int)($_POST['level'] ?? 0);
        $status   = (int)($_POST['status'] ?? Users::STATUS_ACTIVE);
        $teamId   = (int)($_POST['team_id'] ?? 0);

        // --- Kiểm tra dữ liệu ---
        if ($username === '' || $email === '') {
            return ['status' => 'error', 'message' => 'Username and email are required.'];
        }
        if (mb_strlen($username) > 100 || mb_strlen($email) > 100) {
            return ['status' => 'error', 'message' => 'Username and email must be 100 characters or fewer.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'error', 'message' => 'Invalid email address.'];
        }
        if (!isset(Users::STATUSES[$status])) {
            return ['status' => 'error', 'message' => 'Invalid status.'];
        }
        if (!$conn->execute_query('SELECT ID FROM roles_permissions WHERE ID = ? LIMIT 1', [$level])->fetch_row()) {
            return ['status' => 'error', 'message' => 'Invalid role.'];
        }
        if ($isNew && strlen($password) < 8) {
            return ['status' => 'error', 'message' => 'Password must be at least 8 characters.'];
        }
        if (!$isNew && $password !== '' && strlen($password) < 8) {
            return ['status' => 'error', 'message' => 'Password must be at least 8 characters.'];
        }

        // --- Bản ghi đang sửa + kiểm quyền trên chính dòng đó ---
        $current = null;
        if (!$isNew) {
            $current = $conn->execute_query(
                'SELECT ID, team_id, level FROM authors WHERE ID = ? LIMIT 1', [$id])->fetch_assoc();
            if (!$current) {
                return ['status' => 'error', 'message' => 'User not found.'];
            }
            if (!Users::can_edit_row((int)$current['team_id'], (int)$current['level'], $conn)) {
                return ['status' => 'error', 'message' => 'You do not have permission to edit this user.'];
            }
            // Không tự đổi quyền/team của chính mình qua màn hình này
            if ((int)$current['ID'] === (int)($_SESSION['auth']['user_id'] ?? 0)
                && ((int)$current['level'] !== $level || (int)$current['team_id'] !== $teamId)) {
                return ['status' => 'error', 'message' => 'You cannot change your own role or team.'];
            }
        }

        // --- Ép các tham số vượt quyền về giá trị hợp lệ (không báo lỗi, chỉ bỏ qua) ---
        if (!is_admin()) {
            // Non-admin luôn thao tác trong team của chính mình
            $teamId = (int)($_SESSION['auth']['team'] ?? 0);
            // và tuyệt đối không được tạo/nâng ai lên quyền admin
            if (in_array($level, $adminLevels, true)) {
                return ['status' => 'error', 'message' => 'You cannot assign the admin role.'];
            }
        }
        if ($teamId > 0 && !$conn->execute_query('SELECT ID FROM team WHERE ID = ? LIMIT 1', [$teamId])->fetch_row()) {
            return ['status' => 'error', 'message' => 'Team does not exist.'];
        }

        // --- Trùng username/email ---
        $dup = $conn->execute_query(
            'SELECT ID FROM authors WHERE (username = ? OR email = ?) AND ID <> ? LIMIT 1',
            [$username, $email, $id])->fetch_row();
        if ($dup) {
            return ['status' => 'error', 'message' => 'Username or email is already in use.'];
        }

        // Lương: chỉ người được xem lương mới được ghi; người khác gửi lên thì bỏ qua
        $wage = $insurance = null;
        if (Users::can_see_salary()) {
            $wage      = (float)($_POST['wage'] ?? 0);
            $insurance = (float)($_POST['insurance'] ?? 0);
        }

        try {
            if ($isNew) {
                // `key` (API extension) tự sinh, không nhận từ form
                $conn->execute_query(
                    'INSERT INTO authors (team_id, email, status, username, pass, `key`, level, wage, insurance, date)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                    [$teamId, $email, $status, $username, password_hash($password, PASSWORD_DEFAULT),
                     bin2hex(random_bytes(16)), $level, $wage ?? 0, $insurance ?? 0]
                );
                return ['status' => 'success', 'id' => $conn->insert_id];
            }

            $sets = ['team_id = ?', 'email = ?', 'status = ?', 'username = ?', 'level = ?'];
            $args = [$teamId, $email, $status, $username, $level];
            if ($password !== '') {
                $sets[] = 'pass = ?';
                $args[] = password_hash($password, PASSWORD_DEFAULT);
            }
            if ($wage !== null) {
                $sets[] = 'wage = ?';
                $args[] = $wage;
                $sets[] = 'insurance = ?';
                $args[] = $insurance;
            }
            $args[] = $id;
            $conn->execute_query('UPDATE authors SET ' . implode(', ', $sets) . ' WHERE ID = ?', $args);

            // Đổi mật khẩu -> thu hồi mọi token "nhớ đăng nhập" cũ của user đó
            if ($password !== '') {
                $conn->execute_query('DELETE FROM author_remember_tokens WHERE author_id = ?', [$id]);
            }
            return ['status' => 'success', 'id' => $id];
        } catch (\mysqli_sql_exception $e) {
            return ['status' => 'error', 'message' => 'Save failed: ' . $e->getMessage()];
        }
    }
}
