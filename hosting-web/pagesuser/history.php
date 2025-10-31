<?php
/**
 * Lịch sử của User
 * Xem lịch sử đỗ xe và booking
 */

$user_id = $_SESSION['user_id'];

// Pagination
$page_num = $_GET['page_num'] ?? 1;
$limit = 10;
$offset = ($page_num - 1) * $limit;

// Lấy lịch sử xe
$vehicles = $supabase->request('vehicles', 'GET', null, [
    'select' => '*',
    'user_id' => "eq.{$user_id}",
    'order' => 'entry_time.desc',
    'limit' => $limit,
    'offset' => $offset
]);

// Lấy bookings
$bookings = $supabase->request('bookings', 'GET', null, [
    'select' => '*, payments(*)',
    'user_id' => "eq.{$user_id}",
    'order' => 'created_at.desc',
    'limit' => $limit
]);
?>

<div class="container-fluid p-4">
    <h2 class="fw-bold mb-4">
        <i class="fas fa-history me-2"></i>
        Lịch sử của tôi
    </h2>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#vehicles-tab">
                <i class="fas fa-car me-2"></i>
                Lịch sử đỗ xe
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#bookings-tab">
                <i class="fas fa-calendar-check me-2"></i>
                Lịch sử booking
            </a>
        </li>
    </ul>

    <div class="tab-content">
        <!-- Vehicles Tab -->
        <div class="tab-pane fade show active" id="vehicles-tab">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Biển số</th>
                                    <th>Slot</th>
                                    <th>Giờ vào</th>
                                    <th>Giờ ra</th>
                                    <th>Phí</th>
                                    <th>Trạng thái</th>
                                    <th>Chi tiết</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($vehicles): ?>
                                    <?php foreach ($vehicles as $vehicle): ?>
                                    <tr>
                                        <td><span class="badge bg-primary"><?= htmlspecialchars($vehicle['license_plate']) ?></span></td>
                                        <td><?= htmlspecialchars($vehicle['slot_id']) ?></td>
                                        <td><small><?= date('d/m H:i', strtotime($vehicle['entry_time'])) ?></small></td>
                                        <td>
                                            <?php if ($vehicle['exit_time']): ?>
                                                <small><?= date('d/m H:i', strtotime($vehicle['exit_time'])) ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">Đang đỗ</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($vehicle['fee']): ?>
                                                <strong><?= number_format($vehicle['fee'], 0, ',', '.') ?>đ</strong>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $vehicle['status'] === 'in_parking' ? 'success' : 'secondary' ?>">
                                                <?= $vehicle['status'] === 'in_parking' ? 'Trong bãi' : 'Đã ra' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-info" onclick='showVehicleDetail(<?= json_encode($vehicle) ?>)'>
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-muted">
                                            Chưa có lịch sử
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bookings Tab -->
        <div class="tab-pane fade" id="bookings-tab">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Mã</th>
                                    <th>Biển số</th>
                                    <th>Slot</th>
                                    <th>Thời gian</th>
                                    <th>Số tiền</th>
                                    <th>Trạng thái</th>
                                    <th>Thanh toán</th>
                                    <th>Hành động</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($bookings): ?>
                                    <?php foreach ($bookings as $booking): 
                                        $payment = $booking['payments'][0] ?? null;
                                    ?>
                                    <tr>
                                        <td><code>#<?= $booking['id'] ?></code></td>
                                        <td><span class="badge bg-primary"><?= htmlspecialchars($booking['license_plate']) ?></span></td>
                                        <td><?= htmlspecialchars($booking['slot_id']) ?></td>
                                        <td>
                                            <small>
                                                <?= date('d/m H:i', strtotime($booking['start_time'])) ?><br>
                                                → <?= date('d/m H:i', strtotime($booking['end_time'])) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php if ($payment): ?>
                                                <strong><?= number_format($payment['amount'], 0, ',', '.') ?>đ</strong>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $booking['status'] === 'confirmed' ? 'success' : 'warning' ?>">
                                                <?= $booking['status'] === 'confirmed' ? 'Đã xác nhận' : 'Chờ' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($payment): ?>
                                                <span class="badge bg-<?= $payment['status'] === 'completed' ? 'success' : 'warning' ?>">
                                                    <?= $payment['status'] === 'completed' ? 'Đã TT' : 'Chờ TT' ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($booking['status'] === 'confirmed'): ?>
                                                <button class="btn btn-sm btn-danger" onclick="cancelConfirmedBooking(<?= $booking['id'] ?>)">
                                                    <i class="fas fa-times"></i> Hủy
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-muted">
                                            Chưa có booking
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Chi tiết xe -->
<div class="modal fade" id="vehicleDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Chi tiết xe</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="vehicleDetailContent">
                <!-- Content here -->
            </div>
        </div>
    </div>
