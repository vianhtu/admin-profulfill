<?php
function renderMenu($currentMenu) {
	$menuItems = [
		'Dashboards' => [
			'icon' => 'tabler-smart-home',
			'link' => '' // để trống => mặc định dùng $currentMenu
		],
		'eCommerce' => [
			'icon' => 'tabler-shopping-cart',
			'sub' => [
				'products' => 'Products',
				'stores' => 'Stores'
			]
		],
		'Export' => [
			'icon' => 'tabler-file-type-xls',
			'sub' => [
				'exports_xlsx' => 'XLSX',
			]
		],
		'Users' => ['icon' => 'tabler-users', 'link' => 'users']
	];

	foreach ($menuItems as $mainLabel => $mainData) {
		$icon = $mainData['icon'];

		if (!empty($mainData['sub'])) {
			// Có submenu
			$subMenuHtml = '';
			$isOpen = false;
			foreach ($mainData['sub'] as $key => $value) {
				$label = is_array($value) ? $value['label'] : $value;
				$target = is_array($value) && isset($value['target']) ? 'target="_blank"' : '';
				$activeClass = ($currentMenu === $key) ? 'active' : '';
				if ($activeClass) $isOpen = true;

				$subMenuHtml .= "<li class='menu-item {$activeClass}'>
                    <a href='index.php?menu={$key}' class='menu-link' {$target}>
                        <div data-i18n='{$label}'>{$label}</div>
                    </a>
                </li>";
			}
			$openClass = $isOpen ? 'active open' : '';
			echo "<li class='menu-item {$openClass}'>
                <a href='javascript:void(0);' class='menu-link menu-toggle'>
                    <i class='menu-icon icon-base ti {$icon}'></i>
                    <div data-i18n='{$mainLabel}'>{$mainLabel}</div>
                </a>
                <ul class='menu-sub'>{$subMenuHtml}</ul>
            </li>";
		} else {
			// Không có submenu
			$link = trim($mainData['link']) === '' ? '' : $mainData['link'];
			$activeClass = ($currentMenu === $link) ? 'active' : '';
			$href = $link === '' ? 'index.php' : "index.php?menu={$link}";
			echo "<li class='menu-item {$activeClass}'>
                <a href='{$href}' class='menu-link'>
                    <i class='menu-icon icon-base ti {$icon}'></i>
                    <div data-i18n='{$mainLabel}'>{$mainLabel}</div>
                </a>
            </li>";
		}
	}
}

function getTypes(): array {
	$conn = db();
	$stmt = $conn->query("SELECT ID, name FROM type");
	$types = [];
	while ($row = $stmt->fetch_assoc()) {
		$types[$row['ID']] = [
			'title' => $row['name']
		];
	}
	$stmt->close();
	return $types;
}

function getAuthors(): array {
	$conn = db();
	$stmt = $conn->query("SELECT ID, username FROM authors");
	$types = [];
	while ($row = $stmt->fetch_assoc()) {
		$types[$row['ID']] = [
			'title' => $row['username']
		];
	}
	$stmt->close();
	return $types;
}

function getSites(): array {
	$conn = db();
	$stmt = $conn->query("SELECT ID, name FROM site");
	$types = [];
	while ($row = $stmt->fetch_assoc()) {
		$types[$row['ID']] = [
			'title' => $row['name']
		];
	}
	$stmt->close();
	return $types;
}

function getAuthorsProductInfo(): ?array {
	$sql = "SELECT
    -- Tổng số bài viết
    COUNT(*) AS total_items,
    -- Tổng số bài viết đang chờ duyệt
    COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending_items,
    -- Tổng số bài viết của tác giả hiện tại
    COUNT(CASE WHEN author_id = ? THEN 1 END) AS author_items,
    -- Tổng số bài viết trong tháng hiện tại
    COUNT(CASE WHEN MONTH(date) = MONTH(CURRENT_DATE()) AND YEAR(date) = YEAR(CURRENT_DATE()) THEN 1 END) AS total_this_month,
    -- Tổng số bài viết đang chờ duyệt trong tháng hiện tại
    COUNT(CASE WHEN status = 'pending' AND MONTH(date) = MONTH(CURRENT_DATE()) AND YEAR(date) = YEAR(CURRENT_DATE()) THEN 1 END) AS pending_this_month,
    -- Tổng số bài viết của tác giả hiện tại trong tháng hiện tại
    COUNT(CASE WHEN author_id = ? AND MONTH(date) = MONTH(CURRENT_DATE()) AND YEAR(date) = YEAR(CURRENT_DATE()) THEN 1 END) AS author_this_month
    FROM posts";
	$stmt = db()->prepare($sql);
	$stmt->bind_param('ii', $_SESSION['auth']['user_id'],$_SESSION['auth']['user_id']);
	$stmt->execute();
	$result = $stmt->get_result();
	$data = $result->fetch_assoc();
	$stmt->close();
	return $data;
}

