#include <SPI.h>
#include <MFRC522.h>
#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>
#include <WiFi.h>
#include <PubSubClient.h>
#include <ArduinoJson.h>
#include <ESP32Servo.h>

// --- Cau hinh Pinout ---
#define SS_PIN 5
#define RST_PIN 27
#define IR_OUT_PIN 2
#define BUZZER_PIN 15
#define SERVO_OUT_PIN 4

const unsigned long RFID_CHECK_TIMEOUT = 10000;
const unsigned long IR_STABLE_TIME = 1000;
const unsigned long STATUS_REPORT_INTERVAL = 5000;
const unsigned long AUTO_CLOSE_DELAY = 500; // sau khi xe qua

// Cau hinh OLED
#define OLED_ADDR 0x3C
#define SCREEN_WIDTH 128
#define SCREEN_HEIGHT 64

// Cau hinh Servo
const int BARRIER_CLOSED_ANGLE = 0;
const int BARRIER_OPENED_ANGLE = 90;

// Cau hinh WiFi & MQTT
const char* WIFI_SSID = "XANHCAFE";
const char* WIFI_PASSWORD = "012345678";
const char* MQTT_SERVER = "192.168.1.94";
const int MQTT_PORT = 1883;


// --- Khai bao cac doi tuong toan cuc ---
Adafruit_SSD1306 display(SCREEN_WIDTH, SCREEN_HEIGHT, &Wire, -1);
WiFiClient espClient;
PubSubClient client(espClient);
MFRC522 rfid(SS_PIN, RST_PIN);
Servo servoOut;

// --- Bien trang thai ---
bool isBuzzing = false;
int buzzCount = 0;
bool barrierOutCurrentlyOpen = false;
bool carPassedThrough = false;
String currentRfidTag = "";
unsigned long rfidCheckStartTime = 0;
unsigned long lastStatusReport = 0;
unsigned long carPassTime = 0;

enum State {
  IDLE,
  WAITING_FOR_BSX,
  WAITING_FOR_RFID,
  RFID_SCANNED,
  WAITING_FOR_PAYMENT,
  BARRIER_OPEN_WAITING_PASS
};
State currentState = IDLE;

// --- Ham chuc nang ---
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

void openBarrierOut() {
  if (!barrierOutCurrentlyOpen) {
    servoOut.write(BARRIER_OPENED_ANGLE);
    barrierOutCurrentlyOpen = true;
    carPassedThrough = false;
    Serial.println("mo barrier Exit");
  }
}

void closeBarrierOut() {
  if (barrierOutCurrentlyOpen) {
    servoOut.write(BARRIER_CLOSED_ANGLE);
    barrierOutCurrentlyOpen = false;
    Serial.println("dong barrier Exit");
    updateOled("X-PARKING", "Exit");
  }
}

void resetBarrierPosition() {
  servoOut.write(BARRIER_CLOSED_ANGLE);
  barrierOutCurrentlyOpen = false;
  Serial.println("reset barrier ve vi tri dong");
}

void publishMessage(String topic, String event, String data = "") {
  // Dung DynamicJsonDocument de an toan hon
  DynamicJsonDocument doc(300); 
  doc["event"] = event;
  doc["station"] = "OUT";
  doc["timestamp"] = millis();
  if (data != "") {
    doc["data"] = data;
  }
  char buffer[400];
  serializeJson(doc, buffer);
  client.publish(topic.c_str(), buffer);
}

void publishRfid(String event, String rfid) {
  // Dung DynamicJsonDocument de an toan hon
  DynamicJsonDocument doc(200);
  doc["event"] = event;
  doc["station"] = "OUT";
  doc["rfid"] = rfid;
  doc["timestamp"] = millis();
  char buffer[256];
  serializeJson(doc, buffer);
  client.publish("xparking/rfid", buffer);
}

void publishStatus() {
  // Dung DynamicJsonDocument de an toan hon
  DynamicJsonDocument doc(400); 
  doc["event"] = "STATUS_REPORT";
  doc["station"] = "OUT";
  doc["state"] = String(currentState);
  doc["current_rfid"] = currentRfidTag;
  doc["barrier_out_open"] = barrierOutCurrentlyOpen;
  doc["timestamp"] = millis();
  char buffer[512];
  serializeJson(doc, buffer);
  client.publish("xparking/status/out", buffer);
}

