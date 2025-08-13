<?php
// dashboards.php$
declare(strict_types=1);
require __DIR__ . '/config.php';
require_login();
$user = $_SESSION['auth']['user'] ?? 'user';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>Bảng điều khiển</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; padding: 24px; background: #f6f7fb; }
        .wrap { max-width: 960px; margin: 0 auto; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        a.btn { display: inline-block; padding: 8px 12px; background: #1f6feb; color: #fff; border-radius: 8px; text-decoration: none; }
        .card { background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <h1>Xin chào, <?= h($user) ?> 👋</h1>
        <a class="btn" href="/auth.php?action=logout">Đăng xuất</a>
    </header>

    <div class="card">
        <p>Bạn đã đăng nhập thành công. Đây là trang bảng điều khiển.</p>
        <ul>
            <li>Phiên đăng nhập được bảo vệ bằng cookie HttpOnly, SameSite và Secure (khi chạy HTTPS).</li>
            <li>Có CSRF token cho form đăng nhập và hạn chế brute-force cơ bản.</li>
        </ul>
    </div>
</div>
</body>
</html>