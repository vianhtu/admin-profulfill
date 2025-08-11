<?php
if (!defined('AJAX_APP')) {
	die('Access denied');
}

function addListing(array $data): array{
	// 1. Validate inputs
	if(empty($data['id']) || empty($data['images']['main']) || empty($data['title']) || empty($data['type']) || empty($data['site'] || empty($data['shop']['id']))) {
		return [];
	}

	// 2. Lấy ID loại theo chính ID (integer)
	$typeId  = getIDBy('type',  'ID',   $data['type'], 'i');
	if(!$typeId){
		return [];
	}

	// 3. Lấy ID site theo site name
	$siteId  = getIDBy('site',  'name',   $data['site']);
	if(!$siteId){
		return [];
	}

	// 4. Check if the product already exists
	$postId = getIDBy('posts', 'sku',  $data['id']);
	if($postId){
		return [];
	}

	// 5. Check if the store exists
	$storeId = getIDBy('store', 'slug', strtolower($data['shop']['id']));
	if(!$storeId){
		// 5.1 Add store and get store id.
		$sql = "INSERT INTO store (name, slug) VALUES (?, ?)";
		$stmt1 = $GLOBALS['mysqli']->prepare($sql);
		$stmt1->bind_param('ss', $data['shop']['id'], strtolower($data['shop']['id']));
		$stmt1->execute();
		$storeId = $GLOBALS['mysqli']->insert_id;
		$stmt1->close();
		if(empty($storeId)){
			return [];
		}
	}

	$badge = $data['badge'] ?? '';
	// 6. Add a product to the database
	$sql      = "INSERT INTO posts (author_id, date, title, status, sku, images, type_id, site_id, store_id, badge) VALUES (?, NOW(),?, 'pending', ?, ?, ?, ?, ?, ?)";
	$stmt2    = $GLOBALS['mysqli']->prepare($sql);
	$stmt2->bind_param('isssiiis', $GLOBALS['authorid'], $data['title'], $data['id'], json_encode($data['images']), $typeId, $siteId, $storeId, $badge);
	$stmt2->execute();
	$postId   = $GLOBALS['mysqli']->insert_id;
	$stmt2->close();
	return [$postId];
}

$data = json_decode(file_get_contents('php://input'), true);
if (empty($data)) {
	echo json_encode([
		'status'  => 'error',
		'message' => 'Titles list is empty or invalid'
	]);
	return;
}

$results = addListing($data);

echo json_encode([
	'status' => 'success',
	'data'   => $results
]);