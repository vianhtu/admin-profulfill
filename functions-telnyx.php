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
    $stmt = $conn->prepare("INSERT INTO sms (phone_id, from_number, text, date) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $phoneId, $fromNumber, $text, $date);

    if ($stmt->execute()) {
        $stmt->close();
        return ['status' => 'success', 'message' => 'SMS saved'];
    } else {
        $stmt->close();
        return ['status' => 'error', 'message' => 'Database insert failed'];
    }
}