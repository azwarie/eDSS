<?php
// --- dashboard.php ---

// Set the correct timezone at the very top
date_default_timezone_set('Asia/Kuala_Lumpur'); // <--- IMPORTANT: Set your correct timezone

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include the SINGLE database connection ($conn to azwarie_dss)
include('connection.php');

// Check if the single connection succeeded
if (!$conn) {
    die("Database connection failed: Cannot connect to azwarie_dss.");
}

// Initialize variables for safety
$totalBedData = ['available' => 0, 'occupied' => 0, 'maintenance' => 0];
$bedData = [];
$trendsData = [];
$pastTrendsData = [];
$totalBedsCount = 'Error';
$activeAdmissionsCount = 'Error';
$dischargedPatientsCount = 'Error';
$query_error = null; // To store potential query errors

// --- Total Bed Utilization Query ---
$totalBedUtilizationQuery = "
  SELECT
    b.BedStatus, COUNT(*) as Count
  FROM BED b
  LEFT JOIN inventory i ON b.inventoryID = i.inventoryID
  WHERE i.categoryID = 'C006'
  GROUP BY b.BedStatus";
$totalBedUtilizationResult = $conn->query($totalBedUtilizationQuery);

if ($totalBedUtilizationResult) {
    while ($row = $totalBedUtilizationResult->fetch_assoc()) {
        $status = strtolower($row['BedStatus']);
        if (array_key_exists($status, $totalBedData)) {
             $totalBedData[$status] = (int)$row['Count'];
        }
    }
    $totalBedUtilizationResult->free();
} else {
     $query_error .= " Error fetching total bed utilization: " . $conn->error;
     error_log("Query Error (Total Bed Util): " . $conn->error);
}


// --- Bed Utilization by Type Query ---
$bedTypeUtilizationQuery = "
  SELECT
    b.BedStatus, COUNT(*) as Count, i.name as BedType
  FROM BED b
  LEFT JOIN inventory i ON b.inventoryID = i.inventoryID
  WHERE i.categoryID = 'C006'
  GROUP BY b.BedStatus, i.name";
$bedTypeUtilizationResult = $conn->query($bedTypeUtilizationQuery);

if ($bedTypeUtilizationResult) {
    while ($row = $bedTypeUtilizationResult->fetch_assoc()) {
        $bedType = $row['BedType'];
        $status = strtolower($row['BedStatus']);
        if (!isset($bedData[$bedType])) {
            $bedData[$bedType] = ['available' => 0, 'occupied' => 0, 'maintenance' => 0];
        }
        if (array_key_exists($status, $bedData[$bedType])) {
             $bedData[$bedType][$status] = (int)$row['Count'];
        }
    }
    $bedTypeUtilizationResult->free();
} else {
    $query_error .= " Error fetching bed type utilization: " . $conn->error;
    error_log("Query Error (Bed Type Util): " . $conn->error);
}
$bedDataJson = json_encode($bedData);
$totalBedDataJson = json_encode($totalBedData);


// --- Admission Trends Query ---
$admissionTrendsQuery = "
  SELECT
    DATE(AdmissionDateTime) as Date, COUNT(*) as Admissions
  FROM ADMISSION
  WHERE AdmissionDateTime >= CURDATE() - INTERVAL 7 DAY
  GROUP BY DATE(AdmissionDateTime)
  ORDER BY Date ASC";
$admissionTrendsResult = $conn->query($admissionTrendsQuery);

if ($admissionTrendsResult) {
    while ($row = $admissionTrendsResult->fetch_assoc()) {
        $trendsData[$row['Date']] = (int)$row['Admissions'];
    }
    $admissionTrendsResult->free();
} else {
     $query_error .= " Error fetching admission trends: " . $conn->error;
     error_log("Query Error (Admission Trends): " . $conn->error);
}
$trendsDataJson = json_encode($trendsData);


// --- Past Admission (Discharge) Trends Query ---
$pastAdmissionTrendsQuery = "
  SELECT
    DATE(DischargeDateTime) as Date, COUNT(*) as Discharges
  FROM PAST_ADMISSION
  WHERE DischargeDateTime >= CURDATE() - INTERVAL 7 DAY
  GROUP BY DATE(DischargeDateTime)
  ORDER BY Date ASC";
$pastAdmissionTrendsResult = $conn->query($pastAdmissionTrendsQuery);

