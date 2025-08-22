<?php
// Database configuration
$servername = "localhost";
$username = "root";
$password = ""; // Your MySQL password
$dbname = "environmentalmonitoring";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Function to fetch latest sensor data
function getLatestSensorData($conn) {
    $data = [];
    
    // Get the latest reading for each location
    $sql = "SELECT sr.*, l.location_name 
            FROM sensorreadings sr
            JOIN location l ON sr.location_id = l.location_id
            WHERE sr.timestamp = (SELECT MAX(timestamp) FROM sensorreadings WHERE location_id = sr.location_id)";
    
    $result = $conn->query($sql);
    
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $data[$row['location_id']] = $row;
        }
    }
    
    return $data;
}

// Function to fetch alerts
function getAlerts($conn) {
    $alerts = [];
    
    $sql = "SELECT a.*, sr.timestamp, l.location_name 
            FROM alert a
            JOIN sensorreadings sr ON a.reading_id = sr.reading_id
            JOIN location l ON sr.location_id = l.location_id
            ORDER BY sr.timestamp DESC
            LIMIT 3";
    
    $result = $conn->query($sql);
    
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $alerts[] = $row;
        }
    }
    
    return $alerts;
}

// Get data from database
$sensorData = getLatestSensorData($conn);
$alerts = getAlerts($conn);

// If this is an AJAX request, return JSON data
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'sensorData' => $sensorData,
        'alerts' => $alerts
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>3D Mine Ventilation & Haulage Analytics</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <style>
        /* Your existing CSS styles here */
        :root {
            --primary-dark: #0f172a;
            /* ... rest of your CSS ... */
        }
    </style>
