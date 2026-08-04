<?php
/**
 * Products — toàn bộ nghiệp vụ trang Products (bảng, filter, thống kê,
 * thêm/sửa/xóa, phân quyền dữ liệu theo team).
 * Cùng khuôn với class.orders.php: method static, gọi từ ajax.php.
 */
class Products
{


/**
 * Chạy câu COUNT và cache kết quả trong session theo TTL.
 * Dùng cho các COUNT nặng chạy lại liên tục theo mỗi lần draw DataTables.
 */
    private static function get_cached_count(mysqli $conn, string $sql, int $ttl = 60): int {
    $key = md5($sql);
    $c = $_SESSION['count_cache'][$key] ?? null;
    if ($c !== null && (time() - $c['t']) < $ttl) {
        return $c['v'];
    }
    $res = $conn->query($sql);
    $v = $res ? (int)($res->fetch_assoc()['cnt'] ?? 0) : 0;
    // Giữ cache gọn, tránh session phình to theo số tổ hợp filter
    if (count($_SESSION['count_cache'] ?? []) > 50) {
        $_SESSION['count_cache'] = array_slice($_SESSION['count_cache'], -25, null, true);
    }
    $_SESSION['count_cache'][$key] = ['t' => time(), 'v' => $v];
    return $v;
}

/**
 * Điều kiện phân quyền dữ liệu cho bảng posts.
 * - Admin: toàn hệ thống, mọi team (không điều kiện).
 * - Manager: toàn bộ team của mình.
 * - Mọi level còn lại: chỉ sản phẩm của chính user đó.
 * Trả về [joinSql, whereSql] để gắn vào query.
 */
    private static function get_base_auth_conditions(string $postsAlias = 'posts'): array {
    if (is_admin()) {
        return ['', ''];
    }
    if (is_manager()) {
        $team = (int)($_SESSION['auth']['team'] ?? 0);
        return ["INNER JOIN authors scope_a ON scope_a.ID = $postsAlias.author_id", "scope_a.team_id = $team"];
    }
    return ['', "$postsAlias.author_id = " . (int)($_SESSION['auth']['user_id'] ?? 0)];
}

/**
 * Lọc danh sách post ID về đúng phạm vi dữ liệu của user hiện tại.
 * Dùng trước mọi thao tác ghi/xóa hàng loạt để chặn ID ngoài quyền.
 */
    public static function check_products_ownership(mysqli $conn, array $ids): array {
    if (is_admin() || empty($ids)) {
        return $ids;
    }
    [$join, $where] = self::get_base_auth_conditions();
    $idsStr = implode(',', array_map('intval', $ids));
    $res = $conn->query("SELECT posts.ID FROM posts $join WHERE posts.ID IN ($idsStr)" . ($where !== '' ? " AND $where" : ''));
    $allowed = [];
    while ($row = $res->fetch_row()) {
        $allowed[] = (int)$row[0];
    }
    return $allowed;
}

