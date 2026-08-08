<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/functions/functions-telnyx.php';
require __DIR__ . '/functions/ajax-select2.php';
require_once __DIR__ . '/functions/functions-upload-files.php';
require __DIR__ . '/class/class.extension.php';
require_once __DIR__ . '/class/class.orders.php';
require __DIR__ . '/class/class.order.php';
require_once __DIR__ . '/class/class.products.php';
require_once __DIR__ . '/class/class.product.php';
require_once __DIR__ . '/class/class.categories.php';
require_once __DIR__ . '/class/class.category.php';
require_once __DIR__ . '/class/class.sites.php';
require_once __DIR__ . '/class/class.site.php';
require_once __DIR__ . '/class/class.stores.php';
require_once __DIR__ . '/class/class.store.php';
require_once __DIR__ . '/class/class.teams.php';
require_once __DIR__ . '/class/class.team.php';
require_once __DIR__ . '/class/class.users.php';
require_once __DIR__ . '/class/class.user.php';
require_once __DIR__ . '/class/class.account.php';
require_once __DIR__ . '/class/class.roles.php';
require_once __DIR__ . '/class/class.role.php';
require __DIR__ . '/model/functions-gemini.php';
require __DIR__ . '/model/functions-openai.php';
header('Content-Type: application/json; charset=utf-8');

// Xử lý các action extension-* độc lập với trạng thái đăng nhập session,
// vì đã xác thực bằng key/email riêng.
if (isset($_GET['action']) && isset($_POST['key']) && str_starts_with($_GET['action'], 'extension-')) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/php_errors.log');
    error_reporting(E_ALL);
    switch ($_GET['action']) {
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
        case 'extension-add-products':
            echo json_encode(Extensions::add_products());
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
        case 'extension-check-products-exist':
            echo json_encode(Extensions::check_products_exist());
            break;
        case 'extension-get-products':
            echo json_encode(Extensions::get_products());
            break;
    }
    exit;
}

// Nếu chưa login hoặc cookie nhớ đăng nhập không hợp lệ → chặn
if (!is_logged_in() && !attempt_cookie_login()) {
    // Key có thể nằm ở POST hoặc QUERY STRING. hookTelnyx() đọc $_GET['key'] (Telnyx gọi
    // `?action=hook-telnyx&key=<team key>` với thân JSON, nên $_POST luôn rỗng) trong khi
    // cổng này chỉ nhận $_POST['key'] -> webhook bị 401 và KHÔNG BAO GIỜ chạy. Tin nhắn mới
    // nhất trong DB là 05/06/2026, đúng khớp với việc đường vào đã chết (phát hiện
    // 07/08/2026). Nới cổng cho cả hai chỗ; từng handler vẫn tự xác thực key như cũ.
    if (isset($_GET['action']) && (isset($_POST['key']) || isset($_GET['key']))) {
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
        echo json_encode(['status' => 'error', 'message' => 'You are not signed in.']);
    }
	exit;
}

