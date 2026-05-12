<?php

use JetBrains\PhpStorm\NoReturn;

function addAccount(): array {
    $conn = db();

    // 1. Addmin full quyền, các user khác phải được cấp quyền.
    if (!is_admin() && !checkRoles(['add', 'edit'], 'stores')) {
        return ['status' => 'error', 'message' => 'Bạn không có quyền thực hiện thao tác này'];
    }

    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $csrfToken !== $_SESSION['csrf_token']) {
        return ['status' => 'error', 'message' => 'CSRF token không hợp lệ'];
    }

    // 2. Thu thập dữ liệu
    $id        = !empty($_POST['_id']) ? (int)$_POST['_id'] : null;
    $site_id   = (int)$_POST['site'];
    $team_id   = (int)$_POST['team'];
    $name      = $_POST['name'] ?? '';
    $email     = $_POST['email'] ?? '';
    $password  = $_POST['password'] ?? '';
    $two_fa    = $_POST['2fa'] ?? '';
    $address   = $_POST['address'] ?? '';
    $dob       = !empty($_POST['dob']) ? $_POST['dob'] : null;
    $ssn       = $_POST['ssn'] ?? '';
    $phone     = $_POST['phone'] ?? '';
    $user_id   = $_POST['id'] ?? '';
    $sku       = $_POST['sku'] ?? '';
    $note      = $_POST['note'] ?? '';
    $optionsRaw = $_POST['options'] ?? '[]';
    $status_val = (int)$_POST['status'];
    $account_date = !empty($_POST['date']) ? $_POST['date'] : null;
    $sys_date   = date('Y-m-d');
    $accounts   = array_unique(array_filter(array_map('intval', is_array($a = $_POST['accounts'] ?? []) ? $a : explode(',', $a))));
    $authors    = array_unique(array_filter(array_map('intval', is_array($a = $_POST['authors'] ?? []) ? $a : explode(',', $a))));
    $auth       = $_SESSION['auth'];
    $currentUserID = $auth['user_id'];
    $currentUserTeamID = $auth['team'];

    try {
        $conn->begin_transaction();

        if($password) $password = encrypt($password);
        if($two_fa) $two_fa = encrypt($two_fa);

        if ($id) {
            // --- LOGIC UPDATE ---
            if (!is_admin()) {
                // Không cho user sửa team
                $team_id = null;
            }

            $sql = "UPDATE accounts SET 
                    site_id=?, team_id=COALESCE(?, team_id), name=?, email=?, password=?, 2fa=?, 
                    address=?, dob=?, ssn=?, phone=?, user_id=?, sku=?, note=?, custom_fields=?, status=?, seller_date=? 
                    WHERE ID=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iissssssssssssisi",
                $site_id, $team_id, $name, $email, $password, $two_fa,
                $address, $dob, $ssn, $phone, $user_id, $sku, $note, $optionsRaw, $status_val, $account_date, $id
            );
            $stmt->execute();
            $accountId = $id;

            // Chỉ cho admin và manager update authors.
            if(is_staff()){
                syncAccountLinks($conn, $accountId, 'accounts_authors', 'author_id', $authors);
            }

            $resStatus = 'updated';
        } else {
            // --- LOGIC INSERT ---
            if(is_user()){
                // Chỉ cho gán team của user.
                $team_id = $currentUserTeamID;
                // Gán cứng author là chính user.
                $authors = [$currentUserID];
            } elseif (is_manager()){
                // Chỉ cho gán team của user
                $team_id = $currentUserTeamID;
            }

            $sql = "INSERT INTO accounts 
                    (site_id, team_id, name, email, password, 2fa, address, dob, ssn, phone, user_id, sku, note, custom_fields, status, created_date, seller_date) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iisssssssssssisss",
                $site_id, $team_id, $name, $email, $password, $two_fa,
                $address, $dob, $ssn, $phone, $user_id, $sku, $note, $optionsRaw, $status_val, $sys_date, $account_date
            );
            $stmt->execute();
            $accountId = $conn->insert_id;
            // --- END LOGIC INSERT ---

            syncAccountLinks($conn, $accountId, 'accounts_authors', 'author_id', $authors);

            $resStatus = 'inserted';
        }
        $stmt->close();

        syncAccountLinks($conn, $accountId, 'accounts_links', 'link_id', $accounts, true);

        // 2. Xử lý Upload File nếu có
        if (!empty($_FILES['files']['name'][0])) {
            $insertedFileIds = handleFileUploads($conn, $currentUserID, $_FILES['files'], 'accounts', $accountId);
            if (!empty($insertedFileIds)) {
                // 2. Liên kết các ID file đó với account_id trong bảng 'accounts_files'
                linkFilesToAccount($conn, $accountId, $insertedFileIds);
            }
        }

        $conn->commit();
        return ['status' => $resStatus, 'id' => $accountId];

    } catch (Exception $e) {
        $conn->rollback();
        return ['status' => 'error', 'message' => 'Lỗi hệ thống: ' . $e->getMessage()];
    }
}

