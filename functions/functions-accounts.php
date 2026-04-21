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
    $sys_date  = date('Y-m-d');

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

        syncLinks($conn, $accountId, 'accounts_links', 'link_id', $_POST['accounts'] ?? []);
        syncLinks($conn, $accountId, 'accounts_authors', 'author_id', $_POST['authors'] ?? []);

        $conn->commit();
        return ['status' => $resStatus, 'id' => $accountId];

    } catch (Exception $e) {
        $conn->rollback();
        return ['status' => 'error', 'message' => 'Lỗi hệ thống: ' . $e->getMessage()];
    }
}

function syncLinks($conn, int $accountId, string $tableName, string $columnName, $data): void
{
    // 1. Xóa liên kết cũ (Dùng chuẩn bind_param cho an toàn)
    $stmtDel = $conn->prepare("DELETE FROM $tableName WHERE account_id = ? OR $columnName = ?");
    $stmtDel->bind_param("ii", $accountId, $accountId);
    $stmtDel->execute();

    // 2. Chèn liên kết mới
    if (!empty($data)) {
        $ids = is_array($data) ? $data : explode(',', $data);
        $stmtIns = $conn->prepare("INSERT INTO $tableName (account_id, $columnName) VALUES (?, ?)");
        foreach ($ids as $id) {
            $id = (int)trim($id);
            if ($id > 0 && $id !== $accountId) {
                $stmtIns->bind_param("ii", $accountId, $id);
                $stmtIns->execute();
            }
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

    // 1. Lấy thông tin cơ bản từ bảng accounts
    $sql = "SELECT *
            FROM accounts
            WHERE ID = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $account = $result->fetch_assoc();

    if (!$account) {
        return null;
    }

    // 2. Lấy danh sách Quản lý (Authors) liên kết với account này
    $sqlAuthor = "SELECT au.ID, au.username, t.name as team_name 
                  FROM accounts_authors aa
                  JOIN authors au ON aa.author_id = au.ID
                  LEFT JOIN team t ON au.team_id = t.ID
                  WHERE aa.account_id = ?";
    $stmtAuth = $conn->prepare($sqlAuthor);
    $stmtAuth->bind_param("i", $id);
    $stmtAuth->execute();
    $resAuth = $stmtAuth->get_result();

    $authorsArgs = [];
    while ($row = $resAuth->fetch_assoc()) {
        $authorsArgs[$row['ID']] = [
            'title' => $row['team_name'] . " (" . $row['username'] . ")",
            'selected' => true
        ];
    }
    $account['authors_args'] = $authorsArgs;

    // 3. Lấy danh sách ID liên kết theo cả 2 chiều
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
    $stmtLink = $conn->prepare($sqlLink);
    $stmtLink->bind_param("ii", $id, $id); // Truyền ID vào cả 2 vị trí
    $stmtLink->execute();
    $resultLink = $stmtLink->get_result();

    $linkedAccounts = [];
    while ($row = $resultLink->fetch_assoc()) {
        // Vì dùng UNION nên kết quả trả về sẽ nằm chung ở cột đầu tiên (mặc định lấy tên cột của query 1)
        $linkedAccounts[$row['ID']] = [
            'title' => $row['site_name'] . " (" . $row['name'] . ")",
            'selected' => true
        ];
    }

    // Gán mảng liên kết vào dữ liệu account trả về
    $account['linked_args'] = $linkedAccounts;

    return $account;
}