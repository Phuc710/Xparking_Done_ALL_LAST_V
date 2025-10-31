<?php
// api/get_booking.php 
// xe ra tinh phi theo booking
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
        SELECT * FROM bookings 
        WHERE license_plate = ? 
        AND status IN ('confirmed', 'in_parking')
        ORDER BY start_time DESC
        LIMIT 1
    ");
    $stmt->execute([$license_plate]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($booking) {
        echo json_encode([
            'success' => true,
            'booking' => [
                'id' => $booking['id'],
                'start_time' => $booking['start_time'],
                'end_time' => $booking['end_time'],
                'status' => $booking['status']
            ]
        ]);
    } else {
        echo json_encode(['success' => false]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>