
BEGIN;

-- ===================================================
-- BƯỚC 1: XÓA CÁC BẢNG CŨ (NẾU CÓ)
-- ===================================================

DROP TABLE IF EXISTS system_logs CASCADE;
DROP TABLE IF EXISTS payments CASCADE;
DROP TABLE IF EXISTS vehicles CASCADE;
DROP TABLE IF EXISTS bookings CASCADE;
DROP TABLE IF EXISTS rfid_pool CASCADE;
DROP TABLE IF EXISTS parking_slots CASCADE;
DROP TABLE IF EXISTS users CASCADE;
DROP TABLE IF EXISTS notifications CASCADE;

-- ===================================================
-- BƯỚC 2: TẠO CÁC BẢNG CHÍNH
-- ===================================================

-- Bảng users (người dùng)
CREATE TABLE users (
    id BIGSERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    role VARCHAR(20) DEFAULT 'user' CHECK (role IN ('admin', 'user')),
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Bảng parking_slots (4 chỗ đỗ xe)
CREATE TABLE parking_slots (
    id VARCHAR(10) PRIMARY KEY,
    status VARCHAR(20) DEFAULT 'empty' CHECK (status IN ('empty', 'occupied', 'reserved', 'maintenance')),
    rfid_assigned VARCHAR(50) DEFAULT 'empty',
    vehicle_id BIGINT,
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Bảng rfid_pool (pool thẻ RFID)
CREATE TABLE rfid_pool (
    id BIGSERIAL PRIMARY KEY,
    uid VARCHAR(50) NOT NULL UNIQUE,
    status VARCHAR(20) DEFAULT 'available' CHECK (status IN ('available', 'assigned', 'maintenance')),
    assigned_at TIMESTAMPTZ,
    assigned_to_vehicle BIGINT,
    last_used TIMESTAMPTZ,
    usage_count INTEGER DEFAULT 0,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Bảng vehicles (xe ra vào) với ảnh base64 nén
CREATE TABLE vehicles (
    id BIGSERIAL PRIMARY KEY,
    license_plate VARCHAR(20) NOT NULL,
    user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    slot_id VARCHAR(10) REFERENCES parking_slots(id) ON DELETE SET NULL,
    rfid_tag VARCHAR(50),
    entry_time TIMESTAMPTZ DEFAULT NOW(),
    exit_time TIMESTAMPTZ,
    entry_image TEXT,  -- Ảnh vào (base64 nén, ~50KB)
    exit_image TEXT,   -- Ảnh ra (base64 nén, ~50KB)
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('in_parking', 'exited', 'pending')),
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Bảng bookings (đặt chỗ trước)
CREATE TABLE bookings (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    slot_id VARCHAR(10) NOT NULL REFERENCES parking_slots(id),
    license_plate VARCHAR(20) NOT NULL,
    start_time TIMESTAMPTZ NOT NULL,
    end_time TIMESTAMPTZ NOT NULL,
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'confirmed', 'cancelled', 'completed', 'in_parking')),
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Bảng payments (thanh toán với Snowflake ID)
CREATE TABLE payments (
    id VARCHAR(20) PRIMARY KEY,
    user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    booking_id BIGINT REFERENCES bookings(id) ON DELETE SET NULL,
    vehicle_id BIGINT REFERENCES vehicles(id) ON DELETE SET NULL,
    amount INTEGER NOT NULL,
    description TEXT,
    payment_ref VARCHAR(100) UNIQUE NOT NULL,
    qr_code TEXT,
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'completed', 'failed', 'expired', 'cancelled')),
    payment_time TIMESTAMPTZ,
    expires_at TIMESTAMPTZ NOT NULL,
    sepay_ref VARCHAR(100),
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Bảng system_logs (logs hệ thống)
CREATE TABLE system_logs (
    id BIGSERIAL PRIMARY KEY,
    event_type VARCHAR(50) NOT NULL,
    event_category VARCHAR(20) DEFAULT 'general' CHECK (event_category IN ('general', 'vehicle', 'payment', 'system', 'security')),
    description TEXT,
    metadata JSONB DEFAULT '{}'::JSONB,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Bảng notifications (thông báo)
CREATE TABLE notifications (
    id BIGSERIAL PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type VARCHAR(20) DEFAULT 'info' CHECK (type IN ('info', 'warning', 'error')),
    target_user_id BIGINT REFERENCES users(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- ===================================================
-- BƯỚC 3: TẠO INDEXES ĐỂ TỐI ƯU TỐC ĐỘ
-- ===================================================

-- Vehicles indexes (quan trọng nhất cho performance)
CREATE INDEX idx_vehicles_license_plate ON vehicles(license_plate);
CREATE INDEX idx_vehicles_rfid_status ON vehicles(rfid_tag, status) WHERE status = 'in_parking';
CREATE INDEX idx_vehicles_slot_status ON vehicles(slot_id, status) WHERE status = 'in_parking';
CREATE INDEX idx_vehicles_entry_time ON vehicles(entry_time DESC);
CREATE INDEX idx_vehicles_status ON vehicles(status);

-- RFID pool indexes
CREATE INDEX idx_rfid_status ON rfid_pool(status) WHERE status = 'available';
CREATE INDEX idx_rfid_uid ON rfid_pool(uid);
CREATE INDEX idx_rfid_assigned ON rfid_pool(assigned_to_vehicle) WHERE assigned_to_vehicle IS NOT NULL;

-- Payments indexes
CREATE INDEX idx_payments_ref ON payments(payment_ref);
CREATE INDEX idx_payments_status_expires ON payments(status, expires_at);
CREATE INDEX idx_payments_created ON payments(created_at DESC);
CREATE INDEX idx_payments_vehicle ON payments(vehicle_id);

-- Bookings indexes
CREATE INDEX idx_bookings_slot_time ON bookings(slot_id, start_time, end_time);
CREATE INDEX idx_bookings_user_status ON bookings(user_id, status);
CREATE INDEX idx_bookings_license ON bookings(license_plate);
CREATE INDEX idx_bookings_status_time ON bookings(status, start_time, end_time);

-- Parking slots indexes
CREATE INDEX idx_slots_status ON parking_slots(status);

-- System logs indexes
CREATE INDEX idx_logs_category_created ON system_logs(event_category, created_at DESC);
CREATE INDEX idx_logs_type_created ON system_logs(event_type, created_at DESC);
CREATE INDEX idx_logs_metadata_gin ON system_logs USING GIN (metadata);

-- ===================================================
-- BƯỚC 4: TẠO FUNCTIONS HỖ TRỢ
-- ===================================================

-- Function lấy slots trống
CREATE OR REPLACE FUNCTION get_available_slots()
RETURNS TABLE (
    id VARCHAR(10),
    status VARCHAR(20)
)
LANGUAGE SQL
STABLE
AS $$
    SELECT ps.id, ps.status
    FROM parking_slots ps
    LEFT JOIN vehicles v ON ps.id = v.slot_id AND v.status = 'in_parking'
    LEFT JOIN bookings b ON ps.id = b.slot_id 
        AND b.status = 'confirmed' 
        AND NOW() BETWEEN b.start_time AND b.end_time
    WHERE ps.status != 'maintenance'
      AND v.id IS NULL
      AND b.id IS NULL
    ORDER BY ps.id;
$$;

-- Function expire payments cũ
CREATE OR REPLACE FUNCTION expire_old_payments()
RETURNS INTEGER
LANGUAGE plpgsql
AS $$
DECLARE
    expired_count INTEGER;
BEGIN
    UPDATE payments 
    SET status = 'expired', updated_at = NOW()
    WHERE status = 'pending' AND expires_at < NOW();
    
    GET DIAGNOSTICS expired_count = ROW_COUNT;
    RETURN expired_count;
END;
$$;

-- Function tự động cập nhật updated_at
CREATE OR REPLACE FUNCTION update_updated_at()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$;

-- Function kiểm tra booking đang active
CREATE OR REPLACE FUNCTION get_active_booking(p_license_plate VARCHAR(20))
RETURNS TABLE (
    id BIGINT,
    slot_id VARCHAR(10),
    start_time TIMESTAMPTZ,
    end_time TIMESTAMPTZ,
    status VARCHAR(20)
)
LANGUAGE SQL
STABLE
AS $$
    SELECT id, slot_id, start_time, end_time, status
    FROM bookings
    WHERE license_plate = p_license_plate
      AND status = 'confirmed'
      AND NOW() BETWEEN start_time AND end_time
    ORDER BY start_time DESC
    LIMIT 1;
$$;

-- Function ghi log xe vào
CREATE OR REPLACE FUNCTION log_vehicle_entry(
    p_license_plate VARCHAR(20),
    p_slot_id VARCHAR(10),
    p_rfid VARCHAR(50)
)
RETURNS VOID
LANGUAGE plpgsql
AS $$
BEGIN
    INSERT INTO system_logs (event_type, event_category, description, metadata)
    VALUES (
        'vehicle_entry',
        'vehicle',
        'Vehicle entered parking',
        jsonb_build_object(
            'license_plate', p_license_plate,
            'slot_id', p_slot_id,
            'rfid', p_rfid,
            'timestamp', NOW()
        )
    );
END;
$$;

-- Function ghi log xe ra
CREATE OR REPLACE FUNCTION log_vehicle_exit(
    p_license_plate VARCHAR(20),
    p_slot_id VARCHAR(10),
    p_rfid VARCHAR(50),
    p_duration_minutes INTEGER
)
RETURNS VOID
LANGUAGE plpgsql
AS $$
BEGIN
    INSERT INTO system_logs (event_type, event_category, description, metadata)
    VALUES (
        'vehicle_exit',
        'vehicle',
        'Vehicle exited parking',
        jsonb_build_object(
            'license_plate', p_license_plate,
            'slot_id', p_slot_id,
            'rfid', p_rfid,
            'duration_minutes', p_duration_minutes,
            'timestamp', NOW()
        )
    );
END;
$$;

-- Function dọn dẹp logs cũ (giữ 30 ngày)
CREATE OR REPLACE FUNCTION cleanup_old_logs()
RETURNS INTEGER
LANGUAGE plpgsql
AS $$
DECLARE
    deleted_count INTEGER;
BEGIN
    DELETE FROM system_logs
    WHERE created_at < NOW() - INTERVAL '30 days';
    
    GET DIAGNOSTICS deleted_count = ROW_COUNT;
    RETURN deleted_count;
END;
$$;

-- ===================================================
-- BƯỚC 5: TẠO TRIGGERS
-- ===================================================

-- Trigger cập nhật updated_at cho vehicles
CREATE TRIGGER vehicles_updated_at
    BEFORE UPDATE ON vehicles
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at();

-- Trigger cập nhật updated_at cho bookings
CREATE TRIGGER bookings_updated_at
    BEFORE UPDATE ON bookings
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at();

-- Trigger cập nhật updated_at cho payments
CREATE TRIGGER payments_updated_at
    BEFORE UPDATE ON payments
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at();

-- Trigger cập nhật updated_at cho parking_slots
CREATE TRIGGER parking_slots_updated_at
    BEFORE UPDATE ON parking_slots
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at();

-- ===================================================
-- BƯỚC 6: THÊM DỮ LIỆU KHỞI TẠO
-- ===================================================

-- Thêm 4 slots đỗ xe
INSERT INTO parking_slots (id, status) VALUES 
('A01', 'empty'),
('A02', 'empty'),
('A03', 'empty'),
('A04', 'empty');

-- Thêm 8 thẻ RFID vào pool
INSERT INTO rfid_pool (uid, status) VALUES 
('04A1B2C3', 'available'),
('04D4E5F6', 'available'),
('04G7H8I9', 'available'),
('04J0K1L2', 'available'),
('04M3N4O5', 'available'),
('04P6Q7R8', 'available'),
('04S9T0U1', 'available'),
('04V2W3X4', 'available');

-- Thêm tài khoản admin mặc định
-- Username: admin
-- Password: admin123 (đã hash bcrypt)
INSERT INTO users (username, password, email, full_name, role) VALUES 
('admin', '$2b$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqyqdgG5fHJ3w9E7/2TGDzm', 'admin@xparking.com', 'Administrator', 'admin');

-- ===================================================
-- BƯỚC 7: ENABLE REALTIME
-- ===================================================

-- Enable realtime cho các bảng quan trọng
ALTER PUBLICATION supabase_realtime ADD TABLE parking_slots;
ALTER PUBLICATION supabase_realtime ADD TABLE vehicles;
ALTER PUBLICATION supabase_realtime ADD TABLE payments;
ALTER PUBLICATION supabase_realtime ADD TABLE bookings;

COMMIT;

-- ===================================================
-- VERIFICATION - Kiểm tra đã tạo thành công
-- ===================================================

-- Kiểm tra tables
SELECT 'Tables created:' as status;
SELECT table_name FROM information_schema.tables 
WHERE table_schema = 'public' 
ORDER BY table_name;

-- Kiểm tra data khởi tạo
SELECT 'Parking Slots:' as info, COUNT(*) as count FROM parking_slots;
SELECT 'RFID Pool:' as info, COUNT(*) as count FROM rfid_pool;
SELECT 'Admin User:' as info, COUNT(*) as count FROM users WHERE role = 'admin';

-- Kiểm tra indexes
SELECT 'Indexes:' as status;
SELECT indexname FROM pg_indexes 
WHERE schemaname = 'public' 
ORDER BY indexname;

-- Test function
SELECT 'Available Slots:' as info, * FROM get_available_slots();

-- ===================================================
-- DONE! DATABASE ĐÃ SẴN SÀNG
-- ===================================================

SELECT '✅ XPARKING DATABASE INITIALIZATION COMPLETE!' as status;
SELECT '📊 Total Tables: 8' as info;
SELECT '🔑 Total Indexes: 20+' as info;
SELECT '⚡ Functions: 7' as info;
SELECT '🎯 Triggers: 4' as info;