    public static function get_products_statistic(): ?array {
	// Không có quyền xem thì trả 0, không chạy query
	if (!checkRoles('view', 'products')) {
		return ['total_items' => 0, 'pending_items' => 0, 'author_items' => 0,
			'total_this_month' => 0, 'pending_this_month' => 0, 'author_this_month' => 0];
	}
	// Query này quét full bảng posts (~1s ở 1M dòng) — cache 5 phút theo user
	$cacheKey = 'products_stats_' . ($_SESSION['auth']['user_id'] ?? 0);
	$c = $_SESSION[$cacheKey] ?? null;
	if ($c !== null && (time() - $c['t']) < 300) {
		return $c['data'];
	}
	// Cột phải qualify posts.* vì scope join thêm bảng authors (trùng tên status/date)
	$sql = "SELECT
    -- Tổng số bài viết
    COUNT(*) AS total_items,
    -- Tổng số bài viết đang chờ duyệt
    COUNT(CASE WHEN posts.status = 'pending' THEN 1 END) AS pending_items,
    -- Tổng số bài viết của tác giả hiện tại
    COUNT(CASE WHEN posts.author_id = ? THEN 1 END) AS author_items,
    -- Tổng số bài viết trong tháng hiện tại
    COUNT(CASE WHEN MONTH(posts.date) = MONTH(CURRENT_DATE()) AND YEAR(posts.date) = YEAR(CURRENT_DATE()) THEN 1 END) AS total_this_month,
    -- Tổng số bài viết đang chờ duyệt trong tháng hiện tại
    COUNT(CASE WHEN posts.status = 'pending' AND MONTH(posts.date) = MONTH(CURRENT_DATE()) AND YEAR(posts.date) = YEAR(CURRENT_DATE()) THEN 1 END) AS pending_this_month,
    -- Tổng số bài viết của tác giả hiện tại trong tháng hiện tại
    COUNT(CASE WHEN posts.author_id = ? AND MONTH(posts.date) = MONTH(CURRENT_DATE()) AND YEAR(posts.date) = YEAR(CURRENT_DATE()) THEN 1 END) AS author_this_month
    FROM posts";
	// Áp phân quyền dữ liệu (admin: tất cả, user: của mình, manager: theo team)
	[$scopeJoin, $scopeWhere] = self::get_base_auth_conditions();
	$sql .= " $scopeJoin" . ($scopeWhere !== '' ? " WHERE $scopeWhere" : '');
	$stmt = db()->prepare($sql);
	$stmt->bind_param('ii', $_SESSION['auth']['user_id'],$_SESSION['auth']['user_id']);
	$stmt->execute();
	$result = $stmt->get_result();
	$data = $result->fetch_assoc();
	$stmt->close();
	$_SESSION[$cacheKey] = ['t' => time(), 'data' => $data];
	return $data;
}

