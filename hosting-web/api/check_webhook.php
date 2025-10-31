<?php
// api/check_webhook.php - Check payment status + auto expire
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../includes/config.php';

try {
    $payment_ref = $_GET['payment_ref'] ?? '';
    $payment_id = $_GET['id'] ?? '';
    
    if (empty($payment_ref) && empty($payment_id)) {
        echo json_encode([
            'success' => false,
            'message' => 'Thiếu payment_ref hoặc id'
        ]);
        exit;
    }
    
    // Get payment
    $filters = [];
    if ($payment_ref) {
        $filters['payment_ref'] = "eq.{$payment_ref}";
    } else {
        $filters['id'] = "eq.{$payment_id}";
    }
    
    $payments = $supabase->select('payments', '*', $filters, '', 1);
    
    if (!$payments || empty($payments)) {
        echo json_encode([
            'success' => false,
            'message' => 'Không tìm thấy payment'
        ]);
        exit;
    }
    
    $payment = $payments[0];
    
    // Check expiration
    $now = new DateTime();
    $expiresAt = new DateTime($payment['expires_at']);
    $secondsRemaining = max(0, $expiresAt->getTimestamp() - $now->getTimestamp());
    
    // Auto expire nếu hết hạn
    if ($secondsRemaining <= 0 && $payment['status'] === 'pending') {
        $supabase->update('payments', 
            ['status' => 'expired', 'updated_at' => getVNTime()],
            ['id' => "eq.{$payment['id']}"]
        );
        $payment['status'] = 'expired';
    }
    
    echo json_encode([
        'success' => true,
        'payment' => $payment,
        'seconds_remaining' => $secondsRemaining,
        'expired' => $secondsRemaining <= 0
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Lỗi server: ' . $e->getMessage()
    ]);
}
?>