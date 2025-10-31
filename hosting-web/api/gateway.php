<?php
// api/gateway.php
require_once '../includes/config.php';
date_default_timezone_set('Asia/Ho_Chi_Minh');

// Headers để bypass CORS và security restrictions
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

// Function to get parameter from GET or POST
function getParam($key, $default = '') {
    return $_GET[$key] ?? $_POST[$key] ?? $default;
}

// Function to execute API action
function executeAPI($action, $params) {
    global $pdo;
    
    try {
        $pdo->exec("SET time_zone = '+07:00'");
        
        switch($action) {
            case 'check_booking':
                return checkBooking($params['license_plate'] ?? '');
                
            case 'get_vehicle':
                return getVehicle($params['rfid'] ?? '');
                
            case 'get_vehicle_by_plate':
                return getVehicleByPlate($params['license_plate'] ?? '');
                
            case 'get_booking':
                return getBooking($params['license_plate'] ?? '');
                
            case 'checkin':
                return checkinVehicle($params);
                
            case 'checkout': 
                return checkoutVehicle($params);
                
            case 'update_slot':
                return updateSlot($params);
                
            case 'rollback_rfid':
                return rollbackRFID($params['rfid'] ?? '');
                
            case 'update_booking':
                return updateBooking($params);
                
            default:
                return ['error' => 'Invalid action'];
        }
        
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// API Functions
function checkBooking($license_plate) {
    global $pdo;
    
    if (empty($license_plate)) {
        return ['error' => 'Missing license_plate'];
    }
    
    $stmt = $pdo->prepare("
        SELECT id, slot_id, start_time, end_time 
        FROM bookings 
        WHERE license_plate = ? 
        AND status = 'confirmed'
        AND NOW() BETWEEN start_time AND end_time
        LIMIT 1
    ");
    $stmt->execute([$license_plate]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return [
        'has_booking' => $booking ? true : false,
        'booking_id' => $booking['id'] ?? null,
        'slot_id' => $booking['slot_id'] ?? null
    ];
}

function getVehicle($rfid) {
    global $pdo;
    
    if (empty($rfid)) {
        return ['error' => 'Missing RFID'];
    }
    
    $stmt = $pdo->prepare("
        SELECT v.*, ps.id as slot_id
        FROM vehicles v 
        LEFT JOIN parking_slots ps ON v.slot_id = ps.id
        WHERE v.rfid_tag = ? AND v.status = 'in_parking'
    ");
    $stmt->execute([$rfid]);
    $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($vehicle) {
        return [
            'success' => true,
            'vehicle' => [
                'id' => $vehicle['id'],
                'license_plate' => $vehicle['license_plate'],
                'entry_time' => $vehicle['entry_time'],
                'slot_id' => $vehicle['slot_id'],
                'rfid_tag' => $vehicle['rfid_tag']
            ]
        ];
    } else {
        return [
            'success' => false,
            'error' => 'Vehicle not found'
        ];
    }
}

function getVehicleByPlate($license_plate) {
    global $pdo;
    
    if (empty($license_plate)) {
        return ['error' => 'Missing license_plate'];
    }
    
    $stmt = $pdo->prepare("
        SELECT v.*, ps.id as slot_id
        FROM vehicles v 
        LEFT JOIN parking_slots ps ON v.slot_id = ps.id
        WHERE v.license_plate = ? AND v.status = 'in_parking'
        ORDER BY v.entry_time DESC
        LIMIT 1
    ");
    $stmt->execute([$license_plate]);
    $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($vehicle) {
        return [
            'success' => true,
            'vehicle' => [
                'id' => $vehicle['id'],
                'license_plate' => $vehicle['license_plate'],
                'entry_time' => $vehicle['entry_time'],
                'slot_id' => $vehicle['slot_id'],
                'rfid_tag' => $vehicle['rfid_tag'],
                'user_id' => $vehicle['user_id'],
                'status' => $vehicle['status']
            ]
        ];
    } else {
        return [
            'success' => false,
            'error' => 'Vehicle not found or not in parking'
        ];
    }
}

function getBooking($license_plate) {
    global $pdo;
    
    if (empty($license_plate)) {
        return ['error' => 'Missing license_plate'];
    }
    
    $stmt = $pdo->prepare("
        SELECT * FROM bookings 
        WHERE license_plate = ? 
        AND status IN ('confirmed', 'in_parking')
        ORDER BY start_time DESC
        LIMIT 1
    ");
    $stmt->execute([$license_plate]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($booking) {
        return [
            'success' => true,
            'booking' => [
                'id' => $booking['id'],
                'start_time' => $booking['start_time'],
                'end_time' => $booking['end_time'],
                'status' => $booking['status']
            ]
        ];
    } else {
        return ['success' => false];
    }
}

function checkinVehicle($params) {
    global $pdo;
    
    $license_plate = $params['license_plate'] ?? '';
    $slot_id = $params['slot_id'] ?? '';
    $rfid = $params['rfid'] ?? '';
    $entry_time = $params['entry_time'] ?? date('Y-m-d H:i:s');
    $image_base64 = $params['image_path'] ?? '';
    
    if (empty($license_plate) || empty($slot_id) || empty($rfid)) {
        return ['error' => 'Missing required fields'];
    }
    
    try {
        $pdo->beginTransaction();
        
        // --- CẢI TIẾN LOGIC: KIỂM TRA LẠI TRẠNG THÁI SLOT TRƯỚC KHI CHECKIN ---
        $stmt = $pdo->prepare("SELECT status FROM parking_slots WHERE id = ? FOR UPDATE");
        $stmt->execute([$slot_id]);
        $slot_status_db = $stmt->fetchColumn();
        
        if ($slot_status_db !== 'empty' && $slot_status_db !== 'reserved') {
            $pdo->rollback();
            return ['success' => false, 'error' => 'Slot is not empty or reserved in DB. Current status: ' . $slot_status_db];
        }
        // ----------------------------------------------------------------------
        
        // Insert vehicle
        $stmt = $pdo->prepare("
            INSERT INTO vehicles (license_plate, slot_id, rfid_tag, entry_time, status, created_at) 
            VALUES (?, ?, ?, ?, 'in_parking', NOW())
        ");
        $stmt->execute([$license_plate, $slot_id, $rfid, $entry_time]);
        $vehicle_id = $pdo->lastInsertId();
        
        // Update slot status to 'occupied'
        $stmt = $pdo->prepare("UPDATE parking_slots SET status = 'occupied', rfid_assigned = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$rfid, $slot_id]);
        
        // Update RFID status (redundant with get_rfid.php but good for safety)
        $stmt = $pdo->prepare("UPDATE rfid_pool SET status = 'assigned', assigned_at = NOW() WHERE uid = ?");
        $stmt->execute([$rfid]);
        
        // Log entry
        $stmt = $pdo->prepare("INSERT INTO system_logs (event_type, description, created_at) VALUES ('vehicle_entry', ?, NOW())");
        $description = "Xe {$license_plate} vao bai luc {$entry_time} - Slot: {$slot_id} - RFID: {$rfid}";
        $stmt->execute([$description]);
        
        $pdo->commit();
        
        return [
            'success' => true,
            'vehicle_id' => $vehicle_id,
            'license_plate' => $license_plate,
            'slot_id' => $slot_id,
            'rfid' => $rfid,
            'entry_time' => $entry_time
        ];
        
    } catch (Exception $e) {
        $pdo->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function checkoutVehicle($params) {
    global $pdo;
    
    $rfid = $params['rfid'] ?? '';
    $license_plate = $params['license_plate'] ?? '';
    $exit_time = $params['exit_time'] ?? date('Y-m-d H:i:s');
    $paid = $params['paid'] ?? '0';
    
    if (empty($rfid)) {
        return ['error' => 'Missing RFID'];
    }
    
    try {
        $pdo->beginTransaction();
        
        // Update vehicle exit
        $stmt = $pdo->prepare("UPDATE vehicles SET exit_time = ?, status = 'exited' WHERE rfid_tag = ? AND status = 'in_parking'");
        $stmt->execute([$exit_time, $rfid]);
        
        if ($stmt->rowCount() == 0) {
            $pdo->rollback();
            return ['error' => 'Vehicle not found'];
        }
        
        // Get vehicle info
        $stmt = $pdo->prepare("SELECT slot_id, license_plate FROM vehicles WHERE rfid_tag = ? AND status = 'exited' ORDER BY exit_time DESC LIMIT 1");
        $stmt->execute([$rfid]);
        $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $slot_id = $vehicle['slot_id'] ?? '';
        $actual_plate = $vehicle['license_plate'] ?? $license_plate;
        
        // Update slot status
        if ($slot_id) {
            $stmt = $pdo->prepare("UPDATE parking_slots SET status = 'empty', rfid_assigned = 'empty', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$slot_id]);
        }
        
        // Update RFID status
        $stmt = $pdo->prepare("UPDATE rfid_pool SET status = 'available', assigned_at = NULL WHERE uid = ?");
        $stmt->execute([$rfid]);
        
        // Log exit
        $stmt = $pdo->prepare("INSERT INTO system_logs (event_type, description, created_at) VALUES ('vehicle_exit', ?, NOW())");
        $description = "Xe {$actual_plate} ra bai luc {$exit_time} - RFID: {$rfid} - Paid: {$paid}";
        $stmt->execute([$description]);
        
        $pdo->commit();
        
        return [
            'success' => true,
            'license_plate' => $actual_plate,
            'exit_time' => $exit_time,
            'slot_id' => $slot_id,
            'paid' => $paid
        ];
        
    } catch (Exception $e) {
        $pdo->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function updateSlot($params) {
    global $pdo;
    
    $slot_id = $params['slot_id'] ?? '';
    $status = $params['status'] ?? '';
    $timestamp = $params['timestamp'] ?? date('Y-m-d H:i:s');
    
    if (empty($slot_id) || empty($status)) {
        return ['error' => 'Missing required fields'];
    }
    
    $allowed_statuses = ['empty', 'occupied', 'reserved', 'maintenance'];
    if (!in_array($status, $allowed_statuses)) {
        return ['error' => 'Invalid status'];
    }
    
    $stmt = $pdo->prepare("UPDATE parking_slots SET status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$status, $slot_id]);
    
    if ($stmt->rowCount() > 0) {
        // Log update
        $stmt = $pdo->prepare("INSERT INTO system_logs (event_type, description, created_at) VALUES ('slot_update', ?, NOW())");
        $description = "Slot {$slot_id} cap nhat trang thai: {$status} luc {$timestamp}";
        $stmt->execute([$description]);
        
        return [
            'success' => true,
            'slot_id' => $slot_id,
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s')
        ];
    } else {
        return [
            'success' => false,
            'error' => 'Slot not found'
        ];
    }
}

function rollbackRFID($rfid) {
    global $pdo;
    
    if (empty($rfid)) {
        return ['error' => 'Missing RFID'];
    }
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            UPDATE rfid_pool 
            SET status = 'available', assigned_at = NULL 
            WHERE uid = ? AND status = 'assigned'
        ");
        $stmt->execute([$rfid]);
        
        if ($stmt->rowCount() > 0) {
            // Log rollback
            $stmt = $pdo->prepare("
                INSERT INTO system_logs (event_type, description, created_at) 
                VALUES ('rfid_rollback', ?, NOW())
            ");
            $description = "Thu hoi RFID {$rfid} do timeout slot monitoring";
            $stmt->execute([$description]);
            
            $pdo->commit();
            
            return [
                'success' => true,
                'rfid' => $rfid,
                'message' => 'RFID rollback successful'
            ];
        } else {
            $pdo->rollback();
            return [
                'success' => false,
                'error' => 'RFID not found or not assigned'
            ];
        }
        
    } catch (Exception $e) {
        $pdo->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function updateBooking($params) {
    global $pdo;
    
    $booking_id = $params['booking_id'] ?? '';
    $status = $params['status'] ?? '';
    $slot_id = $params['slot_id'] ?? '';
    
    if (empty($booking_id) || empty($status)) {
        return ['error' => 'Missing required fields'];
    }
    
    $sql = "UPDATE bookings SET status = ?, updated_at = NOW()";
    $params_array = [$status];
    
    if (!empty($slot_id)) {
        $sql .= ", slot_id = ?";
        $params_array[] = $slot_id;
    }
    
    $sql .= " WHERE id = ?";
    $params_array[] = $booking_id;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params_array);
    
    if ($stmt->rowCount() > 0) {
        return [
            'success' => true,
            'booking_id' => $booking_id,
            'status' => $status
        ];
    } else {
        return [
            'success' => false,
            'error' => 'Booking not found'
        ];
    }
}

// Main execution
$action = getParam('action');

if (empty($action)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing action parameter']);
    exit;
}

// Collect all parameters
$params = array_merge($_GET, $_POST);
unset($params['action']); // Remove action from params

// Execute API
$result = executeAPI($action, $params);

// Return result
echo json_encode($result, JSON_UNESCAPED_UNICODE);
?>