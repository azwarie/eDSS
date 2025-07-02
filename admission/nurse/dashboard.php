<?php
// Set the correct timezone at the very top
date_default_timezone_set('Asia/Kuala_Lumpur');

// error_reporting(E_ALL); // Recommended for development
// ini_set('display_errors', 1); // Recommended for development
error_reporting(0); // Suppress errors/warnings on production - REMOVE FOR DEBUGGING
ini_set('display_errors', 0); // Suppress errors/warnings on production - REMOVE FOR DEBUGGING


include('connection.php'); // Ensure this path is correct

if (!$conn) {
    die("Database connection failed: Cannot connect to azwarie_dss. Check connection details.");
}

// --- Staff ID and Link Function ---
$staffID = $_GET['staffid'] ?? null;
$loggedInStaffID_Session = $_SESSION['staffid'] ?? $staffID;
$current_page = basename($_SERVER['PHP_SELF']);

function addStaffIdToLink($url, $staffIdParam) {
    if ($staffIdParam !== null && $staffIdParam !== '') {
        $separator = (strpos($url, '?') === false) ? '?' : '&';
        return $url . $separator . 'staffid=' . urlencode($staffIdParam);
    }
    return $url;
}

// For arrivals toggle specifically
$baseDashboardUrl = basename($_SERVER['PHP_SELF']);
$baseArrivalsLinkParams = [];
if (isset($_GET['staffid']) && $_GET['staffid'] !== '') {
    $baseArrivalsLinkParams['staffid'] = $_GET['staffid'];
} elseif ($staffID !== null && $staffID !== '') {
     $baseArrivalsLinkParams['staffid'] = $staffID;
}

$arrivalsHourlyLinkParams = $baseArrivalsLinkParams;
$arrivalsHourlyLinkParams['arrivals_view'] = 'hourly';
$arrivalsHourlyLink = $baseDashboardUrl . '?' . http_build_query($arrivalsHourlyLinkParams);

$arrivalsDailyLinkParams = $baseArrivalsLinkParams;
$arrivalsDailyLinkParams['arrivals_view'] = 'daily';
$arrivalsDailyLink = $baseDashboardUrl . '?' . http_build_query($arrivalsDailyLinkParams);


// --- Initialize variables ---
$currentPatientsInED = 'Error';
$avgCurrentEDDuration = 'N/A';
$patientsWaitingForTriage = 'Error';
$avgEDLOSCompletedYesterday = 'N/A';

$query_error = null;

// Acuity Map (used in multiple places) - UPDATED
$acuityMap = [
    1 => "1-Immediate", // Changed from "1-Resuscitation"
    2 => "2-Emergent",
    3 => "3-Urgent",
    4 => "4-Less Urgent",
    5 => "5-Non-Urgent"
];

// --- KPI Card Queries ---
// ... (KPI queries remain the same - I'll omit for brevity but they should be here) ...
// 1. Current Patients in ED
$currentPatientsResult = $conn->query("SELECT COUNT(*) as active_ed FROM edstays WHERE Outime IS NULL");
if ($currentPatientsResult && $row = $currentPatientsResult->fetch_assoc()) { $currentPatientsInED = (int)$row['active_ed']; $currentPatientsResult->free(); } else { $query_error .= " Err KPI1; "; error_log("KPI Error 1: " . $conn->error); }

// 2. Average Current ED Duration for Active Patients
$totalMinutesWaitedActive = 0; $activePatientCountForAvg = 0;
$activeStaysTimesQuery = "SELECT Intime FROM edstays WHERE Outime IS NULL";
$activeStaysTimesResult = $conn->query($activeStaysTimesQuery);
if ($activeStaysTimesResult) {
    $nowForCalc = new DateTime();
    while ($row = $activeStaysTimesResult->fetch_assoc()) {
        $intimeForCalc = new DateTime($row['Intime']); $intervalForCalc = $nowForCalc->diff($intimeForCalc);
        $minutesWaited = ($intervalForCalc->d * 24 * 60) + ($intervalForCalc->h * 60) + $intervalForCalc->i;
        $totalMinutesWaitedActive += $minutesWaited; $activePatientCountForAvg++;
    } $activeStaysTimesResult->free();
    if ($activePatientCountForAvg > 0) { $avgMinutesActive = round($totalMinutesWaitedActive / $activePatientCountForAvg); $hoursActive = floor($avgMinutesActive / 60); $minsActive = $avgMinutesActive % 60; $avgCurrentEDDuration = ($hoursActive > 0 ? $hoursActive . "h " : "") . $minsActive . "m"; } elseif ($currentPatientsInED === 0) { $avgCurrentEDDuration = "0m"; } else { $avgCurrentEDDuration = "No active patients"; }
} else { $query_error .= " Error fetching active stay times for avg duration: " . $conn->error; error_log("Query Error (Active Stay Times): " . $conn->error); $avgCurrentEDDuration = 'Error'; }

// 3. Patients Waiting for Triage
$waitingTriageQuery = "SELECT COUNT(es.StayID) as count FROM edstays es LEFT JOIN edstays_triage et ON es.StayID = et.StayID WHERE es.Outime IS NULL AND et.TriageID IS NULL";
$waitingTriageResult = $conn->query($waitingTriageQuery);
if ($waitingTriageResult && $row = $waitingTriageResult->fetch_assoc()) { $patientsWaitingForTriage = (int)$row['count']; $waitingTriageResult->free(); } else { $query_error .= " Error fetching patients waiting triage: " . $conn->error; error_log("Query Error (Waiting Triage): " . $conn->error); }

