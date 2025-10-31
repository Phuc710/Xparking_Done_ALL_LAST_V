import tkinter as tk
from tkinter import ttk
import threading
import time
import json
import logging
import requests
from PIL import Image, ImageTk
import urllib.request
import os
import io
import math
from config import get_vn_time

class PaymentManager:
    def __init__(self, main_system):
        self.main_system = main_system 
        self.qr_cache_dir = "qr_cache"
        self.timeout_photo = None 
        self.timeout_image_pil = None
        os.makedirs(self.qr_cache_dir, exist_ok=True)
        
    def get_vn_time(self, format_str='%Y-%m-%d %H:%M:%S'):
        """Sử dụng hàm global thay vì duplicate"""
        return get_vn_time(format_str)
    def load_timeout_image(self):
        """Load timeout image - chỉ load khi cần thiết (sau khi Tkinter root đã tạo)"""
        try:
            image_path = "img/timeout.png" 
            if os.path.exists(image_path):
                img = Image.open(image_path)
                img = img.resize((300, 300), Image.Resampling.LANCZOS)
                # Chỉ tạo PhotoImage khi Tkinter root đã sẵn sàng
                try:
                    self.timeout_photo = ImageTk.PhotoImage(img)
                    logging.info(f"Tải ảnh {image_path} thành công.")
                except Exception as tk_error:
                    # Nếu Tkinter chưa sẵn sàng, lưu PIL Image để dùng sau
                    self.timeout_image_pil = img
                    self.timeout_photo = None
                    logging.info(f"Lưu ảnh PIL để load sau khi Tkinter sẵn sàng")
            else:
                self.timeout_photo = None
                logging.warning(f"Không tìm thấy file ảnh: {image_path}")
        except Exception as e:
            self.timeout_photo = None
            logging.error(f"Lỗi khi tải ảnh timeout.png: {e}")
    def generate_qr_url(self, amount, order_id):
        try:
            bank_id = "MBBank"
            account_no = "09696969690"
            account_name = "NGUYEN THANH PHUC"
            
            content = f"XPARK{order_id}"
            
            qr_url = f"https://qr.sepay.vn/img?acc={account_no}&bank={bank_id}&amount={amount}&des={content}&template=compact"
            
            logging.info(f"Tao QR URL: {qr_url}")
            return qr_url
            
        except Exception as e:
            logging.error(f"Generate QR error: {e}")
            return None
    
    def show_payment_window(self, amount, description, order_id):
        qr_url = self.generate_qr_url(amount, order_id)
        if not qr_url:
            logging.error("Khong tao duoc QR")
            return False
            
        payment_result = {'completed': False, 'success': False}
        
        def payment_thread():
            try:
                payment_result['success'] = self.show_qr_payment(
                    qr_url, str(order_id), amount, description
                )
                payment_result['completed'] = True
            except Exception as e:
                logging.error(f"Payment thread error: {e}")
                payment_result['completed'] = True
                payment_result['success'] = False
        
        thread = threading.Thread(target=payment_thread, daemon=True)
        thread.start()
        
        while not payment_result['completed']:
            if self.main_system.root:
                self.main_system.root.update()
            time.sleep(0.1)
        
        return payment_result['success']
    
    def show_qr_payment(self, qr_url, order_id, amount, description):
        payment_window = tk.Toplevel(self.main_system.root)
        payment_window.title("Thanh toan")
        payment_window.geometry('400x700')
        payment_window.transient(self.main_system.root)
        payment_window.grab_set()
        payment_window.resizable(False, False)
        payment_window.configure(bg='white')
        
        x = (payment_window.winfo_screenwidth() // 2) - 200
        y = (payment_window.winfo_screenheight() // 2) - 350
        payment_window.geometry(f"400x700+{x}+{y}")
        
        payment_state = {
            'completed': False,
            'success': False,
            'countdown_active': True,
            'remaining_time': 180,  # 3 PHÚT cho popup Python (xe ra)
            'window_exists': True
        }
        
        main_frame = ttk.Frame(payment_window, padding=20)
        main_frame.pack(fill='both', expand=True)
        
        header_frame = ttk.Frame(main_frame)
        header_frame.pack(fill='x')
        
        title_label = ttk.Label(header_frame, text="X PARKING PAY", 
                              font=('Arial', 24, 'bold'), foreground='#2ecc71')
        title_label.pack(pady=(0, 10))
        
        status_var = tk.StringVar(value="Dang cho thanh toan...")
        status_label = ttk.Label(header_frame, textvariable=status_var,
                               font=('Arial', 12, 'bold'), foreground='blue', anchor='center')
        status_label.pack(fill='x', pady=(10, 5))
        
        countdown_var = tk.StringVar(value="Con lai: 03:00")
        countdown_label = ttk.Label(header_frame, textvariable=countdown_var,
                                  font=('Arial', 12), foreground='red', anchor='center')
        countdown_label.pack(fill='x')
        
        qr_frame = ttk.Frame(main_frame, padding=5)
        qr_frame.pack(pady=20)
        
        qr_canvas = tk.Canvas(qr_frame, width=300, height=300, bg='white',
                            highlightthickness=1, highlightbackground='gray')
        qr_canvas.pack()
        
        details_frame = ttk.LabelFrame(main_frame, text="Chi tiet thanh toan")
        details_frame.pack(pady=10, fill='x')
        
        ttk.Label(details_frame, text="So tien:", font=('Arial', 11)).grid(
            row=0, column=0, sticky='w', padx=10, pady=2)
        ttk.Label(details_frame, text=f"{amount:,} VND", 
                 font=('Arial', 11, 'bold'), foreground='red').grid(
            row=0, column=1, sticky='e', padx=10, pady=2)
        
        ttk.Label(details_frame, text="Noi dung:", font=('Arial', 11)).grid(
            row=1, column=0, sticky='w', padx=10, pady=2)
        ttk.Label(details_frame, text=description, font=('Arial', 11)).grid(
            row=1, column=1, sticky='e', padx=10, pady=2)
        
        ttk.Label(details_frame, text="Ma giao dich:", font=('Arial', 11)).grid(
            row=2, column=0, sticky='w', padx=10, pady=2)
        ttk.Label(details_frame, text=order_id, font=('Arial', 11)).grid(
            row=2, column=1, sticky='e', padx=10, pady=2)
        
        details_frame.columnconfigure(1, weight=1)
        
        btn_frame = ttk.Frame(main_frame)
        btn_frame.pack(pady=(20, 0))
        
        cancel_btn = ttk.Button(btn_frame, text="Huy")
        cancel_btn.pack()
        
        # Animation state
        animation_state = {
            'active': False,
            'angle': 0,
            'pulse_scale': 1.0,
            'pulse_direction': 1
        }
        
        def safe_window_check():
            try:
                return payment_window.winfo_exists()
            except:
                return False
        
        def draw_waiting_animation():
            """Animation pulse nhẹ khi đang chờ thanh toán"""
            if not safe_window_check() or not animation_state['active']:
                return
            
            try:
                # Xóa các phần tử animation cũ (giữ lại QR image)
                qr_canvas.delete("pulse_effect")
                
                # Pulse effect cho QR border
                pulse_size = 145 + 8 * animation_state['pulse_scale']
                qr_canvas.create_rectangle(
                    150 - pulse_size, 150 - pulse_size,
                    150 + pulse_size, 150 + pulse_size,
                    outline='#2ecc71', width=2,
                    tags="pulse_effect"
                )
                
                # Update animation values
                animation_state['pulse_scale'] += 0.05 * animation_state['pulse_direction']
                
                if animation_state['pulse_scale'] >= 1.0 or animation_state['pulse_scale'] <= 0:
                    animation_state['pulse_direction'] *= -1
                
                # Continue animation
                payment_window.after(50, draw_waiting_animation)
                
            except Exception as e:
                logging.error(f"Animation error: {e}")
        
        def draw_success_animation():
            """Animation checkmark khi thanh toán thành công"""
            if not safe_window_check():
                return
            
            try:
                qr_canvas.delete("all")
                qr_canvas.config(bg='#d4edda', highlightthickness=0)
                
                # Vẽ circle background
                qr_canvas.create_oval(50, 50, 250, 250, 
                                     fill='#28a745', outline='')
                
                # Animation vẽ checkmark
                def draw_checkmark_step(step=0):
                    if step > 20 or not safe_window_check():
                        return
                    
                    progress = step / 20.0
                    
                    # Vẽ checkmark từng đoạn
                    if progress <= 0.5:
                        # Vẽ nửa đầu checkmark
                        p = progress * 2
                        x1, y1 = 100, 150
                        x2 = 100 + (130 - 100) * p
                        y2 = 150 + (180 - 150) * p
                        qr_canvas.create_line(x1, y1, x2, y2,
                                            fill='white', width=15,
                                            capstyle='round', tags='checkmark')
                    else:
                        # Vẽ nửa sau checkmark
                        qr_canvas.create_line(100, 150, 130, 180,
                                            fill='white', width=15,
                                            capstyle='round', tags='checkmark')
                        p = (progress - 0.5) * 2
                        x1, y1 = 130, 180
                        x2 = 130 + (200 - 130) * p
                        y2 = 180 - (180 - 120) * p
                        qr_canvas.create_line(x1, y1, x2, y2,
                                            fill='white', width=15,
                                            capstyle='round', tags='checkmark')
                    
                    payment_window.after(30, lambda: draw_checkmark_step(step + 1))
                
                draw_checkmark_step()
                
            except Exception as e:
                logging.error(f"Success animation error: {e}")
        
        def draw_failure_animation():
            """Animation X mark khi thất bại/hủy"""
            if not safe_window_check():
                return
            
            try:
                qr_canvas.delete("all")
                qr_canvas.config(bg='#f8d7da', highlightthickness=0)
                
                # Vẽ circle background
                qr_canvas.create_oval(50, 50, 250, 250, 
                                     fill='#dc3545', outline='')
                
                # Animation vẽ X mark
                def draw_x_step(step=0):
                    if step > 20 or not safe_window_check():
                        return
                    
                    progress = step / 20.0
                    
                    if progress <= 0.5:
                        # Vẽ line đầu tiên của X
                        p = progress * 2
                        x1, y1 = 90, 90
                        x2 = 90 + (210 - 90) * p
                        y2 = 90 + (210 - 90) * p
                        qr_canvas.create_line(x1, y1, x2, y2,
                                            fill='white', width=15,
                                            capstyle='round', tags='xmark')
                    else:
                        # Vẽ line thứ hai của X
                        qr_canvas.create_line(90, 90, 210, 210,
                                            fill='white', width=15,
                                            capstyle='round', tags='xmark')
                        p = (progress - 0.5) * 2
                        x1, y1 = 210, 90
                        x2 = 210 - (210 - 90) * p
                        y2 = 90 + (210 - 90) * p
                        qr_canvas.create_line(x1, y1, x2, y2,
                                            fill='white', width=15,
                                            capstyle='round', tags='xmark')
                    
                    payment_window.after(30, lambda: draw_x_step(step + 1))
                
                draw_x_step()
                
            except Exception as e:
                logging.error(f"Failure animation error: {e}")
        
        def load_qr_image():
            try:
                qr_canvas.create_text(150, 150, text="Dang tai QR code...", 
                                    fill="gray", font=("Arial", 14))
                
                cache_file = os.path.join(self.qr_cache_dir, f"qr_{order_id}.png")
                
                if os.path.exists(cache_file):
                    logging.info("Load QR tu cache")
                    img = Image.open(cache_file)
                else:
                    logging.info(f"Download QR tu: {qr_url}")
                    with urllib.request.urlopen(qr_url, timeout=10) as response:
                        img_data = response.read()
                        img = Image.open(io.BytesIO(img_data))
                        img.save(cache_file)
                
                img = img.resize((280, 280), Image.Resampling.LANCZOS)
                photo = ImageTk.PhotoImage(img)
                
                if safe_window_check():
                    qr_canvas.delete("all")
                    qr_canvas.create_image(150, 150, image=photo, anchor='center', tags='qr_image')
                    qr_canvas.image = photo
                    
                    # Bắt đầu animation loading
                    animation_state['active'] = True
                    draw_waiting_animation()
                    
            except Exception as e:
                logging.error(f"Load QR error: {e}")
                if safe_window_check():
                    qr_canvas.delete("all")
                    qr_canvas.create_text(150, 150, 
                                        text="Loi tai QR code\nVui long thu lai",
                                        fill="red", font=("Arial", 12), justify='center')
        
        def check_payment_status():
            try:
                # Sử dụng webhook enhanced mới
                url = f"{self.main_system.config_manager.config['site_url']}/api/check_webhook.php"
                data = {'payment_ref': f'XPARK{str(order_id)[-8:]}'} 
                
                logging.info(f"Kiem tra trang thai thanh toan cho payment_ref: XPARK{str(order_id)[-8:]}")
                response = requests.get(url, params=data, timeout=10)
                
                logging.info(f"Response status: {response.status_code}")
                logging.info(f"Response body: {response.text}")
                
                if response.status_code == 200:
                    result = response.json()
                    logging.info(f"Ket qua kiem tra thanh toan: {result}")
                    if result.get('status') == 'completed':
                        logging.info("Thanh toan da hoan thanh")
                        return True
                    elif result.get('status') == 'expired':
                        logging.warning("Thanh toan da het han")
                        return False
                            
            except Exception as e:
                logging.error(f"Check payment error: {e}")
            
            return False
        
        def payment_monitor():
            logging.info("Đợi 5s mới bắt đầu check trạng thái thanh toán")
            time.sleep(5)
            logging.info("Bat dau kiem tra trang thai thanh toan...")
            check_interval = 0.5
            while payment_state['countdown_active'] and not payment_state['completed']:
                if check_payment_status():
                    payment_state['completed'] = True
                    payment_state['success'] = True
                    payment_state['countdown_active'] = False
                    
                    if safe_window_check():
                        payment_window.after(0, show_success_animation)
                    break
                    
                time.sleep(check_interval)
        
        def show_success_animation():
            try:
                if not safe_window_check(): return
                
                animation_state['active'] = False 
                draw_success_animation()
                status_var.set("Payment Successful")
                status_label.configure(foreground="#28a745")
                cancel_btn.configure(text="Dong", command=close_success_window)
                countdown_var.set("")
                cancel_btn.pack()
                payment_window.after(1500, close_success_window)
                logging.info("Thanh toan thanh cong")
                
            except Exception as e:
                logging.error(f"Success animation error: {e}")
        
        def close_success_window():
            try:
                if safe_window_check():
                    payment_window.destroy()
                    logging.info("Đóng của sổ thanh toán")
            except Exception as e:
                logging.error(f"Close window error: {e}")
        
        def draw_timeout_animation():
            if not safe_window_check():
                return
            
            try:
                qr_canvas.delete("all")
                qr_canvas.config(bg='white', highlightthickness=0)
                
                # Lazy load timeout image nếu chưa có
                if not self.timeout_photo and self.timeout_image_pil:
                    try:
                        self.timeout_photo = ImageTk.PhotoImage(self.timeout_image_pil)
                    except:
                        pass
                
                if not self.timeout_photo:
                    # Thử load lại nếu chưa có
                    self.load_timeout_image()
                
                if self.timeout_photo:
                    qr_canvas.create_image(150, 150, image=self.timeout_photo, anchor='center', tags='timeout_image')
                    qr_canvas.image = self.timeout_photo
                else:
                    qr_canvas.create_text(150, 150, 
                                          text="HET THOI GIAN\n(Khong co anh timeout.png)", 
                                          fill="red", font=("Arial", 12), justify='center')
                    
            except Exception as e:
                logging.error(f"Timeout image display error: {e}")
        
        def update_countdown():
            if not payment_state['countdown_active'] or payment_state['completed']:
                return
                
            if not safe_window_check():
                payment_state['window_exists'] = False
                return
                
            if payment_state['remaining_time'] > 0:
                mins, secs = divmod(payment_state['remaining_time'], 60)
                countdown_var.set(f"Con lai: {mins:02d}:{secs:02d}")
                payment_state['remaining_time'] -= 1
                payment_window.after(1000, update_countdown)
            else:
                countdown_var.set("⏰ Het thoi gian!")
                status_var.set("Timeout")
                status_label.configure(foreground='#ffc107')
                payment_state['completed'] = True
                payment_state['countdown_active'] = False
                
                animation_state['active'] = False
                draw_timeout_animation()                
                payment_window.after(1500, handle_timeout)
        
        def handle_timeout():
            if safe_window_check():
                payment_window.destroy()
        
        def handle_cancel():
            """Xử lý khi người dùng hủy"""
            payment_state['completed'] = True
            payment_state['success'] = False
            payment_state['countdown_active'] = False
            animation_state['active'] = False
            
            if safe_window_check():
                status_var.set("Thanh Toán Bị Huỷ")
                status_label.configure(foreground='#dc3545')
                countdown_var.set("")
                cancel_btn.pack_forget()                
                draw_failure_animation()                
                payment_window.after(1500, payment_window.destroy)
                logging.info("Người dùng hủy thanh toán")
        
        def on_window_close():
            handle_cancel()
        
        threading.Thread(target=load_qr_image, daemon=True).start()
        threading.Thread(target=payment_monitor, daemon=True).start()
        
        update_countdown()
        cancel_btn.config(command=handle_cancel)
        payment_window.protocol("WM_DELETE_WINDOW", on_window_close)
        logging.info(f"Mo cua so thanh toan: {amount:,} VND, Order: {order_id}")
        
        try:
            payment_window.wait_window()
        except:
            logging.error("Payment window error on wait_window")
            pass
        
        return payment_state['success']