<?php
function hookTelnyx(): array
{
    $conn = db(); // Hàm db() trả về MySQLi connection

    $key = $_GET['key'] ?? '';
    if ($key === '') {
        return ['status' => 'error', 'message' => 'Missing key'];
    }

    // Kiểm tra khóa và lấy author ID. status = 1: team ngừng hoạt động thì key hết hiệu lực.
    $stmt_a = $conn->prepare("SELECT ID FROM team WHERE `key` = ? AND status = 1 LIMIT 1");
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
    $stmt = $conn->prepare("SELECT id, status FROM phones WHERE number = ? LIMIT 1");
    $stmt->bind_param("s", $toNumber);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        // SỐ ĐANG SUSPEND thì KHÔNG nhận tin nữa (chốt 07/08/2026). Suspend là "thôi dùng
        // số này", nên tiếp tục ghi tin vào chỉ làm dữ liệu phình ra và huy hiệu chưa đọc
        // nhảy số cho một số không ai còn theo dõi. Tin cũ vẫn đọc được như thường —
        // chặn ở đây là chặn ĐẦU VÀO, không phải giấu lịch sử.
        if ((string)$row['status'] !== 'active') {
            $stmt->close();
            return ['status' => 'ignored',
                    'message' => 'Number is suspended; message not recorded.'];
        }
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

function getPhonesTable(): array
{
    // Cột sắp xếp được: khóa DataTables (`data` bên JS) => biểu thức SQL.
    // Cột hiển thị TÊN thì sort theo tên (phone_carrier.name), không theo id.
    // `notice` sort theo số tin chưa đọc — dùng alias của hàm đếm, hợp lệ trong ORDER BY
    // sau GROUP BY.
    $sortMap = [
        'ID'      => 'phones.ID',
        'number'  => 'phones.number',
        'status'  => 'phones.status',
        'carrier' => 'phone_carrier.name',
        'notice'  => 'sms_count',
    ];
    $params = get_datatable_params(array_keys($sortMap), 'number');
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
    // PHẠM VI THEO TEAM. Bản cũ viết `if($teamId)` nên tài khoản có team_id = 0 rơi vào
    // nhánh KHÔNG lọc gì -> thấy số điện thoại của MỌI team. Nay non-admin luôn bị ràng,
    // team_id = 0 thì chỉ thấy đúng nhóm team_id = 0 chứ không thấy hết.
    $totalTeam = '';
    if (!is_admin()) {
        $teamId = (int)($_SESSION['auth']['team'] ?? 0);
        $whereClauses[] = "phones.team_id = $teamId";
        $totalTeam = "WHERE phones.team_id = $teamId";
    }

    // Tổng số bản ghi
    $totalRecords = $conn->query("SELECT COUNT(*) AS cnt FROM phones $totalTeam")->fetch_assoc()['cnt'];

    $where = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

    // JOIN tối ưu: lấy sms mới nhất qua bảng con
    $join = "
        INNER JOIN phone_carrier ON phone_carrier.ID = phones.carrier_id
        LEFT JOIN sms ON sms.phone_id = phones.ID AND sms.status = 'pending'
        LEFT JOIN (
            -- Tin MỚI NHẤT của mỗi số. Bắt buộc chọn theo MAX(ID) chứ không phải MAX(date):
            -- hai tin cùng một GIÂY (tin nhắn về theo cụm là chuyện thường) làm bản cũ trả
            -- NHIỀU dòng cho một số, mà `s_latest.text` lại nằm trong GROUP BY -> số đó hiện
            -- lặp mấy lần trên bảng, và recordsFiltered đếm một đằng dữ liệu một nẻo.
            -- ID là khóa chính nên MAX(ID) luôn ra đúng MỘT dòng, và cũng đúng là tin mới nhất.
            SELECT s1.phone_id, s1.text
            FROM sms s1
            INNER JOIN (
                SELECT phone_id, MAX(ID) AS max_id
                FROM sms
                GROUP BY phone_id
            ) m ON m.phone_id = s1.phone_id AND m.max_id = s1.ID
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
            phones.team_id, 
            phone_carrier.name, 
            COUNT(sms.ID) AS sms_count,
            s_latest.text AS latest_sms_text
        FROM phones
        $join
        $where
        GROUP BY phones.ID, phones.status, phones.number, phones.team_id,
                 phone_carrier.name, s_latest.text
        ORDER BY " . build_order_by($params, $sortMap, 'phones.ID') . "
        LIMIT {$params['start']}, {$params['length']}";
    $rs  = $conn->query($sql);

    // Account gắn với từng số. Quan hệ NHIỀU-NHIỀU (1 số dùng cho nhiều account, 1 account
    // dùng nhiều số) nên đi qua bảng nối `accounts_phones`. Lấy một lượt cho cả trang thay
    // vì mỗi dòng một truy vấn.
    //
    // LƯU Ý: `accounts.phone` (varchar) KHÔNG phải mối liên kết này — đó là số đăng ký trên
    // sàn, nhập tay, đủ định dạng; đối chiếu với phones.number chỉ khớp 5/125. Đừng dùng nó.
    $rows = [];
    while ($row = $rs->fetch_assoc()) {
        $rows[] = $row;
    }
    $accByPhone = [];
    if ($rows && db_table_exists('accounts_phones', $conn)) {
        $ids = implode(',', array_map(fn($r) => (int)$r['ID'], $rows));
        $ar = $conn->query(
            "SELECT ap.phone_id, a.ID, a.name, a.email
             FROM accounts_phones ap
             INNER JOIN accounts a ON a.ID = ap.account_id
             WHERE ap.phone_id IN ($ids)
             ORDER BY a.name");
        while ($x = $ar->fetch_assoc()) {
            $accByPhone[(int)$x['phone_id']][] = [
                'id'    => (int)$x['ID'],
                'label' => (string)($x['name'] !== '' ? $x['name'] : $x['email']),
            ];
        }
    }

    // Chuẩn bị dữ liệu trả về
    $data = [];
    foreach ($rows as $row) {
        $data[] = [
            "id"      => $row['ID'],
            "number"  => $row['number'],
            "status"  => $row['status'],
            "carrier" => $row['name'],
            "notice"  => [
                'sms_count' => $row['sms_count'],
            ],
            'latest_sms_text' => $row['latest_sms_text'],
            // Danh sách account đang dùng số này (rỗng khi chưa có bảng nối)
            'accounts' => $accByPhone[(int)$row['ID']] ?? [],
            // Cờ quyền theo TỪNG DÒNG. Số và nhà mạng do Telnyx cấp nên KHÔNG sửa được ở
            // đây (can_edit luôn false); đổi trạng thái đi qua thao tác hàng loạt.
            'team_id'    => (int)$row['team_id'],
            // Số và nhà mạng do Telnyx cấp -> không sửa ở đây. Sửa được là trạng thái, và
            // team (chỉ admin) khi cần chuyển số sang nhóm khác.
            'can_edit'   => checkRoles('edit', 'phones_numbers'),
            'can_delete' => checkRoles('delete', 'phones_numbers'),
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

/**
 * Lọc danh sách ID số điện thoại về đúng những dòng người gọi được phép đụng.
 *
 * ID gửi lên là dữ liệu người dùng — non-admin chỉ được đụng số của team mình. Lọc ở đây
 * một lần rồi mọi thao tác hàng loạt dùng chung, thay vì mỗi chỗ tự kiểm một kiểu.
 *
 * @param int[] $ids
 * @return int[] ID hợp lệ
 */
function phonesInScope(mysqli $conn, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
    if (!$ids) {
        return [];
    }
    $in = implode(',', $ids);
    $where = "phones.ID IN ($in)";
    if (!is_admin()) {
        $where .= ' AND phones.team_id = ' . (int)($_SESSION['auth']['team'] ?? 0);
    }
    $ok = [];
    $rs = $conn->query("SELECT ID FROM phones WHERE $where");
    while ($r = $rs->fetch_row()) {
        $ok[] = (int)$r[0];
    }
    return $ok;
}

/**
 * XÓA HÀNG LOẠT số điện thoại.
 *
 * LƯU Ý QUAN TRỌNG: việc này CHỈ xóa bản ghi trong DB. Số vẫn đang thuê bên Telnyx và VẪN
 * BỊ TÍNH PHÍ — muốn thôi trả tiền thì phải release số trong bảng điều khiển Telnyx. Cố ý
 * không gọi API hủy từ đây: đó là thao tác tốn tiền/không lấy lại được, phải do người quyết.
 *
 * Thứ tự con -> cha: sms và liên kết account phải xóa TRƯỚC phones, nếu không chúng trơ lại
 * trỏ vào bản ghi không còn tồn tại (đúng lỗi đã dính khi thêm 'xóa phones khi purge team').
 */
function deletePhonesBulk(): array
{
    if (!check_csrf()) {
        return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
    }
    if (!checkRoles('delete', 'phones_numbers')) {
        return ['status' => 'error', 'message' => 'You do not have permission to delete phone numbers.'];
    }
    $conn = db();
    $ids = phonesInScope($conn, (array)($_POST['ids'] ?? []));
    if (!$ids) {
        return ['status' => 'error', 'message' => 'No phone number you can delete was selected.'];
    }
    $in = implode(',', $ids);
    try {
        $conn->query("DELETE FROM sms WHERE phone_id IN ($in)");
        $sms = $conn->affected_rows;
        $links = 0;
        if (db_table_exists('accounts_phones', $conn)) {
            $conn->query("DELETE FROM accounts_phones WHERE phone_id IN ($in)");
            $links = $conn->affected_rows;
        }
        $conn->query("DELETE FROM phones WHERE ID IN ($in)");
        $deleted = $conn->affected_rows;
    } catch (\mysqli_sql_exception $e) {
        return ['status' => 'error', 'message' => 'Delete failed: ' . $e->getMessage()];
    }
    return ['status' => 'success', 'deleted' => $deleted, 'sms' => $sms, 'links' => $links,
            'message' => 'Deleted ' . number_format($deleted) . ' number(s). They are still '
                . 'rented at Telnyx — release them there to stop being billed.'];
}

/** Trạng thái hợp lệ của một số điện thoại. */
const PHONE_STATUSES = ['active', 'suspend'];

/**
 * ĐỔI TRẠNG THÁI HÀNG LOẠT. Chỉ nhận giá trị trong whitelist — trạng thái là dữ liệu người
 * dùng gửi lên, không ghi thẳng vào cột.
 */
function updatePhonesStatusBulk(): array
{
    if (!check_csrf()) {
        return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
    }
    if (!checkRoles('edit', 'phones_numbers')) {
        return ['status' => 'error', 'message' => 'You do not have permission to change phone numbers.'];
    }
    $status = (string)($_POST['status'] ?? '');
    if (!in_array($status, PHONE_STATUSES, true)) {
        return ['status' => 'error', 'message' => 'Invalid status.'];
    }
    $conn = db();
    $ids = phonesInScope($conn, (array)($_POST['ids'] ?? []));
    if (!$ids) {
        return ['status' => 'error', 'message' => 'No phone number you can change was selected.'];
    }
    $conn->query("UPDATE phones SET status = '" . $conn->real_escape_string($status)
        . "' WHERE ID IN (" . implode(',', $ids) . ')');
    return ['status' => 'success', 'updated' => $conn->affected_rows,
            'message' => 'Updated ' . number_format(count($ids)) . ' number(s).'];
}

/**
 * Sửa MỘT số điện thoại. Chỉ đổi được những gì thuộc về mình:
 *   - `status`  : whitelist, không ghi thẳng giá trị người dùng gửi lên;
 *   - `team_id` : CHỈ admin — đây là chuyển số sang nhóm khác, non-admin không được đụng.
 * `number` và `carrier_id` do Telnyx cấp nên không nhận từ form.
 */
function savePhone(): array
{
    if (!check_csrf()) {
        return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
    }
    if (!checkRoles('edit', 'phones_numbers')) {
        return ['status' => 'error', 'message' => 'You do not have permission to edit phone numbers.'];
    }
    $conn = db();
    $id = (int)($_POST['id'] ?? 0);
    // Lọc qua đúng helper phạm vi: non-admin chỉ đụng được số của team mình
    if (!in_array($id, phonesInScope($conn, [$id]), true)) {
        return ['status' => 'error', 'message' => 'Phone number not found.'];
    }
    $status = (string)($_POST['status'] ?? '');
    if (!in_array($status, PHONE_STATUSES, true)) {
        return ['status' => 'error', 'message' => 'Invalid status.'];
    }

    $sets = ['status = ?'];
    $args = [$status];
    if (is_admin() && array_key_exists('team_id', $_POST)) {
        $teamId = (int)$_POST['team_id'];
        if ($teamId > 0 && !$conn->execute_query(
                'SELECT ID FROM team WHERE ID = ? LIMIT 1', [$teamId])->fetch_row()) {
            return ['status' => 'error', 'message' => 'Team does not exist.'];
        }
        // Chuyển số sang team khác thì liên kết tới account của team CŨ thành chéo team ->
        // gỡ, đúng luật đang áp khi chuyển user sang team khác.
        if (db_table_exists('accounts_phones', $conn)) {
            $conn->execute_query(
                'DELETE ap FROM accounts_phones ap
                 INNER JOIN accounts ac ON ac.ID = ap.account_id
                 WHERE ap.phone_id = ? AND ac.team_id <> ?', [$id, $teamId]);
        }
        $sets[] = 'team_id = ?';
        $args[] = $teamId;
    }
    $args[] = $id;
    $conn->execute_query('UPDATE phones SET ' . implode(', ', $sets) . ' WHERE ID = ?', $args);
    return ['status' => 'success', 'id' => $id, 'message' => 'Phone number updated.'];
}

/**
 * Tin nhắn của MỘT số điện thoại — dùng cho modal ở cột Notices.
 *
 * Chỉ ĐỌC, nhưng vẫn phải qua đúng hai chốt như mọi nơi khác: role `view` và phạm vi team
 * (phonesInScope). Nội dung tin nhắn là dữ liệu do người lạ gửi tới nên KHÔNG được tin —
 * phía JS bọc esc() khi render.
 *
 * @return array{status:string,messages?:array,number?:string,message?:string}
 */
function getPhoneMessages(): array
{
    // Hàm này GHI (đánh dấu đã đọc) nên phải có CSRF, không còn là endpoint chỉ đọc nữa.
    if (!check_csrf()) {
        return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
    }
    if (!checkRoles('view', 'phones_numbers')) {
        return ['status' => 'error', 'message' => 'You do not have permission to view messages.'];
    }
    $conn = db();
    $id = (int)($_POST['id'] ?? 0);
    if (!in_array($id, phonesInScope($conn, [$id]), true)) {
        return ['status' => 'error', 'message' => 'Phone number not found.'];
    }
    $number = (string)$conn->execute_query(
        'SELECT number FROM phones WHERE ID = ?', [$id])->fetch_row()[0];

    $out = [];
    $rs = $conn->execute_query(
        'SELECT ID, status, text, from_number, date FROM sms
         WHERE phone_id = ? ORDER BY date DESC, ID DESC LIMIT 100', [$id]);
    while ($r = $rs->fetch_assoc()) {
        $out[] = [
            'id'     => (int)$r['ID'],
            'status' => (string)$r['status'],
            'text'   => (string)$r['text'],
            'from'   => (string)$r['from_number'],
            'date'   => (string)$r['date'],
        ];
    }
    // KHÔNG đánh dấu đã đọc ở đây. Mở modal ra không có nghĩa là đã đọc hết — danh sách có
    // thể dài hơn màn hình. Việc đánh dấu do markSmsRead() làm, theo đúng những tin người
    // dùng CUỘN TỚI (xem app-phones-list.js).
    return ['status' => 'success', 'number' => $number, 'messages' => $out,
            'unread' => $conn->execute_query(
                "SELECT COUNT(*) FROM sms WHERE phone_id = ? AND status = 'pending'",
                [$id])->fetch_row()[0]];
}

/**
 * Đánh dấu ĐÃ ĐỌC một số tin cụ thể — những tin người dùng đã cuộn tới trong modal.
 *
 * Nhận danh sách ID tin nhắn, nhưng KHÔNG tin: chỉ cập nhật tin thuộc về số điện thoại nằm
 * trong phạm vi của người gọi (phonesInScope). Trả về số tin CÒN chưa đọc của số đó để phía
 * JS chỉnh huy hiệu cho khớp, và tắt hẳn khi về 0.
 *
 * @return array{status:string,unread?:int,marked?:int,message?:string}
 */
function markSmsRead(): array
{
    if (!check_csrf()) {
        return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
    }
    if (!checkRoles('view', 'phones_numbers')) {
        return ['status' => 'error', 'message' => 'You do not have permission to view messages.'];
    }
    $conn = db();
    $phoneId = (int)($_POST['phone_id'] ?? 0);
    if (!in_array($phoneId, phonesInScope($conn, [$phoneId]), true)) {
        return ['status' => 'error', 'message' => 'Phone number not found.'];
    }
    $ids = array_values(array_unique(array_filter(
        array_map('intval', (array)($_POST['ids'] ?? [])), fn($v) => $v > 0)));
    $marked = 0;
    if ($ids) {
        // Ràng cả phone_id vào câu UPDATE: ID tin nhắn là dữ liệu người dùng gửi lên,
        // không được phép đánh dấu tin của số khác.
        $conn->execute_query(
            "UPDATE sms SET status = 'viewed'
             WHERE ID IN (" . implode(',', $ids) . ") AND phone_id = ? AND status = 'pending'",
            [$phoneId]);
        $marked = $conn->affected_rows;
    }
    return ['status' => 'success', 'marked' => $marked,
            'unread' => (int)$conn->execute_query(
                "SELECT COUNT(*) FROM sms WHERE phone_id = ? AND status = 'pending'",
                [$phoneId])->fetch_row()[0]];
}


/**
 * Bảng SMS cho menu Phones ▸ SMS (DataTables server-side).
 *
 * Thay cho getSMS() cũ: hàm đó trả thẳng 20 dòng mới nhất, không phân trang, không tìm kiếm,
 * và fragment echo nội dung tin nhắn RA THẲNG HTML — mà nội dung đó do người lạ nhắn tới.
 *
 * Phạm vi: `sms` bám theo `phones.team_id`, nên non-admin chỉ thấy tin của team mình.
 *
 * @return array{draw:int,recordsTotal:int,recordsFiltered:int,data:array}
 */
function getSmsTable(): array
{
    // Cột sắp xếp được: khóa DataTables => biểu thức SQL. Cột hiển thị TÊN thì sort theo tên.
    $sortMap = [
        'ID'     => 'sms.ID',
        'number' => 'p.number',
        'from'   => 'sms.from_number',
        'text'   => 'sms.text',
        'status' => 'sms.status',
        'date'   => 'sms.date',
    ];
    $params = get_datatable_params(array_keys($sortMap), 'date');
    if (!checkRoles('view', 'phones_sms')) {
        return ['draw' => $params['draw'], 'recordsTotal' => 0,
                'recordsFiltered' => 0, 'data' => []];
    }
    $conn = db();

    $where = [];
    // PHẠM VI THEO TEAM — non-admin luôn bị ràng, kể cả khi team_id = 0
    if (!is_admin()) {
        $where[] = 'p.team_id = ' . (int)($_SESSION['auth']['team'] ?? 0);
    }
    // Lọc theo một số cụ thể (trang Phones link sang bằng ?id=)
    $phoneId = (int)($_POST['phone_id'] ?? 0);
    if ($phoneId > 0) {
        $where[] = 'sms.phone_id = ' . $phoneId;
    }
    // Lọc theo trạng thái đọc
    $st = (string)($_POST['sms_status'] ?? '');
    if (in_array($st, ['pending', 'viewed'], true)) {
        $where[] = "sms.status = '" . $conn->real_escape_string($st) . "'";
    }
    if ($params['searchValue'] !== '') {
        $q = $conn->real_escape_string($params['searchValue']);
        $where[] = "(sms.text LIKE '%$q%' OR sms.from_number LIKE '%$q%' OR p.number LIKE '%$q%')";
    }
    $join  = 'INNER JOIN phones p ON p.ID = sms.phone_id';
    $wSql  = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    // recordsTotal đếm theo ĐÚNG phạm vi (bỏ search/filter), không phải toàn bảng
    $wScope = is_admin() ? '' : ' WHERE p.team_id = ' . (int)($_SESSION['auth']['team'] ?? 0);

    $tong = (int)$conn->query("SELECT COUNT(*) FROM sms $join$wScope")->fetch_row()[0];
    $loc  = (int)$conn->query("SELECT COUNT(*) FROM sms $join$wSql")->fetch_row()[0];

    $rs = $conn->query(
        "SELECT sms.ID, sms.phone_id, sms.status, sms.text, sms.from_number, sms.date, p.number
         FROM sms $join$wSql
         ORDER BY " . build_order_by($params, $sortMap, 'sms.ID') . "
         LIMIT {$params['start']}, {$params['length']}");
    $data = [];
    while ($r = $rs->fetch_assoc()) {
        $data[] = [
            'id'       => (int)$r['ID'],
            'phone_id' => (int)$r['phone_id'],
            'number'   => (string)$r['number'],
            'from'     => (string)$r['from_number'],
            'text'     => (string)$r['text'],
            'status'   => (string)$r['status'],
            'date'     => (string)$r['date'],
            // Cờ quyền theo TỪNG DÒNG đúng quy ước chung
            'can_edit'   => checkRoles('edit', 'phones_sms'),
            'can_delete' => checkRoles('delete', 'phones_sms'),
        ];
    }
    return ['draw' => $params['draw'], 'recordsTotal' => $tong,
            'recordsFiltered' => $loc, 'data' => $data];
}

/**
 * Lọc danh sách ID tin nhắn về đúng những dòng người gọi được phép đụng.
 * ID gửi lên là dữ liệu người dùng — không tin, lọc một lần rồi mọi thao tác dùng chung.
 */
function smsInScope(mysqli $conn, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
    if (!$ids) {
        return [];
    }
    $w = 'sms.ID IN (' . implode(',', $ids) . ')';
    if (!is_admin()) {
        $w .= ' AND p.team_id = ' . (int)($_SESSION['auth']['team'] ?? 0);
    }
    $ok = [];
    $rs = $conn->query("SELECT sms.ID FROM sms INNER JOIN phones p ON p.ID = sms.phone_id WHERE $w");
    while ($r = $rs->fetch_row()) {
        $ok[] = (int)$r[0];
    }
    return $ok;
}

/** Đánh dấu đã đọc / chưa đọc hàng loạt cho menu SMS. */
function updateSmsStatusBulk(): array
{
    if (!check_csrf()) {
        return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
    }
    if (!checkRoles('edit', 'phones_sms')) {
        return ['status' => 'error', 'message' => 'You do not have permission to change messages.'];
    }
    $st = (string)($_POST['status'] ?? '');
    if (!in_array($st, ['pending', 'viewed'], true)) {
        return ['status' => 'error', 'message' => 'Invalid status.'];
    }
    $conn = db();
    $ids = smsInScope($conn, (array)($_POST['ids'] ?? []));
    if (!$ids) {
        return ['status' => 'error', 'message' => 'No message you can change was selected.'];
    }
    $conn->query("UPDATE sms SET status = '" . $conn->real_escape_string($st)
        . "' WHERE ID IN (" . implode(',', $ids) . ')');
    return ['status' => 'success', 'updated' => $conn->affected_rows,
            'message' => 'Updated ' . number_format(count($ids)) . ' message(s).'];
}

/** Xóa hàng loạt tin nhắn. Không bảng nào trỏ tới `sms` nên không để lại mồ côi. */
function deleteSmsBulk(): array
{
    if (!check_csrf()) {
        return ['status' => 'error', 'message' => 'Invalid CSRF token.'];
    }
    if (!checkRoles('delete', 'phones_sms')) {
        return ['status' => 'error', 'message' => 'You do not have permission to delete messages.'];
    }
    $conn = db();
    $ids = smsInScope($conn, (array)($_POST['ids'] ?? []));
    if (!$ids) {
        return ['status' => 'error', 'message' => 'No message you can delete was selected.'];
    }
    $conn->query('DELETE FROM sms WHERE ID IN (' . implode(',', $ids) . ')');
    return ['status' => 'success', 'deleted' => $conn->affected_rows,
            'message' => 'Deleted ' . number_format($conn->affected_rows) . ' message(s).'];
}

/** Danh sách số điện thoại cho ô lọc — đúng phạm vi team của người gọi. */
function getSmsPhones(): array
{
    // Trả rỗng chứ không trả lỗi: đây là nguồn dữ liệu của select2, nó chỉ hiểu
    // results/pagination. Người không có quyền xem cũng không có ô lọc để mà gọi.
    if (!checkRoles('view', 'phones_sms')) {
        return ['results' => [], 'pagination' => ['more' => false]];
    }
    $conn = db();
    $where = [];
    if (!is_admin()) {
        $where[] = 'team_id = ' . (int)($_SESSION['auth']['team'] ?? 0);
    }
    // `id` = giải nghĩa MỘT số cho ô lọc dựng sẵn từ URL (`?SmsPhone=` hoặc `?id=`),
    // không phải tìm kiếm. Vẫn đi qua đúng ràng buộc team ở trên.
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $where[] = 'ID = ' . $id;
    }
    $q = trim((string)($_POST['q'] ?? ''));
    if ($q !== '') {
        $where[] = "number LIKE '%" . $conn->real_escape_string($q) . "%'";
    }
    $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $moi  = 30;
    $page = max(1, (int)($_POST['page'] ?? 1));
    $bo   = ($page - 1) * $moi;
    // Lấy DƯ 1 dòng để biết còn trang sau hay không — rẻ hơn chạy thêm một COUNT(*)
    $rs = $conn->query("SELECT ID, number FROM phones$w ORDER BY number LIMIT $bo, " . ($moi + 1));
    $rows = [];
    while ($r = $rs->fetch_assoc()) {
        $rows[] = ['id' => (int)$r['ID'], 'text' => (string)$r['number']];
    }
    return ['results' => array_slice($rows, 0, $moi),
            'pagination' => ['more' => count($rows) > $moi]];
}
