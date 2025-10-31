import sys
import logging
import threading
import time
from concurrent.futures import ThreadPoolExecutor
import asyncio

# Import các module đã tối ưu
from config import SystemConfig, GUIManager
from email_handler import EmailHandler
from functions import SystemFunctions

# Modules bên ngoài
from QUET_BSX import OptimizedLPR
from payment import PaymentManager
from db_api import DatabaseAPI_instance as db_api
from websocket_server import manager as websocket_manager
from init_data import check_and_recover_data  # Auto data recovery

# Cấu hình logging - Chỉ hiện WARNING và ERROR
logging.basicConfig(
    level=logging.WARNING,
    format='[%(levelname)s] %(message)s',
    handlers=[logging.StreamHandler(sys.stdout)]
)

# Tắt logs không cần thiết
logging.getLogger('httpx').setLevel(logging.ERROR)
logging.getLogger('httpcore').setLevel(logging.ERROR)

logger = logging.getLogger('XParking')

class XParkingSystem:
    def __init__(self):
        """Khởi tạo hệ thống XParking"""
        logger.info("⚙️  Khởi tạo modules...")
        
        # Khởi tạo các thành phần cốt lõi
        self.config_manager = SystemConfig()
        self.gui_manager = GUIManager(self.config_manager)
        self.lpr_system = OptimizedLPR()
        self.payment_manager = PaymentManager(self)
        self.db_api = db_api  # Sử dụng global instance
        self.email_handler = EmailHandler(self.config_manager)
        
        # Khởi tạo Functions chính - db_api đã là DatabaseAPI
        self.functions = SystemFunctions(
            self.config_manager, self.gui_manager, self.lpr_system, 
            self.db_api, self.payment_manager, self.email_handler, self.db_api
        )
        
        # Khởi tạo executor pool cho xử lý song song
        self.executor = ThreadPoolExecutor(max_workers=8)
        
        # GUI root reference
        self.root = None
        
        logger.info("✅ Modules OK")

    def run(self):
        """Chạy hệ thống chính"""
        try:
            logger.info("🚀 Khởi động XParking...")
            
            # 0. Kiểm tra và khôi phục dữ liệu
            logger.info("[1/4] Kiểm tra dữ liệu...")
            if not check_and_recover_data():
                logger.error("❌ Không thể khôi phục dữ liệu! Dừng hệ thống.")
                return
            
            # 1. Khởi tạo GUI
            logger.info("[2/4] Khởi tạo GUI...")
            self.root = self.gui_manager.init_gui(self)
            
            # 2. Khởi tạo Supabase realtime
            logger.info("[3/4] Supabase realtime...")
            self.root.after(100, self._init_supabase_realtime)
            
            # 3. Khởi tạo delayed components
            logger.info("[4/4] Components...")
            self.root.after(200, self._delayed_init)
            
            logger.info("✅ Hệ thống sẵn sàng!")
            
            # 4. Chạy GUI main loop
            self.root.mainloop()
            
        except KeyboardInterrupt:
            logger.info("Nhận lệnh ngắt từ bàn phím")
        except Exception as e:
            logger.error(f"Lỗi chạy hệ thống: {e}")
            import traceback
            traceback.print_exc()
        finally:
            self.shutdown()

    def _init_supabase_realtime(self):
        """Khởi tạo Supabase realtime connections"""
        try:
            success = db_api.init_realtime_subscriptions()
            if success:
                logger.info("✅ Realtime OK")
            else:
                logger.warning("⚠️  Realtime bỏ qua")
        except Exception as e:
            logger.warning("⚠️  Realtime bỏ qua")

    def _delayed_init(self):
        """Khởi tạo các thành phần sau khi GUI đã sẵn sàng"""
        try:
            # Kết nối MQTT
            mqtt_success = self.functions.init_mqtt()
            if mqtt_success:
                logger.info("✅ MQTT OK")
            else:
                logger.warning("⚠️  MQTT bỏ qua")
            self.gui_manager.update_status('mqtt_status', mqtt_success)
            
            # Khởi tạo cameras
            cam_success = self.gui_manager.init_cameras(self.gui_manager.update_status)
            if cam_success:
                logger.info("✅ Cameras OK")
            else:
                logger.warning("⚠️  Cameras bỏ qua")
            
            # Load AI model trong background
            self.gui_manager.update_status('ai_status', False)
            threading.Thread(target=self._init_ai_model, daemon=True).start()
            
            # Gas sensor mặc định OK
            self.gui_manager.update_status('gas_status', True)
            
            logger.info("✅ Components OK")
            
        except Exception as e:
            logger.error(f"Lỗi khởi tạo delayed components: {e}")

    def _init_ai_model(self):
        """Khởi tạo AI model trong background thread"""
        try:
            if self.lpr_system.load_models():
                if self.root:
                    self.root.after(0, lambda: self.gui_manager.update_status('ai_status', True))
                logger.info("✅ AI model OK")
            else:
                if self.root:
                    self.root.after(0, lambda: self.gui_manager.update_status('ai_status', False))
                logger.warning("⚠️  AI model bỏ qua")
        except Exception as e:
            logger.warning("⚠️  AI model bỏ qua")
            if self.root:
                self.root.after(0, lambda: self.gui_manager.update_status('ai_status', False))

    def shutdown(self):
        """Tắt hệ thống an toàn"""
        logger.info("🛑 Đang tắt...")
        try:
            if hasattr(self, 'functions'):
                self.functions.shutdown()
            if hasattr(self, 'executor'):
                self.executor.shutdown(wait=True)
            # Đóng Database connections
            if hasattr(db_api, 'client'):
                logger.info("🔌 Đóng Database")
        except Exception as e:
            logger.error(f"Lỗi khi tắt functions: {e}")
        
        logger.info("✅ Đã tắt")

    # Các phương thức hỗ trợ cho PaymentManager và các modules khác
    def update_slot_status(self, slot_id, status):
        """Cập nhật trạng thái slot"""
        if hasattr(self, 'gui_manager'):
            self.gui_manager.update_slot_status(slot_id, status)

    def update_status(self, key, is_active):
        """Cập nhật status indicator"""
        if hasattr(self, 'gui_manager'):
            self.gui_manager.update_status(key, is_active)

# Entry point chính
if __name__ == "__main__":
    system = None
    try:
        system = XParkingSystem()
        system.run()
    except Exception as e:
        logger.error(f"Lỗi khởi động hệ thống: {e}")
        import traceback
        traceback.print_exc()
    finally:
        if system:
            system.shutdown()