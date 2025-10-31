<?php
// api/get_revenue_distribution.php
require_once '../includes/config.php';
header('Content-Type: application/json');

try {
    $stmt = $pdo->prepare("SELECT HOUR(payment_time) as hour, COALESCE(SUM(amount), 0) as revenue
                           FROM payments
                           WHERE status = 'completed' AND DATE(payment_time) = CURDATE()
                           GROUP BY hour ORDER BY hour ASC");
    $stmt->execute();
    $daily_revenue = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $labels = [];
    $values = [];
    for ($i = 0; $i < 24; $i++) {
        $labels[] = sprintf('%02d:00', $i);
        $values[] = $daily_revenue[$i] ?? 0;
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