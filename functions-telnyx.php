<?php
function hookTelnyx(): array
{
    $conn = db(); // PDO connection

    // Lấy raw JSON từ webhook
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['status' => 'error', 'message' => 'Invalid JSON'];
    }

    // Lấy thông tin cần lưu
    $fromNumber = $data['data']['payload']['from']['phone_number'] ?? null;
    $carrierName = $data['data']['payload']['to'][0]['carrier'] ?? null;
    $toNumber    = $data['data']['payload']['to'][0]['phone_number'] ?? null;
    $text        = $data['data']['payload']['text'] ?? null;
    $date        = $data['data']['payload']['received_at'] ?? null;

    if (!$fromNumber || !$toNumber || !$text) {
        return ['status' => 'error', 'message' => 'Missing required fields'];
    }

    // Nếu không có date thì dùng thời gian hiện tại
    if (!$date) {
        $date = date('Y-m-d H:i:s');
    }

    // --- Lấy carrier_id từ bảng phone_carrier ---
    $carrierId = null;
    if ($carrierName) {
        $stmt = $conn->prepare("SELECT id FROM phone_carrier WHERE name = :name LIMIT 1");
        $stmt->execute([':name' => $carrierName]);
        $carrier = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($carrier) {
            $carrierId = $carrier['id'];
        } else {
            // Thêm mới carrier nếu chưa có
            $insertCarrier = $conn->prepare("INSERT INTO phone_carrier (name) VALUES (:name)");
            $insertCarrier->execute([':name' => $carrierName]);
            $carrierId = $conn->lastInsertId();
        }
    }

    // --- Kiểm tra $toNumber trong bảng phones ---
    $stmt = $conn->prepare("SELECT id FROM phones WHERE number = :number LIMIT 1");
    $stmt->execute([':number' => $toNumber]);
    $phone = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($phone) {
        $phoneId = $phone['id'];
    } else {
        // Thêm mới nếu chưa có
        $insertPhone = $conn->prepare("
            INSERT INTO phones (carrier_id, status, number)
            VALUES (:carrier_id, 'active', :number)
        ");
        $insertPhone->execute([
            ':carrier_id' => $carrierId,
            ':number'     => $toNumber
        ]);
        $phoneId = $conn->lastInsertId();
    }

    // --- Lưu vào bảng sms ---
    $stmt = $conn->prepare("
        INSERT INTO sms (phone_id, from_number, text, date)
        VALUES (:phone_id, :from_number, :text, :date)
    ");

    $stmt->bindParam(':phone_id', $phoneId, PDO::PARAM_INT);
    $stmt->bindParam(':from_number', $fromNumber, PDO::PARAM_STR);
    $stmt->bindParam(':text', $text, PDO::PARAM_STR);
    $stmt->bindParam(':date', $date, PDO::PARAM_STR);

    if ($stmt->execute()) {
        return ['status' => 'success', 'message' => 'SMS saved'];
    } else {
        return ['status' => 'error', 'message' => 'Database insert failed'];
    }
}