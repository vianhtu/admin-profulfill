<?php
function getTeamsTable():array
{
    $allowedCols = ['ID', 'name', 'status'];

    // Lấy tham số từ DataTables
    $params = getDataTableParams($allowedCols);
    if(!checkRoles('view', 'teams')){
        return [
            "draw"            => $params['draw'],
            "recordsTotal"    => 0,
            "recordsFiltered" => 0,
            "data"            => []
        ];
    }

    $conn = db();

    // Tổng số bản ghi
    $totalRecords = $conn->query("SELECT COUNT(*) AS cnt FROM team")->fetch_assoc()['cnt'];

    $whereClauses = [];

    // Lọc theo search
    if ($params['searchValue'] !== '') {
        $searchEsc = $conn->real_escape_string($params['searchValue']);
        $whereClauses[] = "team.name LIKE '%$searchEsc%'";
    }

    $where = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

    // Tổng số bản ghi sau khi lọc
    $totalFiltered = $conn->query(
        "SELECT COUNT(ID) AS cnt FROM team $where"
    )->fetch_assoc()['cnt'];

    // Lấy dữ liệu
    $sql = "SELECT team.ID, team.name, team.status, COUNT(authors.ID) AS members
            FROM team
            LEFT JOIN authors ON authors.team_id = team.ID
            $where
            GROUP BY team.ID
            ORDER BY team.{$params['orderColumn']} {$params['orderDir']}
            LIMIT {$params['start']}, {$params['length']}";
    $rs = $conn->query($sql);

    // Chuẩn bị dữ liệu trả về
    $data = [];
    while ($row = $rs->fetch_assoc()) {
        $data[] = [
            "id"          => $row['ID'],
            "name"        => $row['name'],
            "status"      => $row['status'],
            "members"     => $row['members']
        ];
    }

    return [
        "draw"            => $params['draw'],
        "recordsTotal"    => $totalRecords,
        "recordsFiltered" => $totalFiltered,
        "data"            => $data
    ];
}