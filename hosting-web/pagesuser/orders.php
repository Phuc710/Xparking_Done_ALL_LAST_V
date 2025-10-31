<?php
/**
 * Trang Đặt chỗ - User Dashboard
 * Cho phép user đặt chỗ và thanh toán QR
 */

// Lấy slots trống từ Supabase
$availableSlots = $supabase->select(
    'parking_slots',
    '*',
    ['status' => 'eq.empty'],
    'id.asc'
);

// Lấy booking hiện tại của user
$currentBooking = null;
if (isset($_SESSION['user_id'])) {
    $userBookings = $supabase->select(
        'bookings',
        '*, payments(*)',
        [
            'user_id' => 'eq.' . $_SESSION['user_id'],
            'status' => 'in.(pending,confirmed)'
        ],
        'created_at.desc',
        1
    );
    
    if ($userBookings && count($userBookings) > 0) {
        $currentBooking = $userBookings[0];
    }
}
?>

<div class="container-fluid p-4">
    <!-- Header -->
    <div class="mb-4">
        <h2 class="fw-bold">
            <i class="fas fa-calendar-plus me-2"></i>
            Đặt chỗ đỗ xe
        </h2>
        <p class="text-muted">Đặt chỗ trước và thanh toán online để đảm bảo có chỗ đỗ</p>
    </div>

    <?php if ($currentBooking): ?>
        <!-- Có booking đang active -->
        <div class="alert alert-info">
            <h5 class="alert-heading">
                <i class="fas fa-info-circle me-2"></i>
                Bạn đang có booking
            </h5>
            <hr>
            <div class="row">
                <div class="col-md-6">
                    <p><strong>Mã booking:</strong> #<?= $currentBooking['id'] ?></p>
                    <p><strong>Slot:</strong> <?= htmlspecialchars($currentBooking['slot_id']) ?></p>
                    <p><strong>Biển số:</strong> <?= htmlspecialchars($currentBooking['license_plate']) ?></p>
                </div>
                <div class="col-md-6">
                    <p><strong>Thời gian:</strong> <?= date('H:i d/m/Y', strtotime($currentBooking['start_time'])) ?></p>
                    <p><strong>Trạng thái:</strong> 
                        <span class="badge bg-<?= $currentBooking['status'] == 'confirmed' ? 'success' : 'warning' ?>">
                            <?= $currentBooking['status'] == 'confirmed' ? 'Đã xác nhận' : 'Chờ thanh toán' ?>
                        </span>
                    </p>
                    
                    <?php if ($currentBooking['status'] == 'pending' && $currentBooking['payments']): ?>
                        <?php 
                        $payment = $currentBooking['payments'][0];
                        if ($payment['status'] == 'pending'):
                        ?>
                        <button class="btn btn-primary me-2" 
                                onclick="showPaymentQR('<?= $payment['id'] ?>', '<?= $payment['expires_at'] ?>')">
                            <i class="fas fa-qrcode me-2"></i>
                            Hiển thị QR thanh toán
                        </button>
                        <button class="btn btn-danger" onclick="cancelBooking(<?= $currentBooking['id'] ?>)">
                            <i class="fas fa-times me-2"></i>
                            Hủy booking
                        </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Form đặt chỗ mới -->
        <div class="row justify-content-center">
            <!-- Form booking -->
            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">
                            <i class="fas fa-clipboard-list me-2"></i>
                            Thông tin đặt chỗ
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <small>
                                <i class="fas fa-info-circle me-1"></i>
                                Hệ thống sẽ tự động chọn slot trống cho bạn
                            </small>
                        </div>
                        
                        <form id="bookingForm">
                            <div class="mb-3">
                                <label class="form-label">Biển số xe <span class="text-danger">*</span></label>
                                <input type="text" class="form-control text-uppercase" id="license_plate" 
                                       placeholder="VD: 29A12345 hoặc 51F12345" required>
                                <small class="text-muted">Nhập tự do, hệ thống tự động IN HOA</small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Thời gian đặt</label>
                                <select class="form-control" id="duration">
                                    <option value="1">1 giờ - 20,000đ</option>
                                    <option value="2">2 giờ - 35,000đ</option>
                                    <option value="3">3 giờ - 50,000đ</option>
                                    <option value="4">4 giờ - 60,000đ</option>
                                    <option value="8">8 giờ - 100,000đ</option>
                                    <option value="24">24 giờ - 200,000đ</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Ghi chú</label>
                                <textarea class="form-control" id="notes" rows="2" 
                                          placeholder="Ghi chú thêm (không bắt buộc)"></textarea>
                            </div>
                            
                            <div class="alert alert-warning">
                                <small>
                                    <i class="fas fa-info-circle me-1"></i>
                                    QR thanh toán sẽ hết hạn sau 10 phút
                                </small>
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-check-circle me-2"></i>
                                Đặt chỗ và thanh toán
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Modal QR Payment -->
<div class="modal fade" id="qrPaymentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Thanh toán QR</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <div id="qrCodeContainer"></div>
                <div class="mt-3">
                    <h5>Quét mã để thanh toán</h5>
                    <p class="text-muted">Thời gian còn lại: <span id="countdown">10:00</span></p>
                    <div class="alert alert-warning mt-3">
                        <small>
                            <i class="fas fa-info-circle me-1"></i>
                            Nếu quay lại trang này, thời gian sẽ KHÔNG RESET
                        </small>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" onclick="cancelPayment()" id="cancelPaymentBtn">
                    <i class="fas fa-times me-2"></i>
                    Hủy thanh toán
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-arrow-left me-2"></i>
                    Quay lại
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.slot-card {
    transition: all 0.3s;
    cursor: pointer;
}