function syncAccountLinks($conn, int $accountId, string $tableName, string $columnName, array $ids, bool $isBidirectional = false): void
{
    // 1. Xóa liên kết cũ
    if ($isBidirectional) {
        // Đối với accounts_links (quan hệ 2 chiều, xóa cả 2 đầu để làm sạch)
        $stmtDel = $conn->prepare("DELETE FROM $tableName WHERE account_id = ? OR $columnName = ?");
        $stmtDel->bind_param("ii", $accountId, $accountId);
    } else {
        // Đối với accounts_authors (quan hệ 1 chiều, chỉ xóa liên kết thuộc về account này)
        $stmtDel = $conn->prepare("DELETE FROM $tableName WHERE account_id = ?");
        $stmtDel->bind_param("i", $accountId);
    }

    $stmtDel->execute();
    $stmtDel->close();

    if (!empty($ids)) {
        // Sử dụng Bulk Insert để tối ưu hiệu năng
        $placeholders = [];
        $params = [];
        $types = "";

        foreach ($ids as $targetId) {
            if ($targetId > 0 && $targetId !== $accountId) {
                $placeholders[] = "(?, ?)";
                $params[] = $accountId;
                $params[] = $targetId;
                $types .= "ii";
            }
        }

        if (!empty($placeholders)) {
            $sql = "INSERT INTO $tableName (account_id, $columnName) VALUES " . implode(', ', $placeholders);
            $stmtIns = $conn->prepare($sql);
            $stmtIns->bind_param($types, ...$params);
            $stmtIns->execute();
            $stmtIns->close();
        }
    }
}

/**
 * Lấy thông tin chi tiết một account theo ID
 * @param int $id
 * @return array|null Trả về mảng dữ liệu hoặc null nếu không tìm thấy
 */
function getAccount(int $id): ?array {
    $conn = db();
    if ($id <= 0) return null;

    // 1. Lấy thông tin cơ bản
    $sql = "SELECT 
            a.*, s.custom_fields AS default_custom_fields
        FROM accounts a
        LEFT JOIN site s ON a.site_id = s.ID
        WHERE a.ID = ? 
        LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$account) return null;

    $account['password'] = !empty($account['password']) ? decrypt($account['password']) : '';
    $account['2fa']      = !empty($account['2fa'])      ? decrypt($account['2fa'])      : '';

    // 2. Lấy danh sách Quản lý (Authors)
    $sqlAuthor = "SELECT au.ID, au.username, t.name as team_name 
                  FROM accounts_authors aa
                  JOIN authors au ON aa.author_id = au.ID
                  LEFT JOIN team t ON au.team_id = t.ID
                  WHERE aa.account_id = ?";

    $stmt = $conn->prepare($sqlAuthor);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $resAuth = $stmt->get_result();

    $account['authors_args'] = [];
    while ($row = $resAuth->fetch_assoc()) {
        $account['authors_args'][$row['ID']] = [
            'title' => ($row['team_name'] ?? 'No Team') . " ({$row['username']})",
            'selected' => true
        ];
    }
    $stmt->close();

    // 3. Lấy danh sách liên kết 2 chiều
    // Sử dụng UNION giúp lấy ID từ cả 2 phía của mối quan hệ
    $sqlLink = "SELECT a.ID, a.name, s.name as site_name 
                FROM accounts_links al
                JOIN accounts a ON al.link_id = a.ID
                LEFT JOIN site s ON a.site_id = s.ID
                WHERE al.account_id = ?
                UNION
                SELECT a.ID, a.name, s.name as site_name  
                FROM accounts_links al
                JOIN accounts a ON al.account_id = a.ID
                LEFT JOIN site s ON a.site_id = s.ID
                WHERE al.link_id = ?";

    $stmt = $conn->prepare($sqlLink);
    $stmt->bind_param("ii", $id, $id);
    $stmt->execute();
    $resultLink = $stmt->get_result();

    $account['linked_args'] = [];
    while ($row = $resultLink->fetch_assoc()) {
        $account['linked_args'][$row['ID']] = [
            'title' => ($row['site_name'] ?? 'No Site') . " ({$row['name']})",
            'selected' => true
        ];
    }
    $stmt->close();

    return $account;
}

