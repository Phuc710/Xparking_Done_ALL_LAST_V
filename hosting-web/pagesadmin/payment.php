<?php
/**
 * Lịch sử Thanh toán - Admin
 */

// Lấy payments
$payments = $supabase->request('payments', 'GET', null, [
    'select' => 'id,user_id,booking_id,vehicle_id,amount,status,payment_method,payment_ref,payment_time,expires_at,created_at,users(full_name),vehicles(license_plate)',
    'order' => 'created_at.desc',
    'limit' => 100
]);
?>

<div class="container-fluid p-4">
    <h2 class="fw-bold mb-4">
        <i class="fas fa-credit-card me-2"></i>
        Lịch sử Thanh toán
    </h2>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <?php
        $totalPayments = count($payments);
        $completedPayments = count(array_filter($payments, fn($p) => $p['status'] === 'completed'));
        $pendingPayments = count(array_filter($payments, fn($p) => $p['status'] === 'pending'));
        $totalAmount = array_sum(array_map(fn($p) => $p['status'] === 'completed' ? $p['amount'] : 0, $payments));
        ?>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Tổng giao dịch</h6>
                    <h3 class="mb-0"><?= $totalPayments ?></h3>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Thành công</h6>
                    <h3 class="mb-0 text-success"><?= $completedPayments ?></h3>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Chờ thanh toán</h6>
                    <h3 class="mb-0 text-warning"><?= $pendingPayments ?></h3>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Tổng tiền</h6>
                    <h3 class="mb-0 text-primary"><?= number_format($totalAmount, 0, ',', '.') ?>đ</h3>
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
                            <th>Loại</th>
                            <th>Biển số</th>
                            <th>Mã ref</th>
                            <th>Số tiền</th>
                            <th>Trạng thái</th>
                            <th>Thời gian</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($payments): ?>
                            <?php foreach ($payments as $payment): 
                                $statusClass = [
                                    'completed' => 'success',
                                    'pending' => 'warning',
                                    'failed' => 'danger',
                                    'expired' => 'secondary'
                                ][$payment['status']] ?? 'secondary';
                                
                                $statusText = [
                                    'completed' => 'Thành công',
                                    'pending' => 'Chờ',
                                    'failed' => 'Thất bại',
                                    'expired' => 'Hết hạn'
                                ][$payment['status']] ?? $payment['status'];
                                
                                // Xác định loại
                                $type = 'Khác';
                                if ($payment['booking_id']) {
                                    $type = 'Booking #' . $payment['booking_id'];
                                } elseif ($payment['vehicle_id']) {
                                    $type = 'Xe ra #' . $payment['vehicle_id'];
                                }
                            ?>
                            <tr>
                                <td><code><?= substr($payment['id'], 0, 8) ?></code></td>
                                <td><?= htmlspecialchars($payment['users']['full_name'] ?? 'Khách') ?></td>
                                <td><small><?= $type ?></small></td>
                                <td>
                                    <?php if ($payment['vehicles']): ?>
                                        <span class="badge bg-primary"><?= htmlspecialchars($payment['vehicles']['license_plate']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><small><code><?= htmlspecialchars($payment['payment_ref']) ?></code></small></td>
                                <td><strong><?= number_format($payment['amount'], 0, ',', '.') ?>đ</strong></td>
                                <td><span class="badge bg-<?= $statusClass ?>"><?= $statusText ?></span></td>
                                <td>
                                    <small>
                                        <?php if ($payment['payment_time']): ?>
                                            <?= date('d/m H:i', strtotime($payment['payment_time'])) ?>
                                        <?php else: ?>
                                            <?= date('d/m H:i', strtotime($payment['created_at'])) ?>
                                        <?php endif; ?>
                                    </small>
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