.slot-card:hover {
    background: #f0f9ff;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.slot-card.selected {
    background: #dbeafe;
    border-color: #2563eb !important;
}
</style>

<script>
// Handle booking form (AUTO ASSIGN SLOT - Không cần chọn slot thủ công)
document.getElementById('bookingForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const licensePlate = document.getElementById('license_plate').value.toUpperCase(); // TỰ ĐỘNG IN HOA
    
    const formData = {
        license_plate: licensePlate,
        duration: document.getElementById('duration').value,
        notes: document.getElementById('notes').value
    };
    
    try {
        const response = await fetch('api/create_booking.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(formData)
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Show QR payment với expires_at (KHÔNG RESET TIME)
            showPaymentQR(result.payment_id, result.expires_at);
            
            // Show thông báo
            alert(`Booking thành công!\nCòn ${result.available_slots} slot trống.\nQuét QR để thanh toán.`);
        } else {
            alert(result.message || 'Có lỗi xảy ra!');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Lỗi kết nối!');
    }
});

let currentPaymentId = null;

function showPaymentQR(paymentId, expiresAt) {
    currentPaymentId = paymentId; // Lưu để cancel
    
    // Show QR payment modal
    const modal = new bootstrap.Modal(document.getElementById('qrPaymentModal'));
    modal.show();
    
    // Load QR code
    loadQRCode(paymentId);
    
    // Start countdown dựa vào expires_at (KHÔNG RESET)
    startCountdown(expiresAt);
    
    // Check payment status mỗi 3 giây
    startPaymentCheck(paymentId);
}

// Hủy thanh toán (trong QR modal)
function cancelPayment() {
    if (!currentPaymentId) return;
    
    if (!confirm('Bạn có chắc muốn hủy thanh toán?')) {
        return;
    }
    
    fetch('api/cancel_payment.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({payment_id: currentPaymentId})
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Đã hủy thanh toán');
            location.reload();
        } else {
            alert(data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Lỗi khi hủy');
    });
}

function loadQRCode(paymentId) {
    // Fetch QR từ API
    fetch(`api/get_payment_qr.php?id=${paymentId}`)
        .then(response => response.json())
        .then(data => {
            if (data.qr_url) {
                document.getElementById('qrCodeContainer').innerHTML = 
                    `<img src="${data.qr_url}" alt="QR Code" class="img-fluid">`;
            }
        });
}

// Countdown đã có trong dashboard.js (dùng expires_at)
</script>
