<?php
// config.php
declare(strict_types=1);

// 1. Kết nối DB qua PDO
define('DB_HOST', 'localhost');
define('DB_NAME', 'data');
define('DB_USER', 'data');
define('DB_PASS', '519483@Pff');
$options = [
	PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
	PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

// 2. Cấu hình JWT
define('JWT_SECRET', 'vLTaFpPEW3Wvm5Rgymattm7SVNd6Jvb1DXmaph8M38R0AAyyEb9cso7rvMoi4c1b'); // Đổi thành biến môi trường
define('JWT_ALGO', 'HS256');
define('JWT_EXPIRE', 3600); // 1 giờ

// 3. Cookie settings
define('COOKIE_NAME', 'rvMoi4c1b_auth_token');
define('COOKIE_PATH', '/');
define('COOKIE_DOMAIN', '45.76.185.106'); // ví dụ '.yourdomain.com'
//define('COOKIE_SECURE', true);        // chỉ HTTPS
define('COOKIE_HTTPONLY', true);      // JS không thể đọc
define('COOKIE_SAMESITE', 'Strict');  // ngăn CSRF

/*
 * Bật/Tắt debug
 * Đặt thành true trên development, false trên production
 */
define('DEBUG', true);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);