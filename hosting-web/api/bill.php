<?php
// api/bill.php - Send bill email after payment success
require_once '../includes/config.php';
require_once '../../PHPMailer/src/PHPMailer.php';
require_once '../../PHPMailer/src/SMTP.php';
require_once '../../PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$data = json_decode(file_get_contents('php://input'), true);
$payment_id = $data['payment_id'] ?? '';

if (empty($payment_id)) {
    echo json_encode(['success' => false, 'message' => 'Thiếu payment_id']);
    exit;
}

try {
    // Lấy thông tin payment + booking + user
    $payments = $supabase->request('payments', 'GET', null, [
        'select' => '*, users(full_name,email,username), bookings(slot_id,license_plate,start_time,end_time)',
        'id' => "eq.{$payment_id}"
    ]);
    
    if (!$payments) {
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy payment']);
        exit;
    }
    
    $payment = $payments[0];
    $user = $payment['users'];
    $booking = $payment['bookings'];
    
    // Tạo HTML bill
    $billHtml = createBillHtml($payment, $user, $booking);
    
    // Gửi cho USER
    $sendUser = sendBillEmail(
        $user['email'],
        $user['full_name'],
        'Hóa đơn đặt chỗ - XPARKING',
        $billHtml
    );
    
    // Gửi cho ADMIN
    $sendAdmin = sendBillEmail(
        'athanhphuc7102005@gmail.com',
        'Admin XPARKING',
        '[ADMIN] Booking mới #' . $booking['id'],
        $billHtml
    );
    
    echo json_encode([
        'success' => true,
        'sent_to_user' => $sendUser,
        'sent_to_admin' => $sendAdmin
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function createBillHtml($payment, $user, $booking) {
    $paymentTime = date('d/m/Y H:i', strtotime($payment['payment_time']));
    $startTime = date('d/m/Y H:i', strtotime($booking['start_time']));
    $endTime = date('d/m/Y H:i', strtotime($booking['end_time']));
    $amount = number_format($payment['amount'], 0, ',', '.');
    
    return <<<HTML
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            margin: 0;
            padding: 20px;
        }
        .bill-container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .bill-header {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .bill-header img {
            height: 50px;
            margin-bottom: 10px;
        }
        .bill-header h1 {
            margin: 0;
            font-size: 28px;
        }
        .bill-header p {
            margin: 5px 0 0 0;
            opacity: 0.9;
            font-size: 14px;
        }
        .bill-body {
            padding: 30px;
        }
        .bill-section {
            margin-bottom: 25px;
        }
        .bill-section h3 {
            color: #2563eb;
            font-size: 16px;
            margin-bottom: 15px;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 8px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        .info-label {
            color: #6b7280;
            font-weight: 500;
        }
        .info-value {
            color: #111827;
            font-weight: 600;
        }
        .total-section {
            background: #f9fafb;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            font-size: 20px;
            font-weight: 700;
            color: #2563eb;
        }
        .status-badge {
            display: inline-block;
            padding: 6px 16px;
            background: #10b981;
            color: white;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }
        .bill-footer {
            background: #f9fafb;
            padding: 20px;
            text-align: center;
            color: #6b7280;
            font-size: 13px;
        }
        .bill-footer strong {
            color: #2563eb;
        }
    </style>
</head>
<body>
    <div class="bill-container">
        <div class="bill-header">
            <h1>🅿️ XPARKING</h1>
            <p>Hóa đơn đặt chỗ đỗ xe</p>
        </div>
        
        <div class="bill-body">
            <!-- Thông tin khách hàng -->
            <div class="bill-section">
                <h3>📋 Thông tin khách hàng</h3>
                <div class="info-row">
                    <span class="info-label">Họ tên:</span>
                    <span class="info-value">{$user['full_name']}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Email:</span>
                    <span class="info-value">{$user['email']}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Username:</span>
                    <span class="info-value">{$user['username']}</span>
                </div>
            </div>
            
            <!-- Thông tin booking -->
            <div class="bill-section">
                <h3>🚗 Thông tin đặt chỗ</h3>
                <div class="info-row">
                    <span class="info-label">Mã booking:</span>
                    <span class="info-value">#{$booking['id']}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Biển số xe:</span>
                    <span class="info-value">{$booking['license_plate']}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Vị trí đỗ:</span>
                    <span class="info-value">{$booking['slot_id']}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Thời gian bắt đầu:</span>
                    <span class="info-value">{$startTime}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Thời gian kết thúc:</span>
                    <span class="info-value">{$endTime}</span>
                </div>
            </div>
            
            <!-- Thông tin thanh toán -->
            <div class="bill-section">
                <h3>💳 Thông tin thanh toán</h3>
                <div class="info-row">
                    <span class="info-label">Mã giao dịch:</span>
                    <span class="info-value">{$payment['payment_ref']}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Thời gian thanh toán:</span>
                    <span class="info-value">{$paymentTime}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Phương thức:</span>
                    <span class="info-value">Chuyển khoản QR</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Trạng thái:</span>
                    <span class="info-value"><span class="status-badge">✓ Thành công</span></span>
                </div>
            </div>
            
            <!-- Tổng tiền -->
            <div class="total-section">
                <div class="total-row">
                    <span>TỔNG TIỀN:</span>
                    <span>{$amount}đ</span>
                </div>
            </div>
        </div>
        
        <div class="bill-footer">
            <p style="margin:0 0 10px 0;">
                <strong>Bill tự động từ hệ thống bãi xe thông minh XPARKING</strong>
            </p>
            <p style="margin:0;">
                Cảm ơn quý khách đã sử dụng dịch vụ!<br>
                Hotline: 1900-xxxx | Email: support@xparking.com
            </p>
        </div>
    </div>
</body>
</html>
HTML;
}

function sendBillEmail($to, $name, $subject, $htmlContent) {
    $mail = new PHPMailer(true);
    
    try {
        // SMTP config
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'Acc13422@gmail.com';
        $mail->Password = 'onkqhepgezpafkts';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';
        
        // Người gửi
        $mail->setFrom('Acc13422@gmail.com', 'Bill Thanh Toán XPARKING');
        
        // Người nhận
        $mail->addAddress($to, $name);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlContent;
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Email error: " . $mail->ErrorInfo);
        return false;
    }
}
?>
