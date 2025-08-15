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
	$thumb = '';
	if (!empty($row['images'])) {
		$imgs = explode(',', $row['images']);
		$firstImg = trim($imgs[0]);
		$thumb = '<img src="'.$firstImg.'" alt="img" style="width:50px;height:50px;object-fit:cover;">';
	}

	$badgeHTML = '';
	if (!empty($row['badge'])) {
		$color = match(strtolower($row['badge'])) {
			'new' => 'success',
			'sale' => 'danger',
			'hot' => 'warning',
			default => 'secondary',
		};
		$badgeHTML = '<span class="badge bg-'.$color.'">'.htmlspecialchars($row['badge']).'</span>';
	}

	$data[] = [
		'ID'     => $row['ID'],
		'title'  => htmlspecialchars($row['title']),
		'status' => htmlspecialchars($row['status']),
		'sku'    => htmlspecialchars($row['sku']),
		'images' => '',//$thumb,
		'badge'  => $badgeHTML,
		'date'   => date('d-m-Y H:i', strtotime($row['date']))
	];
}

// Trả JSON
echo json_encode([
	"draw" => $draw,
	"recordsTotal" => $totalRecords,
	"recordsFiltered" => $totalFiltered,
	"data" => $data
]);