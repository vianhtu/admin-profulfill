<?php
function getIDBy(string $table, string $column, string|int $value, string $type = 's'): false|int {
	global $mysqli;

	$sql  = "SELECT ID FROM `$table` WHERE `$column` = ? LIMIT 1";
	$stmt = $mysqli->prepare($sql);
	if (!$stmt) {
		return false;
	}

	$stmt->bind_param($type, $value);
	$stmt->execute();
	$stmt->bind_result($id);

	$found = $stmt->fetch();
	$stmt->close();

	return $found ? (int)$id : false;
}
function getPostTypes() {
	$sql  = "SELECT ID, name
             FROM type
             LIMIT 50";
	$stmt = $GLOBALS['mysqli']->prepare($sql);
	$stmt->execute();
	// 3. Lấy toàn bộ bản ghi
	$rows = $stmt
		->get_result()
		->fetch_all(MYSQLI_ASSOC);

	$stmt->close();
	return $rows;
}
function getFoundKeywordsFast(array $keywords): array
{
	// 1. Chuẩn bị map từ lowercase → original
	$lowerKeywords = array_map('strtolower', $keywords);
	$map           = array_combine($lowerKeywords, $keywords);

	// Không có keyword thì return ngay
	if (empty($map)) {
		return [];
	}

	// 2. Chuẩn bị placeholder và tham số
	$statuses   = [1, 2];
	$phStatus   = implode(',', array_fill(0, count($statuses), '?'));
	$phKeywords = implode(',', array_fill(0, count($lowerKeywords), '?'));

	$sql  = "SELECT name, status
             FROM keywords
             WHERE status IN ($phStatus)
               AND name   IN ($phKeywords)";
	$stmt = $GLOBALS['mysqli']->prepare($sql);

	// Loại dữ liệu: 'i' cho status, 's' cho keywords
	$types  = str_repeat('i', count($statuses))
	          . str_repeat('s', count($lowerKeywords));
	$params = array_merge($statuses, $lowerKeywords);

	$stmt->bind_param($types, ...$params);
	$stmt->execute();

	// 3. Lấy toàn bộ bản ghi
	$rows = $stmt
		->get_result()
		->fetch_all(MYSQLI_ASSOC);

	$stmt->close();

	// 4. Map kết quả về mảng trả về
	$output = [];
	foreach ($rows as $row) {
		$originalKey = $map[strtolower($row['name'])];
		$output[$originalKey] = (int)$row['status'];
	}

	return $output;
}
function extract_keywords(string $title): array {
	// 1. Remove HTML tags and non-alphanumeric characters
	$cleaned = strip_tags($title);
	$cleaned = preg_replace('/[^A-Za-z0-9 ]+/', '', $cleaned);
	$cleaned = preg_replace('/\s+/', ' ', $cleaned);
	$cleaned = trim($cleaned);

	// 2. Split into words
	$words  = explode(' ', $cleaned);
	$length = count($words);

	$single = [];
	$double = [];
	$triple = [];

	// 3. Build 1-grams, 2-grams, 3-grams
	for ($i = 0; $i < $length; $i++) {
		$single[] = $words[$i];

		if ($i + 1 < $length) {
			$double[] = $words[$i] . ' ' . $words[$i + 1];
		}

		if ($i + 2 < $length) {
			$triple[] = $words[$i] . ' ' . $words[$i + 1] . ' ' . $words[$i + 2];
		}
	}

	// 4. Deduplicate and reindex
	$single = array_values(array_unique($single));
	$double = array_values(array_unique($double));
	$triple = array_values(array_unique($triple));

	return array_merge($single, $double, $triple);
}