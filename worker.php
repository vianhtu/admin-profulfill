<?php
require __DIR__ . '/config.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/model/functions-gemini.php';
require __DIR__ . '/model/functions-openai.php';

$conn       = db();
$downloadId = (int)$argv[1] ?? 0;
$batch_name = $argv[2] ?? '';
$ai_name    = $argv[3] ?? '';
$team_id    = (int)$argv[4] ?? 0;

if ($downloadId == 0 || $batch_name == '' || $ai_name == '' || $team_id == 0) {
    $conn->close();
    exit();
}

function writeLog($message): void
{
    global $downloadId, $batch_name;
    $logFile = __DIR__ . "/worker.log";
    $time    = date("Y-m-d H:i:s");
    file_put_contents($logFile, "[$time] [$downloadId] [$batch_name] $message" . PHP_EOL, FILE_APPEND);
}

function updateDownload($conn, $id, $fields): bool
{
    try {
        // Tạo danh sách "field = ?" từ mảng $fields
        $setParts = [];
        $values = [];
        $types = "";

        foreach ($fields as $column => $value) {
            $setParts[] = "$column = ?";
            $values[] = $value;

            // Xác định kiểu dữ liệu cho bind_param
            if (is_int($value)) {
                $types .= "i";
            } elseif (is_float($value)) {
                $types .= "d";
            } else {
                $types .= "s";
            }
        }

        // Ghép chuỗi SET
        $setClause = implode(", ", $setParts);

        // Thêm điều kiện WHERE
        $sql = "UPDATE download SET $setClause WHERE id = ?";

        $stmt = $conn->prepare($sql);

        // Thêm id vào cuối mảng values
        $values[] = $id;
        $types .= "i";

        // bind_param yêu cầu truyền tham chiếu
        $stmt->bind_param($types, ...$values);

        $result = $stmt->execute();

        $stmt->close();

        return $result;
    } catch (\Throwable $e) {
        writeLog("updateDownload error: " . $e->getMessage());
        return false;
    }
}

function updatePostStatus($conn, $ids): bool
{
    try {
        // Đảm bảo $ids là mảng số nguyên
        $ids = array_map('intval', $ids);

        if (empty($ids)) {
            return false;
        }

        // Tạo chuỗi placeholder tương ứng số lượng id
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $sql = "UPDATE posts 
                SET status = 'listed', updated_at = NOW() 
                WHERE ID IN ($placeholders)";

        $stmt = $conn->prepare($sql);

        // Tạo kiểu dữ liệu cho bind_param (toàn bộ là integer)
        $types = str_repeat('i', count($ids));

        // bind_param cần truyền tham chiếu
        $stmt->bind_param($types, ...$ids);

        $result = $stmt->execute();

        $stmt->close();

        return $result;
    } catch (\Throwable $e) {
        writeLog("updatePostStatus error: " . $e->getMessage());
        return false;
    }
}

session_start();
$_SESSION['auth'] = ['user_id' => 0, 'team' => $team_id];

switch ($ai_name) {
    case 'google':
        $batch = gemini_get_batches_by_name($batch_name);
        break;
    case 'openai':
        $batch = openai_get_batches_by_name($batch_name);
        break;
    default:
        $conn->close();
        exit();
}

if($batch['status'] !== 'success'){
    writeLog($batch['message'] ?? 'Unknown error');
    $conn->close();
    exit();
}

if($batch['data']['status'] == 'expired' || $batch['data']['status'] == 'error'){
    updateDownload($conn, $downloadId, [
        'status' => $batch['data']['status']
    ]);
} elseif ($batch['data']['status'] == 'running' && $batch['data']['completed'] != 0){
    updateDownload($conn, $downloadId, [
        'completed_items' => (int)$batch['data']['completed']
    ]);
} elseif ($batch['data']['status'] == 'ready' && is_array($batch['data']['file'])){
    $products = $batch['data']['file'];
    $ids = [];
    foreach ($products as $item) {
        $insert = insertAmazonListingFromAI($conn, $downloadId, $item['id'], $item['json']);
        if($insert['status'] === 'inserted'){
            $ids[] = (int)$insert['id'];
        } else {
            writeLog("Insert {$item['id']} failed: {$insert['message']}");
        }
    }
    if(!updatePostStatus($conn, $ids)){
        $conn->close();
        exit();
    }
    $input_tokens = $batch['data']['input_tokens'] ?? 0;
    $output_tokens = $batch['data']['output_tokens'] ?? 0;
    if(updateDownload($conn, $downloadId, [
        'status'            => $batch['data']['status'],
        'completed_items'   => $batch['data']['completed'] ?? 0,
        'failed_items'      => $batch['data']['failed'] ?? 0,
        'input_tokens'      => $input_tokens,
        'output_tokens'     => $output_tokens,
        'total_token'       => $input_tokens + $output_tokens,
        'locked_at'         => NULL
    ])){
        writeLog("Update success: input_tokens {$input_tokens} output_tokens {$output_tokens} completed_items {$batch['data']['completed']} failed_items {$batch['data']['failed']}");
    }
    $conn->close();
}