<?php
require __DIR__ . '/config.php';
require __DIR__ . '/functions.php';

$conn = db();

// Lấy download_id từ tham số dòng lệnh
$downloadId = isset($argv[1]) ? (int)$argv[1] : 0;
if (!$downloadId) {
    exit(1);
}

// Gọi AIProcessProducts với tham số
$log = AIProcessProducts($downloadId);

// Sau khi xử lý xong, cập nhật trạng thái và giải phóng lock
$status = null; // mặc định không cập nhật status

if (!empty($log['status'])) {
    if (in_array($log['status'], ['error', 'warning'])) {
        $status = 'error';
    } elseif ($log['status'] === 'done') {
        $status = 'ready';
    } elseif ($log['status'] === 'success') {
        $status = null; // chỉ bỏ lock, không đổi status
    }
}

if ($status !== null) {
    // Cập nhật cả status và locked_at
    $stmt = $conn->prepare("
        UPDATE download
        SET status = ?, locked_at = NULL
        WHERE ID = ?
    ");
    $stmt->bind_param("si", $status, $downloadId);
} else {
    // Chỉ bỏ lock
    $stmt = $conn->prepare("
        UPDATE download
        SET locked_at = NULL
        WHERE ID = ?
    ");
    $stmt->bind_param("i", $downloadId);
}

$stmt->execute();

// Ghi log ra file (tùy chọn)
$logFile = __DIR__ . '/worker.log';
file_put_contents(
    $logFile,
    date('Y-m-d H:i:s') . " - ID {$downloadId} - " . json_encode($log, JSON_UNESCAPED_UNICODE) . PHP_EOL,
    FILE_APPEND
);