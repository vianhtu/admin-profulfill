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
			$href = $link === '' ? 'javascript:void(0);' : "index.php?menu={$link}";
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

function getProductTableFilter(): array {
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