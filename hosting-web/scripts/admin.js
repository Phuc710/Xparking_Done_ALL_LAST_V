// scripts/admin.js - Admin panel JavaScript

// WebSocket realtime connection
const ws = new WebSocket('ws://xparking.x10.mx:8765');

ws.onopen = function() {
    console.log('WebSocket connected');
};

ws.onmessage = function(event) {
    const data = JSON.parse(event.data);
    console.log('Realtime update:', data);
    
    // Handle different event types
    switch(data.type) {
        case 'slot_update':
            handleSlotUpdate(data);
            break;
        case 'vehicle_entry':
            handleVehicleEntry(data);
            break;
        case 'vehicle_exit':
            handleVehicleExit(data);
            break;
        case 'payment_completed':
            handlePaymentCompleted(data);
            break;
    }
};

ws.onerror = function(error) {
    console.error('WebSocket error:', error);
};

// Handle slot update
function handleSlotUpdate(data) {
    console.log('Slot updated:', data);
    // Reload page or update specific slot
    if (window.location.href.includes('page=slots') || window.location.href.includes('page=tongquan')) {
        location.reload();
    }
}

// Handle vehicle entry
function handleVehicleEntry(data) {
    console.log('Vehicle entered:', data);
    // Show notification or update stats
    if (window.location.href.includes('page=tongquan') || window.location.href.includes('page=lichsuxe')) {
        location.reload();
    }
}

// Handle vehicle exit
function handleVehicleExit(data) {
    console.log('Vehicle exited:', data);
    if (window.location.href.includes('page=tongquan') || window.location.href.includes('page=lichsuxe')) {
        location.reload();
    }
}

// Handle payment completed
function handlePaymentCompleted(data) {
    console.log('Payment completed:', data);
    if (window.location.href.includes('page=payment') || window.location.href.includes('page=doanhthu')) {
        location.reload();
    }
}

// Mobile menu toggle
function toggleMobileMenu() {
    const mobileMenu = document.getElementById('mobileMenu');
    const overlay = document.querySelector('.menu-overlay');
    const toggle = document.querySelector('.mobile-menu-toggle i');
    
    if (mobileMenu && overlay && toggle) {
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
}

function closeMobileMenu() {
    const mobileMenu = document.getElementById('mobileMenu');
    const overlay = document.querySelector('.menu-overlay');
    const toggle = document.querySelector('.mobile-menu-toggle i');
    
    if (mobileMenu && overlay && toggle) {
        mobileMenu.classList.remove('show');
        overlay.classList.remove('show');
        toggle.classList.remove('fa-times');
        toggle.classList.add('fa-bars');
        document.body.style.overflow = '';
    }
}

// Auto-refresh data every 30 seconds
setInterval(function() {
    if (window.location.href.includes('page=tongquan')) {
        // Refresh stats without full reload
        fetch('api/slots_status.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('Stats refreshed');
                }
            });
    }
}, 30000);

// Update clock
function updateClock() {
    const clockEl = document.getElementById('clock');
    if (clockEl) {
        const now = new Date();
        const time = now.toLocaleTimeString('vi-VN', { hour12: false });
        const date = now.toLocaleDateString('vi-VN');
        clockEl.textContent = `${time} - ${date}`;
    }
}

setInterval(updateClock, 1000);
updateClock();