    public static function get_products(): array {
    $allowedCols = ['ID', 'title', 'status', 'date', 'type_id', 'author_id', 'badge'];
    // Lấy tham số từ DataTables
    $params = get_datatable_params($allowedCols);
    if(!checkRoles('view', 'products')){
        return [
            "draw"            => $params['draw'],
            "recordsTotal"    => 0,
            "recordsFiltered" => 0,
            "data"            => []
        ];
    }

    $conn = db();

    // Phân quyền dữ liệu: admin = tất cả, user = của mình, manager = theo team
    [$scopeJoin, $scopeWhere] = self::get_base_auth_conditions();

    // Tổng số bản ghi (cache ngắn — COUNT(*) full bảng chạy lại mỗi lần draw rất tốn khi dữ liệu lớn)
    $totalRecords = self::get_cached_count($conn, "SELECT COUNT(*) AS cnt FROM posts $scopeJoin" . ($scopeWhere !== '' ? " WHERE $scopeWhere" : ''), 60);

    $whereClauses = [];
    if ($scopeWhere !== '') {
        $whereClauses[] = $scopeWhere;
    }

    // Lọc theo search
    if ($params['searchValue'] !== '') {
        $searchRaw = $params['searchValue'];
        if (ctype_digit($searchRaw)) {
            // Toàn số → tìm theo sku prefix (dùng được index sku, không quét full bảng)
            $searchEsc = $conn->real_escape_string($searchRaw);
            $whereClauses[] = "posts.sku LIKE '$searchEsc%'";
        } else {
            // FULLTEXT trên title; loại từ < 3 ký tự (dưới ngưỡng innodb_ft_min_token_size)
            $words = preg_split('/\s+/', trim(preg_replace('/[+\-<>()~*"@]+/', ' ', $searchRaw)));
            $words = array_filter($words, fn($w) => mb_strlen($w) >= 3);
            if (!empty($words)) {
                $boolean = implode(' ', array_map(fn($w) => '+' . $w . '*', $words));
                $esc = $conn->real_escape_string($boolean);
                $badgeEsc = $conn->real_escape_string($searchRaw);
                $whereClauses[] = "(MATCH(posts.title) AGAINST('$esc' IN BOOLEAN MODE) OR posts.badge = '$badgeEsc')";
            } else {
                // Toàn từ ngắn → fallback LIKE (hiếm gặp)
                $searchEsc = $conn->real_escape_string($searchRaw);
                $whereClauses[] = "(posts.title LIKE '%$searchEsc%' OR posts.badge = '$searchEsc')";
            }
        }
    }

    // Lọc theo status (string)
    add_table_filter($whereClauses, 'posts.status', 8, 'string', $conn);

    // Lọc theo type (int)
    add_table_filter($whereClauses, 'posts.type_id', 4, 'int', $conn);

    // Lọc theo author — chỉ admin/manager mới được lọc; user luôn bị ép về
    // chính mình bởi scope nên bỏ qua tham số author họ tự gửi lên.
    $filterAuthor = (int)($_POST['author'] ?? 0);
    if ($filterAuthor > 0 && (is_admin() || is_manager())) {
        $whereClauses[] = "posts.author_id = $filterAuthor";
    }

    // Lọc theo team — chỉ admin. Non-admin đã bị scope giới hạn sẵn nên tham số này bị bỏ qua.
    $filterTeam = (int)($_POST['team'] ?? 0);
    if ($filterTeam > 0 && is_admin()) {
        $scopeJoin = "INNER JOIN authors scope_a ON scope_a.ID = posts.author_id";
        $whereClauses[] = "scope_a.team_id = $filterTeam";
    }

    // Lọc theo sites
    $filterSites = $_POST['sites'] ?? [];
    if (!empty($filterSites) && is_array($filterSites)) {
        $idsStr = implode(',', array_map('intval', $filterSites));
        if ($idsStr !== '') {
            $whereClauses[] = "posts.site_id IN ($idsStr)";
        }
    }

    // Lọc theo khoảng ngày — so sánh range trực tiếp trên cột để index dùng được
    $minDate = $_POST['minDate'] ?? '';
    $maxDate = $_POST['maxDate'] ?? '';
    if ($minDate !== '') {
        $escMin = $conn->real_escape_string($minDate);
        $whereClauses[] = "posts.date >= '$escMin 00:00:00'";
    }
    if ($maxDate !== '') {
        $escMax = $conn->real_escape_string($maxDate);
        $whereClauses[] = "posts.date <= '$escMax 23:59:59'";
    }

    // Lọc theo stores
    $filterStores = $_POST['stores'] ?? [];
    if (!empty($filterStores) && is_array($filterStores)) {
        $idsStr = implode(',', array_map('intval', $filterStores));
        if ($idsStr !== '') {
            $whereClauses[] = "posts.store_id IN ($idsStr)";
        }
    }

    // Lọc theo accounts
    $joinAccounts = '';
    $filterAccounts = $_POST['accounts'] ?? [];
    if (!empty($filterAccounts) && is_array($filterAccounts)) {
        $idsStr = implode(',', array_map('intval', $filterAccounts));
        if ($idsStr !== '') {
            $joinAccounts = "INNER JOIN accounts_relationships ar ON ar.post_id = posts.ID";
            $whereClauses[] = "ar.account_id IN ($idsStr)";
        }
    }

    $where = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

    // Tổng số bản ghi sau khi lọc (cache ngắn theo bộ lọc — chuyển trang không phải đếm lại)
    $totalFiltered = self::get_cached_count($conn, "SELECT COUNT(posts.ID) AS cnt FROM posts $scopeJoin $joinAccounts $where", 60);

    // Lấy dữ liệu: lọc/sắp xếp/phân trang trên subquery chỉ chứa ID (đi bằng index,
    // không phải đọc các cột longtext) rồi mới join lấy dữ liệu — offset sâu nhanh hơn nhiều
    $sql = "SELECT posts.ID, posts.title, posts.status, posts.sku, posts.images, posts.badge, posts.date, posts.type_id, posts.author_id,
                   au.username AS author_name, tm.name AS team_name
            FROM (SELECT posts.ID AS pid
                  FROM posts
                  $scopeJoin
                  $joinAccounts
                  $where
                  ORDER BY posts.{$params['orderColumn']} {$params['orderDir']}
                  LIMIT {$params['start']}, {$params['length']}) AS sel
            JOIN posts ON posts.ID = sel.pid
            LEFT JOIN authors au ON au.ID = posts.author_id
            LEFT JOIN team tm ON tm.ID = au.team_id
            ORDER BY posts.{$params['orderColumn']} {$params['orderDir']}";
    $rs = $conn->query($sql);

    // Chuẩn bị dữ liệu trả về
    $data = [];
    while ($row = $rs->fetch_assoc()) {
        $imgs = json_decode($row['images']);
        $updatedUrl = '';
        if ($imgs && isset($imgs->main)) {
            $updatedUrl = preg_replace('/il_\d+xN/', 'il_100xN', $imgs->main);
        }
        $data[] = [
            "id"            => $row['ID'],
            "title"         => $row['title'],
            "sku"           => $row['sku'],
            "type_id"       => $row['type_id'],
            "author_id"     => $row['author_id'],
            "author_name"   => $row['author_name'] ?? '',
            "team_name"     => $row['team_name'] ?? '',
            "badge"         => $row['badge'],
            "date"          => $row['date'],
            "status"        => $row['status'],
            "image"         => $updatedUrl,
            "product_brand" => "Etsy"
        ];
    }

    return [
        "draw"            => $params['draw'],
        "recordsTotal"    => $totalRecords,
        "recordsFiltered" => $totalFiltered,
        "data"            => $data
    ];
}

