<?php
function handleFileUploads($conn, int $userId, array $files, string $type = '', string $id = ''): array
{
    $subDir = date('Y/m/d');
    $typeDir = $type ? $type . '/' : '';
    $idDir = $id ?? $subDir;
    $uploadDir = dirname(__DIR__) . '/uploads/' . $typeDir . $idDir . '/';
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf', 'xlsx', 'txt']; // Giới hạn loại file

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $insertedIds = [];

    // PHP $_FILES structure fix: chuyển về dạng mảng phẳng dễ xử lý
    $fileList = [];
    if (is_array($files['name'])) {
        foreach ($files['name'] as $key => $name) {
            $fileList[] = [
                'name'     => $files['name'][$key],
                'type'     => $files['type'][$key],
                'tmp_name' => $files['tmp_name'][$key],
                'error'    => $files['error'][$key],
                'size'     => $files['size'][$key],
            ];
        }
    } else {
        $fileList[] = $files;
    }

    foreach ($fileList as $file) {
        if ($file['error'] !== UPLOAD_ERR_OK) continue;

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        // Kiểm tra whitelist extension
        if (!in_array($extension, $allowedExtensions)) continue;

        // Kiểm tra MIME thực tế (an toàn hơn $_FILES['type'])
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        // Tạo UUID v4 chuẩn hơn
        $fileUuid = bin2hex(random_bytes(16)); // Hoặc dùng hàm sprintf của bạn
        $saveName = $fileUuid . '.' . $extension;
        $storagePath = 'uploads/' . $typeDir . $idDir . '/' . $saveName;
        $checksum = hash_file('sha256', $file['tmp_name']);

        if (move_uploaded_file($file['tmp_name'], $uploadDir . $saveName)) {
            try {
                $sql = "INSERT INTO files 
                        (file_uuid, user_id, file_name, storage_path, file_size, mime_type, extension, checksum, status, type, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())";

                $stmt = $conn->prepare($sql);
                // Sử dụng $realMimeType thay vì $file['type']
                $stmt->bind_param("sisssssss",
                    $fileUuid, $userId, $file['name'], $storagePath,
                    $file['size'], $realMimeType, $extension, $checksum, $type
                );

                if ($stmt->execute()) {
                    $insertedIds[] = $conn->insert_id;
                }
                $stmt->close();
            } catch (Exception $e) {
                if (file_exists($uploadDir . $saveName)) unlink($uploadDir . $saveName);
                error_log("Upload DB Error: " . $e->getMessage());
            }
        }
    }

    return $insertedIds;
}

function deletePhysicalFile(int $fileId): bool
{
    $conn = db();

    // 2. Lấy đường dẫn file trước khi xóa trong DB
    $sqlFile = "SELECT storage_path FROM files WHERE ID = ? LIMIT 1";
    $stmtFile = $conn->prepare($sqlFile);
    $stmtFile->bind_param("i", $fileId);
    $stmtFile->execute();
    $fileData = $stmtFile->get_result()->fetch_assoc();
    $stmtFile->close();

    if ($fileData) {
        // Xóa bản ghi trong bảng files
        $sqlDel = "DELETE FROM files WHERE ID = ?";
        $stmtDel = $conn->prepare($sqlDel);
        $stmtDel->bind_param("i", $fileId);

        if ($stmtDel->execute()) {
            // Xóa file vật lý trên ổ cứng
            $physicalPath = dirname(__DIR__) . '/' . $fileData['storage_path'];
            if (file_exists($physicalPath)) {
                unlink($physicalPath);
            }
            $stmtDel->close();
            return true;
        }
        $stmtDel->close();
    }

    return false;
}