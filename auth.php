<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

if (($_GET['action'] ?? '') === 'logout') {
	logout_user();
	flash_set('info', 'Bạn đã đăng xuất.');
	header('Location: /html/horizontal-menu-template-no-customizer/auth-login-basic.php');
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
	$token    = $_POST['_csrf'] ?? '';
	$username = trim($_POST['username'] ?? '');
	$password = $_POST['password'] ?? '';

	if (!csrf_verify($token)) {
		flash_set('error', 'Phiên không hợp lệ.');
		header('Location: /html/horizontal-menu-template-no-customizer/auth-login-basic.php');
		exit;
	}
	if ($username === '' || $password === '') {
		flash_set('error', 'Nhập đầy đủ thông tin.');
		header('Location: /html/horizontal-menu-template-no-customizer/auth-login-basic.php');
		exit;
	}
	if (!verify_credentials($username, $password)) {
		flash_set('error', 'Sai tên đăng nhập hoặc mật khẩu.');
		header('Location: /html/horizontal-menu-template-no-customizer/auth-login-basic.php');
		exit;
	}

	login_user($username);
	header('Location: /dashboards.php');
	exit;
}

http_response_code(405);
echo 'Method Not Allowed';