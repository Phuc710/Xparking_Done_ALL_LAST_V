<?php
// api/checkin.php - Vehicle check-in to parking
header('Content-Type: application/json');
require_once '../includes/config.php';

$data = json_decode(file_get_contents('php://input'), true);

$license_plate = $data['license_plate'] ?? '';
$slot_id = $data['slot_id'] ?? '';
$rfid = $data['rfid'] ?? '';

if (empty($license_plate) || empty($slot_id) || empty($rfid)) {
    echo json_encode(['success' => false, 'message' => 'Thiếu thông tin']);
    exit;
}

try {
    $vehicleData = [
        'license_plate' => $license_plate,
        'slot_id' => $slot_id,
        'rfid_tag' => $rfid,
        'entry_time' => getVNTime(),
        'status' => 'in_parking'
    ];
    
    $result = $supabase->insert('vehicles', $vehicleData);
    
    if ($result) {
        // Update slot
        $supabase->update('parking_slots', 
            ['status' => 'occupied', 'rfid_assigned' => $rfid],
            ['id' => "eq.{$slot_id}"]
        );
        
        echo json_encode(['success' => true, 'vehicle_id' => $result[0]['id']]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Lỗi check-in']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>