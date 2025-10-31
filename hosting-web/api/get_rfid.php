<?php
// api/get_rfid.php
header('Content-Type: application/json');
require_once '../includes/config.php';

try {
    $rfids = $supabase->select('rfid_pool', '*', ['status' => 'eq.available'], '', 1);
    
    if ($rfids) {
        echo json_encode([
            'success' => true,
            'rfid' => $rfids[0]['uid']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Không còn RFID khả dụng'
        ]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>