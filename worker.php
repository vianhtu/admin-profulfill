<?php
require __DIR__ . '/config.php';
require __DIR__ . '/functions.php';

$conn = db();

// Nếu được truyền ID từ cron-job.php thì xử lý trước job đó
$downloadId = isset($argv[1]) ? (int)$argv[1] : 0;



function processDownload($downloadId, $conn): void
{
    // Gọi AIProcessProducts
    $log = AIProcessDownloadProducts($downloadId);

    // Xác định status mới
    $status = null;
    if (!empty($log['status'])) {
        if (in_array($log['status'], ['error', 'warning'])) {
            $status = 'error';
        } elseif ($log['status'] === 'done') {
            $status = 'ready';
        } elseif ($log['status'] === 'success') {
            $status = null; // chỉ bỏ lock
        }
    }

    // Cập nhật DB
    if ($status !== null) {
        $stmt = $conn->prepare("
            UPDATE download
            SET status = ?, locked_at = NULL
            WHERE ID = ?
        ");
        $stmt->bind_param("si", $status, $downloadId);
    } else {
        $stmt = $conn->prepare("
            UPDATE download
            SET locked_at = NULL
            WHERE ID = ?
        ");
        $stmt->bind_param("i", $downloadId);
    }
    $stmt->execute();
}