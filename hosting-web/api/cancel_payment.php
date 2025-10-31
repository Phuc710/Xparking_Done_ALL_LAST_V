<?php
// api/cancel_payment.php - Cancel payment (nút Hủy trong QR modal)
header('Content-Type: application/json');
require_once '../includes/config.php';

$data = json_decode(file_get_contents('php://input'), true);
$payment_id = $data['payment_id'] ?? '';

if (empty($payment_id)) {
    echo json_encode(['success' => false, 'message' => 'Thiếu payment_id']);
    exit;
}

try {
    // Get payment
    $payments = $supabase->select('payments', '*, bookings(*)', [
        'id' => "eq.{$payment_id}"
    ], '', 1);
    
    if (!$payments) {
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy payment']);
        exit;
    }
    
    $payment = $payments[0];
    
    // Chỉ cho phép cancel nếu status = pending
    if ($payment['status'] !== 'pending') {
        echo json_encode(['success' => false, 'message' => 'Không thể hủy payment này']);
        exit;
    }
    
    // Expire payment ngay lập tức
    $supabase->update('payments', [
        'status' => 'expired',
        'updated_at' => getVNTime()
    ], ['id' => "eq.{$payment_id}"]);
    
    // Cancel booking liên quan
    if ($payment['booking_id']) {
        $supabase->update('bookings', [
            'status' => 'cancelled',
            'updated_at' => getVNTime()
        ], ['id' => "eq.{$payment['booking_id']}"]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Đã hủy thanh toán'
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
