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

define('ROOT_DIR', __DIR__);
define('GEMINI_API_KEY', 'AIzaSyALP80h2H1We1RA6Jl5cvFPlbYK0Zh29RE');
define('ENCRYPT_KEY', '8f42e3b2b8564e529a1b926a738c8531c3656912456789');
define('BASE_URL', 'https://profulfill.io/admin-profulfill/');
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
function login_user(array $username): void {
	session_regenerate_id(true);
	$_SESSION['auth'] = [
        'user'=>$username['username'],
        'ua'=>user_agent_fingerprint(),
        't'=>time(),
        'user_id'=> $username['id'],
        'team' => $username['team'],
        'level'=>$username['level'],
        'roles'=>$username['roles']
    ];
}
function is_logged_in(): bool {
	return !empty($_SESSION['auth']['user']) && hash_equals($_SESSION['auth']['ua'], user_agent_fingerprint());
}
function require_login(): void {
	if (!is_logged_in()) {
		// Thử auto-login bằng cookie trước khi chuyển hướng
		if (!attempt_cookie_login()) {
			header('Location: ./html/vertical-menu-template-no-customizer/auth-login-basic.php');
			exit;
		}
	}
}
function logout_user(): void {
    $db = db();

    // 1. Xóa Token Remember-me trong Database trước khi hủy Session
    $userId = $_SESSION['auth']['user_id'] ?? null;
    if ($userId) {
        $stmt = $db->prepare("DELETE FROM author_remember_tokens WHERE author_id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    clear_remember_cookie();

    // 2. Xóa dữ liệu Session trong bộ nhớ (RAM)
    $_SESSION = [];

    // 3. Xóa Session Cookie để trình duyệt hủy định danh phiên làm việc
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    // 4. Hủy file session vật lý trên Server
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function get_user_data(int|string $identifier): ?array {

    // Xác định cột cần tìm dựa trên kiểu dữ liệu của identifier
    $column = is_int($identifier) ? "a.ID" : (filter_var($identifier, FILTER_VALIDATE_EMAIL) ? "a.email" : "a.username");

    $sql = "
        SELECT 
            a.ID as id, 
            a.username,
            a.pass as hash,
            a.team_id as team, 
            rl.roles, 
            rl.slug as level
        FROM authors a
        LEFT JOIN roles_permissions rl ON rl.ID = a.level
        WHERE {$column} = ?
        LIMIT 1
    ";

    try {
        // Thực thi truy vấn và lấy result object ngay lập tức
        $result = db()->execute_query($sql, [$identifier]);

        // Fetch dữ liệu dưới dạng mảng kết hợp (associative array)
        $user = $result->fetch_assoc();

        if ($user) {
            // Xử lý decode JSON cho roles (sử dụng toán tử ?? của PHP)
            $user['roles'] = json_decode($user['roles'] ?? '[]', true);

            // Ép kiểu dữ liệu để đảm bảo tính nhất quán
            $user['id'] = (int)$user['id'];
            $user['team'] = (int)$user['team'];
            return $user;
        }
    } catch (mysqli_sql_exception $e) {
        // Log lỗi nếu cần: error_log($e->getMessage());
        return null;
    }

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
    // Tự động nhận diện HTTPS để tránh lỗi trên localhost
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
	setcookie(REMEMBER_COOKIE, $value, [
		'expires'  => $expires,
		'path'     => '/',
		'secure'   => $secure,
		'httponly' => true,
		'samesite' => 'Lax'
	]);
}

/**
 * Thử auto-login bằng cookie. Trả về true nếu thành công.
 */
function attempt_cookie_login(): bool {
	if (is_logged_in()) return true;
	if (empty($_COOKIE[REMEMBER_COOKIE])) return false;

	$raw = urldecode($_COOKIE[REMEMBER_COOKIE]);
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
	$user = get_user_data((int)$authorId);
	if ($user === null) {
		delete_remember_selector($selector);
		clear_remember_cookie();
		return false;
	}

	login_user($user);

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
    // Tự động nhận diện HTTPS để tránh lỗi trên localhost
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
	setcookie(REMEMBER_COOKIE, '', [
		'expires'  => time() - 3600,
		'path'     => '/',
		'secure'   => $secure,
		'httponly' => true,
		'samesite' => 'Lax',
	]);
}

function check_level(string $target): bool {
    return strtolower((string)($_SESSION['auth']['level'] ?? '')) === strtolower($target);
}

/**
 * So khớp csrf_token trong POST với token của session (chống CSRF).
 * Dùng cho MỌI hành động ghi/xóa, kể cả xóa/sửa hàng loạt — không chỉ form save.
 */
function check_csrf(): bool {
    return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token']);
}

function is_admin(): bool { return check_level('admin'); }
function is_manager(): bool { return check_level('manager'); }
function is_user(): bool { return check_level('user'); }
function is_customer(): bool { return check_level('customer'); }

// Kiểm tra nhân sự cấp cao (Admin + Manager)
function is_staff(): bool {
    return is_admin() || is_manager();
}

// Kiểm tra toàn bộ nhân viên nội bộ (Admin + Manager + User)
function is_internal(): bool {
    return is_admin() || is_manager() || is_user();
}

/**
 * Mã hóa dữ liệu
 */
function encrypt($data): string
{
    // AES-256 yêu cầu key dài 32 bytes. Ta dùng hash để đảm bảo key luôn đúng độ dài.
    $hashed_key = hash('sha256', ENCRYPT_KEY, true);
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));

    $encrypted = openssl_encrypt($data, 'aes-256-cbc', $hashed_key, 0, $iv);

    // Trả về chuỗi bao gồm IV để sau này giải mã (Base64 để lưu vào DB an toàn)
    return base64_encode($iv . '::' . $encrypted);
}

/**
 * Giải mã dữ liệu
 */
function decrypt($data): false|string
{
    $hashed_key = hash('sha256', ENCRYPT_KEY, true);
    $decoded = base64_decode($data);

    if (str_contains($decoded, '::')) {
        list($iv, $encrypted_data) = explode('::', $decoded, 2);
        return openssl_decrypt($encrypted_data, 'aes-256-cbc', $hashed_key, 0, $iv);
    }
    return false;
}