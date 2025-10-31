<?php
// admin.php - Admin panel (Supabase PostgreSQL)
require_once 'includes/config.php';
require_once 'includes/auth.php';
// Require admin login
require_login();
require_admin();

// Handle tab switching
$tab = $_GET['tab'] ?? 'dashboard';

// Handle admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'update_slot':
            $slot_id = $_POST['slot_id'] ?? '';
            $status = $_POST['status'] ?? '';

            if (empty($slot_id) || empty($status)) {
                set_flash_message('error', 'Thiếu thông tin cần thiết!');
                redirect('admin.php?tab=slots');
                break;
            }

            // Only allow empty <-> maintenance transitions
            if (!in_array($status, ['empty', 'maintenance'])) {
                set_flash_message('error', 'Chỉ có thể chuyển đổi giữa trạng thái trống và bảo trì!');
                redirect('admin.php?tab=slots');
                break;
            }

            if (update_slot_status($slot_id, $status)) {
                set_flash_message('success', 'Cập nhật trạng thái slot thành công!');
            } else {
                set_flash_message('error', 'Có lỗi xảy ra khi cập nhật slot!');
            }
            redirect('admin.php?tab=slots');
            break;

        case 'delete_notification':
            try {
                // Xóa tất cả thông báo
                $stmt = $pdo->prepare("DELETE FROM notifications");
                if ($stmt->execute()) {
                    set_flash_message('success', 'Đã xóa tất cả thông báo thành công!');
                } else {
                    set_flash_message('error', 'Có lỗi xảy ra khi xóa thông báo!');
                }
            } catch (PDOException $e) {
                error_log("Delete notification error: " . $e->getMessage());
                set_flash_message('error', 'Lỗi hệ thống khi xóa thông báo!');
            }
            redirect('admin.php?tab=settings');
            break;
        case 'send_notification':
            $title = trim($_POST['notification_title'] ?? '');
            $message = trim($_POST['notification_message'] ?? '');
            $type = $_POST['notification_type'] ?? 'info';
            
            if (empty($title) || empty($message)) {
                set_flash_message('error', 'Vui lòng nhập đầy đủ tiêu đề và nội dung!');
                redirect('admin.php?tab=settings');
                break;
            }
            
            try {
                // target_user_id = NULL nghĩa là gửi cho tất cả user
                $stmt = $pdo->prepare("INSERT INTO notifications (title, message, type, target_user_id) VALUES (?, ?, ?, NULL)");
                if ($stmt->execute([$title, $message, $type])) {
                    set_flash_message('success', 'Gửi thông báo thành công!');
                } else {
                    set_flash_message('error', 'Có lỗi xảy ra khi gửi thông báo!');
                }
            } catch (PDOException $e) {
                error_log("Send notification error: " . $e->getMessage());
                set_flash_message('error', 'Lỗi hệ thống khi gửi thông báo!');
            }
            redirect('admin.php?tab=settings');
            break;
    }
}

// Function to get active vehicles
function get_active_vehicles() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("SELECT v.*, s.id as slot_id
                            FROM vehicles v
                            JOIN parking_slots s ON v.slot_id = s.id
                            WHERE v.status = 'in_parking'
                            ORDER BY v.entry_time DESC");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Get active vehicles error: " . $e->getMessage());
        return [];
    }
}

// Function to get all users
function get_all_users() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("SELECT id, username, email, full_name, phone, role, created_at
                            FROM users
                            ORDER BY created_at DESC");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Get users error: " . $e->getMessage());
        return [];
    }
}

// Function to get all bookings
function get_all_bookings() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("SELECT b.*, u.username, u.full_name, p.status as payment_status, p.amount
                            FROM bookings b
                            JOIN users u ON b.user_id = u.id
                            LEFT JOIN payments p ON b.id = p.booking_id
                            ORDER BY b.created_at DESC");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Get bookings error: " . $e->getMessage());
        return [];
    }
}

// Function to get all payments
function get_all_payments() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("SELECT p.*, u.username, u.full_name, b.id as booking_id, v.license_plate
                            FROM payments p
                            LEFT JOIN users u ON p.user_id = u.id
                            LEFT JOIN bookings b ON p.booking_id = b.id
                            LEFT JOIN vehicles v ON p.vehicle_id = v.id
                            ORDER BY p.created_at DESC
                            LIMIT 50");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Get payments error: " . $e->getMessage());
        return [];
    }
}

// Function to get system logs
function get_system_logs() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("SELECT l.*, u.username
                            FROM system_logs l
                            LEFT JOIN users u ON l.user_id = u.id
                            ORDER BY l.created_at DESC
                            LIMIT 100");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Get logs error: " . $e->getMessage());
        return [];
    }
}

