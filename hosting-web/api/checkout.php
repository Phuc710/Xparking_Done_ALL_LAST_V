<?php
// api/checkout.php - Vehicle checkout from parking
header('Content-Type: application/json');
require_once '../includes/config.php';

$data = json_decode(file_get_contents('php://input'), true);

$rfid = $data['rfid'] ?? '';
$license_plate = $data['license_plate'] ?? '';

if (empty($rfid) || empty($license_plate)) {
    echo json_encode(['success' => false, 'message' => 'Thiếu thông tin']);
    exit;
}

try {
    // Get vehicle
    $vehicles = $supabase->select('vehicles', '*', [
        'rfid_tag' => "eq.{$rfid}",
        'status' => 'eq.in_parking'
    ], '', 1);
    
    if (!$vehicles) {
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy xe']);
        exit;
    }
    
    $vehicle = $vehicles[0];
    
    // Update vehicle
    $result = $supabase->update('vehicles', 
        ['exit_time' => getVNTime(), 'status' => 'exited'],
        ['id' => "eq.{$vehicle['id']}"]
    );
    
    if ($result) {
        // Update slot
        $supabase->update('parking_slots', 
            ['status' => 'empty', 'rfid_assigned' => 'empty', 'vehicle_id' => null],
            ['id' => "eq.{$vehicle['slot_id']}"]
        );
        
        // Update RFID
        $supabase->update('rfid_pool', 
            ['status' => 'available', 'assigned_to_vehicle' => null],
            ['uid' => "eq.{$rfid}"]
        );
        
        echo json_encode(['success' => true, 'message' => 'Checkout thành công']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Lỗi checkout']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>