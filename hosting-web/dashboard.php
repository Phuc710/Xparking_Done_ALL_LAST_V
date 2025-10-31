<?php
// dashboard.php - User dashboard (Supabase PostgreSQL)
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_login();

// Lấy thông báo mới nhất từ admin
function get_latest_notification() {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT id, title, message, type, created_at 
                              FROM notifications 
                              WHERE target_user_id IS NULL 
                              ORDER BY created_at DESC 
                              LIMIT 1");
        $stmt->execute();
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Get notification error: " . $e->getMessage());
        return null;
    }
}

// Lấy thống kê thực tế của user
function get_user_statistics($user_id) {
    global $pdo;
    
    try {
        $stats = array();
        
        // Tổng số lần đặt chỗ
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE user_id = ?");
        $stmt->execute(array($user_id));
        $stats['total_bookings'] = $stmt->fetchColumn();
        
        // Tổng số lần đỗ xe (vehicles)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE user_id = ?");
        $stmt->execute(array($user_id));
        $stats['total_parkings'] = $stmt->fetchColumn();
        
        // Tổng giờ đã book
        $stmt = $pdo->prepare("SELECT SUM(TIMESTAMPDIFF(HOUR, start_time, end_time)) as total_hours 
                              FROM bookings 
                              WHERE user_id = ? AND status IN ('confirmed', 'completed')");
        $stmt->execute(array($user_id));
        $stats['total_hours'] = $stmt->fetchColumn() ?: 0;
        
        // Tổng chi phí
        $stmt = $pdo->prepare("SELECT SUM(amount) as total_spent 
                              FROM payments 
                              WHERE user_id = ? AND status = 'completed'");
        $stmt->execute(array($user_id));
        $stats['total_spent'] = $stmt->fetchColumn() ?: 0;
        
        // Số booking hiện tại (confirmed)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE user_id = ? AND status = 'confirmed'");
        $stmt->execute(array($user_id));
        $stats['active_bookings'] = $stmt->fetchColumn();
        
        return $stats;
        
    } catch (PDOException $e) {
        error_log("Get user statistics error: " . $e->getMessage());
        return array(
            'total_bookings' => 0,
            'total_parkings' => 0,
            'total_hours' => 0,
            'total_spent' => 0,
            'active_bookings' => 0
        );
    }
}

function get_slots_display_status() {
    global $pdo;

    try {
        // The query prioritizes the statuses in a specific order:
        // 1. 'occupied': A vehicle is physically parked.
        // 2. 'reserved': A booking is active for the current time.
        // 3. 'maintenance': The slot is marked for maintenance.
        // 4. 'empty': The default status if no other conditions are met.
        $stmt = $pdo->prepare("
            SELECT
                ps.id,
                ps.status AS predefined_status,
                v.license_plate AS vehicle_license,
                v.status AS vehicle_status,
                b.license_plate AS booking_license,
                b.start_time AS booking_start,
                b.end_time AS booking_end,
                b.status AS booking_status,
                CASE 
                    WHEN v.id IS NOT NULL AND v.status = 'in_parking' THEN 'occupied'
                    WHEN b.id IS NOT NULL AND b.status = 'confirmed' AND NOW() BETWEEN b.start_time AND b.end_time THEN 'reserved'
                    WHEN ps.status = 'maintenance' THEN 'maintenance'
                    ELSE 'empty'
                END AS actual_status
            FROM parking_slots ps
            LEFT JOIN vehicles v ON ps.id = v.slot_id AND v.status = 'in_parking'
            LEFT JOIN bookings b ON ps.id = b.slot_id AND b.status = 'confirmed' AND NOW() BETWEEN b.start_time AND b.end_time
            ORDER BY ps.id
        ");
        $stmt->execute();
        
        return $stmt->fetchAll();
        
    } catch (PDOException $e) {
        error_log("Get slots display error: " . $e->getMessage());
        return array();
    }
}
// Lấy xe của user
function get_user_vehicles($user_id) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT v.*, ps.id as slot_id 
                              FROM vehicles v 
                              LEFT JOIN parking_slots ps ON v.slot_id = ps.id
                              WHERE v.user_id = ? 
                              ORDER BY v.entry_time DESC");
        $stmt->execute(array($user_id));
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Get user vehicles error: " . $e->getMessage());
        return array();
    }
}

$user = get_user($_SESSION['user_id']);
$tab = $_GET['tab'] ?? 'overview';

// Xử lý các hành động POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'update_profile':
            $email = $_POST['email'] ?? '';
            $full_name = $_POST['full_name'] ?? '';
            $phone = $_POST['phone'] ?? '';
            
            if (update_user_profile($_SESSION['user_id'], $email, $full_name, $phone)) {
                set_flash_message('success', 'Cập nhật thông tin thành công!');
            } else {
                set_flash_message('error', 'Có lỗi xảy ra khi cập nhật thông tin!');
            }
            redirect('dashboard.php?tab=profile');
            break;
            
        case 'cancel_payment':
            $payment_ref = $_POST['payment_ref'] ?? '';
            
            if (empty($payment_ref)) {
                set_flash_message('error', 'Thiếu mã thanh toán!');
                redirect('dashboard.php?tab=bookings');
                break;
            }
            
            $result = cancel_payment($payment_ref, $_SESSION['user_id']);
            
            if ($result['success']) {
                set_flash_message('success', $result['message']);
            } else {
                set_flash_message('error', $result['message']);
            }
            redirect('dashboard.php?tab=bookings');
            break;
            
        case 'change_password':
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            
            if ($new_password !== $confirm_password) {
                set_flash_message('error', 'Mật khẩu xác nhận không khớp!');
                redirect('dashboard.php?tab=profile');
                break;
            }
            
            $result = change_user_password($_SESSION['user_id'], $current_password, $new_password);
            
            if ($result['success']) {
                set_flash_message('success', $result['message']);
            } else {
                set_flash_message('error', $result['message']);
            }
            redirect('dashboard.php?tab=profile');
            break;
            
        case 'create_booking':
            $slot_id = $_POST['slot_id'] ?? '';
            $license_plate = trim(strtoupper($_POST['license_plate'] ?? ''));
            $start_date = $_POST['start_date'] ?? '';
            $start_time = $_POST['start_time'] ?? '';
            $duration = intval($_POST['duration'] ?? 1);
            
            // Validate các trường bắt buộc
            if (empty($slot_id) || empty($license_plate) || empty($start_date) || empty($start_time)) {
                set_flash_message('error', 'Vui lòng điền đầy đủ thông tin!');
                redirect('dashboard.php?tab=booking');
                break;
            }
            
            // Validate định dạng biển số xe Việt Nam (99A-99999)
            if (!preg_match('/^[0-9]{2}[A-Z]{1,2}[0-9]{4,5}$/', $license_plate)) {
                set_flash_message('error', 'Định dạng biển số xe không đúng! (VD: 77A77777)');
                redirect('dashboard.php?tab=booking');
                break;
            }
            
            // Validate thời gian đỗ
            if ($duration < 1 || $duration > 24) {
                set_flash_message('error', 'Thời gian đỗ phải từ 1 đến 24 giờ!');
                redirect('dashboard.php?tab=booking');
                break;
            }
            
            try {
                // Tạo thời gian bắt đầu và kết thúc
                $start_datetime = new DateTime("$start_date $start_time");
                $end_datetime = clone $start_datetime;
                $end_datetime->add(new DateInterval("PT{$duration}H"));
                
                // Kiểm tra thời gian đặt chỗ không được trong quá khứ
                $now = new DateTime();
                if ($start_datetime < $now) {
                    set_flash_message('error', 'Thời gian đặt chỗ không được trong quá khứ!');
                    redirect('dashboard.php?tab=booking');
                    break;
                }
                
                // Format cho database
                $start_time_db = $start_datetime->format('Y-m-d H:i:s');
                $end_time_db = $end_datetime->format('Y-m-d H:i:s');
                
                // Tạo booking
                $result = create_booking($_SESSION['user_id'], $slot_id, $license_plate, $start_time_db, $end_time_db);
                
                if ($result['success']) {
                    set_flash_message('success', 'Đặt chỗ thành công! Vui lòng thanh toán trong vòng 10 phút.');
                    redirect('dashboard.php?tab=payment&ref=' . $result['payment_ref']);
                } else {
                    set_flash_message('error', $result['message']);
                    redirect('dashboard.php?tab=booking');
                }
                
            } catch (Exception $e) {
                error_log("Booking form error: " . $e->getMessage());
                set_flash_message('error', 'Lỗi xử lý thời gian. Vui lòng kiểm tra lại!');
                redirect('dashboard.php?tab=booking');
            }
            break;
            
        case 'cancel_booking':
            $booking_id = $_POST['booking_id'] ?? '';
            
            if (empty($booking_id)) {
                set_flash_message('error', 'Booking ID không hợp lệ!');
                redirect('dashboard.php?tab=bookings');
                break;
            }
            
            try {
                global $pdo;
                
                // Kiểm tra booking thuộc về user
                $stmt = $pdo->prepare("SELECT status FROM bookings WHERE id = ? AND user_id = ?");
                $stmt->execute(array($booking_id, $_SESSION['user_id']));
                $booking = $stmt->fetch();
                
                if (!$booking) {
                    set_flash_message('error', 'Booking không tồn tại hoặc không thuộc về bạn!');
                    redirect('dashboard.php?tab=bookings');
                    break;
                }
                
                if ($booking['status'] === 'completed') {
                    set_flash_message('error', 'Không thể hủy booking đã hoàn thành!');
                    redirect('dashboard.php?tab=bookings');
                    break;
                }
                
                $stmt = $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?");
                $stmt->execute(array($booking_id));
                
                // Hủy payment liên quan nếu có
                $stmt = $pdo->prepare("UPDATE payments SET status = 'cancelled' WHERE booking_id = ?");
                $stmt->execute(array($booking_id));
                
                set_flash_message('success', 'Hủy booking thành công!');
                
            } catch (PDOException $e) {
                error_log("Cancel booking error: " . $e->getMessage());
                set_flash_message('error', 'Lỗi hệ thống. Vui lòng thử lại sau!');
            }
            
            redirect('dashboard.php?tab=bookings');
            break;
    }
}

