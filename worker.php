<?php
require __DIR__ . '/config.php';
require __DIR__ . '/functions.php';

$conn = db();

// Nếu được truyền ID từ cron-job.php thì xử lý trước job đó
$downloadId = isset($argv[1]) ? (int)$argv[1] : 0;

while (true) {
    if ($downloadId) {
        // Xử lý job được truyền vào
        processDownload($downloadId, $conn);
        $downloadId = 0; // reset để vòng lặp lấy job mới
    } else {
        // Tìm job mới
        $sql = "SELECT ID 
                FROM download
                WHERE status IN ('schedule', 'running')
                  AND (locked_at IS NULL OR locked_at < NOW() - INTERVAL 2 MINUTE)
                ORDER BY id ASC
                LIMIT 1
                ";
        $result = $conn->query($sql);

        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();

            // Lock job
            $stmt = $conn->prepare("UPDATE download SET status='running', locked_at=NOW() WHERE ID=?");
            $stmt->bind_param("i", $row['ID']);
            $stmt->execute();

            processDownload($row['ID'], $conn);
        } else {
            // Không còn job → thoát
            break;
        }
    }
}

function processDownload($downloadId, $conn): void
{
    // Gọi AIProcessProducts
    $log = AIProcessProducts($downloadId);

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

    // Ghi log
    $logFile = __DIR__ . '/worker.log';
    file_put_contents(
        $logFile,
        date('Y-m-d H:i:s') . " - ID {$downloadId} - " . json_encode($log, JSON_UNESCAPED_UNICODE) . PHP_EOL,
        FILE_APPEND
    );
}