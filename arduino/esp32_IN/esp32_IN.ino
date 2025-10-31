#include <SPI.h>
#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>
#include <WiFi.h>
#include <PubSubClient.h>
#include <ArduinoJson.h>
#include <ESP32Servo.h>

// --- CAU HINH PINOUT ---
#define IR_IN_PIN 2
#define BUZZER_PIN 15
#define SERVO_IN_PIN 4
#define SMOKE_PIN 32
const int SLOT_PINS[4] = {25, 26, 33, 14};

// --- CAU HINH CHUNG ---
const int SMOKE_THRESHOLD = 6000;
const unsigned long SLOT_MONITOR_TIMEOUT = 10000;
const unsigned long IR_STABLE_TIME = 1000;
const unsigned long STATUS_REPORT_INTERVAL = 5000;
// Giảm trễ để barrier đóng nhanh hơn sau khi xe qua
const unsigned long AUTO_CLOSE_DELAY = 500; 

// CAU HINH OLED
#define OLED_ADDR 0x3C
#define SCREEN_WIDTH 128
#define SCREEN_HEIGHT 64

// CAU HINH SERVO
const int BARRIER_CLOSED_ANGLE = 0;
const int BARRIER_OPENED_ANGLE = 90;

// CAU HINH WIFI & MQTT
const char* WIFI_SSID = "XANHCAFE";
const char* WIFI_PASSWORD = "012345678";
const char* MQTT_SERVER = "192.168.1.94";
const int MQTT_PORT = 1883;

// --- KHAI BAO DOI TUONG TOAN CUC ---
Adafruit_SSD1306 display(SCREEN_WIDTH, SCREEN_HEIGHT, &Wire, -1);
WiFiClient espClient;
PubSubClient client(espClient);
Servo servoIn;

// --- BIEN TRANG THAI ---
bool isBuzzing = false;
int buzzCount = 0;
bool barrierInCurrentlyOpen = false;
bool emergencyActive = false;
bool isMonitoringSlots = false;
bool carPassedThrough = false;
unsigned long slotMonitorStartTime = 0;
unsigned long lastStatusReport = 0;
unsigned long carPassTime = 0;

int emptySlotsToMonitor = 0;
int slotsToMonitor[4];

enum State {
  IDLE,
  PROCESSING_ENTRY,
  WAITING_FOR_SLOT,
  BARRIER_OPEN_WAITING_PASS,
  EMERGENCY
};
State currentState = IDLE;

// --- HAM CHUC NANG ---

void updateOled(String line1, String line2 = "", bool clearFirst = true) {
  if (clearFirst) {
    display.clearDisplay();
  }
  display.setTextSize(1);
  display.setTextColor(WHITE);
  
  int16_t x1, y1;
  uint16_t w1, h1;
  display.getTextBounds(line1, 0, 0, &x1, &y1, &w1, &h1);
  display.setCursor((SCREEN_WIDTH - w1) / 2, 10);
  display.println(line1);

  if (line2.length() > 0) {
    int16_t x2, y2;
    uint16_t w2, h2;
    display.getTextBounds(line2, 0, 0, &x2, &y2, &w2, &h2);
    display.setCursor((SCREEN_WIDTH - w2) / 2, 30);
    display.println(line2);
  }
  
  display.display();
}

void buzz(int count, int duration = 100, int interval = 150) {
  isBuzzing = true;
  buzzCount = count;
}

void openBarrierIn() {
  if (!barrierInCurrentlyOpen) {
    servoIn.write(BARRIER_OPENED_ANGLE);
    barrierInCurrentlyOpen = true;
    carPassedThrough = false; // Reset cờ xe qua khi mở barrier
    Serial.println("MO BARRIER CONG VAO");
  }
}

void closeBarrierIn() {
  if (barrierInCurrentlyOpen) {
    servoIn.write(BARRIER_CLOSED_ANGLE);
    barrierInCurrentlyOpen = false;
    Serial.println("DONG BARRIER CONG VAO");
    // Chi cập nhật OLED nếu không trong chế độ khẩn cấp
    if (!emergencyActive) {
        updateOled("X PARKING", "Entrance");
    }
  }
}

void resetBarrierPosition() {
  servoIn.write(BARRIER_CLOSED_ANGLE);
  barrierInCurrentlyOpen = false;
  Serial.println("RESET BARRIER VE VI TRI DONG");
}

void publishMessage(String topic, String event, String data = "") {
  DynamicJsonDocument doc(300);
  doc["event"] = event;
  doc["station"] = "IN";
  doc["timestamp"] = millis();
  if (data != "") {
    doc["data"] = data;
  }
  char buffer[400];
  serializeJson(doc, buffer);
  client.publish(topic.c_str(), buffer);
}