    public static function get_products_filters(): array {
    if (!checkRoles('view', 'products')) {
        return ['status' => 'error', 'message' => 'You do not have permission to view products.'];
    }
    $options = [];
    $options['types'] = get_all_types();
    // Trang Products gửi skip_authors: filter Manager dùng select2 ajax và tên tác giả
    // đã đi kèm từng dòng, không cần nạp toàn bộ user (các trang khác vẫn cần map này).
    if (empty($_POST['skip_authors'])) {
        // Danh sách authors đúng phạm vi: admin = tất cả, manager = trong team,
        // còn lại chỉ chính mình (không lộ username đồng đội).
        if (is_admin()) {
            $options['authors'] = get_all_authors();
        } elseif (is_manager()) {
            $options['authors'] = get_authors_by_team();
        } else {
            $uid = (int)($_SESSION['auth']['user_id'] ?? 0);
            $options['authors'] = [$uid => ['title' => get_field_by_id('authors', 'username', $uid) ?? '']];
        }
        // Gắn tên team để cột Author hiển thị team name dưới tên tác giả
        $teamNames = get_author_team_names();
        foreach ($options['authors'] as $id => $info) {
            $options['authors'][$id]['team'] = $teamNames[$id] ?? '';
        }
    } else {
        $options['authors'] = [];
    }
    $options['sites'] = get_all_sites();
    // Danh sách team chỉ gửi cho admin — user/manager không được lọc chéo team
    $options['teams'] = is_admin() ? get_all_teams() : [];
    // Frontend dùng để ẩn/hiện nút & filter theo quyền (backend vẫn tự kiểm tra lại)
    $options['perms'] = [
        'add'           => checkRoles('add', 'products'),
        'edit'          => checkRoles('edit', 'products'),
        'delete'        => checkRoles('delete', 'products'),
        'export'        => checkRoles('add', 'exports_download'),
        'filter_team'   => is_admin(),              // chỉ admin lọc theo team
        'filter_author' => is_admin() || is_manager(), // user không có select Manager
    ];
    return $options;
}
    public static function update_products_status(): array
{
    if (!checkRoles('edit', 'products')) {
        return ['status' => 'error', 'message' => 'You do not have permission to edit products.'];
    }

    $ids = $_POST['ids'] ?? [];
    $newStatus = (string)($_POST['status'] ?? '');

    $allowed = ['pending', 'schedule', 'listed', 'inactive', 'trademark'];
    if (!in_array($newStatus, $allowed, true)) {
        return ['status' => 'error', 'message' => 'Invalid status.'];
    }

    if (!is_array($ids) || empty($ids)) {
        return ['status' => 'error', 'message' => 'Missing product list.'];
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
    if (empty($ids)) {
        return ['status' => 'error', 'message' => 'Invalid product list.'];
    }

    $conn = db();

    // Chỉ giữ các ID thuộc phạm vi dữ liệu của user (team/own)
    $ids = self::check_products_ownership($conn, $ids);
    if (empty($ids)) {
        return ['status' => 'error', 'message' => 'No permitted products in selection.'];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("UPDATE posts SET status = ? WHERE ID IN ($placeholders)");
    $stmt->bind_param('s' . str_repeat('i', count($ids)), $newStatus, ...$ids);
    if (!$stmt->execute()) {
        return ['status' => 'error', 'message' => 'Update failed: ' . $conn->error];
    }

    return ['status' => 'success', 'updated' => $stmt->affected_rows];
}

/**
 * Bulk delete products (Actions > Delete Selected trên trang Products).
 * Xóa bản ghi liên quan trước rồi mới xóa posts, tất cả trong 1 transaction.
 */

    public static function delete_products(): array
{
    if (!checkRoles('delete', 'products')) {
        return ['status' => 'error', 'message' => 'You do not have permission to delete products.'];
    }

    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids) || empty($ids)) {
        return ['status' => 'error', 'message' => 'Missing product list.'];
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
    if (empty($ids)) {
        return ['status' => 'error', 'message' => 'Invalid product list.'];
    }

    $conn = db();

    // Chỉ giữ các ID thuộc phạm vi dữ liệu của user (team/own)
    $ids = self::check_products_ownership($conn, $ids);
    if (empty($ids)) {
        return ['status' => 'error', 'message' => 'No permitted products in selection.'];
    }
    $idsStr = implode(',', $ids);

    $conn->begin_transaction();
    try {
        // accounts_relationships có FK tới posts nên phải dọn trước
        $conn->query("DELETE FROM accounts_relationships WHERE post_id IN ($idsStr)");
        $conn->query("DELETE FROM download_relationships WHERE post_id IN ($idsStr)");
        $conn->query("DELETE FROM amazon_listings WHERE post_id IN ($idsStr)");
        $conn->query("DELETE FROM posts WHERE ID IN ($idsStr)");
        $deleted = $conn->affected_rows;
        $conn->commit();
        return ['status' => 'success', 'deleted' => $deleted];
    } catch (\Throwable $e) {
        $conn->rollback();
        return ['status' => 'error', 'message' => 'Delete failed: ' . $e->getMessage()];
    }
}

    public static function update_products_type(): array
{
    if (!checkRoles('edit', 'products')) {
        return ['status' => 'error', 'message' => 'You do not have permission to edit products.'];
    }

    $ids = $_POST['ids'] ?? [];
    $typeId = (int)($_POST['type_id'] ?? 0);

    if (!is_array($ids) || empty($ids) || $typeId <= 0) {
        return ['status' => 'error', 'message' => 'Missing product list or type.'];
    }

    // Chuẩn hóa danh sách ID về số nguyên dương, loại trùng
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
    if (empty($ids)) {
        return ['status' => 'error', 'message' => 'Invalid product list.'];
    }

    $conn = db();

    // Chỉ giữ các ID thuộc phạm vi dữ liệu của user (team/own)
    $ids = self::check_products_ownership($conn, $ids);
    if (empty($ids)) {
        return ['status' => 'error', 'message' => 'No permitted products in selection.'];
    }

    // Type phải tồn tại
    $stmt = $conn->prepare('SELECT ID FROM `type` WHERE ID = ?');
    $stmt->bind_param('i', $typeId);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_row()) {
        return ['status' => 'error', 'message' => 'Type does not exist.'];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("UPDATE posts SET type_id = ? WHERE ID IN ($placeholders)");
    $stmt->bind_param(str_repeat('i', count($ids) + 1), $typeId, ...$ids);
    if (!$stmt->execute()) {
        return ['status' => 'error', 'message' => 'Update failed: ' . $conn->error];
    }

    return ['status' => 'success', 'updated' => $stmt->affected_rows];
}
}
