<?php
session_start(); // Start session if needed
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include the SINGLE database connection ($conn to azwarie_dss)
include 'connection.php';



// Initialize variables
$new_admission_id = '';
$reason_for_admission = '';
$bed_id = '';
$patient_id = '';
$success_message = '';
$discharge_error_message = '';
$error_message = '';
$staffID = null;      // Staff ID from URL param
$staff_status = null; // Staff status fetched from DB
$staff_name = null;   // Staff name fetched from DB
$staff_id_db = null;  // Staff ID fetched from DB (to use internally)
$search_query = '';   // For patient search

// --- Get StaffID from URL ---
if (isset($_GET['staffid'])) {
    $staffID = $_GET['staffid']; // Raw ID from URL
    // You should add validation for staffID format here if needed
}

// --- Function to generate PastAdmissionID ---
function generatePastAdmissionID($db_connection) {
    $current_date = date('my'); // Format: MMYY
    $prefix = "PA" . $current_date; // Prefix: PAMMYY

    $sql_max_id = "SELECT MAX(PastAdmissionID) AS max_id FROM PAST_ADMISSION WHERE PastAdmissionID LIKE ?";
    $like_prefix = $prefix . '%';
    $stmt = $db_connection->prepare($sql_max_id);
    if (!$stmt) {
        error_log("Prepare failed (generatePastAdmissionID): (" . $db_connection->errno . ") " . $db_connection->error);
        return $prefix . '001'; // Fallback
    }
    $stmt->bind_param('s', $like_prefix);
    $stmt->execute();
    $result = $stmt->get_result();
    $max_id = null;
    if ($result && $row = $result->fetch_assoc()) {
        $max_id = $row['max_id'];
    }
    $stmt->close();
    if ($result) $result->free(); // Free result

    if ($max_id) {
        $numeric_part_str = substr($max_id, -3);
        if (ctype_digit($numeric_part_str)) {
             $numeric_part = (int)$numeric_part_str;
             $new_numeric_part = $numeric_part + 1;
             return $prefix . str_pad($new_numeric_part, 3, '0', STR_PAD_LEFT);
        }
    }
    return $prefix . '001';
}


// --- Fetch staff details if staffID is set ---
if ($staffID) {
    $staff_sql = "SELECT StaffID, Name, Status FROM STAFF WHERE StaffID = ?";
    $stmt_staff = $conn->prepare($staff_sql);
    if ($stmt_staff) {
        $stmt_staff->bind_param('s', $staffID);
        $stmt_staff->execute();
        $staff_result = $stmt_staff->get_result();
        if ($staff_result && $staff_row = $staff_result->fetch_assoc()) {
            $staff_status = $staff_row['Status'];
            $staff_id_db = $staff_row['StaffID'];
            $staff_name = $staff_row['Name'];
        } else {
            // $error_message = "Staff ID '$staffID' not found or invalid."; // Don't set error here, might override others
            $staffID = null;
            $staff_id_db = null;
        }
        if ($staff_result) $staff_result->free();
        $stmt_staff->close();
    } else {
         $error_message = "Error preparing staff query: " . $conn->error;
         $staffID = null;
         $staff_id_db = null;
    }
} else {
     // $error_message = "Staff ID not provided in URL."; // Don't necessarily show error if not required for viewing
}


