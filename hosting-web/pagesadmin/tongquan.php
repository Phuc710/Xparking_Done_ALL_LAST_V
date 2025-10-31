<?php
// pagesadmin/tongquan.php - Tổng quan hệ thống

// Lấy dữ liệu từ Supabase
$stats = [];

// Tổng số xe trong bãi
$vehiclesInParking = $supabase->select('vehicles', 'count', ['status' => 'eq.in_parking']);
$stats['vehicles_in_parking'] = $vehiclesInParking ? $vehiclesInParking[0]['count'] : 0;

// Tổng slot
$totalSlots = $supabase->select('parking_slots', 'count');
$stats['total_slots'] = $totalSlots ? $totalSlots[0]['count'] : 4;

// Slot trống
$emptySlots = $supabase->select('parking_slots', 'count', ['status' => 'eq.empty']);
$stats['empty_slots'] = $emptySlots ? $emptySlots[0]['count'] : 0;

// Doanh thu hôm nay
$today = date('Y-m-d');
$todayRevenue = $supabase->rpc('get_daily_revenue', ['date' => $today]);
$stats['today_revenue'] = $todayRevenue ? $todayRevenue[0]['total'] : 0;

// Booking đang chờ
$pendingBookings = $supabase->select('bookings', 'count', ['status' => 'eq.pending']);
$stats['pending_bookings'] = $pendingBookings ? $pendingBookings[0]['count'] : 0;

// Lấy 10 hoạt động gần nhất
$recentActivities = $supabase->select(
    'system_logs',
    '*',
    [],
    'created_at.desc',
    10
);
?>

<div class="container-fluid p-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold">
            <i class="fas fa-chart-line me-2"></i>
            Tổng quan hệ thống
        </h2>
        <div class="text-muted">
            <i class="fas fa-clock me-1"></i>
            Cập nhật: <?= date('H:i:s d/m/Y') ?>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-4 mb-4">
        <!-- Xe trong bãi -->
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-2">Xe trong bãi</h6>
                            <h2 class="fw-bold mb-0 text-primary">
                                <?= $stats['vehicles_in_parking'] ?>
                            </h2>
                        </div>
                        <div class="bg-primary bg-opacity-10 p-3 rounded-circle">
                            <i class="fas fa-car text-primary fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Slot trống -->
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-2">Slot trống</h6>
                            <h2 class="fw-bold mb-0 text-success">
                                <?= $stats['empty_slots'] ?>/<?= $stats['total_slots'] ?>
                            </h2>
                        </div>
                        <div class="bg-success bg-opacity-10 p-3 rounded-circle">
                            <i class="fas fa-parking text-success fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Doanh thu hôm nay -->
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-2">Doanh thu hôm nay</h6>
                            <h2 class="fw-bold mb-0 text-warning">
                                <?= number_format($stats['today_revenue'], 0, ',', '.') ?>đ
                            </h2>
                        </div>
                        <div class="bg-warning bg-opacity-10 p-3 rounded-circle">
                            <i class="fas fa-dollar-sign text-warning fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Booking chờ -->
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-2">Booking chờ</h6>
                            <h2 class="fw-bold mb-0 text-info">
                                <?= $stats['pending_bookings'] ?>
                            </h2>
                        </div>
                        <div class="bg-info bg-opacity-10 p-3 rounded-circle">
                            <i class="fas fa-clock text-info fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row g-4 mb-4">
        <!-- Biểu đồ slot realtime -->
        <div class="col-md-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-area me-2"></i>
                        Trạng thái slot (Realtime)
                    </h5>
                </div>
                <div class="card-body">
                    <canvas id="slotChart" height="100"></canvas>
                </div>
            </div>
        </div>

        <!-- Thống kê nhanh -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        Thống kê nhanh
                    </h5>
                </div>
                <div class="card-body">
                    <div class="mb-3 pb-3 border-bottom">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted">Tỷ lệ sử dụng</span>
                            <span class="fw-bold">
                                <?= round(($stats['vehicles_in_parking'] / max($stats['total_slots'], 1)) * 100) ?>%
                            </span>
                        </div>
                        <div class="progress mt-2" style="height: 8px;">
                            <div class="progress-bar bg-primary" 
                                 style="width: <?= ($stats['vehicles_in_parking'] / max($stats['total_slots'], 1)) * 100 ?>%">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3 pb-3 border-bottom">
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">Xe vào hôm nay</span>
                            <span class="fw-bold text-success">+12</span>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">Xe ra hôm nay</span>
                            <span class="fw-bold text-danger">-8</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Hoạt động gần nhất -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-0 py-3">
            <h5 class="mb-0">
                <i class="fas fa-history me-2"></i>
                Hoạt động gần nhất
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Thời gian</th>
                            <th>Loại</th>
                            <th>Mô tả</th>
                            <th>Người thực hiện</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recentActivities): ?>
                            <?php foreach ($recentActivities as $activity): ?>
                            <tr>
                                <td>
                                    <small><?= date('H:i:s d/m', strtotime($activity['created_at'])) ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $activity['level'] == 'error' ? 'danger' : 'info' ?>">
                                        <?= htmlspecialchars($activity['action']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($activity['details']) ?></td>
                                <td><?= htmlspecialchars($activity['user_id'] ?? 'System') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="text-center py-3 text-muted">
                                    Không có hoạt động nào
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
// Realtime update với WebSocket
const ws = new WebSocket('ws://localhost:8765');

ws.onmessage = function(event) {
    const data = JSON.parse(event.data);
    
    if (data.type === 'slot_update') {
        updateSlotChart(data);
    }
    
    if (data.type === 'vehicle_entry' || data.type === 'vehicle_exit') {
        location.reload(); // Reload để cập nhật stats
    }
};

// Chart.js cho biểu đồ slot
const ctx = document.getElementById('slotChart').getContext('2d');
const slotChart = new Chart(ctx, {
    type: 'doughnut',
    data: {
        labels: ['Trống', 'Đã đặt', 'Đang sử dụng'],
        datasets: [{
            data: [
                <?= $stats['empty_slots'] ?>,
                <?= $stats['pending_bookings'] ?>,
                <?= $stats['vehicles_in_parking'] ?>
            ],
            backgroundColor: [
                'rgba(40, 167, 69, 0.8)',
                'rgba(255, 193, 7, 0.8)',
                'rgba(220, 53, 69, 0.8)'
            ]
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});
</script>
