import tkinter as tk
import logging
import time
import random
from payment import PaymentManager

# Cấu hình logging
logging.basicConfig(
    level=logging.INFO
)

class MockConfigManager:
    """Mock config manager để test"""
    def __init__(self):
        self.config = {
            'site_url': 'https://xparking.x10.mx'
        }

class MockMainSystem:
    """Mock main system để test"""
    def __init__(self):
        self.root = tk.Tk()
        self.root.title("Payment Test Window")
        self.root.geometry("800x600")
        self.config_manager = MockConfigManager()
        
        # Setup UI
        self.setup_ui()
        
    def setup_ui(self):
        """Tạo giao diện test"""
        # Header
        header = tk.Label(
            self.root, 
            text="PAYMENT MANAGER TEST", 
            font=('Arial', 20, 'bold'),
            bg='#2ecc71',
            fg='white',
            pady=20
        )
        header.pack(fill='x')
        
        # Test scenarios frame
        scenarios_frame = tk.LabelFrame(
            self.root,
            text="Test Scenarios",
            font=('Arial', 14, 'bold'),
            padx=20,
            pady=20
        )
        scenarios_frame.pack(pady=20, padx=20, fill='both', expand=True)
        
        # Scenario 1: Normal payment
        tk.Button(
            scenarios_frame,
            text="Test 1: Thanh toán bình thường (10,000 VND)",
            font=('Arial', 12),
            bg='#3498db',
            fg='white',
            pady=10,
            command=lambda: self.test_payment(10000, "Test Normal Payment", "TEST001")
        ).pack(fill='x', pady=5)
        
        # Scenario 2: Large payment
        tk.Button(
            scenarios_frame,
            text="Test 2: Thanh toán lớn (50,000 VND)",
            font=('Arial', 12),
            bg='#9b59b6',
            fg='white',
            pady=10,
            command=lambda: self.test_payment(50000, "Test Large Payment", "TEST002")
        ).pack(fill='x', pady=5)
        
        # Scenario 3: Small payment
        tk.Button(
            scenarios_frame,
            text="Test 3: Thanh toán nhỏ (5,000 VND)",
            font=('Arial', 12),
            bg='#1abc9c',
            fg='white',
            pady=10,
            command=lambda: self.test_payment(5000, "Test Small Payment", "TEST003")
        ).pack(fill='x', pady=5)
        
        # Scenario 4: Random order ID
        tk.Button(
            scenarios_frame,
            text="Test 4: Order ID ngẫu nhiên",
            font=('Arial', 12),
            bg='#e67e22',
            fg='white',
            pady=10,
            command=self.test_random_order
        ).pack(fill='x', pady=5)
        
        # Scenario 5: Quick succession
        tk.Button(
            scenarios_frame,
            text="Test 5: Nhiều thanh toán liên tiếp",
            font=('Arial', 12),
            bg='#e74c3c',
            fg='white',
            pady=10,
            command=self.test_multiple_payments
        ).pack(fill='x', pady=5)
        
        # Status display
        self.status_label = tk.Label(
            self.root,
            text="Sẵn sàng để test",
            font=('Arial', 12),
            bg='#ecf0f1',
            pady=10
        )
        self.status_label.pack(fill='x', side='bottom')
        
        # Results log
        log_frame = tk.LabelFrame(
            self.root,
            text="Test Results Log",
            font=('Arial', 12, 'bold')
        )
        log_frame.pack(pady=10, padx=20, fill='both', expand=True)
        
        self.results_text = tk.Text(
            log_frame,
            height=8,
            font=('Courier', 10),
            bg='#2c3e50',
            fg='#ecf0f1'
        )
        self.results_text.pack(fill='both', expand=True, padx=5, pady=5)
        
    def test_payment(self, amount, description, order_id):
        """Test một giao dịch thanh toán"""
        self.update_status(f"Đang test: {description}")
        self.log_result(f"\n{'='*60}")
        self.log_result(f"Test Payment: {order_id}")
        self.log_result(f"Amount: {amount:,} VND")
        self.log_result(f"Description: {description}")
        self.log_result(f"{'='*60}")
        
        payment_mgr = PaymentManager(self)
        
        start_time = time.time()
        result = payment_mgr.show_payment_window(amount, description, order_id)
        end_time = time.time()
        
        duration = end_time - start_time
        
        self.log_result(f"Result: {'SUCCESS ✓' if result else 'FAILED ✗'}")
        self.log_result(f"Duration: {duration:.2f}s")
        self.log_result(f"{'='*60}\n")
        
        if result:
            self.update_status(f"✓ Test thành công: {order_id}")
        else:
            self.update_status(f"✗ Test thất bại: {order_id}")
    
    def test_random_order(self):
        """Test với order ID ngẫu nhiên"""
        order_id = f"TEST{random.randint(1000, 9999)}"
        amount = random.randint(5, 50) * 1000
        self.test_payment(amount, f"Random Test {order_id}", order_id)
    
    def test_multiple_payments(self):
        """Test nhiều thanh toán liên tiếp"""
        self.update_status("Đang test nhiều thanh toán liên tiếp...")
        
        test_cases = [
            (10000, "Multi Test 1", "MULTI001"),
            (20000, "Multi Test 2", "MULTI002"),
            (15000, "Multi Test 3", "MULTI003")
        ]
        
        for amount, desc, order_id in test_cases:
            self.log_result(f"\nStarting: {desc}")
            self.test_payment(amount, desc, order_id)
            time.sleep(1)  # Delay giữa các test
        
        self.update_status("Hoàn thành test nhiều thanh toán")
    
    def update_status(self, message):
        """Cập nhật status label"""
        self.status_label.config(text=message)
        self.root.update()
    
    def log_result(self, message):
        """Ghi log vào text widget"""
        self.results_text.insert('end', message + '\n')
        self.results_text.see('end')
        self.root.update()
        logging.info(message)

def main():
    """Main test function"""
    print("="*60)
    print("XPARKING PAYMENT MANAGER TEST")
    print("="*60)
    print("\nHướng dẫn test:")
    print("1. Nhấn các button để test các trường hợp khác nhau")
    print("2. Cửa sổ thanh toán sẽ hiển thị QR code")
    print("3. Bạn có thể:")
    print("   - Đợi thanh toán thành công (nếu có webhook)")
    print("   - Nhấn 'Huy' để test trường hợp hủy")
    print("   - Đợi hết timeout để test trường hợp hết thời gian")
    print("4. Kết quả sẽ hiển thị trong log")
    print("\nLưu ý:")
    print("- Cần có internet để tải QR code")
    print("- Webhook cần được cấu hình đúng để test thanh toán thành công")
    print("- Log chi tiết được lưu trong file test_payment.log")
    print("="*60)
    
    app = MockMainSystem()
    
    # Thêm instruction label
    instruction = tk.Label(
        app.root,
        text="💡 Chọn một test scenario để bắt đầu",
        font=('Arial', 11, 'italic'),
        fg='#7f8c8d',
        pady=5
    )
    instruction.pack(after=app.status_label)
    
    app.root.mainloop()

if __name__ == "__main__":
    main()