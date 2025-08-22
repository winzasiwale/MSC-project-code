import requests
import json
import logging
import sqlite3
import time
import random
from datetime import datetime
from pathlib import Path
import serial
import mysql.connector


class MineMonitoringSystem:
    def __init__(self):
        self.api_url = "http://localhost/mine_monitoring/api/sensor_data.php"  # Fixed path
        self.api_key = "your-secure-api-key-here"  # Must match PHP config
        self.fallback_db = str(Path.home() / "mine_data_fallback.db")
        self.max_retries = 3
        self.retry_delay = 5
        self.serial_port = 'COM3'
        self.baud_rate = 9600

        self.setup_logging()
        self.init_fallback_db()
        self.init_mysql()
        self.last_sent_time = 0
        self.buffered_data = None

    def setup_logging(self):
        logging.basicConfig(
            level=logging.INFO,
            format='%(asctime)s - %(levelname)s - %(message)s',
            handlers=[
                logging.FileHandler('mine_monitoring.log', encoding='utf-8'),
                logging.StreamHandler()
            ]
        )
        self.logger = logging.getLogger('MineMonitor')

    def init_fallback_db(self):
        try:
            self.fallback_conn = sqlite3.connect(self.fallback_db)
            cursor = self.fallback_conn.cursor()
            cursor.execute('''
                CREATE TABLE IF NOT EXISTS sensor_data (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    reading_time DATETIME NOT NULL,
                    location_id INTEGER NOT NULL,
                    temperature REAL,
                    humidity REAL,
                    co2_ppm REAL,
                    pm1_ugm3 REAL,
                    pm25_ugm3 REAL,
                    pm10_ugm3 REAL,
                    no2_ppm REAL,
                    nh3_ppm REAL,
                    co_ppm REAL,
                    methane_percent REAL,
                    oxygen_percent REAL,
                    h2s_ppm REAL,
                    airflow_ms REAL,
                    uploaded BOOLEAN DEFAULT 0,
                    upload_attempts INTEGER DEFAULT 0
                )
            ''')
            self.fallback_conn.commit()
            self.logger.info("Fallback database initialized")
        except Exception as e:
            self.logger.error(f"Failed to initialize fallback DB: {e}")
            raise

    def init_mysql(self):
        try:
            self.mysql_conn = mysql.connector.connect(
                host="localhost",
                user="root",
                password="",
                database="underground_mine_monitoring1"
            )
            self.mysql_cursor = self.mysql_conn.cursor(dictionary=True)
            self.logger.info("Connected to MySQL for location lookup")
        except mysql.connector.Error as err:
            self.logger.error(f"MySQL connection failed: {err}")
            self.mysql_conn = None

    def get_or_create_location_id(self, name):
        try:
            self.mysql_cursor.execute("SELECT location_id FROM mine_locations WHERE section_name = %s", (name,))
            result = self.mysql_cursor.fetchone()
            if result:
                return result['location_id']
            else:
                now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
                self.mysql_cursor.execute('''
                    INSERT INTO mine_locations (
                        mine_name, level_number, section_name, coordinates, depth, ventilation_zone, is_active, created_at, updated_at
                    ) VALUES (
                        %s, %s, %s, %s, %s, %s, %s, %s, %s
                    )
                ''', (
                    "Mopani Copper Mine", 10, name, None, 400.0, "Vent-Zone-X", 1, now, now
                ))
                self.mysql_conn.commit()
                return self.mysql_cursor.lastrowid
        except Exception as e:
            self.logger.error(f"Location ID fetch/insert failed: {e}")
            return 1  # fallback default

    def send_to_api(self, data, is_retry=False):
        self.logger.info(f"Sending data to API: {json.dumps(data)}")
        for attempt in range(self.max_retries):
            try:
                response = requests.post(
                    self.api_url,
                    json=data,
                    headers={'Content-Type': 'application/json'},
                    timeout=10
                )
                if response.status_code == 200:
                    result = response.json()
                    if result.get('success'):
                        self.logger.info(
                            f"Data sent successfully. Alerts generated: {result.get('alerts_generated', 0)}")
                        return True
                    else:
                        self.logger.error(f"API reported failure: {result.get('message')}")
                else:
                    self.logger.error(f"API error {response.status_code}: {response.text}")
            except requests.exceptions.RequestException as e:
                self.logger.error(f"Connection attempt {attempt + 1} failed: {e}")
            if attempt < self.max_retries - 1:
                time.sleep(self.retry_delay)
        return False

    def save_to_mysql(self, data):
        try:
            cursor = self.mysql_conn.cursor()
            cursor.execute('''
                INSERT INTO sensor_data (
                    reading_time, location_id, temperature, humidity, co2_ppm,
                    pm1_ugm3, pm25_ugm3, pm10_ugm3, no2_ppm, nh3_ppm, co_ppm,
                    methane_percent, oxygen_percent, h2s_ppm, airflow_ms
                ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            ''', (
                data.get('reading_time', datetime.now().isoformat()),
                data['location_id'],
                data['temperature'],
                data['humidity'],
                data.get('co2_ppm'),
                data.get('pm1_ugm3'),
                data.get('pm25_ugm3'),
                data.get('pm10_ugm3'),
                data.get('no2_ppm'),
                data.get('nh3_ppm'),
                data.get('co_ppm'),
                data.get('methane_percent'),
                data.get('oxygen_percent'),
                data.get('h2s_ppm'),
                data.get('airflow_ms')
            ))
            self.mysql_conn.commit()
            self.logger.info("Data saved to MySQL database")
            return True
        except Exception as e:
            self.logger.error(f"Failed to save to MySQL: {e}")
            return False

    def save_to_fallback(self, data):
        try:
            cursor = self.fallback_conn.cursor()
            cursor.execute('''
                INSERT INTO sensor_data (
                    reading_time, location_id, temperature, humidity, co2_ppm,
                    pm1_ugm3, pm25_ugm3, pm10_ugm3, no2_ppm, nh3_ppm, co_ppm,
                    methane_percent, oxygen_percent, h2s_ppm, airflow_ms
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ''', (
                data.get('reading_time', datetime.now().isoformat()),
                data['location_id'],
                data['temperature'],
                data['humidity'],
                data.get('co2_ppm'),
                data.get('pm1_ugm3'),
                data.get('pm25_ugm3'),
                data.get('pm10_ugm3'),
                data.get('no2_ppm'),
                data.get('nh3_ppm'),
                data.get('co_ppm'),
                data.get('methane_percent'),
                data.get('oxygen_percent'),
                data.get('h2s_ppm'),
                data.get('airflow_ms')
            ))
            self.fallback_conn.commit()
            self.logger.info("Data saved to fallback storage")
            return True
        except Exception as e:
            self.logger.error(f"Failed to save to fallback: {e}")
            return False

    def estimate_missing_values(self, data):
        if data.get('oxygen_percent') is None:
            data['oxygen_percent'] = round(20.5 + random.uniform(-0.3, 0.3), 1)
        if data.get('methane_percent') is None:
            data['methane_percent'] = round(0.1 + 0.7 * random.random(), 2)
        if data.get('airflow_ms') is None:
            data['airflow_ms'] = round(0.3 + 0.5 * random.random(), 1)
        return data

    def insert_alert(self, location_id, type_id, condition_id, severity, threshold_value, measured_value):
        now = datetime.now()
        try:
            sql_alert = '''
                INSERT INTO alerts (location_id, type_id, condition_id, severity, threshold_value, measured_value, start_time, is_active)
                VALUES (%s, %s, %s, %s, %s, %s, %s, 1)
            '''
            self.mysql_cursor.execute(sql_alert, (
                location_id, type_id, condition_id, severity, threshold_value, measured_value, now
            ))
            self.mysql_conn.commit()
            alert_id = self.mysql_cursor.lastrowid
            self.logger.info(f"Inserted alert id {alert_id}")
            return alert_id
        except Exception as e:
            self.logger.error(f"Failed to insert alert: {e}")
            return None

    def insert_alert_response(self, alert_id, response_type, responding_crew, notes):
        try:
            sql_response = '''
                INSERT INTO alert_responses (alert_id, response_type, response_time, responding_crew, notes)
                VALUES (%s, %s, NOW(), %s, %s)
            '''
            self.mysql_cursor.execute(sql_response, (alert_id, response_type, responding_crew, notes))
            self.mysql_conn.commit()
            self.logger.info(f"Inserted alert response for alert_id {alert_id}")
        except Exception as e:
            self.logger.error(f"Failed to insert alert response: {e}")

    def check_for_danger_alerts(self, sensor_data):
        # Example conditions for danger alerts - customize thresholds as needed
        danger_conditions = []

        # High CO2
        if sensor_data.get('co2_ppm', 0) > 1000:
            danger_conditions.append({
                'type_id': 1, 'condition_id': 1,
                'severity': 8, 'threshold': 1000,
                'measured': sensor_data['co2_ppm'],
                'desc': 'High CO2 levels'
            })

        # Low oxygen
        if sensor_data.get('oxygen_percent', 21) < 19.5:
            danger_conditions.append({
                'type_id': 2, 'condition_id': 2,
                'severity': 9, 'threshold': 19.5,
                'measured': sensor_data['oxygen_percent'],
                'desc': 'Low oxygen levels'
            })

        # High H2S
        if sensor_data.get('h2s_ppm', 0) > 10:
            danger_conditions.append({
                'type_id': 3, 'condition_id': 3,
                'severity': 10, 'threshold': 10,
                'measured': sensor_data['h2s_ppm'],
                'desc': 'High H2S levels'
            })

        # If any danger detected, insert alerts and responses
        for cond in danger_conditions:
            alert_id = self.insert_alert(
                location_id=sensor_data['location_id'],
                type_id=cond['type_id'],
                condition_id=cond['condition_id'],
                severity=cond['severity'],
                threshold_value=cond['threshold'],
                measured_value=cond['measured']
            )
            if alert_id:
                self.insert_alert_response(
                    alert_id,
                    response_type='Automatic',
                    responding_crew='System',
                    notes=cond['desc'] + " detected automatically"
                )

    def process_sensor_data(self, sensor_data):
        required_fields = ['location_id', 'temperature', 'humidity']
        if not all(field in sensor_data for field in required_fields):
            self.logger.error("Missing required fields in sensor data")
            return False
        if 'reading_time' not in sensor_data:
            sensor_data['reading_time'] = datetime.now().isoformat()
        sensor_data = self.estimate_missing_values(sensor_data)

        # First try to save to MySQL
        if not self.save_to_mysql(sensor_data):
            # If MySQL fails, try API
            if not self.send_to_api(sensor_data):
                # If API fails, fall back to SQLite
                if not self.save_to_fallback(sensor_data):
                    self.logger.error("Failed to save data to all destinations")
                    return False

        # Check alerts after sending/saving sensor data
        self.check_for_danger_alerts(sensor_data)
        return True

    def is_number(self, s):
        try:
            float(s)
            return True
        except ValueError:
            return False

    def listen_com_port(self):
        try:
            with serial.Serial(self.serial_port, self.baud_rate, timeout=2) as ser:
                self.logger.info(f"Listening to serial port {self.serial_port} at {self.baud_rate} baud")
                while True:
                    line = ser.readline().decode(errors='ignore').strip()
                    if not line:
                        continue
                    self.logger.info(f"Received from COM port: {line}")
                    if line.startswith('DB_INSERT|'):
                        try:
                            parts = line.split('|')
                            location_name = parts[1].strip()
                            values = parts[2:]
                            while values and not self.is_number(values[-1]):
                                values.pop()
                            location_id = self.get_or_create_location_id(location_name)
                            sensor_data = {
                                'location_id': location_id,
                                'reading_time': datetime.now().isoformat(),
                                'temperature': float(values[0]),
                                'humidity': float(values[1]),
                                'co2_ppm': float(values[2]),
                                'pm1_ugm3': float(values[3]),
                                'pm25_ugm3': float(values[4]),
                                'pm10_ugm3': float(values[5]),
                                'no2_ppm': float(values[6]),
                                'nh3_ppm': float(values[7]),
                                'co_ppm': float(values[8]),
                                'methane_percent': float(values[9]) if len(values) > 9 else None,
                                'oxygen_percent': float(values[10]) if len(values) > 10 else None,
                                'h2s_ppm': float(values[11]) if len(values) > 11 else None,
                                'airflow_ms': float(values[12]) if len(values) > 12 else None,
                            }
                            self.buffered_data = sensor_data
                            self.process_sensor_data(sensor_data)
                        except Exception as e:
                            self.logger.error(f"Failed to parse sensor line: {e}")
        except Exception as e:
            self.logger.error(f"Fatal error: {e}")
        finally:
            if hasattr(self, 'fallback_conn'):
                self.fallback_conn.close()
            if hasattr(self, 'mysql_conn'):
                self.mysql_conn.close()
            self.logger.info("System shutdown complete")

    def run(self):
        self.logger.info("Starting Mine Monitoring System")
        self.listen_com_port()


if __name__ == "__main__":
    monitor = MineMonitoringSystem()
    monitor.run()