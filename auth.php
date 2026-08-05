<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

// Đường dẫn login dùng chung để dễ bảo trì
$loginPage = './html/vertical-menu-template-no-customizer/auth-login-basic.php';
$homePage  = './html/vertical-menu-template-no-customizer/index.php';

// 1. Xử lý Đăng xuất
if (($_GET['action'] ?? '') === 'logout') {
	logout_user();
	flash_set('info', 'Bạn đã đăng xuất.');
	header("Location: $loginPage");
	exit;
}
// 2. Xử lý Đăng nhập
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
	$token    = $_POST['_csrf'] ?? '';
	$userKey  = trim($_POST['username'] ?? ''); // có thể là email hoặc username
	$password = $_POST['password'] ?? '';
	$remember = isset($_POST['remember']) && $_POST['remember'] === '1';

    // Kiểm tra CSRF
	if (!csrf_verify($token)) {
		flash_set('error', 'Phiên không hợp lệ.');
		header("Location: $loginPage");
		exit;
	}
    // Kiểm tra đầu vào rỗng
	if ($userKey === '' || $password === '') {
		flash_set('error', 'Nhập đầy đủ thông tin.');
		header("Location: $loginPage");
		exit;
	}

    // Lấy dữ liệu user
	$author = get_user_data($userKey);

    // Kiểm tra sự tồn tại và mật khẩu
	if (!$author || !password_verify($password, $author['hash'])) {
		flash_set('error', 'Sai tên đăng nhập hoặc mật khẩu.');
		header("Location: $loginPage");
		exit;
	}

    // Tài khoản chưa duyệt (Pending) hoặc đã khóa (Inactive) -> chặn đăng nhập
	if ((int)($author['user_status'] ?? 2) !== 2) {
		flash_set('error', USER_INACTIVE_MESSAGE);
		header("Location: $loginPage");
		exit;
	}

    // Team ngừng hoạt động -> chặn đăng nhập (admin không bị chặn để còn bật lại được)
	if (($author['level'] ?? '') !== 'admin' && (int)($author['team_status'] ?? 1) !== 1) {
		flash_set('error', TEAM_INACTIVE_MESSAGE);
		header("Location: $loginPage");
		exit;
	}

    // Thực hiện đăng nhập (Ghi Session)
	login_user($author);

    // Xử lý Remember Me
	if ($remember) {
		set_remember_cookie((int)$author['id']);
	} else {
		// Nếu trước đó có cookie nhớ đăng nhập thì xóa để tránh còn hiệu lực
		clear_remember_cookie();
	}

	header("Location: $homePage");
	exit;
}
// 3. Chặn các phương thức không hợp lệ
http_response_code(405);
echo 'Method Not Allowed';