/**
 * Liên kết danh sách file với một account cụ thể
 */
function linkFilesToAccount($conn, int $accountId, array $fileIds): bool
{
    if (empty($fileIds)) {
        return false;
    }

    // Sử dụng prepared statement để tránh SQL Injection và tối ưu hiệu suất
    $sql = "INSERT IGNORE INTO accounts_files (account_id, file_id) VALUES (?, ?)";
    $stmt = $conn->prepare($sql);

    foreach ($fileIds as $fileId) {
        $stmt->bind_param("ii", $accountId, $fileId);
        $stmt->execute();
    }

    $stmt->close();
    return true;
}

function getAccountUploadFiles(): array
{
    $conn = db();
    $accountId = (int)($_POST['account_id'] ?? 0);
    $auth = $_SESSION['auth'] ?? [];

    // 1. Kiểm tra ID và Role cơ bản
    if ($accountId <= 0 || (!is_admin() && !checkRoles(['view'], 'stores'))) {
        return [];
    }

    // 2. Kiểm tra quyền truy cập chi tiết (Chỉ dành cho Manager/User)
    if (!is_admin()) {
        $teamId = (int)($auth['team'] ?? 0);
        $userId = (int)($auth['user_id'] ?? 0);

        // Gộp kiểm tra Team và Owner (Thoát nếu không thỏa mãn)
        if (!hasAccountTeamAccess($conn, $accountId, $teamId) ||
            (is_user() && !isAccountOwner($conn, $accountId, $userId))) {
            return [];
        }
    }

    $sql = "SELECT f.ID, f.file_name, f.file_size, f.storage_path, f.file_uuid 
        FROM accounts_files af
        JOIN files f ON af.file_id = f.ID
        WHERE af.account_id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $accountId);
    $stmt->execute();
    $result = $stmt->get_result();

    $files = [];
    while ($row = $result->fetch_assoc()) {
        $extension = strtolower(pathinfo($row['file_name'], PATHINFO_EXTENSION));
        $imageUrl = BASE_URL . "ajax.php?action=get-account-file&id=" . $row['ID'];

        // Nếu không phải là ảnh, có thể trả về một icon mặc định để Dropzone hiển thị đẹp hơn
        $imageExtensions = ['jpg', 'jpeg', 'png'];
        if (!in_array($extension, $imageExtensions)) {
            // Bạn có thể tạo các icon sẵn như pdf.png, excel.png trong assets
            $imageUrl = BASE_URL . '/assets/img/file-icons/' . $extension . '.png';
        }

        // Chỉ lấy thông tin cần thiết cho Dropzone hiển thị
        $files[] = [
            'id'   => $row['ID'],
            'name' => $row['file_name'],
            'size' => (int)$row['file_size'],
            'url'  => $imageUrl, // Đường dẫn để xem file
            'uuid' => $row['file_uuid']
        ];
    }
    $stmt->close();
    return $files;
}

