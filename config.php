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

// ===== Team ngừng hoạt động =====
// Team có status = 0 (Inactive) thì MỌI hoạt động liên quan bị chặn: đăng nhập, phiên
// đang mở, auto-login bằng cookie, ajax admin, và API extension (key team / key author).
// ADMIN không bị chặn — nếu không, tắt chính team của admin sẽ tự nhốt luôn người duy
// nhất bật lại được.

/**
 * Team này có đang hoạt động không. Chưa gán team (id <= 0) coi như không có gì để khóa.
 * Team không tồn tại -> coi là KHÓA (dữ liệu mồ côi thì chặn cho an toàn).
 */
/**
 * Cache trạng thái truy cập theo MỘT REQUEST (đổi trong DB là request sau ăn ngay).
 * Để ở biến toàn cục thay vì `static` để bộ kiểm thử CLI — vốn chạy hàng nghìn "request"
 * trong cùng một tiến trình — có thể xóa giữa các vai bằng reset_access_cache().
 */
$GLOBALS['__access_cache'] = ['team' => [], 'user' => [], 'user_team' => [], 'perms' => [], 'own_level' => []];

function reset_access_cache(): void {
	$GLOBALS['__access_cache'] = ['team' => [], 'user' => [], 'user_team' => [], 'perms' => [], 'own_level' => []];
}

function team_is_active(int $teamId): bool {
	if ($teamId <= 0) {
		return true;
	}
	if (isset($GLOBALS['__access_cache']['team'][$teamId])) {
		return $GLOBALS['__access_cache']['team'][$teamId];
	}
	try {
		$row = db()->execute_query('SELECT status FROM team WHERE ID = ? LIMIT 1', [$teamId])->fetch_row();
	} catch (mysqli_sql_exception) {
		// DB hỏng thì cả app đã không chạy được; không tự khóa thêm người dùng ở đây.
		return true;
	}
	return $GLOBALS['__access_cache']['team'][$teamId] = ($row !== null && (int)$row[0] === 1);
}

/**
 * Tài khoản này có đang hoạt động không (authors.status = 2).
 * Trạng thái Pending/Inactive -> không cho đăng nhập và không cho dùng tiếp phiên đang mở.
 */
/** Tài khoản còn tồn tại trong DB không (phân biệt "bị khóa" với "đã bị xóa"). */
function user_exists(int $userId): bool {
	if ($userId <= 0) {
		return false;
	}
	try {
		return db()->execute_query('SELECT 1 FROM authors WHERE ID = ? LIMIT 1', [$userId])->fetch_row() !== null;
	} catch (mysqli_sql_exception) {
		return true;
	}
}

function user_is_active(int $userId): bool {
	if ($userId <= 0) {
		return false;
	}
	if (isset($GLOBALS['__access_cache']['user'][$userId])) {
		return $GLOBALS['__access_cache']['user'][$userId];
	}
	try {
		$row = db()->execute_query('SELECT status FROM authors WHERE ID = ? LIMIT 1', [$userId])->fetch_row();
	} catch (mysqli_sql_exception) {
		return true;   // DB hỏng thì cả app đã không chạy được; không tự khóa thêm ở đây
	}
	return $GLOBALS['__access_cache']['user'][$userId] = ($row !== null && (int)$row[0] === 2);
}

/**
 * Phiên đăng nhập hiện tại có bị chặn không — vì team ngừng hoạt động HOẶC vì chính tài
 * khoản bị khóa. Gọi ở require_login() (trang) và ajax.php (API nội bộ).
 *
 * Admin được miễn phần TEAM (tránh tự nhốt khi tắt team của mình) nhưng KHÔNG được miễn
 * phần tài khoản: admin bị khóa tài khoản thì cũng phải dừng.
 */
function access_block_reason(): ?string {
	if (empty($_SESSION['auth'])) {
		return null;
	}
	$uid  = (int)($_SESSION['auth']['user_id'] ?? 0);
	$team = (int)($_SESSION['auth']['team'] ?? 0);

	if (!user_is_active($uid)) {
		// Không còn bản ghi = tài khoản đã bị xóa (vd. xóa cả team), khác với bị khóa
		return user_exists($uid) ? USER_INACTIVE_MESSAGE : USER_DELETED_MESSAGE;
	}
	// Bị admin chuyển sang team khác trong lúc đang đăng nhập: session vẫn giữ team CŨ
	// (login_user chụp team một lần lúc đăng nhập) nên vẫn thao tác được trên phạm vi cũ.
	// Buộc đăng nhập lại để nhận đúng team mới.
	if (user_team_changed($uid, $team)) {
		return TEAM_CHANGED_MESSAGE;
	}
	// Bị đổi nhóm quyền, hoặc nhóm quyền của họ bị sửa nội dung: session vẫn giữ bộ quyền
	// CŨ (login_user chụp level + roles một lần) nên thu hồi quyền sẽ không có tác dụng gì
	// tới khi họ tự đăng xuất. Buộc đăng nhập lại để nhận đúng quyền hiện hành.
	if (user_perms_changed($uid, (string)($_SESSION['auth']['level'] ?? ''),
			(array)($_SESSION['auth']['roles'] ?? []))) {
		return PERMS_CHANGED_MESSAGE;
	}
	// Admin được miễn phần TEAM (tránh tự nhốt khi tắt team của mình)
	if (!is_admin() && !team_is_active($team)) {
		return TEAM_INACTIVE_MESSAGE;
	}
	return null;
}

function current_team_blocked(): bool {
	return access_block_reason() !== null;
}

