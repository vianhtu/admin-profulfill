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
    $accounts   = $_POST['accounts'] ?? [];
    $authors    = $_POST['authors'] ?? [];
    $auth       = $_SESSION['auth'];
    $currentUserID = $auth['user_id'];
    $currentUserTeamID = $auth['team'];

    try {
        $conn->begin_transaction();

        if ($id) {
            // --- LOGIC UPDATE ---
            if (!is_admin()) {
                // Không cho user sửa team
                $team_id = null;
                // Chỉ cho liên kết tài khoản thuộc team.
                $accounts = filterAccountsByTeam($conn, $accounts, $currentUserTeamID);
                if(is_manager()){
                    // cho manager sửa authors trong team.
                    $authors = filterAuthorsByTeam($conn, $authors, $currentUserTeamID);
                }
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

            syncAccountLinks($conn, $accountId, 'accounts_links', 'link_id', $accounts, true);

            $resStatus = 'updated';
        } else {
            // --- LOGIC INSERT ---
            if(is_user()){
                // Chỉ cho gán team của user.
                $team_id = $currentUserTeamID;
                // Gán cứng author là chính user.
                $authors = [$currentUserID];
                // Chỉ cho liên kết tài khoản thuộc team.
                $accounts = filterAccountsByTeam($conn, $accounts, $currentUserTeamID);
            } elseif (is_manager()){
                // Chỉ cho gán team của user
                $team_id = $currentUserTeamID;
                // Chỉ cho gán authors trong team.
                $authors = filterAuthorsByTeam($conn, $authors, $currentUserTeamID);
                // Chỉ cho liên kết tài khoản thuộc team.
                $accounts = filterAccountsByTeam($conn, $accounts, $currentUserTeamID);
            }

            $sql = "INSERT INTO accounts 
                    (site_id, team_id, name, email, password, 2fa, address, dob, ssn, phone, user_id, sku, note, custom_fields, status, created_date, seller_date) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iisssssssssssiss",
                $site_id, $team_id, $name, $email, $password, $two_fa,
                $address, $dob, $ssn, $phone, $user_id, $sku, $note, $optionsRaw, $status_val, $sys_date, $account_date
            );
            $stmt->execute();
            $accountId = $conn->insert_id;
            // --- END LOGIC INSERT ---

            syncAccountLinks($conn, $accountId, 'accounts_authors', 'author_id', $authors);
            syncAccountLinks($conn, $accountId, 'accounts_links', 'link_id', $accounts, true);

            $resStatus = 'inserted';
        }
        $stmt->close();

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

function syncAccountLinks($conn, int $accountId, string $tableName, string $columnName, $data, bool $isBidirectional = false): void
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

    // 2. Chèn liên kết mới
    if (empty($data)) return;

    $ids = is_array($data) ? $data : explode(',', $data);
    $ids = array_unique(array_filter(array_map('intval', $ids)));

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
    $sql = "SELECT * FROM accounts WHERE ID = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$account) return null;

    // 2. Lấy danh sách Quản lý (Authors)
    // Tối ưu: Chỉ select những cột thực sự cần thiết
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
    $accountId = (int)($_POST['account_id'] ?? 0);
    if ($accountId <= 0) {
        return [];
    }

    $conn = db();
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

    // 2. Kiểm tra quyền truy cập (Giữ nguyên logic bảo mật)
    if (!is_admin()) {
        $teamId = (int)($_SESSION['auth']['team'] ?? 0);
        $userId = (int)($_SESSION['auth']['user_id'] ?? 0);

        if (!hasAccountTeamAccess($accountId, $teamId)) {
            header("HTTP/1.1 403 Forbidden");
            exit('Bạn không có quyền truy cập file của Team khác');
        }

        if (is_user() && !isAccountOwner($accountId, $userId)) {
            header("HTTP/1.1 403 Forbidden");
            exit('Bạn không có quyền truy cập file này');
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
        if (!hasAccountTeamAccess($accountId, $currentUserTeamId)) {
            return [
                'success' => false,
                'message' => 'Cảnh báo: Bạn không có quyền tác động lên File của Team khác.'
            ];
        }

        // 2. Kiểm tra quyền sở hữu cá nhân (Nếu là level User)
        if (is_user() && !isAccountOwner($accountId, $currentUserId)) {
            return [
                'success' => false,
                'message' => 'Cảnh báo: Bạn không có quyền tác động lên File khi chưa được phân quyền.'
            ];
        }
    }
    $conn = db();
    // 1. Xóa liên kết trong bảng accounts_files
    $sql = "DELETE FROM accounts_files WHERE account_id = ? AND file_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $accountId, $fileId);

    if ($stmt->execute()) {
        $stmt->close();
        deletePhysicalFile($fileId);
        return ['success' => true];
    }

    $stmt->close();
    return ['success' => false, 'message' => 'Không thể gỡ bỏ liên kết file.'];
}

/**
 * Kiểm tra xem Account có thuộc về Team cụ thể hay không
 */
function hasAccountTeamAccess($accountId, $teamId): bool
{
    $conn = db();

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
function isAccountOwner($accountId, $userId): bool
{
    $conn = db();
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