
import requests
import json
import logging
import time
from datetime import datetime, timedelta

# Cấu hình logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)

class XParkingCompleteTest:
    def __init__(self, site_url):
        self.site_url = site_url
        self.gateway_url = f"{site_url}/api/gateway.php"
        self.session = requests.Session()
        
        # Test data
        self.test_license_plate = "TEST-001"
        self.test_rfid = None
        self.test_vehicle_id = None
        self.results = []
        
    def call_gateway(self, action, params=None):
        """Gọi API qua gateway"""
        try:
            all_params = {'action': action}
            if params:
                all_params.update(params)
            
            # Thử GET trước
            response = self.session.get(self.gateway_url, params=all_params, timeout=10)
            
            if response.status_code == 200:
                return response.json()
            else:
                # Thử POST nếu GET fail
                response = self.session.post(self.gateway_url, data=all_params, timeout=10)
                if response.status_code == 200:
                    return response.json()
                else:
                    return {'error': f'HTTP {response.status_code}'}
                    
        except Exception as e:
            return {'error': str(e)}
    
    def call_direct_api(self, endpoint, method='GET', params=None):
        """Gọi API trực tiếp"""
        try:
            url = f"{self.site_url}/api/{endpoint}"
            
            if method == 'GET':
                response = self.session.get(url, params=params, timeout=10)
            else:
                response = self.session.post(url, data=params, timeout=10)
                
            if response.status_code == 200:
                return response.json()
            else:
                return {'error': f'HTTP {response.status_code}'}
                
        except Exception as e:
            return {'error': str(e)}
    
    def log_test(self, name, success, details=None):
        """Log kết quả test"""
        status = "PASS" if success else "FAIL"
        result = {
            'test': name,
            'status': status,
            'timestamp': datetime.now().isoformat(),
            'details': details
        }
        self.results.append(result)
        
        print(f"[{status}] {name}")
        if details:
            print(f"       Details: {json.dumps(details, ensure_ascii=False)}")
        print("-" * 60)
    
    def test_1_connectivity(self):
        """Test 1: Kiểm tra kết nối cơ bản"""
        print("\n=== TEST 1: CONNECTIVITY ===")
        
        # Test gateway
        try:
            response = self.session.get(f"{self.gateway_url}?action=test", timeout=5)
            gateway_ok = response.status_code == 200
        except:
            gateway_ok = False
            
        self.log_test("Gateway Connectivity", gateway_ok, 
                     {'gateway_url': self.gateway_url, 'status': gateway_ok})
        
        # Test slots_status (GET endpoint)
        slots_data = self.call_direct_api('slots_status.php')
        slots_ok = 'success' in slots_data and slots_data.get('success')
        
        self.log_test("Slots Status API", slots_ok, 
                     {'available_slots': len(slots_data.get('available_slots', [])) if slots_ok else 0})
        
        return gateway_ok and slots_ok
    
    def test_2_rfid_management(self):
        """Test 2: Quản lý RFID"""
        print("\n=== TEST 2: RFID MANAGEMENT ===")
        
        # Lấy RFID từ pool
        rfid_data = self.call_direct_api('get_rfid.php')
        rfid_ok = rfid_data.get('success') and rfid_data.get('rfid')
        
        if rfid_ok:
            self.test_rfid = rfid_data['rfid']
            
        self.log_test("Get Available RFID", rfid_ok, 
                     {'rfid': self.test_rfid if rfid_ok else None})
        
        return rfid_ok
    
    def test_3_booking_system(self):
        """Test 3: Hệ thống booking"""
        print("\n=== TEST 3: BOOKING SYSTEM ===")
        
        # Check booking (không có booking)
        booking_data = self.call_gateway('check_booking', {
            'license_plate': self.test_license_plate
        })
        
        no_booking = not booking_data.get('has_booking', True)
        
        self.log_test("Check Booking (No Booking)", no_booking, 
                     {'has_booking': booking_data.get('has_booking')})
        
        # Get booking details
        booking_details = self.call_gateway('get_booking', {
            'license_plate': self.test_license_plate
        })
        
        no_booking_details = not booking_details.get('success', True)
        
        self.log_test("Get Booking Details (No Booking)", no_booking_details, 
                     {'success': booking_details.get('success')})
        
        return no_booking and no_booking_details
    
    def test_4_vehicle_checkin(self):
        """Test 4: Check-in xe"""
        print("\n=== TEST 4: VEHICLE CHECK-IN ===")
        
        if not self.test_rfid:
            self.log_test("Vehicle Check-in", False, {'error': 'No RFID available'})
            return False
        
        checkin_data = self.call_gateway('checkin', {
            'license_plate': self.test_license_plate,
            'slot_id': 'A01',
            'rfid': self.test_rfid,
            'entry_time': datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        })
        
        checkin_ok = checkin_data.get('success')
        if checkin_ok:
            self.test_vehicle_id = checkin_data.get('vehicle_id')
            
        self.log_test("Vehicle Check-in", checkin_ok, {
            'vehicle_id': self.test_vehicle_id,
            'slot_id': checkin_data.get('slot_id'),
            'rfid': checkin_data.get('rfid')
        })
        
        return checkin_ok
    
    def test_5_vehicle_queries(self):
        """Test 5: Truy vấn thông tin xe"""
        print("\n=== TEST 5: VEHICLE QUERIES ===")
        
        if not self.test_rfid:
            self.log_test("Vehicle Queries", False, {'error': 'No RFID to test'})
            return False
        
        # Get vehicle by RFID
        vehicle_by_rfid = self.call_gateway('get_vehicle', {
            'rfid': self.test_rfid
        })
        
        rfid_ok = vehicle_by_rfid.get('success')
        
        self.log_test("Get Vehicle by RFID", rfid_ok, {
            'license_plate': vehicle_by_rfid.get('vehicle', {}).get('license_plate'),
            'slot_id': vehicle_by_rfid.get('vehicle', {}).get('slot_id')
        })
        
        # Get vehicle by plate
        vehicle_by_plate = self.call_gateway('get_vehicle_by_plate', {
            'license_plate': self.test_license_plate
        })
        
        plate_ok = vehicle_by_plate.get('success')
        
        self.log_test("Get Vehicle by Plate", plate_ok, {
            'rfid_tag': vehicle_by_plate.get('vehicle', {}).get('rfid_tag'),
            'slot_id': vehicle_by_plate.get('vehicle', {}).get('slot_id')
        })
        
        return rfid_ok and plate_ok
    
    def test_6_slot_management(self):
        """Test 6: Quản lý slot"""
        print("\n=== TEST 6: SLOT MANAGEMENT ===")
        
        # Update slot status
        update_data = self.call_gateway('update_slot', {
            'slot_id': 'A01',
            'status': 'occupied',
            'timestamp': datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        })
        
        update_ok = update_data.get('success')
        
        self.log_test("Update Slot Status", update_ok, {
            'slot_id': update_data.get('slot_id'),
            'status': update_data.get('status')
        })
        
        return update_ok
    
    def test_7_vehicle_checkout(self):
        """Test 7: Check-out xe"""
        print("\n=== TEST 7: VEHICLE CHECK-OUT ===")
        
        if not self.test_rfid:
            self.log_test("Vehicle Check-out", False, {'error': 'No RFID to checkout'})
            return False
        
        checkout_data = self.call_gateway('checkout', {
            'rfid': self.test_rfid,
            'license_plate': self.test_license_plate,
            'exit_time': datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
            'paid': '1'
        })
        
        checkout_ok = checkout_data.get('success')
        
        self.log_test("Vehicle Check-out", checkout_ok, {
            'license_plate': checkout_data.get('license_plate'),
            'slot_id': checkout_data.get('slot_id'),
            'paid': checkout_data.get('paid')
        })
        
        return checkout_ok
    
    def test_8_cleanup(self):
        """Test 8: Dọn dẹp"""
        print("\n=== TEST 8: CLEANUP ===")
        
        if not self.test_rfid:
            self.log_test("RFID Rollback", True, {'note': 'No RFID to rollback'})
            return True
        
        # RFID rollback (có thể fail nếu đã được free bởi checkout)
        rollback_data = self.call_gateway('rollback_rfid', {
            'rfid': self.test_rfid
        })
        
        # Rollback có thể fail nếu RFID đã được giải phóng
        rollback_ok = rollback_data.get('success') or 'not assigned' in rollback_data.get('error', '')
        
        self.log_test("RFID Rollback", rollback_ok, {
            'rfid': self.test_rfid,
            'message': rollback_data.get('message', rollback_data.get('error'))
        })
        
        return rollback_ok
    
    def test_9_system_integration(self):
        """Test 9: Tích hợp hệ thống"""
        print("\n=== TEST 9: SYSTEM INTEGRATION ===")
        
        # Test booking với xe có trong database
        existing_booking = self.call_gateway('check_booking', {
            'license_plate': '51G1-999.99'  # Từ test data
        })
        
        has_test_booking = existing_booking.get('has_booking', False)
        
        self.log_test("Check Existing Booking", True, {
            'test_plate': '51G1-999.99',
            'has_booking': has_test_booking,
            'booking_id': existing_booking.get('booking_id')
        })
        
        # Test slots status chi tiết
        slots_data = self.call_direct_api('slots_status.php')
        slots_detailed = slots_data.get('success')
        
        self.log_test("Detailed Slots Status", slots_detailed, {
            'total_slots': slots_data.get('total_slots'),
            'occupied_slots': slots_data.get('occupied_slots'),
            'available_count': len(slots_data.get('available_slots', []))
        })
        
        return True  # Integration tests are informational
    
    def run_all_tests(self):
        """Chạy tất cả tests"""
        print("=" * 80)
        print("🚀 XPARKING COMPLETE SYSTEM TEST")
        print(f"🌐 Target: {self.site_url}")
        print(f"⏰ Started: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
        print("=" * 80)
        
        tests = [
            ("Connectivity", self.test_1_connectivity),
            ("RFID Management", self.test_2_rfid_management),
            ("Booking System", self.test_3_booking_system),
            ("Vehicle Check-in", self.test_4_vehicle_checkin),
            ("Vehicle Queries", self.test_5_vehicle_queries),
            ("Slot Management", self.test_6_slot_management),
            ("Vehicle Check-out", self.test_7_vehicle_checkout),
            ("Cleanup", self.test_8_cleanup),
            ("System Integration", self.test_9_system_integration)
        ]
        
        passed = 0
        failed = 0
        
        for test_name, test_func in tests:
            try:
                if test_func():
                    passed += 1
                else:
                    failed += 1
                time.sleep(1)  # Delay between tests
            except Exception as e:
                print(f"[ERROR] {test_name} failed with exception: {e}")
                failed += 1
        
        # Final report
        total = passed + failed
        success_rate = (passed / total * 100) if total > 0 else 0
        
        print("\n" + "=" * 80)
        print("📊 FINAL TEST REPORT")
        print("=" * 80)
        print(f"✅ Passed: {passed}")
        print(f"❌ Failed: {failed}")
        print(f"📈 Success Rate: {success_rate:.1f}%")
        print(f"⏰ Completed: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
        
        if success_rate >= 90:
            print("\n🎉 EXCELLENT! System is working perfectly!")
        elif success_rate >= 70:
            print("\n✅ GOOD! System is mostly functional.")
        elif success_rate >= 50:
            print("\n⚠️ FAIR! Some issues need attention.")
        else:
            print("\n❌ POOR! System needs significant work.")
        
        # Save detailed results
        with open('test_results.json', 'w', encoding='utf-8') as f:
            json.dump({
                'summary': {
                    'passed': passed,
                    'failed': failed,
                    'success_rate': success_rate,
                    'test_time': datetime.now().isoformat()
                },
                'detailed_results': self.results
            }, f, ensure_ascii=False, indent=2)
        
        print(f"\n📄 Detailed results saved to: test_results.json")
        
        return success_rate >= 70

def main():
    """Main test function"""
    tester = XParkingCompleteTest('https://xparking.x10.mx')
    
    try:
        success = tester.run_all_tests()
        return 0 if success else 1
    except KeyboardInterrupt:
        print("\n\n⚠️ Test interrupted by user")
        return 1
    except Exception as e:
        print(f"\n\n❌ Test suite failed: {e}")
        return 1

if __name__ == "__main__":
    exit(main())