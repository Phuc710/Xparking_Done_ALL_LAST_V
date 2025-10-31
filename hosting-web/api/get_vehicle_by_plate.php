<?php
require_once '../includes/config.php';
date_default_timezone_set('Asia/Ho_Chi_Minh');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$license_plate = $_POST['license_plate'] ?? '';

if (empty($license_plate)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing license_plate']);
    exit;
}

try {
    $pdo->exec("SET time_zone = '+07:00'");
    
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
        echo json_encode([
            'success' => true,
            'vehicle' => [
                'id' => $vehicle['id'],
                'license_plate' => $vehicle['license_plate'],
                'entry_time' => $vehicle['entry_time'],
                'slot_id' => $vehicle['slot_id'],
                'rfid_tag' => $vehicle['rfid_tag'],
                'user_id' => $vehicle['user_id']
            ]
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Vehicle not found or not in parking'
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>