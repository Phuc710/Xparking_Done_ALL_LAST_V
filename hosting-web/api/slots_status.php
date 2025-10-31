<?php
// api/slots_status.php - Get all parking slots status
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../includes/config.php';

try {
    $slots = $supabase->select('parking_slots', '*', [], 'id.asc');
    
    if ($slots) {
        echo json_encode([
            'success' => true,
            'data' => $slots
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Không thể lấy dữ liệu slots'
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Lỗi server: ' . $e->getMessage()
    ]);
}
?>