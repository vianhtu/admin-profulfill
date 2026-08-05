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
        if (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
            return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
        }

        $file = $_FILES['file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
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

        return ['status' => 'success', 'logo' => $row['storage_path']];
    }

    /**
     * Vẽ lại ảnh về đúng khung 96x96 (giữ tỉ lệ, canh giữa, nền trong suốt) và
     * ghi đè lên chính file đó. Vẽ lại cũng loại bỏ dữ liệu lạ nhúng trong ảnh.
     */
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
            $current = $conn->query('SELECT ID, created_by FROM site WHERE ID = ' . $id . ' LIMIT 1')->fetch_assoc();
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

        $logo   = trim((string)($_POST['logo'] ?? ''));
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

        return ['status' => $isEdit ? 'updated' : 'inserted', 'id' => $newId];
    }
}