/**
 * Bộ quyền trong session có còn khớp DB không — bắt CẢ HAI trường hợp:
 *   - user bị chuyển sang nhóm quyền khác (`authors.level` đổi -> slug đổi);
 *   - nhóm quyền của họ bị sửa nội dung (`roles_permissions.roles` đổi).
 */
function user_perms_changed(int $userId, string $sessionLevel, array $sessionRoles): bool {
	if ($userId <= 0) {
		return false;
	}
	if (!array_key_exists($userId, $GLOBALS['__access_cache']['perms'])) {
		try {
			$row = db()->execute_query(
				'SELECT rl.slug, rl.roles FROM authors a
				 LEFT JOIN roles_permissions rl ON rl.ID = a.level
				 WHERE a.ID = ? LIMIT 1', [$userId])->fetch_assoc();
			$GLOBALS['__access_cache']['perms'][$userId] = $row === null ? null : [
				'slug'  => (string)($row['slug'] ?? ''),
				'roles' => (array)json_decode((string)($row['roles'] ?? '[]'), true),
			];
		} catch (mysqli_sql_exception) {
			$GLOBALS['__access_cache']['perms'][$userId] = null;   // DB hỏng thì không tự đá ra
		}
	}
	$cur = $GLOBALS['__access_cache']['perms'][$userId];
	if ($cur === null) {
		return false;
	}
	// So sánh lỏng (==) trên mảng: khớp theo cặp khóa/giá trị, không quan tâm thứ tự
	return $cur['slug'] !== $sessionLevel || $cur['roles'] != $sessionRoles;
}

/** Team trong session có còn khớp team trong DB không. */
function user_team_changed(int $userId, int $sessionTeam): bool {
	if ($userId <= 0) {
		return false;
	}
	if (!array_key_exists($userId, $GLOBALS['__access_cache']['user_team'])) {
		try {
			$row = db()->execute_query('SELECT team_id FROM authors WHERE ID = ? LIMIT 1', [$userId])->fetch_row();
			$GLOBALS['__access_cache']['user_team'][$userId] = $row === null ? null : (int)$row[0];
		} catch (mysqli_sql_exception) {
			$GLOBALS['__access_cache']['user_team'][$userId] = null;   // DB hỏng thì không tự đá người dùng ra
		}
	}
	$dbTeam = $GLOBALS['__access_cache']['user_team'][$userId];
	return $dbTeam !== null && $dbTeam !== $sessionTeam;
}

/** Thông báo dùng chung khi bị khóa (app hiển thị tiếng Anh). */
const TEAM_INACTIVE_MESSAGE = 'Your team has been deactivated. Please contact an administrator.';
/** Tài khoản chưa duyệt / đã khóa. */
const USER_INACTIVE_MESSAGE = 'Your account is not active. Please contact an administrator.';
/** Tài khoản đã bị xóa (vd. cả team bị xóa). */
const USER_DELETED_MESSAGE = 'Your account no longer exists. Please contact an administrator.';
/** Bị chuyển sang team khác trong lúc đang đăng nhập. */
const TEAM_CHANGED_MESSAGE = 'Your team has changed. Please sign in again.';
/** Nhóm quyền bị đổi hoặc bị sửa nội dung. */
const PERMS_CHANGED_MESSAGE = 'Your permissions have changed. Please sign in again.';
/**
 * URL trang đăng nhập, tính theo vị trí script đang chạy.
 *
 * KHÔNG dùng đường dẫn tương đối './html/...': require_login() được gọi từ chính
 * thư mục đó (html/vertical-menu-template-no-customizer/index.php) nên trình duyệt
 * ghép thành .../html/vertical-menu-template-no-customizer/html/vertical-menu-.../
 * và ra 404. Trả về đường dẫn tính từ gốc site để đúng ở mọi nơi gọi.
 */
function login_url(): string {
	$script = $_SERVER['SCRIPT_NAME'] ?? '';
	$marker = '/html/vertical-menu-template-no-customizer/';
	$pos = strpos($script, $marker);
	// Script nằm trong thư mục template -> cắt lấy phần gốc app; ngược lại script ở gốc app
	$base = $pos !== false
		? substr($script, 0, $pos)
		: rtrim(str_replace('\\', '/', dirname($script)), '/');
	return $base . $marker . 'auth-login-basic.php';
}

function require_login(): void {
	if (!is_logged_in()) {
		// Thử auto-login bằng cookie trước khi chuyển hướng
		if (!attempt_cookie_login()) {
			header('Location: ' . login_url());
			exit;
		}
	}
	// Tài khoản bị khóa / team bị tắt / bị chuyển team trong lúc đang đăng nhập
	// -> đẩy ra ngay, không vào được trang nào
	$reason = access_block_reason();
	if ($reason !== null) {
		logout_user();
		flash_set('error', $reason);
		header('Location: ' . login_url());
		exit;
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
            rl.slug as level,
            a.status as user_status,
            t.status as team_status
        FROM authors a
        LEFT JOIN roles_permissions rl ON rl.ID = a.level
        LEFT JOIN team t ON t.ID = a.team_id
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

	// Tài khoản bị khóa/chưa duyệt -> không auto-login, thu hồi token
	if ((int)($user['user_status'] ?? 2) !== 2) {
		delete_remember_selector($selector);
		clear_remember_cookie();
		return false;
	}
	// Team ngừng hoạt động -> không auto-login, thu hồi luôn token nhớ đăng nhập
	if (($user['level'] ?? '') !== 'admin' && (int)($user['team_status'] ?? 1) !== 1) {
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