<?php
// cron_job.php - Auto expire bookings & sync slot status
require_once 'includes/config.php';

$now = getVNTime();
echo "=== CRON JOB START: {$now} ===\n";

// 1. EXPIRE BOOKINGS HẾT HẠN CHƯA THANH TOÁN
echo "\n[1] Checking expired unpaid bookings...\n";

// Lấy bookings đã hết hạn (end_time <= now) và chưa thanh toán
$expiredUnpaidBookings = $supabase->request('bookings', 'GET', null, [
    'select' => 'id,end_time,license_plate,status',
    'end_time' => "lte.{$now}",
    'status' => 'in.(pending,confirmed)' // Cả pending và confirmed
]);

$cancelledCount = 0;

if ($expiredUnpaidBookings) {
    foreach ($expiredUnpaidBookings as $booking) {
        // Check xem có payment completed không
        $completedPayments = $supabase->select('payments', 'id', [
            'booking_id' => "eq.{$booking['id']}",
            'status' => 'eq.completed'
        ]);
        
        // Nếu CHƯA có payment completed → Cancel booking
        if (!$completedPayments || count($completedPayments) === 0) {
            $supabase->update('bookings', [
                'status' => 'cancelled',
                'updated_at' => getVNTime()
            ], ['id' => "eq.{$booking['id']}"]);
            
            echo "❌ Cancelled booking #{$booking['id']} (unpaid, expired)\n";
            $cancelledCount++;
        } else {
            // Có payment completed → Chuyển thành completed
            $supabase->update('bookings', [
                'status' => 'completed',
                'updated_at' => getVNTime()
            ], ['id' => "eq.{$booking['id']}"]);
            
            echo "✅ Completed booking #{$booking['id']} (paid, expired)\n";
        }
    }
}

echo "Total cancelled: {$cancelledCount}\n";

// 2. EXPIRE PENDING PAYMENTS
echo "\n[2] Expiring old payments...\n";

$expiredPayments = $supabase->request('payments', 'GET', null, [
    'select' => 'id,payment_ref',
    'status' => 'eq.pending',
    'expires_at' => "lte.{$now}"
]);

$expiredCount = 0;

if ($expiredPayments) {
    foreach ($expiredPayments as $payment) {
        $supabase->update('payments', [
            'status' => 'expired',
            'updated_at' => getVNTime()
        ], ['id' => "eq.{$payment['id']}"]);
        
        echo "⏰ Expired payment: {$payment['payment_ref']}\n";
        $expiredCount++;
    }
}

echo "Total expired payments: {$expiredCount}\n";

// 3. SYNC SLOT STATUS DỰA TRÊN DỮ LIỆU THỰC TẾ
echo "\n[3] Syncing slot status with real data...\n";

$allSlots = $supabase->select('parking_slots', '*');
$syncedCount = 0;

foreach ($allSlots as $slot) {
    $slotId = $slot['id'];
    $currentStatus = $slot['status'];
    $correctStatus = 'empty'; // Default
    
    // Check xe đang trong slot
    $vehicleInSlot = $supabase->select('vehicles', '*', [
        'slot_id' => "eq.{$slotId}",
        'status' => 'eq.in_parking'
    ], '', 1);
    
    if ($vehicleInSlot) {
        // Có xe thực tế → occupied
        $correctStatus = 'occupied';
        $vehicle = $vehicleInSlot[0];
        
        // Update slot với thông tin xe
        if ($currentStatus !== 'occupied') {
            $supabase->update('parking_slots', [
                'status' => 'occupied',
                'rfid_assigned' => $vehicle['rfid_tag'],
                'vehicle_id' => $vehicle['id'],
                'updated_at' => getVNTime()
            ], ['id' => "eq.{$slotId}"]);
            
            echo "🚗 Slot {$slotId}: {$currentStatus} → occupied (vehicle #{$vehicle['id']})\n";
            $syncedCount++;
        }
    } else {
        // Không có xe thực tế
        
        // Check booking confirmed trong khung giờ hiện tại
        $activeBooking = $supabase->request('bookings', 'GET', null, [
            'select' => 'id,start_time,end_time',
            'slot_id' => "eq.{$slotId}",
            'status' => 'eq.confirmed',
            'start_time' => "lte.{$now}",
            'end_time' => "gte.{$now}"
        ]);
        
        if ($activeBooking && count($activeBooking) > 0) {
            // Có booking active → reserved
            $correctStatus = 'reserved';
        } else {
            // Không có gì → empty
            $correctStatus = 'empty';
        }
        
        // Update nếu khác
        if ($currentStatus !== $correctStatus && $currentStatus !== 'maintenance') {
            $supabase->update('parking_slots', [
                'status' => $correctStatus,
                'rfid_assigned' => 'empty',
                'vehicle_id' => null,
                'updated_at' => getVNTime()
            ], ['id' => "eq.{$slotId}"]);
            
            echo "📍 Slot {$slotId}: {$currentStatus} → {$correctStatus}\n";
            $syncedCount++;
        }
    }
}

echo "Total synced slots: {$syncedCount}\n";

echo "\n=== CRON JOB COMPLETED: " . getVNTime() . " ===\n";
?>