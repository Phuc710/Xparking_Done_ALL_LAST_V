<?php
/**
 * Tổng quan - User Dashboard
 */

// Lấy thống kê của user
$userVehicles = $supabase->select('vehicles', 'count', ['user_id' => "eq.{$user_id}"]);
$userBookings = $supabase->select('bookings', 'count', ['user_id' => "eq.{$user_id}"]);
$userPayments = $supabase->select('payments', 'count', ['user_id' => "eq.{$user_id}", 'status' => 'eq.completed']);

$totalVehicles = $userVehicles ? $userVehicles[0]['count'] : 0;
$totalBookings = $userBookings ? $userBookings[0]['count'] : 0;
$totalPayments = $userPayments ? $userPayments[0]['count'] : 0;

// Lấy thông báo mới nhất
$notifications = $supabase->select('notifications', '*', [], 'created_at.desc', 1);
$latestNotification = $notifications ? $notifications[0] : null;

// Lấy slots status
$slots = $supabase->select('parking_slots', '*');
$emptySlots = count(array_filter($slots, fn($s) => $s['status'] === 'empty'));
?>

<div class="container-fluid p-4">
    <h2 class="fw-bold mb-4">
        <i class="fas fa-home me-2"></i>
        Tổng quan
    </h2>

    <!-- Notification -->
    <?php if ($latestNotification): ?>
    <div class="alert alert-<?= $latestNotification['type'] === 'error' ? 'danger' : $latestNotification['type'] ?> mb-4">
        <h6 class="alert-heading">
            <i class="fas fa-bullhorn me-2"></i>
            <?= htmlspecialchars($latestNotification['title']) ?>
        </h6>
        <p class="mb-0"><?= nl2br(htmlspecialchars($latestNotification['message'])) ?></p>
        <hr>
        <small class="text-muted">
            <?= date('d/m/Y H:i', strtotime($latestNotification['created_at'])) ?>
        </small>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-2">Lượt đỗ xe</h6>
                            <h2 class="mb-0"><?= $totalVehicles ?></h2>
                        </div>
                        <div class="bg-primary bg-opacity-10 p-3 rounded">
                            <i class="fas fa-car text-primary fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-2">Bookings</h6>
                            <h2 class="mb-0"><?= $totalBookings ?></h2>
                        </div>
                        <div class="bg-success bg-opacity-10 p-3 rounded">
                            <i class="fas fa-calendar-check text-success fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-2">Thanh toán</h6>
                            <h2 class="mb-0"><?= $totalPayments ?></h2>
                        </div>
                        <div class="bg-warning bg-opacity-10 p-3 rounded">
                            <i class="fas fa-credit-card text-warning fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-2">Slot trống</h6>
                            <h2 class="mb-0"><?= $emptySlots ?>/<?= count($slots) ?></h2>
                        </div>
                        <div class="bg-info bg-opacity-10 p-3 rounded">
                            <i class="fas fa-parking text-info fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="row g-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h5 class="card-title">
                        <i class="fas fa-bolt text-warning me-2"></i>
                        Hành động nhanh
                    </h5>
                    <hr>
                    <div class="d-grid gap-2">
                        <a href="?page=orders" class="btn btn-primary">
                            <i class="fas fa-calendar-plus me-2"></i>
                            Đặt chỗ mới
                        </a>
                        <a href="?page=history" class="btn btn-outline-secondary">
                            <i class="fas fa-history me-2"></i>
                            Xem lịch sử
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h5 class="card-title">
                        <i class="fas fa-parking text-info me-2"></i>
                        Trạng thái bãi xe
                    </h5>
                    <hr>
                    <div class="row g-2">
                        <?php foreach ($slots as $slot): 
                            $statusClass = [
                                'empty' => 'success',
                                'occupied' => 'danger',
                                'reserved' => 'warning',
                                'maintenance' => 'secondary'
                            ][$slot['status']] ?? 'secondary';
                        ?>
                        <div class="col-3">
                            <div class="card border-<?= $statusClass ?> text-center p-2">
                                <small><?= $slot['id'] ?></small>
                                <i class="fas fa-car text-<?= $statusClass ?>"></i>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
