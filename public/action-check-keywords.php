<?php
if (!defined('AJAX_APP')) {
	die('Access denied');
}
/**
 * Tìm keyword và status theo batch mỗi 1000 keywords
 *
 * @param mysqli $mysqli
 * @param array  $keywords  Mảng keywords gốc
 * @param array  $statuses  Mảng status (mặc định [1,2])
 * @param int    $batchSize Số keywords tối đa mỗi lượt query
 * @return array  [originalKeyword => status, ...]
 */
function getFoundKeywordsInBatches(array $keywords, int $batchSize = 500 ): array {
	// Loại trùng, map lowercase -> original
	$keywords = array_values(array_unique($keywords));
	if (empty($keywords)) {
		return [];
	}

	$output = [];

	// Chia mảng thành từng batch
	$chunks = array_chunk($keywords, $batchSize);
	foreach ($chunks as $chunk) {
		// Dùng lại hàm getFoundKeywordsFast hoặc nội dung tương tự
		$found = getFoundKeywordsFast($chunk);
		// Gộp kết quả (key là original keyword)
		foreach ($found as $origKw => $status) {
			$output[$origKw] = $status;
		}
	}

	return $output;
}

$titles = json_decode(file_get_contents('php://input'), true);  // ['title1', 'title2', ...]
if (empty($titles) || !is_array($titles)) {
	echo json_encode([
		'status'  => 'error',
		'message' => 'Titles list is empty or invalid'
	]);
	return;
}

// 1. Extract & map keywords cho từng title
$titleMap    = [];
$allKeywords = [];
foreach ($titles as $idx => $title) {
	$kws = extract_keywords($title);
	$titleMap[$idx] = $kws;
	$allKeywords = array_merge($allKeywords, $kws);
}

// 2. Lấy status cho tất cả keywords (tự động chia batch)
$foundMap = getFoundKeywordsInBatches($allKeywords);

// 3. Gán kết quả về từng title
$results = [];
foreach ($titleMap as $idx => $kws) {
	// Chỉ lấy những keyword có trong foundMap
	$results[$titles[$idx]] = array_intersect_key(
		$foundMap,
		array_flip($kws)
	);
}

echo json_encode([
	'status' => 'success',
	'data'   => $results
]);