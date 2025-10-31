<?php
// api/check_booking.php 
// check xe vào có booking hay không
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
    
    // Check for active booking
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
    
    echo json_encode([
        'has_booking' => $booking ? true : false,
        'booking_id' => $booking['id'] ?? null,
        'slot_id' => $booking['slot_id'] ?? null
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}