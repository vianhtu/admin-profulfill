<?php
function handleFileUploads(int $userId, array $files): array
{
    $conn = db();
    $uploadDir = dirname(__DIR__) . '/uploads/'; // Đường dẫn thư mục lưu trữ

    // Đảm bảo thư mục tồn tại
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $insertedIds = [];

    // Chuẩn hóa mảng $_FILES nếu chỉ có 1 file được upload
    // (PHP đôi khi cấu trúc mảng khác nhau tùy vào cách gửi từ Dropzone)
    $fileNames = (array)$files['name'];

    foreach ($fileNames as $key => $name) {
        // Kiểm tra lỗi upload
        if ($files['error'][$key] !== UPLOAD_ERR_OK) {
            continue;
        }

        $tmpName     = $files['tmp_name'][$key];
        $fileSize    = $files['size'][$key];
        $mimeType    = $files['type'][$key];
        $extension   = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        // 1. Tạo định danh duy nhất (UUID/Unique ID)
        // file_uuid trong DB của bạn là char(36)
        $fileUuid    = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        $saveName    = $fileUuid . '.' . $extension;
        $storagePath = 'uploads/' . $saveName;
        $checksum    = hash_file('sha256', $tmpName);

        // 2. Di chuyển file vào thư mục lưu trữ
        if (move_uploaded_file($tmpName, $uploadDir . $saveName)) {
            try {
                $sql = "INSERT INTO files 
                        (file_uuid, user_id, file_name, storage_path, file_size, mime_type, extension, checksum, status, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";

                $stmt = $conn->prepare($sql);
                // Cột: uuid(s), user_id(i), name(s), path(s), size(i), mime(s), ext(s), check(s)
                $stmt->bind_param("sissssss",
                    $fileUuid,
                    $userId,
                    $name,
                    $storagePath,
                    $fileSize,
                    $mimeType,
                    $extension,
                    $checksum
                );

                if ($stmt->execute()) {
                    $insertedIds[] = $conn->insert_id;
                }
                $stmt->close();
            } catch (Exception $e) {
                // Nếu lỗi DB thì xóa file vật lý để tránh rác server
                if (file_exists($uploadDir . $saveName)) {
                    unlink($uploadDir . $saveName);
                }
                error_log("Upload DB Error: " . $e->getMessage());
            }
        }
    }

    return $insertedIds; // Trả về mảng các ID trong bảng 'files'
}