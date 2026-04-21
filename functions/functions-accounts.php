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
    $author_id = (int)$_POST['author'];
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
                    site_id=?, team_id=?, author_id=?, name=?, email=?, password=?, 2fa=?, 
                    address=?, dob=?, ssn=?, phone=?, user_id=?, sku=?, note=?, custom_fields=?, status=?, seller_date=? 
                    WHERE ID=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iiissssssssssssisi",
                $site_id, $team_id, $author_id, $name, $email, $password, $two_fa,
                $address, $dob, $ssn, $phone, $user_id, $sku, $note, $optionsRaw, $status_val, $account_date, $id
            );
            $stmt->execute();
            $accountId = $id;
            $resStatus = 'updated';
        } else {
            // --- LOGIC INSERT ---
            $sql = "INSERT INTO accounts 
                    (site_id, team_id, author_id, name, email, password, 2fa, address, dob, ssn, phone, user_id, sku, note, custom_fields, status, created_date, seller_date) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iiisssssssssssiss",
                $site_id, $team_id, $author_id, $name, $email, $password, $two_fa,
                $address, $dob, $ssn, $phone, $user_id, $sku, $note, $optionsRaw, $status_val, $sys_date, $account_date
            );
            $stmt->execute();
            $accountId = $conn->insert_id;
            $resStatus = 'inserted';
        }

        // 3. Xử lý bảng accounts_link (Liên kết nhiều tài khoản)
        // Luôn xóa các liên kết cũ của account này trước khi chèn mới (cho cả Insert/Update)
        $conn->query("DELETE FROM accounts_link WHERE account_id = $accountId OR link_id = $accountId");

        if (!empty($_POST['accounts'])) {
            // Giả sử accounts gửi lên dạng mảng hoặc chuỗi cách nhau bởi dấu phẩy
            $linkIds = is_array($_POST['accounts']) ? $_POST['accounts'] : explode(',', $_POST['accounts']);

            $stmtLink = $conn->prepare("INSERT INTO accounts_link (account_id, link_id) VALUES (?, ?)");
            foreach ($linkIds as $lId) {
                $lId = (int)trim($lId);
                if ($lId > 0) {
                    $stmtLink->bind_param("ii", $accountId, $lId);
                    $stmtLink->execute();
                }
            }
        }

        $conn->commit();
        return ['status' => $resStatus, 'id' => $accountId];

    } catch (Exception $e) {
        $conn->rollback();
        return ['status' => 'error', 'message' => 'Lỗi hệ thống: ' . $e->getMessage()];
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
    $sql = "SELECT a.*, u.username as author_name
            FROM accounts a
            LEFT JOIN authors u ON a.author_id = u.ID
            WHERE a.ID = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $account = $result->fetch_assoc();

    if (!$account) {
        return null;
    }

    // Lấy danh sách ID liên kết theo cả 2 chiều
    $sqlLink = "SELECT link_id FROM accounts_link WHERE account_id = ? 
            UNION 
            SELECT account_id FROM accounts_link WHERE link_id = ?";
    $stmtLink = $conn->prepare($sqlLink);
    $stmtLink->bind_param("ii", $id, $id); // Truyền ID vào cả 2 vị trí
    $stmtLink->execute();
    $resultLink = $stmtLink->get_result();

    $linkedAccounts = [];
    while ($row = $resultLink->fetch_assoc()) {
        // Vì dùng UNION nên kết quả trả về sẽ nằm chung ở cột đầu tiên (mặc định lấy tên cột của query 1)
        $linkedAccounts[] = $row['link_id'];
    }

    // Gán mảng liên kết vào dữ liệu account trả về
    $account['linked_ids'] = $linkedAccounts;

    return $account;
}