// --- Handle Form Submission for Patient Coordination ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_patient']) && $staff_id_db) { // Only proceed if staff is valid

    $reason_for_admission_post = $_POST['reason_for_admission'] ?? '';
    $bed_id_post = $_POST['bed_id'] ?? null;
    $patient_id_post = $_POST['patient_id'] ?? null;
    $staff_id_to_use = $staff_id_db;

    if (empty($patient_id_post)) {
        $error_message = "Please select a patient.";
    } elseif (empty($bed_id_post)) {
        $error_message = "Please select an available bed.";
    } elseif (empty($staff_id_to_use)) {
         $error_message = "Invalid Staff ID associated with the admission.";
    } elseif ($staff_status !== 'Available') {
        $error_message = "Cannot admit patient. Your current status ($staff_status) is not 'Available'.";
    } else {
        // --- Generate New Admission ID ---
        $sql_max_id = "SELECT MAX(AdmissionID) AS max_id FROM ADMISSION";
        $result_max_id = $conn->query($sql_max_id); // Use $conn
        $max_admission_id = null;
        if ($result_max_id && $row = $result_max_id->fetch_assoc()) {
            $max_admission_id = $row['max_id'];
        }
        if($result_max_id) $result_max_id->free();

        if ($max_admission_id && preg_match('/^A(\d+)$/', $max_admission_id, $matches)) {
            $numeric_part = (int)$matches[1];
            $new_numeric_part = $numeric_part + 1;
            $new_admission_id = 'A' . str_pad($new_numeric_part, 3, '0', STR_PAD_LEFT);
        } else {
            $new_admission_id = 'A001';
        }
        // --- End Generate Admission ID ---

        // --- Database Transaction Start ---
        $conn->begin_transaction();
        $transaction_failed = false;

        try {
            // 1. Insert into ADMISSION table
            $sql_insert_admission = "INSERT INTO ADMISSION (AdmissionID, AdmissionDateTime, DischargeDateTime, ReasonForAdmission, BedID, PatientID, StaffID)
                                     VALUES (?, NOW(), NULL, ?, ?, ?, ?)";
            $stmt_insert = $conn->prepare($sql_insert_admission);
            if (!$stmt_insert) throw new Exception("Prepare failed (insert admission): " . $conn->error);
            $stmt_insert->bind_param("sssss", $new_admission_id, $reason_for_admission_post, $bed_id_post, $patient_id_post, $staff_id_to_use);
            if (!$stmt_insert->execute()) throw new Exception("Execute failed (insert admission): " . $stmt_insert->error);
            $stmt_insert->close();

            // 2. Update BED status to 'occupied'
            $sql_update_bed = "UPDATE BED SET BedStatus = 'occupied' WHERE BedID = ? AND BedStatus = 'available'";
            $stmt_update_bed = $conn->prepare($sql_update_bed);
            if (!$stmt_update_bed) throw new Exception("Prepare failed (update bed): " . $conn->error);
            $stmt_update_bed->bind_param("s", $bed_id_post);
            if (!$stmt_update_bed->execute()) throw new Exception("Execute failed (update bed): " . $stmt_update_bed->error);
            if ($stmt_update_bed->affected_rows === 0) {
                  throw new Exception("Failed to occupy bed '$bed_id_post'. It might already be occupied or does not exist.");
            }
            $stmt_update_bed->close();

            // 3. Update Appointment status to 'Admitted'
            $sql_select_appointment = "SELECT AppointmentID FROM appointments WHERE PatientID = ? AND AppointmentStatus = 'Diagnosed' ORDER BY AppointmentDate DESC, AppointmentTime DESC LIMIT 1";
            $stmt_find_app = $conn->prepare($sql_select_appointment);
            $appointment_id = null;
            if ($stmt_find_app) {
                 $stmt_find_app->bind_param("s", $patient_id_post);
                 $stmt_find_app->execute();
                 $result_app = $stmt_find_app->get_result();
                 if ($result_app && $row_app = $result_app->fetch_assoc()) {
                     $appointment_id = $row_app['AppointmentID'];
                 }
                 if($result_app) $result_app->free();
                 $stmt_find_app->close();
            } else {
                  error_log("Prepare failed (find appointment): " . $conn->error);
                  throw new Exception("Could not find a 'Diagnosed' appointment for patient '$patient_id_post'.");
            }
            if (!$appointment_id) {
                  throw new Exception("No active 'Diagnosed' appointment found for patient '$patient_id_post' to mark as admitted.");
            }

            $sql_update_appointment = "UPDATE appointments SET AppointmentStatus = 'Admitted' WHERE AppointmentID = ?";
            $stmt_update_app = $conn->prepare($sql_update_appointment);
            if (!$stmt_update_app) throw new Exception("Prepare failed (update appointment): " . $conn->error);
            $stmt_update_app->bind_param("s", $appointment_id);
            if (!$stmt_update_app->execute()) throw new Exception("Execute failed (update appointment): " . $stmt_update_app->error);
            $stmt_update_app->close();

            // REMOVED: Thanesh DB Update Block

            // If all steps succeeded, commit the transaction
            $conn->commit();
            $success_message = "Patient admitted successfully!"; // Simplified success message

        } catch (Exception $e) {
            // An error occurred, rollback the transaction
            $conn->rollback();
            $transaction_failed = true;
            $error_message = "Transaction Failed: " . $e->getMessage();
        }
        // --- End Database Transaction ---

    } // End basic validation else
} // End Form Submission Check


