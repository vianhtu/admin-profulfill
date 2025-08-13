<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Security headers (tùy bạn giữ/hoàn thiện thêm)
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

define('APP_NAME', 'SecureAuthDB');
define('REMEMBER_COOKIE', 'APPREMEMBER_' . APP_NAME);
define('REMEMBER_DURATION', 30 * 24 * 60 * 60); // 30 ngày

// ===== DB connection =====
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

// ===== Helpers =====
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
function user_agent_fingerprint(): string { return hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? 'ua'); }

// ===== Auth core =====
function login_user(string $username): void {
	session_regenerate_id(true);
	$_SESSION['auth'] = ['user'=>$username, 'ua'=>user_agent_fingerprint(), 't'=>time()];
}
function is_logged_in(): bool {
	return !empty($_SESSION['auth']['user']) && hash_equals($_SESSION['auth']['ua'], user_agent_fingerprint());
}
function require_login(): void {
	if (!is_logged_in()) {
		// Thử auto-login bằng cookie trước khi chuyển hướng
		if (!attempt_cookie_login()) {
			header('Location: ./html/horizontal-menu-template-no-customizer/auth-login-basic.php');
			exit;
		}
	}
}
function logout_user(): void {
	if (!empty($_SESSION['auth']['user'])) {
		// Lấy thông tin user hiện tại
		$username = $_SESSION['auth']['user'];

		// Xóa tất cả token remember-me của user này
		$stmt = db()->prepare("
            DELETE t FROM author_remember_tokens t
            JOIN authors a ON a.ID = t.author_id
            WHERE a.username = ? OR a.email = ?
        ");
		$stmt->bind_param('ss', $username, $username);
		$stmt->execute();
		$stmt->close();
	}

	// Xóa cookie remember-me
	clear_remember_cookie();

	// Xóa session
	$_SESSION = [];
	if (ini_get('session.use_cookies')) {
		$p = session_get_cookie_params();
		setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
	}
	session_destroy();
}

// ===== Login lookup (email OR username) =====
function find_author_by_login(string $userOrEmail): ?array {
	$sql = "SELECT ID, username, pass FROM authors WHERE username = ? OR email = ? LIMIT 1";
	$stmt = db()->prepare($sql);
	$stmt->bind_param('ss', $userOrEmail, $userOrEmail);
	$stmt->execute();
	$stmt->bind_result($id, $username, $hash);
	if ($stmt->fetch()) {
		$stmt->close();
		return ['id' => (int)$id, 'username' => (string)$username, 'hash' => (string)$hash];
	}
	$stmt->close();
	return null;
}
function get_username_by_id(int $id): ?string {
	$stmt = db()->prepare("SELECT username FROM authors WHERE ID = ? LIMIT 1");
	$stmt->bind_param('i', $id);
	$stmt->execute();
	$stmt->bind_result($username);
	if ($stmt->fetch()){
		$stmt->close();
		return (string)$username;
	}
	$stmt->close();
	return null;
}

// ===== Remember-me implementation =====
function b64url_encode(string $bin): string {
	return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
function b64url_decode(string $str): string {
	$pad = 4 - (strlen($str) % 4);
	if ($pad < 4) $str .= str_repeat('=', $pad);
	return base64_decode(strtr($str, '-_', '+/')) ?: '';
}

/**
 * Tạo token nhớ đăng nhập và set cookie. Xoay vòng token cũ nếu cùng selector.
 */
function set_remember_cookie(int $authorId): void {
	// 12 bytes ~ 16 chars b64url cho selector, 32 bytes cho validator
	$selector  = b64url_encode(random_bytes(12));
	$validator = random_bytes(32);
	$validator_b64 = b64url_encode($validator);
	$validator_hash = hash('sha256', $validator);
	$ua_hash = user_agent_fingerprint();
	$expires = time() + REMEMBER_DURATION;
	$expires_at = date('Y-m-d H:i:s', $expires);

	// Lưu vào DB
	$stmt = db()->prepare("INSERT INTO author_remember_tokens (author_id, selector, validator_hash, user_agent_hash, expires_at) VALUES (?, ?, ?, ?, ?)");
	$stmt->bind_param('issss', $authorId, $selector, $validator_hash, $ua_hash, $expires_at);
	$stmt->execute();

	// Lưu cookie: selector:validator
	$value = $selector . ':' . $validator_b64;
	setcookie(REMEMBER_COOKIE, $value, [
		'expires'  => $expires,
		'path'     => '/',
		'secure'   => true,
		'httponly' => true,
		'samesite' => 'Lax',
		'domain'   => '.profulfill.io',
	]);
}

/**
 * Thử auto-login bằng cookie. Trả về true nếu thành công.
 */
function attempt_cookie_login(): bool {
	if (is_logged_in()) return true;
	if (empty($_COOKIE[REMEMBER_COOKIE])) return false;

	$raw = $_COOKIE[REMEMBER_COOKIE];
	$parts = explode(':', $raw, 2);
	if (count($parts) !== 2) {
		clear_remember_cookie(); // format sai -> dọn
		return false;
	}
	[$selector, $validator_b64] = $parts;
	$validator = b64url_decode($validator_b64);
	if ($selector === '' || $validator === '') {
		clear_remember_cookie();
		return false;
	}

	// Lấy token từ DB
	$stmt = db()->prepare("SELECT author_id, validator_hash, user_agent_hash, expires_at FROM author_remember_tokens WHERE selector = ? LIMIT 1");
	$stmt->bind_param('s', $selector);
	$stmt->execute();
	$stmt->bind_result($authorId, $validator_hash_db, $ua_hash_db, $expires_at);
	if (!$stmt->fetch()) {
		$stmt->close();
		clear_remember_cookie();
		return false;
	}
	$stmt->close();

	// Kiểm tra hạn và UA
	if (strtotime($expires_at) < time() || !hash_equals((string)$ua_hash_db, user_agent_fingerprint())) {
		delete_remember_selector($selector);
		clear_remember_cookie();
		return false;
	}

	// So khớp hash validator
	$validator_hash = hash('sha256', $validator);
	if (!hash_equals((string)$validator_hash_db, $validator_hash)) {
		// Nghi ngờ bị đánh cắp cookie -> thu hồi token
		delete_remember_selector($selector);
		clear_remember_cookie();
		return false;
	}

	// Lấy username và đăng nhập
	$username = get_username_by_id((int)$authorId);
	if ($username === null) {
		delete_remember_selector($selector);
		clear_remember_cookie();
		return false;
	}

	login_user($username);

	// Xoay vòng token (xóa cũ, tạo mới)
	delete_remember_selector($selector);
	set_remember_cookie((int)$authorId);

	return true;
}

/**
 * Xóa token theo selector (nếu tồn tại).
 */
function delete_remember_selector(string $selector): void {
	$stmt = db()->prepare("DELETE FROM author_remember_tokens WHERE selector = ? LIMIT 1");
	$stmt->bind_param('s', $selector);
	$stmt->execute();
	$stmt->close();
}

/**
 * Xóa cookie và token hiện tại (nếu có trong DB).
 */
function clear_remember_cookie(): void {
	if (!empty($_COOKIE[REMEMBER_COOKIE])) {
		$raw = $_COOKIE[REMEMBER_COOKIE];
		$parts = explode(':', $raw, 2);
		if (count($parts) === 2 && $parts[0] !== '') {
			delete_remember_selector($parts[0]);
		}
	}
	$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
	setcookie(REMEMBER_COOKIE, '', [
		'expires'  => time() - 3600,
		'path'     => '/',
		'secure'   => $secure,
		'httponly' => true,
		'samesite' => 'Lax',
	]);
}