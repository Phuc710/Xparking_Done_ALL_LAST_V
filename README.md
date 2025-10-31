# 🚗 XParking - Hệ Thống Quản Lý Bãi Đỗ Xe Thông Minh

![Python](https://img.shields.io/badge/Python-3.8+-blue.svg)
![License](https://img.shields.io/badge/License-MIT-green.svg)
![Status](https://img.shields.io/badge/Status-Production-success.svg)

Hệ thống quản lý bãi đỗ xe tự động hoàn chỉnh với nhận diện biển số xe (LPR), thanh toán QR Code, đặt chỗ trực tuyến, và tích hợp IoT (RFID, MQTT, Arduino).

---

## 📋 Mục Lục

- [Tính Năng Chính](#-tính-năng-chính)
- [Kiến Trúc Hệ Thống](#-kiến-trúc-hệ-thống)
- [Yêu Cầu Hệ Thống](#-yêu-cầu-hệ-thống)
- [Cài Đặt](#-cài-đặt)
- [Cấu Hình](#-cấu-hình)
- [Sử Dụng](#-sử-dụng)
- [Cấu Trúc Dự Án](#-cấu-trúc-dự-án)
- [API Reference](#-api-reference)
- [Công Nghệ Sử Dụng](#-công-nghệ-sử-dụng)
- [Troubleshooting](#-troubleshooting)

---

## ✨ Tính Năng Chính

### 🎯 Core Features

- **Nhận Diện Biển Số Tự Động (LPR)**
  - Sử dụng YOLOv5 cho detection và OCR cho recognition
  - Độ chính xác cao, xử lý realtime
  - Hỗ trợ biển số Việt Nam
  - Lưu ảnh vào/ra (compressed base64)

- **Quản Lý RFID Thông Minh**
  - Pool RFID động với 100 thẻ
  - Tự động phân phối và thu hồi thẻ
  - Tracking usage và last_used
  - Integration với Arduino

- **Thanh Toán QR Code**
  - Tích hợp Sepay/VietQR
  - Tự động tạo QR code thanh toán
  - Hết hạn tự động sau 10 phút (booking) / 3 phút (popup)
  - Webhook callback realtime

- **Hệ Thống Đặt Chỗ (Booking)**
  - Đặt chỗ qua web interface
  - Tự động check-in khi xe vào
  - Miễn phí trong thời gian booking hợp lệ
  - Email xác nhận tự động

### 🌐 Web & Realtime

- **Web Dashboard**
  - Quản lý slots realtime
  - Lịch sử xe ra/vào
  - Quản lý users và bookings
  - Responsive design

- **WebSocket Server**
  - Realtime updates cho web clients
  - Push notifications
  - Slot status broadcasting

- **Email Notifications**
  - Xác nhận booking
  - Thông báo thanh toán
  - Hệ thống cảnh báo

### 🔧 Hardware Integration

- **Arduino Controller**
  - Điều khiển barrier (servo)
  - Đọc RFID tags
  - Gửi dữ liệu qua MQTT

- **Camera System**
  - 2 cameras (vào/ra)
  - Auto frame capture
  - Low-latency streaming

- **IoT Sensors**
  - Gas sensor monitoring
  - Temperature & humidity
  - Emergency alerts

---

## 🏗️ Kiến Trúc Hệ Thống

```
┌─────────────────────────────────────────────────────────────┐
│                     XParking System                         │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐ │
│  │   GUI (Tk)   │    │  Web Client  │    │   Arduino    │ │
│  │  - Status    │    │  - Dashboard │    │  - RFID      │ │
│  │  - Control   │    │  - Booking   │    │  - Barrier   │ │
│  └──────┬───────┘    └──────┬───────┘    └──────┬───────┘ │
│         │                   │                    │         │
│         └───────────────────┼────────────────────┘         │
│                             │                              │
│                    ┌────────▼────────┐                     │
│                    │   main.py       │                     │
│                    │  (Orchestrator) │                     │
│                    └────────┬────────┘                     │
│                             │                              │
│         ┌───────────────────┼───────────────────┐          │
│         │                   │                   │          │
│    ┌────▼─────┐      ┌─────▼──────┐     ┌─────▼──────┐   │
│    │ LPR      │      │ Functions  │     │ Payment    │   │
│    │ (YOLOv5) │      │ (Business) │     │ (Sepay)    │   │
│    └──────────┘      └─────┬──────┘     └────────────┘   │
│                             │                              │
│                    ┌────────▼────────┐                     │
│                    │   Database API  │                     │
│                    │   (Supabase)    │                     │
│                    └────────┬────────┘                     │
│                             │                              │
│                    ┌────────▼────────┐                     │
│                    │   Supabase      │                     │
│                    │   PostgreSQL    │                     │
│                    └─────────────────┘                     │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## 💻 Yêu Cầu Hệ Thống

### Phần Mềm

- **Python**: 3.8 hoặc cao hơn
- **Operating System**: Windows 10/11, Linux, macOS
- **RAM**: Tối thiểu 4GB (khuyến nghị 8GB)
- **GPU**: NVIDIA GPU với CUDA (optional, cho AI tốc độ cao)

### Phần Cứng (Optional)

- **Arduino Uno/Mega**: Điều khiển barrier & RFID
- **RFID RC522 Module**: Đọc thẻ RFID
- **2x USB Cameras**: Quét biển số vào/ra
- **Servo Motor**: Điều khiển barrier tự động

---

## 📦 Cài Đặt

### 1. Clone Repository

```bash
git clone https://github.com/yourusername/xparking.git
cd xparking
```

### 2. Tạo Virtual Environment

```bash
python -m venv venv

# Windows
venv\Scripts\activate

# Linux/macOS
source venv/bin/activate
```

### 3. Cài Đặt Dependencies

```bash
pip install -r requirements.txt
```

### 4. Khởi Tạo Database

```bash
# Import database schema vào Supabase
# Truy cập https://supabase.com và tạo project mới
# Copy nội dung database.sql vào SQL Editor và execute

python init_data.py  # Tạo dữ liệu mẫu
```

### 5. Download AI Models

```bash
# YOLOv5 models sẽ tự động download lần đầu chạy
# Hoặc download thủ công:
cd model/
# Đặt các file weights vào đây:
# - license_plate_detector.pt
# - character_recognition.pt
```

---

## ⚙️ Cấu Hình

### 1. Cấu Hình Supabase

Chỉnh sửa file `db_api.py`:

```python
class DatabaseAPI:
    def __init__(self):
        self.url = "YOUR_SUPABASE_URL"
        self.key = "YOUR_SUPABASE_ANON_KEY"
```

### 2. Cấu Hình Thanh Toán (Sepay)

Chỉnh sửa file `payment.py`:

```python
SEPAY_API_KEY = "YOUR_SEPAY_API_KEY"
BANK_ACCOUNT = "YOUR_BANK_ACCOUNT_NUMBER"
BANK_NAME = "YOUR_BANK_NAME"
```

### 3. Cấu Hình Email

Chỉnh sửa file `email_handler.py`:

```python
SMTP_SERVER = "smtp.gmail.com"
SMTP_PORT = 587
EMAIL_ADDRESS = "your-email@gmail.com"
EMAIL_PASSWORD = "your-app-password"
```

### 4. Cấu Hình MQTT (Optional)

Chỉnh sửa file `config.py`:

```python
MQTT_BROKER = "broker.hivemq.com"
MQTT_PORT = 1883
MQTT_TOPIC_IN = "xparking/in"
MQTT_TOPIC_OUT = "xparking/out"
```

---

## 🚀 Sử Dụng

### Chạy Hệ Thống Chính

```bash
python main.py
```

### Chạy Web Server

```bash
cd hosting-web
# Mở file index.html trong browser
# Hoặc dùng live server
python -m http.server 8000
```

### Chạy WebSocket Server

```bash
python websocket_server.py
```

### Test Các Module

```bash
# Test Database
python test_db.py

# Test Email
python test_mail.py

# Test Payment
python test_pay.py

# Test License Plate Recognition
python QUET_BSX.py
```

---

## 📁 Cấu Trúc Dự Án

```
xparking/
├── main.py                 # Entry point chính
├── config.py              # Cấu hình hệ thống & GUI
├── functions.py           # Business logic chính
├── db_api.py              # Database API (Supabase)
├── payment.py             # Payment integration
├── email_handler.py       # Email notifications
├── QUET_BSX.py           # License Plate Recognition
├── websocket_server.py    # WebSocket server
├── init_data.py          # Database initialization
├── requirements.txt       # Python dependencies
├── database.sql          # Database schema
│
├── arduino/              # Arduino code
│   ├── rfid_barrier.ino
│   └── sensors.ino
│
├── hosting-web/          # Web dashboard
│   ├── index.html
│   ├── booking.html
│   ├── admin.html
│   └── assets/
│
├── model/               # AI models
│   ├── license_plate_detector.pt
│   └── character_recognition.pt
│
├── training/            # Training scripts
│   └── train_model.py
│
└── function/            # Utility functions
    └── helpers.py
```

---

## 📡 API Reference

### Database API (db_api.py)

#### Quản Lý Slots

```python
# Lấy slots trống
db_api.get_available_slots() -> List[Dict]

# Lấy thông tin slot
db_api.get_slot_status(slot_id: str) -> Dict

# Cập nhật slot
db_api.update_slot_status(slot_id: str, status: str, rfid: str) -> bool
```

#### Quản Lý Vehicles

```python
# Ghi xe vào
db_api.record_vehicle_entry(
    license_plate: str,
    slot_id: str,
    rfid: str,
    entry_time: str,
    entry_image_base64: str
) -> bool

# Lấy thông tin xe
db_api.get_vehicle_by_rfid(rfid: str) -> Dict
db_api.get_vehicle_by_license_plate(plate: str) -> Dict

# Ghi xe ra
db_api.complete_vehicle_exit(
    rfid: str,
    license_plate: str,
    exit_time: str,
    paid: bool,
    exit_image_base64: str
) -> bool
```

#### Quản Lý Payments

```python
# Tạo payment
db_api.create_payment_with_snowflake_id(
    amount: int,
    description: str,
    user_id: int,
    booking_id: int,
    expire_minutes: int
) -> Dict

# Cập nhật status
db_api.update_payment_status(payment_id: str, status: str) -> bool

# Expire payments cũ
db_api.expire_old_payments() -> int
```

#### Quản Lý Bookings

```python
# Lấy booking đang active
db_api.get_active_booking(license_plate: str) -> Dict

# Cập nhật booking status
db_api.update_booking_status(booking_id: int, status: str, slot_id: str) -> bool
```

#### Quản Lý RFID

```python
# Lấy RFID trống
db_api.get_available_rfid() -> str

# Thu hồi RFID
db_api.rollback_rfid(rfid: str) -> bool

# Danh sách RFID trống
db_api.get_available_rfids() -> List[str]
```

### Payment API (payment.py)

```python
# Tạo QR thanh toán
payment_manager.create_payment_qr(
    amount: int,
    description: str,
    payment_id: str
) -> str  # Returns QR code base64

# Check payment status
payment_manager.check_payment_status(payment_ref: str) -> Dict
```

---

## 🛠️ Công Nghệ Sử Dụng

### Backend

- **Python 3.8+**: Core language
- **Tkinter**: Desktop GUI
- **Supabase**: Database & Realtime
- **FastAPI**: Web API server
- **WebSockets**: Realtime communication

### AI/ML

- **YOLOv5**: Object detection (license plates)
- **PyTorch**: Deep learning framework
- **OpenCV**: Image processing
- **Pillow**: Image manipulation

### IoT

- **MQTT (Paho)**: IoT messaging protocol
- **Arduino**: Hardware controller
- **RFID RC522**: Tag reading

### Payment

- **Sepay API**: VietQR payment gateway
- **QR Code generation**: Dynamic QR codes

### Frontend

- **HTML/CSS/JavaScript**: Web dashboard
- **Bootstrap/Tailwind**: UI framework
- **WebSocket Client**: Realtime updates

---

## 🐛 Troubleshooting

### Lỗi Kết Nối Database

```bash
# Kiểm tra Supabase URL và API key
python test_db.py
```

**Solution**: Đảm bảo URL và key chính xác trong `db_api.py`

### Camera Không Hoạt Động

```bash
# Test cameras
python -c "import cv2; print(cv2.VideoCapture(0).isOpened())"
```

**Solution**: Kiểm tra camera index trong `config.py`

### AI Model Không Load

```bash
# Kiểm tra GPU
python -c "import torch; print(torch.cuda.is_available())"
```

**Solution**: Download models vào folder `model/`

### MQTT Connection Failed

```bash
# Test MQTT broker
python -c "import paho.mqtt.client as mqtt; print('OK')"
```

**Solution**: Kiểm tra broker URL và port trong `config.py`

### Payment QR Không Tạo

```bash
python test_pay.py
```

**Solution**: Kiểm tra Sepay API key và bank account

### Lỗi Import Module

```bash
# Reinstall dependencies
pip install -r requirements.txt --force-reinstall
```

**Solution**: Đảm bảo tất cả dependencies đã được cài đặt

---

## 🔐 Security

### Best Practices

- ✅ Không commit API keys vào Git
- ✅ Sử dụng environment variables cho credentials
- ✅ HTTPS cho production deployment
- ✅ JWT authentication cho web API
- ✅ Rate limiting cho API endpoints
- ✅ Input validation và sanitization
- ✅ SQL injection prevention (sử dụng ORM)

### Cấu Hình Environment Variables

Tạo file `.env`:

```env
SUPABASE_URL=your_supabase_url
SUPABASE_KEY=your_supabase_key
SEPAY_API_KEY=your_sepay_key
SMTP_PASSWORD=your_email_password
MQTT_USERNAME=your_mqtt_username
MQTT_PASSWORD=your_mqtt_password
```

---

## 📊 Database Schema

### Main Tables

- **users**: Quản lý người dùng
- **parking_slots**: 4 chỗ đỗ xe (can UPDATE)
- **rfid_pool**: Pool 100 thẻ RFID
- **vehicles**: Lịch sử xe ra/vào
- **bookings**: Đặt chỗ trước
- **payments**: Thanh toán với Snowflake ID
- **notifications**: Thông báo hệ thống
- **system_logs**: Logs audit trail

Xem chi tiết trong file `database.sql`

---

## 🔄 Workflow

### Quy Trình Xe Vào (Entry)

1. Camera quét biển số xe
2. AI nhận diện license plate
3. Check booking (nếu có → miễn phí)
4. Lấy RFID từ pool
5. Tìm slot trống
6. Gửi RFID đến Arduino qua MQTT
7. Arduino mở barrier + ghi thẻ
8. Lưu vehicle entry vào database
9. Update slot status → occupied
10. GUI hiển thị realtime

### Quy Trình Xe Ra (Exit)

1. Arduino đọc thẻ RFID
2. Tìm vehicle trong database
3. Camera quét biển số xác nhận
4. Tính phí đỗ xe (nếu không có booking)
5. Hiển thị popup thanh toán QR
6. User scan QR và thanh toán
7. Webhook callback xác nhận
8. Arduino mở barrier
9. Update vehicle exit time
10. Release RFID về pool
11. Update slot status → empty

---

## 📝 License

This project is licensed under the MIT License.

```
MIT License

Copyright (c) 2024 XParking

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

---

## 🚀 Deployment

### Local Development

```bash
python main.py  # Desktop app
python websocket_server.py  # WebSocket server (port 8765)
cd hosting-web && python -m http.server 8000  # Web interface
```

### Production (Linux Server)

```bash
# Install system dependencies
sudo apt-get update
sudo apt-get install python3-pip python3-venv

# Setup virtual environment
python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt

## 👥 Contributors

- **Phucx (JeRus)** - Lead Developer

---

## 🙏 Acknowledgments

- YOLOv5 by Ultralytics
- Supabase for amazing BaaS
- Sepay for VietQR integration
- OpenCV community
- Arduino community
- All testers and contributors

---

## 📞 Support & Contact

- 📧 **Email**: support@xparking.com
- 💬 **Issues**: [GitHub Issues](https://github.com/Phuc710/Xparking)
- 📖 **Documentation**: Xem file này và code comments

---

<div align="center">

**Made with ❤️ Phucx**

⭐ Star this repo if you find it helpful!

[⬆ Back to top](#-xparking---hệ-thống-quản-lý-bãi-đỗ-xe-thông-minh)

</div>
