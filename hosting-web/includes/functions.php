<?php
// includes/functions.php 
require_once 'config.php';

function get_all_slots() {
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT
                ps.id,
                ps.status AS predefined_status,
                ps.rfid_assigned,
                v.license_plate AS vehicle_license,
                v.status AS vehicle_status,
                b.license_plate AS booking_license,
                b.start_time AS booking_start,
                b.end_time AS booking_end,
                b.status AS booking_status,
                CASE 
                    WHEN v.id IS NOT NULL AND v.status = 'in_parking' THEN 'occupied'
                    WHEN b.id IS NOT NULL AND b.status = 'confirmed' AND NOW() BETWEEN b.start_time AND b.end_time THEN 'reserved'
                    WHEN ps.status = 'maintenance' THEN 'maintenance'
                    ELSE 'empty'
                END AS actual_status
            FROM parking_slots ps
            LEFT JOIN vehicles v ON ps.id = v.slot_id AND v.status = 'in_parking'
            LEFT JOIN bookings b ON ps.id = b.slot_id AND b.status = 'confirmed' AND NOW() BETWEEN b.start_time AND b.end_time
            ORDER BY ps.id
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Get slots display error: " . $e->getMessage());
        return array();
    }
}

// Lấy slots trống
function get_available_slots() {
    global $pdo;
    
    try {
        // Truy vấn lấy tất cả các slot và xác định trạng thái thực tế của chúng.
        // Sau đó, chỉ chọn những slot có trạng thái 'empty' để hiển thị.
        $stmt = $pdo->prepare("
            SELECT * FROM (
                SELECT 
                    ps.id,
                    ps.status AS predefined_status,
                    v.id AS vehicle_id,
                    b.id AS booking_id,
                    CASE 
                        WHEN v.id IS NOT NULL AND v.status = 'in_parking' THEN 'occupied'
                        WHEN b.id IS NOT NULL AND b.status = 'confirmed' AND NOW() BETWEEN b.start_time AND b.end_time THEN 'reserved'
                        WHEN ps.status = 'maintenance' THEN 'maintenance'
                        ELSE 'empty'
                    END AS actual_status
                FROM parking_slots ps
                LEFT JOIN vehicles v ON ps.id = v.slot_id AND v.status = 'in_parking'
                LEFT JOIN bookings b ON ps.id = b.slot_id AND b.status = 'confirmed' AND NOW() BETWEEN b.start_time AND b.end_time
            ) AS subquery
            WHERE actual_status = 'empty'
            ORDER BY id
        ");
        $stmt->execute();
        
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Get available slots error: " . $e->getMessage());
        return array();
    }
}

// Lấy slot theo ID
function get_slot($slot_id) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM parking_slots WHERE id = ?");
        $stmt->execute(array($slot_id));
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Get slot error: " . $e->getMessage());
        return false;
    }
}

