<?php
// api/update_booking.php
require_once '../includes/config.php';
date_default_timezone_set('Asia/Ho_Chi_Minh');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$booking_id = $_POST['booking_id'] ?? '';
$status = $_POST['status'] ?? '';
$slot_id = $_POST['slot_id'] ?? '';

if (empty($booking_id) || empty($status)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

try {
    $pdo->exec("SET time_zone = '+07:00'");
    
    $sql = "UPDATE bookings SET status = ?, updated_at = NOW()";
    $params = [$status];
    
    if (!empty($slot_id)) {
        $sql .= ", slot_id = ?";
        $params[] = $slot_id;
    }
    
    $sql .= " WHERE id = ?";
    $params[] = $booking_id;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode([
            'success' => true,
            'booking_id' => $booking_id,
            'status' => $status
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Booking not found'
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>