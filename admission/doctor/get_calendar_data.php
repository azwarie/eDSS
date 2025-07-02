<?php
// --- get_calendar_data.php ---

// Set the correct timezone at the very top
date_default_timezone_set('Asia/Kuala_Lumpur'); // <--- IMPORTANT: Set your correct timezone

error_reporting(E_ALL);
ini_set('display_errors', 1); // Keep for debugging, disable in production

header('Content-Type: application/json'); // Set JSON header

// Include the SINGLE database connection ($conn to azwarie_dss)
include('connection.php');

// Initialize the response structure
$response = [
    'success' => false,
    'totalBeds' => 0,
    'admissionData' => [],       // Holds {date, patientName, diagnosisName, predictedDischargeDate} for active patients
    'dischargeData' => [],       // Holds {date, patientName} - For ACTUAL discharges
    'activeAdmissions' => 0,     // Active count at time of script run (used for prediction starting point)
    'dailyChanges' => [],        // Holds {'YYYY-MM-DD': {admissions: N, discharges: M}} (based on actual events)
    'error' => null
];

// Check connection
if (!$conn) {
    $response['error'] = "Database connection failed.";
    echo json_encode($response);
    exit;
}

try {
    // --- 1. Get Simple Counts ---

    // Total Beds (assuming C006 is Bed Category)
    $totalBedsResult = $conn->query("SELECT COUNT(b.BedID) as total FROM BED b LEFT JOIN inventory i ON b.InventoryID = i.inventoryID WHERE i.CategoryID = 'C006'");
    if ($totalBedsResult && $row = $totalBedsResult->fetch_assoc()) {
        $response['totalBeds'] = (int)$row['total'];
    } else { throw new Exception("Failed to get total beds count: " . $conn->error); }
    if ($totalBedsResult) $totalBedsResult->free();

    // Current Active Admissions Count (where discharge IS NULL)
    $activeAdmissionsResult = $conn->query("SELECT COUNT(*) as active FROM ADMISSION WHERE DischargeDateTime IS NULL");
    if ($activeAdmissionsResult && $row = $activeAdmissionsResult->fetch_assoc()) {
        $response['activeAdmissions'] = (int)$row['active'];
    } else { throw new Exception("Failed to get active admissions count: " . $conn->error); }
    if ($activeAdmissionsResult) $activeAdmissionsResult->free();

    // --- 2. Get Detailed Admission Data (Active Patients) for Calendar Display & Prediction ---
    // Define date range for fetching relevant data
    $date_limit_past = date('Y-m-d', strtotime('-1 year')); // Look back 1 year for context/changes
    // No future limit needed for fetching active admissions

    $sql_admission_details = "
        SELECT
            a.AdmissionID,
            a.AdmissionDateTime,           -- Full timestamp needed for accurate calculation
            a.ReasonForAdmission,
            p.PatientName,
            d.DiagnosisName,
            d.EstimatedLengthOfStay        -- Ensure this column name is correct
        FROM
            ADMISSION a
        LEFT JOIN
            patients p ON a.PatientID = p.PatientID
        LEFT JOIN
            DIAGNOSIS d ON p.DiagnosisID = d.DiagnosisID -- Verify this JOIN is correct
        WHERE
            a.DischargeDateTime IS NULL -- CRITICAL: Only currently admitted patients need predictions
        ORDER BY
            a.AdmissionDateTime ASC"; // Order doesn't strictly matter but can be helpful

    $admissionDetailsResult = $conn->query($sql_admission_details);
    if (!$admissionDetailsResult) {
        throw new Exception("Failed to get current admission details: " . $conn->error);
    }

    $tempAdmissionData = []; // Holds processed data for active patients

    while ($row = $admissionDetailsResult->fetch_assoc()) {
        $admissionTimestamp = $row['AdmissionDateTime'];
        $patientName = $row['PatientName'] ?? ('Patient (' . ($row['PatientID'] ?? 'N/A') . ')');
        $diagnosisDisplay = $row['DiagnosisName'] ?? $row['ReasonForAdmission'] ?? 'Unknown Diagnosis';
        $estimatedStayDays = isset($row['EstimatedLengthOfStay']) ? (int)$row['EstimatedLengthOfStay'] : 0;

        $admissionDateOnly = null;
        $admissionDateTimeObj = null;

        if ($admissionTimestamp) {
            try {
                $admissionDateTimeObj = new DateTime($admissionTimestamp, new DateTimeZone(date_default_timezone_get()));
                $admissionDateOnly = $admissionDateTimeObj->format('Y-m-d');
            } catch (Exception $e) {
                 error_log("DateTime error parsing admission date " . $admissionTimestamp . ": " . $e->getMessage());
                 continue;
            }
        } else {
             error_log("Warning: AdmissionDateTime is NULL for AdmissionID " . ($row['AdmissionID'] ?? 'N/A'));
             continue;
        }

        $predictedDischargeDate = null;

        // --- Prediction Calculation Block (Using DateInterval) ---
        if ($estimatedStayDays > 0 && $admissionDateTimeObj !== null) {
            // Optional: Keep the detailed logging from previous step if still needed
            // error_log("--- Pre-Calculation Check (DateInterval) ---"); ... etc ...

            try {
                $dischargeDateTimeObj = clone $admissionDateTimeObj;
                $interval_spec = 'P' . $estimatedStayDays . 'D';
                // error_log("Attempting: \$dischargeDateTimeObj->add(new DateInterval('{$interval_spec}'))");
                $interval = new DateInterval($interval_spec);
                $dischargeDateTimeObj->add($interval);
                $predictedDischargeDate = $dischargeDateTimeObj->format('Y-m-d H:i:s');
                // error_log("  -> Calculated Predicted Discharge (Using DateInterval): {$predictedDischargeDate}");

            } catch (Exception $e) {
                error_log("!!! DateTime calculation EXCEPTION (DateInterval) for {$patientName}: " . $e->getMessage());
                $predictedDischargeDate = null;
            }
        } else {
             // Optional: Keep skip log if needed
             // error_log("Skipping prediction for Patient: {$patientName} (LOS={$estimatedStayDays}, ...)");
        }
        // --- End Prediction Calculation Block ---


        // --- MODIFIED PART: Add losDaysUsed to the output array ---
        $tempAdmissionData[] = [
            'date' => $admissionDateOnly,             // Admission date part (YYYY-MM-DD)
            'patientName' => $patientName,
            'diagnosisName' => $diagnosisDisplay,      // Diagnosis/Reason for tooltip
            'predictedDischargeDate' => $predictedDischargeDate, // Predicted date (YYYY-MM-DD HH:MM:SS) or null
            'losDaysUsed' => $estimatedStayDays        // ADDED: The actual LOS value used
        ];
        // --- END MODIFIED PART ---

    } // --- End of while loop ---
    $admissionDetailsResult->free();

    // Assign the final processed data for active patients
    $response['admissionData'] = $tempAdmissionData;


    // --- 3. Fetch ACTUAL Discharge Data for Calendar Markers ---
    $sql_actual_discharges = "
        SELECT
            DATE(pa.DischargeDateTime) as date, -- Just the date part
            p.PatientName
        FROM
            PAST_ADMISSION pa
        LEFT JOIN
            patients p ON pa.PatientID = p.PatientID
        WHERE
            pa.DischargeDateTime IS NOT NULL
          AND pa.DischargeDateTime >= ? -- Use date range parameter ($date_limit_past)
        ORDER BY
            pa.DischargeDateTime DESC";

    $stmt_actual_discharge = $conn->prepare($sql_actual_discharges);
    if (!$stmt_actual_discharge) {
         error_log("Prepare failed (actual discharges): " . $conn->error);
         // Continue execution, but actual discharges might be missing
    } else {
        $stmt_actual_discharge->bind_param("s", $date_limit_past);
        $stmt_actual_discharge->execute();
        $actualDischargeResult = $stmt_actual_discharge->get_result();
        if (!$actualDischargeResult) {
            error_log("Failed to get actual discharge details: " . $stmt_actual_discharge->error);
        } else {
            while ($row = $actualDischargeResult->fetch_assoc()) {
                $response['dischargeData'][] = [
                    'date' => $row['date'], // YYYY-MM-DD
                    'patientName' => $row['PatientName'] ?? 'Unknown Patient'
                ];
            }
            $actualDischargeResult->free();
        }
        $stmt_actual_discharge->close();
    }


    // --- 4. Calculate Daily Changes (Admissions and ACTUAL Discharges) ---
    // Used by JS to predict day-to-day occupancy changes based on past events
    $sql_daily_changes = "
        SELECT Date, SUM(Admissions) as Admissions, SUM(Discharges) as Discharges
        FROM (
            -- Count Admissions
            SELECT DATE(AdmissionDateTime) as Date, 1 as Admissions, 0 as Discharges
            FROM ADMISSION
            WHERE AdmissionDateTime IS NOT NULL AND AdmissionDateTime >= ?

            UNION ALL

            -- Count ACTUAL Discharges from PAST_ADMISSION
            SELECT DATE(DischargeDateTime) as Date, 0 as Admissions, 1 as Discharges
            FROM PAST_ADMISSION
            WHERE DischargeDateTime IS NOT NULL AND DischargeDateTime >= ?
        ) as DailyEvents
        WHERE Date IS NOT NULL
        GROUP BY Date
        ORDER BY Date";

    $stmt_daily = $conn->prepare($sql_daily_changes);
     if (!$stmt_daily) {
         error_log("Prepare failed (daily changes): " . $conn->error);
          // Continue execution, but occupancy prediction might be less accurate
     } else {
         $stmt_daily->bind_param("ss", $date_limit_past, $date_limit_past);
         $stmt_daily->execute();
         $dailyResult = $stmt_daily->get_result();
         if (!$dailyResult) {
             error_log("Failed to get daily changes: " . $stmt_daily->error);
         } else {
             while ($row = $dailyResult->fetch_assoc()) {
                 // Store changes keyed by date 'YYYY-MM-DD'
                 $response['dailyChanges'][$row['Date']] = [
                     'admissions' => (int)$row['Admissions'],
                     'discharges' => (int)$row['Discharges'] // These are ACTUAL discharges
                 ];
             }
             $dailyResult->free();
         }
         $stmt_daily->close();
     }

    // If we reached here without critical exceptions during counts/main query, mark as success
    $response['success'] = true;

} catch (Exception $e) {
    // Catch critical exceptions
    $response['error'] = "Server Error processing calendar data."; // Generic error for user
    error_log("Critical Error in get_calendar_data.php: " . $e->getMessage()); // Log the detailed error
    $response['success'] = false; // Ensure success is explicitly false
}

// --- Clean up and Output ---
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close(); // Close the database connection
}

// Output the final JSON response
echo json_encode($response);
exit; // Ensure no further output
?>