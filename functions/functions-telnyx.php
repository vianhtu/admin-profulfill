<?php
function hookTelnyx(): array
{
    $conn = db(); // Hàm db() trả về MySQLi connection

    $key = $_GET['key'] ?? '';
    if ($key === '') {
        return ['status' => 'error', 'message' => 'Missing key'];
    }

    // Kiểm tra khóa và lấy author ID.
    $stmt_a = $conn->prepare("SELECT ID FROM team WHERE `key` = ? LIMIT 1");
    $stmt_a->bind_param("s", $key);
    $stmt_a->execute();
    $result_a = $stmt_a->get_result();
    if ($row_a = $result_a->fetch_assoc()) {
        // Tìm thấy key
        $teamId = $row_a['ID'];
    } else {
        // Không tìm thấy key
        return ['status' => 'error', 'message' => 'Invalid key'];
    }

    // Lấy raw JSON từ webhook
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['status' => 'error', 'message' => 'Invalid JSON'];
    }

    // Lấy dữ liệu từ JSON
    $fromNumber  = $data['data']['payload']['from']['phone_number'] ?? null;
    $toNumber    = $data['data']['payload']['to'][0]['phone_number'] ?? null;
    $carrierName = $data['data']['payload']['to'][0]['carrier'] ?? null;
    $text        = $data['data']['payload']['text'] ?? null;
    $dateRaw     = $data['data']['payload']['received_at'] ?? null;

    if (!$fromNumber || !$toNumber || !$text) {
        return ['status' => 'error', 'message' => 'Missing required fields'];
    }

    if ($dateRaw) {
        // Chuyển về timestamp
        $dt = new DateTime($dateRaw);
        $date = $dt->format('Y-m-d H:i:s'); // MySQL datetime format
    } else {
        $date = date('Y-m-d H:i:s'); // fallback nếu không có
    }

    // --- Lấy carrier_id ---
    $carrierId = null;
    if ($carrierName) {
        $stmt = $conn->prepare("SELECT id FROM phone_carrier WHERE name = ? LIMIT 1");
        $stmt->bind_param("s", $carrierName);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $carrierId = $row['id'];
        } else {
            // Thêm mới carrier
            $insertCarrier = $conn->prepare("INSERT INTO phone_carrier (name) VALUES (?)");
            $insertCarrier->bind_param("s", $carrierName);
            $insertCarrier->execute();
            $carrierId = $conn->insert_id;
            $insertCarrier->close();
        }
        $stmt->close();
    }

    // --- Lấy phone_id ---
    $stmt = $conn->prepare("SELECT id FROM phones WHERE number = ? LIMIT 1");
    $stmt->bind_param("s", $toNumber);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $phoneId = $row['id'];
    } else {
        // Thêm mới phone
        $insertPhone = $conn->prepare("INSERT INTO phones (team_id, carrier_id, status, number) VALUES (?, ?, 'active', ?)");
        $insertPhone->bind_param("iis",$teamId, $carrierId, $toNumber);
        $insertPhone->execute();
        $phoneId = $conn->insert_id;
        $insertPhone->close();
    }
    $stmt->close();

    // --- Lưu vào bảng sms ---
    $stmt = $conn->prepare("INSERT INTO sms (phone_id, status, from_number, text, date) VALUES (?, 'pending', ?, ?, ?)");
    $stmt->bind_param("isss", $phoneId, $fromNumber, $text, $date);

    if ($stmt->execute()) {
        $stmt->close();
        return ['status' => 'success', 'message' => 'SMS saved'];
    } else {
        $stmt->close();
        return ['status' => 'error', 'message' => 'Database insert failed'];
    }
}