// Cập nhật trạng thái slot
function update_slot_status($slot_id, $status, $rfid = null) {
    global $pdo;
    
    try {
        if ($rfid) {
            $stmt = $pdo->prepare("UPDATE parking_slots SET status = ?, rfid_assigned = ? WHERE id = ?");
            $stmt->execute(array($status, $rfid, $slot_id));
        } else {
            $stmt = $pdo->prepare("UPDATE parking_slots SET status = ?, rfid_assigned = NULL WHERE id = ?");
            $stmt->execute(array($status, $slot_id));
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("Update slot error: " . $e->getMessage());
        return false;
    }
}

// Tạo booking mới với proper locking
function create_booking($user_id, $slot_id, $license_plate, $start_time, $end_time) {
    global $pdo;
    
    try {
        // Validation input
        if (empty($user_id) || empty($slot_id) || empty($license_plate) || empty($start_time) || empty($end_time)) {
            return array('success' => false, 'message' => 'Thiếu thông tin cần thiết!');
        }
        
        // Validate thời gian
        $start_datetime = DateTime::createFromFormat('Y-m-d H:i:s', $start_time);
        $end_datetime = DateTime::createFromFormat('Y-m-d H:i:s', $end_time);
        
        if (!$start_datetime || !$end_datetime) {
            return array('success' => false, 'message' => 'Định dạng thời gian không hợp lệ!');
        }
        
        if ($end_datetime <= $start_datetime) {
            return array('success' => false, 'message' => 'Thời gian kết thúc phải sau thời gian bắt đầu!');
        }
        
        $pdo->beginTransaction();
        
        // Lấy trạng thái thực tế của slot với FOR UPDATE để khóa dòng
        $stmt = $pdo->prepare("
            SELECT 
                ps.id,
                ps.status AS predefined_status,
                CASE 
                    WHEN v.id IS NOT NULL AND v.status = 'in_parking' THEN 'occupied'
                    WHEN b.id IS NOT NULL AND b.status = 'confirmed' AND NOW() BETWEEN b.start_time AND b.end_time THEN 'reserved'
                    WHEN ps.status = 'maintenance' THEN 'maintenance'
                    ELSE 'empty'
                END AS actual_status
            FROM parking_slots ps
            LEFT JOIN vehicles v ON ps.id = v.slot_id AND v.status = 'in_parking'
            LEFT JOIN bookings b ON ps.id = b.slot_id AND b.status = 'confirmed' AND NOW() BETWEEN b.start_time AND b.end_time
            WHERE ps.id = ? FOR UPDATE
        ");
        $stmt->execute(array($slot_id));
        $slot = $stmt->fetch();

        if (!$slot) {
            $pdo->rollback();
            return array('success' => false, 'message' => 'Vị trí đỗ xe không tồn tại!');
        }

        // Kiểm tra trạng thái thực tế
        if ($slot['actual_status'] !== 'empty') {
            $pdo->rollback();
            return array('success' => false, 'message' => 'Vị trí đỗ xe đã được sử dụng!');
        }
        
        // Kiểm tra slot có bị book trùng thời gian
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings 
                              WHERE slot_id = ? 
                              AND status IN ('pending', 'confirmed') 
                              AND (
                                  (start_time <= ? AND end_time >= ?) OR
                                  (start_time <= ? AND end_time >= ?) OR
                                  (start_time >= ? AND end_time <= ?)
                              )");
        $stmt->execute(array($slot_id, $start_time, $start_time, $end_time, $end_time, $start_time, $end_time));
        
        if ($stmt->fetchColumn() > 0) {
            $pdo->rollback();
            return array('success' => false, 'message' => 'Slot đã được đặt trong khoảng thời gian này!');
        }
        
        // Tạo booking
        $stmt = $pdo->prepare("INSERT INTO bookings (user_id, slot_id, license_plate, start_time, end_time, status, created_at) 
                              VALUES (?, ?, ?, ?, ?, 'pending', NOW())");
        $stmt->execute(array($user_id, $slot_id, $license_plate, $start_time, $end_time));
        
        $booking_id = $pdo->lastInsertId();
        
        // Tính phí
        $diff = $end_datetime->diff($start_datetime);
        $hours = $diff->h + ($diff->days * 24);
        
        // Làm tròn lên giờ
        if ($diff->i > 0) {
            $hours += 1;
        }
        
        // Tối thiểu 1 giờ
        if ($hours < 1) {
            $hours = 1;
        }
        
        $amount = $hours * HOURLY_RATE;
        
        // Tạo payment
        $payment_ref = 'BOOK-' . time() . '-' . $booking_id;
        $stmt = $pdo->prepare("INSERT INTO payments (user_id, booking_id, amount, payment_ref, status, created_at) 
                              VALUES (?, ?, ?, ?, 'pending', NOW())");
        $stmt->execute(array($user_id, $booking_id, $amount, $payment_ref));
        
        $payment_id = $pdo->lastInsertId();
        
        $pdo->commit();
        
        return array(
            'success' => true, 
            'booking_id' => $booking_id,
            'payment_id' => $payment_id,
            'payment_ref' => $payment_ref,
            'amount' => $amount
        );
        
    } catch (Exception $e) {
        $pdo->rollback();
        error_log("Create booking error: " . $e->getMessage());
        return array('success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage());
    }
}

// Lấy danh sách booking của user
function get_user_bookings($user_id) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT b.*, p.status as payment_status, p.amount, p.payment_ref 
                              FROM bookings b 
                              LEFT JOIN payments p ON b.id = p.booking_id 
                              WHERE b.user_id = ? 
                              ORDER BY b.created_at DESC");
        $stmt->execute(array($user_id));
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Get bookings error: " . $e->getMessage());
        return array();
    }
}

// Lấy booking theo ID
function get_booking($booking_id) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT b.*, u.username, u.email, u.full_name, 
                              p.status as payment_status, p.amount, p.payment_ref 
                              FROM bookings b 
                              JOIN users u ON b.user_id = u.id 
                              LEFT JOIN payments p ON b.id = p.booking_id 
                              WHERE b.id = ?");
        $stmt->execute(array($booking_id));
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Get booking error: " . $e->getMessage());
        return false;
    }
}

// Hủy booking
function cancel_booking($booking_id, $user_id) {
    global $pdo;
    
    try {
        // Kiểm tra booking thuộc về user
        $stmt = $pdo->prepare("SELECT status FROM bookings WHERE id = ? AND user_id = ?");
        $stmt->execute(array($booking_id, $user_id));
        $booking = $stmt->fetch();
        
        if (!$booking) {
            return array('success' => false, 'message' => 'Booking không tồn tại hoặc không thuộc về bạn!');
        }
        
        if ($booking['status'] === 'completed') {
            return array('success' => false, 'message' => 'Không thể hủy booking đã hoàn thành!');
        }
        
        // Cập nhật booking status
        $stmt = $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?");
        $stmt->execute(array($booking_id));
        
        return array('success' => true, 'message' => 'Hủy booking thành công!');
        
    } catch (PDOException $e) {
        error_log("Cancel booking error: " . $e->getMessage());
        return array('success' => false, 'message' => 'Lỗi hệ thống. Vui lòng thử lại sau!');
    }
}
// Tạo QR thanh toán - Đơn giản hóa
function generate_payment_qr($payment_id) {
    global $pdo;

    try {
        // Lấy thông tin payment
        $stmt = $pdo->prepare("SELECT p.*, b.slot_id, b.license_plate, u.full_name, u.email
                              FROM payments p 
                              LEFT JOIN bookings b ON p.booking_id = b.id
                              LEFT JOIN users u ON p.user_id = u.id
                              WHERE p.id = ? AND p.status = 'pending'");
        $stmt->execute(array($payment_id));
        
        $payment = $stmt->fetch();
        
        if (!$payment) {
            return array('success' => false, 'message' => 'Thanh toán không tồn tại hoặc đã hoàn thành!');
        }
        
        $reference = $payment['payment_ref'];
        $amount = intval($payment['amount']);
        
        // Tạo QR URL với SePay- theo config
        $qr_url = sprintf(
            "%s?acc=%s&bank=%s&amount=%d&des=%s&template=%s",
            SEPAY_QR_API,
            VIETQR_ACCOUNT_NO,
            VIETQR_BANK_ID,
            $amount,
            urlencode("XParking " . $reference),
            VIETQR_TEMPLATE
        );
        
        // Cập nhật QR URL vào database
        $stmt = $pdo->prepare("UPDATE payments SET qr_code = ? WHERE id = ?");
        $stmt->execute(array($qr_url, $payment_id));

        return array(
            'success' => true,
            'qr_code' => $qr_url,
            'reference' => $reference,
            'amount' => $payment['amount'],
            'bank_info' => array(
                'bank' => VIETQR_BANK_ID,
                'account' => VIETQR_ACCOUNT_NO,
                'name' => VIETQR_ACCOUNT_NAME
            )
        );
        
    } catch (Exception $e) {
        error_log("Generate QR error: " . $e->getMessage());
        return array('success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage());
    }
}

// Kiểm tra status payment
function check_payment_status($payment_ref) {
    global $pdo;
    
    try {
        if (empty($payment_ref)) {
            return 'unknown';
        }
        
        $stmt = $pdo->prepare("SELECT status, created_at FROM payments WHERE payment_ref = ?");
        $stmt->execute(array($payment_ref));
        $payment = $stmt->fetch();
        
        if (!$payment) {
            return 'not_found';
        }
        
        // Nếu đã completed/failed/expired thì return
        if (in_array($payment['status'], array('completed', 'failed', 'expired', 'cancelled'))) {
            return $payment['status'];
        }
        
        // Kiểm tra hết hạn (10 phút) - SỬ DỤNG UTC TIME
        $created_time = new DateTime($payment['created_at']);
        $current_time = new DateTime();
        
        $interval = $current_time->diff($created_time);
        $minutes_passed = $interval->days * 24 * 60 + $interval->h * 60 + $interval->i;
        
        if ($minutes_passed >= QR_EXPIRE_MINUTES) {
            // Cập nhật thành expired
            $stmt = $pdo->prepare("UPDATE payments SET status = 'expired' WHERE payment_ref = ? AND status = 'pending'");
            $stmt->execute(array($payment_ref));
            return 'expired';
        }
        
        return $payment['status'];
        
    } catch (Exception $e) {
        error_log("Check payment status error: " . $e->getMessage());
        return 'error';
    }
}


// Verify payment qua SePay API 
function verify_payment_via_api($payment_ref, $amount) {
    try {
        // Gọi SePay API theo code mẫu
        $curl = curl_init();
        
        curl_setopt_array($curl, array(
            CURLOPT_URL => SEPAY_API_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30, // Theo code mẫu
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . SEPAY_TOKEN,
                'Content-Type: application/json'
            ),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ));
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        
        curl_close($curl);
        
        // Xử lý error theo code mẫu
        if ($error) {
            error_log("SePay CURL Error: " . $error);
            return array('success' => false, 'error' => 'CURL Error: ' . $error);
        }
        
        if ($httpCode !== 200) {
            error_log("SePay HTTP Error: " . $httpCode);
            return array('success' => false, 'error' => 'HTTP Error: ' . $httpCode);
        }
        
        $data = json_decode($response, true);
        
        if (!$data || $data['status'] !== 200 || !isset($data['transactions'])) {
            error_log("SePay invalid response");
            return array('success' => false, 'error' => 'Invalid API response');
        }
        
        // Logic matching đơn giản hóa
        $expected_amount = intval($amount);
        
        // Thời gian check (15 phút trước)
        $check_time = new DateTime();
        $check_time->modify('-15 minutes');
        
        foreach ($data['transactions'] as $transaction) {
            $transaction_time = new DateTime($transaction['transaction_date']);
            $amount_match = intval($transaction['amount_in']) === $expected_amount;
            
            // Pattern matching đơn giản
            $content_patterns = array(
                $payment_ref,                           // BOOK-1756482887-26
                str_replace(array('BOOK-', 'EXIT-'), '', $payment_ref), // 1756482887-26
                'XParking ' . $payment_ref,             // XParking BOOK-1756482887-26
            );
            
            $content_match = false;
            foreach ($content_patterns as $pattern) {
                if (stripos($transaction['transaction_content'], $pattern) !== false) {
                    $content_match = true;
                    break;
                }
            }
            
            // Chỉ check giao dịch gần đây
            $time_match = $transaction_time >= $check_time;
            
            if ($amount_match && $content_match && $time_match) {
                error_log("Payment found: $payment_ref - Transaction: " . $transaction['id']);
                return array(
                    'success' => true,
                    'transaction_id' => $transaction['id']
                );
            }
        }
        
        return array('success' => false);
        
    } catch (Exception $e) {
        error_log("Verify payment API error: " . $e->getMessage());
        return array('success' => false, 'error' => $e->getMessage());
    }
}

function process_payment_completion($payment_ref, $transaction_id) {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        // Update payment
        $stmt = $pdo->prepare("UPDATE payments SET 
                              status = 'completed', 
                              payment_time = NOW(), 
                              sepay_ref = ? 
                              WHERE payment_ref = ? AND status = 'pending'");
        $stmt->execute(array($transaction_id, $payment_ref));
        
        if ($stmt->rowCount() == 0) {
            $pdo->rollback();
            return false;
        }
        
        $stmt->execute(array($payment_ref));
        $payment = $stmt->fetch();
        
        if (!$payment) {
            $pdo->rollback();
            return false;
        }
        
        // Update booking if exists
        if ($payment['booking_id']) {
            $stmt = $pdo->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = ?");
            $stmt->execute(array($payment['booking_id']));
        }
        
        // Handle vehicle exit payment
        if ($payment['vehicle_id']) {
            $stmt = $pdo->prepare("UPDATE vehicles SET status = 'exited' WHERE id = ?");
            $stmt->execute(array($payment['vehicle_id']));
            
            // Release resources
            $stmt = $pdo->prepare("SELECT slot_id, rfid_tag FROM vehicles WHERE id = ?");
            $stmt->execute(array($payment['vehicle_id']));
            $vehicle = $stmt->fetch();
            
            if ($vehicle) {
                $stmt = $pdo->prepare("UPDATE parking_slots SET status = 'empty' WHERE id = ?");
                $stmt->execute(array($vehicle['slot_id']));
                
                $stmt = $pdo->prepare("UPDATE rfid_pool SET status = 'available' WHERE uid = ?");
                $stmt->execute(array($vehicle['rfid_tag']));
            }
        }
        
        $pdo->commit();
        return true;
        
    } catch (Exception $e) {
        $pdo->rollback();
        error_log("Process payment completion error: " . $e->getMessage());
        return false;
    }
}

// Hủy payment
function cancel_payment($payment_ref, $user_id = null) {
    global $pdo;
    
    try {
        // Kiểm tra payment có tồn tại và thuộc về user không
        $where_clause = "payment_ref = ?";
        $params = array($payment_ref);
        
        if ($user_id) {
            $where_clause .= " AND user_id = ?";  
            $params[] = $user_id;
        }
        
        // Kiểm tra payment trước khi hủy
        $stmt = $pdo->prepare("SELECT id, status, created_at FROM payments WHERE $where_clause");
        $stmt->execute($params);
        $payment = $stmt->fetch();
        
        if (!$payment) {
            return array('success' => false, 'message' => 'Không tìm thấy thanh toán này hoặc không thuộc về bạn!');
        }
        
        // Kiểm tra status có thể hủy được không
        if ($payment['status'] === 'completed') {
            return array('success' => false, 'message' => 'Không thể hủy thanh toán đã hoàn thành!');
        }
        
        if ($payment['status'] === 'cancelled') {
            return array('success' => true, 'message' => 'Thanh toán đã được hủy trước đó!');
        }
        
        // Kiểm tra có hết hạn chưa (10 phút)
        $created_time = new DateTime($payment['created_at']);
        $current_time = new DateTime();
        $interval = $current_time->diff($created_time);
        $minutes_passed = $interval->days * 24 * 60 + $interval->h * 60 + $interval->i;
        
        if ($minutes_passed >= QR_EXPIRE_MINUTES) {
            // Cập nhật thành expired nhưng vẫn return success = true 
            // vì user đã thực hiện hành động thành công
            $stmt = $pdo->prepare("UPDATE payments SET status = 'expired' WHERE id = ?");
            $stmt->execute(array($payment['id']));
            
            // Hủy booking liên quan nếu có
            $stmt = $pdo->prepare("SELECT booking_id FROM payments WHERE id = ?");
            $stmt->execute(array($payment['id']));
            $booking_id = $stmt->fetchColumn();
            
            if ($booking_id) {
                $stmt = $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?");
                $stmt->execute(array($booking_id));
            }
            
            return array('success' => true, 'message' => 'Thanh toán đã hết hạn và được hủy thành công!');
        }
        
        // Thực hiện hủy thanh toán bình thường
        $stmt = $pdo->prepare("UPDATE payments SET status = 'cancelled' WHERE id = ?");
        $stmt->execute(array($payment['id']));
        
        if ($stmt->rowCount() > 0) {
            // Nếu có booking liên quan, hủy booking luôn
            $stmt = $pdo->prepare("SELECT booking_id FROM payments WHERE id = ?");
            $stmt->execute(array($payment['id']));
            $booking_id = $stmt->fetchColumn();
            
            if ($booking_id) {
                $stmt = $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?");
                $stmt->execute(array($booking_id));
            }
            
            return array('success' => true, 'message' => 'Đã hủy thanh toán thành công!');
        } else {
            return array('success' => false, 'message' => 'Không thể hủy thanh toán này!');
        }
        
    } catch (PDOException $e) {
        error_log("Cancel payment error: " . $e->getMessage());
        return array('success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage());
    }
}
