<?php
/**
 * Trang Doanh thu - Admin
 * Thống kê và báo cáo doanh thu
 */

// Lấy tham số filter
$dateFrom = $_GET['date_from'] ?? date('Y-m-01'); // Đầu tháng
$dateTo = $_GET['date_to'] ?? date('Y-m-d'); // Hôm nay

// Lấy doanh thu theo ngày
$dailyRevenue = $supabase->request(
    'payments',
    'GET',
    null,
    [
        'select' => 'created_at,amount',
        'status' => 'eq.completed',
        'created_at' => 'gte.' . $dateFrom,
        'created_at' => 'lte.' . $dateTo . ' 23:59:59',
        'order' => 'created_at.asc'
    ]
);

// Xử lý data cho biểu đồ
$revenueByDay = [];
$totalRevenue = 0;

if ($dailyRevenue) {
    foreach ($dailyRevenue as $payment) {
        $day = date('Y-m-d', strtotime($payment['created_at']));
        if (!isset($revenueByDay[$day])) {
            $revenueByDay[$day] = 0;
        }
        $revenueByDay[$day] += $payment['amount'];
        $totalRevenue += $payment['amount'];
    }
}

// Thống kê theo loại thanh toán
$paymentStats = $supabase->request(
    'payments', 
    'GET',
    null,
    [
        'select' => 'payment_method,count,amount.sum',
        'status' => 'eq.completed',
        'created_at' => 'gte.' . $dateFrom,
        'created_at' => 'lte.' . $dateTo . ' 23:59:59'
    ]
);
?>

<div class="container-fluid p-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold">
            <i class="fas fa-chart-line me-2"></i>
            Báo cáo doanh thu
        </h2>
        <button class="btn btn-success" onclick="exportRevenue()">
            <i class="fas fa-download me-2"></i>
            Xuất Excel
        </button>
    </div>

    <!-- Filter -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="page" value="doanhthu">
                
                <div class="col-md-3">
                    <label class="form-label">Từ ngày</label>
                    <input type="date" class="form-control" name="date_from" 
                           value="<?= htmlspecialchars($dateFrom) ?>">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Đến ngày</label>
                    <input type="date" class="form-control" name="date_to" 
                           value="<?= htmlspecialchars($dateTo) ?>">
                </div>
                
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter me-2"></i>
                        Lọc dữ liệu
                    </button>
                    <a href="?page=doanhthu" class="btn btn-secondary ms-2">
                        <i class="fas fa-undo"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted mb-2">Tổng doanh thu</h6>
                    <h3 class="fw-bold text-success mb-0">
                        <?= number_format($totalRevenue, 0, ',', '.') ?>đ
                    </h3>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted mb-2">Số giao dịch</h6>
                    <h3 class="fw-bold text-primary mb-0">
                        <?= count($dailyRevenue ?? []) ?>
                    </h3>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted mb-2">Trung bình/ngày</h6>
                    <h3 class="fw-bold text-info mb-0">
                        <?php 
                        $days = max(1, count($revenueByDay));
                        echo number_format($totalRevenue / $days, 0, ',', '.'); 
                        ?>đ
                    </h3>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted mb-2">Cao nhất</h6>
                    <h3 class="fw-bold text-warning mb-0">
                        <?= number_format(max($revenueByDay ?: [0]), 0, ',', '.') ?>đ
                    </h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts -->
    <div class="row g-4 mb-4">
        <!-- Biểu đồ doanh thu theo ngày -->
        <div class="col-md-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-area me-2"></i>
                        Doanh thu theo ngày
                    </h5>
                </div>
                <div class="card-body">
                    <canvas id="revenueChart" height="100"></canvas>
                </div>
            </div>
        </div>

        <!-- Thống kê phương thức -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0">
                    <h5 class="mb-0">
                        <i class="fas fa-credit-card me-2"></i>
                        Phương thức thanh toán
                    </h5>
                </div>
                <div class="card-body">
                    <canvas id="paymentMethodChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Chi tiết giao dịch -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-0">
            <h5 class="mb-0">
                <i class="fas fa-list me-2"></i>
                Chi tiết giao dịch
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Mã GD</th>
                            <th>Thời gian</th>
                            <th>Biển số</th>
                            <th>Số tiền</th>
                            <th>Phương thức</th>
                            <th>Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Lấy chi tiết giao dịch
                        $transactions = $supabase->select(
                            'payments',
                            '*, vehicles(license_plate)',
                            [
                                'created_at' => 'gte.' . $dateFrom,
                                'created_at' => 'lte.' . $dateTo . ' 23:59:59'
                            ],
                            'created_at.desc',
                            20
                        );
                        
                        if ($transactions):
                            foreach ($transactions as $trans):
                        ?>
                        <tr>
                            <td>
                                <code><?= substr($trans['id'], 0, 8) ?>...</code>
                            </td>
                            <td>
                                <small><?= date('H:i d/m/Y', strtotime($trans['created_at'])) ?></small>
                            </td>
                            <td>
                                <span class="badge bg-primary">
                                    <?= htmlspecialchars($trans['vehicles']['license_plate'] ?? 'N/A') ?>
                                </span>
                            </td>
                            <td class="fw-bold text-success">
                                <?= number_format($trans['amount'], 0, ',', '.') ?>đ
                            </td>
                            <td>
                                <span class="badge bg-info">
                                    <?= $trans['payment_method'] ?? 'QR' ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-<?= $trans['status'] == 'completed' ? 'success' : 'warning' ?>">
                                    <?= ucfirst($trans['status']) ?>
                                </span>
                            </td>
                        </tr>
                        <?php 
                            endforeach;
                        else:
                        ?>
                        <tr>
                            <td colspan="6" class="text-center py-3 text-muted">
                                Không có giao dịch nào
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// Biểu đồ doanh thu theo ngày
const revenueData = <?= json_encode($revenueByDay) ?>;
const labels = Object.keys(revenueData);
const data = Object.values(revenueData);

new Chart(document.getElementById('revenueChart'), {
    type: 'line',
    data: {
        labels: labels,
        datasets: [{
            label: 'Doanh thu',
            data: data,
            borderColor: 'rgb(75, 192, 192)',
            backgroundColor: 'rgba(75, 192, 192, 0.1)',
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return new Intl.NumberFormat('vi-VN').format(context.parsed.y) + 'đ';
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return new Intl.NumberFormat('vi-VN').format(value);
                    }
                }
            }
        }
    }
});

// Export Excel
function exportRevenue() {
    window.location.href = `api/export_revenue.php?from=${encodeURIComponent('<?= $dateFrom ?>')}&to=${encodeURIComponent('<?= $dateTo ?>')}`;
}
</script>