// 4. Avg ED LOS (Completed Stays Yesterday)
$avgLOSQuery = "SELECT AVG(TIMESTAMPDIFF(MINUTE, Intime, Outime)) as avg_los_minutes FROM edstays WHERE DATE(Outime) = CURDATE() - INTERVAL 1 DAY AND Outime IS NOT NULL AND Intime IS NOT NULL";
$avgLOSResult = $conn->query($avgLOSQuery);
if ($avgLOSResult && $row = $avgLOSResult->fetch_assoc()) { if ($row['avg_los_minutes'] !== null) { $avgMinutesLOS = round($row['avg_los_minutes']); $hours = floor($avgMinutesLOS / 60); $mins = $avgMinutesLOS % 60; $avgEDLOSCompletedYesterday = ($hours > 0 ? $hours . "h " : "") . $mins . "m"; } else { $avgEDLOSCompletedYesterday = "No data"; } $avgLOSResult->free(); } else { $query_error .= " Err KPI4; "; error_log("KPI Error 4: " . $conn->error); }


// --- Chart Data Queries ---
// ... (Arrivals, Triage Acuity, Throughput, Arrival Transport queries remain the same) ...
// 1. Arrivals (Hourly/Daily Toggle)
$arrivals_view_type = $_GET['arrivals_view'] ?? 'hourly'; // Default to hourly
$arrivalsChartTitle = '';
$arrivalsDataLabels = [];
$arrivalsDataCounts = [];

if ($arrivals_view_type === 'daily') {
    $arrivalsChartTitle = 'Arrivals per Day (Last 7 Days)';
    $dailyArrivalsQuery = "SELECT DATE(Intime) as arrival_day, COUNT(*) as count
                           FROM edstays
                           WHERE Intime >= CURDATE() - INTERVAL 6 DAY AND Intime < CURDATE() + INTERVAL 1 DAY
                           GROUP BY DATE(Intime)
                           ORDER BY arrival_day ASC";
    $dailyArrivalsResult = $conn->query($dailyArrivalsQuery);
    $tempData = [];
    for ($i = 6; $i >= 0; $i--) {
        $dayKey = date('Y-m-d', strtotime("-$i days"));
        $tempData[$dayKey] = 0;
        $arrivalsDataLabels[] = date('D, M j', strtotime("-$i days"));
    }
    if ($dailyArrivalsResult) {
        while ($row = $dailyArrivalsResult->fetch_assoc()) {
            if (isset($tempData[$row['arrival_day']])) {
                $tempData[$row['arrival_day']] = (int)$row['count'];
            }
        }
        $dailyArrivalsResult->free();
    } else {
        $query_error .= " Err Chart1-Daily; "; error_log("Chart Error 1 (Daily Arrivals): " . $conn->error);
    }
    $arrivalsDataCounts = array_values($tempData);
} else { // Default to hourly
    $arrivalsChartTitle = 'Arrivals per Hour (Today)';
    $hourlyArrivalsQuery = "SELECT HOUR(Intime) as arrival_hour, COUNT(*) as count
                            FROM edstays
                            WHERE DATE(Intime) = CURDATE()
                            GROUP BY HOUR(Intime)
                            ORDER BY arrival_hour ASC";
    $hourlyArrivalsResult = $conn->query($hourlyArrivalsQuery);
    $tempData = array_fill(0, 24, 0);
    if ($hourlyArrivalsResult) {
        while ($row = $hourlyArrivalsResult->fetch_assoc()) {
            $tempData[(int)$row['arrival_hour']] = (int)$row['count'];
        }
        for ($i = 0; $i < 24; $i++) {
            $arrivalsDataLabels[] = str_pad($i, 2, "0", STR_PAD_LEFT) . ":00";
            $arrivalsDataCounts[] = $tempData[$i];
        }
        $hourlyArrivalsResult->free();
    } else {
        $query_error .= " Err Chart1-Hourly; "; error_log("Chart Error 1 (Hourly Arrivals): " . $conn->error);
    }
}
$arrivalsDataJson = json_encode(['labels' => $arrivalsDataLabels, 'counts' => $arrivalsDataCounts, 'view_type' => $arrivals_view_type]);

// 2. Triage Acuity Distribution (All Active Patients)
$triageAcuityChartTitle = 'Triage Acuity (Current ED Patients)';
$triageAcuityQuery = "SELECT t.Acuity, COUNT(DISTINCT es.StayID) as count
                      FROM edstays es
                      JOIN edstays_triage et ON es.StayID = et.StayID
                      JOIN triage t ON et.TriageID = t.TriageID
                      WHERE es.Outime IS NULL
                      GROUP BY t.Acuity ORDER BY t.Acuity ASC";
$triageAcuityResult = $conn->query($triageAcuityQuery); $triageAcuityLabels = []; $triageAcuityCounts = [];
if ($triageAcuityResult) { while ($row = $triageAcuityResult->fetch_assoc()) { $triageAcuityLabels[] = $acuityMap[$row['Acuity']] ?? 'Unknown Acuity (' . $row['Acuity'] . ')'; $triageAcuityCounts[] = (int)$row['count']; } $triageAcuityResult->free(); } else { $query_error .= " Err Chart2; "; error_log("Chart Error 2 (Triage Acuity): " . $conn->error); }
$triageAcuityDataJson = json_encode(['labels' => $triageAcuityLabels, 'counts' => $triageAcuityCounts]);

