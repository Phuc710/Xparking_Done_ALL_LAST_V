<?php
// api/create_booking.php - Create booking (NO slot assignment, just check available)
header('Content-Type: application/json');
require_once '../includes/config.php';

session_start();

$data = json_decode(file_get_contents('php://input'), true);

$user_id = $_SESSION['user_id'] ?? null;
$license_plate = strtoupper(trim($data['license_plate'] ?? '')); // TỰ ĐỘNG IN HOA
$duration = intval($data['duration'] ?? 1);
$notes = $data['notes'] ?? '';

if (!$user_id || empty($license_plate)) {
    echo json_encode(['success' => false, 'message' => 'Thiếu thông tin']);
    exit;
}

try {
    // Tính thời gian
    $start_time = date('Y-m-d H:i:s');
    $end_time = date('Y-m-d H:i:s', strtotime("+{$duration} hours"));
    
    // Tính phí
    $amount = 0;
    switch($duration) {
        case 1: $amount = 20000; break;
        case 2: $amount = 35000; break;
        case 3: $amount = 50000; break;
        case 4: $amount = 60000; break;
        case 8: $amount = 100000; break;
        case 24: $amount = 200000; break;
        default: $amount = $duration * 15000;
    }
    
    // CHECK CÒN SLOT TRỐNG KHÔNG (không gán cụ thể)
    $emptySlots = $supabase->select('parking_slots', 'count', ['status' => 'eq.empty']);
    $emptyCount = $emptySlots ? $emptySlots[0]['count'] : 0;
    
    if ($emptyCount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Bãi xe đã đầy, không thể booking']);
        exit;
    }
    
    // Tạo booking KHÔNG GÁN SLOT (slot_id = null)
    // Xe vào slot nào do Python/ESP32 quyết định
    $bookingData = [
        'user_id' => $user_id,
        'slot_id' => null,  // KHÔNG GÁN SLOT
        'license_plate' => $license_plate,
        'start_time' => $start_time,
        'end_time' => $end_time,
        'status' => 'pending',
        'notes' => $notes,
        'created_at' => getVNTime()
    ];
    
    $bookingResult = $supabase->insert('bookings', $bookingData);
    
    if (!$bookingResult) {
        echo json_encode(['success' => false, 'message' => 'Lỗi tạo booking']);
        exit;
    }
    
    $booking_id = $bookingResult[0]['id'];
    
    // Tạo payment với Snowflake ID
    $payment_ref = substr(generateSnowflakeId(), -6);
    $payment_id = generateSnowflakeId();
    $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes')); // 10 PHÚT
    
    $paymentData = [
        'id' => $payment_id,
        'user_id' => $user_id,
        'booking_id' => $booking_id,
        'amount' => $amount,
        'payment_ref' => $payment_ref,
        'status' => 'pending',
        'expires_at' => $expires_at,
        'created_at' => getVNTime()
    ];
    
    $paymentResult = $supabase->insert('payments', $paymentData);
    
    if (!$paymentResult) {
        echo json_encode(['success' => false, 'message' => 'Lỗi tạo payment']);
        exit;
    }
    
    // Tạo QR URL
    $qrContent = "XPARK{$payment_ref}";
    $qrUrl = SEPAY_QR_API . '?' . http_build_query([
        'bank' => VIETQR_BANK_ID,
        'acc' => VIETQR_ACCOUNT_NO,
        'name' => VIETQR_ACCOUNT_NAME,
        'amount' => $amount,
        'des' => $qrContent,
        'template' => VIETQR_TEMPLATE
    ]);
    
    echo json_encode([
        'success' => true,
        'booking_id' => $booking_id,
        'payment_id' => $payment_id,
        'payment_ref' => $payment_ref,
        'qr_url' => $qrUrl,
        'amount' => $amount,
        'expires_at' => $expires_at,
        'available_slots' => $emptyCount  // Số slot trống
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>