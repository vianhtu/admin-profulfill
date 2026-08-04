<?php
/**
 * Product — thao tác trên MỘT sản phẩm (form Add/Edit, xem ảnh).
 * Cùng khuôn với class.order.php: phần danh sách/tập hợp nằm ở class.products.php.
 */
class Product
{

    public static function get_product_images(): array
{
    if (!checkRoles('view', 'products')) {
        return ['status' => 'error', 'message' => 'You do not have permission to view products.'];
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        return ['status' => 'error', 'message' => 'Missing product ID.'];
    }

    $conn = db();

    // Ngoài phạm vi dữ liệu (team/own) thì coi như không tồn tại
    if (empty(Products::check_products_ownership($conn, [$id]))) {
        return ['status' => 'error', 'message' => 'Product not found.'];
    }

    $stmt = $conn->prepare('SELECT title, images FROM posts WHERE ID = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return ['status' => 'error', 'message' => 'Product not found.'];
    }

    // images là JSON {main: url, images: [url...]}
    $imgs = json_decode($row['images']);
    $list = [];
    if ($imgs) {
        if (!empty($imgs->main)) {
            $list[] = $imgs->main;
        }
        if (!empty($imgs->images) && is_array($imgs->images)) {
            foreach ($imgs->images as $u) {
                if ($u && !in_array($u, $list, true)) {
                    $list[] = $u;
                }
            }
        }
    }

    return ['status' => 'success', 'title' => $row['title'], 'images' => $list];
}

/**
 * Lấy 1 sản phẩm để đổ vào form Edit. Trả null nếu không tồn tại hoặc
 * ngoài phạm vi dữ liệu của user hiện tại.
 */
    public static function get_product(int $id): ?array
{
    // Phải có quyền xem products; ID còn bị lọc theo phạm vi dữ liệu ở dưới
    if ($id <= 0 || !checkRoles('view', 'products')) {
        return null;
    }
    $conn = db();
    if (empty(Products::check_products_ownership($conn, [$id]))) {
        return null;
    }
    $stmt = $conn->prepare('SELECT p.*, st.name AS store_name, s.name AS site_name
        FROM posts p
        LEFT JOIN store st ON st.ID = p.store_id
        LEFT JOIN site s ON s.ID = p.site_id
        WHERE p.ID = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }

    $imgs = json_decode($row['images'] ?? '', true);
    $list = [];
    if (!empty($imgs['main'])) {
        $list[] = $imgs['main'];
    }
    foreach ($imgs['images'] ?? [] as $u) {
        if ($u && !in_array($u, $list, true)) {
            $list[] = $u;
        }
    }
    $row['image_list'] = $list;
    $meta = json_decode($row['metadata'] ?? '', true);
    $row['tags'] = $meta['tags'] ?? [];
    return $row;
}

/**
 * Lọc HTML của trình soạn thảo: chỉ giữ thẻ định dạng, bỏ script/iframe và
 * mọi thuộc tính sự kiện (onclick, onerror...) cùng javascript: URL.
 */
    private static function sanitize_html(string $html): string
    {
        $allowed = '<p><br><b><strong><i><em><u><s><a><ul><ol><li><h1><h2><h3><h4><h5><h6>'
                 . '<blockquote><pre><code><span><div><sub><sup>';
        $clean = strip_tags($html, $allowed);
        $clean = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean);
        $clean = preg_replace('/(href|src)\s*=\s*("|\')?\s*javascript:[^"\'>]*("|\')?/i', '', $clean);
        return trim((string)$clean);
    }

/**
 * Xác định author_id được phép gán cho sản phẩm.
 * - Admin: gán cho bất kỳ author nào.
 * - Manager: chỉ trong team của mình.
 * - Còn lại: luôn là chính họ (bỏ qua giá trị gửi lên).
 * Trả 0 nếu lựa chọn không hợp lệ.
 */
    private static function resolve_author_id(mysqli $conn, int $requested): int
    {
        $self = (int)($_SESSION['auth']['user_id'] ?? 0);
        if ($requested <= 0 || $requested === $self) {
            return $self;
        }
        if (is_admin()) {
            $res = $conn->query("SELECT ID FROM authors WHERE ID = $requested LIMIT 1");
            return $res && $res->fetch_row() ? $requested : 0;
        }
        if (is_manager()) {
            $team = (int)($_SESSION['auth']['team'] ?? 0);
            $res = $conn->query("SELECT ID FROM authors WHERE ID = $requested AND team_id = $team LIMIT 1");
            return $res && $res->fetch_row() ? $requested : 0;
        }
        return $self;
    }

