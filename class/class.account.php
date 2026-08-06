<?php
/**
 * Account — trang tài khoản của MỘT người (`?menu=account`, `?menu=account&id=N`).
 *
 * Sinh ra vì menu Users là cửa duy nhất để đổi mật khẩu, mà cửa đó lại đòi role `users`:
 * người không được cấp menu đó thì không có đường nào tự đổi mật khẩu (chốt 06/08/2026).
 *
 * QUYỀN XEM — theo đúng 3 trục của dự án, khác nhau giữa hai chế độ:
 *   - **Của MÌNH** (`?menu=account`): ai đăng nhập cũng vào được, KHÔNG đòi role nào. Đây là
 *     ngoại lệ có chủ ý — xem tài khoản của chính mình không phải là "quản lý người dùng".
 *   - **Của NGƯỜI KHÁC** (`&id=N`): phải thoả CẢ BA trục như bảng Users —
 *     ROLE `view` trên menu `users` × PHẠM VI (admin=mọi team, còn lại=team mình)
 *     × CẤP (không nhìn lên trên). Dùng lại thẳng `Users::scope_where()` để hai nơi không
 *     bao giờ lệch nhau.
 *
 * QUYỀN SỬA: chỉ hồ sơ của CHÍNH MÌNH. Xem người khác là CHỈ ĐỌC — muốn sửa họ thì vào menu
 * Users, nơi đã có sẵn luật cấp và luật team. Không nhân đôi đường ghi ở đây.
 *
 * Ba trường tự ban phát cho bản thân được (role/team/status) và LƯƠNG không hề có mặt trong
 * form này, nên không cần chốt riêng — đường ghi duy nhất là save_profile() + change_password()
 * và cả hai chỉ đụng đúng dòng của người đang đăng nhập, không nhận tham số `id` nào.
 */
final class Account
{
    /** Số thiết bị ghi nhớ đăng nhập hiển thị tối đa. */
    private const MAX_DEVICES = 20;

    private static function my_id(): int
    {
        return (int)($_SESSION['auth']['user_id'] ?? 0);
    }