function getSMS(): array
{
    $conn = db();
    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

    $sql = "SELECT sms.text, sms.from_number, sms.date, p.number, pc.name
            FROM sms
            INNER JOIN phones p ON p.ID = sms.phone_id
            INNER JOIN phone_carrier pc ON pc.ID = p.carrier_id";

    $whereClauses = [];
    if ($id) {
        $whereClauses[] = "sms.phone_id = ?";
    }

    if(!is_admin()){
        $teamId = $_SESSION['auth']['team'] ?? null;
        if($teamId){
            $whereClauses[] = "p.team_id = $teamId";
        }
    }
    $where = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';
    $sql .= "$where ORDER BY sms.date DESC LIMIT 20";

    $stmt = $conn->prepare($sql);

    if ($id) {
        $stmt->bind_param("i", $id);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $smsList = [];
    while ($row = $result->fetch_assoc()) {
        $smsList[] = $row;
    }

    return $smsList;
}

function getPhonesTable(): array
{
    $allowedCols = ['ID', 'number', 'status'];
    $params = get_datatable_params($allowedCols);
    if(!checkRoles('view', 'phones_numbers')){
        return [
            "draw"            => $params['draw'],
            "recordsTotal"    => 0,
            "recordsFiltered" => 0,
            "data"            => []
        ];
    }
    $conn = db();
    // search.
    $whereClauses = [];
    if ($params['searchValue'] !== '') {
        $searchEsc      = $conn->real_escape_string($params['searchValue']);
        $whereClauses[] = "phones.number LIKE '%$searchEsc%'";
    }
    // team
    $totalTeam = '';
    if(!is_admin()){
        $teamId = $_SESSION['auth']['team'] ?? null;
        if($teamId){
            $whereClauses[] = "phones.team_id = $teamId";
            $totalTeam = "WHERE phones.team_id = $teamId";
        }
    }

    // Tổng số bản ghi
    $totalRecords = $conn->query("SELECT COUNT(*) AS cnt FROM phones $totalTeam")->fetch_assoc()['cnt'];

    $where = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

    // JOIN tối ưu: lấy sms mới nhất qua bảng con
    $join = "
        INNER JOIN phone_carrier ON phone_carrier.ID = phones.carrier_id
        LEFT JOIN sms ON sms.phone_id = phones.ID AND sms.status = 'pending'
        LEFT JOIN (
            SELECT s1.phone_id, s1.text
            FROM sms s1
            INNER JOIN (
                SELECT phone_id, MAX(date) AS max_date
                FROM sms
                GROUP BY phone_id
            ) m ON m.phone_id = s1.phone_id AND m.max_date = s1.date
        ) s_latest ON s_latest.phone_id = phones.ID
    ";

    // Tổng số bản ghi sau khi lọc
    $totalFiltered = $conn->query("
        SELECT COUNT(DISTINCT phones.ID) AS cnt
        FROM phones
        $join
        $where
    ")->fetch_assoc()['cnt'];

    // Lấy dữ liệu
    $sql = "
        SELECT 
            phones.ID, 
            phones.status, 
            phones.number, 
            phone_carrier.name, 
            COUNT(sms.ID) AS sms_count,
            s_latest.text AS latest_sms_text
        FROM phones
        $join
        $where
        GROUP BY phones.ID, phones.status, phones.number, phone_carrier.name, s_latest.text
        ORDER BY phones.{$params['orderColumn']} {$params['orderDir']}
        LIMIT {$params['start']}, {$params['length']}";
    $rs  = $conn->query($sql);

    // Chuẩn bị dữ liệu trả về
    $data = [];
    while ($row = $rs->fetch_assoc()) {
        $data[] = [
            "id"      => $row['ID'],
            "number"  => $row['number'],
            "status"  => $row['status'],
            "carrier" => $row['name'],
            "notice"  => [
                'sms_count' => $row['sms_count'],
            ],
            'latest_sms_text' => $row['latest_sms_text'],
            "account" => '',
        ];
    }

    // Trả JSON
    return [
        "draw"            => $params['draw'],
        "recordsTotal"    => $totalRecords,
        "recordsFiltered" => $totalFiltered,
        "data"            => $data
    ];
}