if ($pastAdmissionTrendsResult) {
    while ($row = $pastAdmissionTrendsResult->fetch_assoc()) {
        $pastTrendsData[$row['Date']] = (int)$row['Discharges'];
    }
    $pastAdmissionTrendsResult->free();
} else {
    $query_error .= " Error fetching discharge trends: " . $conn->error;
    error_log("Query Error (Discharge Trends): " . $conn->error);
}
$pastTrendsDataJson = json_encode($pastTrendsData);


// --- Card Statistics Queries ---
// Total Beds (using category filter for accuracy)
$totalBedsResult = $conn->query("SELECT COUNT(b.BedID) as total FROM BED b LEFT JOIN inventory i ON b.InventoryID = i.inventoryID WHERE i.CategoryID = 'C006'");
if ($totalBedsResult && $row = $totalBedsResult->fetch_assoc()) {
    $totalBedsCount = (int)$row['total'];
    $totalBedsResult->free();
} else { $query_error .= " Error fetching total beds count: " . $conn->error; error_log("Query Error (Total Beds): " . $conn->error); }

// Active Admissions
$activeAdmissionsResult = $conn->query("SELECT COUNT(*) as active FROM ADMISSION WHERE DischargeDateTime IS NULL");
if ($activeAdmissionsResult && $row = $activeAdmissionsResult->fetch_assoc()) {
    $activeAdmissionsCount = (int)$row['active'];
    $activeAdmissionsResult->free();
} else { $query_error .= " Error fetching active admissions count: " . $conn->error; error_log("Query Error (Active Admissions): " . $conn->error); }

// Discharged Patients (using PAST_ADMISSION)
$dischargedPatientsResult = $conn->query("SELECT COUNT(*) as discharged FROM PAST_ADMISSION WHERE DischargeDateTime IS NOT NULL");
if ($dischargedPatientsResult && $row = $dischargedPatientsResult->fetch_assoc()) {
    $dischargedPatientsCount = (int)$row['discharged'];
    $dischargedPatientsResult->free();
} else { $query_error .= " Error fetching discharged count: " . $conn->error; error_log("Query Error (Discharged): " . $conn->error); }


// --- Staff ID and Link Function ---
$staffID = null;
if (isset($_GET['staffid'])) {
    $staffID = $_GET['staffid']; // Consider validating this ID format
}