</head>
<body>
    <!-- LEVEL NAVIGATION -->
    <div class="level-nav">
        <?php
        // Generate level navigation based on locations in database
        $sql = "SELECT * FROM location ORDER BY location_name";
        $result = $conn->query($sql);
        
        if ($result->num_rows > 0) {
            $first = true;
            while($row = $result->fetch_assoc()) {
                $active = $first ? 'active' : '';
                echo '<a href="#'.$row['location_id'].'" class="level-link '.$active.'" data-location-id="'.$row['location_id'].'">'.$row['location_name'].'</a>';
                $first = false;
            }
        }
        ?>
    </div>
    
    <!-- 3D VISUALIZATION CONTAINER -->
    <div class="visualization-container">
        <div class="visualization-panel">
            <h3>Mine Level Visualization</h3>
            <canvas id="mine3d-canvas" class="mine3d-canvas"></canvas>
            <div class="mine-level-info">
                <h2 id="current-location-name">Loading...</h2>
                <p id="current-location-status">Current ventilation status: Loading</p>
            </div>
        </div>
        
        <div class="visualization-panel">
            <h3>Haulage System Monitoring</h3>
            <canvas id="haulage3d-canvas" class="haulage3d-canvas"></canvas>
            <div class="mine-level-info" style="left: auto; right: 20px;">
                <h2>Haulage Tunnel</h2>
                <p id="haulage-status">Gas: Loading | Dust: Loading</p>
            </div>
        </div>
    </div>
    
    <div class="dashboard-container">
        <div class="dashboard-header">
            <h1>Mine Ventilation & Haulage Analytics</h1>
            <div class="status-indicators">
                <div class="status-indicator">
                    <div class="indicator green"></div>
                    <span>Normal</span>
                </div>
                <div class="status-indicator">
                    <div class="indicator yellow"></div>
                    <span>Warning</span>
                </div>
                <div class="status-indicator">
                    <div class="indicator red"></div>
                    <span>Critical</span>
                </div>
            </div>
        </div>
        
        <!-- GAUGES SECTION -->
        <div class="gauges-container">
            <div class="gauge-card">
                <div class="gauge-title">Temperature</div>
                <canvas id="tempGauge"></canvas>
                <div class="gauge-value" id="temp-value">Loading...</div>
                <div class="gauge-status status-good" id="temp-status">Loading</div>
            </div>
            
            <div class="gauge-card">
                <div class="gauge-title">Gas Concentration</div>
                <canvas id="gasGauge"></canvas>
                <div class="gauge-value" id="gas-value">Loading...</div>
                <div class="gauge-status status-good" id="gas-status">Loading</div>
            </div>
            
            <div class="gauge-card">
                <div class="gauge-title">Air Quality</div>
                <canvas id="airGauge"></canvas>
                <div class="gauge-value" id="air-value">Loading...</div>
                <div class="gauge-status status-good" id="air-status">Loading</div>
            </div>
            
            <div class="gauge-card">
                <div class="gauge-title">Dust Levels</div>
                <canvas id="dustGauge"></canvas>
                <div class="gauge-value" id="dust-value">Loading...</div>
                <div class="gauge-status status-good" id="dust-status">Loading</div>
            </div>
        </div>
        
        <!-- TRENDS CONTAINER -->
        <div class="trends-vertical">
            <div class="trend-header">
                <h2 class="trend-title">Environmental Trends</h2>
                <div class="time-period-selector">
                    <button class="time-period-btn active" onclick="changeTrendPeriod('day')">24 Hours</button>
                    <button class="time-period-btn" onclick="changeTrendPeriod('week')">7 Days</button>
                    <button class="time-period-btn" onclick="changeTrendPeriod('month')">30 Days</button>
                </div>
            </div>
            
            <!-- Temperature Trend -->
            <div class="chart-container">
                <canvas id="tempTrend"></canvas>
            </div>
            
            <!-- Gas Trend -->
            <div class="chart-container">
                <canvas id="gasTrend"></canvas>
            </div>
            
            <!-- Dust Trend -->
            <div class="chart-container">
                <canvas id="dustTrend"></canvas>
            </div>
        </div>
        
        <!-- ALERTS SECTION -->
        <div class="alerts-section">
            <h2 class="trend-title">Recent Alerts</h2>
            <div id="alerts-container">
                <?php foreach ($alerts as $alert): ?>
                    <div class="alert-item alert-<?= $alert['severity'] === 'danger' ? 'danger' : 'warning' ?>">
                        <div>
                            <div class="alert-message"><?= 
                                ucfirst($alert['alert_type']) . ' alert at ' . $alert['location_name'] . 
                                ' (Value: ' . $alert['value'] . ')' 
                            ?></div>
                            <div class="alert-time"><?= date('M j, H:i', strtotime($alert['timestamp'])) ?></div>
                        </div>
                        <button class="alert-btn alert-btn-<?= $alert['severity'] === 'danger' ? 'danger' : 'warning' ?>">
                            <?= $alert['severity'] === 'danger' ? 'Emergency' : 'Review' ?>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <script>
        // Current location data
        let currentLocationId = null;
        let sensorData = <?= json_encode($sensorData) ?>;
        let alerts = <?= json_encode($alerts) ?>;
        
        // ===== DATA FETCHING =====
        function fetchData() {
            fetch('?ajax=1')
                .then(response => response.json())
                .then(data => {
                    sensorData = data.sensorData;
                    alerts = data.alerts;
                    
                    // Update UI if we have a selected location
                    if (currentLocationId && sensorData[currentLocationId]) {
                        updateDashboard(sensorData[currentLocationId]);
                    }
                    
                    // Update alerts
                    updateAlerts();
                })
                .catch(error => console.error('Error fetching data:', error));
        }
        
        // Update dashboard with sensor data
        function updateDashboard(data) {
            // Update location info
            document.getElementById('current-location-name').textContent = data.location_name;
            document.getElementById('current-location-status').textContent = 
                `Current ventilation status: ${getVentilationStatus(data)}`;
            
            // Update gauge values
            document.getElementById('temp-value').textContent = data.temperature ? data.temperature.toFixed(1) + '°C' : 'N/A';
            document.getElementById('gas-value').textContent = data.co2 ? data.co2 + 'ppm' : 'N/A';
            document.getElementById('air-value').textContent = calculateAirQuality(data) + '%';
            document.getElementById('dust-value').textContent = data.pm25 ? data.pm25.toFixed(1) + ' mg/m³' : 'N/A';
            
            // Update gauge statuses
            updateGaugeStatus('temp', data.temperature, [20, 30], [15, 35]);
            updateGaugeStatus('gas', data.co2, [0, 1000], [1000, 2000]);
            updateGaugeStatus('air', calculateAirQuality(data), [80, 100], [60, 80]);
            updateGaugeStatus('dust', data.pm25, [0, 1], [1, 2]);
            
            // Update haulage info
            document.getElementById('haulage-status').textContent = 
                `Gas: ${data.co2 ? (data.co2/1000).toFixed(1) + '%' : 'N/A'} | Dust: ${data.pm25 ? data.pm25.toFixed(1) + ' mg/m³' : 'N/A'}`;
        }
        
        // Update gauge status indicators
        function updateGaugeStatus(type, value, warningRange, dangerRange) {
            if (value === null || value === undefined) {
                document.getElementById(`${type}-status`).textContent = 'N/A';
                document.getElementById(`${type}-status`).className = 'gauge-status status-warning';
                return;
            }
            
            const element = document.getElementById(`${type}-status`);
            
            if (dangerRange && (value < dangerRange[0] || value > dangerRange[1])) {
                element.textContent = 'Critical';
                element.className = 'gauge-status status-danger';
            } else if (warningRange && (value < warningRange[0] || value > warningRange[1])) {
                element.textContent = 'Warning';
                element.className = 'gauge-status status-warning';
            } else {
                element.textContent = 'Optimal';
                element.className = 'gauge-status status-good';
            }
        }
        
        // Update alerts display
        function updateAlerts() {
            const container = document.getElementById('alerts-container');
            container.innerHTML = '';
            
            alerts.forEach(alert => {
                const alertElement = document.createElement('div');
                alertElement.className = `alert-item alert-${alert.severity === 'danger' ? 'danger' : 'warning'}`;
                alertElement.innerHTML = `
                    <div>
                        <div class="alert-message">${ucfirst(alert.alert_type)} alert at ${alert.location_name} (Value: ${alert.value})</div>
                        <div class="alert-time">${formatTime(alert.timestamp)}</div>
                    </div>
                    <button class="alert-btn alert-btn-${alert.severity === 'danger' ? 'danger' : 'warning'}">
                        ${alert.severity === 'danger' ? 'Emergency' : 'Review'}
                    </button>
                `;
                container.appendChild(alertElement);
            });
        }
        
        // Helper functions
        function getVentilationStatus(data) {
            // Simple logic to determine ventilation status
            if (data.co2 > 1500 || data.pm25 > 2 || data.temperature > 35) {
                return 'Critical';
            } else if (data.co2 > 1000 || data.pm25 > 1 || data.temperature > 30) {
                return 'Warning';
            }
            return 'Optimal';
        }
        
        function calculateAirQuality(data) {
            // Simple air quality calculation (0-100%)
            let score = 100;
            
            if (data.co2) score -= Math.min(data.co2 / 20, 30); // Max 30% deduction
            if (data.pm25) score -= Math.min(data.pm25 * 10, 30); // Max 30% deduction
            if (data.temperature && data.temperature > 25) {
                score -= Math.min((data.temperature - 25) * 2, 20); // Max 20% deduction
            }
            
            return Math.max(0, Math.round(score));
        }
        
        function ucfirst(str) {
            return str.charAt(0).toUpperCase() + str.slice(1);
        }
        
        function formatTime(timestamp) {
            const date = new Date(timestamp);
            return date.toLocaleString();
        }
        
        // ===== INITIALIZATION =====
        document.addEventListener('DOMContentLoaded', () => {
            // Initialize 3D visualizations
            init3DMine();
            init3DHaulage();
            
            // Initialize gauges
            createGauge('tempGauge', 75, '#10b981');
            createGauge('gasGauge', 85, '#10b981');
            createGauge('airGauge', 90, '#10b981');
            createGauge('dustGauge', 45, '#f59e0b');
            
            // Initialize charts
            const initialData = generateData('day');
            const tempCtx = document.getElementById('tempTrend').getContext('2d');
            const gasCtx = document.getElementById('gasTrend').getContext('2d');
            const dustCtx = document.getElementById('dustTrend').getContext('2d');
            
            window.tempChart = createTrendChart(tempCtx, { labels: initialData.labels, tempData: initialData.tempData }, 'Temperature (°C)', '#ef4444');
            window.gasChart = createTrendChart(gasCtx, { labels: initialData.labels, gasData: initialData.gasData }, 'Gas (%)', '#3b82f6');
            window.dustChart = createTrendChart(dustCtx, { labels: initialData.labels, dustData: initialData.dustData }, 'Dust (mg/m³)', '#f59e0b');
            
            // Set first location as active
            const firstLocationLink = document.querySelector('.level-link');
            if (firstLocationLink) {
                currentLocationId = firstLocationLink.dataset.locationId;
                if (sensorData[currentLocationId]) {
                    updateDashboard(sensorData[currentLocationId]);
                }
            }
            
            // Set up location click handlers
            document.querySelectorAll('.level-link').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    // Update active link
                    document.querySelectorAll('.level-link').forEach(l => l.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Update current location
                    currentLocationId = this.dataset.locationId;
                    if (sensorData[currentLocationId]) {
                        updateDashboard(sensorData[currentLocationId]);
                    }
                });
            });
            
            // Set up auto-refresh every 5 seconds
            setInterval(fetchData, 5000);
        });
        
        // ===== YOUR EXISTING JAVASCRIPT FUNCTIONS =====
        // (Keep all your existing functions like init3DMine, init3DHaulage, createGauge, etc.)
        // ... rest of your JavaScript code ...
    </script>
</body>
</html>
<?php
// Close database connection
$conn->close();
?>