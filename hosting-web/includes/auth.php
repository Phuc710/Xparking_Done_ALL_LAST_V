<?php
// includes/auth.php
require_once 'config.php';

// Check if user is logged in
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

// Check if user is admin
function is_admin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

// Login user
function login_user($username, $password) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username");
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_fullname'] = $user['full_name'];
            
            // Log login
            log_activity('login', 'User logged in', $user['id']);
            
            return true;
        }
        
        return false;
    } catch (PDOException $e) {
        error_log("Login error: " . $e->getMessage());
        return false;
    }
}

// Register new user
function register_user($username, $password, $email, $full_name, $phone = null) {
    global $pdo;
    
    try {
        // Check if username or email already exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username OR email = :email");
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        
        if ($stmt->fetchColumn() > 0) {
            return ['success' => false, 'message' => 'Tên đăng nhập hoặc email đã tồn tại!'];
        }
        
        // Hash password
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        // Insert new user
        $stmt = $pdo->prepare("INSERT INTO users (username, password, email, full_name, phone) 
                              VALUES (:username, :password, :email, :full_name, :phone)");
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':password', $password_hash);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':full_name', $full_name);
        $stmt->bindParam(':phone', $phone);
        $stmt->execute();
        
        $user_id = $pdo->lastInsertId();
        
        // Log registration
        log_activity('registration', 'New user registered', $user_id);
        
        return ['success' => true, 'message' => 'Đăng ký thành công!'];
    } catch (PDOException $e) {
        error_log("Registration error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Lỗi hệ thống. Vui lòng thử lại sau!'];
    }
}

// Log out user
function logout_user() {
    if (isset($_SESSION['user_id'])) {
        log_activity('logout', 'User logged out', $_SESSION['user_id']);
    }
    
    // Unset all session variables
    $_SESSION = [];
    
    // Delete the session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // Destroy the session
    session_destroy();
}

// Get user details by ID
function get_user($user_id) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT id, username, email, full_name, phone, role, created_at FROM users WHERE id = :user_id");
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Get user error: " . $e->getMessage());
        return false;
    }
}

// Update user profile
function update_user_profile($user_id, $email, $full_name, $phone) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("UPDATE users SET email = :email, full_name = :full_name, phone = :phone WHERE id = :user_id");
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':full_name', $full_name);
        $stmt->bindParam(':phone', $phone);
        $stmt->execute();
        
        // Update session values
        $_SESSION['user_email'] = $email;
        $_SESSION['user_fullname'] = $full_name;
        
        // Log update
        log_activity('profile_update', 'User updated profile', $user_id);
        
        return true;
    } catch (PDOException $e) {
        error_log("Update profile error: " . $e->getMessage());
        return false;
    }
}

// Change user password
function change_user_password($user_id, $current_password, $new_password) {
    global $pdo;
    
    try {
        // Verify current password
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = :user_id");
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($current_password, $user['password'])) {
            return ['success' => false, 'message' => 'Mật khẩu hiện tại không đúng!'];
        }
        
        // Hash new password
        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        
        // Update password
        $stmt = $pdo->prepare("UPDATE users SET password = :password WHERE id = :user_id");
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':password', $password_hash);
        $stmt->execute();
        
        // Log password change
        log_activity('password_change', 'User changed password', $user_id);
        
        return ['success' => true, 'message' => 'Đổi mật khẩu thành công!'];
    } catch (PDOException $e) {
        error_log("Change password error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Lỗi hệ thống. Vui lòng thử lại sau!'];
    }
}

// Log system activity
function log_activity($event_type, $description, $user_id = null) {
    global $pdo;
    
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        
        $stmt = $pdo->prepare("INSERT INTO system_logs (event_type, description, user_id, ip_address) 
                              VALUES (:event_type, :description, :user_id, :ip_address)");
        $stmt->bindParam(':event_type', $event_type);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':ip_address', $ip);
        $stmt->execute();
        
        return true;
    } catch (PDOException $e) {
        error_log("Log activity error: " . $e->getMessage());
        return false;
    }
}

// Require login or redirect
function require_login() {
    if (!is_logged_in()) {
        set_flash_message('error', 'Vui lòng đăng nhập để tiếp tục!');
        redirect(SITE_URL . '/index.php?page=login');
    }
}

// Require admin or redirect
function require_admin() {
    if (!is_admin()) {
        set_flash_message('error', 'Bạn không có quyền truy cập trang này!');
        redirect(SITE_URL . '/dashboard.php');
    }
}
?>