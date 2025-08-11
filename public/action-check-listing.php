<?php
if (!defined('AJAX_APP')) {
	die('Access denied');
}

function getFoundListing(array $data): array
{
	// 1. Validate inputs
	if (empty($data['id']) || empty($data['store']) || empty($data['title'])) {
		return [];
	}

	// 2. LEFT JOIN để luôn trả về store.status ngay cả khi posts.sku không khớp
	$sql = "
        SELECT
            s.status,
            p.sku
        FROM store AS s
        LEFT JOIN posts AS p
            ON p.sku = ?
        WHERE s.name = ?
    ";

	$stmt = $GLOBALS['mysqli']->prepare($sql);

	// Thứ tự bind: p.sku = ?, s.name = ?
	$stmt->bind_param('ss', $data['id'], $data['store']);

	// 5. Thực thi và fetch kết quả
	$stmt->execute();
	$result = $stmt->get_result();
	$row    = $result->fetch_assoc();
	$stmt->close();

	$found = [];
	// 4. Chuẩn bị mảng kết quả: luôn có status, thêm sku nếu có
	if(!empty($row['status'])){
		$found['status'] = $row['status'];
	}

	if (! empty($row['sku'])) {
		$found['sku'] = $row['sku'];
	} else {
		$found['type'] = getPostTypes();
	}

	$kws = extract_keywords($data['title']);
	$kws_found = getFoundKeywordsFast($kws);
	if(!empty($kws_found)){
		$found['keywords'] = $kws_found;
	}

	return $found;
}

$data = json_decode(file_get_contents('php://input'), true);
if (empty($data)) {
	echo json_encode([
		'status'  => 'error',
		'message' => 'Titles list is empty or invalid'
	]);
	return;
}

$results = getFoundListing($data);

echo json_encode([
	'status' => 'success',
	'data'   => $results
]);