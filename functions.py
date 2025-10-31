import time
import json
import threading
import logging
from concurrent.futures import ThreadPoolExecutor, as_completed
from functools import lru_cache
import re
import paho.mqtt.client as mqtt
from config import get_vn_time

logger = logging.getLogger('XParking')

class SystemFunctions:
    def __init__(self, system_config, gui_manager, lpr_system, db_api, payment_manager, email_handler, database=None):
        self.config = system_config
        self.gui = gui_manager
        self.lpr_system = lpr_system
        self.db_api = db_api  # db_api bây giờ chính là DatabaseAPI từ database.py
        self.payment_manager = payment_manager
        self.email_handler = email_handler
        # db_api đã là instance của DatabaseAPI nên có đầy đủ chức năng
        
        self.mqtt_client = None
        self.executor = ThreadPoolExecutor(max_workers=8)  # Tăng workers
        self.processing_lock = threading.Lock()
        self.pre_recognition_data = {}
        
        # Tối ưu state management
        self.vehicle_states = {
            'entry': {'processing': False, 'last_plate': None, 'timestamp': 0},
            'exit': {'processing': False, 'last_plate': None, 'timestamp': 0}
        }
        
        # Cache cho tìm kiếm nhanh
        self.cache = {
            'available_rfids': [],
            'available_slots': [],
            'last_cache_update': 0,
            'cache_ttl': 5  # 5 giây TTL
        }
        
        # Regex cho biển số Việt Nam
        self.plate_regex = re.compile(r'^[0-9]{2}[A-Z]{1,2}[0-9]{4,5}$')

    def init_mqtt(self):
        """Khởi tạo MQTT với cài đặt tối ưu"""
        try:
            self.mqtt_client = mqtt.Client()
            self.mqtt_client.on_connect = self.on_mqtt_connect
            self.mqtt_client.on_message = self.on_mqtt_message
            self.mqtt_client.on_disconnect = self.on_mqtt_disconnect
            
            # Tắt logs MQTT
            self.mqtt_client.enable_logger(logger=None)
            
            # Tối ưu MQTT settings
            self.mqtt_client.max_inflight_messages_set(20)
            self.mqtt_client.max_queued_messages_set(100)
            
            self.mqtt_client.connect(self.config.config['mqtt_broker'], self.config.config['mqtt_port'], 60)
            self.mqtt_client.loop_start()
            logger.info("Đang kết nối MQTT với cài đặt tối ưu...")
            return True
        except Exception as e:
            logger.error(f"Lỗi kết nối MQTT: {e}")
            return False

    def on_mqtt_connect(self, client, userdata, flags, rc):
        if rc == 0:
            logger.info("Kết nối MQTT thành công")
            self.gui.update_status('mqtt_status', True)
            
            # Subscribe tất cả topics cùng lúc với QoS tối ưu
            topics = [
                ("xparking/entrance", 0),
                ("xparking/exit", 0),
                ("xparking/rfid", 0),
                ("xparking/slots", 0),
                ("xparking/alert", 1),  # QoS 1 cho alerts quan trọng
                ("xparking/status/+", 0)  # Wildcard cho tất cả status
            ]
            
            for topic, qos in topics:
                client.subscribe(topic, qos)
            
            self.send_display_message("in", "X-PARKING", "Entrance")
            self.send_display_message("out", "X-PARKING", "Exit")
            
            # Bắt đầu background tasks
            self._start_background_tasks()
            
            threading.Thread(
                target=self.email_handler.send_recovery_email,
                daemon=True
            ).start()

    def _capture_frame_with_retry(self, camera_type, max_attempts=3):
        for attempt in range(max_attempts):
            try:
                frame = self.gui.capture_frame(camera_type)
                if frame is not None:
                    return frame
                time.sleep(0.1)
            except Exception as e:
                logger.warning(f"Lần chụp frame {attempt + 1} thất bại: {e}")
                if attempt < max_attempts - 1:
                    time.sleep(0.2)
        return None
    
    def _detect_plate_optimized(self, frame):
        try:
            if not self.lpr_system.is_ready():
                logger.warning("Khởi động model AI khẩn cấp")
                if not self.lpr_system.load_models():
                    return {'success': False, 'error': 'Lỗi khởi động model'}
            
            result = self.lpr_system.detect_and_read_plate(frame)
            
            if not result['success'] or not result['plates']:
                return {'success': False, 'error': 'Không tìm thấy biển số'}
            
            plate_data = result['plates'][0]
            license_plate = plate_data['text'].upper().strip()
            confidence = plate_data.get('confidence', 0)
            
            if confidence < 0.5:
                logger.warning(f"Độ tin cậy thấp: {confidence:.2f}")
                return {'success': False, 'error': 'Độ tin cậy thấp'}
            
            if len(license_plate) < 4:
                logger.warning(f"Biển số quá ngắn: '{license_plate}'")
                return {'success': False, 'error': 'Biển số không hợp lệ'}
            
            return {
                'success': True,
                'license_plate': license_plate,
                'confidence': confidence
            }
            
        except Exception as e:
            return {'success': False, 'error': str(e)}

    def shutdown(self):
        logger.info("Đang tắt hệ thống XParking...")
        
        if self.mqtt_client:
            try:
                self.mqtt_client.disconnect()
                self.mqtt_client.loop_stop()
            except:
                pass
        
        if hasattr(self, 'executor'):
            self.executor.shutdown(wait=False)
            
        # Clear cache
        if hasattr(self, 'cache'):
            self.cache.clear()
            
        logger.info("Hệ thống đã shutdown hoàn toàn")

    # ===================================================
    # CÁC PHƯƠNG THỨC TỐI ƯU HÓA MỚI
    # ===================================================
    
    def _start_background_tasks(self):
        """Khởi động các tác vụ nền tối ưu"""
        def cache_refresh_task():
            while True:
                try:
                    time.sleep(30)  # Refresh cache mỗi 30 giây
                    self._refresh_cache_if_needed()
                    # Expire old payments định kỳ
                    self.db_api.expire_old_payments()
                except Exception as e:
                    logger.error(f"Lỗi refresh cache nền: {e}")
        
        threading.Thread(target=cache_refresh_task, daemon=True).start()
        logger.info("Đã khởi động các tác vụ nền tối ưu")
    
    def _refresh_cache_if_needed(self):
        """Refresh cache nếu hết hạn"""
        try:
            now = time.time()
            if now - self.cache['last_cache_update'] > self.cache['cache_ttl']:
                logger.info("Đang refresh cache...")
                
                # Refresh từ database (db_api đã là DatabaseAPI)
                try:
                    self.cache['available_rfids'] = self.db_api.get_available_rfids() or []
                    self.cache['available_slots'] = self.db_api.get_available_slots() or []
                except:
                    # Fallback với cache methods
                    self.cache['available_rfids'] = [self.db_api.get_available_rfid_cached()]
                    slots_result = self.db_api.get_cached_slots_status()
                    self.cache['available_slots'] = slots_result.get('available_slots', []) if slots_result else []
                
                self.cache['last_cache_update'] = now
                logger.info(f"Cache đã refresh: {len(self.cache['available_rfids'])} RFID, {len(self.cache['available_slots'])} slots")
        except Exception as e:
            logger.error(f"Lỗi refresh cache: {e}")
    
    def _is_cache_valid(self):
        """Kiểm tra cache còn hợp lệ không"""
        return (time.time() - self.cache['last_cache_update']) < self.cache['cache_ttl']

    def on_mqtt_disconnect(self, client, userdata, rc):
        logger.warning(f"Mất kết nối MQTT: {rc}")
        self.gui.update_status('mqtt_status', False)

    def on_mqtt_message(self, client, userdata, msg):
        try:
            topic = msg.topic
            payload = msg.payload.decode('utf-8')
            
            if self.config.emergency_mode and not topic.startswith("xparking/alert"):
                logger.warning("Chế độ khẩn cấp đang bật")
                return
            
            data = json.loads(payload)
            event = data.get('event', '')
            
            if topic == "xparking/alert" and event == "EMERGENCY_SMOKE":
                self.handle_emergency_alert(data, True)
            elif topic == "xparking/alert" and event == "EMERGENCY_CLEAR":
                self.handle_emergency_alert(data, False)
                
            elif topic == "xparking/entrance" and event == "CAR_DETECT_IN":
                # Debounce và xử lý tối ưu
                now = time.time()
                if now - self.vehicle_states['entry']['timestamp'] < 2.0:
                    logger.info("Debounce: Phát hiện xe vào quá nhanh, bỏ qua")
                    return
                    
                if not self.vehicle_states['entry']['processing']:
                    self.vehicle_states['entry']['processing'] = True
                    self.vehicle_states['entry']['timestamp'] = now
                    self.executor.submit(self.handle_vehicle_entry_optimized)
            
            elif topic == "xparking/exit" and event == "CAR_DETECT_OUT":
                # Debounce và xử lý tối ưu
                now = time.time()
                if now - self.vehicle_states['exit']['timestamp'] < 2.0:
                    logger.info("Debounce: Phát hiện xe ra quá nhanh, bỏ qua")
                    return
                    
                if not self.vehicle_states['exit']['processing']:
                    self.vehicle_states['exit']['processing'] = True
                    self.vehicle_states['exit']['timestamp'] = now
                    self.executor.submit(self.handle_pre_recognition_optimized)
            
            elif topic == "xparking/rfid" and event == "RFID_SCANNED":
                rfid_uid = data.get('rfid', '')
                threading.Thread(target=self.handle_rfid_scan, args=(rfid_uid,), daemon=True).start()
                
            elif topic == "xparking/slots" and event == "CAR_ENTERED_SLOT":
                slot_id = data.get('data', '')
                threading.Thread(target=self.handle_slot_detection, args=(slot_id,), daemon=True).start()
                
            elif topic == "xparking/slots" and event == "MONITOR_TIMEOUT":
                threading.Thread(target=self.handle_slot_timeout, daemon=True).start()
                    
        except Exception as e:
            logger.error(f"Lỗi xử lý tin nhắn MQTT: {e}")

    def send_display_message(self, station, line1, line2=""):
        message = {
            "event": "SHOW_MESSAGE",
            "line1": line1,
            "line2": line2,
        }
        topic = f"xparking/command/{station}"
        self.publish_mqtt(topic, json.dumps(message))

    def send_barrier_command(self, station, action, data=None):
        message = {
            "event": f"{action.upper()}_BARRIER",
            "timestamp": int(time.time()),
            "data": data
        }
        topic = f"xparking/command/{station}"
        self.publish_mqtt(topic, json.dumps(message))

    def publish_mqtt(self, topic, message):
        if self.mqtt_client and self.mqtt_client.is_connected():
            try:
                self.mqtt_client.publish(topic, message)
            except Exception as e:
                logger.error(f"Lỗi MQTT publish: {e}")

    def handle_vehicle_entry_optimized(self):
        """Xử lý xe vào tối ưu với parallel processing"""
        try:
            logger.info("Phát hiện xe tại cổng vào, bắt đầu tiền xử lý.")
            self.send_display_message("in", "NHAN DIEN BSX", "VUI LONG CHO")
            
            frame = self._capture_frame_with_retry('in', max_attempts=3)
            if frame is None:
                self._handle_entry_error("LOI CAMERA", "Loi chup anh tu camera.")
                self.is_entry_processing = False
                return
            
            logger.info("Bắt đầu nhận diện biển số và kiểm tra CSDL song song.")
            
            # Xử lý song song với cache refresh
            with ThreadPoolExecutor(max_workers=4) as executor:
                plate_future = executor.submit(self._detect_plate_optimized, frame)
                cache_future = executor.submit(self._refresh_cache_if_needed)
                slots_future = executor.submit(self._get_cached_slots)
                rfid_future = executor.submit(self._get_cached_rfid)
            
                try:
                    plate_result = plate_future.result(timeout=5.0)
                    if not plate_result['success']:
                        self._handle_entry_error("KHONG NHAN DIEN", f"Khong nhan dien duoc BSX: {plate_result['error']}")
                        self.is_entry_processing = False
                        return
                    
                    license_plate = plate_result['license_plate']
                    logger.info(f"Nhận diện thành công biển số: {license_plate}")
                    
                    self.gui.update_plate_display('in', license_plate)
                    self.send_display_message("in", "DANG XU LY", license_plate)
                
                except Exception as e:
                    self._handle_entry_error("LOI NHAN DIEN", f"Loi he thong nhan dien: {e}")
                    self.is_entry_processing = False
                    return
            
            booking_data = self.db_api.check_booking_fast(license_plate)
            
            try:
                available_rfid = rfid_future.result(timeout=5.0)
                if booking_data and booking_data.get('has_booking'):
                    slots_status = None
                else:
                    slots_status = slots_future.result(timeout=5.0)
            except Exception as e:
                logger.error(f"Lỗi tác vụ song song: {e}")
                slots_status = {'available_count': 0, 'available_slots': []} 
            
            entry_decision = self._make_entry_decision(
                license_plate, booking_data, slots_status, available_rfid
            )
            
            if not entry_decision['allow_entry']:
                self._handle_entry_denial(entry_decision['reason'], entry_decision['message'])
                self.is_entry_processing = False
                return
            
            logger.info(f"Quyết định cho phép xe vào. Gán RFID: {entry_decision['rfid']}")
            
            # ĐÃ BỎ HOÀN TOÀN ENCODE IMAGE
            
            self.config.current_entry_data = {
                'license_plate': license_plate,
                'rfid': entry_decision['rfid'],
                'entry_time': self.config.get_vn_time(),
                'has_booking': entry_decision.get('has_booking', False),
                'booking_id': entry_decision.get('booking_id')
            }
            
            self._grant_entry_access(entry_decision['rfid'], entry_decision['available_slots'])
            
        except Exception as e:
            logger.error(f"Lỗi xử lý vào bãi nghiêm trọng: {e}")
            self._handle_entry_error("LOI HE THONG", str(e))
        finally:
            self.vehicle_states['entry']['processing'] = False
            self.gui.update_status('vehicle_processing', False)

    def _make_entry_decision(self, license_plate, booking_data, slots_status, rfid):
        logger.info(f"Đang ra quyết định cho xe: {license_plate}")

        if not rfid:
            logger.error("Không còn thẻ RFID khả dụng.")
            return {'allow_entry': False, 'reason': 'no_rfid', 'message': 'HET THE RFID'}

        if booking_data and booking_data.get('has_booking'):
            logger.info("Xe có booking hợp lệ. Cho phép vào.")
            return {
                'allow_entry': True, 'has_booking': True,
                'booking_id': booking_data.get('booking_id'), 'rfid': rfid,
                'available_slots': []
            }

        if not slots_status:
            logger.warning("Không thể kiểm tra tình trạng slot")
            return {'allow_entry': False, 'reason': 'system_error', 'message': 'LOI HE THONG'}

        available_count = slots_status.get('available_count', 0)

        if available_count <= 0:
            logger.warning("Bãi xe đã đầy.")
            return {'allow_entry': False, 'reason': 'full', 'message': 'BAI XE DAY'}

        logger.info(f"Bãi còn {available_count} chỗ trống. Cho phép vào.")
        return {
            'allow_entry': True, 
            'has_booking': False, 
            'rfid': rfid, 
            'available_slots': slots_status['available_slots']
        }
    
    def _handle_entry_error(self, display_msg, log_msg):
        logger.error(f"Lỗi vào bãi: {log_msg}")
        self.send_display_message("in", display_msg, "LIEN HE BAO VE")
        time.sleep(3)
        self.send_display_message("in", "X-PARKING", "Entrance")
    
    def _handle_entry_denial(self, reason, message):
        logger.info(f"Từ chối vào bãi: {reason}")
        if reason == 'no_rfid':
            self.send_display_message("in", "HET THE RFID", "LIEN HE BAO VE")
        elif reason == 'full':
            self.send_display_message("in", "BAI XE DAY", "VUI LONG QUAY LAI")
        else:
            self.send_display_message("in", message, "")
        time.sleep(4)
        self.send_display_message("in", "X-PARKING", "Entrance")
    
    def _grant_entry_access(self, rfid, available_slots_list):
        self.send_display_message("in", "MOI XE VAO", rfid)
        self.send_barrier_command("in", "open")
        
        available_slots_ids = [slot['id'] for slot in available_slots_list]
        
        message = {
            "event": "START_SLOT_MONITOR",
            "timestamp": int(time.time()),
            "slots": available_slots_ids
        }
        self.publish_mqtt("xparking/command/in", json.dumps(message))
        
        self.config.waiting_for_slot = True
        logger.info(f"Mở barrier và bắt đầu giám slot trống: {available_slots_ids}")

    def handle_pre_recognition(self):
        try:
            logger.info("Phát hiện xe tại cổng ra, bắt đầu tiền xử lý.")
            self.send_display_message("out", "NHAN DIEN BSX", "VUI LONG CHO")
            
            frame = self._capture_frame_with_retry('out')
            if frame is None or frame.size == 0:
                self._handle_exit_error("LOI CAMERA", "Loi chup anh.")
                return
                
            logger.info("Bắt đầu nhận diện biển số và truy vấn CSDL.")
            
            with ThreadPoolExecutor(max_workers=2) as executor:
                plate_future = executor.submit(self._detect_plate_optimized, frame)
                
                try:
                    plate_result = plate_future.result(timeout=5.0)
                    if not plate_result['success']:
                        self._handle_exit_error("KHONG NHAN DIEN", f"Khong nhan dien duoc BSX: {plate_result['error']}")
                        return
                    
                    license_plate = plate_result['license_plate']
                    logger.info(f"Nhận diện thành công biển số: {license_plate}")

                    self.gui.update_plate_display('out', license_plate)
                    self.send_display_message("out", "DANG XU LY", license_plate)

                    # THÊM DÒNG NÀY để báo ESP32 chuyển state
                    message = {
                        "event": "BSX_DETECTED",
                        "license_plate": license_plate,
                        "station": "out",
                        "timestamp": int(time.time())
                    }
                    self.publish_mqtt("xparking/command/out", json.dumps(message))

                    vehicle_info = self.db_api.get_vehicle_by_plate(license_plate)
                
                except Exception as e:
                    self._handle_exit_error("LOI NHAN DIEN", f"Loi he thong nhan dien: {e}")
                    return
                
            if not vehicle_info or vehicle_info['status'] != 'in_parking':
                self._handle_exit_error("XE KHONG CO", "Khong tim thay thong tin xe trong bai.")
                return
            
            # ĐÃ BỎ HOÀN TOÀN ENCODE EXIT IMAGE
                
            self.pre_recognition_data = {
                'license_plate': license_plate,
                'vehicle_info': vehicle_info
            }
            
            self.send_display_message("out", "QUET THE RFID", license_plate)
            logger.info(f"Tiền xử lý hoàn tất cho xe: {license_plate}. Đang chờ quét RFID.")
            
        except Exception as e:
            logger.error(f"Lỗi tiền xử lý ra bãi: {e}")
            self._handle_exit_error("LOI HE THONG", str(e))
        finally:
            self.vehicle_states['exit']['processing'] = False

    def _handle_exit_error(self, display_msg, log_msg):
        logger.error(f"Lỗi ra bãi: {log_msg}")
        self.send_display_message("out", display_msg, "THU LAI")
        time.sleep(3)
        self.send_display_message("out", "X-PARKING", "Exit")

    def handle_rfid_scan(self, rfid_uid):
        if self.config.rfid_processing:
            return
            
        self.config.rfid_processing = True
        
        try:
            logger.info(f"Cổng ra, Bắt đầu xử lý RFID: {rfid_uid}")
            self.send_display_message("out", "XAC THUC...", rfid_uid)
            
            if not self.pre_recognition_data or self.pre_recognition_data['vehicle_info']['rfid_tag'] != rfid_uid:
                expected_rfid = self.pre_recognition_data.get('vehicle_info', {}).get('rfid_tag', 'N/A')
                license_plate = self.pre_recognition_data.get('license_plate', '')
                
                logger.warning(f"RFID không khớp: {rfid_uid} vs {expected_rfid}")
                
                # Gửi event RFID_MISMATCH - ESP32 tự xử lý (hiện lỗi 1s, reset timeout, cho quét lại)
                self.publish_mqtt("xparking/command/out", json.dumps({
                    "event": "RFID_MISMATCH",
                    "scanned_rfid": rfid_uid,
                    "expected_rfid": expected_rfid,
                    "license_plate": license_plate,
                    "timestamp": int(time.time())
                }))
                
                logger.info("Event RFID_MISMATCH sent - ESP32 auto-recovery")
                return  # ESP32 sẽ tự cho quét lại, không cần Python làm gì
            
            logger.info("Xác thực RFID thành công. Bắt đầu tính phí.")
            vehicle_info = self.pre_recognition_data['vehicle_info']
            license_plate = self.pre_recognition_data['license_plate']
            
            fee = self.db_api.calculate_smart_fee(license_plate, vehicle_info['entry_time'])
            
            if fee is None:
                logger.error("Lỗi tính phí.")
                self.send_display_message("out", "LOI TINH PHI", "LIEN HE BV")
                time.sleep(3)
                self.send_display_message("out", "X-PARKING", "Exit")
                return

            if fee > 0:
                logger.info(f"Phí: {fee:,} VND. Bắt đầu luồng thanh toán.")
                self.send_display_message("out", f"{fee:,} VND", "DANG CHO TT")
                
                # Tạo payment với Snowflake ID - 3 PHÚT cho popup Python (xe ra)
                payment_data = self.db_api.create_payment_with_snowflake_id(
                    amount=fee,
                    description=f"Phi gui xe - {license_plate}",
                    expire_minutes=3  # 3 PHÚT cho popup Python khi xe ra
                )
                order_id = payment_data['id'] if payment_data else int(time.time())
                
                payment_success = self.payment_manager.show_payment_window(
                    fee, f"Phi gui xe - {license_plate}", order_id
                )
                
                if not payment_success:
                    logger.warning("Thanh toán thất bại hoặc hết hạn.")
                    self.send_display_message("out", "THANH TOAN LOI", "THU LAI")
                    return
            else:
                logger.info("Xe có booking hợp lệ hoặc đã thanh toán. Miễn phí.")
            
            logger.info("Thanh toán thành công. Hoàn tất quy trình ra bãi.")
            self.complete_vehicle_exit(rfid_uid, license_plate, vehicle_info['slot_id'], fee > 0)
            
            self.send_display_message("out", "THANH TOAN TT", "TAM BIET")
            self.send_barrier_command("out", "open")
            
            time.sleep(4)
            self.send_display_message("out", "X-PARKING", "Exit")
            
            self.pre_recognition_data = {}
            
        except Exception as e:
            logger.error(f"Lỗi xử lý ra bãi: {e}")
            self.send_display_message("out", "LOI HE THONG", "")
        finally:
            self.config.rfid_processing = False

    def complete_vehicle_exit(self, rfid, license_plate, slot_id, paid):
        try:
            # Capture ảnh exit và convert sang base64
            exit_image_base64 = None
            try:
                exit_frame = self.gui.capture_frame('out')
                if exit_frame is not None:
                    import cv2
                    import base64
                    _, buffer = cv2.imencode('.jpg', exit_frame, [cv2.IMWRITE_JPEG_QUALITY, 85])
                    exit_image_base64 = base64.b64encode(buffer).decode('utf-8')
                    logger.info(f"Captured exit image: {len(exit_image_base64)} bytes")
            except Exception as e:
                logger.warning(f"Không capture được ảnh exit: {e}")
            
            if self.db_api.complete_vehicle_exit(rfid, license_plate, self.config.get_vn_time(), paid, exit_image_base64):
                self.gui.update_slot_status(slot_id, "empty")
                logger.info("Đã hoàn tất quy trình ra bãi và cập nhật CSDL (với ảnh).")
                return True
        except Exception as e:
            logger.error(f"Lỗi hoàn tất xe ra: {e}")
        return False

    def handle_slot_detection(self, slot_id):
        if not self.config.waiting_for_slot or not self.config.current_entry_data:
            return
        
        self.config.waiting_for_slot = False
        
        try:
            logger.info(f"Phát hiện xe đã đỗ tại slot: {slot_id}. Dừng giám sát.")
            self.config.current_entry_data['slot_id'] = slot_id
            
            # Capture ảnh entry và convert sang base64
            entry_image_base64 = None
            try:
                entry_frame = self.gui.capture_frame('in')
                if entry_frame is not None:
                    import cv2
                    import base64
                    _, buffer = cv2.imencode('.jpg', entry_frame, [cv2.IMWRITE_JPEG_QUALITY, 85])
                    entry_image_base64 = base64.b64encode(buffer).decode('utf-8')
                    logger.info(f"Captured entry image: {len(entry_image_base64)} bytes")
            except Exception as e:
                logger.warning(f"Không capture được ảnh entry: {e}")
            
            success = self.db_api.record_vehicle_entry(
                self.config.current_entry_data['license_plate'],
                slot_id,
                self.config.current_entry_data['rfid'],
                self.config.current_entry_data['entry_time'],
                entry_image_base64  # Lưu ảnh vào
            )
            
            if success:
                self.gui.update_slot_status(slot_id, "occupied")
                
                if self.config.current_entry_data.get('has_booking'):
                    self.db_api.update_booking_status(
                        self.config.current_entry_data['booking_id'],
                        'in_parking',
                        slot_id
                    )
                
            self.send_display_message("in", "XE DA VAO", slot_id)
            time.sleep(3)
            self.send_display_message("in", "X-PARKING", "Entrance")
            
            message = {"event": "STOP_SLOT_MONITOR", "timestamp": int(time.time())}
            self.publish_mqtt("xparking/command/in", json.dumps(message))
            
            self.config.current_entry_data = {}
            
        except Exception as e:
            logger.error(f"Lỗi xử lý phát hiện slot: {e}")

    def handle_slot_timeout(self):
        if self.config.waiting_for_slot and self.config.current_entry_data:
            rfid = self.config.current_entry_data.get('rfid')
            license_plate = self.config.current_entry_data.get('license_plate', 'Unknown')
            
            logger.warning(f"Timeout giám sát slot cho xe {license_plate}. Thu hồi RFID: {rfid}")
            
            if rfid:
                self.db_api.rollback_rfid(rfid)
                
            self.config.waiting_for_slot = False
            self.config.current_entry_data = {}
            
            self.send_display_message("in", "TIMEOUT", "VUI LONG THU LAI")
            time.sleep(3)
            self.send_display_message("in", "X-PARKING", "Entrance")
            
            message = {"event": "STOP_SLOT_MONITOR", "timestamp": int(time.time())}
            self.publish_mqtt("xparking/command/in", json.dumps(message))

    def handle_emergency_alert(self, data, is_alert):
        gas_level = data.get('gas_level', 0)
        location = data.get('station', 'unknown')
        
        if is_alert and not self.config.emergency_mode:
            self.config.emergency_mode = True
            logger.critical(f"KHẨN CẤP: Phát hiện khói/gas mức {gas_level} tại trạm {location}")
            
            self.gui.update_emergency_status()
            
            self.send_barrier_command("in", "open")
            self.send_barrier_command("out", "open")
            self.send_display_message("in", "!!! KHAN CAP !!!", "PHAT HIEN KHOI")
            self.send_display_message("out", "!!! KHAN CAP !!!", "PHAT HIEN KHOI")
            
            if not self.config.gas_alert_sent:
                self.config.gas_alert_sent = True
                threading.Thread(
                    target=self.email_handler.send_alert_email,
                    args=(gas_level, location),
                    daemon=True
                ).start()
            
        elif not is_alert and self.config.emergency_mode:
            self.config.emergency_mode = False
            self.config.gas_alert_sent = False
            logger.info("Cảnh báo khẩn cấp đã qua")
            
            self.gui.update_emergency_status()
            
            self.send_barrier_command("in", "close")
            self.send_barrier_command("out", "close")
            
            self.send_display_message("in", "X-PARKING", "Entrance")
            self.send_display_message("out", "X-PARKING", "Exit")
            
            threading.Thread(
                target=self.email_handler.send_recovery_email,
                daemon=True
            ).start()

    def _capture_frame_with_retry(self, camera_type, max_attempts=3):
        for attempt in range(max_attempts):
            try:
                frame = self.gui.capture_frame(camera_type)
                if frame is not None:
                    return frame
                time.sleep(0.1)
            except Exception as e:
                logger.warning(f"Lần chụp frame {attempt + 1} thất bại: {e}")
                if attempt < max_attempts - 1:
                    time.sleep(0.2)
        return None

    def shutdown(self):
        logger.info("Đang tắt hệ thống XParking...")
        
        if self.mqtt_client:
            try:
                self.mqtt_client.disconnect()
                self.mqtt_client.loop_stop()
            except:
                pass
        
        if hasattr(self, 'executor'):
            self.executor.shutdown(wait=False)
