<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

if (($_GET['action'] ?? '') === 'logout') {
	logout_user();
	flash_set('info', 'Bạn đã đăng xuất.');
	header('Location: ./html/vertical-menu-template-no-customizer/auth-login-basic.php');
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
	$token    = $_POST['_csrf'] ?? '';
	$userKey  = trim($_POST['username'] ?? ''); // có thể là email hoặc username
	$password = $_POST['password'] ?? '';
	$remember = isset($_POST['remember']) && $_POST['remember'] === '1';

	if (!csrf_verify($token)) {
		flash_set('error', 'Phiên không hợp lệ.');
		header('Location: ./html/vertical-menu-template-no-customizer/auth-login-basic.php');
		exit;
	}
	if ($userKey === '' || $password === '') {
		flash_set('error', 'Nhập đầy đủ thông tin.');
		header('Location: ./html/vertical-menu-template-no-customizer/auth-login-basic.php');
		exit;
	}

	$author = find_author_by_login($userKey);
	if (!$author || !password_verify($password, $author['hash'])) {
		flash_set('error', 'Sai tên đăng nhập hoặc mật khẩu.');
		header('Location: ./html/vertical-menu-template-no-customizer/auth-login-basic.php');
		exit;
	}

	login_user($author);

	if ($remember) {
		set_remember_cookie((int)$author['id']);
	} else {
		// Nếu trước đó có cookie nhớ đăng nhập thì xóa để tránh còn hiệu lực
		clear_remember_cookie();
	}

	header('Location: ./html/vertical-menu-template-no-customizer/index.php');
	exit;
}

http_response_code(405);
echo 'Method Not Allowed';