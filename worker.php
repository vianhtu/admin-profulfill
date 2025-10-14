<?php
require __DIR__ . '/config.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/model/functions-gemini.php';
require __DIR__ . '/model/functions-openai.php';

$conn = db();
function writeLog($message): void
{
    $logFile = __DIR__ . "/worker.log";
    $time    = date("Y-m-d H:i:s");
    file_put_contents($logFile, "[$time] $message" . PHP_EOL, FILE_APPEND);
}

// Nếu được truyền ID từ cron-job.php thì xử lý trước job đó
$downloadId = (int)$argv[1] ?? 0;
$batch_name = $argv[2] ?? '';
$ai_name    = $argv[3] ?? '';

if ($downloadId == 0 || $batch_name == '' || $ai_name == '') {
    exit();
}
switch ($ai_name) {
    case 'google':
        $items = gemini_get_batches_by_name($batch_name);
        break;
    case 'openai':
        $items = openai_get_batches_by_name($batch_name);
        break;
    default:
        exit();
}

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
    $status = 'error';
    $_stmt = $conn->prepare("UPDATE download SET status = ?, locked_at = NULL WHERE ID = ?");
    $_stmt->bind_param("si", $status, $downloadId);
    $_stmt->execute();
    $_stmt->close();
    writeLog('Expired stop batch: ' . $downloadId);
} else {
    writeLog($items['message'] ?? 'Unknown error');
}