void publishStatus() {
  // TINH TOAN SO SLOT CHIEM DUNG TRUC TIEP TU CAM BIEN CHO BAO CAO
  int occupiedSlotsCount = 0;
  for (int i = 0; i < 4; i++) {
    if (digitalRead(SLOT_PINS[i]) == LOW) {
      occupiedSlotsCount++;
    }
  }
  
  DynamicJsonDocument doc(600);
  doc["event"] = "STATUS_REPORT";
  doc["station"] = "IN";
  doc["state"] = (int)currentState;
  doc["emergency"] = emergencyActive;
  doc["barrier_open"] = barrierInCurrentlyOpen;
  doc["monitoring_slots"] = isMonitoringSlots;
  doc["occupied_slots"] = occupiedSlotsCount; // Su dung gia tri tinh toan truc tiep
  doc["available_slots"] = 4 - occupiedSlotsCount;
  doc["smoke_level"] = analogRead(SMOKE_PIN);
  doc["timestamp"] = millis();
  
  // THEM TRANG THAI SLOT CHI TIET
  JsonArray slots = doc.createNestedArray("slot_status");
  for (int i = 0; i < 4; i++) {
    JsonObject slot = slots.createNestedObject();
    slot["id"] = String("A0") + String(i + 1);
    // CHI DOC SLOT KHI KHONG DANG MONITORING
    slot["occupied"] = digitalRead(SLOT_PINS[i]) == LOW;
  }
  
  char buffer[700];
  serializeJson(doc, buffer);
  client.publish("xparking/status/in", buffer);
}

void handleBuzz() {
  static unsigned long lastBuzzTime = 0;
  static bool buzzState = false;
  if (isBuzzing && buzzCount > 0) {
    // Đảm bảo không bị Blocking Delay
    if (millis() - lastBuzzTime > 150) { 
      buzzState = !buzzState;
      digitalWrite(BUZZER_PIN, buzzState ? HIGH : LOW);
      lastBuzzTime = millis();
      
      if (!buzzState) {
        buzzCount--;
        if (buzzCount <= 0) {
          isBuzzing = false;
          digitalWrite(BUZZER_PIN, LOW);
        }
      }
    }
  } else {
    digitalWrite(BUZZER_PIN, LOW);
    isBuzzing = false;
  }
}

void checkSmokeSensor() {
  static unsigned long lastSmokeCheck = 0;
  if (millis() - lastSmokeCheck < 2000) return;
  lastSmokeCheck = millis();
  
  int sensorValue = analogRead(SMOKE_PIN);
  
  if (sensorValue > SMOKE_THRESHOLD && !emergencyActive) {
    emergencyActive = true;
    currentState = EMERGENCY;
    Serial.println("KHAN CAP - PHAT HIEN KHOI");
    publishMessage("xparking/alert", "EMERGENCY_SMOKE", String(sensorValue));
    buzz(10, 200, 300);
    openBarrierIn();
    updateOled("!!! KHAN CAP !!!", "PHAT HIEN KHOI");
  } else if (sensorValue <= SMOKE_THRESHOLD - 500 && emergencyActive) {
    emergencyActive = false;
    currentState = IDLE;
    Serial.println("KET THUC CHE DO KHAN CAP");
    publishMessage("xparking/alert", "EMERGENCY_CLEAR", String(sensorValue));
    closeBarrierIn();
    updateOled("X PARKING", "Entrance");
  }
}

