<?php
/**
 * Lọc danh sách author_id, chỉ giữ lại những người thuộc cùng một Team cụ thể.
 */
function filterAuthorsByTeam($conn, array $authorIds, int $teamId): array
{
    if (empty($authorIds)) {
        return [];
    }

    // Ép kiểu số nguyên để bảo mật
    $authorIds = array_map('intval', $authorIds);

    // Tạo placeholders (?,?,?) cho câu lệnh IN
    $placeholders = implode(',', array_fill(0, count($authorIds), '?'));

    $sql = "SELECT ID FROM authors WHERE ID IN ($placeholders) AND team_id = ?";
    $stmt = $conn->prepare($sql);

    // Chuẩn bị tham số: danh sách ID + tham số team_id cuối cùng
    $types = str_repeat('i', count($authorIds)) . 'i';
    $params = [...$authorIds, $teamId];

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $filteredIds = [];
    while ($row = $result->fetch_assoc()) {
        $filteredIds[] = (int)$row['ID'];
    }
    $stmt->close();

    return $filteredIds;
}