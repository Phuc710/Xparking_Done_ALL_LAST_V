"""
Auto Data Recovery & Initialization
Tự động khôi phục và kiểm tra dữ liệu khi khởi động Python
"""

import logging
from db_api import DatabaseAPI_instance as db_api

logging.basicConfig(level=logging.WARNING, format='[%(levelname)s] %(message)s')

# Tắt HTTP logs
logging.getLogger('httpx').setLevel(logging.ERROR)
logging.getLogger('httpcore').setLevel(logging.ERROR)

logger = logging.getLogger(__name__)

def check_and_recover_data():
    """
    Kiểm tra và khôi phục dữ liệu cần thiết
    """
    logger.info("🔍 Kiểm tra dữ liệu...")
    
    try:
        # 1. Kiểm tra kết nối database
        logger.info("[1/5] Kết nối Supabase...")
        slots_data = db_api.get_cached_slots_status()
        if slots_data and isinstance(slots_data, dict):
            slots = slots_data.get('available_slots', [])
            logger.info(f"✅ Supabase OK - {slots_data.get('total_slots', 0)} slots")
        else:
            logger.error("❌ Không thể kết nối Supabase!")
            return False
        
        # 2. Kiểm tra và đồng bộ slots
        logger.info("[2/5] Đồng bộ slots...")
        expected_slots = ['A01', 'A02', 'A03', 'A04']
        
        # Lấy tất cả slots từ database
        all_slots = db_api.client.table('parking_slots').select('*').execute().data
        
        for slot_id in expected_slots:
            slot_exists = any(s['id'] == slot_id for s in all_slots)
            if not slot_exists:
                logger.warning(f"⚠️  Tạo slot {slot_id}...")
                # Tạo slot mới nếu cần
                slot_data = {
                    'id': slot_id,
                    'status': 'empty',
                    'rfid_assigned': 'empty',
                    'vehicle_id': None
                }
                result = db_api.client.table('parking_slots').insert(slot_data).execute()
                if result.data:
                    logger.info(f"✅ Đã tạo slot {slot_id}")
            else:
                logger.info(f"✅ Slot {slot_id} OK")
        
        # 3. Kiểm tra RFID pool
        logger.info("[3/5] Kiểm tra RFID...")
        rfids = db_api.get_available_rfids()
        
        if not rfids:
            logger.warning("⚠️  Tạo RFID mặc định...")
            default_rfids = ['RFID001', 'RFID002', 'RFID003', 'RFID004', 'RFID005', 
                           'RFID006', 'RFID007', 'RFID008']
            
            for rfid_uid in default_rfids:
                rfid_data = {
                    'uid': rfid_uid,
                    'status': 'available',
                    'assigned_to_vehicle': None,
                    'usage_count': 0
                }
                result = db_api.client.table('rfid_pool').insert(rfid_data).execute()
                if result.data:
                    logger.info(f"✅ Đã tạo RFID {rfid_uid}")
        else:
            logger.info(f"✅ Tìm thấy {len(rfids)} RFID khả dụng")
            
            # Kiểm tra RFID bị stuck (assigned nhưng không có vehicle)
            all_rfids = db_api.client.table('rfid_pool').select('*').execute().data
            for rfid in all_rfids:
                if rfid['status'] == 'assigned':
                    # Check nếu vehicle tương ứng đã exit
                    vehicle_id = rfid.get('assigned_to_vehicle')
                    if vehicle_id:
                        vehicle = db_api.client.table('vehicles').select('*').eq('id', vehicle_id).single().execute().data
                        if vehicle and vehicle['status'] == 'exited':
                            # Release RFID
                            logger.warning(f"⚠️  RFID {rfid['uid']} bị stuck - đang giải phóng...")
                            db_api.client.table('rfid_pool').update({
                                'status': 'available',
                                'assigned_to_vehicle': None
                            }).eq('uid', rfid['uid']).execute()
                            logger.info(f"✅ Đã giải phóng RFID {rfid['uid']}")
        
        # 4. Kiểm tra vehicles
        logger.info("[4/5] Kiểm tra xe trong bãi...")
        vehicles_in_parking = db_api.client.table('vehicles').select('*').eq('status', 'in_parking').execute().data
        
        if vehicles_in_parking:
            logger.info(f"📊 Có {len(vehicles_in_parking)} xe đang trong bãi")
            
            for vehicle in vehicles_in_parking:
                slot_id = vehicle['slot_id']
                
                # Check slot status - lấy từ all_slots
                slot = next((s for s in all_slots if s['id'] == slot_id), None)
                if slot:
                    if slot['status'] != 'occupied':
                        logger.warning(f"⚠️  Fix slot {slot_id}")
                        # Fix slot status
                        db_api.update_slot_status(slot_id, 'occupied', vehicle['rfid_tag'])
                        logger.info(f"✅ Đã cập nhật slot {slot_id} → occupied")
                    else:
                        logger.info(f"✅ Slot {slot_id} OK")
        else:
            logger.info("✅ Không có xe nào trong bãi")
        
        # 5. Expire old payments
        logger.info("[5/5] Dọn dẹp payments...")
        expired_count = db_api.expire_old_payments()
        logger.info(f"✅ Expired {expired_count} payments")
        
        # Clear cache
        db_api.clear_cache()
        logger.info("✅ Cache cleared")
        
        logger.info("✅ Dữ liệu đã sẵn sàng!")
        
        return True
        
    except Exception as e:
        logger.error(f"❌ Lỗi: {e}")
        import traceback
        traceback.print_exc()
        return False

if __name__ == "__main__":
    # Test standalone
    success = check_and_recover_data()
    if success:
        print("\n✅ Hệ thống sẵn sàng!")
    else:
        print("\n❌ Có lỗi xảy ra!")

