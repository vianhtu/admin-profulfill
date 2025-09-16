<?php
function hookTelnyx(): array
{
    $conn = db(); // Hàm db() trả về MySQLi connection

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
        $insertPhone = $conn->prepare("INSERT INTO phones (carrier_id, status, number) VALUES (?, 'active', ?)");
        $insertPhone->bind_param("is", $carrierId, $toNumber);
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

function get_sms(): array
{
    $conn = db();
    $sql = "SELECT DISTINCT sms.text, sms.from_number, sms.date, p.number, pc.name
            FROM sms
            INNER JOIN phones p ON p.ID = sms.phone_id
            INNER JOIN phone_carrier pc ON pc.ID = p.carrier_id
            ORDER BY sms.date DESC
            LIMIT 20";
    $stmt = $conn->prepare($sql);
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
    $conn = db();
    // Lấy thông số từ DataTables
    $draw             = intval( $_POST['draw'] ?? 1 );
    $start            = intval( $_POST['start'] ?? 0 );
    $length           = intval( $_POST['length'] ?? 10 );
    $orderColumnIndex = intval( $_POST['order'][0]['column'] ?? 0 );
    $orderColumn      = $_POST['columns'][ $orderColumnIndex ]['data'] ?? 'ID';
    $orderDir         = strtolower( $_POST['order'][0]['dir'] ?? 'asc' ) === 'desc' ? 'DESC' : 'ASC';
    $searchValue      = trim( $_POST['search']['value'] ?? '' );

    // Danh sách cột cho phép sort
    $allowedCols = ['ID', 'number', 'status'];
    if ( ! in_array( $orderColumn, $allowedCols ) ) {
        $orderColumn = 'ID';
    }

    // Tổng số bản ghi
    $totalRecords = $conn->query( "SELECT COUNT(*) AS cnt FROM phones" )->fetch_assoc()['cnt'];
    $whereClauses = [];
    // Lọc theo search
    if ( $searchValue !== '' ) {
        $searchEsc      = $conn->real_escape_string( $searchValue );
        $whereClauses[] = "(exports.file_name LIKE '%$searchEsc%' OR exports.name LIKE '%$searchEsc%' OR accounts.name LIKE '%$searchEsc%')";
    }
    // lọc theo type.
    $filterType = $_POST['columns'][11]['search']['value'] ?? '';
    $filterType = trim( $filterType, '^$' ); // bỏ ký tự regex
    if ( $filterType !== '' ) {
        $escType      = $conn->real_escape_string( $filterType );
        $whereClauses[] = "exports.type_id = '$escType'";
    }
    // lọc theo site.
    $filterSite = $_POST['columns'][4]['search']['value'] ?? '';
    $filterSite = trim( $filterSite, '^$' ); // bỏ ký tự regex
    if ( $filterSite !== '' ) {
        $escSite      = $conn->real_escape_string( $filterSite );
        $whereClauses[] = "exports.site_id = '$escSite'";
    }
    // lọc theo author.
    $filterAuthor = $_POST['columns'][10]['search']['value'] ?? '';
    $filterAuthor = trim( $filterAuthor, '^$' ); // bỏ ký tự regex
    if ( $filterAuthor !== '' ) {
        $escAuthor       = $conn->real_escape_string( $filterAuthor );
        $whereClauses[] = "download.author_id = '$escAuthor'";
    }
    // lọc theo accounts.
    $filterAccounts = $_POST['accounts'] ?? [];
    if ( ! empty( $filterAccounts ) && is_array( $filterAccounts ) ) {
        // Ép tất cả sang số nguyên để tránh SQL injection
        $ids    = array_map( 'intval', $filterAccounts );
        $idsStr = implode( ',', $ids );
        if ( $idsStr !== '' ) {
            $whereClauses[] = "exports.accounts_id IN ($idsStr)";
        }
    }

    $where         = $whereClauses ? ' WHERE ' . implode( ' AND ', $whereClauses ) : '';
    $join          = 'INNER JOIN phone_carrier ON phone_carrier.ID = phones.carrier_id';
    $totalFiltered = $conn->query( "SELECT COUNT(DISTINCT phones.ID) AS cnt FROM phones $join $where" )->fetch_assoc()['cnt'];

    // Lấy dữ liệu
    $sql = "SELECT DISTINCT phones.ID, phones.status, phones.number, phone_carrier.name
        FROM phones
        $join
        $where
        ORDER BY phones.$orderColumn $orderDir
        LIMIT $start, $length";
    $rs  = $conn->query( $sql );

    // Chuẩn bị dữ liệu trả về
    $data = [];
    while ( $row = $rs->fetch_assoc() ) {
        $data[] = [
            "id"                => $row['ID'],
            "number"            => $row['number'],
            "email"             => '',
            "site_id"           => 1,
            "type_id"           => 1,
            "author_id"         => 1,
            "status"            => $row['status'],
            "date"              => $row['name'],
            "download_date"     => '',
            "total_items"       => '',
            "temp_file_name"    => ''
        ];
    }

    // Trả JSON
    return [
        "draw"            => $draw,
        "recordsTotal"    => $totalRecords,
        "recordsFiltered" => $totalFiltered,
        "data"            => $data
    ];
}