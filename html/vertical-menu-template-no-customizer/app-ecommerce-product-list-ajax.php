<?php
$conn = db();

// Lấy thông tin từ request DataTables
$draw = intval($_POST['draw']);
$start = intval($_POST['start']);
$length = intval($_POST['length']);
$searchValue = $_POST['search']['value'] ?? '';

// Base query
$baseQuery = "
  SELECT 
    p.post_id, 
    p.post_image, 
    p.post_title, 
    c.category_name, 
    p.post_status, 
    p.stock_status, 
    p.post_date
  FROM posts p
  LEFT JOIN categories c ON p.category_id = c.category_id
";

// Filtering
$where = '';
$params = [];

if (!empty($searchValue)) {
	$where .= " WHERE p.post_title LIKE ? OR c.category_name LIKE ? OR p.post_status LIKE ? ";
	$params[] = "%$searchValue%";
	$params[] = "%$searchValue%";
	$params[] = "%$searchValue%";
}

// Lấy tổng số bản ghi
$totalRecordsStmt = $conn->prepare("SELECT COUNT(*) FROM posts");
$totalRecordsStmt->execute();
$totalRecords = $totalRecordsStmt->fetchColumn();

// Lấy tổng số bản ghi sau filter
$totalFiltered = $totalRecords;
if (!empty($where)) {
	$countFilteredStmt = $conn->prepare("SELECT COUNT(*) FROM posts p LEFT JOIN categories c ON p.category_id = c.category_id $where");
	$countFilteredStmt->execute($params);
	$totalFiltered = $countFilteredStmt->fetchColumn();
}

// Sắp xếp
$orderColumnIndex = $_POST['order'][0]['column'] ?? 0;
$orderColumnName = [
	                   'p.post_id',
	                   'p.post_image',
	                   'p.post_title',
	                   'c.category_name',
	                   'p.post_status',
	                   'p.stock_status',
	                   'p.post_date'
                   ][$orderColumnIndex] ?? 'p.post_id';
$orderDir = $_POST['order'][0]['dir'] === 'desc' ? 'DESC' : 'ASC';

// Thêm ORDER, LIMIT
$query = $baseQuery . $where . " ORDER BY $orderColumnName $orderDir LIMIT ?, ?";
$params[] = $start;
$params[] = $length;

// Thực thi query
$stmt = $conn->prepare($query);
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Thêm cột hành động
foreach ($data as &$row) {
	$row['actions'] = ''; // JS sẽ render
}

echo json_encode([
	"draw" => $draw,
	"recordsTotal" => $totalRecords,
	"recordsFiltered" => $totalFiltered,
	"data" => $data
]);