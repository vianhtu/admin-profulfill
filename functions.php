<?php
function renderMenu($currentMenu) {
	$menuItems = [
		'Dashboards' => [
			'icon' => 'tabler-smart-home',
			'sub' => [
				'analytics' => 'Analytics'
			]
		],
		'eCommerce' => [
			'icon' => 'tabler-shopping-cart',
			'sub' => [
				'products' => 'Products',
				'stores' => 'Stores',
				'types' => 'Types',
				'sites' => 'Sites',
				'tags' => 'Tags',
				'keywords' => 'Keywords',
				'accounts' => 'Accounts',
				'export-settings' => 'Export Settings'
			]
		]
	];

	foreach ($menuItems as $mainLabel => $mainData) {
		$isOpen = false;
		$subMenuHtml = '';

		foreach ($mainData['sub'] as $key => $value) {
			$label = is_array($value) ? $value['label'] : $value;
			$target = is_array($value) && isset($value['target']) ? 'target="_blank"' : '';
			$activeClass = ($currentMenu === $key) ? 'active' : '';
			if ($activeClass) $isOpen = true;

			$subMenuHtml .= <<<HTML
                <li class="menu-item {$activeClass}">
                    <a href="index.php?menu={$key}" class="menu-link" {$target}>
                        <div data-i18n="{$label}">{$label}</div>
                    </a>
                </li>
            HTML;
		}

		$openClass = $isOpen ? 'active open' : '';
		$icon = $mainData['icon'];

		echo <<<HTML
            <li class="menu-item {$openClass}">
                <a href="javascript:void(0);" class="menu-link menu-toggle">
                    <i class="menu-icon icon-base ti {$icon}"></i>
                    <div data-i18n="{$mainLabel}">{$mainLabel}</div>
                </a>
                <ul class="menu-sub">
                    {$subMenuHtml}
                </ul>
            </li>
        HTML;
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

function getAuthorsProductInfo(): array {
	return $_SESSION;
}

function getProductTableFilter(): array {
	$options = [];
	$options['types'] = getTypes();
	$options['authors'] = getAuthors();
	$options['sites'] = getSites();
	$options['authors_info'] = getAuthorsProductInfo();
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
	$sql = "SELECT id, name 
        FROM store
        WHERE (? = '' OR name LIKE ?)
        ORDER BY name ASC
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