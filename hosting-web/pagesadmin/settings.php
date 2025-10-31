<?php
/**
 * Cài đặt Hệ thống - Admin
 */

// Xử lý gửi thông báo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'send_notification') {
        $notificationData = [
            'title' => $_POST['title'],
            'message' => $_POST['message'],
            'type' => $_POST['type'],
            'target_user_id' => null, // Gửi cho tất cả
            'created_at' => getVNTime()
        ];
        
        $result = $supabase->insert('notifications', $notificationData);
        
        if ($result) {
            echo "<script>alert('Gửi thông báo thành công!'); location.reload();</script>";
        } else {
            echo "<script>alert('Có lỗi xảy ra!');</script>";
        }
    }
    
    if ($_POST['action'] === 'delete_notifications') {
        $result = $supabase->delete('notifications', []);
        if ($result) {
            echo "<script>alert('Đã xóa tất cả thông báo!'); location.reload();</script>";
        }
    }
}

// Lấy thông báo hiện tại
$latestNotification = $supabase->select('notifications', '*', [], 'created_at.desc', 1);
$currentNotification = $latestNotification ? $latestNotification[0] : null;

// Lấy thống kê hệ thống
$stats = [
    'total_users' => count($supabase->select('users', 'id') ?? []),
    'total_vehicles' => count($supabase->select('vehicles', 'id') ?? []),
    'total_bookings' => count($supabase->select('bookings', 'id') ?? []),
    'total_payments' => count($supabase->select('payments', 'id') ?? [])
];
?>

<div class="container-fluid p-4">
    <h2 class="fw-bold mb-4">
        <i class="fas fa-cog me-2"></i>
        Cài đặt Hệ thống
    </h2>

    <!-- System Info -->
    <div class="row g-4 mb-4">
        <div class="col-md-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        Thông tin Hệ thống
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <td><strong>Hệ thống:</strong></td>
                                    <td>XPARKING v3.0</td>
                                </tr>
                                <tr>
                                    <td><strong>Database:</strong></td>
                                    <td><span class="badge bg-success">Supabase PostgreSQL</span></td>
                                </tr>
                                <tr>
                                    <td><strong>Realtime:</strong></td>
                                    <td><span class="badge bg-info">WebSocket Active</span></td>
                                </tr>
                                <tr>
                                    <td><strong>Phát triển bởi:</strong></td>
                                    <td>PHUCX</td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <td><strong>Tổng users:</strong></td>
                                    <td><?= $stats['total_users'] ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Tổng xe:</strong></td>
                                    <td><?= $stats['total_vehicles'] ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Tổng bookings:</strong></td>
                                    <td><?= $stats['total_bookings'] ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Tổng payments:</strong></td>
                                    <td><?= $stats['total_payments'] ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="text-center">
                        <p class="text-muted mb-2">Thời gian hệ thống</p>
                        <h4 id="clock" class="text-primary"></h4>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">
                        <i class="fas fa-database me-2"></i>
                        Database Status
                    </h5>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-center mb-3">
                        <div class="flex-shrink-0">
                            <i class="fas fa-check-circle fs-3 text-success"></i>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="mb-0">Supabase Connected</h6>
                            <small class="text-muted">PostgreSQL realtime</small>
                        </div>
                    </div>
                    
                    <div class="d-flex align-items-center mb-3">
                        <div class="flex-shrink-0">
                            <i class="fas fa-sync-alt fs-3 text-info"></i>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="mb-0">Auto Sync</h6>
                            <small class="text-muted">Python ↔ Web</small>
                        </div>
                    </div>
                    
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-bolt fs-3 text-warning"></i>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="mb-0">Realtime Events</h6>
                            <small class="text-muted">WebSocket active</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Notification Management -->
    <div class="row g-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">
                        <i class="fas fa-bullhorn me-2"></i>
                        Gửi Thông báo
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="send_notification">
                        
                        <div class="mb-3">
                            <label class="form-label">Tiêu đề</label>
                            <input type="text" class="form-control" name="title" required 
                                   placeholder="VD: Bảo trì hệ thống">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Nội dung</label>
                            <textarea class="form-control" name="message" rows="3" required 
                                      placeholder="Nhập nội dung thông báo..."></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Loại</label>
                            <select class="form-select" name="type">
                                <option value="info">Thông tin</option>
                                <option value="warning">Cảnh báo</option>
                                <option value="error">Khẩn cấp</option>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-paper-plane me-2"></i>
                            Gửi thông báo
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-bell me-2"></i>
                        Thông báo Hiện tại
                    </h5>
                    <?php if ($currentNotification): ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="delete_notifications">
                        <button type="submit" class="btn btn-sm btn-danger" 
                                onclick="return confirm('Xóa tất cả thông báo?')">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($currentNotification): ?>
                    <div class="alert alert-<?= $currentNotification['type'] === 'error' ? 'danger' : $currentNotification['type'] ?>">
                        <h6 class="alert-heading"><?= htmlspecialchars($currentNotification['title']) ?></h6>
                        <p class="mb-2"><?= nl2br(htmlspecialchars($currentNotification['message'])) ?></p>
                        <hr>
                        <small class="text-muted">
                            Gửi lúc: <?= date('d/m/Y H:i', strtotime($currentNotification['created_at'])) ?>
                        </small>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-bell-slash fs-1 mb-3"></i>
                        <p>Không có thông báo nào</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Update clock
function updateClock() {
    const now = new Date();
    const time = now.toLocaleTimeString('vi-VN', { hour12: false });
    const date = now.toLocaleDateString('vi-VN');
    document.getElementById('clock').textContent = `${time} - ${date}`;
}

setInterval(updateClock, 1000);
updateClock();
</script>