$current_page = basename($_SERVER['PHP_SELF']);
function addStaffIdToLink($url, $staffId) {
    if ($staffId !== null) {
        $separator = (strpos($url, '?') === false) ? '?' : '&';
        return $url . $separator . 'staffid=' . urlencode($staffId);
    }
    return $url;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - WeCare Hospital</title> <!-- Updated Title -->
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
   <style>
        /* Styles from previous version with slight adjustments if needed */
         :root { --bs-primary-rgb: 13, 110, 253; }
         body { font-family: 'Montserrat', sans-serif; margin: 0; padding: 0; background-color: #f8f9fa; padding-top: 70px; }
        .navbar { background-color: white !important; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); position: fixed; width: 100%; top: 0; z-index: 1030; }
        .navbar-brand { font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 700; color: rgb(var(--bs-primary-rgb)) !important; }
        .navbar .nav-link { color: black !important; }
        .navbar .nav-link:hover { color: rgb(var(--bs-primary-rgb)) !important; }
        .navbar .nav-item.active .nav-link { color: rgb(var(--bs-primary-rgb)) !important; font-weight: bold !important; }
        .hero-section { background-color: rgb(var(--bs-primary-rgb)); color: white; padding: 50px 0; text-align: center; }
        .hero-section h1 { font-family: 'Montserrat', sans-serif; font-size: 2.5rem; font-weight: 700; color: white; text-transform: uppercase; }
        .hero-section p { font-size: 1.1rem; }
        .card { margin-top: 20px; box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08); border-radius: 8px; border: none; }
        .card-header { background-color: #e9ecef; border-bottom: 1px solid #dee2e6; font-weight: 600; padding: 0.85rem 1.25rem; font-size: 1.05rem; }
        .card-body { padding: 1.5rem; }
        .chart-container { position: relative; margin: auto; height: 300px; /* Fixed height for charts */ width: 100%; }
        .stat-card .card-text { font-size: 2rem; font-weight: bold; color: rgb(var(--bs-primary-rgb)); }
        .stat-card .card-title { font-size: 1rem; font-weight: 600; color: #6c757d; }
        .card.border-left-primary { border-left: 5px solid rgb(var(--bs-primary-rgb)) !important; }
        .card.border-left-success { border-left: 5px solid #198754 !important; } /* BS5 Success */
        .card.border-left-secondary { border-left: 5px solid #6c757d !important; }
        .card.border-left-info { border-left: 5px solid #0dcaf0 !important; } /* BS5 Info */
        .card.border-left-warning { border-left: 5px solid #ffc107 !important; }
        .card.border-left-danger { border-left: 5px solid #dc3545 !important; }
        .card.border-left-dark { border-left: 5px solid #212529 !important; } /* BS5 Dark */
        footer { background-color: rgb(var(--bs-primary-rgb)); color: white; padding: 20px 0; text-align: center; margin-top: 40px; }
        @media (max-width: 992px) { .chart-container { height: 280px; } }
        @media (max-width: 768px) { .card { margin-bottom: 20px; } .chart-container { height: 250px; } .hero-section h1 {font-size: 2rem;} }
        #simulationIframe { border: 1px solid #dee2e6; }

        /* Calendar Specific Styles */
        #calendar table { width: 100%; border-collapse: collapse; background-color: white; table-layout: fixed; /* Prevent content from stretching cells */ }
        #calendar th, #calendar td { padding: 8px; text-align: center; border: 1px solid #e0e0e0; vertical-align: top; height: 110px; overflow: hidden; /* Prevent overflow */ }
        #calendar th { background-color: #f8f9fa; font-weight: bold; font-size: 0.9rem; }
        #calendar td { cursor: default; /* No pointer needed? */ }
        #calendar td:hover { background-color: #f1f7ff; }
        .calendar-nav { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .calendar-nav h3 { margin: 0; font-size: 1.5rem; }
        .calendar-day-number { font-size: 0.9rem; margin-bottom: 4px; text-align: left; padding-left: 4px;}
        .admission-list { font-size: 0.75rem; margin-top: 3px; text-align: left; max-height: 65px; overflow-y: auto; /* Add scroll if too many items */ padding-left: 4px; }
        /* Individual item styling */
        .admission, .discharge-marker { display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 3px; padding: 2px 5px; border-radius: 4px; border: 1px solid transparent; }
        .admission { background-color: #cfe2ff; border-color: #b6d4fe; color: #0a58ca; } /* Lighter blue admission */
        .discharge-marker.actual-discharge { background-color: #f8d7da; border-color: #f5c2c7; color: #842029; } /* Lighter red actual discharge */
        .discharge-marker.predicted-discharge { background-color: #cff4fc; border-color: #b6effb; color: #087990; font-style: italic; } /* Lighter cyan predicted */
        .admission i, .discharge-marker i { font-size: 0.7em; vertical-align: middle;} /* Smaller icons */

        .calendar-today { border: 2px solid rgb(var(--bs-primary-rgb)) !important; background-color: #e7f1ff; }
        .calendar-day-empty { background-color: #f9f9f9; } /* Style empty cells */
        /* Overcrowding colors */
        .overcrowding-caution { background-color: #fff3cd !important; }
        .overcrowding-warning { background-color: #ffeeba !important; }
        .overcrowding-critical { background-color: #f8d7da !important; }
        .overcrowding-critical .calendar-day-number { font-weight: bold; color: #dc3545; } /* Highlight day number too */

        /* Tooltip Styles */
        [title] { position: relative; } /* Needed for potential JS tooltip libraries */

    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-light">
        <div class="container">
            <a class="navbar-brand" href="<?php echo addStaffIdToLink('index.php', $staffID); ?>">WeCare</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                   <li class="nav-item <?php echo ($current_page == 'index.php') ? 'active' : ''; ?>"><a class="nav-link" href="<?php echo addStaffIdToLink('index.php', $staffID); ?>">Home</a></li>
                   <li class="nav-item <?php echo ($current_page == 'bed_registration.php') ? 'active' : ''; ?>"><a class="nav-link" href="<?php echo addStaffIdToLink('bed_registration.php', $staffID); ?>">Bed Registration</a></li>
                   <li class="nav-item <?php echo ($current_page == 'patient_coordination.php') ? 'active' : ''; ?>"><a class="nav-link" href="<?php echo addStaffIdToLink('patient_coordination.php', $staffID); ?>">Patient Coordination</a></li>
                   <li class="nav-item <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>"><a class="nav-link" href="<?php echo addStaffIdToLink('dashboard.php', $staffID); ?>">Real-Time Dashboards</a></li>
                   <!-- Optional Logout Link -->
                   <li class="nav-item"><a class="nav-link" href="login.php?action=logout">Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <div class="hero-section">
        <h1>Hospital Analysis Dashboard</h1>
        <p>Real-Time Bed and Admission Overview</p>
    </div>

    <div class="container mt-4">

         <!-- Display Query Errors if any -->
         <?php if (!empty($query_error)): ?>
            <div class="alert alert-danger" role="alert">
                <h5 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Database Query Error!</h5>
                <p>There was an issue retrieving some data for the dashboard. Details logged.</p>
            </div>
        <?php endif; ?>


        <!-- Admission Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <div class="card border-left-primary stat-card h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                         <div>
                            <div class="card-title text-primary text-uppercase mb-1">Total Beds</div>
                            <div class="card-text"><?php echo $totalBedsCount; ?></div>
                        </div>
                        <i class="fas fa-procedures fa-3x text-gray-300"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card border-left-success stat-card h-100">
                     <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="card-title text-success text-uppercase mb-1">Active Admissions</div>
                            <div class="card-text"><?php echo $activeAdmissionsCount; ?></div>
                         </div>
                         <i class="fas fa-hospital-user fa-3x text-gray-300"></i>
                     </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card border-left-secondary stat-card h-100">
                     <div class="card-body d-flex justify-content-between align-items-center">
                         <div>
                            <div class="card-title text-secondary text-uppercase mb-1">Total Discharged</div>
                            <div class="card-text"><?php echo $dischargedPatientsCount; ?></div>
                         </div>
                         <i class="fas fa-user-check fa-3x text-gray-300"></i>
                     </div>
                </div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="row">
            <!-- Bed Utilization Chart Card -->
            <div class="col-lg-6 mb-4">
                <div class="card border-left-info h-100">
                    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-info"><i class="fas fa-chart-pie me-2"></i>Bed Utilization</h6>
                        <select id="bedTypeSelector" class="form-select form-select-sm" style="max-width: 180px;">
                            <option value="all" selected>All Bed Types</option>
                            <?php foreach ($bedData as $bedType => $statusData) : ?>
                                <option value="<?php echo htmlspecialchars($bedType); ?>"><?php echo htmlspecialchars($bedType); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="bedUtilizationChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Admission Trends Card -->
            <div class="col-lg-6 mb-4">
                 <div class="card border-left-success h-100">
                    <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-success"><i class="fas fa-chart-line me-2"></i>Admission Trends (Last 7 Days)</h6></div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="admissionTrendsChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

         <!-- Discharge Trends Card & Placeholder -->
        <div class="row">
             <div class="col-lg-6 mb-4">
                 <div class="card border-left-secondary h-100">
                    <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-secondary"><i class="fas fa-chart-line me-2"></i>Discharge Trends (Last 7 Days)</h6></div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="pastAdmissionTrendsChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
             <!-- Placeholder for another chart or info card -->
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-info-circle me-2"></i>Additional Metrics</h6></div>
                    <div class="card-body align-items-center justify-content-center d-flex text-muted">
                        <p>Placeholder for future metrics (e.g., Avg. LOS).</p>
                    </div>
                </div>
            </div>
        </div>


        <!-- Admission Calendar Card -->
        <div class="card border-left-warning mb-4">
             <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-warning"><i class="fas fa-calendar-alt me-2"></i>Admission & Discharge Calendar</h6></div>
            <div class="card-body">
                <div class="calendar-nav">
                     <button id="prevMonthBtn" class="btn btn-outline-secondary btn-sm"><i class="fas fa-chevron-left"></i> Prev Month</button>
                     <h3 id="calendarMonthYear" class="text-primary">Month Year</h3>
                     <button id="nextMonthBtn" class="btn btn-outline-secondary btn-sm">Next Month <i class="fas fa-chevron-right"></i></button>
                </div>
                <div id="calendar" class="table-responsive mt-3">
                    <!-- Calendar table will be generated here by JS -->
                    <div class="text-center p-5 text-muted">Loading Calendar... <i class="fas fa-spinner fa-spin"></i></div>
                </div>
                 <div class="mt-3 small d-flex flex-wrap justify-content-center gap-2">
                    <span class="badge bg-light text-dark border">Normal</span>
                    <span class="badge bg-warning text-dark border"><i class="fas fa-exclamation-circle me-1"></i>Caution (≥50%)</span>
                    <span class="badge bg-orange border" style="background-color: #fd7e14; color: white;"><i class="fas fa-exclamation-triangle me-1"></i>Warning (≥70%)</span>
                    <span class="badge bg-danger border"><i class="fas fa-skull-crossbones me-1"></i>Critical (≥90%)</span>
                 </div>
            </div>
        </div>

        <!-- Simulation Model Card -->
        <div class="card border-left-dark mb-4">
            <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                 <h6 class="m-0 font-weight-bold text-dark"><i class="fas fa-project-diagram me-2"></i>Simulation Model</h6>
                 <button id="toggleModel" class="btn btn-outline-primary btn-sm">Show Simulation</button>
            </div>
            <div class="card-body" id="simulationModelContainer" style="display: none;">
                <p>Interactive AnyLogic simulation model for operational analysis.</p>
                <div class="text-center ratio ratio-16x9"> <!-- Use ratio for responsive iframe -->
                    <iframe
                        id="simulationIframe"
                        src="https://cloud.anylogic.com/assets/embed?modelId=52cdd2fd-2015-495e-88c6-8a1fe950f09a"
                        style="border: 1px solid #ccc;"
                        allowfullscreen
                        title="Simulation Model">
                        Your browser does not support iframes.
                    </iframe>
                </div>
            </div>
        </div>

    </div><!-- /container -->

     <!-- Footer -->
    <footer>
        <p>© <?php echo date("Y"); ?> WeCare Hospital Management. All rights reserved.</p>
    </footer>

<script>
    // Wrap all chart generation and JS logic in DOMContentLoaded
    document.addEventListener('DOMContentLoaded', function () {

        // --- Bed Utilization Chart ---
        const bedDataAllTypes = <?php echo $totalBedDataJson; ?> || {}; // Default to empty object
        const bedDataPerType = <?php echo $bedDataJson; ?> || {}; // Default to empty object
        const bedCtx = document.getElementById('bedUtilizationChart')?.getContext('2d');
        let bedChartInstance = null;

         function renderBedChart(data) {
             if (!bedCtx) { return; } // Exit if canvas not found
             if (bedChartInstance) { bedChartInstance.destroy(); }

             const chartData = {
                labels: ['Available', 'Occupied', 'Maintenance'],
                datasets: [{
                    label: 'Bed Status',
                    data: [ data.available || 0, data.occupied || 0, data.maintenance || 0 ],
                    backgroundColor: ['#198754', '#ffc107', '#dc3545'], // Updated BS5 colors
                    borderColor: '#fff',
                    borderWidth: 2 // Slightly thicker border
                }]
             };
             // Check if all data points are zero
             const allZero = chartData.datasets[0].data.every(item => item === 0);

             if (allZero) {
                 // Optional: Display a message instead of an empty chart
                 bedCtx.clearRect(0, 0, bedCtx.canvas.width, bedCtx.canvas.height);
                 bedCtx.textAlign = 'center';
                 bedCtx.fillStyle = '#6c757d'; // Grey color
                 bedCtx.fillText('No bed data available for this selection.', bedCtx.canvas.width / 2, bedCtx.canvas.height / 2);
                 return; // Don't draw the chart
             }


             bedChartInstance = new Chart(bedCtx, {
                type: 'doughnut', // Changed to doughnut
                data: chartData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '60%', // Doughnut hole size
                    plugins: {
                        legend: { position: 'bottom', labels: { padding: 15 } }, // Legend at bottom
                        tooltip: {
                             callbacks: {
                                label: function(context) {
                                    let label = context.label || '';
                                    if (label) label += ': ';
                                    if (context.parsed !== null) label += context.parsed;
                                     const total = context.dataset.data.reduce((acc, value) => acc + value, 0);
                                     const percentage = total > 0 ? ((context.parsed / total) * 100).toFixed(1) + '%' : '0%';
                                     label += ` (${percentage})`;
                                    return label;
                                }
                            }
                        },
                        title: { display: false } // No need for separate title
                    }
                }
            });
         }

        // Initial render
        renderBedChart(bedDataAllTypes);

        // Update chart on selector change
        const bedSelector = document.getElementById('bedTypeSelector');
        if (bedSelector) {
             bedSelector.addEventListener('change', function() {
                 const selectedType = this.value;
                 const dataToShow = (selectedType === 'all' || !bedDataPerType[selectedType])
                                     ? bedDataAllTypes
                                     : bedDataPerType[selectedType];
                renderBedChart(dataToShow);
             });
        }


        // --- Admission Trends Chart ---
        const trendsData = <?php echo $trendsDataJson; ?> || {};
        const admissionTrendsCtx = document.getElementById('admissionTrendsChart')?.getContext('2d');
        if (admissionTrendsCtx && Object.keys(trendsData).length > 0) { // Check if data exists
             new Chart(admissionTrendsCtx, {
                type: 'line',
                data: {
                    labels: Object.keys(trendsData),
                    datasets: [{
                        label: 'Admissions',
                        data: Object.values(trendsData),
                        borderColor: '#198754', // BS5 Success Green
                        backgroundColor: 'rgba(25, 135, 84, 0.1)',
                        fill: true,
                        borderWidth: 2,
                        tension: 0.4 // Slightly more curve
                    }]
                },
                options: {
                     responsive: true, maintainAspectRatio: false,
                     scales: {
                          y: { beginAtZero: true, ticks: { stepSize: 1 } }, // Integer steps
                          x: { grid: { display: false } } // Hide vertical grid lines
                      },
                      plugins: { legend: { display: false } } // Hide legend if only one dataset
                 }
            });
        } else if(admissionTrendsCtx) { /* Optional: display message if no data */ }


        // --- Discharge Trends Chart ---
        const pastTrendsData = <?php echo $pastTrendsDataJson; ?> || {};
        const pastAdmissionTrendsCtx = document.getElementById('pastAdmissionTrendsChart')?.getContext('2d');
        if (pastAdmissionTrendsCtx && Object.keys(pastTrendsData).length > 0) {
             new Chart(pastAdmissionTrendsCtx, {
                type: 'line',
                data: {
                    labels: Object.keys(pastTrendsData),
                    datasets: [{
                        label: 'Discharges',
                        data: Object.values(pastTrendsData),
                        borderColor: '#6c757d', // BS5 Secondary Grey
                        backgroundColor: 'rgba(108, 117, 125, 0.1)',
                        fill: true,
                        borderWidth: 2,
                        tension: 0.4
                    }]
                },
                options: {
                     responsive: true, maintainAspectRatio: false,
                     scales: {
                        y: { beginAtZero: true, ticks: { stepSize: 1 } },
                        x: { grid: { display: false } }
                     },
                     plugins: { legend: { display: false } }
                 }
            });
        } else if (pastAdmissionTrendsCtx) { /* Optional: display message if no data */ }


        // --- Calendar Logic ---
        let currentMonthIndex = new Date().getMonth();
        let currentYear = new Date().getFullYear();
        // Removed calendarDataCache as fetch is called each time

        const occupancyCaution = 50;
        const occupancyWarning = 70;
        const occupancyCritical = 90;

        // --- REFINED: generateCalendar with corrected date comparison ---
        function generateCalendar(monthIndex, year, data) {
            // console.log("Generating Calendar for:", monthIndex + 1, year); // Keep for debug if needed

            // --- Data Validation ---
            if (!data || typeof data !== 'object') { console.error('...'); document.getElementById('calendar').innerHTML = '... Invalid data ...'; return; }
            const requiredKeys = ['totalBeds', 'admissionData', 'dischargeData', 'activeAdmissions', 'dailyChanges'];
            for (const key of requiredKeys) { if (!(key in data)) { console.error(`... missing ${key}`); document.getElementById('calendar').innerHTML = `... Incomplete data (missing ${key}) ...`; return; } }
            if (!Array.isArray(data.admissionData) || !Array.isArray(data.dischargeData) || typeof data.dailyChanges !== 'object') { console.error('...'); document.getElementById('calendar').innerHTML = '... Invalid data types ...'; return; }
            // --- End Validation ---

            const totalBeds = Number(data.totalBeds) || 0;
            const admissionData = data.admissionData;     // Active patients {date, patientName, ..., predictedDischargeDate, losDaysUsed}
            const actualDischargeData = data.dischargeData; // Actually discharged {date, patientName}
            const initialActiveAdmissions = Number(data.activeAdmissions);
            const dailyChanges = data.dailyChanges || {}; // Actual daily counts {'YYYY-MM-DD': {admissions, discharges}}

            const calendarContainer = document.getElementById('calendar');
            const monthYearHeader = document.getElementById('calendarMonthYear');
            const today = new Date(); // Gets local date/time
            const todayYear = today.getFullYear();
            const todayMonth = String(today.getMonth() + 1).padStart(2, '0'); // Month is 0-indexed
            const todayDay = String(today.getDate()).padStart(2, '0');
            const todayDateString = `${todayYear}-${todayMonth}-${todayDay}`; // Format as 'YYYY-MM-DD' in local time

            const firstDayOfMonth = new Date(year, monthIndex, 1);
            const lastDayOfMonth = new Date(year, monthIndex + 1, 0);
            const daysInMonth = lastDayOfMonth.getDate();
            const startingDayOfWeek = firstDayOfMonth.getDay();

            if(monthYearHeader) monthYearHeader.textContent = `${firstDayOfMonth.toLocaleString('default', { month: 'long' })} ${year}`;

            let html = "<table class='calendar table table-bordered table-sm'>";
            html += "<thead><tr>";
            const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            weekdays.forEach(day => html += `<th>${day}</th>`);
            html += "</tr></thead><tbody><tr>";

            for (let i = 0; i < startingDayOfWeek; i++) {
                html += "<td class='calendar-day-empty'></td>";
            }

            let predictedOccupancyCount = initialActiveAdmissions;

            for (let day = 1; day <= daysInMonth; day++) {
                // --- Generate CURRENT Date String Directly ---
                const currentYearStr = String(year);
                const currentMonthStr = String(monthIndex + 1).padStart(2, '0');
                const currentDayStr = String(day).padStart(2, '0');
                const currentDateStr = `${currentYearStr}-${currentMonthStr}-${currentDayStr}`;
                // --- End Generate CURRENT Date String ---

                // --- Occupancy Prediction (using previous day's ACTUAL changes) ---
                const prevDayDate = new Date(year, monthIndex, day - 1);
                const prevDayStr = prevDayDate.toISOString().split('T')[0];
                if (day > 1 && dailyChanges[prevDayStr]) {
                    predictedOccupancyCount += (dailyChanges[prevDayStr].admissions || 0);
                    predictedOccupancyCount -= (dailyChanges[prevDayStr].discharges || 0);
                }
                predictedOccupancyCount = Math.max(0, predictedOccupancyCount);
                // --- End Occupancy Prediction ---


                // --- Filter Events for THIS Day (Using the Correctly Generated currentDateStr) ---
                // Check 'date' property from PHP which should be the ADMISSION date 'YYYY-MM-DD'
                const admissionsToday = admissionData.filter(entry => entry.date === currentDateStr);

                // Check 'date' property from PHP for ACTUAL discharges
                const actualDischargesToday = actualDischargeData.filter(entry => entry.date === currentDateStr);

                // Check the DATE PART of 'predictedDischargeDate' from PHP
                const predictedDischargesToday = admissionData.filter(entry =>
                     entry.predictedDischargeDate &&
                     typeof entry.predictedDischargeDate === 'string' &&
                     entry.predictedDischargeDate.startsWith(currentDateStr)
                );
                // --- End Filtering ---


                // --- Cell Styling ---
                let occupancyPercent = (totalBeds > 0) ? (predictedOccupancyCount / totalBeds) * 100 : 0;
                occupancyPercent = Math.max(0, occupancyPercent);
                let cellClass = 'calendar-day';
                 if (occupancyPercent >= occupancyCritical) cellClass += ' overcrowding-critical';
                 else if (occupancyPercent >= occupancyWarning) cellClass += ' overcrowding-warning';
                 else if (occupancyPercent >= occupancyCaution) cellClass += ' overcrowding-caution';
                 if (currentDateStr === todayDateString) cellClass += ' calendar-today';
                // --- End Styling ---


                // --- Start Cell HTML ---
                const tooltipText = `Est. Occupancy (start of day): ${predictedOccupancyCount}/${totalBeds} (${occupancyPercent.toFixed(0)}%)`;
                html += `<td class='${cellClass}' title='${tooltipText}'>`;
                html += `<div class="calendar-day-number fw-bold">${day}</div>`;

                // --- Display ADMISSIONS ---
                if (admissionsToday.length > 0) {
                    html += "<div class='admission-list mt-1'>";
                    admissionsToday.forEach(entry => {
                         // console.log(`Placing Admission for ${entry.patientName} on ${currentDateStr} because entry.date is ${entry.date}`); // DEBUG
                         const admissionTooltip = `Admitted: ${entry.patientName}\nDiagnosis: ${entry.diagnosisName || 'N/A'}\nLOS Used: ${entry.losDaysUsed || 'N/A'} days`;
                         html += `<div class='admission' title="${admissionTooltip}">
                                    <i class="fas fa-arrow-alt-circle-right me-1"></i>${entry.patientName}
                                  </div>`;
                     });
                     html += "</div>";
                }

                // --- Display ACTUAL DISCHARGES ---
                 if (actualDischargesToday.length > 0) {
                     html += "<div class='admission-list mt-1'>";
                     actualDischargesToday.forEach(entry => {
                         const dischargeTooltip = `Discharged (Actual): ${entry.patientName}`;
                          html += `<div class='discharge-marker actual-discharge' title="${dischargeTooltip}">
                                     <i class="fas fa-arrow-alt-circle-left me-1"></i>${entry.patientName}
                                  </div>`;
                      });
                      html += "</div>";
                 }

                // --- Display PREDICTED DISCHARGES ---
                if (predictedDischargesToday.length > 0) {
                    html += "<div class='admission-list mt-1'>";
                    predictedDischargesToday.forEach(entry => {
                        const alreadyDischarged = actualDischargesToday.some(d => d.patientName === entry.patientName);
                        if (!alreadyDischarged) {
                             const predictedTooltip = `Predicted Discharge: ${entry.patientName}\nBased on ${entry.losDaysUsed || 'N/A'} days LOS for ${entry.diagnosisName || 'N/A'}`;
                             html += `<div class='discharge-marker predicted-discharge' title='${predictedTooltip}'>
                                         <i class="fas fa-hourglass-end me-1"></i>${entry.patientName} (Pred.)
                                      </div>`;
                        }
                    });
                    html += "</div>";
                }
                // --- End Event Display ---

                html += "</td>"; // Close cell

                // --- Row Management ---
                if ((startingDayOfWeek + day) % 7 === 0) { html += "</tr>"; if (day < daysInMonth) { html += "<tr>"; } }

            } // --- End FOR loop ---

            // --- Complete Last Row ---
            let remainingCells = (7 - ((startingDayOfWeek + daysInMonth) % 7)) % 7;
            if (remainingCells > 0 && remainingCells < 7) { for (let i = 0; i < remainingCells; i++) { html += "<td class='calendar-day-empty'></td>"; } html += "</tr>"; }
            else if (remainingCells === 0 && (startingDayOfWeek + daysInMonth) % 7 !== 0) { html += "</tr>"; }

            html += "</tbody></table>";
            calendarContainer.innerHTML = html; // Render
        } // --- End generateCalendar ---


        // --- Fetch and Render Calendar ---
        function fetchAndRenderCalendar(month, year) {
            document.getElementById('calendar').innerHTML = '<div class="text-center p-5 text-muted">Loading Calendar Data... <i class="fas fa-spinner fa-spin"></i></div>';
            // Construct URL for fetching data (pass month/year if needed by PHP)
            // const fetchUrl = `get_calendar_data.php?month=${month + 1}&year=${year}`; // Example
            const fetchUrl = 'get_calendar_data.php'; // Current version doesn't use params

            fetch(fetchUrl)
                 .then(response => {
                    if (!response.ok) throw new Error(`HTTP error ${response.status}: ${response.statusText}`);
                    return response.text(); // Get text first
                 })
                 .then(text => {
                     try {
                         const data = JSON.parse(text); // Then parse
                         // console.log("Data received from server:", data); // Optional: Check full data
                         if (data.success) {
                             generateCalendar(month, year, data);
                         } else {
                             console.error('API Error:', data.error);
                             document.getElementById('calendar').innerHTML = `<div class="alert alert-danger">Failed to load calendar data: ${data.error || 'Unknown error'}</div>`;
                         }
                     } catch (e) {
                          console.error("Failed to parse JSON:", e);
                          console.error("Received text:", text); // Log the text that failed to parse
                          throw new Error("Received invalid JSON response from server.");
                     }
                 })
                 .catch(error => {
                     console.error('Fetch Error:', error);
                     document.getElementById('calendar').innerHTML = `<div class="alert alert-danger">Could not fetch calendar data: ${error.message}.</div>`;
                 });
         }

        // Initial calendar render
        fetchAndRenderCalendar(currentMonthIndex, currentYear);

        // Calendar Navigation Buttons
        document.getElementById('nextMonthBtn')?.addEventListener('click', function () {
             currentMonthIndex++;
             if (currentMonthIndex > 11) { currentMonthIndex = 0; currentYear++; }
             fetchAndRenderCalendar(currentMonthIndex, currentYear);
        });
        document.getElementById('prevMonthBtn')?.addEventListener('click', function () {
             currentMonthIndex--;
             if (currentMonthIndex < 0) { currentMonthIndex = 11; currentYear--; }
             fetchAndRenderCalendar(currentMonthIndex, currentYear);
        });

        // --- Simulation Model Toggle ---
        const toggleBtn = document.getElementById('toggleModel');
        const modelContainer = document.getElementById('simulationModelContainer');
        if (toggleBtn && modelContainer) {
             toggleBtn.addEventListener('click', function () {
                const isHidden = modelContainer.style.display === 'none';
                modelContainer.style.display = isHidden ? 'block' : 'none';
                this.textContent = isHidden ? 'Hide Simulation' : 'Show Simulation';
                // Scroll to model if showing it? Optional.
                // if(isHidden) modelContainer.scrollIntoView({ behavior: 'smooth' });
             });
        }

    }); // End DOMContentLoaded
</script>

</body>
</html>
<?php
// Close the single database connection
if (isset($conn) && $conn instanceof mysqli && $conn->thread_id) {
    $conn->close();
}
?>