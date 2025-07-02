<?php
ini_set('display_errors', 1); // FOR DEBUGGING
ini_set('display_startup_errors', 1); // FOR DEBUGGING
error_reporting(E_ALL); // FOR DEBUGGING

session_start();
include 'connection.php'; // Defines $conn

// --- Database Connection Check ---
if (!$conn || $conn->connect_error) {
    die("Database connection failed: " . ($conn ? $conn->connect_error : 'Unknown error'));
}

// --- Timezone & Session/URL Variables ---
date_default_timezone_set('Asia/Kuala_Lumpur');
$loggedInStaffID_Session = $_SESSION['staffid'] ?? null;
$staffIDFromURL = $_GET['staffid'] ?? null;
$staffIdForPageContext = $staffIDFromURL ?? $loggedInStaffID_Session;
$staffID = $staffIdForPageContext;
$current_page = basename($_SERVER['PHP_SELF']);

function addStaffIdToLink($url, $staffIdParam) {
    if ($staffIdParam !== null && $staffIdParam !== '') {
        $separator = (strpos($url, '?') === false) ? '?' : '&';
        return $url . $separator . 'staffid=' . urlencode($staffIdParam);
    }
    return $url;
}

// --- START: PATIENT REPORT GENERATION LOGIC ---
if (isset($_GET['action']) && $_GET['action'] === 'generate_patient_report' && isset($_GET['patient_id'])) {
    $reportPatientID = $_GET['patient_id'];
    $patientDetails = null;
    $edStays = [];

    // 1. Fetch Patient Details
    $stmt_patient = $conn->prepare("SELECT PatientID, PatientName, Gender, Phone_Number, Address FROM patients WHERE PatientID = ?");
    if ($stmt_patient) {
        $stmt_patient->bind_param("s", $reportPatientID);
        $stmt_patient->execute();
        $result_patient = $stmt_patient->get_result();
        $patientDetails = $result_patient->fetch_assoc();
        if($result_patient) $result_patient->free();
        $stmt_patient->close();
    } else {
        error_log("Error preparing patient details statement: " . $conn->error);
        die("Error preparing report (patient details).");
    }

    if ($patientDetails) {
        // 2. Fetch ED Stays for the patient
        $stmt_stays = $conn->prepare("SELECT StayID, Intime, Outime, StaffID AS AttendingEDStaffID, Arrival_transport, Disposition, Status FROM edstays WHERE PatientID = ? ORDER BY Intime DESC");
        if ($stmt_stays) {
            $stmt_stays->bind_param("s", $reportPatientID);
            $stmt_stays->execute();
            $result_stays = $stmt_stays->get_result();
            while ($stay = $result_stays->fetch_assoc()) {
                $stayData = $stay;
                $stayData['diagnoses'] = [];
                $stayData['triage'] = null;

                // 3. Fetch Diagnoses for each stay
                $stmt_dx = $conn->prepare("
                    SELECT
                        edx.ICD_Code,
                        d.ICD_Title,
                        edx.DiagnosisTimestamp,
                        edx.SeqNum,
                        s.Name as DiagnosingStaffName,
                        esr.Role as DiagnosingStaffRole
                    FROM edstays_diagnosis edx
                    JOIN diagnosis d ON edx.ICD_Code = d.ICD_Code
                    LEFT JOIN edstay_staff_roles esr ON edx.StayID = esr.StayID AND edx.SeqNum = esr.DiagnosisSeqNum
                    LEFT JOIN staff s ON esr.StaffID = s.StaffID
                    WHERE edx.StayID = ?
                    ORDER BY edx.SeqNum ASC, edx.DiagnosisTimestamp ASC
                ");
                if ($stmt_dx) {
                    $stmt_dx->bind_param("s", $stay['StayID']);
                    $stmt_dx->execute();
                    $result_dx_fetch = $stmt_dx->get_result();
                    while ($dx_row = $result_dx_fetch->fetch_assoc()) {
                        $stayData['diagnoses'][] = $dx_row;
                    }
                    if($result_dx_fetch) $result_dx_fetch->free();
                    $stmt_dx->close();
                } else {
                    error_log("Error preparing stay diagnoses statement (with staff roles): " . $conn->error);
                }

                // 4. Fetch Triage Info for each stay
                $stmt_triage = $conn->prepare("
                    SELECT et.ChiefComplaint, t.Temperature, t.Heartrate, t.Resprate, t.O2sat, t.SBP, t.DBP, t.Pain, t.Acuity
                    FROM edstays_triage et
                    JOIN triage t ON et.TriageID = t.TriageID
                    WHERE et.StayID = ? LIMIT 1
                ");
                if ($stmt_triage) {
                    $stmt_triage->bind_param("s", $stay['StayID']);
                    $stmt_triage->execute();
                    $result_triage = $stmt_triage->get_result();
                    $stayData['triage'] = $result_triage->fetch_assoc();
                    if($result_triage) $result_triage->free();
                    $stmt_triage->close();
                } else {
                     error_log("Error preparing triage statement: " . $conn->error);
                }
                $edStays[] = $stayData;
            }
            if($result_stays) $result_stays->free();
            $stmt_stays->close();
        } else {
            error_log("Error preparing patient stays statement: " . $conn->error);
        }

        // --- Generate CSV ---
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="patient_report_' . htmlspecialchars($reportPatientID) . '_' . date('Ymd_His') . '.csv"');
        $output = fopen('php://output', 'w');
        // ADD THIS LINE - Write UTF-8 BOM
fwrite($output, "\xEF\xBB\xBF"); // This is the UTF-8 BOM

        fputcsv($output, ['Patient Report: ' . htmlspecialchars($patientDetails['PatientName']) . ' (ID: ' . htmlspecialchars($patientDetails['PatientID']) . ')']);
        fputcsv($output, ['Gender:', htmlspecialchars($patientDetails['Gender'] ?? 'N/A')]);
 // MODIFIED LINE FOR PHONE NUMBER
$phoneNumberForCSV = htmlspecialchars($patientDetails['Phone_Number'] ?? 'N/A');
if (is_numeric(str_replace(['-', ' ', '(', ')', '+'], '', $phoneNumberForCSV)) && substr($phoneNumberForCSV, 0, 1) === '0') {
    // If it looks like a number and starts with 0, format for Excel
    fputcsv($output, ['Phone:', '="' . $phoneNumberForCSV . '"']);
} else {
    // Otherwise, output as is (fputcsv will handle normal quoting if needed)
    fputcsv($output, ['Phone:', $phoneNumberForCSV]);
}
        fputcsv($output, ['Address:', htmlspecialchars($patientDetails['Address'] ?? 'N/A')]);
        fputcsv($output, []);

        if (empty($edStays)) {
            fputcsv($output, ['No ED stay records found for this patient.']);
        } else {
            foreach ($edStays as $stay) {
                fputcsv($output, ['ED Stay ID:', htmlspecialchars($stay['StayID'])]);
                fputcsv($output, ['Arrival (In-Time):', $stay['Intime'] ? htmlspecialchars(date('d M Y, h:i A', strtotime($stay['Intime']))) : 'N/A']);
                fputcsv($output, ['Departure (Out-Time):', $stay['Outime'] ? htmlspecialchars(date('d M Y, h:i A', strtotime($stay['Outime']))) : 'N/A']);
                fputcsv($output, ['Attending ED Staff ID (Overall Stay):', htmlspecialchars($stay['AttendingEDStaffID'] ?? 'N/A')]);
                fputcsv($output, ['Arrival Transport:', htmlspecialchars($stay['Arrival_transport'] ?? 'N/A')]);
                fputcsv($output, ['Disposition:', htmlspecialchars($stay['Disposition'] ?? 'N/A')]);
                fputcsv($output, ['Status:', htmlspecialchars($stay['Status'] ?? 'N/A')]);
                fputcsv($output, []);

                if ($stay['triage']) {
                    fputcsv($output, ['Triage Information:']);
                    fputcsv($output, ['Chief Complaint:', htmlspecialchars($stay['triage']['ChiefComplaint'] ?? 'N/A')]);
                    fputcsv($output, ['Acuity:', htmlspecialchars($stay['triage']['Acuity'] ?? 'N/A')]);
                    fputcsv($output, ['Pain:', htmlspecialchars($stay['triage']['Pain'] ?? 'N/A')]);
                    fputcsv($output, ['Temp:', ($stay['triage']['Temperature'] ?? 'N/A') . ' °C', 'HR:', ($stay['triage']['Heartrate'] ?? 'N/A'). ' bpm', 'RR:', ($stay['triage']['Resprate'] ?? 'N/A'). ' rpm']);
                    fputcsv($output, ['O2 Sat:', ($stay['triage']['O2sat'] ?? 'N/A'). ' %', 'BP:', ($stay['triage']['SBP'] ?? 'N/A') . '/' . ($stay['triage']['DBP'] ?? 'N/A'). ' mmHg']);
                    fputcsv($output, []);
                } else {
                    fputcsv($output, ['Triage Information:', 'Not Available']);
                    fputcsv($output, []);
                }

                if (!empty($stay['diagnoses'])) {
                    fputcsv($output, ['Diagnoses for this Stay:']);
                    fputcsv($output, ['Seq#', 'ICD Code', 'ICD Title', 'Timestamp', 'Diagnosing Staff', 'Staff Role (at Dx)']);
                    foreach ($stay['diagnoses'] as $dx) {
                        fputcsv($output, [
                            htmlspecialchars($dx['SeqNum'] ?? 'N/A'),
                            htmlspecialchars($dx['ICD_Code']),
                            htmlspecialchars($dx['ICD_Title']),
                            $dx['DiagnosisTimestamp'] ? htmlspecialchars(date('d M Y, h:i A', strtotime($dx['DiagnosisTimestamp']))) : 'N/A',
                            htmlspecialchars($dx['DiagnosingStaffName'] ?? 'N/A'),
                            htmlspecialchars($dx['DiagnosingStaffRole'] ?? 'N/A')
                        ]);
                    }
                } else {
                    fputcsv($output, ['Diagnoses for this Stay:', 'No diagnoses recorded for this stay.']);
                }
                fputcsv($output, []);
                fputcsv($output, array_fill(0, 6, '---'));
                fputcsv($output, []);
            }
        }
        fclose($output);
        exit;
    } elseif (isset($_GET['action']) && $_GET['action'] === 'generate_patient_report') {
        die("Error: Could not generate report. Patient not found or invalid Patient ID specified.");
    }
}
// --- END: PATIENT REPORT GENERATION LOGIC ---


$query_error = null; // Reset query error for dashboard display
$patientsList = [];

// --- START: DASHBOARD KPIs AND DATA ---
$totalRegisteredPatients = 0;
$totalDiagnosesAssigned = 0;
$avgTimeToDiagnosisDisplay = 'N/A';
$mostFrequentDiagnosis = ['title' => 'N/A', 'count' => 0];
$patientsWaitingForDoctor = 0; // Initialize NEW KPI

try {
    // Fetch patients list for the report generator dropdown (always needed)
    $sql_patients_list = "SELECT PatientID, PatientName FROM patients ORDER BY PatientName ASC";
    $result_patients_list = $conn->query($sql_patients_list);
    if ($result_patients_list) {
        while ($row_pl = $result_patients_list->fetch_assoc()) {
            $patientsList[] = $row_pl;
        }
        $result_patients_list->free();
    } else {
        // Log error but don't throw, as the page might still be usable
        error_log("Error fetching patients list for report generator: " . $conn->error);
        $query_error .= " Error fetching patient list for report. ";
    }

    // KPI: Total Registered Patients
    $sql_total_patients = "SELECT COUNT(*) AS count FROM patients";
    $result_tp = $conn->query($sql_total_patients);
    if ($result_tp && $row_tp = $result_tp->fetch_assoc()) { $totalRegisteredPatients = (int)$row_tp['count']; }
    else { throw new Exception("KPI Total Patients: " . ($conn->error ?? 'Query failed')); }
    if($result_tp) $result_tp->free();

    // KPI: Total Diagnoses Assigned
    $sql_total_dx = "SELECT COUNT(*) AS count FROM edstays_diagnosis";
    $result_tdx = $conn->query($sql_total_dx);
    if ($result_tdx && $row_tdx = $result_tdx->fetch_assoc()) { $totalDiagnosesAssigned = (int)$row_tdx['count']; }
    else { throw new Exception("KPI Total Diagnoses Assigned: " . ($conn->error ?? 'Query failed')); }
    if($result_tdx) $result_tdx->free();

    // KPI: Patients Waiting for Doctor
    $sql_waiting_for_doctor = "SELECT COUNT(*) AS count 
                               FROM edstays 
                               WHERE Outime IS NULL 
                               AND Status = 'Waiting for Doctor'"; //  <-- ****** IMPORTANT: VERIFY THIS STATUS STRING ******
    $result_wfd = $conn->query($sql_waiting_for_doctor);
    if ($result_wfd && $row_wfd = $result_wfd->fetch_assoc()) {
        $patientsWaitingForDoctor = (int)$row_wfd['count'];
    } else {
        throw new Exception("KPI Waiting For Doctor: " . ($conn->error ?? 'Query failed'));
    }
    if($result_wfd) $result_wfd->free();


    // KPI: Average Time to 1st Diagnosis
    $sql_avg_time_dx = "
        SELECT AVG(TIME_TO_SEC(TIMEDIFF(MIN_DIAGNOSIS_TIMESTAMP, Intime))) AS AvgTimeToDiagnosisInSeconds
        FROM (
            SELECT es.Intime, MIN(edx.DiagnosisTimestamp) AS MIN_DIAGNOSIS_TIMESTAMP
            FROM edstays es JOIN edstays_diagnosis edx ON es.StayID = edx.StayID
            WHERE es.Intime IS NOT NULL AND edx.DiagnosisTimestamp IS NOT NULL AND edx.DiagnosisTimestamp >= es.Intime
            GROUP BY es.StayID, es.Intime HAVING MIN(edx.DiagnosisTimestamp) IS NOT NULL
        ) AS StayDiagnosisTimes;";
    $result_attd = $conn->query($sql_avg_time_dx);
    if ($result_attd && $row_attd = $result_attd->fetch_assoc()) {
        if ($row_attd['AvgTimeToDiagnosisInSeconds'] !== null) {
            $avgSeconds = (int)$row_attd['AvgTimeToDiagnosisInSeconds'];
            if ($avgSeconds >= 0) {
                $hours = floor($avgSeconds / 3600);
                $minutes = floor(($avgSeconds % 3600) / 60);
                $seconds = $avgSeconds % 60;
                if ($hours > 0) $avgTimeToDiagnosisDisplay = sprintf("%dhr %dmin", $hours, $minutes);
                elseif ($minutes > 0) $avgTimeToDiagnosisDisplay = sprintf("%dmin %dsec", $minutes, $seconds);
                else $avgTimeToDiagnosisDisplay = sprintf("%dsec", $seconds);
            } else { $avgTimeToDiagnosisDisplay = 'N/A'; }
        } else { $avgTimeToDiagnosisDisplay = 'N/A'; }
    } else { throw new Exception("KPI Avg Time to Dx: " . ($conn->error ?? 'Query failed')); }
    if($result_attd) $result_attd->free();

    // KPI: Most Frequent Diagnosis
    $sql_most_freq = "SELECT d.ICD_Title, COUNT(sd.ICD_Code) as diagnosis_count
            FROM edstays_diagnosis sd JOIN diagnosis d ON sd.ICD_Code = d.ICD_Code
            GROUP BY sd.ICD_Code, d.ICD_Title ORDER BY diagnosis_count DESC LIMIT 1";
    $result_most_freq = $conn->query($sql_most_freq);
    if ($result_most_freq && $row_mf = $result_most_freq->fetch_assoc()) {
        $mostFrequentDiagnosis['title'] = $row_mf['ICD_Title'];
        $mostFrequentDiagnosis['count'] = (int)$row_mf['diagnosis_count'];
    } elseif($result_most_freq && $result_most_freq->num_rows === 0) { $mostFrequentDiagnosis['title'] = 'None Assigned'; }
    elseif (!$result_most_freq) { throw new Exception("KPI Most Frequent Dx: " . ($conn->error ?? 'Query failed')); }
    if($result_most_freq) $result_most_freq->free();

    // Chart Data
    $patientGenderDataJson = '{"labels":[], "counts":[]}'; // Initialize
    $sql_gender = "SELECT Gender, COUNT(*) AS count FROM patients GROUP BY Gender";
    $result_gender = $conn->query($sql_gender); $gender_labels = []; $gender_counts_arr = [];
    if ($result_gender) { while ($row = $result_gender->fetch_assoc()) { $gender_labels[] = !empty($row['Gender']) ? $row['Gender'] : 'Unknown'; $gender_counts_arr[] = (int)$row['count']; } $result_gender->free(); }
    else { throw new Exception("Chart1 Gender Dist: " . ($conn->error ?? 'Query failed')); }
    $patientGenderDataJson = json_encode(['labels' => $gender_labels, 'counts' => $gender_counts_arr]);

    $topDiagnosesDataJson = '{"labels":[], "counts":[]}'; // Initialize
    $sql_top_dx = "SELECT d.ICD_Code, d.ICD_Title, COUNT(sd.ICD_Code) as diagnosis_count
                   FROM edstays_diagnosis sd JOIN diagnosis d ON sd.ICD_Code = d.ICD_Code
                   GROUP BY sd.ICD_Code, d.ICD_Title ORDER BY diagnosis_count DESC LIMIT 10";
    $result_top_dx = $conn->query($sql_top_dx); $topDxForChartLabels = []; $topDxForChartCounts = []; $topDxCodesForGenderChart = [];
    if ($result_top_dx) { $count = 0; while ($row = $result_top_dx->fetch_assoc()) { $topDxForChartLabels[] = $row['ICD_Title']; $topDxForChartCounts[] = (int)$row['diagnosis_count']; if ($count < 3) $topDxCodesForGenderChart[$row['ICD_Code']] = $row['ICD_Title']; $count++; } $result_top_dx->free(); }
    else { throw new Exception("Chart2 Top Dx: " . ($conn->error ?? 'Query failed')); }
    $topDiagnosesDataJson = json_encode(['labels' => $topDxForChartLabels, 'counts' => $topDxForChartCounts]);

    $edVisitDistributionJson = '{"labels":[], "counts":[]}'; // Initialize
    $sql_ed_visits = "SELECT VisitCategory, COUNT(PatientID) AS NumberOfPatients FROM (SELECT PatientID, CASE WHEN COUNT(StayID) = 1 THEN '1 Visit' WHEN COUNT(StayID) = 2 THEN '2 Visits' WHEN COUNT(StayID) = 3 THEN '3 Visits' WHEN COUNT(StayID) = 4 THEN '4 Visits' ELSE '5+ Visits' END AS VisitCategory FROM edstays GROUP BY PatientID) AS PatientVisitCounts GROUP BY VisitCategory ORDER BY CASE VisitCategory WHEN '1 Visit' THEN 1 WHEN '2 Visits' THEN 2 WHEN '3 Visits' THEN 3 WHEN '4 Visits' THEN 4 WHEN '5+ Visits' THEN 5 ELSE 6 END;";
    $result_ed_visits = $conn->query($sql_ed_visits); $edVisitLabels = []; $edVisitCounts = [];
    if ($result_ed_visits) { while ($row = $result_ed_visits->fetch_assoc()) { $edVisitLabels[] = $row['VisitCategory']; $edVisitCounts[] = (int)$row['NumberOfPatients']; } $result_ed_visits->free(); }
    else { throw new Exception("Chart3 ED Visit Dist: " . ($conn->error ?? 'Query failed')); }
    $edVisitDistributionJson = json_encode(['labels' => $edVisitLabels, 'counts' => $edVisitCounts]);

    $topDiagnosesByGenderJson = '{"diagnoses":[], "genders":[], "datasets":[]}'; // Initialize
    $topDiagnosesByGenderData = ['diagnoses' => [], 'genders' => [], 'datasets' => []];
    if (!empty($topDxCodesForGenderChart)) {
        $genderDataForAllDiagnoses = []; $allGendersEncountered = [];
        $placeholders = implode(',', array_fill(0, count($topDxCodesForGenderChart), '?'));
        $types = str_repeat('s', count($topDxCodesForGenderChart)); $bindNames = array_keys($topDxCodesForGenderChart);
        $sql_dx_gender = "SELECT sd.ICD_Code, p.Gender, COUNT(DISTINCT p.PatientID) AS PatientCount FROM edstays_diagnosis sd JOIN edstays es ON sd.StayID = es.StayID JOIN patients p ON es.PatientID = p.PatientID WHERE sd.ICD_Code IN ($placeholders) GROUP BY sd.ICD_Code, p.Gender ORDER BY sd.ICD_Code, p.Gender;";
        $stmt_dx_gender = $conn->prepare($sql_dx_gender);
        if ($stmt_dx_gender) {
            $stmt_dx_gender->bind_param($types, ...$bindNames); $stmt_dx_gender->execute();
            $result_dx_gender = $stmt_dx_gender->get_result();
            while ($row = $result_dx_gender->fetch_assoc()) {
                $gender = !empty($row['Gender']) ? $row['Gender'] : 'Unknown';
                if (stripos($gender, 'female') !== false) $gender = 'Female'; elseif (stripos($gender, 'male') !== false) $gender = 'Male';
                if (!in_array($gender, $allGendersEncountered)) $allGendersEncountered[] = $gender;
                if (!isset($genderDataForAllDiagnoses[$gender])) $genderDataForAllDiagnoses[$gender] = [];
                $genderDataForAllDiagnoses[$gender][$row['ICD_Code']] = (int)$row['PatientCount'];
            } $stmt_dx_gender->close(); if($result_dx_gender) $result_dx_gender->free();
            $topDiagnosesByGenderData['diagnoses'] = array_values($topDxCodesForGenderChart);
            usort($allGendersEncountered, function($a, $b) { if ($a == 'Male') return -1; if ($b == 'Male') return 1; if ($a == 'Female') return -1; if ($b == 'Female') return 1; return strcmp($a, $b); });
            $topDiagnosesByGenderData['genders'] = $allGendersEncountered;
            $genderColorMap = ['Male' => 'rgba(0, 123, 255, 0.75)', 'Female' => 'rgba(255, 99, 132, 0.75)'];
            $fallbackColors = ['rgba(32, 201, 151, 0.75)', 'rgba(255, 159, 64, 0.75)', 'rgba(153, 102, 255, 0.75)']; $fbIdx = 0;
            foreach ($allGendersEncountered as $gender) {
                $countsForThisGender = [];
                foreach ($topDxCodesForGenderChart as $icdCode => $title) $countsForThisGender[] = $genderDataForAllDiagnoses[$gender][$icdCode] ?? 0;
                $color = $genderColorMap[$gender] ?? $fallbackColors[$fbIdx++ % count($fallbackColors)];
                $topDiagnosesByGenderData['datasets'][] = ['label' => $gender, 'data' => $countsForThisGender, 'backgroundColor' => $color, 'borderWidth' => 1];
            }
        } else { throw new Exception("Chart4 Dx By Gender Prepare: " . ($conn->error ?? 'Query failed')); }
    }
    $topDiagnosesByGenderJson = json_encode($topDiagnosesByGenderData);

    $diagnosisPatientCountsTable = []; // Initialize
    $sql_diag_table = "SELECT d.ICD_Code, d.ICD_Title, d.ICD_version, COUNT(DISTINCT sd.StayID) as assigned_stay_count FROM diagnosis d LEFT JOIN edstays_diagnosis sd ON d.ICD_Code = sd.ICD_Code GROUP BY d.ICD_Code, d.ICD_Title, d.ICD_version ORDER BY assigned_stay_count DESC";
    $result_diag_table = $conn->query($sql_diag_table);
    if ($result_diag_table) { $diagnosisPatientCountsTable = $result_diag_table->fetch_all(MYSQLI_ASSOC); $result_diag_table->free(); }
    else { throw new Exception("Table1 Dx Counts: " . ($conn->error ?? 'Query failed')); }
    $itemsPerPage = 10; $totalDiagnosisEntries = count($diagnosisPatientCountsTable);
    $totalPages = $totalDiagnosisEntries > 0 ? ceil($totalDiagnosisEntries / $itemsPerPage) : 1;
    $currentPage = isset($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $currentPage = min($currentPage, $totalPages); $startIndex = ($currentPage - 1) * $itemsPerPage;
    $paginatedDiagnosisData = array_slice($diagnosisPatientCountsTable, $startIndex, $itemsPerPage);

} catch (Exception $e) {
    $query_error = ($query_error ? $query_error . " | " : "") . "Data Fetch Error: " . $e->getMessage();
    error_log("Dashboard Query Error: " . $e->getMessage());
    // Ensure JSON variables are initialized even on error to prevent JS issues
    if (!isset($patientGenderDataJson)) $patientGenderDataJson = '{"labels":[], "counts":[]}';
    if (!isset($topDiagnosesDataJson)) $topDiagnosesDataJson = '{"labels":[], "counts":[]}';
    if (!isset($edVisitDistributionJson)) $edVisitDistributionJson = '{"labels":[], "counts":[]}';
    if (!isset($topDiagnosesByGenderJson)) $topDiagnosesByGenderJson = '{"diagnoses":[], "genders":[], "datasets":[]}';
    if (!isset($avgTimeToDiagnosisDisplay)) $avgTimeToDiagnosisDisplay = 'Error';
    if (!isset($mostFrequentDiagnosis['title'])) $mostFrequentDiagnosis = ['title' => 'Error', 'count' => 0];
    if (!isset($patientsWaitingForDoctor)) $patientsWaitingForDoctor = 'Error';
}
// --- END: DASHBOARD KPIs AND DATA ---

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Diagnosis Dashboard - eDSS</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
   <style>
        :root { 
            --bs-primary-rgb: 0, 123, 255;
            --bs-success-rgb: 25, 135, 84;
            --bs-info-rgb: 13, 202, 240; /* Added for info card */
            --bs-warning-rgb: 255, 193, 7;
            --bs-purple-rgb: 111, 66, 193; /* For purple card */
        }
        body { font-family: 'Montserrat', sans-serif; background-color: #f8f9fa; padding-top: 80px; }
        .navbar { position: sticky; top: 0; z-index: 1030; background-color: white !important; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05); padding: 0.5rem 1rem; }
.navbar-brand {
    color: #007bff !important;
}
        .navbar .nav-link { color: #495057 !important; font-weight: 500; transition: color 0.2s ease; padding: 0.5rem 1rem; }
        .navbar .nav-link:hover, .navbar .nav-item.active .nav-link { color: var(--primary-color) !important; }
        .navbar .nav-item.active .nav-link { font-weight: 700 !important; }        .hero-section { background-color: rgb(var(--bs-primary-rgb)); color: white; padding: 40px 0; text-align: center; }
        .hero-section h1 { font-family: 'Montserrat', sans-serif; font-size: 2.2rem; font-weight: 700; color: white; text-transform: uppercase; }
        .hero-section p { font-size: 1rem; }
        .card { margin-top: 20px; box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08); border-radius: 8px; border: none; }
        .card-header { background-color: #e9ecef; border-bottom: 1px solid #dee2e6; font-weight: 600; padding: 0.75rem 1.1rem; font-size: 1rem; }
        .card-body { padding: 1.25rem; }
        .chart-container { position: relative; margin: auto; height: 330px; width: 100%; }
        .stat-card .card-text { font-size: 1.8rem; font-weight: bold; color: rgb(var(--bs-primary-rgb)); }
        .stat-card .card-text.small-text { font-size: 1.4rem; }
        .stat-card .card-title { font-size: 0.85rem; font-weight: 600; color: #6c757d; text-transform: uppercase; line-height: 1.2; margin-bottom: 0.3rem;}
        .card.border-left-primary { border-left: 5px solid rgb(var(--bs-primary-rgb)) !important; }
        .card.border-left-success { border-left: 5px solid rgb(var(--bs-success-rgb)) !important; }
        .card.border-left-info { border-left: 5px solid rgb(var(--bs-info-rgb)) !important; } /* Added */
        .card.border-left-purple { border-left: 5px solid rgb(var(--bs-purple-rgb)) !important; }
        .card.border-left-warning { border-left: 5px solid rgb(var(--bs-warning-rgb)) !important; }
        .table th, .table td { font-size: 0.9rem; }
        footer { background-color: rgb(var(--bs-primary-rgb)); color: white; padding: 20px 0; text-align: center; margin-top: 40px; }
        @media (max-width: 991.98px) { .navbar-brand[style*="position: absolute"] { position: relative !important; top: auto !important; left: auto !important; margin-right: auto; } .navbar-collapse { width: 100%; } .navbar-nav { width: 100%; text-align: center; } }
        @media (max-width: 768px) { .hero-section h1 {font-size: 1.8rem;} .chart-container { height: 280px; } }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light fixed-top" style="background-color: white; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
        <div class="container">
            <?php $staffIdToUseInNavbarLinks = $staffID; ?>
<a class="navbar-brand" href="<?php echo addStaffIdToLink('https://localhost/dss/staff/doctor/DoctorLandingPage.php', $staffIdToUseInNavbarLinks); ?>" style="font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 700; position: absolute; top: 10px; left: 15px;">eDSS</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation" style="margin-left: auto;">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item <?php echo ($current_page == 'index.php') ? 'active' : ''; ?>">
                        <a class="nav-link" href="<?php echo addStaffIdToLink('index.php', $staffIdToUseInNavbarLinks); ?>" style="color: black;">Home</a>
                    </li>
                    <li class="nav-item <?php echo ($current_page == 'register_patient.php') ? 'active' : ''; ?>">
                        <a class="nav-link" href="<?php echo addStaffIdToLink('register_patient.php', $staffIdToUseInNavbarLinks); ?>" style="color: black;">Patient Registration</a>
                    </li>
                    <li class="nav-item <?php echo ($current_page == 'assign_ed_diagnosis.php') ? 'active' : ''; ?>">
                        <a class="nav-link" href="<?php echo addStaffIdToLink('assign_ed_diagnosis.php', $staffIdToUseInNavbarLinks); ?>" style="color: black;">Patient Diagnosis</a>
                    </li>
                    <li class="nav-item <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
                        <a class="nav-link" href="<?php echo addStaffIdToLink('dashboard.php', $staffIdToUseInNavbarLinks); ?>" style="color: black;">Real-Time Dashboards</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
<style>
    .navbar .nav-link:hover { color: #007bff !important; }
    .navbar .nav-item.active .nav-link { color: #007bff !important; font-weight: bold !important; }
</style>
    <div class="hero-section">
        <h1>Patient & Diagnosis Dashboard</h1>
        <p>Overview of Patient Demographics and Diagnostic Trends</p>
    </div>

    <div class="container mt-4">
         <?php if (!empty($query_error)): ?>
            <div class="alert alert-danger"><h5 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Data Fetch Error!</h5><p>Some dashboard components might not display correctly. Details: <?php echo htmlspecialchars(trim($query_error)); ?></p></div>
        <?php endif; ?>

        <!-- KPI Cards Row -->
        <div class="row mb-4">
            <div class="col-lg col-md-6 mb-4">
                <div class="card border-left-primary shadow h-100 py-2 stat-card">
                    <div class="card-body"><div class="row no-gutters align-items-center"><div class="col mr-2">
                        <div class="card-title text-primary">Total Patients</div>
                        <div class="card-text"><?php echo $totalRegisteredPatients; ?></div>
                    </div><div class="col-auto"><i class="fas fa-users fa-2x text-gray-300"></i></div></div></div>
                </div>
            </div>
            <div class="col-lg col-md-6 mb-4">
                <div class="card border-left-success shadow h-100 py-2 stat-card">
                     <div class="card-body"><div class="row no-gutters align-items-center"><div class="col mr-2">
                        <div class="card-title text-success">Total Diagnoses Assigned</div>
                        <div class="card-text"><?php echo $totalDiagnosesAssigned; ?></div>
                    </div><div class="col-auto"><i class="fas fa-file-medical-alt fa-2x text-gray-300"></i></div></div></div>
                </div>
            </div>

            <!-- NEW KPI CARD: Waiting for Doctor -->
            <div class="col-lg col-md-6 mb-4">
                <div class="card border-left-info shadow h-100 py-2 stat-card">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="card-title text-info">Waiting for Doctor</div>
                                <div class="card-text" style="color: rgb(var(--bs-info-rgb));"><?php echo $patientsWaitingForDoctor; ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-user-clock fa-2x text-gray-300"></i> <!-- Changed icon -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- END NEW KPI CARD -->

            <div class="col-lg col-md-6 mb-4">
                <div class="card border-left-purple shadow h-100 py-2 stat-card">
                    <div class="card-body"><div class="row no-gutters align-items-center"><div class="col mr-2">
                        <div class="card-title" style="color: rgb(var(--bs-purple-rgb));">Avg. Time to 1st Diagnosis</div>
                        <div class="card-text small-text" style="color: rgb(var(--bs-purple-rgb));"><?php echo $avgTimeToDiagnosisDisplay; ?></div>
                    </div><div class="col-auto"><i class="fas fa-stopwatch fa-2x text-gray-300"></i></div></div></div>
                </div>
            </div>
            <div class="col-lg col-md-6 mb-4">
                <div class="card border-left-warning shadow h-100 py-2 stat-card">
                    <div class="card-body"><div class="row no-gutters align-items-center"><div class="col mr-2">
                        <div class="card-title text-warning">Most Frequent Diagnosis</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800" style="font-size: 1.1rem; line-height:1.3;"><?php echo htmlspecialchars($mostFrequentDiagnosis['title']); ?></div>
                        <div class="text-xs text-muted">(<?php echo $mostFrequentDiagnosis['count']; ?> assignments)</div>
                    </div><div class="col-auto"><i class="fas fa-stethoscope fa-2x text-gray-300"></i></div></div></div>
                </div>
            </div>
        </div>

        <!-- Charts Row 1 -->
        <div class="row">
            <div class="col-lg-4 mb-4">
                <div class="card h-100">
                    <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-venus-mars me-2"></i>Patient Gender Distribution</h6></div>
                    <div class="card-body"><div class="chart-container"><canvas id="patientGenderChart"></canvas></div></div>
                </div>
            </div>
            <div class="col-lg-8 mb-4">
                 <div class="card h-100">
                    <div class="card-header py-3"><h6 class="m-0 font-weight-bold" style="color: rgb(var(--bs-success-rgb));"><i class="fas fa-notes-medical me-2"></i>Top 10 Assigned Diagnoses</h6></div>
                    <div class="card-body"><div class="chart-container"><canvas id="topDiagnosesChart"></canvas></div></div>
                </div>
            </div>
        </div>

         <!-- Charts Row 2 -->
        <div class="row">
            <div class="col-lg-5 mb-4">
                 <div class="card h-100">
                    <div class="card-header py-3"><h6 class="m-0 font-weight-bold" style="color: #fd7e14;"><i class="fas fa-hospital-user me-2"></i>Patient ED Visit Frequency</h6></div>
                    <div class="card-body"><div class="chart-container"><canvas id="edVisitDistributionChart"></canvas></div></div>
                </div>
            </div>
            <div class="col-lg-7 mb-4">
                 <div class="card h-100">
                    <div class="card-header py-3"><h6 class="m-0 font-weight-bold" style="color: rgb(var(--bs-purple-rgb));"><i class="fas fa-user-md me-2"></i>Top 3 Diagnoses by Gender</h6></div>
                    <div class="card-body"><div class="chart-container"><canvas id="topDiagnosesByGenderChart"></canvas></div></div>
                </div>
            </div>
        </div>

        <!-- START: Generate Patient Report Section -->
        <div class="row mt-4 mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header"><i class="fas fa-file-csv me-2"></i>Generate Patient Report (CSV)</div>
                    <div class="card-body">
                        <form method="GET" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                            <input type="hidden" name="action" value="generate_patient_report">
                            <?php if ($staffID): ?>
                                <input type="hidden" name="staffid" value="<?php echo htmlspecialchars($staffID); ?>">
                            <?php endif; ?>
                            <div class="row align-items-end">
                                <div class="col-md-6 mb-3">
                                    <label for="report_patient_id" class="form-label">Select Patient:</label>
                                    <select name="patient_id" id="report_patient_id" class="form-select" required>
                                        <option value="">-- Select a Patient --</option>
                                        <?php if (!empty($patientsList)): ?>
                                            <?php foreach ($patientsList as $patient): ?>
                                                <option value="<?php echo htmlspecialchars($patient['PatientID']); ?>">
                                                    <?php echo htmlspecialchars($patient['PatientName']) . " (ID: " . htmlspecialchars($patient['PatientID']) . ")"; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="" disabled>No patients found to generate report</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-download me-2"></i>Generate Report</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <!-- END: Generate Patient Report Section -->

        <!-- Diagnosis Table -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header"><i class="fas fa-list-alt me-2"></i>Diagnosis Assignment Counts (Unique Stays)</div>
                    <div class="card-body">
                       <div class="table-responsive">
                            <table class="table table-striped table-hover table-sm">
                                <thead>
                                    <tr><th>ICD Code</th><th>ICD Title</th><th>Version</th><th class="text-center"># ED Stays Assigned To</th></tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($paginatedDiagnosisData)): ?>
                                        <?php foreach($paginatedDiagnosisData as $diagData): ?>
                                          <tr><td><?php echo htmlspecialchars($diagData['ICD_Code']); ?></td><td><?php echo htmlspecialchars($diagData['ICD_Title']); ?></td><td><?php echo htmlspecialchars($diagData['ICD_version']); ?></td><td class="text-center"><?php echo (int)$diagData['assigned_stay_count']; ?></td></tr>
                                        <?php endforeach; ?>
                                     <?php else: ?>
                                        <tr><td colspan="4" class="text-center text-muted">No diagnosis data found.</td></tr>
                                     <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                         <?php if ($totalPages > 1): ?>
                         <nav aria-label="Diagnosis Pagination" class="mt-3">
                             <ul class="pagination justify-content-center">
                                 <li class="page-item <?php echo ($currentPage <= 1) ? 'disabled' : ''; ?>"><a class="page-link" href="?page=<?php echo $currentPage - 1; ?>&staffid=<?php echo urlencode($staffID); ?>">«</a></li>
                                 <?php $range = 2; $start = max(1, $currentPage - $range); $end = min($totalPages, $currentPage + $range); if ($start > 1) { echo '<li class="page-item"><a class="page-link" href="?page=1&staffid='.urlencode($staffID).'">1</a></li>'; if ($start > 2) { echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; } } for ($i = $start; $i <= $end; $i++): ?> <li class="page-item <?php echo ($i == $currentPage) ? 'active' : ''; ?>"><a class="page-link" href="?page=<?php echo $i; ?>&staffid=<?php echo urlencode($staffID); ?>"><?php echo $i; ?></a></li> <?php endfor; if ($end < $totalPages) { if ($end < $totalPages - 1) { echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; } echo '<li class="page-item"><a class="page-link" href="?page='.$totalPages.'&staffid='.urlencode($staffID).'">'.$totalPages.'</a></li>'; } ?>
                                 <li class="page-item <?php echo ($currentPage >= $totalPages) ? 'disabled' : ''; ?>"><a class="page-link" href="?page=<?php echo $currentPage + 1; ?>&staffid=<?php echo urlencode($staffID); ?>">»</a></li>
                             </ul>
                         </nav>
                         <?php endif; ?>
                    </div>
                  </div>
            </div>
         </div>
    </div>


<script>
document.addEventListener('DOMContentLoaded', function () {
    const rootStyles = getComputedStyle(document.documentElement);
    const primaryColorRGB = rootStyles.getPropertyValue('--bs-primary-rgb').trim();
    const successColorRGB = rootStyles.getPropertyValue('--bs-success-rgb').trim();
    const infoColorRGB = rootStyles.getPropertyValue('--bs-info-rgb').trim();
    const warningColorRGB = rootStyles.getPropertyValue('--bs-warning-rgb').trim();
    const purpleColorRGB = rootStyles.getPropertyValue('--bs-purple-rgb').trim();

    const weCareColors = {
        blue: `rgba(${primaryColorRGB}, 0.75)`, pink: 'rgba(255, 99, 132, 0.75)', teal: 'rgba(32, 201, 151, 0.75)',
        orange: 'rgba(253, 126, 20, 0.75)', purple: `rgba(${purpleColorRGB}, 0.75)`, yellow: `rgba(${warningColorRGB}, 0.75)`,
        gray: 'rgba(108, 117, 125, 0.75)', greenBS: `rgba(${successColorRGB}, 0.75)`, lime: 'rgba(160, 200, 70, 0.75)',
        skyBlue: 'rgba(135, 206, 235, 0.75)', info: `rgba(${infoColorRGB}, 0.75)`
    };
    const weCareBorders = {
        blue: `rgb(${primaryColorRGB})`, pink: 'rgb(255, 99, 132)', teal: 'rgb(32, 201, 151)',
        orange: 'rgb(253, 126, 20)', purple: `rgb(${purpleColorRGB})`, yellow: `rgb(${warningColorRGB})`,
        gray: 'rgb(108, 117, 125)', greenBS: `rgb(${successColorRGB})`, lime: 'rgb(160, 200, 70)',
        skyBlue: 'rgb(135, 206, 235)', info: `rgb(${infoColorRGB})`
    };

    function drawChartOrMessage(canvasId, chartConfig, noDataMessage) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) { console.error("Canvas element not found:", canvasId); return; }
        const ctx = canvas.getContext('2d');

        const existingChart = Chart.getChart(canvas);
        if (existingChart) { existingChart.destroy(); }

        const hasData = chartConfig.data.datasets.some(ds => ds.data && ds.data.length > 0 && ds.data.some(val => (typeof val === 'number' && val > 0) || (typeof val === 'object' && val !== null) ));
        
        if (ctx && chartConfig.data.labels && chartConfig.data.labels.length > 0 && hasData) { 
            new Chart(ctx, chartConfig); 
        } else if (ctx) {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.font = '14px Montserrat'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillStyle = '#6c757d';
            let message = noDataMessage || 'No data available for this chart.'; let words = message.split(' '); let line = '';
            let y = canvas.height / 2 - ((message.split('\n').length -1) * 10); let maxLineWidth = canvas.width * 0.8;
            for(let n = 0; n < words.length; n++) { let testLine = line + words[n] + ' '; let metrics = ctx.measureText(testLine); let testWidth = metrics.width; if (testWidth > maxLineWidth && n > 0) { ctx.fillText(line, canvas.width / 2, y); line = words[n] + ' '; y += 20; } else { line = testLine; } }
            ctx.fillText(line, canvas.width / 2, y);
        } else { console.error("Ctx or ChartConfig issue for: " + noDataMessage, canvasId, chartConfig); }
    }

    const patientGenderDataPHP = <?php echo $patientGenderDataJson; ?>;
    let assignedGenderColors = [weCareColors.blue, weCareColors.pink, weCareColors.yellow, weCareColors.gray, weCareColors.teal];
    if (patientGenderDataPHP && patientGenderDataPHP.labels && patientGenderDataPHP.labels.length > 0) { assignedGenderColors = patientGenderDataPHP.labels.map((label, index) => { const lowerLabel = label.toLowerCase(); if (lowerLabel.includes('female')) return weCareColors.pink; if (lowerLabel.includes('male')) return weCareColors.blue; return assignedGenderColors[index % assignedGenderColors.length]; }); }
    drawChartOrMessage('patientGenderChart', { type: 'doughnut', data: { labels: patientGenderDataPHP.labels, datasets: [{ label: 'Patients', data: patientGenderDataPHP.counts, backgroundColor: assignedGenderColors, borderColor: '#fff', borderWidth: 2 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels:{padding:15} } } } }, 'No patient gender data recorded.');

    const topDiagnosesDataPHP = <?php echo $topDiagnosesDataJson; ?>;
    drawChartOrMessage('topDiagnosesChart', { type: 'bar', data: { labels: topDiagnosesDataPHP.labels, datasets: [{ label: 'Number of ED Stays', data: topDiagnosesDataPHP.counts, backgroundColor: weCareColors.greenBS, borderColor: weCareBorders.greenBS, borderWidth: 1 }] }, options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: { x: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } } }, plugins: { legend: { display: false } } } }, 'No diagnosis assignments found.');

    const edVisitDataPHP = <?php echo $edVisitDistributionJson; ?>;
    const edVisitBarColors = [weCareColors.teal, weCareColors.skyBlue, weCareColors.lime, weCareColors.yellow, weCareColors.orange];
    const edVisitBarBorders = [weCareBorders.teal, weCareBorders.skyBlue, weCareBorders.lime, weCareBorders.yellow, weCareBorders.orange];
    drawChartOrMessage('edVisitDistributionChart', { type: 'bar', data: { labels: edVisitDataPHP.labels, datasets: [{ label: 'Number of Patients', data: edVisitDataPHP.counts, backgroundColor: edVisitBarColors.slice(0, edVisitDataPHP.labels.length), borderColor: edVisitBarBorders.slice(0, edVisitDataPHP.labels.length), borderWidth: 1 }] }, options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { display: false } } } }, 'No ED visit data available.');

    const topDxGenderDataPHP = <?php echo $topDiagnosesByGenderJson; ?>;
    drawChartOrMessage('topDiagnosesByGenderChart', { type: 'bar', data: { labels: topDxGenderDataPHP.diagnoses, datasets: topDxGenderDataPHP.datasets }, options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { position: 'bottom', labels:{padding:15} } } } }, 'Insufficient data for diagnoses by gender comparison.');
});
</script>
</body>
</html>