// --- Handle Discharge Request ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['discharge_patient'])) {
    $admission_id_to_discharge = $_POST['admission_id'] ?? null;

     // Reset discharge error message
     $discharge_error_message = '';

    if (empty($admission_id_to_discharge)) {
        $discharge_error_message = "No Admission ID provided for discharge.";
    } else {
        // --- Database Transaction for Discharge ---
        $conn->begin_transaction();
        $discharge_failed = false;
        $bed_id_to_update = null;

        try {
             // 1. Fetch the admission record
             $sql_get_admission = "SELECT * FROM ADMISSION WHERE AdmissionID = ?";
             $stmt_get = $conn->prepare($sql_get_admission);
             if (!$stmt_get) throw new Exception("Prepare failed (get admission): " . $conn->error);
             $stmt_get->bind_param("s", $admission_id_to_discharge);
             $stmt_get->execute();
             $result_admission = $stmt_get->get_result();
             if (!$result_admission || $result_admission->num_rows === 0) {
                  throw new Exception("Admission record '$admission_id_to_discharge' not found.");
             }
             $admission_record = $result_admission->fetch_assoc();
             $bed_id_to_update = $admission_record['BedID']; // Get bed ID before closing result
             $result_admission->free();
             $stmt_get->close();

             // 2. Generate PastAdmissionID
             $past_admission_id = generatePastAdmissionID($conn);

             // 3. Insert into PAST_ADMISSION
             $sql_insert_past = "INSERT INTO PAST_ADMISSION (PastAdmissionID, AdmissionID, AdmissionDateTime, DischargeDateTime, ReasonForAdmission, BedID, PatientID, StaffID) VALUES (?, ?, ?, NOW(), ?, ?, ?, ?)";
             $stmt_insert_past = $conn->prepare($sql_insert_past);
             if (!$stmt_insert_past) throw new Exception("Prepare failed (insert past): " . $conn->error);
             $stmt_insert_past->bind_param("sssssss", $past_admission_id, $admission_record['AdmissionID'], $admission_record['AdmissionDateTime'], $admission_record['ReasonForAdmission'], $admission_record['BedID'], $admission_record['PatientID'], $admission_record['StaffID']);
             if (!$stmt_insert_past->execute()) throw new Exception("Execute failed (insert past): " . $stmt_insert_past->error);
             $stmt_insert_past->close();

             // 4. Update BED status to 'available'
              // Ensure bed_id was fetched
             if (empty($bed_id_to_update)) {
                 throw new Exception("Could not determine the Bed ID for admission '$admission_id_to_discharge'. Cannot update bed status.");
             }
             $sql_update_bed_discharge = "UPDATE BED SET BedStatus = 'available' WHERE BedID = ? AND BedStatus = 'occupied'";
             $stmt_update_bed = $conn->prepare($sql_update_bed_discharge);
             if (!$stmt_update_bed) throw new Exception("Prepare failed (update bed discharge): " . $conn->error);
             $stmt_update_bed->bind_param("s", $bed_id_to_update);
             if (!$stmt_update_bed->execute()) throw new Exception("Execute failed (update bed discharge): " . $stmt_update_bed->error);
             // It's okay if affected_rows is 0, maybe bed was already available, but log it
             if ($stmt_update_bed->affected_rows === 0) {
                 error_log("Warning: Bed '$bed_id_to_update' status may not have been 'occupied' during discharge of admission '$admission_id_to_discharge'.");
             }
             $stmt_update_bed->close();

             // 5. Delete from ADMISSION table
             $sql_delete_admission = "DELETE FROM ADMISSION WHERE AdmissionID = ?";
             $stmt_delete = $conn->prepare($sql_delete_admission);
             if (!$stmt_delete) throw new Exception("Prepare failed (delete admission): " . $conn->error);
             $stmt_delete->bind_param("s", $admission_id_to_discharge);
             if (!$stmt_delete->execute()) throw new Exception("Execute failed (delete admission): " . $stmt_delete->error);
             $stmt_delete->close();

            // If all succeeded
            $conn->commit();
            $success_message = "Patient discharged successfully (Admission ID: $admission_id_to_discharge)!";

        } catch (Exception $e) {
             $conn->rollback();
             $discharge_failed = true;
             $discharge_error_message = "Discharge Failed: " . $e->getMessage(); // Show specific error
        }
        // --- End Database Transaction for Discharge ---
    }
}


// --- Fetch Data for Display ---

// Fetch Available Beds (re-fetch after potential admission/discharge)
$sql_beds = "SELECT b.BedID, i.name AS BedType FROM BED b LEFT JOIN inventory i ON b.InventoryID = i.inventoryID WHERE b.BedStatus = 'available' ORDER BY b.BedID ASC";
$result_beds = $conn->query($sql_beds);
// Don't overwrite $error_message if query fails, append or handle differently
if (!$result_beds) $fetch_error_beds = " Error fetching available beds: " . $conn->error; else $fetch_error_beds = '';

// Fetch Active Admissions
$sql_active_admissions = "SELECT a.AdmissionID, a.AdmissionDateTime, a.BedID, a.ReasonForAdmission, p.PatientName AS PatientName, s.Name AS StaffName FROM ADMISSION a LEFT JOIN patients p ON a.PatientID = p.PatientID LEFT JOIN STAFF s ON a.StaffID = s.StaffID ORDER BY a.AdmissionID ASC"; // Changed ORDER BY
$result_active_admissions = $conn->query($sql_active_admissions);
if (!$result_active_admissions) $fetch_error_admissions = " Error fetching active admissions: " . $conn->error; else $fetch_error_admissions = '';

