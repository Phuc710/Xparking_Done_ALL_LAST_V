<?php


session_start();
date_default_timezone_set('Asia/Ho_Chi_Minh');

// ======================================
// SUPABASE DATABASE CONFIG
// ======================================
define('SUPABASE_URL', 'https://qkdtxxezcjifxncicfbr.supabase.co');
define('SUPABASE_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InFrZHR4eGV6Y2ppZnhuY2ljZmJyIiwicm9sZSI6ImFub24iLCJpYXQiOjE3MzM1MDQ4OTQsImV4cCI6MjA0OTA4MDg5NH0.sb_publishable_2kenLPxz8W0ciHRtEzneNA_oJ4aONWj');

// ======================================
// SUPABASE API HELPER CLASS
// ======================================
class SupabaseDB {
    private $url;
    private $key;
    
    public function __construct() {
        $this->url = SUPABASE_URL;
        $this->key = SUPABASE_KEY;
    }
    
    /**
     * Execute Supabase REST API request
     */
    public function request($endpoint, $method = 'GET', $data = null, $params = []) {
        $url = $this->url . '/rest/v1/' . $endpoint;
        
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        $headers = [
            'apikey: ' . $this->key,
            'Authorization: Bearer ' . $this->key,
            'Content-Type: application/json',
            'Prefer: return=representation'
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true);
        } else {
            error_log("Supabase API error: $httpCode - $response");
            return false;
        }
    }
    
    public function select($table, $columns = '*', $filters = [], $orderBy = null, $limit = null) {
        $params = ['select' => $columns];
        
        foreach ($filters as $key => $value) {
            $params[$key] = $value;
        }
        
        if ($orderBy) $params['order'] = $orderBy;
        if ($limit) $params['limit'] = $limit;
        
        return $this->request($table, 'GET', null, $params);
    }
    
    public function insert($table, $data) {
        return $this->request($table, 'POST', $data);
    }
    
    public function update($table, $data, $filters = []) {
        $params = [];
        foreach ($filters as $key => $value) {
            $params[$key] = $value;
        }
        return $this->request($table, 'PATCH', $data, $params);
    }
    
    public function delete($table, $filters = []) {
        $params = [];
        foreach ($filters as $key => $value) {
            $params[$key] = $value;
        }
        return $this->request($table, 'DELETE', null, $params);
    }
    
    public function rpc($function, $params = []) {
        $url = $this->url . '/rest/v1/rpc/' . $function;
        
        $headers = [
            'apikey: ' . $this->key,
            'Authorization: Bearer ' . $this->key,
            'Content-Type: application/json'
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true);
        }
        
        return false;
    }
}

// Initialize global database instance
$supabase = new SupabaseDB();

// ======================================
// PAYMENT CONFIG (SEPAY)
// ======================================
define('SEPAY_API_URL', 'https://my.sepay.vn/userapi/transactions/list');
define('SEPAY_TOKEN', 'TRK794OUY0TJVVIPCASYOULQGNKI6DME2CXC8H0P4ZGDLRAK6FS1HWFV5DHGYNGJ');
define('SEPAY_QR_API', 'https://qr.sepay.vn/img');
define('VIETQR_BANK_ID', 'MBBank');
define('VIETQR_ACCOUNT_NO', '09696969690');
define('VIETQR_ACCOUNT_NAME', 'NGUYEN THANH PHUC');
define('VIETQR_TEMPLATE', 'compact');
define('HOURLY_RATE', 5000);
define('QR_EXPIRE_MINUTES', 10);

// ======================================
// SITE CONFIG
// ======================================
define('SITE_URL', 'https://xparking.x10.mx');
define('ADMIN_EMAIL', 'support@xparking.x10.mx');

// ======================================
// HELPER FUNCTIONS
// ======================================
function getVNTime() {
    return date('Y-m-d H:i:s');
}

function get_vn_now($format = 'Y-m-d H:i:s') {
    return date($format);
}

function generateSnowflakeId() {
    $timestamp = (int)(microtime(true) * 1000) - 1609459200000;
    $machineId = 1;
    $sequence = rand(0, 4095);
    $id = ($timestamp << 22) | ($machineId << 12) | $sequence;
    return (string)$id;
}

function redirect($url) {
    header("Location: $url");
    exit;
}

function send_email($to, $subject, $message) {
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= 'From: XParking <noreply@xparking.x10.mx>' . "\r\n";
    return mail($to, $subject, $message, $headers);
}

function set_flash_message($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function get_flash_message() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}
?>