#[NoReturn]
function getAccountUploadFileData(): void
{
    if (!is_admin() && !checkRoles(['view'], 'stores')) {
        exit('Bạn chưa được cấp quyền xem file.');
    }

    $fileId = (int)($_GET['id'] ?? 0);

    if ($fileId <= 0) {
        header("HTTP/1.1 400 Bad Request");
        exit('ID không hợp lệ');
    }

    $conn = db();

    // 1. Lấy storage_path, mime_type và thông tin quyền từ DB
    $sqlFile = "SELECT f.storage_path, f.mime_type, f.file_name, af.account_id 
                FROM files f
                JOIN accounts_files af ON f.ID = af.file_id
                WHERE f.ID = ? LIMIT 1";

    $stmt = $conn->prepare($sqlFile);
    $stmt->bind_param("i", $fileId);
    $stmt->execute();
    $file = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$file) {
        header("HTTP/1.1 404 Not Found");
        exit('File không tồn tại trong database');
    }

    $fullPath = dirname(__DIR__) . '/' . $file['storage_path'];

    if (!file_exists($fullPath)) {
        header("HTTP/1.1 404 Not Found");
        exit('Tệp tin không tồn tại trên máy chủ.');
    }

    $accountId = $file['account_id'];

    // 2. Kiểm tra quyền truy cập
    if (!is_admin()) {
        $teamId = (int)($_SESSION['auth']['team'] ?? 0);
        $userId = (int)($_SESSION['auth']['user_id'] ?? 0);

        if (!hasAccountTeamAccess($conn, $accountId, $teamId)) {
            header("HTTP/1.1 403 Forbidden");
            exit('Bạn không có quyền truy cập file của Team khác');
        }

        if (is_user() && !isAccountOwner($conn, $accountId, $userId)) {
            header("HTTP/1.1 403 Forbidden");
            exit('Bạn không được cấp quyền truy cập file này');
        }
    }

    // 3. Xuất file bằng readfile() - Tối ưu cho file lớn (5MB)
    if (ob_get_length()) ob_end_clean();

    // Thiết lập Header
    header("Content-Type: " . ($file['mime_type'] ?? 'application/octet-stream'));
    header("Content-Length: " . filesize($fullPath));
    header("Content-Disposition: inline; filename=\"" . basename($file['file_name']) . "\"");
    header("Cache-Control: private, max-age=86400");

    // Đọc và trả dữ liệu về trình duyệt
    readfile($fullPath);
    exit;
}

function deleteAccountUploadFile(): array
{
    // 1. Kiểm tra quyền
    if (!is_admin() && !checkRoles(['delete'], 'stores')) {
        return ['success' => false, 'message' => 'Bạn không có quyền thực hiện thao tác này'];
    }

    $conn = db();
    $fileId = (int)($_POST['file_id'] ?? 0);
    $accountId = (int)($_POST['account_id'] ?? 0);

    if ($fileId <= 0 || $accountId <= 0) {
        return ['success' => false, 'message' => 'Dữ liệu không hợp lệ.'];
    }

    // --- BẮT ĐẦU CHẶN TRƯỜNG HỢP ---
    if (!is_admin()) {
        $currentUserTeamId = (int)($_SESSION['auth']['team'] ?? 0);
        $currentUserId = (int)($_SESSION['auth']['user_id'] ?? 0);

        // 1. Kiểm tra quyền Team (Bắt buộc cho cả Leader và User)
        if (!hasAccountTeamAccess($conn, $accountId, $currentUserTeamId)) {
            return [
                'success' => false,
                'message' => 'Cảnh báo: Bạn không có quyền tác động lên File của Team khác.'
            ];
        }

        // 2. Kiểm tra quyền sở hữu cá nhân (Nếu là level User)
        if (is_user() && !isAccountOwner($conn, $accountId, $currentUserId)) {
            return [
                'success' => false,
                'message' => 'Cảnh báo: Bạn không có quyền tác động lên File khi chưa được phân quyền.'
            ];
        }
    }
    // 1. Xóa liên kết trong bảng accounts_files
    $sql = "DELETE FROM accounts_files WHERE account_id = ? AND file_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $accountId, $fileId);

    if ($stmt->execute()) {
        $stmt->close();
        deletePhysicalFile($conn, $fileId);
        return ['success' => true];
    }

    $stmt->close();
    return ['success' => false, 'message' => 'Không thể gỡ bỏ liên kết file.'];
}

/**
 * Kiểm tra xem Account có thuộc về Team cụ thể hay không
 */
function hasAccountTeamAccess($conn, $accountId, $teamId): bool
{
    // 1. Check bảng accounts (Team chính sở hữu account)
    $sql1 = "SELECT COUNT(*) as total FROM accounts WHERE ID = ? AND team_id = ?";
    $stmt1 = $conn->prepare($sql1);
    $stmt1->bind_param("ii", $accountId, $teamId);
    $stmt1->execute();
    $isOwnerTeam = (int)$stmt1->get_result()->fetch_assoc()['total'];
    $stmt1->close();

    if ($isOwnerTeam > 0) return true;

    // 2. Check bảng accounts_authors (Team của những người được gán vào account)
    $sql2 = "SELECT COUNT(*) as total 
             FROM accounts_authors as aa
             JOIN authors as a ON aa.author_id = a.ID
             WHERE aa.account_id = ? AND a.team_id = ?";

    $stmt2 = $conn->prepare($sql2);
    $stmt2->bind_param("ii", $accountId, $teamId);
    $stmt2->execute();
    $isAssignedTeam = (int)$stmt2->get_result()->fetch_assoc()['total'];
    $stmt2->close();

    return $isAssignedTeam > 0;
}