// Function to get revenue statistics
function get_revenue_stats() {
    global $pdo;
    
    try {
        $stats = [];
        
        // Today's revenue
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as today_revenue
                              FROM payments
                              WHERE status = 'completed' AND DATE(payment_time) = CURDATE()");
        $stmt->execute();
        $stats['today'] = $stmt->fetchColumn();
        
        // This week's revenue
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as week_revenue
                              FROM payments
                              WHERE status = 'completed' AND WEEK(payment_time) = WEEK(NOW())
                              AND YEAR(payment_time) = YEAR(NOW())");
        $stmt->execute();
        $stats['week'] = $stmt->fetchColumn();
        
        // This month's revenue
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as month_revenue
                              FROM payments
                              WHERE status = 'completed' AND MONTH(payment_time) = MONTH(NOW())
                              AND YEAR(payment_time) = YEAR(NOW())");
        $stmt->execute();
        $stats['month'] = $stmt->fetchColumn();
        
        // Total revenue
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total_revenue
                              FROM payments
                              WHERE status = 'completed'");
        $stmt->execute();
        $stats['total'] = $stmt->fetchColumn();
        
        // Daily revenue for last 7 days
        $stmt = $pdo->prepare("SELECT DATE(payment_time) as date, COALESCE(SUM(amount), 0) as revenue
                              FROM payments
                              WHERE status = 'completed' AND payment_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                              GROUP BY DATE(payment_time)
                              ORDER BY date ASC");
        $stmt->execute();
        $stats['daily'] = $stmt->fetchAll();
        
        return $stats;
    } catch (PDOException $e) {
        error_log("Get revenue stats error: " . $e->getMessage());
        return [
            'today' => 0,
            'week' => 0,
            'month' => 0,
            'total' => 0,
            'daily' => []
        ];
    }
}

// Get data for current tab
switch ($tab) {
    case 'dashboard':
        $active_vehicles = get_active_vehicles();
        $slots = get_all_slots();
        break;
    case 'vehicles':
        // Lấy tất cả lịch sử xe ra/vào từ cơ sở dữ liệu
        function get_all_vehicles() {
            global $pdo;
            try {
                $stmt = $pdo->query("SELECT v.*, u.full_name, ps.id as slot_id
                                    FROM vehicles v
                                    LEFT JOIN users u ON v.user_id = u.id
                                    LEFT JOIN parking_slots ps ON v.slot_id = ps.id
                                    ORDER BY v.entry_time DESC
                                    LIMIT 50");
                return $stmt->fetchAll();
            } catch (PDOException $e) {
                error_log("Get all vehicles error: " . $e->getMessage());
                return [];
            }
        }
        $all_vehicles = get_all_vehicles();
        break;
    case 'slots':
        $slots = get_all_slots();
        break;
        
    case 'users':
        $users = get_all_users();
        break;
        
    case 'bookings':
        $bookings = get_all_bookings();
        break;
        
    case 'payments':
        $payments = get_all_payments();
        break;
        
    case 'logs':
        $logs = get_system_logs();
        break;
        
    case 'revenue':
        $revenue_stats = get_revenue_stats();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE status = 'completed'");
        $stmt->execute();
        $completed_payments_count = $stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments");
        $stmt->execute();
        $total_payments_count = $stmt->fetchColumn();
        $success_rate = ($total_payments_count > 0) ? ($completed_payments_count / $total_payments_count) * 100 : 0;
        break;
}

// HTML Header
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>XParking - Admin</title>

    <link rel="shortcut icon" href="/LOGO.gif" type="image/x-icon">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    <link rel="stylesheet" href="styles/admin.css">
</head>

<body>
    <!-- Menu Overlay -->
    <div class="menu-overlay" onclick="closeMobileMenu()"></div>

    <header class="header">
        <div class="container header-container">
            <a href="index.php" class="logo">
                <img src="/LOGO.gif" alt="XParking">
                <span>XPARKING</span>
            </a>

            <!-- Desktop Navigation -->
            <div class="desktop-nav">
                <a href="index.php" class="nav-link">Trang chủ</a>
                <a href="dashboard.php" class="btn btn-user-page">Trang người dùng</a>
                <a href="index.php?action=logout" class="btn btn-logout">Đăng xuất</a>
            </div>

            <!-- Mobile Hamburger - Always on right -->
            <button class="mobile-menu-toggle" onclick="toggleMobileMenu()" aria-label="Toggle menu">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </header>

    <!-- Mobile Menu -->
    <div class="mobile-menu" id="mobileMenu">
        <!-- Admin Header -->
        <div class="mobile-menu-header">
            <div class="admin-avatar">
                <i class="fas fa-user-shield" style="font-size: 1.5rem;"></i>
            </div>
            <h3>Quản trị viên</h3>
            <p><?php echo htmlspecialchars($_SESSION['username']); ?></p>
        </div>

        <!-- Navigation Section -->
        <div class="mobile-menu-section">
            <div class="mobile-menu-section-title">Navigation</div>
            <ul class="mobile-menu-list">
                <li class="mobile-menu-item">
                    <a href="admin.php?tab=dashboard"
                        class="mobile-menu-link <?php echo $tab === 'dashboard' ? 'active' : ''; ?>">
                        <i class="fas fa-tachometer-alt"></i>
                        Tổng quan
                    </a>
                </li>
                <li class="mobile-menu-item">
                    <a href="admin.php?tab=revenue"
                        class="mobile-menu-link <?php echo $tab === 'revenue' ? 'active' : ''; ?>">
                        <i class="fas fa-chart-line"></i>
                        Doanh thu
                    </a>
                </li>
                <li class="mobile-menu-item">
                    <a href="admin.php?tab=slots"
                        class="mobile-menu-link <?php echo $tab === 'slots' ? 'active' : ''; ?>">
                        <i class="fas fa-parking"></i>
                        Quản lý slots
                    </a>
                </li>
                <li class="mobile-menu-item">
                    <a href="admin.php?tab=users"
                        class="mobile-menu-link <?php echo $tab === 'users' ? 'active' : ''; ?>">
                        <i class="fas fa-users"></i>
                        Quản lý người dùng
                    </a>
                </li>
                <li class="mobile-menu-item">
                    <a href="admin.php?tab=vehicles"
                        class="mobile-menu-link <?php echo $tab === 'vehicles' ? 'active' : ''; ?>">
                        <i class="fas fa-car-side"></i> Lịch sử đỗ xe
                    </a>
                </li>
                <li class="mobile-menu-item">
                    <a href="admin.php?tab=bookings"
                        class="mobile-menu-link <?php echo $tab === 'bookings' ? 'active' : ''; ?>">
                        <i class="fas fa-calendar-alt"></i>
                        Lịch sử đặt chỗ
                    </a>
                </li>
                <li class="mobile-menu-item">
                    <a href="admin.php?tab=payments"
                        class="mobile-menu-link <?php echo $tab === 'payments' ? 'active' : ''; ?>">
                        <i class="fas fa-money-bill-wave"></i>
                        Lịch sử thanh toán
                    </a>
                </li>
                <li class="mobile-menu-item">
                    <a href="admin.php?tab=logs"
                        class="mobile-menu-link <?php echo $tab === 'logs' ? 'active' : ''; ?>">
                        <i class="fas fa-history"></i>
                        Nhật ký
                    </a>
                </li>
                <li class="mobile-menu-item">
                    <a href="admin.php?tab=settings"
                        class="mobile-menu-link <?php echo $tab === 'settings' ? 'active' : ''; ?>">
                        <i class="fas fa-cogs"></i>
                        Cài đặt
                    </a>
                </li>
            </ul>
        </div>

        <!-- Actions Section -->
        <div class="mobile-menu-section">
            <div class="mobile-menu-actions">
                <a href="index.php" class="mobile-menu-btn btn-primary">
                    <i class="fas fa-home"></i> Trang chủ
                </a>
                <a href="dashboard.php" class="mobile-menu-btn btn-primary">
                    <i class="fas fa-user"></i> Trang người dùng
                </a>
                <a href="index.php?action=logout" class="mobile-menu-btn btn-danger">
                    <i class="fas fa-sign-out-alt"></i> Đăng xuất
                </a>
            </div>
        </div>
    </div>

    <main class="container dashboard">
        <!-- Desktop Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div style="text-align: center; margin-bottom: 1.5rem;">
                <div
                    style="width: 80px; height: 80px; background-color: #e0e7ff; border-radius: 50%; display: flex; justify-content: center; align-items: center; margin: 0 auto 1rem;">
                    <i class="fas fa-user-shield" style="font-size: 2rem; color: var(--primary);"></i>
                </div>
                <h3>Quản trị viên</h3>
                <p style="color: var(--gray); font-size: 0.875rem;">
                    <?php echo htmlspecialchars($_SESSION['username']); ?></p>
            </div>

            <ul class="sidebar-menu">
                <li class="sidebar-item">
                    <a href="admin.php?tab=dashboard"
                        class="sidebar-link <?php echo $tab === 'dashboard' ? 'active' : ''; ?>">
                        <i class="fas fa-tachometer-alt"></i> Tổng quan
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="admin.php?tab=revenue"
                        class="sidebar-link <?php echo $tab === 'revenue' ? 'active' : ''; ?>">
                        <i class="fas fa-chart-line"></i> Doanh thu
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="admin.php?tab=slots" class="sidebar-link <?php echo $tab === 'slots' ? 'active' : ''; ?>">
                        <i class="fas fa-parking"></i> Quản lý slots
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="admin.php?tab=users" class="sidebar-link <?php echo $tab === 'users' ? 'active' : ''; ?>">
                        <i class="fas fa-users"></i> Quản lý người dùng
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="admin.php?tab=vehicles"
                        class="sidebar-link <?php echo $tab === 'vehicles' ? 'active' : ''; ?>">
                        <i class="fas fa-car-side"></i> Lịch sử đỗ xe
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="admin.php?tab=bookings"
                        class="sidebar-link <?php echo $tab === 'bookings' ? 'active' : ''; ?>">
                        <i class="fas fa-calendar-alt"></i> Lịch sử đặt chỗ
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="admin.php?tab=payments"
                        class="sidebar-link <?php echo $tab === 'payments' ? 'active' : ''; ?>">
                        <i class="fas fa-money-bill-wave"></i> Lịch sử thanh toán
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="admin.php?tab=logs" class="sidebar-link <?php echo $tab === 'logs' ? 'active' : ''; ?>">
                        <i class="fas fa-history"></i> Nhật ký
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="admin.php?tab=settings"
                        class="sidebar-link <?php echo $tab === 'settings' ? 'active' : ''; ?>">
                        <i class="fas fa-cogs"></i> Cài đặt
                    </a>
                </li>
            </ul>
        </aside>

        <section class="content">
            <?php
            switch ($tab) {
                case 'dashboard':
                    ?>
            <div class="card">
                <h2 class="card-title"><i class="fas fa-tachometer-alt"></i> Tổng quan</h2>

                <div class="stats">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-car"></i>
                        </div>
                        <div class="stat-value"><?php echo count($active_vehicles); ?></div>
                        <div class="stat-label">Xe đang đỗ</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-parking"></i>
                        </div>
                        <?php
                                $available_count = 0;
                                foreach ($slots as $slot) {
                                    if ($slot['actual_status'] === 'empty') {
                                        $available_count++;
                                    }
                                }
                                ?>
                        <div class="stat-value"><?php echo $available_count; ?>/<?php echo count($slots); ?></div>
                        <div class="stat-label">Slot trống</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <?php
                                $stmt = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status = 'confirmed'");
                                $booking_count = $stmt->fetchColumn();
                                ?>
                        <div class="stat-value"><?php echo $booking_count; ?></div>
                        <div class="stat-label">Đặt chỗ hiện tại</div>

                    </div>
                </div>
            </div>

            <div class="card">
                <h2 class="card-title"><i class="fas fa-parking"></i> Tình trạng bãi đỗ xe</h2>

                <div class="slot-grid">
                    <?php foreach ($slots as $slot): 
                                $statusClass = '';
                                $statusText = '';
                                $statusColor = '';
                                
                                switch ($slot['actual_status']) {
                                    case 'empty':
                                        $statusClass = 'success';
                                        $statusText = 'Trống';
                                        $statusColor = '#10b981';
                                        break;
                                    case 'occupied':
                                        $statusClass = 'danger';
                                        $statusText = 'Có xe';
                                        $statusColor = '#ef4444';
                                        break;
                                    case 'reserved':
                                        $statusClass = 'warning';
                                        $statusText = 'Đã đặt';
                                        $statusColor = '#f59e0b';
                                        break;
                                    case 'maintenance':
                                        $statusClass = 'secondary';
                                        $statusText = 'Bảo trì';
                                        $statusColor = '#6b7280';
                                        break;
                                }
                            ?>
                    <div class="slot-card">
                        <div class="slot-icon">
                            <i class="fas fa-car" style="color: <?php echo $statusColor; ?>"></i>
                        </div>
                        <div class="slot-id"><?php echo htmlspecialchars($slot['id']); ?></div>
                        <div class="slot-status">
                            <span class="badge badge-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                        </div>
                        <?php if ($slot['rfid_assigned'] !== 'empty'): ?>
                        <div style="margin-top: 0.5rem; font-size: 0.875rem;">
                            <strong>RFID:</strong> <?php echo htmlspecialchars($slot['rfid_assigned']?? 'N/A'); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php
                    break;
                    
                case 'revenue':
                    ?>
            <div class="card">
                <h2 class="card-title"><i class="fas fa-chart-line"></i> Báo cáo doanh thu</h2>

                <div class="stats">
                    <div class="stat-card" style="border-left: 4px solid #2563eb;">
                        <div class="stat-icon" style="color: #2563eb;">
                            <i class="fas fa-dollar-sign"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($revenue_stats['total'], 0, ',', '.'); ?>₫
                        </div>
                        <div class="stat-label">Tổng doanh thu</div>
                    </div>

                    <div class="stat-card" style="border-left: 4px solid #2563eb;">
                        <div class="stat-icon" style="color: #2563eb;">
                            <i class="fas fa-file-invoice-dollar"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($revenue_stats['today'], 0, ',', '.'); ?>₫
                        </div>
                        <div class="stat-label">Doanh thu hôm nay</div>
                    </div>

                    <div class="stat-card" style="border-left: 4px solid #10b981;">
                        <div class="stat-icon" style="color: #10b981;">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($completed_payments_count, 0, ',', '.'); ?>
                        </div>
                        <div class="stat-label">Tổng giao dịch thành công</div>
                    </div>

                    <div class="stat-card" style="border-left: 4px solid #10b981;">
                        <div class="stat-icon" style="color: #10b981;">
                            <i class="fas fa-percentage"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($success_rate, 2); ?>%</div>
                        <div class="stat-label">Tỷ lệ thành công</div>
                    </div>
                </div>

                <div class="tab-nav">
                    <div id="tab-distribution" class="active" onclick="switchTab('distribution')">Today</div>
                    <div id="tab-trend" onclick="switchTab('trend')">7 Days</div>
                    <div id="tab-ranking" onclick="switchTab('ranking')">Rank</div>
                </div>

                <div id="chart-distribution" class="chart-container">
                    <h3>Doanh thu trong ngày</h3>
                    <canvas id="revenueDistributionChart"></canvas>
                </div>

                <div id="chart-trend" class="chart-container" style="display: none;">
                    <h3>Doanh thu (7 ngày qua)</h3>
                    <canvas id="revenueTrendChart"></canvas>
                </div>

                <div id="chart-ranking" class="chart-container" style="display: none;">
                    <h3>Xếp hạng người dùng chi tiêu</h3>
                    <canvas id="revenueRankingChart"></canvas>
                </div>
            </div>
            <?php
                    break;
                    
                case 'slots':
                    ?>
            <div class="card">
                <h2 class="card-title"><i class="fas fa-parking"></i> Quản lý slots</h2>

                <div class="slot-grid">
                    <?php foreach ($slots as $slot): 
                                $statusClass = '';
                                $statusText = '';
                                $statusColor = '';
                                
                                switch ($slot['actual_status']) {
                                    case 'empty':
                                        $statusClass = 'success';
                                        $statusText = 'Trống';
                                        $statusColor = '#10b981';
                                        break;
                                    case 'occupied':
                                        $statusClass = 'danger';
                                        $statusText = 'Đang sử dụng';
                                        $statusColor = '#ef4444';
                                        break;
                                    case 'reserved':
                                        $statusClass = 'warning';
                                        $statusText = 'Đã đặt trước';
                                        $statusColor = '#f59e0b';
                                        break;
                                    case 'maintenance':
                                        $statusClass = 'secondary';
                                        $statusText = 'Bảo trì';
                                        $statusColor = '#6b7280';
                                        break;
                                }
                            ?>
                    <div class="slot-card">
                        <div class="slot-icon">
                            <i class="fas fa-car" style="color: <?php echo $statusColor; ?>"></i>
                        </div>
                        <div class="slot-id"><?php echo htmlspecialchars($slot['id']); ?></div>
                        <div class="slot-status">
                            <span class="badge badge-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                        </div>
                        <?php if ($slot['rfid_assigned'] !== 'empty'): ?>
                        <div style="margin-top: 0.5rem; font-size: 0.875rem;">
                            <strong>RFID:</strong> <?php echo htmlspecialchars($slot['rfid_assigned']?? 'N/A'); ?>
                        </div>
                        <?php endif; ?>
                        <div style="margin-top: 1rem;">
                            <?php if (in_array($slot['actual_status'], ['empty', 'maintenance'])): ?>
                            <button class="btn btn-primary"
                                onclick="openEditModal('<?php echo $slot['id']; ?>', '<?php echo $slot['predefined_status']; ?>')">Cập
                                nhật</button>
                            <?php else: ?>
                            <button class="btn" style="background-color: #e5e7eb; color: #6b7280; cursor: not-allowed;"
                                disabled>Đang sử dụng</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div id="editSlotModal" class="modal">
                <div class="modal-content">
                    <span class="modal-close" onclick="closeModal()">&times;</span>
                    <h3 class="modal-title">Cập nhật trạng thái slot</h3>

                    <form action="admin.php?tab=slots" method="post">
                        <input type="hidden" name="action" value="update_slot">
                        <input type="hidden" id="slot_id" name="slot_id" value="">

                        <div class="form-group">
                            <label for="status" class="form-label">Trạng thái</label>
                            <select id="status" name="status" class="form-control" required>
                                <option value="empty">Hoạt động</option>
                                <option value="maintenance">Bảo trì</option>
                            </select>
                            <small>Lưu ý: Phương tiện đông đúc.</small>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width: 100%;">Cập nhật</button>
                    </form>
                </div>
            </div>
            <?php
                    break;
                    
                case 'users':
                    ?>
            <div class="card">
                <h2 class="card-title"><i class="fas fa-users"></i> Quản lý người dùng</h2>

                <?php if (empty($users)): ?>
                <div style="text-align: center; padding: 2rem 0;">
                    <i class="fas fa-users"
                        style="font-size: 3rem; color: #d1d5db; display: block; margin-bottom: 1rem;"></i>
                    <p>Không có người dùng nào</p>
                </div>
                <?php else: ?>
                <!-- Desktop Table -->
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Tên đăng nhập</th>
                                <th>Họ và tên</th>
                                <th>Email</th>
                                <th>Số điện thoại</th>
                                <th>Vai trò</th>
                                <th>Ngày đăng ký</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): 
                                        $roleClass = $user['role'] === 'admin' ? 'danger' : 'info';
                                        $roleText = $user['role'] === 'admin' ? 'Quản trị viên' : 'Người dùng';
                                    ?>
                            <tr>
                                <td><?php echo $user['id']; ?></td>
                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?></td>
                                <td><span class="badge badge-<?php echo $roleClass; ?>"><?php echo $roleText; ?></span>
                                </td>
                                <td><?php echo date('d/m/Y H:i', strtotime($user['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card Layout -->
                <div class="mobile-card-list">
                    <?php foreach ($users as $user): 
                                $roleClass = $user['role'] === 'admin' ? 'danger' : 'info';
                                $roleText = $user['role'] === 'admin' ? 'Quản trị viên' : 'Người dùng';
                            ?>
                    <div class="mobile-card">
                        <div class="mobile-card-header">
                            <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($user['full_name']); ?></span>
                            <span class="badge badge-<?php echo $roleClass; ?>"><?php echo $roleText; ?></span>
                        </div>
                        <div class="mobile-card-content">
                            <div class="mobile-card-row">
                                <span>ID:</span>
                                <span><?php echo $user['id']; ?></span>
                            </div>
                            <div class="mobile-card-row">
                                <span>Username:</span>
                                <span><?php echo htmlspecialchars($user['username']); ?></span>
                            </div>
                            <div class="mobile-card-row">
                                <span>Email:</span>
                                <span><?php echo htmlspecialchars($user['email']); ?></span>
                            </div>
                            <div class="mobile-card-row">
                                <span>SĐT:</span>
                                <span><?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="mobile-card-row">
                                <span>Ngày đăng ký:</span>
                                <span><?php echo date('d/m/Y', strtotime($user['created_at'])); ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php
                    break;
                case 'vehicles':
                    ?>
            <div class="card">
                <h2 class="card-title"><i class="fas fa-car-side"></i> Lịch sử đỗ xe</h2>

                <?php if (empty($all_vehicles)): ?>
                <div style="text-align: center; padding: 2rem 0;">
                    <i class="fas fa-car-crash"
                        style="font-size: 3rem; color: #d1d5db; display: block; margin-bottom: 1rem;"></i>
                    <p>Không có lịch sử đỗ xe nào</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Biển số</th>
                                <th>Người dùng</th>
                                <th>RFID</th>
                                <th>Vị trí đỗ</th>
                                <th>Thời gian vào</th>
                                <th>Thời gian ra</th>
                                <th>Trạng thái</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_vehicles as $vehicle):
                                            $statusClass = ($vehicle['status'] === 'in_parking') ? 'danger' : 'info';
                                            $statusText = ($vehicle['status'] === 'in_parking') ? 'Đang đỗ' : 'Đã ra';
                                        ?>
                            <tr>
                                <td><?php echo $vehicle['id']; ?></td>
                                <td><?php echo htmlspecialchars($vehicle['license_plate']); ?></td>
                                <td><?php echo htmlspecialchars($vehicle['full_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($vehicle['rfid_tag'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($vehicle['slot_id'] ?? 'N/A'); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($vehicle['entry_time'])); ?></td>
                                <td><?php echo $vehicle['exit_time'] ? date('d/m/Y H:i', strtotime($vehicle['exit_time'])) : 'N/A'; ?>
                                </td>
                                <td><span
                                        class="badge badge-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mobile-card-list">
                    <?php foreach ($all_vehicles as $vehicle):
                                    $statusClass = ($vehicle['status'] === 'in_parking') ? 'danger' : 'info';
                                    $statusText = ($vehicle['status'] === 'in_parking') ? 'Đang đỗ' : 'Đã ra';
                                ?>
                    <div class="mobile-card">
                        <div class="mobile-card-header">
                            <span><i class="fas fa-car-side"></i>
                                <?php echo htmlspecialchars($vehicle['license_plate']); ?></span>
                            <span class="badge badge-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                        </div>
                        <div class="mobile-card-content">
                            <div class="mobile-card-row">
                                <span>Người dùng:</span>
                                <span><?php echo htmlspecialchars($vehicle['full_name'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="mobile-card-row">
                                <span>Vị trí:</span>
                                <span><?php echo htmlspecialchars($vehicle['slot_id'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="mobile-card-row">
                                <span>RFID:</span>
                                <span><?php echo htmlspecialchars($vehicle['rfid_tag'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="mobile-card-row">
                                <span>Vào:</span>
                                <span><?php echo date('d/m H:i', strtotime($vehicle['entry_time'])); ?></span>
                            </div>
                            <div class="mobile-card-row">
                                <span>Ra:</span>
                                <span><?php echo $vehicle['exit_time'] ? date('d/m H:i', strtotime($vehicle['exit_time'])) : 'N/A'; ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php
                    break;
                case 'bookings':
                    ?>
            <div class="card">
                <h2 class="card-title"><i class="fas fa-calendar-alt"></i> Lịch sử đặt chỗ</h2>

                <?php if (empty($bookings)): ?>
                <div style="text-align: center; padding: 2rem 0;">
                    <i class="fas fa-calendar-times"
                        style="font-size: 3rem; color: #d1d5db; display: block; margin-bottom: 1rem;"></i>
                    <p>Không có đặt chỗ nào</p>
                </div>
                <?php else: ?>
                <!-- Desktop Table -->
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Người dùng</th>
                                <th>Slot</th>
                                <th>Biển số</th>
                                <th>Thời gian bắt đầu</th>
                                <th>Thời gian kết thúc</th>
                                <th>Trạng thái đặt chỗ</th>
                                <th>Trạng thái thanh toán</th>
                                <th>Thành tiền</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bookings as $booking): 
                                        $bookingStatusClass = '';
                                        $paymentStatusClass = '';
                                        
                                        switch ($booking['status']) {
                                            case 'pending':
                                                $bookingStatusClass = 'warning';
                                                $bookingStatusText = 'Chờ xác nhận';
                                                break;
                                            case 'confirmed':
                                                $bookingStatusClass = 'success';
                                                $bookingStatusText = 'Đã xác nhận';
                                                break;
                                            case 'cancelled':
                                                $bookingStatusClass = 'danger';
                                                $bookingStatusText = 'Đã hủy';
                                                break;
                                            case 'completed':
                                                $bookingStatusClass = 'info';
                                                $bookingStatusText = 'Đã hoàn thành';
                                                break;
                                            default:
                                                $bookingStatusClass = 'warning';
                                                $bookingStatusText = 'Chờ xác nhận';
                                        }
                                        
                                        switch ($booking['payment_status']) {
                                            case 'pending':
                                                $paymentStatusClass = 'warning';
                                                $paymentStatusText = 'Chờ thanh toán';
                                                break;
                                            case 'completed':
                                                $paymentStatusClass = 'success';
                                                $paymentStatusText = 'Đã thanh toán';
                                                break;
                                            case 'failed':
                                                $paymentStatusClass = 'danger';
                                                $paymentStatusText = 'Thanh toán thất bại';
                                                break;
                                            case 'expired':
                                                $paymentStatusClass = 'danger';
                                                $paymentStatusText = 'Hết hạn';
                                                break;
                                            default:
                                                $paymentStatusClass = 'warning';
                                                $paymentStatusText = 'Chờ thanh toán';
                                        }
                                    ?>
                            <tr>
                                <td><?php echo $booking['id']; ?></td>
                                <td><?php echo htmlspecialchars($booking['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($booking['slot_id']); ?></td>
                                <td><?php echo htmlspecialchars($booking['license_plate']); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($booking['start_time'])); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($booking['end_time'])); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $bookingStatusClass; ?>">
                                        <?php echo $bookingStatusText; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($booking['status'] === 'cancelled'): ?>
                                    <span class="badge badge-danger">Đã hủy</span>
                                    <?php else: ?>
                                    <span class="badge badge-<?php echo $paymentStatusClass; ?>">
                                        <?php echo $paymentStatusText; ?>
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo number_format($booking['amount'], 0, ',', '.'); ?>₫</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card Layout -->
                <div class="mobile-card-list">
                    <?php foreach ($bookings as $booking): 
                                $bookingStatusClass = '';
                                $paymentStatusClass = '';
                                
                                switch ($booking['status']) {
                                    case 'pending':
                                        $bookingStatusClass = 'warning';
                                        $bookingStatusText = 'Chờ xác nhận';
                                        break;
                                    case 'confirmed':
                                        $bookingStatusClass = 'success';
                                        $bookingStatusText = 'Đã xác nhận';
                                        break;
                                    case 'cancelled':
                                        $bookingStatusClass = 'danger';
                                        $bookingStatusText = 'Đã hủy';
                                        break;
                                    case 'completed':
                                        $bookingStatusClass = 'info';
                                        $bookingStatusText = 'Đã hoàn thành';
                                        break;
                                    default:
                                        $bookingStatusClass = 'warning';
                                        $bookingStatusText = 'Chờ xác nhận';
                                }
                                
                                switch ($booking['payment_status']) {
                                    case 'pending':
                                        $paymentStatusClass = 'warning';
                                        $paymentStatusText = 'Chờ thanh toán';
                                        break;
                                    case 'completed':
                                        $paymentStatusClass = 'success';
                                        $paymentStatusText = 'Đã thanh toán';
                                        break;
                                    case 'failed':
                                        $paymentStatusClass = 'danger';
                                        $paymentStatusText = 'Thanh toán thất bại';
                                        break;
                                    case 'expired':
                                        $paymentStatusClass = 'danger';
                                        $paymentStatusText = 'Hết hạn';
                                        break;
                                    default:
                                        $paymentStatusClass = 'warning';
                                        $paymentStatusText = 'Chờ thanh toán';
                                }
                            ?>
                    <div class="mobile-card">
                        <div class="mobile-card-header">
                            <span><i class="fas fa-calendar"></i> Booking #<?php echo $booking['id']; ?></span>
                            <span
                                class="badge badge-<?php echo $bookingStatusClass; ?>"><?php echo $bookingStatusText; ?></span>
                        </div>
                        <div class="mobile-card-content">
                            <div class="mobile-card-row">
                                <span>Người dùng:</span>
                                <span><?php echo htmlspecialchars($booking['full_name']); ?></span>
                            </div>
                            <div class="mobile-card-row">
                                <span>Slot:</span>
                                <span><?php echo htmlspecialchars($booking['slot_id']); ?></span>
                            </div>
                            <div class="mobile-card-row">
                                <span>Biển số:</span>
                                <span><?php echo htmlspecialchars($booking['license_plate']); ?></span>
                            </div>
                            <div class="mobile-card-row">
                                <span>Thời gian:</span>
                                <span><?php echo date('d/m H:i', strtotime($booking['start_time'])); ?> -
                                    <?php echo date('H:i', strtotime($booking['end_time'])); ?></span>
                            </div>
                            <div class="mobile-card-row">
                                <span>Thanh toán:</span>
                                <span
                                    class="badge badge-<?php echo $paymentStatusClass; ?>"><?php echo $paymentStatusText; ?></span>
                            </div>
                            <div class="mobile-card-row">
                                <span>Thành tiền:</span>
                                <span><strong><?php echo number_format($booking['amount'], 0, ',', '.'); ?>₫</strong></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php
                    break;
                    
                case 'payments':
                    ?>
            <div class="card">
                <h2 class="card-title"><i class="fas fa-money-bill-wave"></i> Lịch sử thanh toán</h2>

                <?php if (empty($payments)): ?>
                <div style="text-align: center; padding: 2rem 0;">
                    <i class="fas fa-money-bill-wave"
                        style="font-size: 3rem; color: #d1d5db; display: block; margin-bottom: 1rem;"></i>
                    <p>Không có thanh toán nào</p>
                </div>
                <?php else: ?>
                <!-- Desktop Table -->
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Người dùng</th>
                                <th>Loại</th>
                                <th>Mã tham chiếu</th>
                                <th>Số tiền</th>
                                <th>Thời gian thanh toán</th>
                                <th>Trạng thái</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): 
                                        $statusClass = '';
                                        $statusText = '';
                                        
                                        switch ($payment['status']) {
                                            case 'pending':
                                                $statusClass = 'warning';
                                                $statusText = 'Chờ thanh toán';
                                                break;
                                            case 'completed':
                                                $statusClass = 'success';
                                                $statusText = 'Đã thanh toán';
                                                break;
                                            case 'failed':
                                                $statusClass = 'danger';
                                                $statusText = 'Thanh toán thất bại';
                                                break;
                                            case 'expired':
                                                $statusClass = 'danger';
                                                $statusText = 'Hết hạn';
                                                break;
                                            default:
                                                $statusClass = 'warning';
                                                $statusText = 'Chờ thanh toán';
                                        }
                                        
                                        $paymentType = '';
                                        if ($payment['booking_id']) {
                                            $paymentType = 'Đặt chỗ #' . $payment['booking_id'];
                                        } elseif ($payment['vehicle_id']) {
                                            $paymentType = 'Xe ra #' . $payment['vehicle_id'];
                                            if ($payment['license_plate']) {
                                                $paymentType .= ' (' . $payment['license_plate'] . ')';
                                            }
                                        } else {
                                            $paymentType = 'Khác';
                                        }
                                    ?>
                            <tr>
                                <td><?php echo $payment['id']; ?></td>
                                <td><?php echo $payment['full_name'] ? htmlspecialchars($payment['full_name']) : 'N/A'; ?>
                                </td>
                                <td><?php echo $paymentType; ?></td>
                                <td><?php echo htmlspecialchars($payment['payment_ref']); ?></td>
                                <td><?php echo number_format($payment['amount'], 0, ',', '.'); ?>₫</td>
                                <td><?php echo $payment['payment_time'] ? date('d/m/Y H:i', strtotime($payment['payment_time'])) : 'N/A'; ?>
                                </td>
                                <td><span
                                        class="badge badge-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card Layout -->
                <div class="mobile-card-list">
                    <?php foreach ($payments as $payment): 
                                $statusClass = '';
                                $statusText = '';
                                
                                switch ($payment['status']) {
                                    case 'pending':
                                        $statusClass = 'warning';
                                        $statusText = 'Chờ thanh toán';
                                        break;
                                    case 'completed':
                                        $statusClass = 'success';
                                        $statusText = 'Đã thanh toán';
                                        break;
                                    case 'failed':
                                        $statusClass = 'danger';
                                        $statusText = 'Thanh toán thất bại';
                                        break;
                                    case 'expired':
                                        $statusClass = 'danger';
                                        $statusText = 'Hết hạn';
                                        break;
                                    default:
                                        $statusClass = 'warning';
                                        $statusText = 'Chờ thanh toán';
                                }
                                
                                $paymentType = '';
                                if ($payment['booking_id']) {
                                    $paymentType = 'Đặt chỗ #' . $payment['booking_id'];
                                } elseif ($payment['vehicle_id']) {
                                    $paymentType = 'Xe ra #' . $payment['vehicle_id'];
                                    if ($payment['license_plate']) {
                                        $paymentType .= ' (' . $payment['license_plate'] . ')';
                                    }
                                } else {
                                    $paymentType = 'Khác';
                                }
                            ?>
                    <div class="mobile-card">
                        <div class="mobile-card-header">
                            <span><i class="fas fa-money-bill"></i> Payment #<?php echo $payment['id']; ?></span>
                            <span class="badge badge-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                        </div>
                        <div class="mobile-card-content">
                            <div class="mobile-card-row">
                                <span>Người dùng:</span>
                                <span><?php echo $payment['full_name'] ? htmlspecialchars($payment['full_name']) : 'N/A'; ?></span>
                            </div>
                            <div class="mobile-card-row">
                                <span>Loại:</span>
                                <span><?php echo $paymentType; ?></span>
                            </div>
                            <div class="mobile-card-row">
                                <span>Mã ref:</span>
                                <span><?php echo htmlspecialchars($payment['payment_ref']); ?></span>
                            </div>
                            <div class="mobile-card-row">
                                <span>Số tiền:</span>
                                <span><strong><?php echo number_format($payment['amount'], 0, ',', '.'); ?>₫</strong></span>
                            </div>
                            <div class="mobile-card-row">
                                <span>Thời gian:</span>
                                <span><?php echo $payment['payment_time'] ? date('d/m H:i', strtotime($payment['payment_time'])) : 'N/A'; ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php
                    break;
                    
                case 'logs':
                    ?>
            <div class="card">
                <h2 class="card-title"><i class="fas fa-history"></i> Nhật ký hệ thống</h2>

                <?php if (empty($logs)): ?>
                <div style="text-align: center; padding: 2rem 0;">
                    <i class="fas fa-history"
                        style="font-size: 3rem; color: #d1d5db; display: block; margin-bottom: 1rem;"></i>
                    <p>Không có nhật ký nào</p>
                </div>
                <?php else: ?>
                <!-- Desktop Table -->
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Sự kiện</th>
                                <th>Mô tả</th>
                                <th>Người dùng</th>
                                <th>IP</th>
                                <th>Thời gian</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo $log['id']; ?></td>
                                <td><?php echo htmlspecialchars($log['event_type']); ?></td>
                                <td><?php echo htmlspecialchars($log['description']); ?></td>
                                <td><?php echo $log['username'] ? htmlspecialchars($log['username']) : 'N/A'; ?></td>
                                <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                                <td><?php echo date('d/m/Y H:i:s', strtotime($log['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card Layout -->
                <div class="mobile-card-list">
                    <?php foreach ($logs as $log): ?>
                    <div class="mobile-card">
                        <div class="mobile-card-header">
                            <span><i class="fas fa-clipboard-list"></i>
                                <?php echo htmlspecialchars($log['event_type']); ?></span>
                            <span class="badge badge-info">#<?php echo $log['id']; ?></span>
                        </div>
                        <div class="mobile-card-content">
                            <div class="mobile-card-row">
                                <span>Mô tả:</span>
                                <span><?php echo htmlspecialchars($log['description']); ?></span>
                            </div>
                            <div class="mobile-card-row">
                                <span>Người dùng:</span>
                                <span><?php echo $log['username'] ? htmlspecialchars($log['username']) : 'N/A'; ?></span>
                            </div>
                            <div class="mobile-card-row">
                                <span>IP:</span>
                                <span><?php echo htmlspecialchars($log['ip_address']); ?></span>
                            </div>
                            <div class="mobile-card-row">
                                <span>Thời gian:</span>
                                <span><?php echo date('d/m/Y H:i', strtotime($log['created_at'])); ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php
                    break;
                    
                case 'settings':
                    ?>
            <div class="card">
                <h2 class="card-title"><i class="fas fa-cogs"></i> Cài đặt hệ thống</h2>

                <div style="background: #f8fafc; padding: 1.5rem; border-radius: 10px; margin-bottom: 2rem;">

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                        <div>
                            <strong>Thời gian hệ thống:</strong>
                            <p id="clock" style="color: var(--primary); font-family: monospace; font-size: 1.1rem;">Đang
                                tải...</p>
                        </div>
                        <div>
                            <strong>Phát triển bởi:</strong>
                            <p>PHUCX</p>
                        </div>
                    </div>
                </div>

                <div style="background: #fff; padding: 1.5rem; border-radius: 10px;">
                    <h3 style="margin-bottom: 1rem;">
                        <i class="fas fa-bullhorn"></i> Gửi thông báo
                    </h3>

                    <form action="admin.php?tab=settings" method="post">
                        <input type="hidden" name="action" value="send_notification">

                        <div class="form-group">
                            <label for="notification_title" class="form-label">Tiêu đề</label>
                            <input type="text" id="notification_title" name="notification_title" class="form-control"
                                maxlength="255">
                        </div>

                        <div class="form-group">
                            <label for="notification_message" class="form-label">Nội dung</label>
                            <textarea id="notification_message" name="notification_message" class="form-control"
                                rows="5" required placeholder="Nhập nội dung chi tiết của thông báo..."></textarea>
                        </div>

                        <div class="form-group">
                            <label for="notification_type" class="form-label">Loại</label>
                            <select id="notification_type" name="notification_type" class="form-control" required>
                                <option value="info">Normal</option>
                                <option value="warning">Cảnh báo</option>
                                <option value="error">Khẩn cấp</option>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-paper-plane"></i> Gửi thông báo
                        </button>
                    </form>

                    <?php 
                            $current_notification = null;
                            try {
                                $stmt = $pdo->query("SELECT title, message, type, created_at FROM notifications ORDER BY created_at DESC LIMIT 1");
                                $current_notification = $stmt->fetch();
                            } catch (Exception $e) {
                                // Ignore error
                            }
                            ?>

                    <?php if ($current_notification): ?>
                    <div
                        style="margin-top: 25px; padding: 20px; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0;">
                        <div
                            style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                            <h4 style="margin: 0; color: #374151; font-size: 1rem;">
                                Thông báo hiện tại:
                            </h4>
                            <form action="admin.php?tab=settings" method="post" style="margin: 0;"
                                onsubmit="confirmDelete(event)">
                                <input type="hidden" name="action" value="delete_notification">
                                <button type="submit" class="btn btn-danger"
                                    style="padding: 0.4rem 0.8rem; font-size: 0.875rem;">
                                    <i class="fas fa-trash-alt"></i> Xóa
                                </button>
                            </form>
                        </div>

                        <div
                            style="background: white; padding: 15px; border-radius: 8px; border-left: 3px solid #3b82f6;">
                            <h5 style="margin: 0 0 8px 0; color: #1f2937; font-weight: 600;">
                                <?php echo htmlspecialchars($current_notification['title']); ?>
                            </h5>
                            <p style="margin: 0 0 10px 0; color: #4b5563; line-height: 1.4;">
                                <?php echo nl2br(htmlspecialchars($current_notification['message'])); ?>
                            </p>
                            <p style="margin: 0; font-size: 0.875rem; color: #6b7280;">
                                Gửi lúc:
                                <span id="notification-time"
                                    data-timestamp="<?php echo htmlspecialchars($current_notification['created_at']); ?>">
                                    <?php echo date('d/m/Y - H:i', strtotime($current_notification['created_at'])); ?>
                                </span>
                                <span style="margin-left: 15px;">
                                    <?php 
                                            $typeText = 'Loại: Normal';
                                            if ($current_notification['type'] === 'warning') $typeText = 'Loại: Cảnh báo';
                                            if ($current_notification['type'] === 'error') $typeText = 'Loại: Khẩn cấp';
                                            echo $typeText;
                                            ?>
                                </span>
                            </p>
                        </div>
                    </div>
                    <?php else: ?>
                    <div
                        style="margin-top: 25px; padding: 20px; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0;">
                        <h4 style="margin: 0 0 12px 0; color: #374151; font-size: 1rem;">
                            Thông báo hiện tại:
                        </h4>
                        <div
                            style="background: white; padding: 15px; border-radius: 8px; text-align: center; color: #6b7280;">
                            <i class="fas fa-bell-slash" style="font-size: 2rem; color: #d1d5db;margin-top: 20px;"></i>
                            <p style="margin: 30px;">Không có thông báo nào</p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php
                    break;
            }
            ?>
        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    // Mobile Menu Functions - SIMPLIFIED
    function toggleMobileMenu() {
        const mobileMenu = document.getElementById('mobileMenu');
        const overlay = document.querySelector('.menu-overlay');
        const toggle = document.querySelector('.mobile-menu-toggle i');

        // Toggle menu visibility
        mobileMenu.classList.toggle('show');
        overlay.classList.toggle('show');

        // Toggle hamburger icon
        if (mobileMenu.classList.contains('show')) {
            toggle.classList.remove('fa-bars');
            toggle.classList.add('fa-times');
            // Prevent body scroll when menu is open
            document.body.style.overflow = 'hidden';
        } else {
            toggle.classList.remove('fa-times');
            toggle.classList.add('fa-bars');
            // Restore body scroll when menu is closed
            document.body.style.overflow = '';
        }
    }

    function closeMobileMenu() {
        const mobileMenu = document.getElementById('mobileMenu');
        const overlay = document.querySelector('.menu-overlay');
        const toggle = document.querySelector('.mobile-menu-toggle i');

        mobileMenu.classList.remove('show');
        overlay.classList.remove('show');
        toggle.classList.remove('fa-times');
        toggle.classList.add('fa-bars');
        document.body.style.overflow = '';
    }

    // Close mobile menu when clicking outside
    document.addEventListener('click', function(event) {
        const mobileMenu = document.getElementById('mobileMenu');
        const toggle = document.querySelector('.mobile-menu-toggle');

        // Check if click is outside menu and toggle button
        if (!mobileMenu.contains(event.target) && !toggle.contains(event.target)) {
            if (mobileMenu.classList.contains('show')) {
                closeMobileMenu();
            }
        }
    });

    // Close menu when clicking on navigation links
    document.querySelectorAll('.mobile-menu-link, .mobile-menu-btn').forEach(link => {
        link.addEventListener('click', function() {
            // Add small delay to allow navigation to start
            setTimeout(() => {
                closeMobileMenu();
            }, 100);
        });
    });

    // Handle window resize
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
            closeMobileMenu();
        }
    });

    function confirmDelete(event) {
        // Ngăn form submit mặc định
        event.preventDefault();

        Swal.fire({
            title: 'Xác nhận xóa?',
            text: 'Bạn có chắc chắn muốn xóa tất cả thông báo?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6b7280',
            confirmButtonText: '<i class="fas fa-trash-alt"></i> Xóa',
            cancelButtonText: 'Hủy',
            allowOutsideClick: false,
            background: '#ffffff',
            color: '#374151'
        }).then((result) => {
            if (result.isConfirmed) {
                // Thực hiện submit form
                event.target.closest('form').submit();
            }
        });

        return false; // Đảm bảo ngăn form submit
    }

    // Update clock every second
    function updateClock() {
        const now = new Date();
        const time = now.toLocaleTimeString("vi-VN", {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false
        });
        const date = now.toLocaleDateString("vi-VN", {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit'
        });

        const formatted = `${time} ${date}`;
        const clockEl = document.getElementById("clock");
        if (clockEl) {
            clockEl.innerText = formatted;
        }
    }

    // Chạy ngay khi load trang
    document.addEventListener('DOMContentLoaded', function() {
        updateClock();
        setInterval(updateClock, 1000);
    });

    // Modal functions
    function openEditModal(slotId, status) {
        document.getElementById('slot_id').value = slotId;
        document.getElementById('status').value = status;

        // Only allow changing status if slot is empty or in maintenance
        if (status !== 'empty' && status !== 'maintenance') {
            Swal.fire({
                title: 'Không thể thay đổi!',
                text: 'Slot đang được sử dụng, không thể thay đổi trạng thái!',
                icon: 'warning',
                confirmButtonText: 'OK',
                confirmButtonColor: '#f59e0b'
            });
            return;
        }

        document.getElementById('editSlotModal').style.display = 'block';
    }

    function closeModal() {
        document.getElementById('editSlotModal').style.display = 'none';
    }

    // Close modal when clicking outside
    window.onclick = function(event) {
        const modal = document.getElementById('editSlotModal');
        if (event.target === modal) {
            closeModal();
        }
    }

    // Flash message display with SweetAlert2
    <?php
        $flash = get_flash_message();
        if ($flash):
        ?>
    document.addEventListener('DOMContentLoaded', function() {
        const flashType = '<?php echo $flash['type']; ?>';
        const message = '<?php echo addslashes($flash['message']); ?>';

        if (flashType === 'success') {
            const Toast = Swal.mixin({
                toast: true,
                position: "top-end",
                showConfirmButton: false,
                timer: 2500,
                timerProgressBar: true,
                background: '#d1fae5',
                color: '#065f46',
                iconColor: '#10b981',
                didOpen: (toast) => {
                    toast.onmouseenter = Swal.stopTimer;
                    toast.onmouseleave = Swal.resumeTimer;
                }
            });

            Toast.fire({
                icon: "success",
                title: message
            });

        } else {
            Swal.fire({
                title: "Thông báo",
                text: message,
                icon: flashType === 'error' ? 'error' : 'info',
                confirmButtonText: "OK",
                confirmButtonColor: flashType === 'error' ? '#ef4444' : '#2563eb',
                background: '#ffffff',
                color: '#374151'
            });
        }
    });
    <?php endif; ?>

    // JavaScript for Revenue Charts
    Chart.register(ChartDataLabels);

    let myDistributionChart;
    let myTrendChart;
    let myRankingChart;

    function switchTab(tabName) {
        const tabs = ['distribution', 'trend', 'ranking'];
        tabs.forEach(tab => {
            const container = document.getElementById(`chart-${tab}`);
            const navItem = document.getElementById(`tab-${tab}`);
            if (container) container.style.display = 'none';
            if (navItem) navItem.classList.remove('active');
        });

        const activeContainer = document.getElementById(`chart-${tabName}`);
        const activeNav = document.getElementById(`tab-${tabName}`);
        if (activeContainer) activeContainer.style.display = 'block';
        if (activeNav) activeNav.classList.add('active');

        // Load chart for the selected tab
        switch (tabName) {
            case 'distribution':
                loadRevenueDistributionChart();
                break;
            case 'trend':
                loadRevenueTrendChart();
                break;
            case 'ranking':
                loadRevenueRankingChart();
                break;
        }
    }

    function loadRevenueDistributionChart() {
        if (myDistributionChart) myDistributionChart.destroy();
        fetch('api/get_revenue_distribution.php')
            .then(response => response.json())
            .then(data => {
                const ctx = document.getElementById('revenueDistributionChart').getContext('2d');
                myDistributionChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: 'Doanh thu',
                            data: data.values,
                            borderColor: '#2563eb',
                            backgroundColor: 'rgba(37, 99, 235, 0.2)',
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });
            });
    }

    function loadRevenueTrendChart() {
        if (myTrendChart) myTrendChart.destroy();
        fetch('api/get_revenue_trend.php')
            .then(response => response.json())
            .then(data => {
                const ctx = document.getElementById('revenueTrendChart').getContext('2d');
                myTrendChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: 'Doanh thu 7 ngày qua',
                            data: data.values,
                            backgroundColor: '#10b981',
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });
            });
    }

    function loadRevenueRankingChart() {
        if (myRankingChart) myRankingChart.destroy();
        fetch('api/get_revenue_ranking.php')
            .then(response => response.json())
            .then(data => {
                const ctx = document.getElementById('revenueRankingChart').getContext('2d');
                myRankingChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: 'Chi phí đã chi (₫)',
                            data: data.values,
                            backgroundColor: '#ef4444',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        scales: {
                            x: {
                                beginAtZero: true
                            }
                        }
                    }
                });
            });
    }

    // Initial load
    document.addEventListener('DOMContentLoaded', () => {
        const currentTab = '<?php echo $tab; ?>';
        if (currentTab === 'revenue') {
            const urlParams = new URLSearchParams(window.location.search);
            const chartTab = urlParams.get('chart_tab') || 'distribution';
            switchTab(chartTab);
        }
    });
    </script>
</body>

</html>