<?php
require __DIR__ . '/config.php';

$conn = db();

// Lấy 10 row từ bảng download có status = 'running'
$sql = "SELECT d.ID,d.locked_at, d.batch_name, d.ai_name, a.team_id
        FROM download d
        INNER JOIN authors a ON a.ID = d.author_id
        WHERE d.status = 'running'
        AND (d.locked_at IS NULL OR d.locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
        ORDER BY d.ID ASC
        LIMIT 10";

$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // Cập nhật locked_at để tránh tiến trình khác lấy trùng
        $stmt = $conn->prepare("UPDATE download SET locked_at = NOW() WHERE ID = ?");
        $stmt->bind_param("i", $row['ID']);
        $stmt->execute();

        // Spawn worker
        $cmd = "php " . __DIR__ . "/worker.php {$row['ID']} {$row['batch_name']} {$row['ai_name']} {$row['team_id']} > /dev/null 2>&1 &";
        exec($cmd);
    }
}