// Lấy dữ liệu theo tab hiện tại
switch ($tab) {
    case 'bookings':
        $bookings = get_user_bookings($_SESSION['user_id']);
        break;
    case 'overview':
        $latest_notification = get_latest_notification();
        $user_stats = get_user_statistics($_SESSION['user_id']);
        $slots = get_slots_display_status();
        break;
    case 'booking':
        $available_slots = get_available_slots();
        break;
    case 'vehicles':
        $user_vehicles = get_user_vehicles($_SESSION['user_id']);
        break;
        
    case 'payment':
        $payment_ref = $_GET['ref'] ?? '';
        $qr_data = null;

        if ($payment_ref) {
            try {
                // FIX: Kiểm tra payment status trước khi tạo QR
                $stmt = $pdo->prepare("SELECT id, status, TIMESTAMPDIFF(MINUTE, created_at, NOW()) as minutes_elapsed 
                                      FROM payments WHERE payment_ref = ?");
                $stmt->execute([$payment_ref]);
                $payment = $stmt->fetch();
                
                if ($payment) {
                    // Kiểm tra trạng thái
                    if ($payment['status'] === 'completed') {
                        set_flash_message('success', 'Thanh toán đã được hoàn thành!');
                        redirect('dashboard.php?tab=bookings');
                        break;
                    }
                    
                    if (in_array($payment['status'], ['expired', 'cancelled', 'failed'])) {
                        // Tạo QR mới nếu hết hạn
                        if ($payment['status'] === 'expired' && $payment['minutes_elapsed'] >= QR_EXPIRE_MINUTES) {
                            // Reset payment thành pending và tạo QR mới
                            $stmt = $pdo->prepare("UPDATE payments SET status = 'pending', created_at = NOW() WHERE id = ?");
                            $stmt->execute([$payment['id']]);
                            
                            $payment_id = $payment['id'];
                            $qr_data = generate_payment_qr($payment_id);
                        } else {
                            set_flash_message('error', 'Thanh toán đã bị hủy hoặc thất bại!');
                            redirect('dashboard.php?tab=bookings');
                            break;
                        }
                    } else if ($payment['status'] === 'pending') {
                        if ($payment['minutes_elapsed'] < QR_EXPIRE_MINUTES) {
                            $payment_id = $payment['id'];
                            $qr_data = generate_payment_qr($payment_id);
                        } else {
                            // Hết hạn - tạo QR mới
                            $stmt = $pdo->prepare("UPDATE payments SET created_at = NOW() WHERE id = ?");
                            $stmt->execute([$payment['id']]);
                            
                            $payment_id = $payment['id'];
                            $qr_data = generate_payment_qr($payment_id);
                        }
                    }
                } else {
                    set_flash_message('error', 'Không tìm thấy thanh toán!');
                    redirect('dashboard.php?tab=bookings');
                }
            } catch (PDOException $e) {
                error_log("Payment error: " . $e->getMessage());
                set_flash_message('error', 'Lỗi hệ thống!');
                redirect('dashboard.php?tab=bookings');
            }
        } else {
            set_flash_message('error', 'Thiếu mã thanh toán.');
            redirect('dashboard.php?tab=bookings');
        }
        break;
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>XParking</title>

    <link rel="shortcut icon" href="/LOGO.gif" type="image/x-icon">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="styles/dashborad.css">

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
            <ul class="desktop-nav">
                <li><a href="index.php" class="nav-link">Trang chủ</a></li>
                <li><a href="dashboard.php?tab=booking" class="nav-link">Đặt chỗ</a></li>
                <?php if (is_admin()): ?>
                <li><a href="admin.php" class="btn user-page">Quản trị</a></li>
                <?php endif; ?>
                <li><a href="index.php?action=logout" class="btn logout">Đăng xuất</a></li>
            </ul>

            <!-- Mobile Hamburger -->
            <button class="mobile-menu-toggle" onclick="toggleMobileMenu()" aria-label="Toggle menu">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </header>

    <!-- Mobile Menu -->
    <div class="mobile-menu" id="mobileMenu">
        <div class="mobile-menu-header">
            <div class="user-avatar">
                <i class="fas fa-user" style="font-size: 1.5rem;"></i>
            </div>
            <h3><?php echo htmlspecialchars($_SESSION['user_fullname']); ?></h3>
            <p><?php echo htmlspecialchars($_SESSION['username']); ?></p>
        </div>

        <div class="mobile-menu-nav">
            <a href="dashboard.php?tab=overview" class="<?php echo $tab === 'overview' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i> Tổng quan
            </a>
            <a href="dashboard.php?tab=booking" class="<?php echo $tab === 'booking' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-plus"></i> Đặt chỗ mới
            </a>
            <a href="dashboard.php?tab=bookings" class="<?php echo $tab === 'bookings' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-alt"></i> Lịch sử đặt chỗ
            </a>
            <a href="dashboard.php?tab=profile" class="<?php echo $tab === 'profile' ? 'active' : ''; ?>">
                <i class="fas fa-user-cog"></i> Thông tin cá nhân
            </a>

            <div style="border-top: 1px solid #e5e7eb; margin: 1rem 0; padding-top: 1rem;">
                <a href="index.php">
                    <i class="fas fa-home"></i> Trang chủ
                </a>
                <?php if (is_admin()): ?>
                <a href="admin.php">
                    <i class="fas fa-shield-alt"></i> Quản trị
                </a>
                <?php endif; ?>
                <a href="index.php?action=logout">
                    <i class="fas fa-sign-out-alt"></i> Đăng xuất
                </a>
            </div>
        </div>
    </div>

    <main class="container dashboard">
        <!-- Desktop Sidebar -->
        <aside class="sidebar">
            <div style="text-align: center; margin-bottom: 1.5rem;">
                <div
                    style="width: 80px; height: 80px; background-color: #e0e7ff; border-radius: 50%; display: flex; justify-content: center; align-items: center; margin: 0 auto 1rem;">
                    <i class="fas fa-user" style="font-size: 2rem; color: var(--primary);"></i>
                </div>
                <h3><?php echo htmlspecialchars($_SESSION['user_fullname']); ?></h3>
                <p style="color: var(--gray); font-size: 0.875rem;">
                    <?php echo htmlspecialchars($_SESSION['username']); ?></p>
            </div>

            <ul class="sidebar-menu">
                <li class="sidebar-item">
                    <a href="dashboard.php?tab=overview"
                        class="sidebar-link <?php echo $tab === 'overview' ? 'active' : ''; ?>">
                        <i class="fas fa-tachometer-alt"></i> Tổng quan
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="dashboard.php?tab=booking"
                        class="sidebar-link <?php echo $tab === 'booking' ? 'active' : ''; ?>">
                        <i class="fas fa-calendar-plus"></i> Đặt chỗ mới
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="dashboard.php?tab=bookings"
                        class="sidebar-link <?php echo $tab === 'bookings' ? 'active' : ''; ?>">
                        <i class="fas fa-calendar-alt"></i> Lịch sử đặt chỗ
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="dashboard.php?tab=profile"
                        class="sidebar-link <?php echo $tab === 'profile' ? 'active' : ''; ?>">
                        <i class="fas fa-user-cog"></i> Thông tin cá nhân
                    </a>
                </li>
            </ul>
        </aside>

        <section class="content">
            <?php
            switch ($tab) {
                case 'overview':
                    ?>
            <div class="card">
                <h2 class="card-title"><i class="fas fa-tachometer-alt"></i> Tổng quan</h2>

                <div class="stats">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="stat-value"><?php echo $user_stats['active_bookings']; ?></div>
                        <div class="stat-label">Đặt chỗ hiện tại</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-history"></i>
                        </div>
                        <div class="stat-value"><?php echo $user_stats['total_parkings']; ?></div>
                        <div class="stat-label">Lần đỗ xe</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-value"><?php echo $user_stats['total_hours']; ?></div>
                        <div class="stat-label">Tổng giờ đã book</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($user_stats['total_spent'], 0, ',', '.'); ?>₫
                        </div>
                        <div class="stat-label">Tổng chi phí</div>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2 class="card-title"><i class="fas fa-parking"></i> Tình trạng bãi đỗ xe</h2>

                <div class="slot-grid">
                    <?php 
                            foreach ($slots as $slot): 
                                $statusClass = '';
                                $statusText = '';
                                $statusColor = '';
                                $details = '';
                                
                                // Sử dụng actual_status từ query đã tối ưu
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
                                        if ($slot['vehicle_license']) {
                                            $details = 'Xe: ' . $slot['vehicle_license'];
                                        }
                                        break;
                                        
                                    case 'reserved':
                                        $statusClass = 'warning';
                                        $statusText = 'Đã đặt';
                                        $statusColor = '#f59e0b';
                                        if ($slot['booking_license']) {
                                            $details = 'Xe: ' . $slot['booking_license'];
                                            // Hiển thị thêm thời gian
                                            $start = date('H:i', strtotime($slot['booking_start']));
                                            $end = date('H:i', strtotime($slot['booking_end']));
                                            $details .= " ({$start}-{$end})";
                                        }
                                        break;
                                        
                                    case 'maintenance':
                                        $statusClass = 'secondary';
                                        $statusText = 'Bảo trì';
                                        $statusColor = '#6b7280';
                                        break;
                                        
                                    default:
                                        $statusClass = 'success';
                                        $statusText = 'Trống';
                                        $statusColor = '#10b981';
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
                        <?php if ($details): ?>
                        <div class="slot-details"><?php echo htmlspecialchars($details); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <h2 class="card-title"><i class="fas fa-bell"></i> Thông báo</h2>

                <?php if (!$latest_notification): ?>
                <div class="empty-state">
                    <i class="fas fa-bell-slash"></i>
                    <p>Chưa có thông báo nào</p>
                </div>
                <?php else: ?>
                <div class="notification-card">
                    <div class="notification-header">
                        <div class="notification-icon">
                            <?php 
                                    $icon = 'fa-info-circle';
                                    $iconColor = '#33b216'; 
                                    
                                    if ($latest_notification['type'] === 'warning') {
                                        $icon = 'fa-exclamation-triangle';
                                        $iconColor = '#f59e0b';
                                    }
                                    if ($latest_notification['type'] === 'error') {
                                        $icon = 'fa-times-circle';
                                        $iconColor = '#ef4444';
                                    }
                                    ?>
                            <i class="fas <?php echo $icon; ?>"
                                style="color: <?php echo $iconColor; ?>; font-size: 1.2rem;"></i>
                        </div>
                        <div style="flex: 1;">
                            <h3
                                style="margin: 0; font-size: 1.1rem; font-weight: 600; color: <?php echo $iconColor; ?>;">
                                <?php echo htmlspecialchars($latest_notification['title']); ?>
                            </h3>
                        </div>
                    </div>

                    <div class="notification-content">
                        <p style="margin: 0; font-size: 1rem; line-height: 1.5; color: #374151;">
                            <?php echo nl2br(htmlspecialchars($latest_notification['message'])); ?>
                        </p>
                    </div>

                    <div class="notification-footer">
                        <p style="margin: 0; font-size: 0.875rem; color: #6b7280;">
                            Thời gian: <?php echo date('d/m/Y - H:i', strtotime($latest_notification['created_at'])); ?>
                        </p>

                        <?php
                                $typeText = 'Mới';
                                $badgeColor = '#33b216'; 
                                
                                if ($latest_notification['type'] === 'warning') {
                                    $typeText = 'Quan trọng';
                                    $badgeColor = '#f59e0b';
                                }
                                if ($latest_notification['type'] === 'error') {
                                    $typeText = 'Khẩn cấp';
                                    $badgeColor = '#f90000';
                                }
                                ?>
                        <span
                            style="background: #f9fafb; color: <?php echo $badgeColor; ?>; padding: 4px 12px; border-radius: 12px; font-size: 0.8rem; font-weight: 500; border: 1px solid <?php echo $badgeColor; ?>;">
                            <?php echo $typeText; ?>
                        </span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php
                    break;
                    
                case 'booking':
                    ?>
            <div class="card">
                <h2 class="card-title"><i class="fas fa-calendar-plus"></i> Đặt chỗ mới</h2>

                <form action="dashboard.php?tab=booking" method="post">
                    <input type="hidden" name="action" value="create_booking">

                    <div class="form-group">
                        <label class="form-label">Chọn vị trí đỗ xe</label>

                        <div class="slot-grid">
                            <?php foreach ($available_slots as $slot): ?>
                            <div class="slot-card" onclick="selectSlot(this, '<?php echo $slot['id']; ?>')">
                                <div class="slot-icon">
                                    <i class="fas fa-car" style="color: #10b981;"></i>
                                </div>
                                <div class="slot-id"><?php echo htmlspecialchars($slot['id']); ?></div>
                                <div class="slot-status">
                                    <span class="badge badge-success">Trống</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <input type="hidden" id="slot_id" name="slot_id" required>
                    </div>

                    <div class="form-group">
                        <label for="license_plate" class="form-label">Biển số xe</label>
                        <input type="text" id="license_plate" name="license_plate" class="form-control"
                            placeholder="VD: 00A13456" pattern="[0-9]{2}[A-Z]{1,2}[0-9]{4,5}"
                            title="Định dạng: 07A10205" required>
                    </div>

                    <div style="display: flex; flex-wrap: wrap; gap: 1rem;">
                        <div class="form-group" style="flex: 1; min-width: 200px;">
                            <label for="start_date" class="form-label">Ngày đặt</label>
                            <input type="date" id="start_date" name="start_date" class="form-control"
                                min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>

                        <div class="form-group" style="flex: 1; min-width: 200px;">
                            <label for="start_time" class="form-label">Giờ đặt</label>
                            <input type="time" id="start_time" name="start_time" class="form-control"
                                value="<?php echo date('H:i'); ?>" required>
                        </div>

                        <div class="form-group" style="flex: 1; min-width: 200px;">
                            <label for="duration" class="form-label">Thời gian đỗ (giờ)</label>
                            <input type="number" id="duration" name="duration" class="form-control" min="1" max="24"
                                value="1" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Giá dự kiến</label>
                        <div id="estimated_price" class="payment-amount">5.000₫</div>
                        <p>Giá: 5.000₫/giờ</p>
                    </div>

                    <button type="submit" class="btn btn-primary">Đặt chỗ ngay</button>
                </form>
            </div>
            <?php
                    break;
                    
                case 'bookings':
                    ?>
            <div class="card">
                <h2 class="card-title"><i class="fas fa-calendar-alt"></i> Lịch sử đặt chỗ</h2>

                <?php if (empty($bookings)): ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-times"></i>
                    <p>Bạn chưa có lịch sử đặt chỗ nào</p>
                    <a href="dashboard.php?tab=booking" class="btn btn-primary" style="margin-top: 1rem;">Đặt chỗ
                        ngay</a>
                </div>
                <?php else: ?>
                <!-- Desktop Table -->
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Vị trí</th>
                                <th>Biển số xe</th>
                                <th>Thời gian bắt đầu</th>
                                <th>Thời gian kết thúc</th>
                                <th>Trạng thái đặt chỗ</th>
                                <th>Trạng thái thanh toán</th>
                                <th>Thành tiền</th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bookings as $booking): 
                                        $bookingStatusClass = '';
                                        $paymentStatusClass = '';
                                        
                                        // Xác định class và text cho trạng thái booking
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
                                        
                                        // Xác định class và text cho trạng thái thanh toán
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
                                            case 'cancelled':
                                                $paymentStatusClass = 'danger';
                                                $paymentStatusText = 'Đã hủy';
                                                break;
                                            default:
                                                $paymentStatusClass = 'warning';
                                                $paymentStatusText = 'Chờ thanh toán';
                                        }
                                    ?>
                            <tr>
                                <td><?php echo $booking['id']; ?></td>
                                <td><?php echo htmlspecialchars($booking['slot_id']); ?></td>
                                <td style="font-weight: bold; color: var(--primary);">
                                    <?php echo htmlspecialchars($booking['license_plate']); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($booking['start_time'])); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($booking['end_time'])); ?></td>
                                <td><span
                                        class="badge badge-<?php echo $bookingStatusClass; ?>"><?php echo $bookingStatusText; ?></span>
                                </td>
                                <td><span
                                        class="badge badge-<?php echo $paymentStatusClass; ?>"><?php echo $paymentStatusText; ?></span>
                                </td>
                                <td style="font-weight: bold;">
                                    <?php echo number_format($booking['amount'] ?? 0, 0, ',', '.'); ?>₫</td>
                                <td>
                                    <?php 
                                            // Logic hiển thị nút dựa trên trạng thái
                                            if ($booking['status'] === 'pending' && $booking['payment_status'] === 'pending'): 
                                            ?>
                                    <a href="dashboard.php?tab=payment&ref=<?php echo urlencode($booking['payment_ref'] ?? ''); ?>"
                                        class="btn btn-primary" style="padding: 0.25rem 0.5rem; font-size: 0.875rem;">
                                        Thanh toán
                                    </a>
                                    <?php else: ?>
                                    <span style="color: #6b7280; font-style: italic; font-size: 0.875rem;">--</span>
                                    <?php endif; ?>
                                </td>
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
                                    case 'cancelled':
                                        $paymentStatusClass = 'danger';
                                        $paymentStatusText = 'Đã hủy';
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
                                <span>Vị trí:</span>
                                <span><?php echo htmlspecialchars($booking['slot_id']); ?></span>
                            </div>
                            <div class="mobile-card-row">
                                <span>Biển số:</span>
                                <span
                                    style="font-weight: bold; color: var(--primary);"><?php echo htmlspecialchars($booking['license_plate']); ?></span>
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
                                <span
                                    style="font-weight: bold;"><?php echo number_format($booking['amount'] ?? 0, 0, ',', '.'); ?>₫</span>
                            </div>
                        </div>

                        <?php if ($booking['status'] === 'pending' && $booking['payment_status'] === 'pending'): ?>
                        <div class="mobile-card-actions">
                            <a href="dashboard.php?tab=payment&ref=<?php echo urlencode($booking['payment_ref'] ?? ''); ?>"
                                class="btn btn-primary">
                                <i class="fas fa-credit-card"></i> Thanh toán
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php
                    break; 
                case 'profile':
                    ?>
            <div class="card">
                <h2 class="card-title"><i class="fas fa-user-cog"></i> Thông tin cá nhân</h2>

                <div style="margin-bottom: 2rem;">
                    <h3 style="margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid #e5e7eb;">Thông tin
                        tài khoản</h3>

                    <form action="dashboard.php?tab=profile" method="post">
                        <input type="hidden" name="action" value="update_profile">

                        <div class="form-group">
                            <label for="username" class="form-label">Tên đăng nhập</label>
                            <input type="text" id="username" class="form-control"
                                value="<?php echo htmlspecialchars($user['username']); ?>" disabled>
                        </div>

                        <div class="form-group">
                            <label for="full_name" class="form-label">Họ và tên</label>
                            <input type="text" id="full_name" name="full_name" class="form-control"
                                value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" id="email" name="email" class="form-control"
                                value="<?php echo htmlspecialchars($user['email']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="phone" class="form-label">Số điện thoại</label>
                            <input type="tel" id="phone" name="phone" class="form-control"
                                value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                        </div>

                        <button type="submit" class="btn btn-primary">Cập nhật thông tin</button>
                    </form>
                </div>

                <div>
                    <h3 style="margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid #e5e7eb;">Đổi mật
                        khẩu</h3>

                    <form action="dashboard.php?tab=profile" method="post">
                        <input type="hidden" name="action" value="change_password">

                        <div class="form-group">
                            <label for="current_password" class="form-label">Mật khẩu hiện tại</label>
                            <input type="password" id="current_password" name="current_password" class="form-control"
                                required>
                        </div>

                        <div class="form-group">
                            <label for="new_password" class="form-label">Mật khẩu mới</label>
                            <input type="password" id="new_password" name="new_password" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password" class="form-label">Xác nhận mật khẩu mới</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                                required>
                        </div>

                        <button type="submit" class="btn btn-primary">Đổi mật khẩu</button>
                    </form>
                </div>
            </div>
            <?php
                    break;
                    
                case 'payment':
                    ?>
            <div class="card">
                <h2 class="card-title"><i class="fas fa-money-bill-wave"></i> Thanh toán QR Code</h2>

                <?php if (isset($qr_data) && $qr_data['success']): ?>
                <div class="payment-layout">
                    <!-- Phần QR Code -->
                    <div class="qr-section">
                        <div style="background: #f8fafc; padding: 20px; border-radius: 10px; margin-bottom: 20px;">
                            <img src="<?php echo $qr_data['qr_code']; ?>" alt="QR Code" style="border-radius: 8px;">
                        </div>

                        <div class="payment-amount">
                            <?php echo number_format($qr_data['amount'], 0, ',', '.'); ?>₫
                        </div>

                        <p style="color: #666; margin-bottom: 1rem;">Quét mã QR bằng ứng dụng ngân hàng</p>

                        <div class="status-indicator">
                            <p>Trạng thái: <span id="payment-status" class="badge badge-warning">Đang chờ thanh
                                    toán</span></p>
                            <p><span class="countdown-timer">Hết hạn sau: <span
                                        id="countdown-timer"><?php echo QR_EXPIRE_MINUTES; ?> phút</span></span></p>
                        </div>
                    </div>

                    <!-- Phần thông tin trạng thái -->
                    <div class="status-section">
                        <h3 style="margin-bottom: 1.5rem; color: var(--primary);">
                            <i class="fas fa-info-circle"></i> Thông tin thanh toán
                        </h3>

                        <div class="payment-details">
                            <table>
                                <tr>
                                    <td><strong>Mã thanh toán:</strong></td>
                                    <td style="font-family: monospace; font-size: 0.9rem;">
                                        <?php echo htmlspecialchars($qr_data['reference']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Ngân hàng:</strong></td>
                                    <td><?php echo htmlspecialchars($qr_data['bank_info']['bank']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Số tài khoản:</strong></td>
                                    <td><?php echo htmlspecialchars($qr_data['bank_info']['account']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Tên tài khoản:</strong></td>
                                    <td><?php echo htmlspecialchars($qr_data['bank_info']['name']); ?></td>
                                </tr>
                            </table>
                        </div>

                        <div
                            style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 1rem; border-radius: 5px; margin: 1.5rem 0;">
                            <p style="margin: 0; color: #92400e; font-size: 0.9rem;">
                                <strong><i class="fas fa-exclamation-triangle"></i> Lưu ý quan trọng:</strong><br>
                                • QR Code sẽ hết hạn sau <?php echo QR_EXPIRE_MINUTES; ?> phút<br>
                                • Vui lòng thanh toán chính xác số tiền để hệ thống tự động xác nhận<br>
                                • Nội dung chuyển khoản sẽ tự động điền
                            </p>
                        </div>

                        <div id="payment-message" style="text-align: center; margin: 1.5rem 0; color: #666;">
                        </div>

                        <div class="action-buttons">
                            <a href="dashboard.php?tab=bookings" class="btn user-page">
                                <i class="fas fa-arrow-left"></i> Quay lại
                            </a>

                            <button
                                onclick="confirmCancelPayment('<?php echo htmlspecialchars($qr_data['reference']); ?>')"
                                class="btn btn-danger">Hủy thanh toán</button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php
                    break;
            }
            ?>
        </section>
    </main>

    <!-- Các form ẩn để xử lý hành động -->
    <form id="cancelBookingForm" method="post" action="dashboard.php?tab=bookings" style="display: none;">
        <input type="hidden" name="action" value="cancel_booking">
        <input type="hidden" name="booking_id" id="cancelBookingId">
    </form>

    <form id="cancelPaymentForm" method="post" action="dashboard.php?tab=bookings" style="display: none;">
        <input type="hidden" name="action" value="cancel_payment">
        <input type="hidden" name="payment_ref" id="cancelPaymentRef">
    </form>

    <script>
    // Mobile Menu Functions
    function toggleMobileMenu() {
        const mobileMenu = document.getElementById('mobileMenu');
        const overlay = document.querySelector('.menu-overlay');
        const toggle = document.querySelector('.mobile-menu-toggle i');

        mobileMenu.classList.toggle('show');
        overlay.classList.toggle('show');

        if (mobileMenu.classList.contains('show')) {
            toggle.classList.remove('fa-bars');
            toggle.classList.add('fa-times');
            document.body.style.overflow = 'hidden';
        } else {
            toggle.classList.remove('fa-times');
            toggle.classList.add('fa-bars');
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

    // Close menu on click outside
    document.addEventListener('click', function(event) {
        const mobileMenu = document.getElementById('mobileMenu');
        const toggle = document.querySelector('.mobile-menu-toggle');

        if (!mobileMenu.contains(event.target) && !toggle.contains(event.target)) {
            if (mobileMenu.classList.contains('show')) {
                closeMobileMenu();
            }
        }
    });

    // Close menu on window resize
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
            closeMobileMenu();
        }
    });

    // Hệ thống thanh toán tối ưu
    let paymentCheckInterval;
    let checkAttempts = 0;
    let maxAttempts = 150;
    let isChecking = false;

    // Hàm chọn slot đỗ xe
    function selectSlot(element, slotId) {
        document.querySelectorAll('.slot-card').forEach(slot => {
            slot.classList.remove('selected');
        });

        element.classList.add('selected');
        document.getElementById('slot_id').value = slotId;

        // Update estimated price
        updateEstimatedPrice();
    }

    // Update estimated price
    function updateEstimatedPrice() {
        const duration = parseInt(document.getElementById('duration')?.value || 1);
        const price = duration * 5000;
        const priceElement = document.getElementById('estimated_price');
        if (priceElement) {
            priceElement.textContent = price.toLocaleString('vi-VN') + '₫';
        }
    }

    // Hàm kiểm tra thanh toán chính
    function checkPaymentOptimized(ref) {
        if (!ref || isChecking) return;

        isChecking = true;
        checkAttempts++;

        const statusEl = document.getElementById('payment-status');
        const messageEl = document.getElementById('payment-message');

        if (!statusEl || !messageEl) {
            isChecking = false;
            return;
        }

        const apiPaths = [
            `api/check_payment.php?ref=${ref}&_=${Date.now()}`,
            `./api/check_payment.php?ref=${ref}&_=${Date.now()}`,
            `/api/check_payment.php?ref=${ref}&_=${Date.now()}`
        ];

        tryApiCall(apiPaths, 0, ref);
    }

    // Hàm thử call API với multiple paths
    function tryApiCall(paths, pathIndex, ref) {
        if (pathIndex >= paths.length) {
            handlePaymentError('All API paths failed', ref);
            isChecking = false;
            return;
        }

        const currentPath = paths[pathIndex];

        fetch(currentPath)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.text();
            })
            .then(text => {
                const jsonData = extractJSONFromResponse(text);
                if (jsonData) {
                    handlePaymentResponse(jsonData, ref);
                    isChecking = false;
                } else {
                    setTimeout(() => tryApiCall(paths, pathIndex + 1, ref), 1000);
                }
            })
            .catch(error => {
                console.warn(`Path ${pathIndex + 1} failed:`, error.message);
                setTimeout(() => tryApiCall(paths, pathIndex + 1, ref), 1000);
            });
    }

    function extractJSONFromResponse(responseText) {
        try {
            return JSON.parse(responseText);
        } catch (e) {
            const jsonPattern = /\{[\s\S]*\}$/;
            const match = responseText.match(jsonPattern);

            if (match) {
                try {
                    return JSON.parse(match[0]);
                } catch (parseError) {
                    console.error('Failed to parse extracted JSON:', parseError);
                }
            }

            const lines = responseText.split('\n');
            for (let i = lines.length - 1; i >= 0; i--) {
                const line = lines[i].trim();
                if (line.startsWith('{') && line.endsWith('}')) {
                    try {
                        return JSON.parse(line);
                    } catch (e) {
                        continue;
                    }
                }
            }

            return null;
        }
    }

    // Xử lý response từ API
    function handlePaymentResponse(data, ref) {
        console.log(`Kiểm tra lần #${checkAttempts}:`, data.status);
        if (data.status === 'completed') {
            handlePaymentSuccess(data);
        } else if (data.status === 'expired') {
            handlePaymentExpired();
        } else if (data.status === 'pending') {
            handlePaymentPending(ref);
        } else if (data.error) {
            handlePaymentError(data.error, ref);
        }
    }

    // Xử lý thành công
    function handlePaymentSuccess(data) {
        clearInterval(paymentCheckInterval);
        const statusEl = document.getElementById('payment-status');
        const messageEl = document.getElementById('payment-message');
        statusEl.className = 'badge badge-success';
        statusEl.textContent = 'Đã thanh toán';
        messageEl.innerHTML =
            '<div style="text-align: center;"><i class="fas fa-check-circle" style="color: #10b981; font-size: 2rem;"></i></div>';

        Swal.fire({
            title: 'Thanh toán thành công!',
            html: `<p style="font-size: 1.2rem; margin: 15px 0;"><strong>Số tiền: ${data.amount || '10.000₫'}</strong></p>`,
            icon: 'success',
            timer: 2500,
            timerProgressBar: true,
            showConfirmButton: false,
            allowOutsideClick: false,
            allowEscapeKey: false
        }).then(() => {
            window.location.href = 'dashboard.php?tab=bookings';
        });
    }

    // Xử lý hết hạn
    function handlePaymentExpired() {
        clearInterval(paymentCheckInterval);

        const statusEl = document.getElementById('payment-status');
        const messageEl = document.getElementById('payment-message');

        statusEl.className = 'badge badge-danger';
        statusEl.textContent = 'Hết hạn';

        messageEl.innerHTML = `
                <div style="text-align: center;">
                    <i class="fas fa-times-circle" style="color: #ef4444; font-size: 2rem; margin-bottom: 10px;"></i>
                    <p><strong>QR Code đã hết hạn!</strong></p>
                    <button onclick="location.reload()" class="btn btn-primary" style="margin-top: 15px;">
                    </button>
                </div>
            `;
    }

    function handlePaymentPending(ref) {
        const statusEl = document.getElementById('payment-status');
        const messageEl = document.getElementById('payment-message');

        statusEl.className = 'badge badge-warning';
        statusEl.textContent = 'Đang chờ thanh toán';

        messageEl.innerHTML = `
                <div style="text-align: center;">
                </div>
            `;

        if (checkAttempts < maxAttempts) {
            setTimeout(() => checkPaymentOptimized(ref), 2500);
        } else {
            handlePaymentExpired();
        }
    }

    // Xử lý lỗi
    function handlePaymentError(errorMsg, ref) {
        const messageEl = document.getElementById('payment-message');
        const statusEl = document.getElementById('payment-status');

        statusEl.className = 'badge badge-danger';
        statusEl.textContent = 'Lỗi';

        messageEl.innerHTML = `
                <div style="text-align: center;">
                    <i class="fas fa-exclamation-triangle" style="color: #f59e0b; font-size: 1.5rem; margin-bottom: 10px;"></i>
                    <p><strong>Lỗi kết nối</strong></p>
                    <button onclick="checkPaymentOptimized('${ref}')" class="btn user-page" style="margin-top: 10px;">
                        Thử lại
                    </button>
                </div>
            `;

        if (checkAttempts < maxAttempts) {
            setTimeout(() => checkPaymentOptimized(ref), 8000);
        }
    }

    // Timer đếm ngược
    function startCountdownTimer() {
        const timerEl = document.getElementById('countdown-timer');
        if (!timerEl) return;

        let timeLeft = 10 * 60; // 10 phút

        const updateTimer = () => {
            const minutes = Math.floor(timeLeft / 60);
            const seconds = timeLeft % 60;

            if (timerEl) {
                timerEl.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;

                if (timeLeft <= 60) {
                    timerEl.style.color = '#ef4444';
                } else if (timeLeft <= 300) {
                    timerEl.style.color = '#f59e0b';
                }
            }

            timeLeft--;

            if (timeLeft < 0) {
                clearInterval(countdownInterval);
                handlePaymentExpired();
            }
        };

        updateTimer();
        const countdownInterval = setInterval(updateTimer, 1000);
    }

    // Hủy thanh toán
    function confirmCancelPayment(paymentRef) {
        Swal.fire({
            title: "Xác nhận hủy thanh toán?",
            text: "Bạn sẽ phải thanh toán lại từ đầu!",
            icon: "warning",
            showCancelButton: true,
            confirmButtonText: '<span style="padding: 0 2rem;">HỦY THANH TOÁN</span>',
            cancelButtonText: '<span style="padding: 0 2rem;">TIẾP TỤC</span>',
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#10b981',
            reverseButtons: true,
            allowOutsideClick: false
        }).then((result) => {
            if (result.isConfirmed) {
                clearInterval(paymentCheckInterval);
                checkAttempts = maxAttempts;

                document.getElementById('cancelPaymentRef').value = paymentRef;
                document.getElementById('cancelPaymentForm').submit();
            }
        });
    }

    // Flash messages
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
                iconColor: '#10b981'
            });

            Toast.fire({
                icon: "success",
                title: message
            });

        } else {
            Swal.fire({
                title: "Có lỗi xảy ra!",
                text: message,
                icon: "error",
                confirmButtonText: "OK",
                confirmButtonColor: '#ef4444'
            });
        }
    });
    <?php endif; ?>

    // Khởi tạo khi DOM loaded
    document.addEventListener('DOMContentLoaded', function() {
        const paymentPage = document.querySelector('.payment-layout');
        if (paymentPage) {
            const urlParams = new URLSearchParams(window.location.search);
            const ref = urlParams.get('ref');

            if (ref) {
                console.log('🚀 Chuẩn bị kiểm tra thanh toán:', ref);

                checkAttempts = 0;
                isChecking = false;

                startCountdownTimer();

                console.log('⏳ Chờ 10s ...');
                setTimeout(() => {
                    console.log('✅ Bắt đầu kiểm tra thanh toán');
                    checkPaymentOptimized(ref);
                }, 10000);
            }
        }

        // License plate validation
        const licensePlateInput = document.getElementById('license_plate');
        if (licensePlateInput) {
            licensePlateInput.addEventListener('input', function() {
                this.value = this.value.toUpperCase();
            });
        }

        // Duration change listener
        const durationInput = document.getElementById('duration');
        if (durationInput) {
            durationInput.addEventListener('input', updateEstimatedPrice);
        }
    });
    </script>
</body>

</html>