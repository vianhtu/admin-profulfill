<?php
if (!defined('AJAX_APP')) {
	die('Access denied');
}

function getFoundListings(array $data): array {
	// 1. Trích các ID và ép kiểu string
	$ids = array_map('strval', $data['ids']);
	if (empty($ids)) {
		return [];
	}

	// 2. Xây dựng chuỗi IN (...) an toàn
	$inList = implode(',', array_fill(0, count($ids), '?'));
	$types  = str_repeat('s', count($ids)); // tương ứng với VARCHAR sku

	// 3. Chuẩn bị statement
	$sql = "SELECT sku FROM posts WHERE sku IN ($inList)";
	$stmt = $GLOBALS['mysqli']->prepare($sql);

	// 4. Bind các tham số động
	$stmt->bind_param($types, ...$ids);

	// 5. Thực thi và fetch kết quả
	$stmt->execute();
	// 3. Lấy toàn bộ bản ghi
	$rows = $stmt
		->get_result()
		->fetch_all(MYSQLI_ASSOC);
	$stmt->close();
	// Trích xuất giá trị 'sku' từ mỗi phần tử
	$skuList = array_column($rows, 'sku');
	return $skuList;
}

$data = json_decode(file_get_contents('php://input'), true);
if (empty($data)) {
	echo json_encode([
		'status'  => 'error',
		'message' => 'Titles list is empty or invalid'
	]);
	return;
}

$results = getFoundListings($data);

echo json_encode([
	'status' => 'success',
	'data'   => $results
]);