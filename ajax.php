<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/functions/functions-telnyx.php';
require __DIR__ . '/functions/ajax-select2.php';
require __DIR__ . '/class/class.extension.php';
require __DIR__ . '/class/class.orders.php';
require __DIR__ . '/class/class.order.php';
require __DIR__ . '/model/functions-gemini.php';
require __DIR__ . '/model/functions-openai.php';
require __DIR__ . '/tables/functions-teams.php';
header('Content-Type: application/json; charset=utf-8');
// Nếu chưa login hoặc cookie nhớ đăng nhập không hợp lệ → chặn
if (!is_logged_in() && !attempt_cookie_login()) {
    if (isset($_GET['action']) && isset($_REQUEST['key'])) {
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
            case 'extension-update-account-finance':
                echo json_encode(Extensions::update_account_finance());
                break;
            case 'extension-update-account-seller':
                echo json_encode(Extensions::update_account_seller());
                break;
            case 'extension-update-account-cookies':
                echo json_encode(Extensions::update_account_cookies());
                break;
            case 'extension-add-account-orders':
                echo json_encode(Extensions::add_account_orders());
                break;
            case 'extension-get-account-2fa':
                echo json_encode(Extensions::get_account_2fa());
                break;
            case 'extension-get-account-orders':
                echo json_encode(Extensions::get_account_orders());
                break;
            case 'extension-get-account-cookies':
                echo json_encode(Extensions::get_account_cookies());
                break;
            case 'extension-get-account-login':
                echo json_encode(Extensions::get_account_login());
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
            echo json_encode(Orders::get_orders());
            break;
        case 'add-order-tracking':
            echo json_encode(Order::add_tracking());
            break;
        case 'get-stores-table-filter':
            echo json_encode(getStoresTableFilters());
            break;
        case 'get-stores-table':
            echo json_encode(getAccountsTable());
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
        case 'get-common-filter':
            echo json_encode(getCommonFilterData());
            break;
        case 'get-account-upload-files':
            echo json_encode(getAccountUploadFiles());
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
        case 'add-account':
            echo json_encode(addAccount());
            break;
        case 'add-roles-permissions':
            echo json_encode(addRolesPermissions());
            break;
		case 'delete-xlsx':
			echo json_encode(deleteXlsx());
			break;
        case 'delete-account-upload-file':
            echo json_encode(deleteAccountUploadFile());
            break;
		case 'duplicate-xlsx':
			echo json_encode(duplicateXlsx());
			break;
        case 'download-xlsx':
            downloadXlsx();
        case 'save-export-query':
            echo json_encode(saveExportQuery());
            break;
	}
	exit;
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])){
    // TẮT hiển thị lỗi ra HTML (sai sót debug)
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/php_errors.log');
    error_reporting(E_ALL);
    switch ($_GET['action']) {
        case 'get-account-file':
            getAccountUploadFileData();
            break;
    }
}
