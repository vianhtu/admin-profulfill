<?php
require __DIR__ . '/config.php';

$conn = db();

// Lấy 10 row từ bảng download có status = 'running'
$sql = "SELECT ID,locked_at, batch_name, ai_name
        FROM download
        WHERE status = 'running'
        AND (locked_at IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
        ORDER BY id ASC
        LIMIT 10";

$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // Cập nhật locked_at để tránh tiến trình khác lấy trùng
        $stmt = $conn->prepare("UPDATE download SET locked_at = NOW() WHERE ID = ?");
        $stmt->bind_param("i", $row['ID']);
        $stmt->execute();

        // Spawn worker
        $cmd = "php " . __DIR__ . "/worker.php {$row['ID']} {$row['batch_name']} {$row['ai_name']} > /dev/null 2>&1 &";
        exec($cmd);
    }
}
