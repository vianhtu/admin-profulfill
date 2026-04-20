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
    $status_val = (int)$_POST['status'];
    $sys_date  = date('Y-m-d');

    try {
        $conn->begin_transaction();

        if ($id) {
            // --- LOGIC UPDATE ---
            $sql = "UPDATE accounts SET 
                    site_id=?, team_id=?, author_id=?, name=?, email=?, password=?, 2fa=?, 
                    address=?, dob=?, ssn=?, phone=?, user_id=?, sku=?, note=?, status=? 
                    WHERE ID=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iiisssssssssssii",
                $site_id, $team_id, $author_id, $name, $email, $password, $two_fa,
                $address, $dob, $ssn, $phone, $user_id, $sku, $note, $status_val, $id
            );
            $stmt->execute();
            $accountId = $id;
            $resStatus = 'updated';
        } else {
            // --- LOGIC INSERT ---
            $sql = "INSERT INTO accounts 
                    (site_id, team_id, author_id, name, email, password, 2fa, address, dob, ssn, phone, user_id, sku, note, status, created_date) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iiisssssssssssis",
                $site_id, $team_id, $author_id, $name, $email, $password, $two_fa,
                $address, $dob, $ssn, $phone, $user_id, $sku, $note, $status_val, $sys_date
            );
            $stmt->execute();
            $accountId = $conn->insert_id;
            $resStatus = 'inserted';
        }

        // 3. Xử lý bảng accounts_link (Liên kết nhiều tài khoản)
        // Luôn xóa các liên kết cũ của account này trước khi chèn mới (cho cả Insert/Update)
        $conn->query("DELETE FROM accounts_link WHERE account_id = $accountId");

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