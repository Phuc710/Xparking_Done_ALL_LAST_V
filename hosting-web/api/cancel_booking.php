<?php
// api/cancel_booking.php - Cancel booking CONFIRMED (nút Hủy trong history)
// Dùng khi booking đã thanh toán, muốn hủy (KHÔNG HOÀN TIỀN)
header('Content-Type: application/json');
require_once '../includes/config.php';

$data = json_decode(file_get_contents('php://input'), true);
$booking_id = $data['booking_id'] ?? $_GET['booking_id'] ?? '';

if (empty($booking_id)) {
    echo json_encode(['success' => false, 'message' => 'Thiếu booking_id']);
    exit;
}

try {
    // Get booking
    $bookings = $supabase->select('bookings', '*', [
        'id' => "eq.{$booking_id}"
    ], '', 1);
    
    if (!$bookings) {
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy booking']);
        exit;
    }
    
    $booking = $bookings[0];
    
    // Chỉ cho phép cancel nếu status = confirmed (đã thanh toán)
    if ($booking['status'] !== 'confirmed') {
        echo json_encode(['success' => false, 'message' => 'Chỉ có thể hủy booking đã xác nhận']);
        exit;
    }
    
    // Cancel booking
    $supabase->update('bookings', [
        'status' => 'cancelled',
        'updated_at' => getVNTime()
    ], ['id' => "eq.{$booking_id}"]);
    
    // Release slot về empty (nếu có gán slot)
    if ($booking['slot_id']) {
        // Check nếu slot đang reserved cho booking này
        $slot = $supabase->select('parking_slots', '*', [
            'id' => "eq.{$booking['slot_id']}"
        ], '', 1);
        
        if ($slot && $slot[0]['status'] === 'reserved') {
            $supabase->update('parking_slots', [
                'status' => 'empty',
                'rfid_assigned' => 'empty',
                'vehicle_id' => null,
                'updated_at' => getVNTime()
            ], ['id' => "eq.{$booking['slot_id']}"]);
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Đã hủy booking (không hoàn tiền)'
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>