void mqttCallback(char* topic, byte* payload, unsigned int length) {
  String message = "";
  for (int i = 0; i < length; i++) {
    message += (char)payload[i];
  }
  
  Serial.print("NHAN MQTT [");
  Serial.print(topic);
  Serial.print("]: ");
  Serial.println(message);

  DynamicJsonDocument doc(400);
  DeserializationError error = deserializeJson(doc, message);
  if (error) {
    Serial.print("LOI JSON: ");
    Serial.println(error.c_str());
    return;
  }
  
  String event = doc["event"] | "";
  
  if (event == "OPEN_BARRIER") {
    Serial.println("LENH MO BARRIER");
    openBarrierIn();
    // Đặt trạng thái chờ xe qua để kích hoạt auto close
    currentState = BARRIER_OPEN_WAITING_PASS; 
    publishMessage("xparking/entrance", "BARRIER_OPENED");
  }
  
  if (event == "SHOW_MESSAGE") {
    String line1 = doc["line1"] | "";
    String line2 = doc["line2"] | "";
    updateOled(line1, line2);
  }

  if (event == "START_SLOT_MONITOR") {
    isMonitoringSlots = true;
    slotMonitorStartTime = millis();
    // CHỈ thay đổi trạng thái nếu không phải BARRIER_OPEN_WAITING_PASS
    if (currentState != BARRIER_OPEN_WAITING_PASS) {
        currentState = WAITING_FOR_SLOT;
    }
    emptySlotsToMonitor = 0;
    
    Serial.println("[SLOT] Bat dau giam sat slot - BE xu ly");
    
    // KHÔNG HIỆN TRÊN OLED - Backend xử lý việc này
    // OLED vẫn giữ message "MOI XE VAO" hoặc message trước đó
    
    // Parse danh sach slot can monitor
    if (doc.containsKey("slots") && doc["slots"].is<JsonArray>()) {
      JsonArray slots = doc["slots"].as<JsonArray>();
      for (JsonVariant slot : slots) {
        String slot_id = slot.as<String>();
        for (int i = 0; i < 4; i++) {
          if (slot_id == "A0" + String(i + 1)) {
            if (emptySlotsToMonitor < 4) {
              slotsToMonitor[emptySlotsToMonitor++] = SLOT_PINS[i];
              Serial.print("[SLOT] Them slot monitor: ");
              Serial.println(slot_id);
            }
            break;
          }
        }
      }
    }
  }
  
  if (event == "STOP_SLOT_MONITOR") {
    isMonitoringSlots = false;
    // Chuyển về IDLE nếu không phải EMERGENCY
    if (!emergencyActive) {
        currentState = IDLE;
    }
    emptySlotsToMonitor = 0;
    Serial.println("DUNG GIAM SAT SLOT");
  }
}

void reconnect() {
  while (!client.connected()) {
    Serial.print("KET NOI MQTT...");
    String clientId = "ESP32-IN-" + String(WiFi.macAddress());
    if (client.connect(clientId.c_str())) {
      Serial.println("THANH CONG");
      client.subscribe("xparking/command/in");
      client.subscribe("xparking/alert");
      publishStatus();
    } else {
      Serial.print("THAT BAI, rc=");
      Serial.print(client.state());
      Serial.println(" THU LAI SAU 5 GIAY");
      delay(5000);
    }
  }
}

// LOGIC ĐƯỢC CẬP NHẬT ĐỂ TÁCH BIỆT AUTO CLOSE VỚI TRẠNG THÁI MONITORING
void handleIRDetection() {
  bool currentIRState = digitalRead(IR_IN_PIN) == LOW; // LOW = xe chặn
  static bool lastIRState = false;
  static unsigned long stableStartTime = 0;
  
  if (currentIRState != lastIRState) {
    lastIRState = currentIRState;
    stableStartTime = millis();
    return;
  }
  
  if (millis() - stableStartTime < IR_STABLE_TIME) {
    return;
  }

  // --- LOGIC CHUNG CHO AUTO CLOSE ---
  // Nếu barrier đang mở VÀ IR chuyển từ ON (xe chặn) sang OFF (xe qua)
  if (barrierInCurrentlyOpen && !currentIRState && !carPassedThrough) {
      carPassedThrough = true;
      carPassTime = millis();
      Serial.println("XE DA QUA CAM BIEN IR - BAT DAU DEM NGUOC AUTO CLOSE");
      publishMessage("xparking/entrance", "CAR_PASSED_IR"); // Gửi thông báo xe qua
  }
  // ----------------------------------
  
  switch (currentState) {
    case IDLE:
      if (currentIRState) {
        currentState = PROCESSING_ENTRY;
        Serial.println("[ENTRANCE] Phat hien xe tai cong vao - bat dau xu ly");
        publishMessage("xparking/entrance", "CAR_DETECT_IN");
        updateOled("NHAN DIEN BSX", "VUI LONG CHO");
      }
      break;
      
    // BARRIER_OPEN_WAITING_PASS không cần logic riêng trong switch nữa
    case BARRIER_OPEN_WAITING_PASS:
      break;
      
    default:
      break;
  }
}

// LOGIC ĐƯỢC CẬP NHẬT ĐỂ ĐÓNG BARRIER BẤT KỂ TRẠNG THÁI MONITORING
void handleAutoCloseBarrier() {
  // TU DONG DONG BARRIER SAU KHI XE DI QUA, KHÔNG PHỤ THUỘC TRẠNG THÁI HỆ THỐNG
  // Điều kiện: Barrier đang mở VÀ xe đã đi qua IR
  if (barrierInCurrentlyOpen && carPassedThrough) {
    if (millis() - carPassTime >= AUTO_CLOSE_DELAY) {
      closeBarrierIn();
      
      // Đặt lại trạng thái IDLE CHỈ KHI KHÔNG PHẢI EMERGENCY
      if (!emergencyActive) {
          // Nếu đang WAITING_FOR_SLOT hoặc BARRIER_OPEN_WAITING_PASS, chuyển về IDLE
          if (currentState == WAITING_FOR_SLOT || currentState == BARRIER_OPEN_WAITING_PASS) {
            currentState = IDLE;
            Serial.println("CHUYEN TRANG THAI VE IDLE SAU KHI DONG BARRIER");
          }
      }
      
      // Reset cờ
      carPassedThrough = false;
      Serial.println("DA DONG BARRIER ENTRANCE (AUTO)");
    }
  }
}