function getProductsTable() {
	$conn = db();
	// Lấy thông số từ DataTables
	$draw             = intval( $_POST['draw'] ?? 1 );
	$start            = intval( $_POST['start'] ?? 0 );
	$length           = intval( $_POST['length'] ?? 10 );
	$orderColumnIndex = intval( $_POST['order'][0]['column'] ?? 0 );
	$orderColumn      = $_POST['columns'][ $orderColumnIndex ]['data'] ?? 'ID';
	$orderDir         = strtolower( $_POST['order'][0]['dir'] ?? 'asc' ) === 'desc' ? 'DESC' : 'ASC';
	$searchValue      = trim( $_POST['search']['value'] ?? '' );

	// Danh sách cột cho phép sort
	$allowedCols = [ 'ID', 'title', 'status', 'sku', 'date', 'badge' ];
	if ( ! in_array( $orderColumn, $allowedCols ) ) {
		$orderColumn = 'ID';
	}

	// Tổng số bản ghi
	$totalRecords = $conn->query( "SELECT COUNT(*) AS cnt FROM posts" )->fetch_assoc()['cnt'];
	$whereClauses = [];
	// Lọc theo search
	if ( $searchValue !== '' ) {
		$searchEsc      = $conn->real_escape_string( $searchValue );
		$whereClauses[] = "(title LIKE '%$searchEsc%' OR sku LIKE '%$searchEsc%' OR status LIKE '%$searchEsc%' OR badge LIKE '%$searchEsc%')";
	}
	// lọc theo status.
	$filterStatus = $_POST['columns'][8]['search']['value'] ?? '';
	$filterStatus = trim( $filterStatus, '^$' ); // bỏ ký tự regex
	if ( $filterStatus !== '' ) {
		$escStock       = $conn->real_escape_string( $filterStatus );
		$whereClauses[] = "status = '$escStock'";
	}
	// lọc theo type.
	$filterType = $_POST['columns'][3]['search']['value'] ?? '';
	$filterType = trim( $filterType, '^$' ); // bỏ ký tự regex
	if ( $filterType !== '' ) {
		$escStock       = $conn->real_escape_string( $filterType );
		$whereClauses[] = "type_id = '$escStock'";
	}
	// lọc theo author.
	$filterAuthor = $_POST['columns'][4]['search']['value'] ?? '';
	$filterAuthor = trim( $filterAuthor, '^$' ); // bỏ ký tự regex
	if ( $filterAuthor !== '' ) {
		$escStock       = $conn->real_escape_string( $filterAuthor );
		$whereClauses[] = "author_id = '$escStock'";
	}
	// lọc theo sites.
	$filterSites = $_POST['sites'] ?? [];
	if ( ! empty( $filterSites ) && is_array( $filterSites ) ) {
		// Ép tất cả sang số nguyên để tránh injection
		$ids    = array_map( 'intval', $filterSites );
		$idsStr = implode( ',', $ids );
		if ( $idsStr !== '' ) {
			$whereClauses[] = "site_id IN ($idsStr)";
		}
	}
	// Lọc theo khoảng ngày
	$minDate = $_POST['minDate'] ?? '';
	$maxDate = $_POST['maxDate'] ?? '';

	if ( $minDate !== '' && $maxDate !== '' ) {
		$escMin         = $conn->real_escape_string( $minDate );
		$escMax         = $conn->real_escape_string( $maxDate );
		$whereClauses[] = "DATE(`date`) BETWEEN '$escMin' AND '$escMax'";
	} elseif ( $minDate !== '' ) {
		$escMin         = $conn->real_escape_string( $minDate );
		$whereClauses[] = "DATE(`date`) >= '$escMin'";
	} elseif ( $maxDate !== '' ) {
		$escMax         = $conn->real_escape_string( $maxDate );
		$whereClauses[] = "DATE(`date`) <= '$escMax'";
	}
	// lọc theo stores.
	$filterStores = $_POST['stores'] ?? [];
	if ( ! empty( $filterStores ) && is_array( $filterStores ) ) {
		// Ép tất cả sang số nguyên để tránh injection
		$ids    = array_map( 'intval', $filterStores );
		$idsStr = implode( ',', $ids );
		if ( $idsStr !== '' ) {
			$whereClauses[] = "store_id IN ($idsStr)";
		}
	}
	// lọc theo accounts.
	$joinAccounts   = '';
	$filterAccounts = $_POST['accounts'] ?? [];
	if ( ! empty( $filterAccounts ) && is_array( $filterAccounts ) ) {
		// Ép tất cả sang số nguyên để tránh SQL injection
		$ids    = array_map( 'intval', $filterAccounts );
		$idsStr = implode( ',', $ids );
		if ( $idsStr !== '' ) {
			// Thêm JOIN để lọc theo account_id
			$joinAccounts   = "INNER JOIN accounts_relationships ar ON ar.post_id = posts.ID";
			$whereClauses[] = "ar.account_id IN ($idsStr)";
		}
	}

	$where         = $whereClauses ? ' WHERE ' . implode( ' AND ', $whereClauses ) : '';
	$totalFiltered = $conn->query( "SELECT COUNT(DISTINCT posts.ID) AS cnt FROM posts $joinAccounts $where" )->fetch_assoc()['cnt'];

	// Lấy dữ liệu
	$sql = "SELECT DISTINCT posts.ID, posts.title, posts.status, posts.sku, posts.images, posts.badge, posts.date, posts.type_id, posts.author_id
        FROM posts
        $joinAccounts
        $where
        ORDER BY $orderColumn $orderDir
        LIMIT $start, $length";
	$rs  = $conn->query( $sql );

	// Chuẩn bị dữ liệu trả về
	$data = [];
	while ( $row = $rs->fetch_assoc() ) {
		$imgs = json_decode( $row['images'] );
		// Thay thế phần il_###xN bằng il_50xN
		$updatedUrl = '';
		if ( $imgs && isset( $imgs->main ) ) {
			$updatedUrl = preg_replace( '/il_\d+xN/', 'il_100xN', $imgs->main );
		}
		//$firstImg = $imgs['main'];
		$data[] = [
			"id"            => $row['ID'],
			"product_name"  => htmlspecialchars( $row['title'] ),
			"category"      => $row['type_id'],
			"stock"         => $row['author_id'],
			"sku"           => htmlspecialchars( $row['sku'] ),
			"price"         => "$999",
			"qty"           => 665,
			"status"        => 3,
			"image"         => $updatedUrl,
			"product_brand" => "Etsy"
		];
	}

	// Trả JSON
	return [
		"draw"            => $draw,
		"recordsTotal"    => $totalRecords,
		"recordsFiltered" => $totalFiltered,
		"data"            => $data
	];
}

