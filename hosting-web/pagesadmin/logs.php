<?php
/**
 * Nhật ký Hệ thống - Admin
 */

// Lấy system logs
$logs = $supabase->request('system_logs', 'GET', null, [
    'select' => 'id,user_id,action,details,level,created_at,users(username,full_name)',
    'order' => 'created_at.desc',
    'limit' => 200
]);
?>

<div class="container-fluid p-4">
    <h2 class="fw-bold mb-4">
        <i class="fas fa-history me-2"></i>
        Nhật ký Hệ thống
    </h2>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <?php
        $totalLogs = count($logs);
        $infoLogs = count(array_filter($logs, fn($l) => $l['level'] === 'info'));
        $warningLogs = count(array_filter($logs, fn($l) => $l['level'] === 'warning'));
        $errorLogs = count(array_filter($logs, fn($l) => $l['level'] === 'error'));
        ?>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Tổng logs</h6>
                    <h3 class="mb-0"><?= $totalLogs ?></h3>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Info</h6>
                    <h3 class="mb-0 text-info"><?= $infoLogs ?></h3>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Warning</h6>
                    <h3 class="mb-0 text-warning"><?= $warningLogs ?></h3>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Error</h6>
                    <h3 class="mb-0 text-danger"><?= $errorLogs ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Thời gian</th>
                            <th>Level</th>
                            <th>Action</th>
                            <th>Chi tiết</th>
                            <th>Người dùng</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($logs): ?>
                            <?php foreach ($logs as $log): 
                                $levelClass = [
                                    'info' => 'info',
                                    'warning' => 'warning',
                                    'error' => 'danger',
                                    'success' => 'success'
                                ][$log['level']] ?? 'secondary';
                            ?>
                            <tr>
                                <td><small><?= date('d/m H:i:s', strtotime($log['created_at'])) ?></small></td>
                                <td><span class="badge bg-<?= $levelClass ?>"><?= strtoupper($log['level']) ?></span></td>
                                <td><strong><?= htmlspecialchars($log['action']) ?></strong></td>
                                <td><small><?= htmlspecialchars($log['details']) ?></small></td>
                                <td>
                                    <?php if ($log['users']): ?>
                                        <?= htmlspecialchars($log['users']['username']) ?>
                                    <?php else: ?>
                                        <span class="text-muted">System</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">
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
