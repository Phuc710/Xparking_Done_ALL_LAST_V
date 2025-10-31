<?php
// Realtime webhook cho XParking với Supabase integration
require_once '../includes/config.php';
date_default_timezone_set('Asia/Ho_Chi_Minh');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Enhanced logging cho debugging
function log_realtime($message, $data = null) {
    $log_entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => $message,
        'data' => $data,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'endpoint' => $_SERVER['REQUEST_URI'] ?? 'unknown'
    ];
    
    error_log("REALTIME_WEBHOOK: " . json_encode($log_entry, JSON_UNESCAPED_UNICODE));
}

// Hàm tạo Snowflake ID tối ưu
function generate_enhanced_snowflake_id() {
    // Timestamp (milliseconds since epoch)
    $timestamp = intval(microtime(true) * 1000);
    
    // Machine ID (hash của server info)
    $machine_info = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $machine_id = abs(crc32($machine_info)) & 0x3FF; // 10 bits
    
    // Sequence (microseconds)
    $sequence = intval(microtime(true) * 1000000) & 0xFFF; // 12 bits
    
    // Combine: 41 bits timestamp + 10 bits machine + 12 bits sequence
    $snowflake = ($timestamp << 22) | ($machine_id << 12) | $sequence;
    
    return strval($snowflake);
}

