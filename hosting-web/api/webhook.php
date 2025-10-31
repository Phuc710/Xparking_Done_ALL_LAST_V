<?php
// api/webhook.php - Payment webhook & check status
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../includes/config.php';

$method = $_SERVER['REQUEST_METHOD'];

// GET: Check payment status
if ($method === 'GET') {
    $payment_ref = $_GET['payment_ref'] ?? '';
    $payment_id = $_GET['id'] ?? '';
    
    if (empty($payment_ref) && empty($payment_id)) {
        echo json_encode(['success' => false, 'message' => 'Thiếu payment_ref hoặc id']);
        exit;
    }
    
    try {
        // Get payment from Supabase
        $filters = [];
        if ($payment_ref) {
            $filters['payment_ref'] = "eq.{$payment_ref}";
        } else {
            $filters['id'] = "eq.{$payment_id}";
        }
        
        $payments = $supabase->select('payments', '*', $filters, '', 1);
        
        if (!$payments) {
            echo json_encode(['success' => false, 'message' => 'Không tìm thấy payment']);
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
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// POST: Receive webhook from SePay
if ($method === 'POST') {
    $webhookData = file_get_contents('php://input');
    $data = json_decode($webhookData, true);
    
    error_log("Webhook received: " . $webhookData);
    
    try {
        if (!$data || !isset($data['content'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid webhook data']);
            exit;
        }
        
        $content = $data['content'];
        $amount = $data['transferAmount'] ?? 0;
        
        // Extract payment_ref from content
        // Format: "XPARK{payment_ref} ..."
        preg_match('/XPARK(\d+)/', $content, $matches);
        
        if (!$matches) {
            echo json_encode(['success' => false, 'message' => 'Invalid payment reference']);
            exit;
        }
        
        $payment_ref = $matches[1];
        
        // Find payment in Supabase
        $payments = $supabase->select('payments', '*', [
            'payment_ref' => "eq.{$payment_ref}",
            'status' => 'eq.pending'
        ], '', 1);
        
        if (!$payments) {
            echo json_encode(['success' => false, 'message' => 'Payment not found or already processed']);
            exit;
        }
        
        $payment = $payments[0];
        
        // Verify amount
        if ($amount < $payment['amount']) {
            echo json_encode(['success' => false, 'message' => 'Số tiền không đúng']);
            exit;
        }
        
        // Update payment status to completed
        $result = $supabase->update('payments', [
            'status' => 'completed',
            'payment_time' => getVNTime(),
            'payment_method' => 'bank_transfer',
            'updated_at' => getVNTime()
        ], ['id' => "eq.{$payment['id']}"]);
        
        if ($result) {
            // Update booking if exists
            if ($payment['booking_id']) {
                $supabase->update('bookings', [
                    'status' => 'confirmed',
                    'updated_at' => getVNTime()
                ], ['id' => "eq.{$payment['booking_id']}"]);
                
                // GỬI BILL QUA EMAIL (async)
                $billData = json_encode(['payment_id' => $payment['id']]);
                $ch = curl_init('http://' . $_SERVER['HTTP_HOST'] . '/api/bill.php');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $billData);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_TIMEOUT, 1); // Non-blocking
                curl_exec($ch);
                curl_close($ch);
            }
            
            // Log success
            $supabase->insert('system_logs', [
                'action' => 'payment_completed',
                'details' => "Payment {$payment_ref} completed - Amount: {$amount}",
                'level' => 'info',
                'created_at' => getVNTime()
            ]);
            
            echo json_encode([
                'success' => true, 
                'message' => 'Thanh toán thành công',
                'payment_id' => $payment['id']
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Lỗi cập nhật payment']);
        }
        
    } catch (Exception $e) {
        error_log("Webhook error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Invalid method
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
?>