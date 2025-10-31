
import sys
import os
import subprocess
import threading
import time
import logging
import signal
from pathlib import Path

# Thêm thư mục hiện tại vào Python path
current_dir = Path(__file__).parent
sys.path.insert(0, str(current_dir))

# Setup logging - Chỉ ghi file, không hiện console
logging.basicConfig(
    level=logging.INFO,
    format='[%(levelname)s] %(message)s',
    handlers=[
        logging.FileHandler('xparking_system.log', encoding='utf-8')
    ]
)

# Thêm console handler riêng cho thông báo quan trọng
console_handler = logging.StreamHandler(sys.stdout)
console_handler.setLevel(logging.WARNING)  # Chỉ hiện WARNING và ERROR
logging.getLogger().addHandler(console_handler)

# Tắt tất cả logs không cần thiết
logging.getLogger('httpx').setLevel(logging.CRITICAL)
logging.getLogger('httpcore').setLevel(logging.CRITICAL)
logging.getLogger('uvicorn').setLevel(logging.CRITICAL)
logging.getLogger('uvicorn.access').setLevel(logging.CRITICAL)
logging.getLogger('uvicorn.error').setLevel(logging.CRITICAL)

# Tắt stderr của OpenCV
import os
os.environ['OPENCV_LOG_LEVEL'] = 'SILENT'
os.environ['OPENCV_VIDEOIO_DEBUG'] = '0'

logger = logging.getLogger('XParking.Launcher')

class SystemLauncher:
    def __init__(self):
        self.processes = {}
        self.shutdown_event = threading.Event()
        
    def check_dependencies(self):
        """Kiểm tra các dependencies cần thiết"""
        required_modules = [
            'cv2', 'tkinter', 'PIL', 'paho.mqtt.client', 
            'requests', 'supabase', 'websockets', 'fastapi'
        ]
        
        missing_modules = []
        
        for module in required_modules:
            try:
                if module == 'cv2':
                    import cv2
                elif module == 'tkinter':
                    import tkinter
                elif module == 'PIL':
                    from PIL import Image
                elif module == 'paho.mqtt.client':
                    import paho.mqtt.client
                elif module == 'supabase':
                    from supabase import create_client
                elif module == 'websockets':
                    import websockets
                elif module == 'fastapi':
                    from fastapi import FastAPI
                else:
                    __import__(module)
            except ImportError:
                missing_modules.append(module)
        
        if missing_modules:
            logger.error("Thiếu các module bắt buộc:")
            for module in missing_modules:
                logger.error(f"  - {module}")
            logger.error("Chạy lệnh: pip install -r requirements_supabase.txt")
            return False
        
        return True
    
    def start_websocket_server(self):
        """Khởi động WebSocket server"""
        try:
            
            # Import và chạy WebSocket server
            from websocket_server import run_websocket_server
            
            def run_websocket():
                try:
                    run_websocket_server(host="localhost", port=8080)
                except Exception as e:
                    logger.error(f"Lỗi WebSocket server: {e}")
            
            websocket_thread = threading.Thread(target=run_websocket, daemon=True)
            websocket_thread.start()
            
            # Đợi server khởi động
            time.sleep(2)
            
            # Test connection
            import requests
            try:
                response = requests.get("http://localhost:8080/api/status", timeout=5)
                if response.status_code == 200:
                    pass
                return True
            except:
                pass
            
            logger.warning("⚠️  WebSocket chưa sẵn sàng")
            return True
            
        except Exception as e:
            logger.error(f"❌ WebSocket lỗi: {e}")
            return False
    
    def test_supabase_connection(self):
        """Test kết nối Supabase"""
        try:
            pass
            from db_api import DatabaseAPI_instance as db
            
            # Test basic connection
            result = db.get_available_slots()
            pass
            return True
            
        except Exception as e:
            logger.error(f"❌ Database lỗi: {e}")
            logger.error("Hệ thống yêu cầu Supabase để hoạt động!")
            return False
    
    def start_main_application(self):
        """Khởi động ứng dụng chính"""
        try:
            pass
            
            from main import XParkingSystem
            
            # Tạo và chạy hệ thống
            system = XParkingSystem()
            system.run()
            
        except KeyboardInterrupt:
            logger.info("Nhận lệnh dừng từ bàn phím")
        except Exception as e:
            logger.error(f"Lỗi ứng dụng chính: {e}")
            import traceback
            traceback.print_exc()
    
    def setup_signal_handlers(self):
        """Thiết lập signal handlers"""
        def signal_handler(sig, frame):
            logger.info(f"Nhận signal {sig} - đang tắt hệ thống...")
            self.shutdown_event.set()
            sys.exit(0)
        
        signal.signal(signal.SIGINT, signal_handler)
        signal.signal(signal.SIGTERM, signal_handler)
    
    def print_system_info(self):
        """In thông tin hệ thống"""
        pass
        
        # Test camera
        try:
            import cv2
            pass
            
            cap = cv2.VideoCapture(0)
            if cap.isOpened():
                pass
                cap.release()
            else:
                logger.warning("⚠️  Camera: Không OK")
        except Exception as e:
            logger.error(f"Kiểm tra camera lỗi: {e}")
        
        # Test Supabase
        supabase_ok = self.test_supabase_connection()
        
        pass
        
        return supabase_ok
    
    def run(self):
        """Chạy toàn bộ hệ thống"""
        try:
            pass
            # logger.info("🅿️  XPARKING SYSTEM")
            
            # Setup signal handlers
            self.setup_signal_handlers()
            
            # Kiểm tra dependencies
            if not self.check_dependencies():
                logger.error("❌ Lỗi: Thiếu dependencies")
                return False
            
            # In thông tin hệ thống
            supabase_available = self.print_system_info()
            
            # Khởi động WebSocket server (nếu Supabase available)
            if supabase_available:
                websocket_ok = self.start_websocket_server()
                if not websocket_ok:
                    logger.warning("⚠️  WebSocket bỏ qua")
            
            # Khởi động ứng dụng chính
            print("\n" + "="*60)
            print("✅ HỆ THỐNG KHỞI ĐỘNG THÀNH CÔNG")
            print("="*60 + "\n")
            
            self.start_main_application()
            
            return True
            
        except Exception as e:
            logger.error(f"Lỗi khởi động hệ thống: {e}")
            import traceback
            traceback.print_exc()
            return False
    
    def cleanup(self):
        """Dọn dẹp khi tắt hệ thống"""
        logger.info("Đang dọn dẹp hệ thống...")
        
        for name, process in self.processes.items():
            try:
                if process and process.poll() is None:
                    logger.info(f"Đang dừng {name}...")
                    process.terminate()
                    process.wait(timeout=5)
            except:
                pass
        
        logger.info("Dọn dẹp hoàn tất")

def main():
    """Hàm main"""
    launcher = SystemLauncher()
    
    try:
        success = launcher.run()
        if not success:
            logger.error("Khởi động hệ thống thất bại")
            sys.exit(1)
    except KeyboardInterrupt:
        logger.info("Nhận lệnh dừng từ bàn phím")
    except Exception as e:
        logger.error(f"Lỗi không mong muốn: {e}")
        sys.exit(1)
    finally:
        launcher.cleanup()

if __name__ == "__main__":
    main()
