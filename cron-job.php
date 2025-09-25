<?php
require __DIR__ . '/config.php';

$conn = db();

// Lấy 10 row từ bảng download có status = 'schedule'
$sql = "SELECT ID,status,locked_at
        FROM download
        WHERE status IN ('schedule', 'running')
        ORDER BY id ASC
        LIMIT 10";

$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $runWorker = false;

        if (empty($row['locked_at'])) {
            // Chưa bị khóa → chạy luôn
            $runWorker = true;
        } else {
            // locked_at có giá trị → kiểm tra quá 2 phút chưa
            $lockedTime = strtotime($row['locked_at']);
            if (time() - $lockedTime > 60) { // 60 giây = 1 phút
                $runWorker = true;
            }
        }

        if ($runWorker) {
            // Cập nhật locked_at để tránh tiến trình khác lấy trùng
            $stmt = $conn->prepare("UPDATE download SET locked_at = NOW(), status = 'running' WHERE ID = ?");
            $stmt->bind_param("i", $row['ID']);
            $stmt->execute();

            // Spawn worker
            $cmd = "php " . __DIR__ . "/worker.php {$row['ID']} > /dev/null 2>&1 &";
            exec($cmd);
        }
    }
}