void handleBuzz() {
  static unsigned long lastBuzzTime = 0;
  static bool buzzState = false;
  
  if (isBuzzing && buzzCount > 0) {
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

bool scanRFID() {
  if (!rfid.PICC_IsNewCardPresent() || !rfid.PICC_ReadCardSerial()) return false;
  currentRfidTag = "";
  for (byte i = 0; i < rfid.uid.size; i++) {
    currentRfidTag += (rfid.uid.uidByte[i] < 0x10 ? "0" : "");
    currentRfidTag += String(rfid.uid.uidByte[i], HEX);
  }
  currentRfidTag.toUpperCase();
  rfid.PICC_HaltA();
  rfid.PCD_StopCrypto1();
  return true;
}

void mqttCallback(char* topic, byte* payload, unsigned int length) {
  Serial.print("nhan mqtt [");
  Serial.print(topic);
  Serial.print("]: ");
  String message = "";
  for (int i = 0; i < length; i++) {
    message += (char)payload[i];
  }
  Serial.println(message);

  // Tang kich thuoc StaticJsonDocument hoac su dung DynamicJsonDocument
  StaticJsonDocument<400> doc;
  DeserializationError error = deserializeJson(doc, message);
  if (error) {
    Serial.print("loi json: ");
    Serial.println(error.c_str());
    return;
  }
  
  String event = doc["event"] | "";
  
  if (event == "OPEN_BARRIER_OUT" || event == "OPEN_BARRIER") { // Xu ly ca 2 event de tuong thich
    Serial.println("lenh mo barrier Exit");
    openBarrierOut();
    publishMessage("xparking/exit", "BARRIER_OUT_OPENED");
    currentState = BARRIER_OPEN_WAITING_PASS;
  }

  if (event == "PAYMENT_SUCCESS") {
    Serial.println("thanh toan thanh cong - mo barrier");
    buzz(1, 200);
    updateOled("THANH TOAN TT", "TAM BIET");
    openBarrierOut();
    currentState = BARRIER_OPEN_WAITING_PASS;
  }

  if (event == "PAYMENT_FAIL") {
    Serial.println("[EXIT] Thanh toan that bai - reset ve IDLE");
    buzz(3, 200, 200);
    
    // Hiện lỗi 1 giây rồi reset về đầu
    updateOled("THANH TOAN LOI", "THU LAI");
    delay(1000);  // 1 giây
    
    // Reset về IDLE để xe có thể retry toàn bộ flow
    currentState = IDLE;
    updateOled("X-PARKING", "Exit");
    
    Serial.println("[EXIT] Da reset ve IDLE - xe co the thu lai tu dau");
  }

  if (event == "SHOW_MESSAGE") {
    String line1 = doc["line1"] | "";
    String line2 = doc["line2"] | "";
    updateOled(line1, line2);
  }

  if (event == "BSX_DETECTED") {
    String licensePlate = doc["license_plate"] | "";
    Serial.print("[EXIT] Nhan dien BSX thanh cong: ");
    Serial.println(licensePlate);
    updateOled("QUET THE RFID", licensePlate);
    currentState = WAITING_FOR_RFID;
    rfidCheckStartTime = millis();
  }

  if (event == "BSX_FAILED") {
    Serial.println("[EXIT] Nhan dien BSX that bai - reset ve IDLE");
    
    // Hiện lỗi 1 giây rồi reset
    updateOled("LOI NHAN DIEN", "THU LAI");
    delay(1000);  // 1 giây
    
    // Reset về IDLE để xe có thể trigger lại (ra khỏi rồi vào lại IR)
    currentState = IDLE;
    updateOled("X-PARKING", "Exit");
    
    Serial.println("[EXIT] Da reset ve IDLE - xe co the thu lai");
  }
  
  // Event khi RFID sai - Python gửi để cho quét lại
  if (event == "RFID_MISMATCH") {
    Serial.println("[EXIT] RFID khong khop - cho quet lai");
    buzz(2, 150, 200);
    
    // Hiện lỗi 1 giây
    updateOled("THE SAI", "QUET LAI");
    delay(1000);
    
    // Quay lại chờ quét RFID (KHÔNG reset về IDLE)
    String licensePlate = doc["license_plate"] | "";
    updateOled("QUET THE RFID", licensePlate);
    currentState = WAITING_FOR_RFID;
    rfidCheckStartTime = millis();  // Reset timeout
    
    Serial.println("[EXIT] Cho quet RFID lai");
  }
}

void reconnect() {
  while (!client.connected()) {
    Serial.print("ket noi mqtt...");
    String clientId = "ESP32-OUT-" + String(WiFi.macAddress());
    if (client.connect(clientId.c_str())) {
      Serial.println("ket noi mqtt thanh cong");
      
      client.subscribe("xparking/command/out");
      client.subscribe("xparking/alert"); // Them subscription alert
      
      publishStatus();
    } else {
      Serial.print("ket noi that bai, rc=");
      Serial.print(client.state());
      Serial.println(" thu lai sau 5 giay");
      delay(5000);
    }
  }
}

void handleIRDetection() {
  bool currentIRState = digitalRead(IR_OUT_PIN) == LOW;
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
  
  switch (currentState) {
    case IDLE:
      if (currentIRState) {
        Serial.println("[EXIT] Phat hien xe tai cong ra - bat dau nhan dien");
        publishMessage("xparking/exit", "CAR_DETECT_OUT");
        updateOled("NHAN DIEN BSX", "VUI LONG CHO");
        currentState = WAITING_FOR_BSX;
      }
      break;
      
    case BARRIER_OPEN_WAITING_PASS:
      if (!currentIRState && !carPassedThrough) {
        // Xe da qua khoi cam bien IR
        carPassedThrough = true;
        carPassTime = millis();
        Serial.println("[EXIT] Xe da qua cam bien IR - bat dau dem thoi gian dong barrier");
        publishMessage("xparking/exit", "CAR_EXITED");
      }
      break;
      
    default:
      break;
  }
}

void handleAutoCloseBarrier() {
  if (currentState == BARRIER_OPEN_WAITING_PASS && carPassedThrough) {
    if (millis() - carPassTime >= AUTO_CLOSE_DELAY) {
      closeBarrierOut();
      currentState = IDLE;
      carPassedThrough = false;
      Serial.println("tu dong dong barrier sau khi xe qua");
    }
  }
}

void handleRFIDScanning() {
  if (currentState != WAITING_FOR_RFID) return;
  
  // Timeout 10 giây - không quét thì tự động reset về đầu
  if (millis() - rfidCheckStartTime > RFID_CHECK_TIMEOUT) {
    Serial.println("[EXIT] Timeout quet RFID - reset ve IDLE");
    
    // Hiện lỗi 1 giây rồi reset
    updateOled("TIMEOUT RFID", "THU LAI");
    delay(1000);  // 1 giây thôi
    
    // Reset về đầu để xe có thể trigger lại
    currentState = IDLE;
    updateOled("X-PARKING", "Exit");
    
    Serial.println("[EXIT] Da reset ve trang thai IDLE - san sang xu ly xe moi");
    return;
  }
  
  if (scanRFID()) {
    Serial.print("[EXIT] Quet RFID thanh cong: ");
    Serial.println(currentRfidTag);
    publishRfid("RFID_SCANNED", currentRfidTag);
    updateOled("XAC THUC...", currentRfidTag);
    currentState = RFID_SCANNED;
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
  Serial.println("===== KHOI DONG ESP32 CONG RA =====");
  
  pinMode(IR_OUT_PIN, INPUT_PULLUP);
  pinMode(BUZZER_PIN, OUTPUT);
  
  servoOut.attach(SERVO_OUT_PIN);

  if (!display.begin(SSD1306_SWITCHCAPVCC, OLED_ADDR)) {
    Serial.println("loi khoi tao oled");
    while(1);
  }
  display.clearDisplay();
  updateOled("X-PARKING", "KHOI DONG...");

  SPI.begin();
  rfid.PCD_Init();
  Serial.println("khoi tao rfid thanh cong");

  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  Serial.print("ket noi wifi");
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }
  Serial.println();
  Serial.print("WIFI ket noi thanh cong tai IP: ");
  Serial.println(WiFi.localIP());

  client.setServer(MQTT_SERVER, MQTT_PORT);
  client.setCallback(mqttCallback);

  updateOled("KET NOI", "THANH CONG");
  delay(2000);
  updateOled("X-PARKING", "Exit");
  
  Serial.println("===== HE THONG CONG RA SAN SANG HOAT DONG =====");
  currentState = IDLE;
}

void loop() {
  if (!client.connected()) {
    reconnect();
  }
  client.loop();
  
  handleIRDetection();
  handleRFIDScanning();
  handleAutoCloseBarrier();
  handleBuzz();
  handleStatusReporting();
  
  delay(50);
}
