<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/functions-telnyx.php';
header('Content-Type: application/json; charset=utf-8');
// Nếu chưa login hoặc cookie nhớ đăng nhập không hợp lệ → chặn
if (!is_logged_in() && !attempt_cookie_login()) {
    if (isset($_GET['action']) && isset($_GET['key'])) {
        // TẮT hiển thị lỗi ra HTML (sai sót debug)
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        ini_set('error_log', __DIR__ . '/php_errors.log');
        error_reporting(E_ALL);
        switch ($_GET['action']) {
            case 'hook-telnyx':
                echo json_encode(hookTelnyx());
                break;
            case 'get-missing-orders':
                echo json_encode(getMissingOrders());
                break;
            case 'add-orders':
                echo json_encode(addOrders());
                break;
        }
    } else {
        http_response_code(401); // Unauthorized
        echo json_encode(['error' => 'Bạn chưa đăng nhập']);
    }
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
			echo json_encode(getProductsTable());
			break;
		case 'get-product-table-filter':
			echo json_encode(getProductTableFilters());
			break;
        case 'get-xlsx':
            echo json_encode(getFilesTable());
            break;
        case 'get-download':
            echo json_encode(getDownloadTable());
            break;
        case 'get-process-products':
            echo json_encode(getProcessProducts());
            break;
        case 'get-phones':
            echo json_encode(getPhonesTable());
            break;
        case 'get-orders':
            echo json_encode(getOrdersTable());
            break;
        case 'get-keywords-table':
            echo json_encode(getKeywordsTable());
            break;
        case 'get-roles-permissions-table':
            // view
            echo json_encode(getRolesPermissionsTable());
            break;
        case 'get-roles-permissions':
            // edit
            echo json_encode(getRolesPermissions());
            break;
		case 'filter-stores':
			echo json_encode(getStoresTableFilter());
			break;
		case 'filter-accounts':
			echo json_encode(getAccountsTableFilter());
			break;
		case 'filter-export-file':
			echo json_encode(getExportTableFilter());
			break;
		case 'add-xlsx':
			echo json_encode(addXlsx());
			break;
        case 'add-keywords':
			echo json_encode(addKeywords());
			break;
        case 'add-roles-permissions':
            // add, edit
            echo json_encode(addRolesPermissions());
            break;
		case 'delete-xlsx':
			echo json_encode(deleteXlsx());
			break;
		case 'duplicate-xlsx':
			echo json_encode(duplicateXlsx());
			break;
        case 'download-xlsx':
            echo json_encode(downloadXlsx());
            break;
        case 'save-export-query':
            echo json_encode(saveExportQuery());
            break;
        case 'ai-process-products':
            echo json_encode(AIProcessProducts());
            break;
	}
	exit;
}
