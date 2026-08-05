<?php
/**
 * Site — thao tác trên MỘT site của bảng `site` (form Add/Edit).
 * Cùng khuôn với class.product.php / class.category.php.
 */
class Site
{
    /**
     * Lấy 1 site để đổ vào form Edit. Trả null nếu không có quyền xem hoặc không tồn tại.
     */
    public static function get_site(int $id): ?array
    {
        if ($id <= 0 || !checkRoles('view', 'sites')) {
            return null;
        }
        $conn = db();
        $stmt = $conn->prepare('SELECT ID, name, slug, logo, system_prompt, developer_prompt, custom_fields, created_by
            FROM site WHERE ID = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }

        // custom_fields: [{text, value}] — dùng làm giá trị mặc định cho form Store
        $fields = json_decode($row['custom_fields'] ?? '', true);
        $row['fields'] = [];
        foreach ((array)$fields as $f) {
            $row['fields'][] = [
                'text'  => (string)($f['text'] ?? ''),
                'value' => (string)($f['value'] ?? ''),
            ];
        }
        return $row;
    }

    /**
     * Upload logo cho site — dùng lại handleFileUploads() sẵn có của dự án
     * (lưu vào uploads/sites/, ghi bản ghi vào bảng `files`, đặt tên UUID).
     *
     * Cột `logo` chấp nhận 2 dạng cùng tồn tại:
     *  - tên file trần (vd. etsy_logo.png) -> ảnh có sẵn trong assets/img/icons/brands/
     *  - đường dẫn có dấu / (uploads/...)  -> ảnh do người dùng upload
     *
     * @return array{status:string,logo?:string,message?:string}
     */
    public static function upload_logo(): array
    {
        // Đủ quyền thêm là upload được logo cho site mình đang tạo; sửa logo site cũ
        // thì chỉ admin (can_manage), nhưng non-admin vốn không mở được form Edit.
        if (!Sites::can_add() && !Sites::can_manage()) {
            return ['status' => 'error', 'message' => 'You do not have permission to upload site logos.'];
        }

        // Body POST vượt post_max_size -> PHP xóa sạch $_POST (kể cả csrf_token) và $_FILES.
        // Phải nhận biết TRƯỚC khi kiểm CSRF, nếu không sẽ báo nhầm "Invalid CSRF token"
        // cho một file chỉ đơn giản là quá lớn.
        $contentLen = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        $postMax    = self::ini_bytes((string)ini_get('post_max_size'));
        if (empty($_POST) && $contentLen > 0 && $postMax > 0 && $contentLen > $postMax) {
            return ['status' => 'error', 'message' => 'The logo must be 2 MB or smaller.'];
        }

        if (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
            return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
        }

        $file = $_FILES['file'] ?? null;
        $err  = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        // File > upload_max_filesize (2M) -> PHP đặt lỗi INI_SIZE, $_FILES rỗng phần dữ liệu
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            return ['status' => 'error', 'message' => 'The logo must be 2 MB or smaller.'];
        }
        if (!$file || $err !== UPLOAD_ERR_OK) {
            return ['status' => 'error', 'message' => 'No file uploaded.'];
        }
        if ($file['size'] > 2 * 1024 * 1024) {
            return ['status' => 'error', 'message' => 'The logo must be 2 MB or smaller.'];
        }