void handleSlotMonitoring() {
  if (!isMonitoringSlots) return;
  
  // TIMEOUT MONITORING
  if (millis() - slotMonitorStartTime > SLOT_MONITOR_TIMEOUT) {
    Serial.println("TIMEOUT GIAM SAT SLOT");
    isMonitoringSlots = false;
    // Chuyển về IDLE nếu không phải EMERGENCY
    if (!emergencyActive) {
        currentState = IDLE;
    }
    emptySlotsToMonitor = 0;
    publishMessage("xparking/slots", "MONITOR_TIMEOUT");
    return;
  }
  
  // CHI DOC CAC SLOT DUOC CHI DINH
  for (int i = 0; i < emptySlotsToMonitor; i++) {
    if (digitalRead(slotsToMonitor[i]) == LOW) {
      // DEBOUNCE CHECK
      delay(200);
      if (digitalRead(slotsToMonitor[i]) == LOW) {
        // TIM SLOT ID TU PIN
        for (int j = 0; j < 4; j++) {
          if (slotsToMonitor[i] == SLOT_PINS[j]) {
            String slotId = "A0" + String(j + 1);
            Serial.print("PHAT HIEN XE VAO SLOT: ");
            Serial.println(slotId);
            
            publishMessage("xparking/slots", "CAR_ENTERED_SLOT", slotId);
            isMonitoringSlots = false;
            emptySlotsToMonitor = 0;
            // Chuyển về IDLE nếu không phải EMERGENCY
            if (!emergencyActive) {
                currentState = IDLE;
            }
            Serial.println("XE DA VAO SLOT - GUI TIN DEN PYTHON");
            
            return;
          }
        }
      }
    }
  }
}

void handleStatusReporting() {
  if (millis() - lastStatusReport >= STATUS_REPORT_INTERVAL) {
    lastStatusReport = millis();
    publishStatus();
  }
}

void setup() {
  Serial.begin(115200);
  Serial.println("===== KHOI DONG ESP32 CONG VAO - HE THONG XPARKING =====");
  Serial.println("Phien ban: v2.0 - Toi uu hoa");
  
  // Khoi tao pin modes
  pinMode(IR_IN_PIN, INPUT_PULLUP);
  pinMode(BUZZER_PIN, OUTPUT);
  pinMode(SMOKE_PIN, INPUT);
  for (int i = 0; i < 4; i++) {
    pinMode(SLOT_PINS[i], INPUT_PULLUP);
  }
  
  // Khoi tao servo
  ESP32PWM::allocateTimer(0);
  ESP32PWM::allocateTimer(1);
  ESP32PWM::allocateTimer(2);
  ESP32PWM::allocateTimer(3);
  servoIn.setPeriodHertz(50); // Standard 50Hz for servo
  servoIn.attach(SERVO_IN_PIN, 500, 2500); // Tùy chỉnh min/max pulse width
  resetBarrierPosition();
  
  // Khoi tao OLED
  if (!display.begin(SSD1306_SWITCHCAPVCC, OLED_ADDR)) {
    Serial.println("LOI KHOI TAO OLED");
    while(1);
  }
  display.clearDisplay();
  updateOled("X PARKING", "KHOI DONG...");
  
  // Ket noi WiFi
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  Serial.print("KET NOI WIFI");
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }
  Serial.println();
  Serial.print("WIFI ket noi thanh cong tai IP: ");
  Serial.println(WiFi.localIP());
  
  // Ket noi MQTT
  client.setServer(MQTT_SERVER, MQTT_PORT);
  client.setCallback(mqttCallback);
  
  updateOled("KET NOI", "THANH CONG");
  delay(2000);
  updateOled("X PARKING", "Entrance");
  Serial.println("===== HE THONG CONG VAO SAN SANG HOAT DONG =====");
  currentState = IDLE;
}

void loop() {
  if (!client.connected()) {
    reconnect();
  }
  client.loop();
  
  // Logic chung chạy ngay cả khi đang monitoring (trừ emergency mode)
  if (!emergencyActive) {
    handleIRDetection();
    handleAutoCloseBarrier(); // Đóng barrier tự động
    handleSlotMonitoring(); // Giám sát slot vẫn chạy song song
  } else {
      // Trong EMERGENCY mode, chỉ check smoke và buzz
  }
  
  checkSmokeSensor();
  handleBuzz();
  handleStatusReporting();
  delay(50);
}