// Tài khoản khóa / team ngừng hoạt động / bị chuyển team -> chặn TOÀN BỘ ajax của phiên
// này. Đặt ngay sau cửa đăng nhập để không handler nào phải tự nhớ kiểm.
$accessBlock = access_block_reason();
if ($accessBlock !== null) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => $accessBlock]);
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
			echo json_encode(Products::get_products());
			break;
		case 'get-products-table-filter':
			echo json_encode(Products::get_products_filters());
			break;
		case 'update-products-type':
			echo json_encode(Products::update_products_type());
			break;
		case 'update-products-status':
			echo json_encode(Products::update_products_status());
			break;
		case 'get-product-images':
			echo json_encode(Product::get_product_images());
			break;
		case 'delete-products':
			echo json_encode(Products::delete_products());
			break;
		case 'save-product':
			echo json_encode(Product::save_product());
			break;
		case 'get-categories-table':
			echo json_encode(Categories::get_categories());
			break;
		case 'get-categories-table-filter':
			echo json_encode(Categories::get_categories_filters());
			break;
		case 'delete-categories':
			echo json_encode(Categories::delete_categories());
			break;
		case 'save-category':
			echo json_encode(Category::save_category());
			break;
		case 'get-sites-table':
			echo json_encode(Sites::get_sites());
			break;
		case 'get-sites-table-filter':
			echo json_encode(Sites::get_sites_filters());
			break;
		case 'delete-sites':
			echo json_encode(Sites::delete_sites());
			break;
		case 'save-site':
			echo json_encode(Site::save_site());
			break;
		case 'upload-site-logo':
			echo json_encode(Site::upload_logo());
			break;
		case 'get-store-table':
			echo json_encode(Stores::get_stores());
			break;
		case 'get-store-table-filter':
			echo json_encode(Stores::get_stores_filters());
			break;
		case 'delete-stores':
			echo json_encode(Stores::delete_stores());
			break;
		case 'save-store':
			echo json_encode(Store::save_store());
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
        case 'get-orders-table-filter':
            echo json_encode(Orders::get_orders_filters());
            break;
        case 'update-orders-status':
            echo json_encode(Orders::update_orders_status());
            break;
        case 'delete-orders':
            echo json_encode(Orders::delete_orders());
            break;
        case 'add-order-item-tracking':
            echo json_encode(Order::add_item_tracking());
            break;
        case 'update-order-item-data':
            echo json_encode(Order::add_item_data());
            break;
        case 'get-stores-table-filter':
            echo json_encode(get_stores_filters());
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
        case 'get-sms-table':
            echo json_encode(getSmsTable());
            break;
        case 'get-sms-filters':
            echo json_encode(getSmsFilters());
            break;
        case 'update-sms-status':
            echo json_encode(updateSmsStatusBulk());
            break;
        case 'delete-sms':
            echo json_encode(deleteSmsBulk());
            break;
        case 'mark-sms-read':
            echo json_encode(markSmsRead());
            break;
        case 'get-phone-messages':
            echo json_encode(getPhoneMessages());
            break;
        case 'save-phone':
            echo json_encode(savePhone());
            break;
        case 'delete-phones':
            echo json_encode(deletePhonesBulk());
            break;
        case 'update-phones-status':
            echo json_encode(updatePhonesStatusBulk());
            break;
        case 'get-phones-table':
            echo json_encode(getPhonesTable());
            break;
        case 'get-teams-table':
            echo json_encode(Teams::get_teams());
            break;
        case 'save-team':
            echo json_encode(Team::save_team());
            break;
        case 'get-team-purge-preview':
            echo json_encode(Teams::get_purge_preview());
            break;
        case 'purge-team':
            echo json_encode(Teams::purge_team());
            break;
        case 'regenerate-team-key':
            echo json_encode(Teams::regenerate_key());
            break;
        case 'merge-team':
            echo json_encode(Teams::merge_team());
            break;
        case 'get-authors-table':
            echo json_encode(Users::get_users());
            break;
        case 'get-authors-table-filter':
            echo json_encode(Users::get_users_filters());
            break;
        case 'save-user':
            echo json_encode(User::save_user());
            break;
        case 'upload-user-avatar':
            echo json_encode(User::upload_avatar());
            break;
        // --- Trang Account: KHÔNG endpoint nào ở đây nhận `id` của người bị ghi; đích luôn
        // là người đang đăng nhập. Riêng get-account có `id` vì nó CHỈ ĐỌC hồ sơ người khác,
        // và Account::viewable() tự kiểm đủ 3 trục.
        case 'get-account':
            echo json_encode(Account::get_profile());
            break;
        case 'save-account':
            echo json_encode(Account::save_profile());
            break;
        case 'change-account-password':
            echo json_encode(Account::change_password());
            break;
        case 'get-account-devices':
            echo json_encode(Account::get_devices());
            break;
        case 'revoke-account-device':
            echo json_encode(Account::revoke_device());
            break;
        case 'revoke-account-devices-all':
            echo json_encode(Account::revoke_all_devices());
            break;
        case 'regenerate-account-key':
            echo json_encode(Account::regenerate_key());
            break;
        case 'get-user-move-preview':
            echo json_encode(Users::get_move_preview());
            break;
        case 'get-user-delete-preview':
            echo json_encode(Users::get_delete_preview());
            break;
        case 'delete-users':
            echo json_encode(Users::delete_users());
            break;
        case 'get-authors-by-team':
            echo json_encode(get_authors_by_team());
            break;
        case 'get-roles-permissions-table':
            echo json_encode(Roles::get_roles());
            break;
        case 'get-roles-table-filter':
            echo json_encode(Roles::get_roles_filters());
            break;
        case 'get-roles-permissions':
            echo json_encode(Role::get_role());
            break;
        case 'get-role-delete-preview':
            echo json_encode(Roles::get_delete_preview());
            break;
        case 'delete-role':
            echo json_encode(Roles::delete_role());
            break;
        case 'get-common-filter':
            echo json_encode(getCommonFilterData());
            break;
        case 'get-account-upload-files':
            echo json_encode(getAccountUploadFiles());
            break;
		case 'filter-stores':
			echo json_encode(get_stores_select_options());
			break;
		case 'filter-sites':
			echo json_encode(get_sites_select_options());
			break;
		case 'filter-authors':
			echo json_encode(get_authors_select_options());
			break;
		case 'filter-accounts':
			echo json_encode(get_accounts_select_options());
			break;
		case 'filter-export-file':
			echo json_encode(get_export_files_select_options());
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
            echo json_encode(Role::save_role());
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
