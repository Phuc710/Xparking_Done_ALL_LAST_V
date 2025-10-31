
import os
import logging
from typing import Dict, Any, List, Optional
from supabase import create_client, Client
from datetime import datetime, timezone, timedelta
from functools import lru_cache
import asyncio
from config import get_vn_time

logger = logging.getLogger('XParking.Database')

class DatabaseAPI:
    def __init__(self):
        self.url = "https://ckzftuatmaauxfcygdax.supabase.co"
        self.key = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImNremZ0dWF0bWFhdXhmY3lnZGF4Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3NjE5MDA1NDAsImV4cCI6MjA3NzQ3NjU0MH0.Q_iY5rF5MtHSpbd9D1ZeNVyM-mwgV6NrmnaHeFjH-uU"
        self.client: Client = create_client(self.url, self.key)
        self.realtime_subscriptions = {}
        
    def init_realtime_subscriptions(self):
        """Initialize realtime subscriptions for parking slots"""
        try:
            # NOTE: Supabase Python SDK không hỗ trợ realtime như JavaScript
            # Realtime chỉ hoạt động với supabase-js
            # Để sử dụng realtime, cần dùng WebSocket riêng hoặc polling
            
            logger.warning("⚠️  Realtime không khả dụng trong Python SDK")
            logger.info("💡 Sử dụng polling thay thế (auto-refresh mỗi 5s)")
            return False
            
        except Exception as e:
            logger.error(f"❌ Failed to initialize realtime subscriptions: {e}")
            return False
    
    def _handle_slot_change(self, payload):
        """Handle realtime slot status changes"""
        try:
            event_type = payload['eventType']
            record = payload['new'] if event_type != 'DELETE' else payload['old']
            
            logger.info(f"🔄 Slot {record['id']} changed: {record['status']}")
            
            # Emit to web clients via WebSocket (implement later)
            self._emit_to_web_clients('slot_update', {
                'slot_id': record['id'],
                'status': record['status'],
                'timestamp': get_vn_time()
            })
            
        except Exception as e:
            logger.error(f"Error handling slot change: {e}")
    
    def _handle_vehicle_change(self, payload):
        """Handle realtime vehicle changes"""
        try:
            event_type = payload['eventType']
            record = payload['new'] if event_type != 'DELETE' else payload['old']
            
            logger.info(f"🚗 Vehicle {record['license_plate']} status: {record['status']}")
            
        except Exception as e:
            logger.error(f"Error handling vehicle change: {e}")
    
    def _handle_payment_change(self, payload):
        """Handle realtime payment changes"""
        try:
            event_type = payload['eventType']
            record = payload['new'] if event_type != 'DELETE' else payload['old']
            
            logger.info(f"💳 Payment {record['id']} status: {record['status']}")
            
            # Emit payment status to web clients
            self._emit_to_web_clients('payment_update', {
                'payment_id': record['id'],
                'status': record['status'],
                'amount': record.get('amount', 0)
            })
            
        except Exception as e:
            logger.error(f"Error handling payment change: {e}")
    
    def _emit_to_web_clients(self, event_type: str, data: Dict[str, Any]):
        """Emit events to web clients (placeholder for WebSocket implementation)"""
        # TODO: Implement WebSocket server to push to web clients
        logger.info(f"📡 Emitting {event_type}: {data}")
    
    # Database operations
    def get_available_slots(self) -> List[Dict[str, Any]]:
        """Get available parking slots"""
        try:
            response = self.client.rpc('get_available_slots').execute()
            return response.data if response.data else []
        except Exception as e:
            logger.error(f"Error getting available slots: {e}")
            return []
    
    def get_slot_status(self, slot_id: str) -> Optional[Dict[str, Any]]:
        """Get specific slot status"""
        try:
            response = self.client.table('parking_slots').select('*').eq('id', slot_id).single().execute()
            return response.data
        except Exception as e:
            logger.error(f"Error getting slot {slot_id}: {e}")
            return None
    
    def update_slot_status(self, slot_id: str, status: str, rfid: str = None) -> bool:
        """Update slot status"""
        try:
            update_data = {
                'status': status,
                'updated_at': get_vn_time()
            }
            if rfid:
                update_data['rfid_assigned'] = rfid
                
            response = self.client.table('parking_slots').update(update_data).eq('id', slot_id).execute()
            return len(response.data) > 0
        except Exception as e:
            logger.error(f"Error updating slot {slot_id}: {e}")
            return False
    
    def _compress_base64_image(self, base64_str: str, quality: int = 60) -> Optional[str]:
        """
        Nén ảnh base64 để giảm kích thước ~50-70%
        Dùng cho lưu vào database
        """
        try:
            import base64
            import io
            from PIL import Image
            
            # Decode base64
            image_data = base64.b64decode(base64_str)
            image = Image.open(io.BytesIO(image_data))
            
            # Resize nếu quá lớn (max 800px width)
            max_width = 800
            if image.width > max_width:
                ratio = max_width / image.width
                new_size = (max_width, int(image.height * ratio))
                image = image.resize(new_size, Image.LANCZOS)
            
            # Convert to RGB nếu cần
            if image.mode != 'RGB':
                image = image.convert('RGB')
            
            # Nén với quality thấp hơn
            output = io.BytesIO()
            image.save(output, format='JPEG', quality=quality, optimize=True)
            compressed_data = output.getvalue()
            
            # Encode lại base64
            compressed_base64 = base64.b64encode(compressed_data).decode('utf-8')
            
            logger.info(f"Nén ảnh: {len(base64_str)} → {len(compressed_base64)} bytes ({len(compressed_base64)/len(base64_str)*100:.1f}%)")
            return compressed_base64
            
        except Exception as e:
            logger.error(f"Lỗi nén ảnh: {e}")
            return base64_str  # Return original nếu lỗi
    
    def record_vehicle_entry(self, license_plate: str, slot_id: str, rfid: str, entry_time: str = None, entry_image_base64: str = None) -> bool:
        """
        Ghi xe vào bãi + lưu ảnh base64 nén
        Args:
            entry_image_base64: Base64 string của ảnh (sẽ tự động nén)
        """
        try:
            if not entry_time:
                entry_time = get_vn_time()
            
            # Nén ảnh nếu có
            compressed_image = None
            if entry_image_base64:
                compressed_image = self._compress_base64_image(entry_image_base64, quality=60)
            
            vehicle_data = {
                'license_plate': license_plate,
                'slot_id': slot_id,
                'rfid_tag': rfid,
                'entry_time': entry_time,
                'entry_image': compressed_image,  # Lưu ảnh base64 đã nén
                'status': 'in_parking',
                'created_at': get_vn_time()
            }
            
            response = self.client.table('vehicles').insert(vehicle_data).execute()
            
            if response.data:
                # Update slot status
                self.update_slot_status(slot_id, 'occupied', rfid)
                # Update RFID status
                self.client.table('rfid_pool').update({
                    'status': 'assigned',
                    'assigned_at': get_vn_time()
                }).eq('uid', rfid).execute()
                
                logger.info(f"✅ Vehicle {license_plate} entered slot {slot_id} (với ảnh: {bool(compressed_image)})")
                return True
            return False
            
        except Exception as e:
            logger.error(f"Error recording vehicle entry: {e}")
            return False
    
    def get_vehicle_by_rfid(self, rfid: str) -> Optional[Dict[str, Any]]:
        """Get vehicle by RFID"""
        try:
            response = self.client.table('vehicles').select('*').eq('rfid_tag', rfid).eq('status', 'in_parking').single().execute()
            return response.data
        except Exception as e:
            logger.error(f"Error getting vehicle by RFID {rfid}: {e}")
            return None
    
    def complete_vehicle_exit(self, rfid: str, license_plate: str, exit_time: str, paid: bool, exit_image_base64: str = None) -> bool:
        """
        Complete vehicle exit + lưu ảnh base64 nén
        Args:
            exit_image_base64: Base64 string của ảnh (sẽ tự động nén)
        """
        try:
            # Nén ảnh nếu có
            compressed_image = None
            if exit_image_base64:
                compressed_image = self._compress_base64_image(exit_image_base64, quality=60)
            
            # Update vehicle
            update_data = {
                'exit_time': exit_time,
                'exit_image': compressed_image,  # Lưu ảnh ra đã nén
                'status': 'exited'
            }
            
            response = self.client.table('vehicles').update(update_data).eq('rfid_tag', rfid).eq('status', 'in_parking').execute()
            
            if response.data:
                vehicle = response.data[0]
                slot_id = vehicle['slot_id']
                
                # Update slot status
                self.update_slot_status(slot_id, 'empty')
                
                # Release RFID
                self.client.table('rfid_pool').update({
                    'status': 'available',
                    'assigned_at': None
                }).eq('uid', rfid).execute()
                
                logger.info(f"✅ Vehicle {license_plate} exited")
                return True
            return False
            
        except Exception as e:
            logger.error(f"Error completing vehicle exit: {e}")
            return False
    
    def get_available_rfid(self) -> Optional[str]:
        """Get available RFID"""
        try:
            response = self.client.table('rfid_pool').select('uid').eq('status', 'available').limit(1).execute()
            if response.data:
                rfid = response.data[0]['uid']
                # Mark as assigned
                self.client.table('rfid_pool').update({
                    'status': 'assigned',
                    'assigned_at': get_vn_time()
                }).eq('uid', rfid).execute()
                return rfid
            return None
        except Exception as e:
            logger.error(f"Error getting available RFID: {e}")
            return None
    
    def get_available_rfids(self) -> List[str]:
        """Get list of available RFIDs"""
        try:
            response = self.client.table('rfid_pool').select('uid').eq('status', 'available').execute()
            return [item['uid'] for item in response.data] if response.data else []
        except Exception as e:
            logger.error(f"Error getting available RFIDs: {e}")
            return []
    
    def get_vehicle_by_license_plate(self, license_plate: str) -> Optional[Dict[str, Any]]:
        """Get vehicle by license plate"""
        try:
            response = self.client.table('vehicles').select('*').eq('license_plate', license_plate).eq('status', 'in_parking').order('entry_time', desc=True).limit(1).execute()
            return response.data[0] if response.data else None
        except Exception as e:
            logger.error(f"Error getting vehicle by plate {license_plate}: {e}")
            return None
    
    def get_active_booking(self, license_plate: str) -> Optional[Dict[str, Any]]:
        """Get active booking for license plate"""
        try:
            response = self.client.rpc('get_active_booking', {'p_license_plate': license_plate}).execute()
            if response.data:
                booking = response.data[0] if isinstance(response.data, list) else response.data
                return {
                    'has_booking': True,
                    'is_active': True,
                    'id': booking.get('id'),
                    'slot_id': booking.get('slot_id'),
                    'start_time': booking.get('start_time'),
                    'end_time': booking.get('end_time')
                }
            return None
        except Exception as e:
            logger.error(f"Error getting active booking: {e}")
            return None
    
    def calculate_parking_fee(self, license_plate: str, entry_time: str) -> Optional[int]:
        """Calculate parking fee"""
        try:
            from datetime import datetime
            
            # Check if has valid booking
            booking = self.get_active_booking(license_plate)
            
            # Parse times
            if isinstance(entry_time, str):
                entry_dt = datetime.fromisoformat(entry_time.replace('Z', '+00:00'))
            else:
                entry_dt = entry_time
            
            current_dt = datetime.now()
            
            # Has valid booking - free
            if booking and booking.get('is_active'):
                end_time = datetime.fromisoformat(booking['end_time'])
                if current_dt <= end_time:
                    logger.info("Xe có booking hợp lệ - miễn phí")
                    return 0
                else:
                    logger.info("Booking đã hết hạn - tính phí")
                    return 10000
            
            # No booking - calculate by time
            duration = current_dt - entry_dt
            hours = max(1, int(duration.total_seconds() / 3600))
            fee = hours * 10000  # 10,000 VND/hour
            
            logger.info(f"Không có booking - {hours} giờ = {fee:,} VND")
            return fee
            
        except Exception as e:
            logger.error(f"Error calculating fee: {e}")
            return None
    
    def create_payment_with_snowflake_id(self, amount: int, description: str, user_id: int = None, 
                                         booking_id: int = None, expire_minutes: int = 10) -> Optional[Dict[str, Any]]:
        """
        Create payment với Snowflake ID
        Args:
            expire_minutes: Thời gian hết hạn (10 phút cho booking web, 3 phút cho popup Python)
        """
        try:
            # Generate Snowflake-like ID (simplified)
            timestamp = int(datetime.now().timestamp() * 1000)  # milliseconds
            machine_id = 1  # static for now
            sequence = 0  # static for now
            
            # Snowflake format: timestamp(41) + machine(10) + sequence(12) = 63 bits
            snowflake_id = (timestamp << 22) | (machine_id << 12) | sequence
            
            # Tính expires_at theo expire_minutes
            from datetime import datetime, timedelta
            expires_at = datetime.now() + timedelta(minutes=expire_minutes)
            
            payment_data = {
                'id': str(snowflake_id),
                'amount': amount,
                'description': description,
                'user_id': user_id,
                'booking_id': booking_id,
                'status': 'pending',
                'expires_at': expires_at.strftime('%Y-%m-%d %H:%M:%S'),
                'created_at': get_vn_time()
            }
            
            response = self.client.table('payments').insert(payment_data).execute()
            return response.data[0] if response.data else None
            
        except Exception as e:
            logger.error(f"Error creating payment: {e}")
            return None
    
    def update_payment_status(self, payment_id: str, status: str) -> bool:
        """Update payment status"""
        try:
            response = self.client.table('payments').update({
                'status': status,
                'updated_at': get_vn_time()
            }).eq('id', payment_id).execute()
            return len(response.data) > 0
        except Exception as e:
            logger.error(f"Error updating payment {payment_id}: {e}")
            return False
    
    def expire_old_payments(self) -> int:
        """Expire payments older than 10 minutes"""
        try:
            current_time = get_vn_time()
            response = self.client.table('payments').update({
                'status': 'expired'
            }).lt('expires_at', current_time).eq('status', 'pending').execute()
            
            expired_count = len(response.data) if response.data else 0
            if expired_count > 0:
                logger.info(f"⏰ Expired {expired_count} old payments")
            return expired_count
            
        except Exception as e:
            logger.error(f"Error expiring payments: {e}")
            return 0

    # ===================================================
    # CACHE MANAGEMENT - Thêm để tương thích với code cũ
    # ===================================================
    
    @lru_cache(maxsize=32)
    def get_cached_slots_status(self) -> Dict[str, Any]:
        """Lấy slots với cache (tương thích db_api cũ)"""
        try:
            slots = self.get_available_slots()
            return {
                'available_slots': slots or [],
                'available_count': len(slots) if slots else 0,
                'total_slots': 4,
                'occupied_slots': 4 - (len(slots) if slots else 0)
            }
        except Exception as e:
            logger.error(f"Lỗi lấy cached slots: {e}")
            return {'available_slots': [], 'available_count': 0, 'total_slots': 4, 'occupied_slots': 0}
    
    @lru_cache(maxsize=16)
    def get_available_rfid_cached(self) -> Optional[str]:
        """Lấy RFID với cache (tương thích db_api cũ)"""
        try:
            return self.get_available_rfid()
        except Exception as e:
            logger.error(f"Lỗi lấy cached RFID: {e}")
            return None
    
    def check_booking_fast(self, license_plate: str) -> Optional[Dict[str, Any]]:
        """Check booking nhanh (tương thích db_api cũ)"""
        return self.get_active_booking(license_plate)
    
    def get_vehicle_by_plate(self, license_plate: str) -> Optional[Dict[str, Any]]:
        """Tìm xe theo BSX (tương thích db_api cũ)"""
        return self.get_vehicle_by_license_plate(license_plate)
    
    def rollback_rfid(self, rfid: str) -> bool:
        """Thu hồi RFID về pool"""
        try:
            response = self.client.table('rfid_pool').update({
                'status': 'available',
                'assigned_at': None,
                'assigned_to_vehicle': None
            }).eq('uid', rfid).execute()
            
            if response.data:
                logger.info(f"✅ Thu hồi RFID: {rfid}")
                self.clear_cache()
                return True
            return False
        except Exception as e:
            logger.error(f"Lỗi rollback RFID: {e}")
            return False
    
    def update_booking_status(self, booking_id: int, status: str, slot_id: str = None) -> bool:
        """Cập nhật trạng thái booking"""
        try:
            update_data = {'status': status}
            if slot_id:
                update_data['slot_id'] = slot_id
            
            response = self.client.table('bookings').update(update_data).eq('id', booking_id).execute()
            
            if response.data:
                logger.info(f"✅ Cập nhật booking {booking_id} → {status}")
                return True
            return False
        except Exception as e:
            logger.error(f"Lỗi update booking: {e}")
            return False
    
    def calculate_smart_fee(self, license_plate: str, entry_time: str) -> Optional[int]:
        """Tính phí thông minh (tương thích db_api cũ)"""
        return self.calculate_parking_fee(license_plate, entry_time)
    
    def clear_cache(self):
        """Xóa tất cả cache"""
        try:
            self.get_cached_slots_status.cache_clear()
            self.get_available_rfid_cached.cache_clear()
        except Exception as e:
            logger.error(f"Lỗi xóa cache: {e}")
        return True
    
    def get_vn_time(self, format_str='%Y-%m-%d %H:%M:%S'):
        """Lấy thời gian VN (tương thích)"""
        return get_vn_time(format_str)

# ===================================================
# GLOBAL INSTANCE - Sử dụng trong toàn bộ hệ thống
# ===================================================
DatabaseAPI_instance = DatabaseAPI()