// Fetch Patients for Search/Dropdown (re-fetch after potential admission)
$search_query = isset($_POST['search_query']) ? trim($_POST['search_query']) : ''; // Use value from POST if set
$sql_patients_dropdown = "SELECT p.PatientID, p.PatientName FROM patients p INNER JOIN appointments app ON p.PatientID = app.PatientID LEFT JOIN ADMISSION a ON p.PatientID = a.PatientID WHERE a.PatientID IS NULL AND app.AppointmentStatus = 'Diagnosed'";
$search_param = null;
if (!empty($search_query)) {
    $sql_patients_dropdown .= " AND p.PatientName LIKE ?";
    $search_param = "%" . $conn->real_escape_string($search_query) . "%"; // Escape search term
}
$sql_patients_dropdown .= " ORDER BY p.PatientName ASC";
$stmt_patients = $conn->prepare($sql_patients_dropdown);
$result_patients_dropdown = null;
if ($stmt_patients) {
    if ($search_param) $stmt_patients->bind_param("s", $search_param);
    $stmt_patients->execute();
    $result_patients_dropdown = $stmt_patients->get_result();
} else {
     $fetch_error_patients = " Error preparing patient list query: " . $conn->error;
}

// --- Get current page and StaffID link function ---
$current_page = basename($_SERVER['PHP_SELF']);
function addStaffIdToLink($url, $staffId) {
    if ($staffId !== null) {
        // Check if URL already has query params
        $query = parse_url($url, PHP_URL_QUERY);
        $separator = $query ? '&' : '?';
        // Append staffid, ensuring it's URL encoded
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
    <title>Patient Coordination - WeCare</title>
    <!-- CSS links -->
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        /* Base Styles */
         body { font-family: 'Montserrat', sans-serif; margin: 0; padding: 0; padding-top: 70px; background-color: #f4f7f6; } /* Slightly off-white background */
        .navbar { background-color: white !important; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); position: fixed; width: 100%; top: 0; z-index: 1030; }
        .navbar-brand { font-family: 'Montserrat', sans-serif; font-size: 1.6rem; font-weight: 700; color: #0d6efd !important; } /* Standard Bootstrap Blue */
        .navbar .nav-link { color: #495057 !important; font-weight: 500; transition: color 0.2s ease; }
        .navbar .nav-link:hover, .navbar .nav-item.active .nav-link { color: #0d6efd !important; }
        .navbar .nav-item.active .nav-link { font-weight: 600 !important; }
        .hero-section { background: linear-gradient(to right, #0d6efd, #0a58ca); color: white; padding: 50px 0; text-align: center; } /* Gradient Blue */
        .hero-section h1 { font-family: 'Montserrat', sans-serif; font-size: 2.8rem; font-weight: 700; color: white; text-transform: uppercase; letter-spacing: 1px; }
        .hero-section p { font-size: 1.1rem; opacity: 0.9; }
        .card { margin-top: 25px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); border-radius: 8px; border: none; background-color: white; }
        .card-header { background-color: #eef2f7; border-bottom: 1px solid #dee2e6; font-weight: 600; font-size: 1.1rem; padding: 0.9rem 1.25rem; color: #495057; }
        .card-body { padding: 1.75rem; }
        footer { background-color: #343a40; color: #adb5bd; padding: 25px 0; text-align: center; margin-top: 50px; font-size: 0.9rem; }
        footer p { margin-bottom: 0; }
        .btn:focus, .btn:active, a:focus, a:active, input:focus, select:focus, button:focus { outline-offset: 2px; outline: 2px solid rgba(13, 110, 253, 0.5) !important; box-shadow: 0 0 0 2px rgba(13, 110, 253, 0.25) !important; }
        .table-responsive { display: block; width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 1rem; background-color: white; font-size: 0.95rem; }
        table th, table td { padding: 0.9rem 1rem; text-align: left; vertical-align: middle; border-top: 1px solid #e9ecef; }
        table thead th { vertical-align: bottom; border-bottom: 2px solid #dee2e6; background-color: #f8f9fa; font-weight: 600; color: #495057; }
        tbody tr:hover { background-color: #f1f7ff !important; } /* Light blue hover */
        .alert { margin-top: 1.25rem; border-radius: 5px; font-size: 0.95rem; }
        .form-label { font-weight: 600; color: #495057; font-size: 0.9rem; margin-bottom: 0.3rem; }
        .form-control, .form-select { border-radius: 5px; font-size: 0.95rem; }
        input[readonly], select[readonly], .form-control-plaintext { background-color: #e9ecef !important; cursor: not-allowed; opacity: 0.8; }
        .form-text { font-size: 0.85em; color: #6c757d; }
        .btn-sm { padding: 0.3rem 0.6rem; font-size: 0.8rem; } /* Smaller buttons */
        .btn i { margin-right: 0.3rem; }
        .modal-header { background-color: #0d6efd; color: white; }
        .modal-header .modal-title { font-weight: 600; }
        .modal-header .btn-close { filter: invert(1) grayscale(100%) brightness(200%); } /* White close button */
        @media (max-width: 768px) { .hero-section h1 { font-size: 2rem; } .navbar-brand { font-size: 1.4rem; } .navbar-nav { text-align: center; margin-top: 10px; } .card-body { padding: 1.25rem; } }

        /* --- CSS for Sortable Table Headers (ADDED) --- */
        th.sortable {
            cursor: pointer;
            position: relative;
            padding-right: 25px !important;
            user-select: none;
        }
        th.sortable:hover {
            background-color: #e2e6ea; /* Slightly darker hover */
        }
        th.sortable::before,
        th.sortable::after {
            font-family: 'Montserrat', sans-serif;
            font-weight: 900;
            position: absolute;
            right: 8px;
            opacity: 0.25;
            color: #6c757d;
            transition: opacity 0.2s ease-in-out, color 0.2s ease-in-out;
        }
        th.sortable::before { content: "\f0de"; top: calc(50% - 0.6em); }
        th.sortable::after { content: "\f0dd"; top: calc(50% - 0.1em); }
        th.sortable.sort-asc::before { opacity: 1; color: #0d6efd; }
        th.sortable.sort-desc::after { opacity: 1; color: #0d6efd; }
        /* --- End Sortable CSS --- */

    </style>
</head>

<body>
      <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-light">
         <div class="container">
            <a class="navbar-brand" href="<?php echo addStaffIdToLink('index.php', $staffID); ?>">WeCare</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                 <ul class="navbar-nav ms-auto">
                   <li class="nav-item <?php echo ($current_page == 'index.php') ? 'active' : ''; ?>">
                        <a class="nav-link" href="<?php echo addStaffIdToLink('index.php', $staffID); ?>">Home</a>
                    </li>
                    <li class="nav-item <?php echo ($current_page == 'bed_registration.php') ? 'active' : ''; ?>">
                        <a class="nav-link" href="<?php echo addStaffIdToLink('bed_registration.php', $staffID); ?>">Bed Registration</a>
                    </li>
                    <li class="nav-item <?php echo ($current_page == 'patient_coordination.php') ? 'active' : ''; ?>">
                        <a class="nav-link" href="<?php echo addStaffIdToLink('patient_coordination.php', $staffID); ?>">Patient Coordination</a>
                    </li>
                    <li class="nav-item <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
                        <a class="nav-link" href="<?php echo addStaffIdToLink('dashboard.php', $staffID); ?>">Real-Time Dashboards</a>
                    </li>
                     <!-- Basic Logout Link (adjust URL as needed) -->
                      <li class="nav-item">
                          <a class="nav-link" href="login.php?action=logout">Logout</a>
                      </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <div class="hero-section">
        <h1>Patient Coordination</h1>
        <p>Manage Patient Admissions and Discharges Efficiently</p>
    </div>

    <!-- Main Content -->
    <div class="container mt-4 mb-5">
         <!-- Display Messages -->
         <div id="messageArea" style="min-height: 60px;"> <!-- Reserve space -->
             <?php if ($success_message) : ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                     <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if ($error_message) : // General admission errors ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if ($discharge_error_message) : ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                     <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($discharge_error_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
             <?php // Display fetch errors if any
                $fetch_errors = trim(($fetch_error_beds ?? '') . ($fetch_error_admissions ?? '') . ($fetch_error_patients ?? ''));
                if (!empty($fetch_errors)) : ?>
                  <div class="alert alert-warning alert-dismissible fade show" role="alert">
                       <i class="fas fa-exclamation-circle me-2"></i>Could not load all page data: <?php echo htmlspecialchars($fetch_errors); ?>
                      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                  </div>
             <?php endif; ?>
         </div>


        <!-- Admission Form Card -->
        <div class="card mb-4">
             <div class="card-header"><i class="fas fa-procedures me-2"></i>Patient Admission Form</div>
            <div class="card-body">
                 <!-- Patient Search Form -->
                 <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . addStaffIdToLink('', $staffID);?>" class="mb-4 pb-3 border-bottom">
                     <div class="row g-2 align-items-end">
                          <div class="col-md">
                            <label for="search_query" class="form-label">Search Diagnosed Patients</label>
                            <input type="text" class="form-control form-control-sm" id="search_query" name="search_query" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Enter patient name...">
                          </div>
                           <div class="col-auto"> <button type="submit" class="btn btn-secondary btn-sm" name="search_patient"><i class="fas fa-search"></i> Search</button> </div>
                     </div>
                 </form>

                 <!-- Main Admission Form -->
                 <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . addStaffIdToLink('', $staffID);?>" id="admissionForm">
                     <?php if ($staffID && $staff_name && $staff_id_db) : ?>
                         <div class="row mb-3 align-items-center">
                             <label for="staff_name_display" class="col-sm-3 col-form-label pt-0">Staff In Charge:</label>
                             <div class="col-sm-9">
                                 <p class="form-control-plaintext" id="staff_name_display" style="margin-bottom: 0;">
                                     <?php echo htmlspecialchars($staff_name) . ' (' . htmlspecialchars($staff_id_db) . ') - Status: '; ?>
                                     <span class="badge bg-<?php echo ($staff_status === 'Available') ? 'success' : 'warning'; ?>"><?php echo htmlspecialchars($staff_status); ?></span>
                                 </p>
                             </div>
                         </div>
                         <?php if ($staff_status !== 'Available') : ?>
                             <p class="alert alert-warning small p-2"><i class="fas fa-exclamation-triangle me-1"></i>Your staff status is '<?php echo htmlspecialchars($staff_status); ?>'. You cannot submit new admissions.</p>
                         <?php endif; ?>
                     <?php else : ?>
                          <p class="alert alert-danger small p-2">Staff details could not be loaded. Cannot process admission.</p>
                     <?php endif; ?>

                    <div class="mb-3">
                        <label for="patient_id" class="form-label">Select Patient <span class="text-danger">*</span></label>
                        <select class="form-select <?php echo ($error_message && empty($_POST['patient_id'])) ? 'is-invalid' : ''; ?>" id="patient_id" name="patient_id" required onchange="updateReasonForAdmission()" <?php if ($staff_status !== 'Available' || !$staffID) echo 'disabled'; ?>>
                            <option value="" selected disabled>Select a Patient...</option>
                            <?php
                            // Display dropdown options fetched earlier
                            if (isset($result_patients_dropdown) && $result_patients_dropdown->num_rows > 0) {
                                while ($patient = $result_patients_dropdown->fetch_assoc()) {
                                    echo "<option value='" . htmlspecialchars($patient['PatientID']) . "'>" . htmlspecialchars($patient['PatientName']) . " (" . htmlspecialchars($patient['PatientID']) . ")</option>";
                                }
                                // Free result ONLY if it was successfully created
                                if ($result_patients_dropdown) $result_patients_dropdown->free();
                            } else {
                                echo "<option value='' disabled>" . (empty($search_query) ? "No diagnosed patients found awaiting admission." : "No patients found matching search.") . "</option>";
                            }
                            // Close statement ONLY if it was successfully created
                            if (isset($stmt_patients) && $stmt_patients) $stmt_patients->close();
                            ?>
                        </select>
                         <div class="form-text">Only patients with status 'Diagnosed' and not currently admitted are shown. Use search above if needed.</div>
                         <?php if ($error_message && empty($_POST['patient_id'])) : ?><div class="invalid-feedback">Please select a patient.</div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="reason_for_admission" class="form-label">Reason for Admission (Diagnosis)</label>
                        <input type="text" class="form-control" id="reason_for_admission" name="reason_for_admission" readonly>
                         <div class="form-text">Automatically populated from the patient's latest 'Diagnosed' appointment.</div>
                    </div>
                    <div class="mb-3">
                        <label for="bed_id" class="form-label">Assign Available Bed <span class="text-danger">*</span></label>
                        <select class="form-select <?php echo ($error_message && empty($_POST['bed_id'])) ? 'is-invalid' : ''; ?>" id="bed_id" name="bed_id" required <?php if ($staff_status !== 'Available' || !$staffID) echo 'disabled'; ?>>
                             <option value="" selected disabled>Select a Bed...</option>
                            <?php
                            // Display bed options fetched earlier
                            if (isset($result_beds) && $result_beds->num_rows > 0) {
                                // Reset pointer if needed (already looped once?) - safe to re-query or store in array if needed multiple times
                                // $result_beds->data_seek(0); // Reset pointer if already iterated
                                while ($bed = $result_beds->fetch_assoc()) {
                                    echo "<option value='" . htmlspecialchars($bed['BedID']) . "'>" . htmlspecialchars($bed['BedID']) . " - " . htmlspecialchars($bed['BedType']) . "</option>";
                                }
                                $result_beds->free(); // Free bed result set
                            } else {
                                echo "<option value='' disabled>No available beds found</option>";
                            }
                            ?>
                        </select>
                         <?php if ($error_message && empty($_POST['bed_id'])) : ?><div class="invalid-feedback">Please select an available bed.</div><?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn-primary" name="submit_patient" <?php if ($staff_status !== 'Available' || !$staffID) echo 'disabled'; ?>> <i class="fas fa-check-circle me-1"></i> Admit Patient </button>
                </div>
            </div> <!-- End card-body -->
        </form> <!-- End Admission Form -->


        <!-- Active Admissions Table Card -->
        <div class="card">
             <div class="card-header"><i class="fas fa-bed me-2"></i>Active Admissions List</div>
             <div class="card-body">
                 <?php // Check the result variable again before using ?>
                <?php if (!isset($result_active_admissions) || !$result_active_admissions) : ?>
                     <div class="alert alert-warning">Could not load active admissions list. <?php echo htmlspecialchars($fetch_error_admissions ?? ''); ?></div>
                <?php elseif ($result_active_admissions->num_rows > 0) : ?>
                     <div class="table-responsive">
                        <!-- ADDED id="activeAdmissionsTable" -->
                        <table id="activeAdmissionsTable" class='table table-striped table-hover table-sm'> <!-- table-sm for denser table -->
                             <thead>
                                <tr>
                                    <!-- ADDED class="sortable" and onclick -->
                                    <th class="sortable" onclick="sortTable(0, this)">Admission ID</th>
                                    <th class="sortable" onclick="sortTable(1, this)">Admitted On</th>
                                    <th class="sortable" onclick="sortTable(2, this)">Bed ID</th>
                                    <th class="sortable" onclick="sortTable(3, this)">Reason</th>
                                    <th class="sortable" onclick="sortTable(4, this)">Patient Name</th>
                                    <th class="sortable" onclick="sortTable(5, this)">Staff Name</th>
                                    <th>Action</th>
                                </tr>
                              </thead>
                            <tbody>
                            <?php while ($row = $result_active_admissions->fetch_assoc()) : ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['AdmissionID']); ?></td>
                                    <!-- Format date/time for display -->
                                    <td><?php echo htmlspecialchars(date('d M Y, H:i', strtotime($row['AdmissionDateTime']))); ?></td>
                                    <td><?php echo htmlspecialchars($row['BedID']); ?></td>
                                    <td><?php echo htmlspecialchars($row['ReasonForAdmission']); ?></td>
                                    <td><?php echo htmlspecialchars($row['PatientName'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($row['StaffName'] ?? 'N/A'); ?></td>
                                    <td>
                                        <button class='btn btn-danger btn-sm discharge-btn'
                                                data-bs-toggle='modal'
                                                data-bs-target='#dischargeModal'
                                                data-admission-id='<?php echo htmlspecialchars($row['AdmissionID']); ?>'
                                                title='Discharge Patient'>
                                            <i class='fas fa-sign-out-alt'></i> Discharge
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else : ?>
                    <p class="text-center text-muted">No active admissions found.</p>
                <?php endif; ?>
                 <?php // Free result set AFTER the loop
                     if (isset($result_active_admissions) && $result_active_admissions) {
                         $result_active_admissions->free();
                     }
                 ?>
            </div>
        </div>
    </div> <!-- End container -->

    <!-- Discharge Modal -->
    <div class="modal fade" id="dischargeModal" tabindex="-1" aria-labelledby="dischargeModalLabel" aria-hidden="true">
         <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="dischargeModalLabel"><i class="fas fa-sign-out-alt me-2"></i>Confirm Patient Discharge</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                     <!-- Form now submits directly -->
                     <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . addStaffIdToLink('', $staffID);?>" id="dischargeForm">
                        <input type="hidden" id="discharge_admission_id" name="admission_id">
                        <p>Are you sure you want to discharge the patient associated with Admission ID: <strong id="discharge_admission_id_display"></strong>?</p>
                         <p class="text-muted small">This action cannot be undone. The admission record will be moved to history, and the bed status updated.</p>
                         <div class="text-end mt-3">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <!-- Added name="discharge_patient" to the submit button -->
                            <button type="submit" class="btn btn-danger" name="discharge_patient"><i class="fas fa-check"></i> Yes, Discharge</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

     <!-- Footer -->
    <footer>
         <p>© <?php echo date("Y"); ?> WeCare Hospital Management. All rights reserved.</p>
    </footer>

    <!-- JS Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script>
        // Keep existing DOMContentLoaded and updateReasonForAdmission
        document.addEventListener('DOMContentLoaded', function() {
            const dischargeModal = document.getElementById('dischargeModal');
            if (dischargeModal) {
                 dischargeModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget; // Button that triggered the modal (.discharge-btn)
                    const admissionId = button.getAttribute('data-admission-id');
                    const admissionIdInput = dischargeModal.querySelector('#discharge_admission_id');
                    const admissionIdDisplay = dischargeModal.querySelector('#discharge_admission_id_display');
                    if(admissionIdInput) admissionIdInput.value = admissionId;
                    if(admissionIdDisplay) admissionIdDisplay.textContent = admissionId;
                 });
            }
            // Function to fetch reason based on patient selection
            window.updateReasonForAdmission = function() {
                const patientIdSelect = document.getElementById('patient_id');
                const reasonInput = document.getElementById('reason_for_admission');
                const patientId = patientIdSelect ? patientIdSelect.value : null;
                if (patientId && reasonInput) {
                     reasonInput.value = 'Loading diagnosis...'; // Provide feedback
                     // Ensure get_reason_for_admission.php exists and returns JSON like {"success": true, "reason": "Diagnosis Text"} or {"success": false, "error": "Message"}
                    fetch(`get_reason_for_admission.php?patient_id=${encodeURIComponent(patientId)}`)
                        .then(response => {
                            if (!response.ok) { throw new Error(`HTTP error! status: ${response.status}`); }
                            return response.json();
                        })
                        .then(data => {
                            if (data.success && data.reason) {
                                reasonInput.value = data.reason;
                            } else {
                                reasonInput.value = data.error || 'No diagnosis found for this patient.';
                                console.warn("Reason fetch issue:", data.error || 'No reason returned');
                            }
                        })
                        .catch(error => {
                            console.error('Error fetching reason for admission:', error);
                            reasonInput.value = 'Error retrieving diagnosis';
                        });
                } else if (reasonInput) {
                    reasonInput.value = ''; // Clear if no patient selected
                }
            };
            // Initial call in case a patient is pre-selected (e.g., after form error)
            updateReasonForAdmission();

        }); // End of existing DOMContentLoaded


        // --- ADDED: Table Sorting Script ---
        let tableSortDirections = {}; // Tracks sort direction for each column index

        function sortTable(columnIndex, thElement) {
            const table = document.getElementById("activeAdmissionsTable");
            if (!table) return;
            const tbody = table.querySelector("tbody");
             if (!tbody) return;
            const rows = Array.from(tbody.querySelectorAll("tr"));

            // Determine sort direction (1 for asc, -1 for desc)
            const currentDirection = tableSortDirections[columnIndex] || 0;
            const newDirection = (currentDirection === 1) ? -1 : 1;

            // Reset directions & classes for other columns, set current
            table.querySelectorAll("th.sortable").forEach((th, index) => {
                th.classList.remove("sort-asc", "sort-desc");
                 tableSortDirections[index] = (index === columnIndex) ? newDirection : 0;
            });
            thElement.classList.add(newDirection === 1 ? "sort-asc" : "sort-desc");

            // Sort the rows array
            rows.sort((rowA, rowB) => {
                const cellA = rowA.cells[columnIndex]?.innerText.trim() || '';
                const cellB = rowB.cells[columnIndex]?.innerText.trim() || '';

                let comparison = 0;
                if (columnIndex === 1) { // Admitted On (Date/Time)
                    const dateA = new Date(cellA.replace(/(\d{1,2} \w{3} \d{4}), (\d{2}:\d{2})/, '$1 $2')); // Try parsing 'd M Y, H:i'
                    const dateB = new Date(cellB.replace(/(\d{1,2} \w{3} \d{4}), (\d{2}:\d{2})/, '$1 $2'));
                    if (!isNaN(dateA) && !isNaN(dateB)) {
                       comparison = dateA < dateB ? -1 : (dateA > dateB ? 1 : 0);
                    } else { comparison = cellA.localeCompare(cellB); } // Fallback
                } else if (columnIndex === 0 || columnIndex === 2) { // Admission ID, Bed ID
                     comparison = cellA.localeCompare(cellB, undefined, {numeric: true, sensitivity: 'base'});
                 } else { // Reason, Patient Name, Staff Name
                     comparison = cellA.localeCompare(cellB, undefined, { sensitivity: 'base' });
                 }
                return comparison * newDirection; // Apply direction
            });

            // Re-append sorted rows
            rows.forEach(row => tbody.appendChild(row));
        }
        // --- End Table Sorting Script ---

    </script>
</body>
</html>
<?php
// Close the main database connection
if (isset($conn) && $conn instanceof mysqli && $conn->thread_id) {
    $conn->close();
}
?>