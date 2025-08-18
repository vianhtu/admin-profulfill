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

function getProductTableFilter(): array {
	$options = [];
	$options['types'] = getTypes();
	$options['authors'] = getAuthors();
	return $options;
}

function getStoresTableFilter(): array {
	$conn = db();
	// Lấy dữ liệu POST
	$q    = isset($_POST['q']) ? trim($_POST['q']) : '';
	$page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
	$perPage = 20;

// Truy vấn có tìm kiếm
	$sql = "SELECT id, name 
        FROM store
        WHERE (:q = '' OR name LIKE :kw)
        ORDER BY name ASC
        LIMIT :offset, :limit";
	$stmt = $conn->prepare($sql);
	$stmt->bindValue(':q', $q, PDO::PARAM_STR);
	$stmt->bindValue(':kw', "%$q%", PDO::PARAM_STR);
	$stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
	$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
	$stmt->execute();

	$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
	// Xác định còn trang sau không
	$more = count($items) === $perPage;
	return [
		'items' => $items,
		'more'  => $more
	];
}