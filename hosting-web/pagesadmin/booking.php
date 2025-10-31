<?php
/**
 * Lịch sử Booking - Admin
 */

// Lấy bookings với thông tin user và payment
$bookings = $supabase->request('bookings', 'GET', null, [
    'select' => 'id,user_id,slot_id,license_plate,start_time,end_time,status,created_at,users(full_name,username),payments(amount,status,payment_time)',
    'order' => 'created_at.desc',
    'limit' => 100
]);
?>

<div class="container-fluid p-4">
    <h2 class="fw-bold mb-4">
        <i class="fas fa-calendar-check me-2"></i>
        Lịch sử Đặt chỗ
    </h2>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <?php
        $totalBookings = count($bookings);
        $pendingBookings = count(array_filter($bookings, fn($b) => $b['status'] === 'pending'));
        $confirmedBookings = count(array_filter($bookings, fn($b) => $b['status'] === 'confirmed'));
        $completedBookings = count(array_filter($bookings, fn($b) => $b['status'] === 'completed'));
        ?>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Tổng booking</h6>
                    <h3 class="mb-0"><?= $totalBookings ?></h3>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Chờ xác nhận</h6>
                    <h3 class="mb-0 text-warning"><?= $pendingBookings ?></h3>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Đã xác nhận</h6>
                    <h3 class="mb-0 text-success"><?= $confirmedBookings ?></h3>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Hoàn thành</h6>
                    <h3 class="mb-0 text-info"><?= $completedBookings ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Người dùng</th>
                            <th>Biển số</th>
                            <th>Slot</th>
                            <th>Thời gian</th>
                            <th>Trạng thái</th>
                            <th>Thanh toán</th>
                            <th>Số tiền</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($bookings): ?>
                            <?php foreach ($bookings as $booking): 
                                $statusClass = [
                                    'pending' => 'warning',
                                    'confirmed' => 'success',
                                    'completed' => 'info',
                                    'cancelled' => 'danger'
                                ][$booking['status']] ?? 'secondary';
                                
                                $statusText = [
                                    'pending' => 'Chờ',
                                    'confirmed' => 'Đã xác nhận',
                                    'completed' => 'Hoàn thành',
                                    'cancelled' => 'Đã hủy'
                                ][$booking['status']] ?? $booking['status'];
                                
                                $payment = $booking['payments'][0] ?? null;
                            ?>
                            <tr>
                                <td>#<?= $booking['id'] ?></td>
                                <td><?= htmlspecialchars($booking['users']['full_name'] ?? 'N/A') ?></td>
                                <td><span class="badge bg-primary"><?= htmlspecialchars($booking['license_plate']) ?></span></td>
                                <td><?= htmlspecialchars($booking['slot_id']) ?></td>
                                <td>
                                    <small>
                                        <?= date('d/m H:i', strtotime($booking['start_time'])) ?><br>
                                        → <?= date('d/m H:i', strtotime($booking['end_time'])) ?>
                                    </small>
                                </td>
                                <td><span class="badge bg-<?= $statusClass ?>"><?= $statusText ?></span></td>
                                <td>
                                    <?php if ($payment): ?>
                                        <span class="badge bg-<?= $payment['status'] === 'completed' ? 'success' : 'warning' ?>">
                                            <?= $payment['status'] === 'completed' ? 'Đã TT' : 'Chờ TT' ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($payment): ?>
                                        <strong><?= number_format($payment['amount'], 0, ',', '.') ?>đ</strong>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">
                                    Không có dữ liệu
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
