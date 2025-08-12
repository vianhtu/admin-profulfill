<?php
// index.php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

// Nếu tới đây, require_auth() sẽ tự redirect về login khi chưa hợp lệ
$user = require_auth();

// Nếu có payload hợp lệ, chuyển tới Dashboard
header('Location: dashboards.php');
exit;