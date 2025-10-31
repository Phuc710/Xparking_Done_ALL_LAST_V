<?php
// api/rollback_rfid.php - Thu hồi RFID khi timeout slot monitoring
require_once '../includes/config.php';
date_default_timezone_set('Asia/Ho_Chi_Minh');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$rfid = $_POST['rfid'] ?? '';

if (empty($rfid)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing RFID']);
    exit;
}

try {
    $pdo->exec("SET time_zone = '+07:00'");
    $pdo->beginTransaction();
    
    // Đưa RFID về trạng thái available
    $stmt = $pdo->prepare("
        UPDATE rfid_pool 
        SET status = 'available', assigned_at = NULL 
        WHERE uid = ? AND status = 'assigned'
    ");
    $stmt->execute([$rfid]);
    
    if ($stmt->rowCount() > 0) {
        // Log rollback event
        $stmt = $pdo->prepare("
            INSERT INTO system_logs (event_type, description, created_at) 
            VALUES ('rfid_rollback', ?, NOW())
        ");
        $description = "Thu hoi RFID {$rfid} do timeout slot monitoring";
        $stmt->execute([$description]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'rfid' => $rfid,
            'message' => 'RFID rollback successful'
        ]);
    } else {
        $pdo->rollback();
        echo json_encode([
            'success' => false,
            'error' => 'RFID not found or not assigned'
        ]);
    }
    
} catch (Exception $e) {
    $pdo->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>