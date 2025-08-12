<?php
// dashboards.php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

// Lấy payload (chứa user_id) đã verify
$payload = require_auth();
$userId  = $payload['user_id'];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
	<meta charset="UTF-8">
	<title>Admin Dashboard</title>
</head>
<body>
<h1>Welcome, user #<?= htmlspecialchars((string)$userId) ?></h1>
<p>Đây là trang Dashboard của bạn.</p>
<form action="logout.php" method="post">
	<button type="submit">Logout</button>
</form>
</body>
</html>