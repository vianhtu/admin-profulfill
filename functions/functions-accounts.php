<?php
function addAccount(): array {
    $conn = db();

    // 1. Kiểm tra quyền và CSRF
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
    $user_id   = $_POST['id'] ?? ''; // Map từ #account_id
    $sku       = $_POST['sku'] ?? '';
    $note      = $_POST['note'] ?? '';
    $optionsRaw = $_POST['options'] ?? '[]';
    $status_val = (int)$_POST['status'];
    $account_date = !empty($_POST['date']) ? $_POST['date'] : null;
    $sys_date = date('Y-m-d');
    $accounts = $_POST['accounts'] ?? [];
    $authors = $_POST['authors'] ?? [];

    $auth = $_SESSION['auth'];
    $level = $auth['level'] ?? '';

    if ($level == 'manager') {
        $team_id = $auth['team'];
    } elseif ($level == 'user') {
        $team_id = $auth['team'];
        $authors = [$auth['user_id']];
    }

    try {
        $conn->begin_transaction();

        if ($id) {
            // --- LOGIC UPDATE ---
            $sql = "UPDATE accounts SET 
                    site_id=?, team_id=?, name=?, email=?, password=?, 2fa=?, 
                    address=?, dob=?, ssn=?, phone=?, user_id=?, sku=?, note=?, custom_fields=?, status=?, seller_date=? 
                    WHERE ID=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iissssssssssssisi",
                $site_id, $team_id, $name, $email, $password, $two_fa,
                $address, $dob, $ssn, $phone, $user_id, $sku, $note, $optionsRaw, $status_val, $account_date, $id
            );
            $stmt->execute();
            $accountId = $id;
            $resStatus = 'updated';
        } else {
            // --- LOGIC INSERT ---
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
            $resStatus = 'inserted';
        }
        $stmt->close();

        syncAccountLinks($conn, $accountId, 'accounts_links', 'link_id', $accounts, true);
        syncAccountLinks($conn, $accountId, 'accounts_authors', 'author_id', $authors);

        // 2. Xử lý Upload File nếu có
        if (!empty($_FILES['files']['name'][0])) {
            $userId = (int)($auth['user_id'] ?? 0); // Lấy ID người dùng đang login
            $insertedFileIds = handleFileUploads($userId, $_FILES['files'], 'accounts', $accountId);
            if (!empty($insertedFileIds)) {
                // 2. Liên kết các ID file đó với account_id trong bảng 'accounts_files'
                linkFilesToAccount($accountId, $insertedFileIds);
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
function linkFilesToAccount(int $accountId, array $fileIds): bool
{
    if (empty($fileIds)) {
        return false;
    }

    $conn = db();

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
        $imageUrl = BASE_URL . $row['storage_path'];

        // Nếu không phải là ảnh, có thể trả về một icon mặc định để Dropzone hiển thị đẹp hơn
        $imageExtensions = ['jpg', 'jpeg', 'png'];
        if (!in_array($extension, $imageExtensions)) {
            // Bạn có thể tạo các icon sẵn như pdf.png, excel.png trong assets
            $imageUrl = '/assets/img/file-icons/' . $extension . '.png';
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