/**
 * Thêm mới / cập nhật 1 sản phẩm từ form Add-Edit.
 */
    public static function save_product(): array
{
    $conn = db();
    $id = (int)($_POST['id'] ?? 0);
    $isEdit = $id > 0;

    if (!checkRoles($isEdit ? 'edit' : 'add', 'products')) {
        return ['status' => 'error', 'message' => 'You do not have permission to ' . ($isEdit ? 'edit' : 'add') . ' products.'];
    }
    if (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
        return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
    }
    // Sửa: sản phẩm phải nằm trong phạm vi dữ liệu của user
    if ($isEdit && empty(Products::check_products_ownership($conn, [$id]))) {
        return ['status' => 'error', 'message' => 'Product not found or not in your scope.'];
    }

    $title   = trim((string)($_POST['title'] ?? ''));
    $sku     = trim((string)($_POST['sku'] ?? ''));
    // Description là HTML từ Quill — lọc thẻ/thuộc tính nguy hiểm trước khi lưu
    $desc    = self::sanitize_html((string)($_POST['description'] ?? ''));
    $status  = (string)($_POST['status'] ?? 'pending');
    $badge   = trim((string)($_POST['badge'] ?? ''));
    $typeId  = (int)($_POST['type_id'] ?? 0);
    $siteId  = (int)($_POST['site_id'] ?? 0);
    $storeId = (int)($_POST['store_id'] ?? 0);
    $images  = array_values(array_filter(array_map('trim', (array)json_decode($_POST['images'] ?? '[]', true))));
    $tags    = array_values(array_filter(array_map('trim', (array)json_decode($_POST['tags'] ?? '[]', true))));

    if ($title === '' || $sku === '' || $typeId <= 0 || $siteId <= 0 || $storeId <= 0 || empty($images)) {
        return ['status' => 'error', 'message' => 'Please fill in title, SKU, category, site, store and at least one image.'];
    }
    if (!in_array($status, ['pending', 'schedule', 'listed', 'inactive', 'trademark'], true)) {
        return ['status' => 'error', 'message' => 'Invalid status.'];
    }

    // Category / Store phải thuộc phạm vi team (dùng chung cũng tính)
    $team = get_current_team_scope_id();
    if ($team > 0) {
        $ok = $conn->query("SELECT 1 FROM `type` WHERE ID = $typeId AND team_id = $team LIMIT 1")->fetch_row();
        if (!$ok) {
            return ['status' => 'error', 'message' => 'Category is not available for your team.'];
        }
        $ok = $conn->query("SELECT 1 FROM store_teams WHERE store_id = $storeId AND team_id = $team LIMIT 1")->fetch_row();
        if (!$ok) {
            return ['status' => 'error', 'message' => 'Store is not available for your team.'];
        }
    }

    // SKU là UNIQUE — chặn trùng với sản phẩm khác
    $stmt = $conn->prepare('SELECT ID FROM posts WHERE sku = ? AND ID <> ? LIMIT 1');
    $stmt->bind_param('si', $sku, $id);
    $stmt->execute();
    if ($stmt->get_result()->fetch_row()) {
        return ['status' => 'error', 'message' => 'This SKU already exists.'];
    }
    $stmt->close();

    $imagesJson = json_encode(['main' => $images[0], 'images' => array_slice($images, 1)]);
    $metaJson   = json_encode(['tags' => $tags]);
    $badgeVal   = $badge !== '' ? $badge : null;

    // Chủ sở hữu sản phẩm: chỉ admin/manager mới được gán cho người khác,
    // và người được gán phải nằm trong phạm vi quyền của họ.
    $authorId = self::resolve_author_id($conn, (int)($_POST['author_id'] ?? 0));
    if ($authorId <= 0) {
        return ['status' => 'error', 'message' => 'Selected manager is not in your team.'];
    }

    if ($isEdit) {
        // variantdata do extension ghi, form không đụng tới nên giữ nguyên khi sửa
        $stmt = $conn->prepare('UPDATE posts SET title = ?, description = ?, sku = ?, status = ?, badge = ?,
            type_id = ?, site_id = ?, store_id = ?, author_id = ?, images = ?, metadata = ?,
            updated_at = NOW() WHERE ID = ?');
        // title,desc,sku,status,badge | type,site,store,author | images,metadata | id
        $stmt->bind_param('sssssiiiissi', $title, $desc, $sku, $status, $badgeVal,
            $typeId, $siteId, $storeId, $authorId, $imagesJson, $metaJson, $id);
    } else {
        $stmt = $conn->prepare('INSERT INTO posts (author_id, date, title, description, status, sku, images,
            type_id, site_id, store_id, badge, metadata) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        // author | title,desc,status,sku,images | type,site,store | badge,metadata
        $stmt->bind_param('isssssiiiss', $authorId, $title, $desc, $status, $sku, $imagesJson,
            $typeId, $siteId, $storeId, $badgeVal, $metaJson);
    }

    if (!$stmt->execute()) {
        return ['status' => 'error', 'message' => 'Save failed: ' . $conn->error];
    }
    $newId = $isEdit ? $id : (int)$conn->insert_id;
    $stmt->close();

    return ['status' => $isEdit ? 'updated' : 'inserted', 'id' => $newId];
}
}