    /**
     * Người đang đăng nhập có được xem hồ sơ của $id không.
     * Trả về mảng dòng đó, hoặc null nếu không được phép / không tồn tại.
     */
    public static function viewable(int $id): ?array
    {
        $conn = db();
        $me   = self::my_id();
        if ($id <= 0 || $id === $me) {
            $id = $me;   // của mình: luôn được, không cần role
        } elseif (!checkRoles('view', 'users')) {
            return null;
        }

        $row = $conn->execute_query(
            'SELECT a.ID, a.username, a.email, a.team_id, a.level, a.status, a.date,
                    a.wage, a.insurance, a.`key`' . (Users::has_avatar_column() ? ', a.avatar' : '') . ',
                    t.name AS team_name, r.name AS role_name, r.slug AS role_slug
             FROM authors a
             LEFT JOIN team t ON t.ID = a.team_id
             LEFT JOIN roles_permissions r ON r.ID = a.level
             WHERE a.ID = ? LIMIT 1', [$id])->fetch_assoc();
        if (!$row) {
            return null;
        }
        // Người khác: phải nằm trong đúng phạm vi mà bảng Users cho thấy (team + cấp).
        if ((int)$row['ID'] !== $me) {
            $scope = Users::scope_where();
            if ($scope !== '') {
                $ok = $conn->query(
                    'SELECT authors.ID FROM authors WHERE authors.ID = ' . (int)$row['ID']
                    . ' AND (' . $scope . ') LIMIT 1')->fetch_row();
                if (!$ok) {
                    return null;
                }
            }
        }
        return $row;
    }

    /**
     * Dữ liệu cho trang. Lương chỉ gửi cho người được phép xem lương — và lương của CHÍNH
     * MÌNH thì luôn được xem (tiền của mình), kể cả khi không có quyền xem lương người khác.
     *
     * @return array{status:string,user?:array,message?:string}
     */
    public static function get_profile(): array
    {
        $id  = (int)($_POST['id'] ?? 0);
        $row = self::viewable($id);
        if (!$row) {
            return ['status' => 'error', 'message' => 'You do not have permission to view this account.'];
        }
        $laToi = (int)$row['ID'] === self::my_id();

        $out = [
            'id'        => (int)$row['ID'],
            'username'  => (string)$row['username'],
            'email'     => (string)$row['email'],
            'avatar'    => (string)($row['avatar'] ?? ''),
            'role_name' => (string)($row['role_name'] ?? ''),
            'role_slug' => (string)($row['role_slug'] ?? ''),
            'team_name' => (string)($row['team_name'] ?? ''),
            'status'    => (int)$row['status'],
            'date'      => (string)$row['date'],
            'is_me'     => $laToi,
            // Sửa được hồ sơ này không — chỉ chính mình; người khác là chỉ đọc.
            'can_edit'  => $laToi,
        ];
        if ($laToi || Users::can_see_salary()) {
            $out['wage']      = formatCurrencyVND($row['wage']);
            $out['insurance'] = formatCurrencyVND($row['insurance']);
        }
        // API key là bí mật đăng nhập của extension -> CHỈ chủ tài khoản được thấy, không
        // ai khác, kể cả admin đang xem hồ sơ người ta.
        if ($laToi) {
            $out['api_key'] = (string)($row['key'] ?? '');
        }
        return ['status' => 'success', 'user' => $out];
    }

    /**
     * Đổi tên đăng nhập / email / ảnh đại diện của CHÍNH MÌNH.
     * Không nhận `id`: đường này chỉ có một đích duy nhất là người đang đăng nhập.
     */
    public static function save_profile(): array
    {
        if (!check_csrf()) {
            return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
        }
        $me = self::my_id();
        if ($me <= 0) {
            return ['status' => 'error', 'message' => 'You are not signed in.'];
        }
        $conn = db();

        $username = trim((string)($_POST['username'] ?? ''));
        $email    = trim((string)($_POST['email'] ?? ''));
        if ($username === '') {
            return ['status' => 'error', 'message' => 'Please enter a username.'];
        }
        if (mb_strlen($username) > 100 || mb_strlen($email) > 100) {
            return ['status' => 'error', 'message' => 'Username and email must be 100 characters or fewer.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'error', 'message' => 'Please enter a valid email.'];
        }
        $trung = $conn->execute_query(
            'SELECT ID FROM authors WHERE (username = ? OR email = ?) AND ID <> ? LIMIT 1',
            [$username, $email, $me])->fetch_row();
        if ($trung) {
            return ['status' => 'error', 'message' => 'Username or email is already in use.'];
        }

        $sets = ['username = ?', 'email = ?'];
        $args = [$username, $email];
        // Ảnh đại diện: dùng LẠI whitelist của User để hai đường ghi không lệch luật.
        if (Users::has_avatar_column() && array_key_exists('avatar', $_POST)) {
            $avatar = trim((string)$_POST['avatar']);
            if (!User::valid_avatar($avatar)) {
                return ['status' => 'error', 'message' => 'Invalid avatar path.'];
            }
            $sets[] = 'avatar = ?';
            $args[] = $avatar;
        }
        $args[] = $me;
        $conn->execute_query('UPDATE authors SET ' . implode(', ', $sets) . ' WHERE ID = ?', $args);

        // Tên hiển thị trên navbar lấy từ session -> cập nhật luôn, khỏi phải đăng nhập lại
        $_SESSION['auth']['user'] = $username;
        return ['status' => 'success', 'message' => 'Your profile has been updated.'];
    }

    /**
     * Đổi mật khẩu của CHÍNH MÌNH. Bắt buộc nhập mật khẩu hiện tại: nếu không, một phiên bị
     * chiếm (máy để quên, XSS) sẽ đổi được mật khẩu và khoá luôn chủ tài khoản ra ngoài.
     */
    public static function change_password(): array
    {
        if (!check_csrf()) {
            return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
        }
        $me = self::my_id();
        if ($me <= 0) {
            return ['status' => 'error', 'message' => 'You are not signed in.'];
        }
        $hienTai = (string)($_POST['current_password'] ?? '');
        $moi     = (string)($_POST['new_password'] ?? '');
        $nhacLai = (string)($_POST['confirm_password'] ?? '');

        if (strlen($moi) < 8) {
            return ['status' => 'error', 'message' => 'The new password must be at least 8 characters.'];
        }
        if ($moi !== $nhacLai) {
            return ['status' => 'error', 'message' => 'The two passwords do not match.'];
        }
        $conn = db();
        $row = $conn->execute_query('SELECT pass FROM authors WHERE ID = ? LIMIT 1', [$me])->fetch_assoc();
        if (!$row || !password_verify($hienTai, (string)$row['pass'])) {
            return ['status' => 'error', 'message' => 'Your current password is not correct.'];
        }
        if ($hienTai === $moi) {
            return ['status' => 'error', 'message' => 'The new password must be different from the current one.'];
        }
        $conn->execute_query('UPDATE authors SET pass = ? WHERE ID = ?',
            [password_hash($moi, PASSWORD_DEFAULT), $me]);
        // Đổi mật khẩu = thu hồi mọi thiết bị đã ghi nhớ. Nếu không, kẻ trộm cookie vẫn vào
        // được bằng token cũ và việc đổi mật khẩu thành vô nghĩa.
        $conn->execute_query('DELETE FROM author_remember_tokens WHERE author_id = ?', [$me]);
        return ['status' => 'success',
                'message' => 'Password changed. All remembered devices were signed out.'];
    }

    /**
     * Thiết bị đang được ghi nhớ đăng nhập (bảng `author_remember_tokens`).
     * CHỈ của chính mình — danh sách thiết bị của người khác không phải việc của ai cả.
     */
    public static function get_devices(): array
    {
        $me = self::my_id();
        if ($me <= 0) {
            return ['status' => 'error', 'message' => 'You are not signed in.'];
        }
        $rs = db()->execute_query(
            'SELECT id, created_at, expires_at, user_agent_hash
             FROM author_remember_tokens WHERE author_id = ?
             ORDER BY created_at DESC LIMIT ' . self::MAX_DEVICES, [$me]);
        $out = [];
        while ($r = $rs->fetch_assoc()) {
            $out[] = [
                'id'         => (int)$r['id'],
                'created_at' => (string)$r['created_at'],
                'expires_at' => (string)$r['expires_at'],
                // Chỉ có hash của User-Agent chứ không lưu chuỗi gốc -> không đoán được tên
                // trình duyệt. Hiện 8 ký tự đầu để người dùng phân biệt các dòng với nhau.
                'tag'        => substr((string)$r['user_agent_hash'], 0, 8),
                'is_current' => hash_equals((string)$r['user_agent_hash'], user_agent_fingerprint()),
            ];
        }
        return ['status' => 'success', 'devices' => $out];
    }

    /** Thu hồi 1 thiết bị đã ghi nhớ — chỉ thiết bị của chính mình. */
    public static function revoke_device(): array
    {
        if (!check_csrf()) {
            return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
        }
        $me = self::my_id();
        $id = (int)($_POST['id'] ?? 0);
        if ($me <= 0 || $id <= 0) {
            return ['status' => 'error', 'message' => 'Missing device.'];
        }
        // Ràng author_id vào câu DELETE: id gửi lên là dữ liệu người dùng, không tin.
        db()->execute_query('DELETE FROM author_remember_tokens WHERE id = ? AND author_id = ?',
            [$id, $me]);
        return db()->affected_rows > 0
            ? ['status' => 'success', 'message' => 'Device signed out.']
            : ['status' => 'error', 'message' => 'Device not found.'];
    }

    /**
     * Tạo lại API key của extension cho CHÍNH MÌNH.
     *
     * Có mặt vì dữ liệu thật đang có key dài 3–4 ký tự và cả key rỗng: `check_authors_key()`
     * chỉ so `key` + `email`, nên key 3 ký tự là đoán xong trong vài giây và đẩy được dữ liệu
     * dưới danh nghĩa người đó. 32 ký tự hex từ random_bytes() là mức tối thiểu tử tế.
     *
     * Đổi key làm extension đang chạy mất hiệu lực -> phía JS phải hỏi lại trước khi gọi.
     */
    public static function regenerate_key(): array
    {
        if (!check_csrf()) {
            return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
        }
        $me = self::my_id();
        if ($me <= 0) {
            return ['status' => 'error', 'message' => 'You are not signed in.'];
        }
        $key = bin2hex(random_bytes(16));
        db()->execute_query('UPDATE authors SET `key` = ? WHERE ID = ?', [$key, $me]);
        return ['status' => 'success', 'key' => $key,
                'message' => 'New API key generated. Update your extension with it.'];
    }

    /** Thu hồi TẤT CẢ thiết bị đã ghi nhớ của chính mình. */
    public static function revoke_all_devices(): array
    {
        if (!check_csrf()) {
            return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
        }
        $me = self::my_id();
        if ($me <= 0) {
            return ['status' => 'error', 'message' => 'You are not signed in.'];
        }
        db()->execute_query('DELETE FROM author_remember_tokens WHERE author_id = ?', [$me]);
        return ['status' => 'success', 'message' => 'All remembered devices were signed out.'];
    }
}