// Tạo payment với Snowflake ID và 10 phút expiry
function create_payment_with_expiry($amount, $description, $user_id = null, $booking_id = null, $vehicle_id = null) {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        // Generate Snowflake ID
        $payment_id = generate_enhanced_snowflake_id();
        $payment_ref = 'XPARK' . substr($payment_id, -8);
        
        // 10 phút expiry từ bây giờ
        $expires_at = date('Y-m-d H:i:s', time() + 600); // 600 seconds = 10 minutes
        
        // Insert payment
        $stmt = $pdo->prepare("
            INSERT INTO payments (
                id, user_id, booking_id, vehicle_id, amount, description, 
                payment_ref, status, expires_at, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())
        ");
        
        $stmt->execute([
            $payment_id, $user_id, $booking_id, $vehicle_id,
            $amount, $description, $payment_ref, $expires_at
        ]);
        
        // Generate QR code
        $qr_url = generate_optimized_qr_url($amount, $payment_ref);
        
        // Update với QR URL
        $stmt = $pdo->prepare("UPDATE payments SET qr_code = ? WHERE id = ?");
        $stmt->execute([$qr_url, $payment_id]);
        
        $pdo->commit();
        
        log_realtime("Payment created với Snowflake ID", [
            'payment_id' => $payment_id,
            'payment_ref' => $payment_ref,
            'amount' => $amount,
            'expires_at' => $expires_at
        ]);
        
        // Notify realtime clients
        notify_realtime_update('payment_created', [
            'payment_id' => $payment_id,
            'payment_ref' => $payment_ref,
            'amount' => $amount,
            'status' => 'pending',
            'expires_at' => $expires_at,
            'expires_in_seconds' => 600
        ]);
        
        return [
            'success' => true,
            'payment_id' => $payment_id,
            'payment_ref' => $payment_ref,
            'qr_code' => $qr_url,
            'amount' => $amount,
            'expires_at' => $expires_at,
            'expires_in_seconds' => 600
        ];
        
    } catch (Exception $e) {
        $pdo->rollback();
        log_realtime("Lỗi tạo payment", ['error' => $e->getMessage()]);
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Generate QR URL tối ưu
function generate_optimized_qr_url($amount, $payment_ref) {
    $params = [
        'acc' => '09696969690',
        'bank' => 'MBBank',
        'amount' => $amount,
        'des' => urlencode("XParking {$payment_ref}"),
        'template' => 'compact'
    ];
    
    return 'https://qr.sepay.vn/img?' . http_build_query($params);
}

// Kiểm tra và cập nhật payment status
function check_and_update_payment($payment_ref) {
    global $pdo;
    
    try {
        // Get payment info với lock
        $stmt = $pdo->prepare("
            SELECT id, status, amount, expires_at, created_at,
                   TIMESTAMPDIFF(SECOND, NOW(), expires_at) as seconds_remaining
            FROM payments 
            WHERE payment_ref = ? OR id = ?
            FOR UPDATE
        ");
        $stmt->execute([$payment_ref, $payment_ref]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$payment) {
            return ['success' => false, 'error' => 'Payment không tồn tại'];
        }
        
        // Auto-expire nếu hết thời gian
        if ($payment['seconds_remaining'] <= 0 && $payment['status'] === 'pending') {
            $stmt = $pdo->prepare("UPDATE payments SET status = 'expired' WHERE id = ?");
            $stmt->execute([$payment['id']]);
            
            log_realtime("Payment tự động expire", [
                'payment_id' => $payment['id'],
                'payment_ref' => $payment_ref
            ]);
            
            // Notify realtime
            notify_realtime_update('payment_expired', [
                'payment_id' => $payment['id'],
                'payment_ref' => $payment_ref,
                'status' => 'expired'
            ]);
            
            return [
                'success' => true,
                'status' => 'expired',
                'message' => 'Payment đã hết hạn',
                'expired_at' => $payment['expires_at']
            ];
        }
        
        // Kiểm tra với SePay nếu vẫn pending
        if ($payment['status'] === 'pending' && $payment['seconds_remaining'] > 0) {
            $sepay_result = verify_payment_with_sepay_api($payment_ref, $payment['amount']);
            
            if ($sepay_result['found']) {
                // Cập nhật thành completed
                $stmt = $pdo->prepare("
                    UPDATE payments 
                    SET status = 'completed', payment_time = NOW(), sepay_ref = ?
                    WHERE id = ?
                ");
                $stmt->execute([$sepay_result['transaction_id'], $payment['id']]);
                
                log_realtime("Payment completed qua SePay", [
                    'payment_id' => $payment['id'],
                    'payment_ref' => $payment_ref,
                    'transaction_id' => $sepay_result['transaction_id']
                ]);
                
                // Notify realtime
                notify_realtime_update('payment_completed', [
                    'payment_id' => $payment['id'],
                    'payment_ref' => $payment_ref,
                    'status' => 'completed',
                    'amount' => $payment['amount'],
                    'transaction_id' => $sepay_result['transaction_id']
                ]);
                
                return [
                    'success' => true,
                    'status' => 'completed',
                    'amount' => $payment['amount'],
                    'transaction_id' => $sepay_result['transaction_id'],
                    'completed_at' => date('Y-m-d H:i:s')
                ];
            }
        }
        
        // Return current status
        return [
            'success' => true,
            'status' => $payment['status'],
            'payment_ref' => $payment_ref,
            'amount' => $payment['amount'],
            'seconds_remaining' => max(0, $payment['seconds_remaining']),
            'expires_at' => $payment['expires_at']
        ];
        
    } catch (Exception $e) {
        log_realtime("Lỗi check payment", [
            'payment_ref' => $payment_ref,
            'error' => $e->getMessage()
        ]);
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Verify payment với SePay API tối ưu
function verify_payment_with_sepay_api($payment_ref, $expected_amount) {
    try {
        $curl = curl_init();
        
        curl_setopt_array($curl, [
            CURLOPT_URL => SEPAY_API_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8, // Giảm timeout để tăng tốc
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . SEPAY_TOKEN,
                'Content-Type: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        if ($http_code !== 200) {
            return ['found' => false, 'error' => "HTTP {$http_code}"];
        }
        
        $data = json_decode($response, true);
        
        if (!$data || $data['status'] !== 200 || !isset($data['transactions'])) {
            return ['found' => false, 'error' => 'Invalid API response'];
        }
        
        // Tìm transaction khớp trong 15 phút gần đây
        $cutoff_time = time() - 900; // 15 minutes ago
        
        foreach ($data['transactions'] as $transaction) {
            $transaction_time = strtotime($transaction['transaction_date']);
            
            // Skip transactions cũ
            if ($transaction_time < $cutoff_time) {
                continue;
            }
            
            // Check amount
            if (intval($transaction['amount_in']) !== intval($expected_amount)) {
                continue;
            }
            
            // Check content với multiple patterns
            $content = strtolower($transaction['transaction_content']);
            $patterns = [
                strtolower($payment_ref),
                strtolower("XParking {$payment_ref}"),
                strtolower(str_replace('XPARK', '', $payment_ref))
            ];
            
            $found_match = false;
            foreach ($patterns as $pattern) {
                if (strpos($content, $pattern) !== false) {
                    $found_match = true;
                    break;
                }
            }
            
            if ($found_match) {
                return [
                    'found' => true,
                    'transaction_id' => $transaction['id'],
                    'amount' => $transaction['amount_in'],
                    'content' => $transaction['transaction_content'],
                    'date' => $transaction['transaction_date']
                ];
            }
        }
        
        return ['found' => false];
        
    } catch (Exception $e) {
        log_realtime("SePay API error", ['error' => $e->getMessage()]);
        return ['found' => false, 'error' => $e->getMessage()];
    }
}

// Notify realtime updates tới WebSocket clients
function notify_realtime_update($event_type, $data) {
    try {
        // Gửi tới WebSocket server
        $websocket_url = 'http://localhost:8080/api/broadcast/' . $event_type;
        
        $ch = curl_init($websocket_url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 1, // Quick timeout để không block
            CURLOPT_CONNECTTIMEOUT => 1
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200) {
            log_realtime("Realtime notification sent", ['event' => $event_type]);
        }
        
    } catch (Exception $e) {
        // Không fail main operation nếu notification lỗi
        log_realtime("Realtime notification failed", [
            'event' => $event_type,
            'error' => $e->getMessage()
        ]);
    }
}

// Batch cleanup expired payments
function cleanup_expired_payments_batch() {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        // Tìm payments expired
        $stmt = $pdo->prepare("
            SELECT id, payment_ref FROM payments 
            WHERE status = 'pending' AND expires_at < NOW()
            LIMIT 50
        ");
        $stmt->execute();
        $expired_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($expired_payments)) {
            // Batch update
            $payment_ids = array_column($expired_payments, 'id');
            $placeholders = str_repeat('?,', count($payment_ids) - 1) . '?';
            
            $stmt = $pdo->prepare("
                UPDATE payments 
                SET status = 'expired' 
                WHERE id IN ($placeholders)
            ");
            $stmt->execute($payment_ids);
            
            $pdo->commit();
            
            // Notify realtime cho từng payment
            foreach ($expired_payments as $payment) {
                notify_realtime_update('payment_expired', [
                    'payment_id' => $payment['id'],
                    'payment_ref' => $payment['payment_ref'],
                    'status' => 'expired'
                ]);
            }
            
            log_realtime("Batch expired payments", ['count' => count($expired_payments)]);
        }
        
        return count($expired_payments);
        
    } catch (Exception $e) {
        $pdo->rollback();
        log_realtime("Lỗi batch cleanup", ['error' => $e->getMessage()]);
        return 0;
    }
}

// Main webhook handler
try {
    $pdo->exec("SET time_zone = '+07:00'");
    
    // Auto cleanup expired payments
    $expired_count = cleanup_expired_payments_batch();
    
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create_payment':
            $amount = intval($_POST['amount'] ?? 0);
            $description = $_POST['description'] ?? 'XParking Payment';
            $user_id = intval($_POST['user_id'] ?? 0) ?: null;
            $booking_id = intval($_POST['booking_id'] ?? 0) ?: null;
            $vehicle_id = intval($_POST['vehicle_id'] ?? 0) ?: null;
            
            if ($amount <= 0) {
                throw new Exception('Số tiền không hợp lệ');
            }
            
            $result = create_payment_with_expiry($amount, $description, $user_id, $booking_id, $vehicle_id);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;
            
        case 'check_payment':
            $payment_ref = $_GET['payment_ref'] ?? $_POST['payment_ref'] ?? '';
            
            if (empty($payment_ref)) {
                throw new Exception('Thiếu payment_ref');
            }
            
            $result = check_and_update_payment($payment_ref);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;
            
        case 'expire_payment':
            $payment_ref = $_POST['payment_ref'] ?? '';
            
            if (empty($payment_ref)) {
                throw new Exception('Thiếu payment_ref');
            }
            
            $stmt = $pdo->prepare("UPDATE payments SET status = 'expired' WHERE payment_ref = ? AND status = 'pending'");
            $stmt->execute([$payment_ref]);
            
            if ($stmt->rowCount() > 0) {
                notify_realtime_update('payment_expired', [
                    'payment_ref' => $payment_ref,
                    'status' => 'expired'
                ]);
                
                echo json_encode(['success' => true, 'message' => 'Payment đã được expire']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Payment không tìm thấy hoặc đã xử lý']);
            }
            break;
            
        case 'cleanup':
            $expired_count = cleanup_expired_payments_batch();
            echo json_encode([
                'success' => true,
                'expired_count' => $expired_count,
                'message' => "Đã cleanup {$expired_count} payments"
            ]);
            break;
            
        case 'stats':
            // Thống kê realtime
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_payments,
                    COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_payments,
                    COUNT(CASE WHEN status = 'expired' THEN 1 END) as expired_payments,
                    SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_revenue_today
                FROM payments 
                WHERE DATE(created_at) = CURDATE()
            ");
            $stmt->execute();
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'stats' => $stats,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        default:
            throw new Exception('Action không hợp lệ');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    log_realtime("Webhook error", ['error' => $e->getMessage()]);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
}
?>