// 3. ED Throughput (Last 7 Days)
$daysRange = []; for ($i = 6; $i >= 0; $i--) { $daysRange[] = date('Y-m-d', strtotime("-$i days")); }
$arrivalsData = array_fill_keys($daysRange, 0); $departuresData = array_fill_keys($daysRange, 0);
$arrivalsLast7DaysQuery = "SELECT DATE(Intime) as day, COUNT(*) as count FROM edstays WHERE Intime >= CURDATE() - INTERVAL 6 DAY AND Intime < CURDATE() + INTERVAL 1 DAY GROUP BY DATE(Intime)";
$arrivalsResult = $conn->query($arrivalsLast7DaysQuery); if($arrivalsResult) { while($row = $arrivalsResult->fetch_assoc()){ if(isset($arrivalsData[$row['day']])) $arrivalsData[$row['day']] = (int)$row['count']; } $arrivalsResult->free(); } else { $query_error .= " Err Chart3a; "; error_log("Chart Error 3a: " . $conn->error); }
$departuresLast7DaysQuery = "SELECT DATE(Outime) as day, COUNT(*) as count FROM edstays WHERE Outime >= CURDATE() - INTERVAL 6 DAY AND Outime < CURDATE() + INTERVAL 1 DAY GROUP BY DATE(Outime)";
$departuresResult = $conn->query($departuresLast7DaysQuery); if($departuresResult) { while($row = $departuresResult->fetch_assoc()){ if(isset($departuresData[$row['day']])) $departuresData[$row['day']] = (int)$row['count']; } $departuresResult->free(); } else { $query_error .= " Err Chart3d; "; error_log("Chart Error 3d: " . $conn->error); }
$throughputLabels = array_map(function($dateStr) { return date('D, M j', strtotime($dateStr)); }, array_keys($arrivalsData));
$throughputArrivalCounts = array_values($arrivalsData); $throughputDepartureCounts = array_values($departuresData);
$dailyThroughputDataJson = json_encode(['labels' => $throughputLabels, 'arrivals' => $throughputArrivalCounts, 'departures' => $throughputDepartureCounts]);

// 4. Arrival Transport (Today)
$arrivalTransportQuery = "SELECT Arrival_transport, COUNT(*) as count FROM edstays WHERE DATE(Intime) = CURDATE() GROUP BY Arrival_transport ORDER BY count DESC";
$arrivalTransportResult = $conn->query($arrivalTransportQuery); $arrivalTransportLabels = []; $arrivalTransportCounts = [];
if($arrivalTransportResult){ while($row = $arrivalTransportResult->fetch_assoc()){ $arrivalTransportLabels[] = $row['Arrival_transport'] ?? 'Unknown'; $arrivalTransportCounts[] = (int)$row['count']; } $arrivalTransportResult->free(); } else { $query_error .= " Err Chart4; "; error_log("Chart Error 4: " . $conn->error); }
$arrivalTransportDataJson = json_encode(['labels' => $arrivalTransportLabels, 'counts' => $arrivalTransportCounts]);


// --- Data for Active ED Patient Duration List ---
$activePatientDurations = [];
$now = new DateTime();
$activePatientDurationsQuery = "SELECT es.StayID, p.PatientName, es.Intime, es.Status AS EDStatus, s.Name AS StaffName, t.Acuity FROM edstays es JOIN patients p ON es.PatientID = p.PatientID LEFT JOIN staff s ON es.StaffID = s.StaffID LEFT JOIN edstays_triage et ON es.StayID = et.StayID LEFT JOIN triage t ON et.TriageID = t.TriageID WHERE es.Outime IS NULL ORDER BY es.Intime ASC";
$activePatientDurationsResult = $conn->query($activePatientDurationsQuery);
if ($activePatientDurationsResult) {
    while ($row = $activePatientDurationsResult->fetch_assoc()) {
        $intimeDate = new DateTime($row['Intime']); $interval = $now->diff($intimeDate); $durationString = "";
        $totalMinutesWaited = ($interval->d * 24 * 60) + ($interval->h * 60) + $interval->i;
        if ($interval->d > 0) $durationString .= $interval->d . "d "; if ($interval->h > 0) $durationString .= $interval->h . "h ";
        $durationString .= $interval->i . "m"; if(empty(trim($durationString))) $durationString = "0m";
        $row['Duration'] = trim($durationString); $row['TotalMinutesWaited'] = $totalMinutesWaited;
        $row['AcuityText'] = isset($row['Acuity']) ? ($acuityMap[$row['Acuity']] ?? 'N/A') : 'Not Triaged'; // Uses updated acuityMap
        $activePatientDurations[] = $row;
    } $activePatientDurationsResult->free();
} else { $query_error .= " Error fetching active patient durations: " . $conn->error; error_log("Query Error (Active Patient Durations): " . $conn->error); }

// --- NEW: Prepare data for "Average Current ED Duration by Acuity" Bar Chart ---
$avgActiveAcuityDurations = [];
$acuityBarChartLabels = []; // Re-use for both new bar charts
$tempActiveAcuitySums = [];
$tempActiveAcuityCounts = [];

foreach ($acuityMap as $acuityKey => $acuityName) {
    $acuityBarChartLabels[] = $acuityName; // Uses updated acuityMap
    $tempActiveAcuitySums[$acuityKey] = 0;
    $tempActiveAcuityCounts[$acuityKey] = 0;
    $avgActiveAcuityDurations[] = 0; // Initialize with 0
}

foreach ($activePatientDurations as $stay) {
    if (isset($stay['Acuity']) && array_key_exists($stay['Acuity'], $tempActiveAcuitySums)) {
        $tempActiveAcuitySums[$stay['Acuity']] += $stay['TotalMinutesWaited'];
        $tempActiveAcuityCounts[$stay['Acuity']]++;
    }
}