/**
 * Kiểm tra xem User có phải là chủ sở hữu (Author) của Account không
 */
function isAccountOwner($conn, $accountId, $userId): bool
{
    $sql = "SELECT COUNT(*) as total 
            FROM accounts_authors
            WHERE account_id = ? AND author_id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $accountId, $userId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)$result['total'] > 0;
}

function filterAccountsByTeam($conn, array $accountIds, int $teamId): array
{
    if (empty($accountIds)) {
        return [];
    }

    // Ép kiểu số nguyên để bảo mật
    $accountIds = array_map('intval', $accountIds);

    // Tạo placeholders (?,?,?) cho câu lệnh IN
    $placeholders = implode(',', array_fill(0, count($accountIds), '?'));

    $sql = "SELECT ID FROM accounts WHERE ID IN ($placeholders) AND team_id = ?";
    $stmt = $conn->prepare($sql);

    // Chuẩn bị tham số: danh sách ID + tham số team_id cuối cùng
    $types = str_repeat('i', count($accountIds)) . 'i';
    $params = [...$accountIds, $teamId];

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $filteredIds = [];
    while ($row = $result->fetch_assoc()) {
        $filteredIds[] = (int)$row['ID'];
    }
    $stmt->close();

    return $filteredIds;
}

function getAccountsTable(): array
{
    $allowedCols = ['ID', 'team_id', 'status', 'created_date'];

    // Lấy tham số từ DataTables
    $params = getDataTableParams($allowedCols);
    if(!is_admin() && !checkRoles('view', 'stores')){
        return [
            "draw"            => $params['draw'],
            "recordsTotal"    => 0,
            "recordsFiltered" => 0,
            "data"            => []
        ];
    }

    $conn = db();

    // Tổng số bản ghi
    $totalRecords = $conn->query("SELECT COUNT(*) AS cnt FROM accounts")->fetch_assoc()['cnt'];

    $whereClauses = [];

    // Lọc theo search
    if ($params['searchValue'] !== '') {
        $searchEsc = $conn->real_escape_string($params['searchValue']);
        $whereClauses[] = "accounts.email LIKE '%$searchEsc%' OR accounts.name LIKE '%$searchEsc%' OR accounts.user_id LIKE '%$searchEsc%' OR accounts.sku LIKE '%$searchEsc%'";
    }

    // Lọc theo type (int)
    addTableFilter($whereClauses, 'accounts.site_id', 2, 'int', $conn);

    // Lọc theo role (int)
    addTableFilter($whereClauses, 'accounts.team_id', 3, 'int', $conn);

    // Lọc theo team name (int)
    //addTableFilter($whereClauses, 'accounts.author_id', 4, 'int', $conn);

    $where = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

    // Tổng số bản ghi sau khi lọc
    $totalFiltered = $conn->query(
        "SELECT COUNT(ID) AS cnt FROM accounts $where"
    )->fetch_assoc()['cnt'];

    // Lấy dữ liệu
    $sql = "SELECT *
            FROM accounts
            $where
            ORDER BY {$params['orderColumn']} {$params['orderDir']}
            LIMIT {$params['start']}, {$params['length']}";
    $rs = $conn->query($sql);

    // Chuẩn bị dữ liệu trả về
    $data = [];
    while ($row = $rs->fetch_assoc()) {
        $data[] = [
            "id"              => $row['ID'],
            "site_id"         => $row['site_id'],
            "team_id"         => $row['team_id'],
            "author_id"       => 0,
            "name"            => $row['name'],
            "email"           => $row['email'],
            "user_id"         => $row['user_id'],
            "status"          => $row['status'],
            "available_funds" => 0,
            "on_hold"         => 0,
            "subscription_fee"=> 0,
            "created_date"    => $row['created_date'],
            "sys_date"        => '',
        ];
    }

    return [
        "draw"            => $params['draw'],
        "recordsTotal"    => $totalRecords,
        "recordsFiltered" => $totalFiltered,
        "data"            => $data
    ];
}