#include <SoftwareSerial.h>
#include <DHT.h>
#include "MHZ19.h"
#include <PMS.h>

// Define pins
#define DHTPIN 10
#define BUZZER_PIN 9
#define RED_LED_PIN 11
#define YELLOW_LED_PIN 12  // Assuming yellow LED is on pin 12
#define GREEN_LED_PIN 13

// Gas sensor pins
#define NO2_PIN A1
#define NH3_PIN A2
#define CO_PIN A3

// DHT sensor type
#define DHTTYPE DHT11

// Thresholds
#define TEMP_WARNING 27.0
#define TEMP_DANGER 30.0
#define CO2_WARNING 1000
#define CO2_DANGER 2000
#define PM25_WARNING 35
#define PM25_DANGER 55

// Initialize sensors
DHT dht(DHTPIN, DHTTYPE);
MHZ19 myMHZ19;
PMS pms(Serial);
PMS::DATA pmsData;

// SoftwareSerial for MH-Z19 (since PMS5003 uses hardware Serial)
SoftwareSerial mySerial(A0, A1); // RX, TX

void setup() {
  Serial.begin(9600);
  mySerial.begin(9600);
  
  // Initialize pins
  pinMode(BUZZER_PIN, OUTPUT);
  pinMode(RED_LED_PIN, OUTPUT);
  pinMode(YELLOW_LED_PIN, OUTPUT);
  pinMode(GREEN_LED_PIN, OUTPUT);
  
  // Initialize sensors
  dht.begin();
  myMHZ19.begin(mySerial);
  myMHZ19.autoCalibration();
  
  // Start PMS5003
  Serial.begin(9600);
  pms.passiveMode();
  
  // Turn off all LEDs initially
  digitalWrite(RED_LED_PIN, LOW);
  digitalWrite(YELLOW_LED_PIN, LOW);
  digitalWrite(GREEN_LED_PIN, LOW);
  
  Serial.println("System initialized");
}

void loop() {
  // Read DHT11 (temperature and humidity)
  float temperature = dht.readTemperature();
  float humidity = dht.readHumidity();
  
  // Read MH-Z19 (CO2)
  int co2 = myMHZ19.getCO2();
  
  // Read PMS5003 (particulate matter)
  pms.wakeUp();
  delay(30000); // Wait 30 seconds for stable readings
  pms.requestRead();
  if (pms.readUntil(pmsData)) {
    // Data read successfully
  }
  pms.sleep();
  
  // Read gas sensors
  int no2Value = analogRead(NO2_PIN);
  int nh3Value = analogRead(NH3_PIN);
  int coValue = analogRead(CO_PIN);
  
  // Process temperature alerts
  if (!isnan(temperature)) {
    if (temperature >= TEMP_DANGER) {
      digitalWrite(RED_LED_PIN, HIGH);
      digitalWrite(YELLOW_LED_PIN, LOW);
      digitalWrite(GREEN_LED_PIN, LOW);
      tone(BUZZER_PIN, 1000); // Sound alarm
    } 
    else if (temperature >= TEMP_WARNING) {
      digitalWrite(RED_LED_PIN, LOW);
      digitalWrite(YELLOW_LED_PIN, HIGH);
      digitalWrite(GREEN_LED_PIN, LOW);
      tone(BUZZER_PIN, 500, 1000); // Beep intermittently
    } 
    else {
      digitalWrite(RED_LED_PIN, LOW);
      digitalWrite(YELLOW_LED_PIN, LOW);
      digitalWrite(GREEN_LED_PIN, HIGH);
      noTone(BUZZER_PIN);
    }
  }
  
  // Check gas levels (simplified - you'll need to calibrate for your specific sensors)
  bool gasWarning = false;
  if (no2Value > 300 || nh3Value > 300 || coValue > 300) { // Adjust thresholds based on your calibration
    gasWarning = true;
    digitalWrite(YELLOW_LED_PIN, HIGH);
  }
  
  // Check CO2 levels
  if (co2 >= CO2_DANGER) {
    digitalWrite(RED_LED_PIN, HIGH);
    tone(BUZZER_PIN, 1000);
  } 
  else if (co2 >= CO2_WARNING) {
    digitalWrite(YELLOW_LED_PIN, HIGH);
    tone(BUZZER_PIN, 500, 1000);
  }
  
  // Check PM2.5 levels
  if (pmsData.PM_AE_UG_2_5 >= PM25_DANGER) {
    digitalWrite(RED_LED_PIN, HIGH);
    tone(BUZZER_PIN, 1000);
  } 
  else if (pmsData.PM_AE_UG_2_5 >= PM25_WARNING) {
    digitalWrite(YELLOW_LED_PIN, HIGH);
    tone(BUZZER_PIN, 500, 1000);
  }
  
  // Print all readings to serial monitor
  Serial.println("=== Environmental Readings ===");
  Serial.print("Temperature: "); Serial.print(temperature); Serial.println(" °C");
  Serial.print("Humidity: "); Serial.print(humidity); Serial.println(" %");
  Serial.print("CO2: "); Serial.print(co2); Serial.println(" ppm");
  Serial.print("PM2.5: "); Serial.print(pmsData.PM_AE_UG_2_5); Serial.println(" ug/m3");
  Serial.print("NO2: "); Serial.print(no2Value); Serial.println(" (raw)");
  Serial.print("NH3: "); Serial.print(nh3Value); Serial.println(" (raw)");
  Serial.print("CO: "); Serial.print(coValue); Serial.println(" (raw)");
  Serial.println("=============================");
  
  delay(5000); // Wait 5 seconds between readings
}
