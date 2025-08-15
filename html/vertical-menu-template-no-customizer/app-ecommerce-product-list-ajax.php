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

// Lọc theo search
$where = "";
if ($searchValue !== '') {
	$searchEsc = $conn->real_escape_string($searchValue);
	$where = " WHERE (title LIKE '%$searchEsc%' OR sku LIKE '%$searchEsc%' OR status LIKE '%$searchEsc%' OR badge LIKE '%$searchEsc%')";
}

$totalFiltered = $conn->query("SELECT COUNT(*) AS cnt FROM posts $where")->fetch_assoc()['cnt'];

// Lấy dữ liệu
$sql = "SELECT ID, title, status, sku, images, badge, date 
        FROM posts
        $where
        ORDER BY $orderColumn $orderDir
        LIMIT $start, $length";
$rs = $conn->query($sql);

// Chuẩn bị dữ liệu trả về
$data = [];
while ($row = $rs->fetch_assoc()) {
	$imgs = json_decode($row['images']);
	//$firstImg = $imgs['main'];
	$data[] = [
		"id" => $row['ID'],
		"product_name" => htmlspecialchars($row['title']),
		"category"=> 2,
		"stock"=> 1,
		"sku"=> htmlspecialchars($row['sku']),
		"price"=> "$999",
		"qty"=> 665,
		"status"=> 3,
		"image"=> $row['images'],
		"product_brand"=> "Super Retina XDR display footnote Pro Motion technology"
	];
}

// Trả JSON
echo json_encode([
	"draw" => $draw,
	"recordsTotal" => $totalRecords,
	"recordsFiltered" => $totalFiltered,
	"data" => $data
]);