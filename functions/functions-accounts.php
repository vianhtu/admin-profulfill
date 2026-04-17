<?php
function addAccount(): array {
    return $_POST;
    if(!checkRoles(['add','edit'], 'exports_xlsx')){
        return [
            'status'  => 'error',
            'message' => 'Bạn Không có quyền thêm và sửa file excel'
        ];
    }
    $conn = db();
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $csrfToken !== $_SESSION['csrf_token']) {
        return ['status' => 'error', 'message' => 'CSRF token không hợp lệ'];
    }

    // Kiểm tra xem có file mới được upload không
    $fileUploaded = isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK;
    $originalName = '';
    $uniqueName   = '';

    // Nếu có file mới, xử lý kiểm tra và lưu
    if ($fileUploaded) {
        $file         = $_FILES['file'];
        $originalName = $file['name'];
        $extension    = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedMime  = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        if ($file['type'] !== $allowedMime || $extension !== 'xlsx') {
            return ['status' => 'error', 'message' => 'Chỉ chấp nhận file .xlsx'];
        }

        $uniqueName = uniqid('export_', true) . '.xlsx';
        $uploadDir  = __DIR__ . '/xlsx/';
        $targetPath = $uploadDir . $uniqueName;

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            return ['status' => 'error', 'message' => 'Không thể lưu file'];
        }
    }

    // Dữ liệu từ form
    $id           = $_POST['id'] ?? null;
    $site_id      = (int) ($_POST['site'] ?? 0);
    $type_id      = (int) ($_POST['type'] ?? 0);
    $accounts_id  = (int) ($_POST['account'] ?? 0);
    $authors_id = (int)(
    is_admin() && !empty($_POST['author'])
        ? $_POST['author']
        : ($_SESSION['auth']['user_id'] ?? 0)
    );
    $date_create  = date('Y-m-d H:i:s');
    $xlsx_options = $_POST['options'] ?? '';
    $row_header = (int) ($_POST['header'] ?? 0);
    $row_item = (int) ($_POST['startRow'] ?? 0);
    $sheet_name = $_POST['sheet_name'] ?? '';

    // Nếu có ID, kiểm tra bản ghi để cập nhật
    if ($id) {
        $check = $conn->prepare("SELECT file_name, file_dir FROM exports WHERE id = ?");
        $check->bind_param("i", $id);
        $check->execute();
        $result = $check->get_result();

        if ($result->num_rows > 0) {
            $row         = $result->fetch_assoc();
            $oldFileName = $row['file_name'];
            $oldFileDir  = $row['file_dir'];

            // Nếu có file mới và tên khác, xóa file cũ
            if ($fileUploaded && $originalName !== $oldFileName && file_exists(__DIR__ . '/xlsx/' . $oldFileDir)) {
                unlink(__DIR__ . '/xlsx/' . $oldFileDir);
            }

            // Nếu không có file mới, giữ nguyên tên và đường dẫn file cũ
            if (!$fileUploaded) {
                $originalName = $oldFileName;
                $uniqueName   = $oldFileDir;
            }

            // Cập nhật bản ghi
            $update = $conn->prepare("
                UPDATE exports SET
                    accounts_id = ?, type_id = ?, site_id = ?, authors_id = ?,
                    date_create = ?, file_name = ?, file_dir = ?, file_default = ?, row_header = ?, row_item = ?, sheet_name = ?
                WHERE id = ?
            ");
            $update->bind_param("iiiissssiisi", $accounts_id, $type_id, $site_id, $authors_id, $date_create, $originalName, $uniqueName, $xlsx_options, $row_header, $row_item, $sheet_name, $id);

            if ($update->execute()) {
                return ['status' => 'updated', 'id' => $id, 'file' => $uniqueName];
            } else {
                return ['status' => 'error', 'message' => 'Lỗi khi cập nhật dữ liệu'];
            }
        }
    }

    // Nếu không có ID hoặc không tìm thấy bản ghi, thêm mới
    if (!$fileUploaded) {
        return ['status' => 'error', 'message' => 'Vui lòng chọn file .xlsx để thêm mới'];
    }

    $insert = $conn->prepare("
        INSERT INTO exports (accounts_id, type_id, site_id, authors_id, date_create, file_name, file_dir, file_default, row_header, row_item)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insert->bind_param("iiiissssii", $accounts_id, $type_id, $site_id, $authors_id, $date_create, $originalName, $uniqueName, $xlsx_options, $row_header, $row_item);

    if ($insert->execute()) {
        return ['status' => 'inserted', 'id' => $insert->insert_id, 'file' => $uniqueName];
    } else {
        return ['status' => 'error', 'message' => 'Lỗi khi thêm dữ liệu'];
    }
}