$i = 0;
foreach ($acuityMap as $acuityKey => $acuityName) {
    if ($tempActiveAcuityCounts[$acuityKey] > 0) {
        $avgActiveAcuityDurations[$i] = round($tempActiveAcuitySums[$acuityKey] / $tempActiveAcuityCounts[$acuityKey]);
    }
    $i++;
}
$avgActiveAcuityDurationDataJson = json_encode(['labels' => $acuityBarChartLabels, 'data' => $avgActiveAcuityDurations]);


// --- NEW: Prepare data for "Average Completed ED LOS by Acuity (Last 7 Days)" Bar Chart ---
$avgCompletedAcuityLOS = [];
// $acuityBarChartLabels is already populated from above and uses updated acuityMap
$tempCompletedAcuitySums = [];
$tempCompletedAcuityCounts = [];

foreach ($acuityMap as $acuityKey => $acuityName) {
    $tempCompletedAcuitySums[$acuityKey] = 0;
    $tempCompletedAcuityCounts[$acuityKey] = 0;
    $avgCompletedAcuityLOS[] = 0; // Initialize with 0
}

$completedLOSQuery = "SELECT t.Acuity, TIMESTAMPDIFF(MINUTE, es.Intime, es.Outime) as los_minutes
                      FROM edstays es
                      JOIN edstays_triage et ON es.StayID = et.StayID
                      JOIN triage t ON et.TriageID = t.TriageID
                      WHERE es.Outime IS NOT NULL AND es.Intime IS NOT NULL
                        AND DATE(es.Outime) >= CURDATE() - INTERVAL 6 DAY
                        AND DATE(es.Outime) < CURDATE() + INTERVAL 1 DAY";
$completedLOSResult = $conn->query($completedLOSQuery);

if ($completedLOSResult) {
    while ($row = $completedLOSResult->fetch_assoc()) {
        if (isset($row['Acuity']) && array_key_exists($row['Acuity'], $tempCompletedAcuitySums)) {
            $tempCompletedAcuitySums[$row['Acuity']] += (int)$row['los_minutes'];
            $tempCompletedAcuityCounts[$row['Acuity']]++;
        }
    }
    $completedLOSResult->free();
} else {
    $query_error .= " Err Chart-AvgCompletedLOS; "; error_log("Chart Error (Avg Completed LOS by Acuity): " . $conn->error);
}

$i = 0;
foreach ($acuityMap as $acuityKey => $acuityName) {
    if ($tempCompletedAcuityCounts[$acuityKey] > 0) {
        $avgCompletedAcuityLOS[$i] = round($tempCompletedAcuitySums[$acuityKey] / $tempCompletedAcuityCounts[$acuityKey]);
    }
    $i++;
}
$avgCompletedAcuityLOSDataJson = json_encode(['labels' => $acuityBarChartLabels, 'data' => $avgCompletedAcuityLOS]);


