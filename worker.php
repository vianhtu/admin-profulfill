<?php
require __DIR__ . '/config.php';
require __DIR__ . '/functions.php';

function writeLog($message): void
{
    $logFile = __DIR__ . "/worker.log";
    $time    = date("Y-m-d H:i:s");
    file_put_contents($logFile, "[$time] $message" . PHP_EOL, FILE_APPEND);
}

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
            $newId = insertAmazonListingFromAI($downloadId, $key, $item['data']);
            // Cập nhật trạng thái bài viết
            $updateStmt = $conn->prepare("UPDATE posts SET status = 'listed', updated_at = NOW() WHERE ID = ?");
            $updateStmt->bind_param("i", $key);
            $updateStmt->execute();
            $updateStmt->close();
            $total_token += $item['total_token'];
        }
        $status = 'ready';
        $stmt = $conn->prepare("UPDATE download SET status = ?, total_token = ? , locked_at = NULL WHERE ID = ?");
        $stmt->bind_param("sii", $status, $total_token, $downloadId);
        $stmt->execute();
        $stmt->close();
        writeLog('Success: ' . $total_token . ' token used');
    } catch (Exception $e) {
        writeLog($e->getMessage());
    }
} elseif ($items['status'] == 'expired'){
    writeLog(json_encode($items)); exit();
    $batch_result = gemini_create_batches($downloadId, $items['file_name']);
    if ($batch_result['status'] == 'error') {
        writeLog($batch_result['message']);
    } else {
        $batch_id = $batch_result['batch']['name'];
        // update. job name to data.
        $stmt2 = $conn->prepare("UPDATE download SET batch_name = ? WHERE ID = ?");
        $stmt2->bind_param("si", $batch_id, $downloadId);
        $stmt2->execute();
        $stmt2->close();
        writeLog('Expired create new batch: ' . $batch_id);
    }
} else {
    writeLog($items['message'] ?? 'Unknown error');
}