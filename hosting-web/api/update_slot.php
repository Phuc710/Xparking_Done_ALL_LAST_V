<?php
// api/update_slot.php - Update parking slot status
header('Content-Type: application/json');
require_once '../includes/config.php';

$data = json_decode(file_get_contents('php://input'), true);

$slot_id = $data['slot_id'] ?? '';
$status = $data['status'] ?? '';
$rfid = $data['rfid'] ?? '';

if (empty($slot_id) || empty($status)) {
    echo json_encode(['success' => false, 'message' => 'Thiếu thông tin']);
    exit;
}

try {
    $updateData = [
        'status' => $status,
        'updated_at' => getVNTime()
    ];
    
    if ($rfid) {
        $updateData['rfid_assigned'] = $rfid;
    }
    
    $result = $supabase->update('parking_slots', $updateData, ['id' => "eq.{$slot_id}"]);
    
    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Cập nhật thành công']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Lỗi cập nhật']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>