// --- Define Duration Thresholds ---
// ... (Your $durationThresholds array - ensure only one definition) ...
// --- getDurationClass Function ---
// ... (Your getDurationClass function - ensure only one definition) ...
$durationThresholds = [
    'Waiting for Triage' => [ 'caution' => 30, 'warning' => 60, 'critical' => 90 ],
    'Waiting for Doctor' => [
        1 => ['critical' => 10], 2 => ['warning' => 30, 'critical' => 60],
        3 => ['caution' => 60, 'warning' => 120, 'critical' => 240],
        4 => ['caution' => 120, 'warning' => 240, 'critical' => 360],
        5 => ['caution' => 180, 'warning' => 360, 'critical' => 480]
    ],
    'Awaiting Disposition' => [
        1 => ['critical' => 30], 2 => ['warning' => 60, 'critical' => 120],
        3 => ['caution' => 120, 'warning' => 180, 'critical' => 300],
        4 => ['caution' => 180, 'warning' => 300, 'critical' => 420],
        5 => ['caution' => 240, 'warning' => 420, 'critical' => 540]
    ],
    'Doctor Assessment' => [ /* Define if you use this status */ ]
];
function getDurationClass($totalMinutes, $status, $acuity, $thresholds) {
    $acuity = ($acuity !== null) ? (int)$acuity : null; $class = '';
    if (isset($thresholds[$status])) {
        $statusThresholds = $thresholds[$status]; $acuitySpecificThresholds = null;
        if ($acuity !== null && is_array($statusThresholds) && isset($statusThresholds[$acuity])) {
            $acuitySpecificThresholds = $statusThresholds[$acuity];
        } elseif (is_array($statusThresholds) && (isset($statusThresholds['critical']) || isset($statusThresholds['warning']) || isset($statusThresholds['caution']))) {
            $acuitySpecificThresholds = $statusThresholds; // General thresholds for the status
        }
        if ($acuitySpecificThresholds) {
            if (isset($acuitySpecificThresholds['critical']) && $totalMinutes >= $acuitySpecificThresholds['critical']) { $class = 'duration-critical'; }
            elseif (isset($acuitySpecificThresholds['warning']) && $totalMinutes >= $acuitySpecificThresholds['warning']) { $class = 'duration-warning'; }
            elseif (isset($acuitySpecificThresholds['caution']) && $totalMinutes >= $acuitySpecificThresholds['caution']) { $class = 'duration-caution'; }
        }
    } return $class;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ED Dashboard - eDSS</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
   <style>
        :root {
            --bs-primary-rgb: 0, 123, 255;
            --bs-danger-rgb: 220, 53, 69;
            --bs-warning-rgb: 255, 193, 7; /* Added for direct use in JS if needed */
            --bs-success-rgb: 25, 135, 84; /* Added */
            --bs-info-rgb: 13, 202, 240;   /* Added */
            --bs-secondary-rgb: 108, 117, 125;
            --bs-orange-rgb: 253, 126, 20; /* Bootstrap's orange for Emergent */
        }
        body { font-family: 'Montserrat', sans-serif; background-color: #f8f9fa; padding-top: 70px; }
        .navbar { background-color: white !important; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); position: fixed; width: 100%; top: 0; z-index: 1030; }
        .card.border-left-danger { border-left: 5px solid rgb(var(--bs-danger-rgb)) !important; }
        .card.border-left-secondary { border-left: 5px solid rgb(var(--bs-secondary-rgb)) !important; }
        .navbar-brand { font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 700; color: rgb(var(--bs-primary-rgb)) !important; }
        .navbar .nav-link { color: black !important; } .navbar .nav-link:hover { color: rgb(var(--bs-primary-rgb)) !important; }
        .navbar .nav-item.active .nav-link { color: rgb(var(--bs-primary-rgb)) !important; font-weight: bold !important; }
        .hero-section { background-color: rgb(var(--bs-primary-rgb)); color: white; padding: 50px 0; text-align: center; }
        .hero-section h1 { font-family: 'Montserrat', sans-serif; font-size: 2.5rem; font-weight: 700; color: white; text-transform: uppercase; }
        .hero-section p { font-size: 1.1rem; }
        .card { margin-top: 20px; box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08); border-radius: 8px; border: none; }
        .card-header { background-color: #e9ecef; border-bottom: 1px solid #dee2e6; font-weight: 600; padding: 0.85rem 1.25rem; font-size: 1.05rem; }
        .card-body { padding: 1.5rem; }
        .chart-container { position: relative; margin: auto; height: 320px; width: 100%; }
        .stat-card .card-text { font-size: 2rem; font-weight: bold; color: rgb(var(--bs-primary-rgb)); }
        .stat-card .card-title { font-size: 1rem; font-weight: 600; color: #6c757d; text-transform: uppercase; }
        .card.border-left-primary { border-left: 5px solid rgb(var(--bs-primary-rgb)) !important; }
        .card.border-left-success { border-left: 5px solid rgb(var(--bs-success-rgb)) !important; }
        .card.border-left-info { border-left: 5px solid rgb(var(--bs-info-rgb)) !important; }
        .card.border-left-warning { border-left: 5px solid rgb(var(--bs-warning-rgb)) !important; }
        .duration-table th, .duration-table td { font-size: 0.85rem; padding: 0.5rem; vertical-align: middle;}
        .duration-caution { background-color: #fff3cd !important; color: #664d03;}
        .duration-warning { background-color: #ffc107 !important; color: #302a00; font-weight: bold;}
        .duration-critical { background-color: #f8d7da !important; color: #842029; font-weight: bold;}
        footer { background-color: rgb(var(--bs-primary-rgb)); color: white; padding: 20px 0; text-align: center; margin-top: 40px; }
        @media (max-width: 768px) { .hero-section h1 {font-size: 2rem;} .chart-container { height: 280px; } }
   </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light">
         <div class="container">
            <a class="navbar-brand" href="<?php echo addStaffIdToLink('https://localhost/dss/staff/nurse/NurseLandingPage.php', $staffID);?>">eDSS</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"><span class="navbar-toggler-icon"></span></button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                   <li class="nav-item <?php echo ($current_page == 'index.php' || $current_page == 'index.php') ? 'active' : ''; ?>"><a class="nav-link" href="<?php echo addStaffIdToLink('index.php', $staffID); ?>">Home</a></li>
                   <li class="nav-item <?php echo ($current_page == 'manage_edstay.php') ? 'active' : ''; ?>"><a class="nav-link" href="<?php echo addStaffIdToLink('manage_edstay.php', $staffID); ?>">ED</a></li>
                   <li class="nav-item <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>"><a class="nav-link" href="<?php echo addStaffIdToLink('dashboard.php', $staffID); ?>">Real-Time Dashboards</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="hero-section">
        <h1>Emergency Department Dashboard</h1>
        <p>Real-Time ED Flow & Triage Insights</p>
    </div>

    <div class="container mt-4">
         <?php if (!empty($query_error)): ?>
            <div class="alert alert-danger"><h5 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Data Error!</h5><p>Some dashboard data could not be loaded. Errors: <?php echo htmlspecialchars(trim($query_error)); ?></p></div>
        <?php endif; ?>

        <div class="row mb-4">
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card border-left-primary shadow h-100 py-2 stat-card">
                    <div class="card-body"><div class="row no-gutters align-items-center"><div class="col mr-2">
                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Current in ED</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800 card-text"><?php echo $currentPatientsInED; ?></div>
                    </div><div class="col-auto"><i class="fas fa-users fa-2x text-gray-300"></i></div></div></div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card border-left-info shadow h-100 py-2 stat-card">
                     <div class="card-body"><div class="row no-gutters align-items-center"><div class="col mr-2">
                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Avg. Current ED Duration</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800 card-text"><?php echo $avgCurrentEDDuration; ?></div>
                    </div><div class="col-auto"><i class="fas fa-hourglass-start fa-2x text-gray-300"></i></div></div></div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card border-left-warning shadow h-100 py-2 stat-card">
                    <div class="card-body"><div class="row no-gutters align-items-center"><div class="col mr-2">
                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Waiting for Triage</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800 card-text"><?php echo $patientsWaitingForTriage; ?></div>
                    </div><div class="col-auto"><i class="fas fa-user-md fa-2x text-gray-300"></i></div></div></div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card border-left-success shadow h-100 py-2 stat-card">
                    <div class="card-body"><div class="row no-gutters align-items-center"><div class="col mr-2">
                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Avg ED LOS (Yest. Done)</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800 card-text"><?php echo $avgEDLOSCompletedYesterday; ?></div>
                    </div><div class="col-auto"><i class="fas fa-history fa-2x text-gray-300"></i></div></div></div>
                </div>
            </div>
        </div>

         <div class="row">
            <div class="col-lg-6 mb-4">
                <div class="card border-left-primary h-100">
                    <div class="card-header py-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-user-clock me-2"></i><?php echo htmlspecialchars($arrivalsChartTitle); ?></h6>
                            <div class="btn-group btn-group-sm" role="group" aria-label="Arrivals View Toggle">
                                <a href="<?php echo htmlspecialchars($arrivalsHourlyLink); ?>" class="btn btn-outline-primary <?php echo ($arrivals_view_type === 'hourly' ? 'active' : ''); ?>">Hourly</a>
                                <a href="<?php echo htmlspecialchars($arrivalsDailyLink); ?>" class="btn btn-outline-primary <?php echo ($arrivals_view_type === 'daily' ? 'active' : ''); ?>">Daily</a>
                            </div>
                        </div>
                    </div>
                    <div class="card-body"><div class="chart-container"><canvas id="arrivalsChart"></canvas></div></div>
                </div>
            </div>
            <div class="col-lg-6 mb-4">
                <div class="card border-left-info h-100"> <!-- Consider changing border color if acuity is prominent -->
                    <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-info"><i class="fas fa-heartbeat me-2"></i><?php echo htmlspecialchars($triageAcuityChartTitle); ?></h6></div>
                    <div class="card-body"><div class="chart-container"><canvas id="triageAcuityChart"></canvas></div></div>
                </div>
            </div>
        </div>

        <div class="row">
             <div class="col-lg-6 mb-4"><div class="card border-left-success h-100"><div class="card-header py-3"><h6 class="m-0 font-weight-bold text-success"><i class="fas fa-exchange-alt me-2"></i>ED Throughput (Last 7 Days)</h6></div><div class="card-body"><div class="chart-container"><canvas id="dailyThroughputChart"></canvas></div></div></div></div>
            <div class="col-lg-6 mb-4"><div class="card border-left-warning h-100"><div class="card-header py-3"><h6 class="m-0 font-weight-bold text-warning"><i class="fas fa-ambulance me-2"></i>Arrival Transport (Today)</h6></div><div class="card-body"><div class="chart-container"><canvas id="arrivalTransportChart"></canvas></div></div></div></div>
        </div>

        <div class="row">
            <div class="col-lg-6 mb-4">
                <div class="card border-left-danger h-100"> <!-- Card border consistent with highest acuity -->
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-danger"><i class="fas fa-tachometer-alt me-2"></i>Avg. Current ED Duration by Acuity</h6>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="avgActiveAcuityDurationChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6 mb-4">
                <div class="card border-left-secondary h-100">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-secondary"><i class="fas fa-chart-line me-2"></i>Avg. Completed ED LOS by Acuity (Last 7 Days)</h6>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="avgCompletedAcuityLOSChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header"><i class="fas fa-bed-pulse me-2"></i>Active ED Patients: Duration, Status & Acuity</div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover duration-table">
                                <thead>
                                    <tr>
                                        <th>Stay ID</th>
                                        <th>Patient Name</th>
                                        <th>Attending Staff</th>
                                        <th>Check-In Time</th>
                                        <th>Current ED Status</th>
                                        <th>Duration in ED</th>
                                        <th>Triage Acuity</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($activePatientDurations)): ?>
                                        <?php foreach ($activePatientDurations as $patientStay): ?>
                                            <?php
                                                $durationCellClass = getDurationClass(
                                                    $patientStay['TotalMinutesWaited'],
                                                    $patientStay['EDStatus'],
                                                    $patientStay['Acuity'] ?? null,
                                                    $durationThresholds
                                                );
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($patientStay['StayID']); ?></td>
                                                <td><?php echo htmlspecialchars($patientStay['PatientName']); ?></td>
                                                <td><?php echo htmlspecialchars($patientStay['StaffName'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars(date('d M Y, h:i A', strtotime($patientStay['Intime']))); ?></td>
                                                <td><?php echo htmlspecialchars($patientStay['EDStatus'] ?? 'N/A'); ?></td>
                                                <td class="<?php echo $durationCellClass; ?>"><?php echo htmlspecialchars($patientStay['Duration']); ?></td>
                                                <td><?php echo htmlspecialchars($patientStay['AcuityText']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="7" class="text-center text-muted">No active patients in ED currently.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                         <div class="mt-3 small d-flex flex-wrap justify-content-center gap-2">
                            <span class="badge bg-light text-dark border">Normal Wait</span>
                            <span class="badge duration-caution border"><i class="fas fa-exclamation-circle me-1"></i>Caution Wait</span>
                            <span class="badge duration-warning border" style="background-color: #ffc107 !important; color: #302a00 !important;"><i class="fas fa-exclamation-triangle me-1"></i>Warning Wait</span>
                            <span class="badge duration-critical border"><i class="fas fa-skull-crossbones me-1"></i>Critical Wait</span>
                         </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /container -->

<script>
function initializeAllCharts() {
    console.log("--- Attempting to Initialize All Charts NOW ---");
    if (typeof Chart === 'undefined') {
        console.error("Chart.js is still undefined. CANNOT PROCEED.");
        const errorDiv = document.createElement('div');
        errorDiv.className = 'alert alert-danger';
        errorDiv.innerHTML = '<strong>Critical Error:</strong> Charting library failed to load. Graphs cannot be displayed. Please check your internet connection or contact support.';
        document.querySelector('.container').prepend(errorDiv);
        return;
    }
    console.log("Chart.js Version (inside initializeAllCharts):", Chart.version);

    // Standard Colors from CSS variables or direct definition
    const primaryColorRGB = getComputedStyle(document.documentElement).getPropertyValue('--bs-primary-rgb').trim();
    const primaryColor = `rgb(${primaryColorRGB})`;
    const successColorRGB = getComputedStyle(document.documentElement).getPropertyValue('--bs-success-rgb').trim();
    const successColor = `rgb(${successColorRGB})`; // e.g., rgb(25, 135, 84)
    const infoColorRGB = getComputedStyle(document.documentElement).getPropertyValue('--bs-info-rgb').trim();
    const infoColor = `rgb(${infoColorRGB})`; // e.g., rgb(13, 202, 240)
    const warningColorRGB = getComputedStyle(document.documentElement).getPropertyValue('--bs-warning-rgb').trim();
    const warningColor = `rgb(${warningColorRGB})`; // e.g., rgb(255, 193, 7)
    const dangerColorRGBValue = getComputedStyle(document.documentElement).getPropertyValue('--bs-danger-rgb').trim();
    const dangerColor = `rgb(${dangerColorRGBValue})`; // e.g., rgb(220, 53, 69)
    const secondaryColorRGBValue = getComputedStyle(document.documentElement).getPropertyValue('--bs-secondary-rgb').trim();
    const secondaryColor = `rgb(${secondaryColorRGBValue})`;
    const orangeColorRGB = getComputedStyle(document.documentElement).getPropertyValue('--bs-orange-rgb').trim();
    const emergentColor = `rgb(${orangeColorRGB})`; // e.g., rgb(253, 126, 20)

    // Acuity Specific Colors
    const acuitySolidColors = {
        "1-Immediate": dangerColor,        // Red
        "2-Emergent": emergentColor,     // Orange
        "3-Urgent": warningColor,        // Yellow
        "4-Less Urgent": successColor,     // Green
        "5-Non-Urgent": infoColor,         // Blue
        "Unknown Acuity": '#AAAAAA'        // Gray for unknown
    };

    function drawChartOrMessage(canvasIdOrContext, chartConfig, noDataMessage) {
        let ctx;
        let canvasElement;

        if (typeof canvasIdOrContext === 'string') {
            canvasElement = document.getElementById(canvasIdOrContext);
            if (!canvasElement) {
                console.error("Canvas element not found:", canvasIdOrContext);
                return;
            }
            ctx = canvasElement.getContext('2d');
        } else {
            ctx = canvasIdOrContext;
            canvasElement = ctx.canvas;
        }
        
        if (!ctx) {
            console.error("Canvas context could not be obtained for:", canvasIdOrContext);
            return;
        }

        const existingChart = Chart.getChart(canvasElement);
        if (existingChart) {
            existingChart.destroy();
        }

        let hasMeaningfulData = chartConfig.data && chartConfig.data.labels && chartConfig.data.labels.length > 0 &&
                                chartConfig.data.datasets && chartConfig.data.datasets.some(ds => ds.data && ds.data.length > 0 && ds.data.some(d => (typeof d === 'number' && !isNaN(d)) || (typeof d === 'object' && d !== null) ));
        
        if (hasMeaningfulData) {
            try {
                new Chart(ctx, chartConfig);
            } catch (e) {
                console.error(`ERROR CREATING CHART '${chartConfig.type}' on '${canvasIdOrContext}':`, e);
                ctx.clearRect(0, 0, canvasElement.width, canvasElement.height);
                ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillStyle = 'red'; ctx.font = '12px Montserrat, sans-serif';
                ctx.fillText(`Error: ${e.message}. Check console.`, canvasElement.width / 2, canvasElement.height / 2);
            }
        } else {
            ctx.clearRect(0, 0, canvasElement.width, canvasElement.height);
            ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillStyle = '#6c757d'; ctx.font = '16px Montserrat, sans-serif';
            ctx.fillText(noDataMessage || 'No data available for this chart.', canvasElement.width / 2, canvasElement.height / 2);
        }
    }

    // Chart Data (from PHP)
    const arrivalsData = <?php echo $arrivalsDataJson; ?>;
    const triageAcuityData = <?php echo $triageAcuityDataJson; ?>;
    const dailyThroughputData = <?php echo $dailyThroughputDataJson; ?>;
    const arrivalTransportData = <?php echo $arrivalTransportDataJson; ?>;
    const avgActiveAcuityDurationData = <?php echo $avgActiveAcuityDurationDataJson; ?>;
    const avgCompletedAcuityLOSData = <?php echo $avgCompletedAcuityLOSDataJson; ?>;

    // 1. Arrivals Chart
    drawChartOrMessage('arrivalsChart', { 
        type: 'bar',
        data: {
            labels: arrivalsData.labels,
            datasets: [{
                label: 'Arrivals',
                data: arrivalsData.counts,
                backgroundColor: `rgba(${primaryColorRGB}, 0.7)`,
                borderColor: primaryColor,
                borderWidth: 1
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
    }, `No arrival data for ${arrivalsData.view_type === 'daily' ? 'the last 7 days' : 'today'}.`);

    // 2. Triage Acuity Chart (Doughnut) - UPDATED COLORS
    const triageAcuityChartBgColors = triageAcuityData.labels.map(label => 
        acuitySolidColors[label] || (label.startsWith("Unknown Acuity") ? acuitySolidColors["Unknown Acuity"] : '#CCCCCC')
    );
    drawChartOrMessage('triageAcuityChart', { 
        type: 'doughnut',
        data: {
            labels: triageAcuityData.labels, // These now include "1-Immediate"
            datasets: [{
                label: 'Acuity Level',
                data: triageAcuityData.counts,
                backgroundColor: triageAcuityChartBgColors, // Using the new color map
                borderColor: '#fff',
                borderWidth: 2
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
    }, 'No triage acuity data for current ED patients.');

    // 3. Daily Throughput Chart
    drawChartOrMessage('dailyThroughputChart', { 
        type: 'line',
        data: {
            labels: dailyThroughputData.labels,
            datasets: [
                { label: 'Arrivals', data: dailyThroughputData.arrivals, borderColor: primaryColor, backgroundColor: `rgba(${primaryColorRGB}, 0.1)`, fill: true, tension: 0.3 },
                { label: 'Departures', data: dailyThroughputData.departures, borderColor: successColor, backgroundColor: `rgba(${successColorRGB}, 0.1)`, fill: true, tension: 0.3 }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
    }, 'No throughput data available for the last 7 days.');

    // 4. Arrival Transport Chart
    drawChartOrMessage('arrivalTransportChart', { 
        type: 'pie',
        data: {
            labels: arrivalTransportData.labels,
            datasets: [{
                label: 'Transport Methods',
                data: arrivalTransportData.counts,
                backgroundColor: [warningColor, infoColor, secondaryColor, primaryColor, dangerColor, successColor], // Keep diverse colors for categories
                borderColor: '#fff',
                borderWidth: 1
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
    }, 'No arrival transport data for today.');
    
    // 5. Avg. Current ED Duration by Acuity (Bar Chart) - UPDATED COLORS
    const activeAcuityDurationBgColors = avgActiveAcuityDurationData.labels.map(label => {
        const solidColor = acuitySolidColors[label] || (label.startsWith("Unknown Acuity") ? acuitySolidColors["Unknown Acuity"] : '#CCCCCC');
        return solidColor.includes('rgba') ? solidColor : solidColor.replace('rgb', 'rgba').replace(')', ', 0.7)');
    });
    const activeAcuityDurationBorderColors = avgActiveAcuityDurationData.labels.map(label => 
        acuitySolidColors[label] || (label.startsWith("Unknown Acuity") ? acuitySolidColors["Unknown Acuity"] : '#CCCCCC')
    );
    drawChartOrMessage('avgActiveAcuityDurationChart', {
        type: 'bar',
        data: {
            labels: avgActiveAcuityDurationData.labels, // These now include "1-Immediate"
            datasets: [{
                label: 'Avg Duration (minutes)',
                data: avgActiveAcuityDurationData.data,
                backgroundColor: activeAcuityDurationBgColors,
                borderColor: activeAcuityDurationBorderColors,
                borderWidth: 1
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, title: { display: true, text: 'Avg. Duration (minutes)' } },
                x: { title: { display: true, text: 'Acuity Level' } }
            },
            plugins: { legend: { display: false } } // Legend can be off if colors are self-explanatory
        }
    }, 'No active patient average duration data by acuity.');

    // 6. Avg. Completed ED LOS by Acuity (Bar Chart) - UPDATED COLORS
    const completedLOSBgColors = avgCompletedAcuityLOSData.labels.map(label => {
        const solidColor = acuitySolidColors[label] || (label.startsWith("Unknown Acuity") ? acuitySolidColors["Unknown Acuity"] : '#CCCCCC');
        return solidColor.includes('rgba') ? solidColor : solidColor.replace('rgb', 'rgba').replace(')', ', 0.7)');
    });
    const completedLOSBorderColors = avgCompletedAcuityLOSData.labels.map(label => 
        acuitySolidColors[label] || (label.startsWith("Unknown Acuity") ? acuitySolidColors["Unknown Acuity"] : '#CCCCCC')
    );
    drawChartOrMessage('avgCompletedAcuityLOSChart', {
        type: 'bar',
        data: {
            labels: avgCompletedAcuityLOSData.labels, // These now include "1-Immediate"
            datasets: [{
                label: 'Avg LOS (minutes)',
                data: avgCompletedAcuityLOSData.data,
                backgroundColor: completedLOSBgColors,
                borderColor: completedLOSBorderColors,
                borderWidth: 1
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, title: { display: true, text: 'Avg. LOS (minutes)' } },
                x: { title: { display: true, text: 'Acuity Level' } }
            },
            plugins: { legend: { display: false } } // Legend can be off
        }
    }, 'No completed average LOS data by acuity for the last 7 days.');
    
    console.log("--- Chart Initialization Attempted ---");
}

document.addEventListener('DOMContentLoaded', function () {
    console.log("--- DOMContentLoaded Fired ---");
    if (typeof Chart !== 'undefined') {
        initializeAllCharts();
    } else {
        console.warn("Chart.js is UNDEFINED at DOMContentLoaded. Will try again on window.onload.");
        window.addEventListener('load', function() {
            console.log("--- window.onload Fired ---");
            if (typeof Chart !== 'undefined') {
                 initializeAllCharts();
            } else {
                console.error("Chart.js is STILL UNDEFINED even at window.onload.");
                const errorDiv = document.createElement('div');
                errorDiv.className = 'alert alert-danger';
                errorDiv.innerHTML = '<strong>Critical Error:</strong> Charting library failed to load. Graphs cannot be displayed.';
                const container = document.querySelector('.container.mt-4');
                if (container) {
                    container.insertBefore(errorDiv, container.firstChild);
                } else {
                    document.body.insertBefore(errorDiv, document.body.firstChild);
                }
            }
        });
    }
});
</script>
</body>
</html>
<?php
if (isset($conn) && $conn instanceof mysqli && $conn->thread_id) {
    $conn->close();
}
?>