</div>

<script>
// Hủy booking đã confirmed (đã thanh toán, KHÔNG HOÀN TIỀN)
function cancelConfirmedBooking(bookingId) {
    if (!confirm('Bạn có chắc muốn hủy booking?\n\nLƯU Ý: Đã thanh toán sẽ KHÔNG HOÀN TIỀN!')) {
        return;
    }
    
    fetch('api/cancel_booking.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({booking_id: bookingId})
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Đã hủy booking (không hoàn tiền)');
            location.reload();
        } else {
            alert(data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Lỗi khi hủy');
    });
}

function showVehicleDetail(vehicle) {
    let duration = 'Đang đỗ';
    if (vehicle.exit_time) {
        const entry = new Date(vehicle.entry_time);
        const exit = new Date(vehicle.exit_time);
        const diff = exit - entry;
        const hours = Math.floor(diff / (1000 * 60 * 60));
        const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
        duration = `${hours}h ${minutes}m`;
    }
    
    const content = `
        <div class="row">
            <div class="col-md-6">
                <h6 class="text-muted mb-3">Thông tin</h6>
                <table class="table table-sm">
                    <tr>
                        <td>Biển số:</td>
                        <td><span class="badge bg-primary">${vehicle.license_plate}</span></td>
                    </tr>
                    <tr>
                        <td>Slot:</td>
                        <td>${vehicle.slot_id}</td>
                    </tr>
                    <tr>
                        <td>RFID:</td>
                        <td><code>${vehicle.rfid_tag || 'N/A'}</code></td>
                    </tr>
                    <tr>
                        <td>Thời lượng:</td>
                        <td><strong>${duration}</strong></td>
                    </tr>
                    <tr>
                        <td>Phí:</td>
                        <td><strong class="text-success">${vehicle.fee ? new Intl.NumberFormat('vi-VN').format(vehicle.fee) + 'đ' : '-'}</strong></td>
                    </tr>
                </table>
            </div>
            <div class="col-md-6">
                <h6 class="text-muted mb-3">Thời gian</h6>
                <table class="table table-sm">
                    <tr>
                        <td>Vào:</td>
                        <td>${new Date(vehicle.entry_time).toLocaleString('vi-VN')}</td>
                    </tr>
                    <tr>
                        <td>Ra:</td>
                        <td>${vehicle.exit_time ? new Date(vehicle.exit_time).toLocaleString('vi-VN') : 'Đang đỗ'}</td>
                    </tr>
                </table>
            </div>
        </div>
        
        <hr>
        
        <div class="row">
            <div class="col-md-6 text-center">
                <h6 class="text-muted mb-3">Ảnh vào</h6>
                ${vehicle.entry_image ? 
                    `<img src="data:image/jpeg;base64,${vehicle.entry_image}" class="img-fluid rounded shadow" alt="Ảnh vào">` : 
                    '<p class="text-muted">Không có ảnh</p>'
                }
            </div>
            <div class="col-md-6 text-center">
                <h6 class="text-muted mb-3">Ảnh ra</h6>
                ${vehicle.exit_image ? 
                    `<img src="data:image/jpeg;base64,${vehicle.exit_image}" class="img-fluid rounded shadow" alt="Ảnh ra">` : 
                    '<p class="text-muted">Không có ảnh</p>'
                }
            </div>
        </div>
    `;
    
    document.getElementById('vehicleDetailContent').innerHTML = content;
    new bootstrap.Modal(document.getElementById('vehicleDetailModal')).show();
}
</script>