        // Logo chỉ nhận PNG/JPG, đúng khung 96x96 — kiểm cả đuôi, MIME thật và
        // getimagesize để chặn file khác đổi đuôi thành ảnh
        $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!isset($allowed[$ext])) {
            return ['status' => 'error', 'message' => 'Only PNG or JPG images are allowed.'];
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        // @ vì file không phải ảnh sẽ sinh Notice; trường hợp đó đã xử lý bằng false bên dưới
        $size = @getimagesize($file['tmp_name']);
        if ($mime !== $allowed[$ext] || $size === false) {
            return ['status' => 'error', 'message' => 'This file is not a valid image.'];
        }
        // Chặn "pixel flood": ảnh nhỏ trên đĩa nhưng khai kích thước khổng lồ -> imagecreatefrompng
        // cấp phát w*h*4 byte làm cạn RAM (DoS). Logo nguồn 96px nên 4000px là quá dư.
        if ($size[0] > 4000 || $size[1] > 4000) {
            return ['status' => 'error',
                'message' => sprintf('Image dimensions are too large (%dx%d, max 4000x4000).', $size[0], $size[1])];
        }
        // Không có GD thì không co ảnh được -> bắt buộc đúng khung
        $hasGd = function_exists('imagecreatetruecolor');
        if (!$hasGd && ($size[0] !== 96 || $size[1] !== 96)) {
            return ['status' => 'error',
                'message' => sprintf('The logo must be exactly 96x96 pixels (this one is %dx%d).', $size[0], $size[1])];
        }

        $conn = db();
        $ids = handleFileUploads($conn, (int)($_SESSION['auth']['user_id'] ?? 0), $file, 'sites');
        if (empty($ids)) {
            return ['status' => 'error', 'message' => 'Failed to save the uploaded file.'];
        }

        // Lấy đường dẫn vừa lưu để ghi vào cột logo
        $fileId = (int)$ids[0];
        $row = $conn->query('SELECT storage_path FROM files WHERE ID = ' . $fileId)->fetch_assoc();
        if (!$row) {
            return ['status' => 'error', 'message' => 'Uploaded file not found.'];
        }

        // Chuẩn hóa về khung 96x96 ngay tại chỗ, rồi cập nhật lại kích thước/checksum
        // trong bảng files cho khớp với file thật
        $path = ROOT_DIR . '/' . $row['storage_path'];
        if ($hasGd && self::resize_logo($path, $mime)) {
            $stmt = $conn->prepare('UPDATE files SET file_size = ?, checksum = ? WHERE ID = ?');
            $newSize = (int)filesize($path);
            $newSum  = hash_file('sha256', $path);
            $stmt->bind_param('isi', $newSize, $newSum, $fileId);
            $stmt->execute();
            $stmt->close();
        }

        // Người dùng upload đè khi CHƯA lưu form: dọn luôn file vừa bị thay (nếu là file
        // mồ côi chưa gắn với site nào). delete_logo_file tự bỏ qua nếu còn site đang dùng.
        $replace = trim((string)($_POST['replace'] ?? ''));
        if ($replace !== '' && $replace !== $row['storage_path']) {
            self::delete_logo_file($conn, $replace);
        }

        return ['status' => 'success', 'logo' => $row['storage_path']];
    }

    /**
     * Vẽ lại ảnh về đúng khung 96x96 (giữ tỉ lệ, canh giữa, nền trong suốt) và
     * ghi đè lên chính file đó. Vẽ lại cũng loại bỏ dữ liệu lạ nhúng trong ảnh.
     */
    /** Đổi giá trị ini kiểu "8M"/"2K"/"1G" thành số byte. */
    private static function ini_bytes(string $val): int
    {
        $val = trim($val);
        if ($val === '') {
            return 0;
        }
        $num = (int)$val;
        return match (strtolower(substr($val, -1))) {
            'g'     => $num * 1024 * 1024 * 1024,
            'm'     => $num * 1024 * 1024,
            'k'     => $num * 1024,
            default => $num,
        };
    }

    private static function resize_logo(string $path, string $mime): bool
    {
        $img = $mime === 'image/png' ? @imagecreatefrompng($path) : @imagecreatefromjpeg($path);
        if (!$img) {
            return false;
        }
        $box = 96;
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min($box / $w, $box / $h);
        $newW = max(1, (int)round($w * $scale));
        $newH = max(1, (int)round($h * $scale));

        $canvas = imagecreatetruecolor($box, $box);
        if ($mime === 'image/png') {
            // PNG: giữ nền trong suốt
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
            imagealphablending($canvas, true);
        } else {
            // JPG không có kênh alpha — nền trong suốt sẽ ra đen, nên tô trắng trước
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        }
        imagecopyresampled($canvas, $img, (int)(($box - $newW) / 2), (int)(($box - $newH) / 2),
            0, 0, $newW, $newH, $w, $h);

        // Ghi lại đúng định dạng gốc để nội dung khớp với đuôi file
        $ok = $mime === 'image/png' ? imagepng($canvas, $path) : imagejpeg($canvas, $path, 90);
        imagedestroy($img);
        imagedestroy($canvas);
        return $ok;
    }

    /**
     * Chuẩn hóa slug: chữ thường, chỉ giữ chữ/số, ngăn cách bằng dấu gạch ngang.
     */
    private static function make_slug(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim((string)$slug, '-');
    }

    /**
     * Thêm mới / cập nhật 1 site.
     *
     * @return array{status:string,id?:int,message?:string}
     */
    public static function save_site(): array
    {
        $conn = db();
        $id = (int)($_POST['id'] ?? 0);
        $isEdit = $id > 0;

        if (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
            return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
        }
        // Thêm mới: theo role add. Sửa: admin, hoặc chính người đã thêm site đó.
        if ($isEdit) {
            $current = $conn->query('SELECT ID, created_by, logo FROM site WHERE ID = ' . $id . ' LIMIT 1')->fetch_assoc();
            if (!$current) {
                return ['status' => 'error', 'message' => 'Site not found.'];
            }
            if (!Sites::can_edit_row((int)$current['created_by'])) {
                return ['status' => 'error',
                    'message' => 'Sites are shared by the whole system; you can only change a site you added yourself.'];
            }
        } elseif (!Sites::can_add()) {
            return ['status' => 'error', 'message' => 'You do not have permission to add sites.'];
        }

        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            return ['status' => 'error', 'message' => 'Please enter the site name.'];
        }
        // Slug bỏ trống thì tự sinh từ tên
        $slug = self::make_slug((string)($_POST['slug'] ?? '')) ?: self::make_slug($name);
        if ($slug === '') {
            return ['status' => 'error', 'message' => 'Please enter a valid slug.'];
        }

        // Logo CHỈ được là đường dẫn upload (uploads/sites/...png|jpg) hoặc tên icon dựng sẵn.
        // Lưu thô sẽ bị stored XSS vì trang danh sách nhét thẳng logo vào thuộc tính <img>.
        $logo = trim((string)($_POST['logo'] ?? ''));
        if ($logo !== ''
            && !preg_match('#^uploads/sites/[A-Za-z0-9/_.-]+\.(?:png|jpe?g)$#', $logo)
            && !preg_match('#^[A-Za-z0-9_.-]+\.(?:png|jpe?g|svg)$#', $logo)) {
            return ['status' => 'error', 'message' => 'Invalid logo path.'];
        }
        $sysP   = trim((string)($_POST['system_prompt'] ?? ''));
        $devP   = trim((string)($_POST['developer_prompt'] ?? ''));

        // custom_fields: bỏ dòng trống, cột custom_fields có CHECK json_valid()
        $fields = [];
        foreach ((array)json_decode($_POST['fields'] ?? '[]', true) as $f) {
            $text  = trim((string)($f['text'] ?? ''));
            $value = trim((string)($f['value'] ?? ''));
            if ($text !== '') {
                $fields[] = ['text' => $text, 'value' => $value];
            }
        }
        $fieldsJson = $fields ? json_encode($fields) : null;

        // slug là UNIQUE trên toàn bảng
        $stmt = $conn->prepare('SELECT ID FROM site WHERE slug = ? AND ID <> ? LIMIT 1');
        $stmt->bind_param('si', $slug, $id);
        $stmt->execute();
        if ($stmt->get_result()->fetch_row()) {
            $stmt->close();
            return ['status' => 'error', 'message' => 'This slug is already used by another site.'];
        }
        $stmt->close();

        if ($isEdit) {
            $stmt = $conn->prepare('UPDATE site SET name = ?, slug = ?, logo = ?, system_prompt = ?,
                developer_prompt = ?, custom_fields = ? WHERE ID = ?');
            $stmt->bind_param('ssssssi', $name, $slug, $logo, $sysP, $devP, $fieldsJson, $id);
        } else {
            // Ghi lại người thêm để sau này chính họ sửa được site đó
            $uid = (int)($_SESSION['auth']['user_id'] ?? 0);
            $stmt = $conn->prepare('INSERT INTO site (name, slug, logo, system_prompt, developer_prompt, custom_fields, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('ssssssi', $name, $slug, $logo, $sysP, $devP, $fieldsJson, $uid);
        }
        if (!$stmt->execute()) {
            return ['status' => 'error', 'message' => 'Save failed: ' . $conn->error];
        }
        $newId = $isEdit ? $id : (int)$conn->insert_id;
        $stmt->close();

        // Thay logo -> xóa file logo cũ (chỉ khi khác logo mới và không site nào khác còn dùng)
        if ($isEdit) {
            $oldLogo = (string)($current['logo'] ?? '');
            if ($oldLogo !== '' && $oldLogo !== $logo) {
                self::delete_logo_file($conn, $oldLogo);
            }
        }

        return ['status' => $isEdit ? 'updated' : 'inserted', 'id' => $newId];
    }

    /**
     * Xóa 1 file logo do người dùng tải lên (uploads/sites/...) khỏi đĩa và bảng `files`.
     * Bỏ qua an toàn nếu: rỗng, là icon dựng sẵn (assets/img/icons/brands, không có '/'),
     * hoặc vẫn còn site khác đang dùng đường dẫn đó.
     */
    private static function delete_logo_file(mysqli $conn, string $path): void
    {
        $path = trim($path);
        // Chỉ đụng tới file người dùng upload; tên file trần là icon dựng sẵn -> giữ nguyên.
        // CHẶN path traversal: $path đến từ input người dùng ($_POST['replace']), nếu chứa
        // '..' hoặc ký tự null có thể thoát khỏi uploads/sites để xóa file tùy ý (config.php...).
        if ($path === '' || !str_starts_with($path, 'uploads/sites/')
            || str_contains($path, '..') || str_contains($path, "\0")) {
            return;
        }
        // Còn site khác trỏ tới đúng file này thì không xóa (tránh làm hỏng logo của họ)
        $stmt = $conn->prepare('SELECT 1 FROM site WHERE logo = ? LIMIT 1');
        $stmt->bind_param('s', $path);
        $stmt->execute();
        $stillUsed = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        if ($stillUsed) {
            return;
        }

        // Phòng thủ theo lớp: xác nhận file thật NẰM TRONG uploads/sites sau khi giải symlink
        $baseDir  = (defined('ROOT_DIR') ? ROOT_DIR : __DIR__ . '/..') . '/uploads/sites';
        $baseReal = realpath($baseDir);
        $full     = realpath((defined('ROOT_DIR') ? ROOT_DIR : __DIR__ . '/..') . '/' . $path);
        if ($baseReal === false || $full === false
            || !str_starts_with($full, $baseReal . DIRECTORY_SEPARATOR)) {
            return;
        }
        if (is_file($full)) {
            @unlink($full);
        }
        // Dọn bản ghi trong bảng files
        $stmt = $conn->prepare('DELETE FROM files WHERE storage_path = ?');
        $stmt->bind_param('s', $path);
        $stmt->execute();
        $stmt->close();
    }
}
