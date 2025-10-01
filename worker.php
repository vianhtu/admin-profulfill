<?php
require __DIR__ . '/config.php';
require __DIR__ . '/functions.php';

$conn = db();

// Nếu được truyền ID từ cron-job.php thì xử lý trước job đó
$downloadId = (int)$argv[1] ?? 0;
$batch_name = $argv[2] ?? '';

if ($downloadId == 0 || $batch_name == '') {
    exit();
}

$items = gemini_get_batches_by_name($batch_name);
if ($items['status'] == 'success' && count($items['items']) > 0) {
    try {
        $total_token = 0;
        foreach ($items['items'] as $key => $item) {
            $id = (int)str_replace('request-', '', $key);
            $newId = insertAmazonListingFromAI($downloadId, $id, $item['data']);
            // Cập nhật trạng thái bài viết
            $updateStmt = $conn->prepare("UPDATE posts SET status = 'listed', updated_at = NOW() WHERE ID = ?");
            $updateStmt->bind_param("i", $id);
            $updateStmt->execute();
            $updateStmt->close();
            $total_token += $item['total_token'];
        }
        $status = 'ready';
        $stmt = $conn->prepare("UPDATE download SET status = ?, total_token = ? , locked_at = NULL WHERE ID = ?");
        $stmt->bind_param("sii", $status, $total_token, $downloadId);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        writeLog($e->getMessage());
    }
} else {
    writeLog($items['message'] ?? 'Unknown error');
}

function writeLog($message) {
    $logFile = __DIR__ . "/worker.log";
    $time    = date("Y-m-d H:i:s");
    file_put_contents($logFile, "[$time] $message" . PHP_EOL, FILE_APPEND);
}