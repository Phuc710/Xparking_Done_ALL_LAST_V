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
