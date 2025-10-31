// scripts/dashboard.js - User dashboard JavaScript

// WebSocket realtime connection
const ws = new WebSocket('ws://xparking.x10.mx:8765');

ws.onopen = function() {
    console.log('Dashboard WebSocket connected');
};

ws.onmessage = function(event) {
    const data = JSON.parse(event.data);
    console.log('Dashboard update:', data);
    
    // Handle notifications
    if (data.type === 'notification') {
        showNotification(data.title, data.message, data.level);
    }
    
    // Handle booking updates
    if (data.type === 'booking_confirmed') {
        showNotification('Booking xác nhận', 'Booking của bạn đã được xác nhận!', 'success');
        setTimeout(() => location.reload(), 2000);
    }
    
    // Handle payment updates
    if (data.type === 'payment_completed') {
        showNotification('Thanh toán thành công', 'Thanh toán của bạn đã hoàn tất!', 'success');
        setTimeout(() => location.reload(), 2000);
    }
};

// Show notification
function showNotification(title, message, type = 'info') {
    // Use SweetAlert2 or browser notification
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: title,
            text: message,
            icon: type,
            timer: 3000,
            showConfirmButton: false
        });
    } else {
        alert(`${title}\n${message}`);
    }
}

// Booking form handler
function selectSlot(slotId) {
    // Remove previous selection
    document.querySelectorAll('.slot-card').forEach(card => {
        card.classList.remove('selected');
    });
    
    // Add selection
    const slotCard = document.getElementById(`slot-${slotId}`);
    if (slotCard) {
        slotCard.classList.add('selected');
    }
    
    // Update form
    const selectedSlotInput = document.getElementById('selected_slot');
    if (selectedSlotInput) {
        selectedSlotInput.value = slotId;
    }
}

// Payment QR countdown - KHÔNG RESET khi quay lại trang
let countdownTimer = null;

function startCountdown(expiresAt) {
    const countdownEl = document.getElementById('countdown');
    if (!countdownEl) return;
    
    // Clear existing timer
    if (countdownTimer) {
        clearInterval(countdownTimer);
    }
    
    // Tính seconds từ expires_at (không reset)
    function updateCountdown() {
        const now = new Date().getTime();
        const expiryTime = new Date(expiresAt).getTime();
        const secondsRemaining = Math.max(0, Math.floor((expiryTime - now) / 1000));
        
        const minutes = Math.floor(secondsRemaining / 60);
        const secs = secondsRemaining % 60;
        
        countdownEl.textContent = `${minutes}:${secs.toString().padStart(2, '0')}`;
        
        if (secondsRemaining <= 0) {
            clearInterval(countdownTimer);
            showNotification('Hết hạn', 'QR thanh toán đã hết hạn!', 'error');
            setTimeout(() => location.reload(), 2000);
        }
    }
    
    // Update ngay lập tức
    updateCountdown();
    
    // Update mỗi giây
    countdownTimer = setInterval(updateCountdown, 1000);
}

// Cancel booking
function cancelBooking(bookingId) {
    if (!confirm('Bạn có chắc muốn hủy booking?')) {
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
            showNotification('Thành công', 'Đã hủy booking', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showNotification('Lỗi', data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Lỗi', 'Không thể hủy booking', 'error');
    });
}

// Check payment status
function checkPaymentStatus(paymentId) {
    fetch(`api/webhook.php?id=${paymentId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.payment.status === 'completed') {
                    showNotification('Thành công', 'Thanh toán đã hoàn tất!', 'success');
                    setTimeout(() => location.reload(), 2000);
                } else if (data.payment.status === 'expired') {
                    showNotification('Hết hạn', 'QR đã hết hạn!', 'error');
                    setTimeout(() => location.reload(), 2000);
                }
            }
        })
        .catch(error => {
            console.error('Error checking payment:', error);
        });
}

// Auto check payment every 3 seconds
let paymentCheckInterval = null;

function startPaymentCheck(paymentId) {
    stopPaymentCheck();
    paymentCheckInterval = setInterval(() => {
        checkPaymentStatus(paymentId);
    }, 3000);
}

function stopPaymentCheck() {
    if (paymentCheckInterval) {
        clearInterval(paymentCheckInterval);
        paymentCheckInterval = null;
    }
}

// Calculate duration
function calculateDuration(entryTime, exitTime) {
    if (!exitTime) return 'Đang đỗ';
    
    const entry = new Date(entryTime);
    const exit = new Date(exitTime);
    const diff = exit - entry;
    
    const hours = Math.floor(diff / (1000 * 60 * 60));
    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
    
    return `${hours}h ${minutes}m`;
}

