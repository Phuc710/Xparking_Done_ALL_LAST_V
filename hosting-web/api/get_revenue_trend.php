<?php
// api/get_revenue_trend.php
require_once '../includes/config.php';
header('Content-Type: application/json');

try {
    $stmt = $pdo->prepare("SELECT DATE(payment_time) as date, COALESCE(SUM(amount), 0) as revenue
                           FROM payments
                           WHERE status = 'completed' AND payment_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                           GROUP BY date ORDER BY date ASC");
    $stmt->execute();
    $daily_revenue = $stmt->fetchAll();

    $labels = [];
    $values = [];
    foreach ($daily_revenue as $row) {
        $labels[] = date('d/m', strtotime($row['date']));
        $values[] = $row['revenue'];
    }

    echo json_encode([
        'labels' => $labels,
        'values' => $values
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>