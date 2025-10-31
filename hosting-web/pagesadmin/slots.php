<?php
/**
 * Quản lý Slots - Admin
 */

// Lấy tất cả slots từ Supabase
$slots = $supabase->select('parking_slots', '*', [], 'id.asc');

// Xử lý update slot
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_slot') {
        $slot_id = $_POST['slot_id'];
        $status = $_POST['status'];
        
        $result = $supabase->update('parking_slots', 
            ['status' => $status, 'updated_at' => getVNTime()],
            ['id' => "eq.{$slot_id}"]
        );
        
        if ($result) {
            echo "<script>alert('Cập nhật thành công!'); location.reload();</script>";
        }
    }
}
?>

<div class="container-fluid p-4">
    <h2 class="fw-bold mb-4">
        <i class="fas fa-parking me-2"></i>
        Quản lý Slots
    </h2>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <?php
        $totalSlots = count($slots);
        $emptySlots = count(array_filter($slots, fn($s) => $s['status'] === 'empty'));
        $occupiedSlots = count(array_filter($slots, fn($s) => $s['status'] === 'occupied'));
        $reservedSlots = count(array_filter($slots, fn($s) => $s['status'] === 'reserved'));
        ?>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Tổng slots</h6>
                    <h3 class="mb-0"><?= $totalSlots ?></h3>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Trống</h6>
                    <h3 class="mb-0 text-success"><?= $emptySlots ?></h3>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Đang sử dụng</h6>
                    <h3 class="mb-0 text-danger"><?= $occupiedSlots ?></h3>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Đã đặt</h6>
                    <h3 class="mb-0 text-warning"><?= $reservedSlots ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Slots Grid -->
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="row g-4">
                <?php foreach ($slots as $slot): 
                    $statusClass = [
                        'empty' => 'success',
                        'occupied' => 'danger',
                        'reserved' => 'warning',
                        'maintenance' => 'secondary'
                    ][$slot['status']] ?? 'secondary';
                    
                    $statusText = [
                        'empty' => 'Trống',
                        'occupied' => 'Đang sử dụng',
                        'reserved' => 'Đã đặt',
                        'maintenance' => 'Bảo trì'
                    ][$slot['status']] ?? 'Không xác định';
                ?>
                <div class="col-md-3">
                    <div class="card border-<?= $statusClass ?> h-100">
                        <div class="card-body text-center">
                            <i class="fas fa-car fs-1 text-<?= $statusClass ?> mb-3"></i>
                            <h5 class="fw-bold"><?= htmlspecialchars($slot['id']) ?></h5>
                            <span class="badge bg-<?= $statusClass ?> mb-3"><?= $statusText ?></span>
                            
                            <?php if ($slot['rfid_assigned'] && $slot['rfid_assigned'] !== 'empty'): ?>
                            <p class="mb-2"><small>RFID: <code><?= htmlspecialchars($slot['rfid_assigned']) ?></code></small></p>
                            <?php endif; ?>
                            
                            <?php if ($slot['vehicle_id']): ?>
                            <p class="mb-2"><small>Vehicle: #<?= $slot['vehicle_id'] ?></small></p>
                            <?php endif; ?>
                            
                            <hr>
                            
                            <?php if (in_array($slot['status'], ['empty', 'maintenance'])): ?>
                            <button class="btn btn-sm btn-outline-primary w-100" 
                                    onclick="editSlot('<?= $slot['id'] ?>', '<?= $slot['status'] ?>')">
                                <i class="fas fa-edit"></i> Sửa
                            </button>
                            <?php else: ?>
                            <button class="btn btn-sm btn-secondary w-100" disabled>
                                Đang sử dụng
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal Edit -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Cập nhật Slot</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_slot">
                    <input type="hidden" name="slot_id" id="edit_slot_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Trạng thái</label>
                        <select class="form-select" name="status" id="edit_status">
                            <option value="empty">Trống (Hoạt động)</option>
                            <option value="maintenance">Bảo trì</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-primary">Lưu</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editSlot(slotId, status) {
    document.getElementById('edit_slot_id').value = slotId;
    document.getElementById('edit_status').value = status;
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

// Realtime update
if (typeof ws !== 'undefined') {
    ws.onmessage = function(event) {
        const data = JSON.parse(event.data);
        if (data.type === 'slot_update') {
            location.reload();
        }
    };
}
</script>
