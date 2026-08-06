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
    /** Thư mục lưu avatar dưới uploads/ (nginx chỉ phục vụ tĩnh png/jpg trong này). */
    private const AVATAR_DIR = 'avatars';
    /** Khung ảnh avatar sau khi chuẩn hóa. */
    private const AVATAR_BOX = 96;

    /**
     * Giá trị hợp lệ của cột `avatar`: rỗng, hoặc đúng file ảnh trong uploads/avatars/.
     * Whitelist khi LƯU để không ai nhét đường dẫn tùy ý (path traversal / trỏ ra ngoài).
     */
    public static function valid_avatar(string $path): bool
    {
        if ($path === '') {
            return true;
        }
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            return false;
        }
        return (bool)preg_match('#^uploads/' . self::AVATAR_DIR . '/[A-Za-z0-9/_-]+\.(png|jpe?g)$#', $path);
    }

    /**
     * Upload avatar cho user — dùng lại handleFileUploads() và bộ kiểm tra ảnh giống
     * hệt logo site (đuôi + MIME thật + getimagesize + chặn pixel-flood + vẽ lại ảnh).
     *
     * POST: id (0 = đang thêm mới), file. Quyền: phải sửa được đúng user đó (hoặc có
     * quyền thêm khi id = 0).
     *
     * @return array{status:string,avatar?:string,message?:string}
     */
    public static function upload_avatar(): array
    {
        // Body POST vượt post_max_size -> PHP xóa sạch $_POST (kể cả csrf_token) và $_FILES.
        // Phải nhận biết TRƯỚC khi kiểm CSRF, nếu không sẽ báo nhầm "Invalid CSRF token".
        $contentLen = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        $postMax    = ini_bytes((string)ini_get('post_max_size'));
        if (empty($_POST) && $contentLen > 0 && $postMax > 0 && $contentLen > $postMax) {
            return ['status' => 'error', 'message' => 'The avatar must be 2 MB or smaller.'];
        }
        if (!check_csrf()) {
            return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
        }

        $conn = db();
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $row = $conn->execute_query(
                'SELECT team_id, level FROM authors WHERE ID = ? LIMIT 1', [$id])->fetch_assoc();
            if (!$row) {
                return ['status' => 'error', 'message' => 'User not found.'];
            }
            if (!Users::can_edit_row((int)$row['team_id'], (int)$row['level'], $conn)) {
                return ['status' => 'error', 'message' => 'You do not have permission to edit this user.'];
            }
        } elseif (!Users::can_add()) {
            return ['status' => 'error', 'message' => 'You do not have permission to manage users.'];
        }

        $file = $_FILES['file'] ?? null;
        $err  = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            return ['status' => 'error', 'message' => 'The avatar must be 2 MB or smaller.'];
        }
        if (!$file || $err !== UPLOAD_ERR_OK) {
            return ['status' => 'error', 'message' => 'No file uploaded.'];
        }
        if ($file['size'] > 2 * 1024 * 1024) {
            return ['status' => 'error', 'message' => 'The avatar must be 2 MB or smaller.'];
        }

        // Chỉ nhận PNG/JPG — kiểm cả đuôi, MIME thật và getimagesize để chặn file khác
        // đổi đuôi thành ảnh
        $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!isset($allowed[$ext])) {
            return ['status' => 'error', 'message' => 'Only PNG or JPG images are allowed.'];
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $size = @getimagesize($file['tmp_name']);
        if ($mime !== $allowed[$ext] || $size === false) {
            return ['status' => 'error', 'message' => 'This file is not a valid image.'];
        }
        // Chặn "pixel flood": ảnh nhỏ trên đĩa nhưng khai kích thước khổng lồ ->
        // imagecreatefrompng cấp phát w*h*4 byte làm cạn RAM (DoS).
        if ($size[0] > 4000 || $size[1] > 4000) {
            return ['status' => 'error',
                'message' => sprintf('Image dimensions are too large (%dx%d, max 4000x4000).', $size[0], $size[1])];
        }
        $hasGd = function_exists('imagecreatetruecolor');
        if (!$hasGd && ($size[0] !== self::AVATAR_BOX || $size[1] !== self::AVATAR_BOX)) {
            return ['status' => 'error',
                'message' => sprintf('The avatar must be exactly %dx%d pixels (this one is %dx%d).',
                    self::AVATAR_BOX, self::AVATAR_BOX, $size[0], $size[1])];
        }

        $ids = handleFileUploads($conn, (int)($_SESSION['auth']['user_id'] ?? 0), $file, self::AVATAR_DIR);
        if (empty($ids)) {
            return ['status' => 'error', 'message' => 'Failed to save the uploaded file.'];
        }
        $fileId = (int)$ids[0];
        $row = $conn->execute_query('SELECT storage_path FROM files WHERE ID = ?', [$fileId])->fetch_assoc();
        if (!$row) {
            return ['status' => 'error', 'message' => 'Uploaded file not found.'];
        }

        // Vẽ lại về khung vuông: vừa chuẩn hóa kích thước, vừa loại dữ liệu lạ nhúng trong ảnh
        $path = ROOT_DIR . '/' . $row['storage_path'];
        if ($hasGd && resize_image_square($path, $mime, self::AVATAR_BOX)) {
            $conn->execute_query('UPDATE files SET file_size = ?, checksum = ? WHERE ID = ?',
                [(int)filesize($path), hash_file('sha256', $path), $fileId]);
        }

        return ['status' => 'success', 'avatar' => $row['storage_path']];
    }

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
                'SELECT ID, team_id, level, status FROM authors WHERE ID = ? LIMIT 1', [$id])->fetch_assoc();
            if (!$current) {
                return ['status' => 'error', 'message' => 'User not found.'];
            }
            if (!Users::can_edit_row((int)$current['team_id'], (int)$current['level'], $conn)) {
                return ['status' => 'error', 'message' => 'You do not have permission to edit this user.'];
            }
            // Không tự đổi quyền/team/trạng thái của chính mình qua màn hình này.
            // Trạng thái nằm trong danh sách vì đặt Inactive cho chính mình là tự khóa
            // ngay lập tức — admin cuối cùng làm vậy thì kẹt cả hệ thống.
            if ((int)$current['ID'] === (int)($_SESSION['auth']['user_id'] ?? 0)
                && ((int)$current['level'] !== $level
                    || (int)$current['team_id'] !== $teamId
                    || (int)$current['status'] !== $status)) {
                return ['status' => 'error',
                    'message' => 'You cannot change your own role, team or status.'];
            }
        }

        // --- Ép các tham số vượt quyền về giá trị hợp lệ (không báo lỗi, chỉ bỏ qua) ---
        if (!is_admin()) {
            // Non-admin luôn thao tác trong team của chính mình
            $teamId = (int)($_SESSION['auth']['team'] ?? 0);
        }
        // Chỉ gán được role có cấp THẤP HƠN mình (chốt 06/08/2026) — không tạo/nâng ai lên
        // NGANG CẤP với mình, vì người ngang cấp lại quản được người khác cùng vai.
        if (!in_array($level, Users::manageable_level_ids($conn), true)) {
            return ['status' => 'error',
                    'message' => 'You can only assign a role below your own level.'];
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

        // Avatar: chỉ nhận đường dẫn nằm trong uploads/avatars (whitelist khi LƯU).
        // Bỏ qua hoàn toàn khi DB chưa có cột — deploy code trước, đổi schema sau.
        $avatar = null;
        if (Users::has_avatar_column() && array_key_exists('avatar', $_POST)) {
            $avatar = trim((string)$_POST['avatar']);
            if (!self::valid_avatar($avatar)) {
                return ['status' => 'error', 'message' => 'Invalid avatar image.'];
            }
        }

        try {
            if ($isNew) {
                // `key` (API extension) tự sinh, không nhận từ form
                $cols = 'team_id, email, status, username, pass, `key`, level, wage, insurance, date';
                $vals = '?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()';
                $args = [$teamId, $email, $status, $username, password_hash($password, PASSWORD_DEFAULT),
                         bin2hex(random_bytes(16)), $level, $wage ?? 0, $insurance ?? 0];
                if ($avatar !== null) {
                    $cols .= ', avatar';
                    $vals .= ', ?';
                    $args[] = $avatar;
                }
                $conn->execute_query("INSERT INTO authors ($cols) VALUES ($vals)", $args);
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
            if ($avatar !== null) {
                $sets[] = 'avatar = ?';
                $args[] = $avatar;
            }
            $args[] = $id;
            $conn->execute_query('UPDATE authors SET ' . implode(', ', $sets) . ' WHERE ID = ?', $args);

            // Đổi mật khẩu -> thu hồi mọi token "nhớ đăng nhập" cũ của user đó
            if ($password !== '') {
                $conn->execute_query('DELETE FROM author_remember_tokens WHERE author_id = ?', [$id]);
            }

            // CHUYỂN TEAM: sản phẩm đi theo người, nhưng ràng buộc chỉ đúng với team cũ
            // (liên kết account, store riêng) phải gỡ; token bị thu hồi để user đăng nhập
            // lại với team mới (phiên đang mở cũng bị đá ra bởi access_block_reason()).
            $oldTeam = (int)$current['team_id'];
            $moved = [];
            if ($oldTeam !== $teamId) {
                $moved = Users::cleanup_after_move($conn, $id, $oldTeam);
            }
            return ['status' => 'success', 'id' => $id] + ($moved ? ['moved' => $moved] : []);
        } catch (\mysqli_sql_exception $e) {
            return ['status' => 'error', 'message' => 'Save failed: ' . $e->getMessage()];
        }
    }
}
