<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/functions.php';
header('Content-Type: application/json; charset=utf-8');
// Nếu chưa login hoặc cookie nhớ đăng nhập không hợp lệ → chặn
if (!is_logged_in() && !attempt_cookie_login()) {
	http_response_code(401); // Unauthorized
	echo json_encode(['error' => 'Bạn chưa đăng nhập']);
	exit;
}

// XỬ LÝ AJAX POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
	// TẮT hiển thị lỗi ra HTML (sai sót debug)
	ini_set('display_errors', '0');
	ini_set('log_errors', '1');
	ini_set('error_log', __DIR__ . '/php_errors.log');
	error_reporting(E_ALL);

	switch ($_GET['action']) {
		case 'get-products':
			require_once __DIR__ . '/html/vertical-menu-template-no-customizer/app-ecommerce-product-list-ajax.php';
			break;
		case 'get-product-table-filter':
			echo json_encode(getProductTableFilter());
			break;
		case 'filter-stores':
			echo json_encode(getStoresTableFilter());
			break;
		case 'filter-accounts':
			echo json_encode(getAccountsTableFilter());
			break;
	}
	exit;
}
?>