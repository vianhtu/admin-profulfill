<?php
$conn = db();
// Lấy thông số từ DataTables
$draw = intval($_POST['draw'] ?? 1);
$start = intval($_POST['start'] ?? 0);
$length = intval($_POST['length'] ?? 10);
$orderColumnIndex = intval($_POST['order'][0]['column'] ?? 0);
$orderColumn = $_POST['columns'][$orderColumnIndex]['data'] ?? 'ID';
$orderDir = ($_POST['order'][0]['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
$searchValue = trim($_POST['search']['value'] ?? '');

// Danh sách cột cho phép sort
$allowedCols = ['ID','title','status','sku','date','badge'];
if (!in_array($orderColumn, $allowedCols)) {
	$orderColumn = 'ID';
}

// Tổng số bản ghi
$totalRecords = $conn->query("SELECT COUNT(*) AS cnt FROM posts")->fetch_assoc()['cnt'];
$whereClauses = [];
// Lọc theo search
if ($searchValue !== '') {
	$searchEsc = $conn->real_escape_string($searchValue);
	$whereClauses[] = "(title LIKE '%$searchEsc%' OR sku LIKE '%$searchEsc%' OR status LIKE '%$searchEsc%' OR badge LIKE '%$searchEsc%')";
}
// lọc theo status.
$filterStatus = $_POST['columns'][8]['search']['value'] ?? '';
$filterStatus = trim($filterStatus, '^$'); // bỏ ký tự regex
if ($filterStatus !== '') {
	$escStock = $conn->real_escape_string($filterStatus);
	$whereClauses[] = "status = '$escStock'";
}
// lọc theo type.
$filterType = $_POST['columns'][3]['search']['value'] ?? '';
$filterType = trim($filterType, '^$'); // bỏ ký tự regex
if ($filterType !== '') {
	$escStock = $conn->real_escape_string($filterType);
	$whereClauses[] = "type_id = '$escStock'";
}
// lọc theo author.
$filterAuthor = $_POST['columns'][4]['search']['value'] ?? '';
$filterAuthor = trim($filterAuthor, '^$'); // bỏ ký tự regex
if ($filterAuthor !== '') {
	$escStock = $conn->real_escape_string($filterAuthor);
	$whereClauses[] = "author_id = '$escStock'";
}
// lọc theo sites.
$filterSites = $_POST['sites'] ?? [];
if (!empty($filterSites) && is_array($filterSites)) {
	// Ép tất cả sang số nguyên để tránh injection
	$ids = array_map('intval', $filterSites);
	$idsStr = implode(',', $ids);
	if ($idsStr !== '') {
		$whereClauses[] = "site_id IN ($idsStr)";
	}
}
// Lọc theo khoảng ngày
$minDate = $_POST['minDate'] ?? '';
$maxDate = $_POST['maxDate'] ?? '';

if ($minDate !== '' && $maxDate !== '') {
	$escMin = $conn->real_escape_string($minDate);
	$escMax = $conn->real_escape_string($maxDate);
	$whereClauses[] = "DATE(`date`) BETWEEN '$escMin' AND '$escMax'";
} elseif ($minDate !== '') {
	$escMin = $conn->real_escape_string($minDate);
	$whereClauses[] = "DATE(`date`) >= '$escMin'";
} elseif ($maxDate !== '') {
	$escMax = $conn->real_escape_string($maxDate);
	$whereClauses[] = "DATE(`date`) <= '$escMax'";
}
// lọc theo stores.
$filterStores = $_POST['stores'] ?? [];
if (!empty($filterStores) && is_array($filterStores)) {
	// Ép tất cả sang số nguyên để tránh injection
	$ids = array_map('intval', $filterStores);
	$idsStr = implode(',', $ids);
	if ($idsStr !== '') {
		$whereClauses[] = "store_id IN ($idsStr)";
	}
}
// lọc theo accounts.
$filterAccounts = $_POST['accounts'] ?? [];
if (!empty($filterAccounts) && is_array($filterAccounts)) {
	// Ép tất cả sang số nguyên để tránh SQL injection
	$ids = array_map('intval', $filterAccounts);
	$idsStr = implode(',', $ids);
	if ($idsStr !== '') {
		// Lọc các post có account_id nằm trong danh sách
		$whereClauses[] = "posts.ID IN (
            SELECT post_id 
            FROM accounts_relationships
            WHERE account_id IN ($idsStr)
        )";
	}
}

$where = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';
$totalFiltered = $conn->query("SELECT COUNT(*) AS cnt FROM posts $where")->fetch_assoc()['cnt'];

// Lấy dữ liệu
$sql = "SELECT ID, title, status, sku, images, badge, date, type_id, author_id 
        FROM posts
        $where
        ORDER BY $orderColumn $orderDir
        LIMIT $start, $length";
$rs = $conn->query($sql);

// Chuẩn bị dữ liệu trả về
$data = [];
while ($row = $rs->fetch_assoc()) {
	$imgs = json_decode($row['images']);
	// Thay thế phần il_###xN bằng il_50xN
	$updatedUrl = preg_replace('/il_\d+xN/', 'il_100xN', $imgs->main);
	//$firstImg = $imgs['main'];
	$data[] = [
		"id" => $row['ID'],
		"product_name" => htmlspecialchars($row['title']),
		"category"=> $row['type_id'],
		"stock"=> $row['author_id'],
		"sku"=> htmlspecialchars($row['sku']),
		"price"=> "$999",
		"qty"=> 665,
		"status"=> 3,
		"image"=> $updatedUrl,
		"product_brand"=> "Etsy"
	];
}

// Trả JSON
echo json_encode([
	"draw" => $draw,
	"recordsTotal" => $totalRecords,
	"recordsFiltered" => $totalFiltered,
	"data" => $data,
	"post" => $_POST
]);