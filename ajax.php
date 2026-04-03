<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/functions/functions-telnyx.php';
require __DIR__ . '/model/functions-gemini.php';
require __DIR__ . '/model/functions-openai.php';
require __DIR__ . '/tables/functions-teams.php';
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
            case 'debug':
                echo json_encode(getDebug());
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
		case 'get-products-table':
			echo json_encode(getProductsTable());
			break;
		case 'get-products-table-filter':
			echo json_encode(getProductsTableFilters());
			break;
        case 'get-product-copyright-warning':
            echo json_encode(getProductCopyrightWarning());
            break;
        case 'get-keywords-table':
            echo json_encode(getKeywordsTable());
            break;
        case 'get-orders-table':
            echo json_encode(getOrdersTable());
            break;
        case 'get-stores-table-filter':
            echo json_encode(getStoresTableFilters());
            break;
        case 'get-stores-table':
            echo json_encode(getStoresTable());
            break;
        case 'get-download-table':
            echo json_encode(getDownloadTable());
            break;
        case 'get-download-products-process':
            echo json_encode(getDownloadProductsProcess());
            break;
        case 'get-files-table':
            echo json_encode(getFilesTable());
            break;
        case 'get-phones-table':
            echo json_encode(getPhonesTable());
            break;
        case 'get-teams-table':
            echo json_encode(getTeamsTable());
            break;
        case 'get-authors-table':
            echo json_encode(getAuthorsTable());
            break;
        case 'get-authors-table-filter':
            echo json_encode(getAuthorsTableFilters());
            break;
        case 'get-authors-by-team':
            echo json_encode(getAuthorsByTeam());
            break;
        case 'get-roles-permissions-table':
            echo json_encode(getRolesPermissionsTable());
            break;
        case 'get-roles-permissions':
            echo json_encode(getRolesPermissions());
            break;
		case 'filter-stores':
			echo json_encode(getStoresTableFilter());
			break;
		case 'filter-accounts':
			echo json_encode(getAccountsTableFilter());
			break;
		case 'filter-export-file':
			echo json_encode(getFilesTableFilter());
			break;
        case 'action-download-table-model':
            echo json_encode(actionDownloadTableModel());
            break;
        case 'add-keywords':
			echo json_encode(addKeywords());
			break;
        case 'add-xlsx':
            echo json_encode(addXlsx());
            break;
        case 'add-roles-permissions':
            echo json_encode(addRolesPermissions());
            break;
		case 'delete-xlsx':
			echo json_encode(deleteXlsx());
			break;
		case 'duplicate-xlsx':
			echo json_encode(duplicateXlsx());
			break;
        case 'download-xlsx':
            downloadXlsx();
            break;
        case 'save-export-query':
            echo json_encode(saveExportQuery());
            break;
	}
	exit;
}