function getProductTableFilters(): array {
	$options = [];
	$options['types'] = getTypes();
	$options['authors'] = getAuthors();
	$options['sites'] = getSites();
	return $options;
}

function getStoresTableFilter(): array {
	$conn = db();
	// Lấy giá trị tìm kiếm từ POST
	$q    = isset($_POST['q']) ? trim($_POST['q']) : '';
	$page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
	$perPage = 20;
	$offset  = ($page - 1) * $perPage;

// Chuẩn bị câu truy vấn (Prepared Statement để chống SQL injection)
	$sql = "SELECT t.id, CONCAT(s.name, ' (', t.name, ')') AS name
        FROM store AS t
        JOIN site s ON t.site_id = s.id
        WHERE (? = '' OR t.name LIKE ?)
        ORDER BY t.name ASC
        LIMIT ?, ?";
	$stmt = $conn->prepare($sql);

	$like = "%{$q}%";
	$stmt->bind_param("ssii", $q, $like, $offset, $perPage);
	$stmt->execute();

	$result = $stmt->get_result();
	$items = [];
	while ($row = $result->fetch_assoc()) {
		$items[] = $row;
	}
	$stmt->close();

	// Kiểm tra còn dữ liệu trang tiếp theo hay không
	$more = (count($items) === $perPage);
	return [
		'items' => $items,
		'more'  => $more
	];
}

function getAccountsTableFilter(): array {
	$conn = db();
	// Lấy giá trị tìm kiếm từ POST
	$q    = isset($_POST['q']) ? trim($_POST['q']) : '';
	$page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
	$perPage = 20;
	$offset  = ($page - 1) * $perPage;

// Chuẩn bị câu truy vấn (Prepared Statement để chống SQL injection)
	$sql = "SELECT a.id, CONCAT(s.name, ' (', a.name, ')') AS name
        FROM accounts AS a
        JOIN site s ON a.site_id = s.id
        WHERE (? = '' OR a.name LIKE ? OR a.email LIKE ?)
        ORDER BY a.site_id ASC
        LIMIT ?, ?";
	$stmt = $conn->prepare($sql);

	$like = "%{$q}%";
	$stmt->bind_param("sssii", $q, $like, $like, $offset, $perPage);
	$stmt->execute();

	$result = $stmt->get_result();
	$items = [];
	while ($row = $result->fetch_assoc()) {
		$items[] = $row;
	}
	$stmt->close();

	// Kiểm tra còn dữ liệu trang tiếp theo hay không
	$more = (count($items) === $perPage);
	return [
		'items' => $items,
		'more'  => $more
	];
}