<?php
define('AJAX_APP', true);
// Cho phép chạy không giới hạn thời gian cho batch lớn
set_time_limit(0);

require_once __DIR__ . '/functions.php';

// XỬ LÝ AJAX POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && isset($_GET['key'])) {
    // TẮT hiển thị lỗi ra HTML (sai sót debug)
	ini_set('display_errors', '0');
	ini_set('log_errors', '1');
	ini_set('error_log', __DIR__ . '/php_errors.log');
	error_reporting(E_ALL);

	// 1. Cấu hình DB
	$dbHost = 'localhost';
	$dbUser = 'data';
	$dbPass = '519483@Pff';
	$dbName = 'data';

	// 2. Kết nối
	$GLOBALS['mysqli'] = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
	// Khởi tạo kết nối và lưu vào $GLOBALS
	if ($GLOBALS['mysqli']->connect_error) {
		http_response_code(500);
		echo json_encode([
			'status'  => 'error',
			'message' => 'DB connect error: ' . $GLOBALS['mysqli']->connect_error
		]);
		exit;
	}

	// 3. Lấy ID loại theo chính ID (integer)
	$authorId  = getIDBy('authors',  'key',   $_GET['key'], 's');
	if(!$authorId){
		http_response_code(501);
		echo json_encode([
			'status'  => 'error',
			'message' => 'key not found'
		]);
		exit;
	}

	$GLOBALS['authorid'] = $authorId;

	header('Content-Type: application/json; charset=utf-8');
	switch ($_GET['action']) {
		case 'check-keywords':
			require_once __DIR__ . '/action-check-keywords.php';
			break;
		case 'check-listings':
			require_once __DIR__ . '/action-check-listings.php';
			break;
		case 'check-listing':
			require_once __DIR__ . '/action-check-listing.php';
			break;
		case 'add-listing':
			require_once __DIR__ . '/action-add-listing.php';
			break;
	}
	$GLOBALS['mysqli']->close();
    exit;
}
?>