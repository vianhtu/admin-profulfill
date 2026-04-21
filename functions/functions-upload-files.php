<?php
function handleFileUploads(int $userId, array $files, string $type = ''): array
{
    $conn = db();
    $subDir = date('Y/m/d');
    $type = $type ? $type . '/' : '';
    $uploadDir = dirname(__DIR__) . '/uploads/' . $type . $subDir . '/';
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'docx']; // Giới hạn loại file

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
        $storagePath = 'uploads/' . $type . $subDir . '/' . $saveName;
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