<?php
// api/get_vehicle.php - Get vehicle info by RFID
header('Content-Type: application/json');
require_once '../includes/config.php';

$rfid = $_GET['rfid'] ?? '';

if (empty($rfid)) {
    echo json_encode(['success' => false, 'message' => 'Thiếu RFID']);
    exit;
}

try {
    $vehicles = $supabase->select('vehicles', '*', [
        'rfid_tag' => "eq.{$rfid}",
        'status' => 'eq.in_parking'
    ], '', 1);
    
    if ($vehicles) {
        echo json_encode([
            'success' => true,
            'vehicle' => $vehicles[0]
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Không tìm thấy xe'
        ]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>