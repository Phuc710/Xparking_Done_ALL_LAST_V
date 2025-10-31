<?php
/**
 * Trang Lịch sử đỗ xe - Admin
 * Hiển thị tất cả lịch sử xe ra vào với ảnh
 */

// Lấy parameters
$page_num = isset($_GET['page_num']) ? intval($_GET['page_num']) : 1;
$limit = 20;
$offset = ($page_num - 1) * $limit;
$search_plate = $_GET['search_plate'] ?? '';
$search_date = $_GET['search_date'] ?? '';

// Build filters
$filters = [];
if (!empty($search_plate)) {
    $filters["license_plate"] = "ilike.*{$search_plate}*";
}
if (!empty($search_date)) {
    // Filter by date
    $start = $search_date . ' 00:00:00';
    $end = $search_date . ' 23:59:59';
    $filters["entry_time"] = "gte.{$start}";
    $filters["entry_time"] = "lte.{$end}";
}

// Get total count
$totalVehicles = $supabase->select('vehicles', 'count', $filters);
$totalCount = $totalVehicles ? $totalVehicles[0]['count'] : 0;
$totalPages = ceil($totalCount / $limit);

// Get vehicles với pagination
$vehicles = $supabase->select(
    'vehicles',
    '*, users(full_name, username)',
    $filters,
    'entry_time.desc',
    $limit
);
?>

<div class="container-fluid p-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold">
            <i class="fas fa-car me-2"></i>
            Lịch sử đỗ xe
        </h2>
        <button class="btn btn-success" onclick="exportHistory()">
            <i class="fas fa-download me-2"></i>
            Xuất Excel
        </button>
    </div>

    <!-- Search -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <input type="hidden" name="page" value="lichsuxe">
                
                <div class="col-md-3">
                    <label class="form-label">Biển số xe</label>
                    <input type="text" class="form-control" name="search_plate" 
                           value="<?= htmlspecialchars($search_plate) ?>" 
                           placeholder="Nhập biển số...">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Ngày</label>
                    <input type="date" class="form-control" name="search_date" 
                           value="<?= htmlspecialchars($search_date) ?>">
                </div>
                
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="fas fa-search"></i> Tìm kiếm
                    </button>
                    <a href="?page=lichsuxe" class="btn btn-secondary">
                        <i class="fas fa-undo"></i> Reset
                    </a>
                </div>
            </form>
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
                            <th>Biển số</th>
                            <th>Người dùng</th>
                            <th>RFID</th>
                            <th>Slot</th>
                            <th>Thời gian vào</th>
                            <th>Thời gian ra</th>
                            <th>Trạng thái</th>
                            <th>Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($vehicles): ?>
                            <?php foreach ($vehicles as $vehicle): ?>
                            <tr>
                                <td>#<?= $vehicle['id'] ?></td>
                                <td>
                                    <span class="badge bg-primary">
                                        <?= htmlspecialchars($vehicle['license_plate']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($vehicle['users']['full_name'] ?? 'Khách') ?></td>
                                <td>
                                    <code><?= htmlspecialchars($vehicle['rfid_tag'] ?? 'N/A') ?></code>
                                </td>
                                <td><?= htmlspecialchars($vehicle['slot_id'] ?? 'N/A') ?></td>
                                <td>
                                    <small><?= date('d/m/Y H:i', strtotime($vehicle['entry_time'])) ?></small>
                                </td>
                                <td>
                                    <?php if ($vehicle['exit_time']): ?>
                                        <small><?= date('d/m/Y H:i', strtotime($vehicle['exit_time'])) ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">Chưa ra</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($vehicle['status'] === 'in_parking'): ?>
                                        <span class="badge bg-success">Trong bãi</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Đã ra</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-info" 
                                            onclick='showVehicleDetails(<?= json_encode($vehicle) ?>)'>
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center py-4 text-muted">
                                    Không có dữ liệu
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="card-footer bg-white">
            <nav>
                <ul class="pagination mb-0 justify-content-center">
                    <?php for ($i = 1; $i <= min($totalPages, 10); $i++): ?>
                    <li class="page-item <?= $i === $page_num ? 'active' : '' ?>">
                        <a class="page-link" href="?page=lichsuxe&page_num=<?= $i ?>&search_plate=<?= urlencode($search_plate) ?>&search_date=<?= urlencode($search_date) ?>">
                            <?= $i ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal chi tiết -->
<div class="modal fade" id="vehicleDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Chi tiết xe</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="vehicleDetailContent">
                <!-- Content will be inserted here -->
            </div>
        </div>
    </div>
</div>

<script>
function showVehicleDetails(vehicle) {
    let content = `
        <div class="row">
            <div class="col-md-6">
                <h6 class="text-muted mb-3">Thông tin xe</h6>
                <table class="table table-sm">
                    <tr>
                        <td>ID:</td>
                        <td><strong>#${vehicle.id}</strong></td>
                    </tr>
                    <tr>
                        <td>Biển số:</td>
                        <td><span class="badge bg-primary">${vehicle.license_plate}</span></td>
                    </tr>
                    <tr>
                        <td>RFID:</td>
                        <td><code>${vehicle.rfid_tag || 'N/A'}</code></td>
                    </tr>
                    <tr>
                        <td>Slot:</td>
                        <td>${vehicle.slot_id || 'N/A'}</td>
                    </tr>
                    <tr>
                        <td>Người dùng:</td>
                        <td>${vehicle.users ? vehicle.users.full_name : 'Khách'}</td>
                    </tr>
                </table>
            </div>
            <div class="col-md-6">
                <h6 class="text-muted mb-3">Thời gian</h6>
                <table class="table table-sm">
                    <tr>
                        <td>Giờ vào:</td>
                        <td><strong>${new Date(vehicle.entry_time).toLocaleString('vi-VN')}</strong></td>
                    </tr>
                    <tr>
                        <td>Giờ ra:</td>
                        <td><strong>${vehicle.exit_time ? new Date(vehicle.exit_time).toLocaleString('vi-VN') : 'Chưa ra'}</strong></td>
                    </tr>
                    <tr>
                        <td>Thời lượng:</td>
                        <td>${calculateDuration(vehicle.entry_time, vehicle.exit_time)}</td>
                    </tr>
                    <tr>
                        <td>Trạng thái:</td>
                        <td>
                            <span class="badge bg-${vehicle.status === 'in_parking' ? 'success' : 'secondary'}">
                                ${vehicle.status === 'in_parking' ? 'Trong bãi' : 'Đã ra'}
                            </span>
                        </td>
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

function calculateDuration(entryTime, exitTime) {
    if (!exitTime) return 'Đang đỗ';
    
    const entry = new Date(entryTime);
    const exit = new Date(exitTime);
    const diff = exit - entry;
    
    const hours = Math.floor(diff / (1000 * 60 * 60));
    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
    
    return `${hours}h ${minutes}m`;
}

function exportHistory() {
    const params = new URLSearchParams({
        search_plate: '<?= $search_plate ?>',
        search_date: '<?= $search_date ?>'
    });
    window.location.href = `api/export_vehicles.php?${params}`;
}
</script>
