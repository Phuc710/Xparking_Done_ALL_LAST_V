<?php
// api/get_revenue_ranking.php
require_once '../includes/config.php';
header('Content-Type: application/json');

try {
    $stmt = $pdo->prepare("SELECT u.full_name, COALESCE(SUM(p.amount), 0) as total_spent
                           FROM payments p
                           JOIN users u ON p.user_id = u.id
                           WHERE p.status = 'completed'
                           GROUP BY p.user_id
                           ORDER BY total_spent DESC
                           LIMIT 5");
    $stmt->execute();
    $user_ranking = $stmt->fetchAll();

    $labels = [];
    $values = [];
    foreach ($user_ranking as $row) {
        $labels[] = htmlspecialchars($row['full_name']);
        $values[] = $row['total_spent'];
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