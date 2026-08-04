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
        $stmt = $conn->prepare('SELECT ID, name, slug, logo, system_prompt, developer_prompt, custom_fields
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
        if (!checkRoles(['add', 'edit'], 'sites')) {
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
        $size = getimagesize($file['tmp_name']);
        if ($mime !== $allowed[$ext] || $size === false) {
            return ['status' => 'error', 'message' => 'This file is not a valid image.'];
        }
        if ($size[0] !== 96 || $size[1] !== 96) {
            return ['status' => 'error',
                'message' => sprintf('The logo must be exactly 96x96 pixels (this one is %dx%d).', $size[0], $size[1])];
        }

        $conn = db();
        $ids = handleFileUploads($conn, (int)($_SESSION['auth']['user_id'] ?? 0), $file, 'sites');
        if (empty($ids)) {
            return ['status' => 'error', 'message' => 'Failed to save the uploaded file.'];
        }

        // Lấy đường dẫn vừa lưu để ghi vào cột logo
        $row = $conn->query('SELECT storage_path FROM files WHERE ID = ' . (int)$ids[0])->fetch_assoc();
        if (!$row) {
            return ['status' => 'error', 'message' => 'Uploaded file not found.'];
        }
        return ['status' => 'success', 'logo' => $row['storage_path']];
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

        if (!checkRoles($isEdit ? 'edit' : 'add', 'sites')) {
            return ['status' => 'error',
                'message' => 'You do not have permission to ' . ($isEdit ? 'edit' : 'add') . ' sites.'];
        }
        if (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
            return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
        }
        if ($isEdit && !$conn->query('SELECT ID FROM site WHERE ID = ' . $id . ' LIMIT 1')->fetch_row()) {
            return ['status' => 'error', 'message' => 'Site not found.'];
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
            $stmt = $conn->prepare('INSERT INTO site (name, slug, logo, system_prompt, developer_prompt, custom_fields)
                VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('ssssss', $name, $slug, $logo, $sysP, $devP, $fieldsJson);
        }
        if (!$stmt->execute()) {
            return ['status' => 'error', 'message' => 'Save failed: ' . $conn->error];
        }
        $newId = $isEdit ? $id : (int)$conn->insert_id;
        $stmt->close();

        return ['status' => $isEdit ? 'updated' : 'inserted', 'id' => $newId];
    }
}
