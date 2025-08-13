<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

define('APP_NAME', 'SecureAuthDB');

// ===== Kết nối DB =====
function db(): mysqli {
	static $conn;
	if ($conn instanceof mysqli) return $conn;
	$conn = new mysqli('localhost', 'data', '519483@Pff', 'data');
	if ($conn->connect_error) {
		die('Database connection failed: ' . $conn->connect_error);
	}
	$conn->set_charset('utf8mb4');
	return $conn;
}

// ===== Session secure =====
function start_secure_session(): void {
	if (session_status() === PHP_SESSION_ACTIVE) return;
	$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
	session_set_cookie_params([
		'lifetime' => 0,
		'path' => '/',
		'secure' => $secure,
		'httponly' => true,
		'samesite' => 'Lax'
	]);
	session_name('APPSESSID_' . APP_NAME);
	session_start();
}
start_secure_session();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function flash_set(string $k, string $m): void { $_SESSION['flash'][$k] = $m; }
function flash_get(string $k): ?string {
	if (isset($_SESSION['flash'][$k])) { $m = $_SESSION['flash'][$k]; unset($_SESSION['flash'][$k]); return $m; }
	return null;
}
function csrf_token(): string {
	if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
	return $_SESSION['csrf'];
}
function csrf_verify($t): bool {
	return is_string($t) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

// ===== DB verify credentials =====
function verify_credentials(string $userOrEmail, string $password): bool {
	// Tìm theo username HOẶC email
	$sql = "SELECT pass FROM authors 
            WHERE username = ? OR email = ? 
            LIMIT 1";
	$stmt = db()->prepare($sql);
	$stmt->bind_param('ss', $userOrEmail, $userOrEmail);
	$stmt->execute();
	$stmt->bind_result($hash);
	if ($stmt->fetch()) {
		return password_verify($password, $hash);
	}
	return false;
}

// ===== Auth helpers =====
function user_agent_fingerprint(): string { return hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? 'ua'); }
function login_user(string $username): void {
	session_regenerate_id(true);
	$_SESSION['auth'] = ['user'=>$username, 'ua'=>user_agent_fingerprint(), 't'=>time()];
}
function is_logged_in(): bool {
	return !empty($_SESSION['auth']['user']) && hash_equals($_SESSION['auth']['ua'], user_agent_fingerprint());
}
function require_login(): void {
	if (!is_logged_in()) {
		header('Location: ./html/horizontal-menu-template-no-customizer/auth-login-basic.php');
		exit;
	}
}
function logout_user(): void {
	$_SESSION = [];
	if (ini_get('session.use_cookies')) {
		$p = session_get_cookie_params();
		setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
	}
	session_destroy();
}