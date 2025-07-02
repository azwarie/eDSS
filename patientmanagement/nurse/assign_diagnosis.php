<?php
session_start(); // Start session if needed

// Include the SINGLE database connection ($conn to azwarie_dss)
include 'connection.php'; // This should define $conn using mysqli

// Check connection
if (!$conn || $conn->connect_error) {
    die("Database connection failed: " . ($conn ? $conn->connect_error : 'Unknown error'));
}

// Set default timezone
date_default_timezone_set('Asia/Kuala_Lumpur'); // Replace with your timezone

// Get logged-in staff ID (optional, but good for logging)
$loggedInStaffID = $_SESSION['staffid'] ?? $_GET['staffid'] ?? null; // Get from session or URL for flexibility

// Validate loggedInStaffID
if (empty($loggedInStaffID)) {
     // Redirect or handle error if admin ID is mandatory for this page
     die("Admin Staff ID is missing or invalid. Please log in again.");
}


$message = ""; // Initialize the message variable

// --- Reusable Audit Trail Function ---
function logAuditTrail($conn_audit, $staffID_audit, $action, $description) {
    if (!$conn_audit) return;
    try {
        $sql = "INSERT INTO AUDIT_TRAIL (StaffID, Action, Description, Timestamp) VALUES (?, ?, ?, NOW())";
        $stmt = $conn_audit->prepare($sql);
        if ($stmt === false) { throw new Exception("Prepare failed (Audit Trail): " . $conn_audit->error); }
        $actorStaffID = !empty($staffID_audit) ? $staffID_audit : 'SYSTEM';
        $stmt->bind_param('sss', $actorStaffID, $action, $description);
        if (!$stmt->execute()) { throw new Exception("Execute failed (Audit Trail): " . $stmt->error); }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Audit trail error: " . $e->getMessage());
    }
}


// --- Fetch Patients for Dropdown (Only those with 'Booked' status) ---
$patients = [];
$fetch_error_patients = null;
try {
    $patientsQuery = "
        SELECT DISTINCT p.PatientID, p.PatientName, p.ic
        FROM patients p
        JOIN appointments a ON p.PatientID = a.PatientID
        WHERE a.AppointmentStatus = 'Booked'
        ORDER BY p.PatientName ASC
    ";
    $patientsResult = $conn->query($patientsQuery);
    if ($patientsResult) {
        $patients = $patientsResult->fetch_all(MYSQLI_ASSOC);
        $patientsResult->free();
    } else { throw new Exception("Failed to fetch patients with booked appointments: " . $conn->error); }
} catch (Exception $e) {
    $fetch_error_patients = "Error loading patient list: " . $e->getMessage();
    error_log($fetch_error_patients);
}

// --- Fetch all diagnoses ---
$diagnoses = [];
$fetch_error_diagnoses = null;
try {
    $diagnosesQuery = "SELECT DiagnosisID, DiagnosisName FROM DIAGNOSIS ORDER BY DiagnosisName ASC";
    $diagnosesResult = $conn->query($diagnosesQuery);
     if ($diagnosesResult) {
        $diagnoses = $diagnosesResult->fetch_all(MYSQLI_ASSOC);
        $diagnosesResult->free();
    } else { throw new Exception("Failed to fetch diagnoses: " . $conn->error); }
} catch (Exception $e) {
    $fetch_error_diagnoses = "Error loading diagnoses list: " . $e->getMessage();
    error_log($fetch_error_diagnoses);
}

// --- Fetch details for the bottom table (Patients with 'Wait' status) ---
$diagnosisDetails = [];
$fetch_error_details = null;
try {
    // Fetch patients with status 'Wait'
    // Get DiagnosisName via the patients table's DiagnosisID
    // Get Remark (DoctorNotes) from the appointments table
    $diagnosisDetailsQuery = "
        SELECT
            p.PatientID,
            p.PatientName,
            a.Remark AS DoctorNotes, 
            d.DiagnosisName,
            a.AppointmentStatus,
            a.AppointmentID 
        FROM appointments a
        JOIN patients p ON a.PatientID = p.PatientID
        LEFT JOIN DIAGNOSIS d ON p.DiagnosisID = d.DiagnosisID 
        WHERE a.AppointmentStatus = 'Wait'
        -- Optional: If a patient could have multiple 'Wait' appointments (unlikely?),
        -- you might need to add criteria to select the specific one, e.g., latest date.
        -- For now, assume one 'Wait' status shown per patient.
        -- GROUP BY p.PatientID, p.PatientName, a.AppointmentStatus, a.Remark, d.DiagnosisName -- Grouping might hide relevant info if remark/diag changes
        ORDER BY p.PatientName ASC
    ";

    $diagnosisDetailsResult = $conn->query($diagnosisDetailsQuery);
    if ($diagnosisDetailsResult) {
        $diagnosisDetails = $diagnosisDetailsResult->fetch_all(MYSQLI_ASSOC);
        $diagnosisDetailsResult->free();
    } else { throw new Exception("Failed to fetch 'Wait' status patient details: " . $conn->error); }
} catch (Exception $e) {
    $fetch_error_details = "Error loading diagnosed patients list: " . $e->getMessage();
    error_log($fetch_error_details);
}


// --- Handle form submission for assigning diagnosis ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['patient_id'], $_POST['diagnosis_id'], $_POST['doctor_notes'])) {

    $patient_id = trim($_POST['patient_id']);
    $diagnosis_id = trim($_POST['diagnosis_id']);
    $doctor_notes = trim($_POST['doctor_notes']); // This is the 'Remark'
    $form_errors = [];

    // Server-side validation
    if (empty($patient_id)) $form_errors[] = "Please select a patient.";
    if (empty($diagnosis_id)) $form_errors[] = "Please select a diagnosis.";
    if (empty($doctor_notes)) $form_errors[] = "Doctor's notes (Remark) cannot be empty.";
    // Optional: Check if patient_id and diagnosis_id actually exist

    if (empty($form_errors)) {
        $conn->begin_transaction(); // Start transaction
        try {
            // 1. Find the latest AppointmentID for the patient (we need this to update the correct appointment)
            $latestAppId = null;
            $stmt_find_latest = $conn->prepare("SELECT AppointmentID FROM appointments WHERE PatientID = ? ORDER BY AppointmentDate DESC, AppointmentTime DESC LIMIT 1");
            if(!$stmt_find_latest) throw new Exception("Prepare failed (Find Latest Appt): " . $conn->error);
            $stmt_find_latest->bind_param("s", $patient_id);
            if(!$stmt_find_latest->execute()) throw new Exception("Execute failed (Find Latest Appt): " . $stmt_find_latest->error);
            $res_latest = $stmt_find_latest->get_result();
            if($row_latest = $res_latest->fetch_assoc()) {
                $latestAppId = $row_latest['AppointmentID'];
            }
            if($res_latest) $res_latest->free();
            $stmt_find_latest->close();

            if(!$latestAppId) throw new Exception("Could not find an appointment for patient $patient_id to update.");

            // 2. Update the 'patients' table with the selected DiagnosisID
            $updatePatientQuery = "UPDATE patients SET DiagnosisID = ? WHERE PatientID = ?";
            $stmt_update_patient = $conn->prepare($updatePatientQuery);
            if (!$stmt_update_patient) throw new Exception("Prepare failed (Update Patient Diagnosis): " . $conn->error);
            $stmt_update_patient->bind_param("ss", $diagnosis_id, $patient_id);
            if (!$stmt_update_patient->execute()) throw new Exception("Execute failed (Update Patient Diagnosis): " . $stmt_update_patient->error);
            $stmt_update_patient->close();

            // 3. Update the latest 'appointments' table entry: set Remark and status to 'Wait'
            $updateAppointmentQuery = "UPDATE appointments SET AppointmentStatus = 'Wait', Remark = ? WHERE AppointmentID = ?";
            $stmt_update_appt = $conn->prepare($updateAppointmentQuery);
             if (!$stmt_update_appt) throw new Exception("Prepare failed (Update Appt Status/Remark): " . $conn->error);
            $stmt_update_appt->bind_param("ss", $doctor_notes, $latestAppId); // s = Remark, s = AppointmentID
            if (!$stmt_update_appt->execute()) throw new Exception("Execute failed (Update Appt Status/Remark): " . $stmt_update_appt->error);
            $stmt_update_appt->close();

            // Commit transaction
            if (!$conn->commit()) throw new Exception("Transaction commit failed: " . $conn->error);

            logAuditTrail($conn, $loggedInStaffID, 'Diagnosis Assignment', "Assigned DiagnosisID '{$diagnosis_id}' and notes to PatientID '{$patient_id}'. Appt '{$latestAppId}' status set to Wait.");

            // Set success message and redirect
            $successMessage = "Diagnosis assigned successfully for patient $patient_id! Appointment status set to 'Wait'.";
            $_SESSION['form_message'] = "<div class='alert alert-success alert-dismissible fade show' role='alert'>".htmlspecialchars($successMessage)."<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
            header("Location: assign_diagnosis.php?staffid=" . urlencode($loggedInStaffID ?? '')); // Redirect to refresh data
            exit();

        } catch (Exception $e) {
            $conn->rollback(); // Rollback on error
            $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'>Error assigning diagnosis: " . htmlspecialchars($e->getMessage()) . "<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
            error_log("Diagnosis Assignment Error: " . $e->getMessage());
        }
    } else {
        // Display validation errors
        $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'><strong>Assignment failed:</strong><ul class='mb-0'>";
        foreach ($form_errors as $error) { $message .= "<li>" . htmlspecialchars($error) . "</li>"; }
        $message .= "</ul><button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
    }
}


// --- Handle form submission for updating status (Admit 'Yes'/'No') ---
if(isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $patientId = trim($_POST['patient_id'] ?? '');
    $newStatus = trim($_POST['status'] ?? ''); // 'Diagnosed' (Yes) or 'Completed' (No)
    $form_errors = [];

    if (empty($patientId)) $form_errors[] = "Patient ID missing for status update.";
    if (empty($newStatus) || !in_array($newStatus, ['Diagnosed', 'Completed'])) $form_errors[] = "Invalid target status provided.";

    if (empty($form_errors)) {
        $conn->begin_transaction();
        try {
            // 1. Find the latest AppointmentID for the patient (must exist with status 'Wait')
             $latestAppId = null;
             $stmt_find_latest = $conn->prepare("SELECT AppointmentID FROM appointments WHERE PatientID = ? AND AppointmentStatus = 'Wait' ORDER BY AppointmentDate DESC, AppointmentTime DESC LIMIT 1");
             if(!$stmt_find_latest) throw new Exception("Prepare failed (Find Wait Appt): " . $conn->error);
             $stmt_find_latest->bind_param("s", $patientId);
             if(!$stmt_find_latest->execute()) throw new Exception("Execute failed (Find Wait Appt): " . $stmt_find_latest->error);
             $res_latest = $stmt_find_latest->get_result();
             if($row_latest = $res_latest->fetch_assoc()) { $latestAppId = $row_latest['AppointmentID']; }
             if($res_latest) $res_latest->free();
             $stmt_find_latest->close();

             if(!$latestAppId) throw new Exception("Could not find appointment with status 'Wait' for patient $patientId to update.");


            // 2. Update AppointmentStatus in 'appointments' table for the found LATEST 'Wait' appointment
            $updateStatusQuery = "UPDATE appointments SET AppointmentStatus = ? WHERE AppointmentID = ?";
            $stmt_update_status = $conn->prepare($updateStatusQuery);
            if (!$stmt_update_status) throw new Exception("Prepare failed (Update Appt Status): " . $conn->error);
            $stmt_update_status->bind_param("ss", $newStatus, $latestAppId); // Bind new status and the specific Appt ID
            if (!$stmt_update_status->execute()) throw new Exception("Execute failed (Update Appt Status): " . $stmt_update_status->error);

             // 3. Optionally clear DiagnosisID from patients table if 'Completed' (workflow decision)
             // if ($newStatus === 'Completed') {
             //     $stmt_clear_diag = $conn->prepare("UPDATE patients SET DiagnosisID = NULL WHERE PatientID = ?");
             //     if ($stmt_clear_diag) { $stmt_clear_diag->bind_param("s", $patientId); $stmt_clear_diag->execute(); $stmt_clear_diag->close(); }
             //     else { error_log("Warning: Failed to prepare statement to clear patient DiagnosisID for $patientId."); }
             // }

            $stmt_update_status->close();

            // Commit transaction
             if (!$conn->commit()) throw new Exception("Transaction commit failed: " . $conn->error);

             logAuditTrail($conn, $loggedInStaffID, 'Appointment Status Update', "Updated status to '{$newStatus}' for PatientID '{$patientId}' (AppointmentID: {$latestAppId}).");

            // Set success message and redirect
            $_SESSION['form_message'] = "<div class='alert alert-success alert-dismissible fade show' role='alert'>Appointment status updated to '$newStatus' successfully!<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
            header("Location: assign_diagnosis.php?staffid=" . urlencode($loggedInStaffID ?? ''));
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'>Error updating status: " . htmlspecialchars($e->getMessage()) . "<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
            error_log("Status Update Error: " . $e->getMessage());
        }
    } else {
        // Display validation errors
         $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'><strong>Status Update failed:</strong><ul class='mb-0'>";
         foreach ($form_errors as $error) { $message .= "<li>" . htmlspecialchars($error) . "</li>"; }
         $message .= "</ul><button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
    }
}

// Retrieve message from session after redirect
if(isset($_SESSION['form_message'])){
    $message = $_SESSION['form_message'];
    unset($_SESSION['form_message']); // Clear message after displaying
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Diagnosis - WeCare</title>
     <!-- Fonts & Icons -->
     <link rel="preconnect" href="https://fonts.googleapis.com">
     <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
     <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
     <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;700&display=swap" rel="stylesheet">
     <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
     <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
     <style>
          /* Consistent styling from previous conversions */
          :root { --primary-color: #007bff; --background-color: #f8f9fa; --text-color: #343a40; --form-bg-color: #ffffff; --form-border-color: #dee2e6; --input-focus-color: #86b7fe; }
          body { font-family: 'Poppins', sans-serif; margin: 0; padding: 0; background-color: var(--background-color); padding-top: 70px; }
          /* Navbar Styling (assuming navbar.php is included and styled) */
          .navbar { position: sticky; top: 0; z-index: 1030; background-color: white !important; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05); padding: 0.5rem 1rem; }
          .navbar-brand { font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 700; color: var(--primary-color) !important; }
          /* Hero Section */
          .hero-section { background-color: var(--primary-color); color: white; padding: 60px 0; text-align: center; margin-bottom: 30px; }
          .hero-section h1 { font-family: 'Montserrat', sans-serif; font-size: 2.8rem; font-weight: 700; text-transform: uppercase; }
          .hero-section p { font-size: 1.1rem; opacity: 0.9; }
          @media (max-width: 768px) { .hero-section { padding: 40px 0; } .hero-section h1 { font-size: 2rem; } .hero-section p { font-size: 1rem; } }
          /* Card Styling */
          .card { border: none; border-radius: 8px; box-shadow: 0 2px 5px rgba(0, 0, 0, 0.07); margin-bottom: 20px; }
          .card-header { background-color: #e9ecef; border-bottom: 1px solid #dee2e6; font-weight: 600; padding: 0.9rem 1.25rem; font-size: 1.1rem; color: #495057; }
          .card-body { padding: 1.5rem; }
          /* Form Specific Styling */
          .form-container { background-color: var(--form-bg-color); padding: 2rem; border-radius: 8px; }
          .form-container h2 { text-align: center; margin-bottom: 1.5rem; color: var(--primary-color); font-size: 1.5rem; font-weight: 600; }
          .form-label { display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--text-color); font-size: 0.95rem; }
          .form-control, .form-select { width: 100%; padding: 0.5rem 0.85rem; margin-bottom: 1rem; border: 1px solid var(--form-border-color); border-radius: 5px; font-size: 1rem; transition: border-color 0.3s ease, box-shadow 0.2s ease; }
          .form-control:focus, .form-select:focus { border-color: var(--input-focus-color); outline: none; box-shadow: 0 0 4px rgba(0, 123, 255, 0.2); }
          textarea.form-control { min-height: 80px; resize: vertical; }
          .btn-submit { background: linear-gradient(to right, #005c99, #007bff); color: white; border: none; cursor: pointer; transition: background 0.4s ease, transform 0.2s ease; padding: 0.6rem 1.5rem; font-size: 1rem; font-weight: 600; border-radius: 5px; display: block; width: 100%; margin-top: 1rem; }
          .btn-submit:hover { background: linear-gradient(to right, #004080, #0056b3); transform: translateY(-1px); }
          /* Table Styling */
          .patients-container { margin-top: 30px; }
          .table-responsive { display: block; width: 100%; overflow-x: auto; }
          table { width: 100%; border-collapse: collapse; margin-bottom: 1rem; background-color: white; font-size: 0.9rem; }
          table th, table td { padding: 0.75rem; text-align: left; vertical-align: middle; border: 1px solid #dee2e6; }
          table thead th { vertical-align: bottom; border-bottom: 2px solid #dee2e6; background-color: #f8f9fa; font-weight: 600; color: #495057; white-space: nowrap; }
          table tbody tr:hover { background-color: #f1f7ff !important; }
          /* Admit/Complete Buttons */
           .btn-admit { background-color: #198754; color: white; border: none; padding: 0.25rem 0.5rem; font-size: 0.8rem; border-radius: 4px; }
           .btn-complete { background-color: #dc3545; color: white; border: none; padding: 0.25rem 0.5rem; font-size: 0.8rem; border-radius: 4px; }
           .btn-admit:hover { background-color: #157347; }
           .btn-complete:hover { background-color: #bb2d3b; }
           .action-forms form { display: inline-block; margin: 0 2px;}
           .action-forms button { cursor: pointer; }

           /* Custom Dropdown Styles */
            .custom-dropdown { position: relative; display: block; width: 100%; margin-bottom: 1rem; }
            .custom-dropdown .dropdown-input { width: 100%; padding: 0.5rem 0.85rem; border: 1px solid var(--form-border-color); border-radius: 5px; font-size: 1rem; cursor: pointer; background-color: white; display: flex; justify-content: space-between; align-items: center; }
            .custom-dropdown .dropdown-input::after { content: '\25BC'; font-size: 0.8em; color: #888; margin-left: 5px;}
            .custom-dropdown .dropdown-input:focus { border-color: var(--input-focus-color); outline: none; box-shadow: 0 0 4px rgba(0, 123, 255, 0.2); }
            .custom-dropdown .dropdown-list { position: absolute; top: 100%; left: 0; width: 100%; background-color: #fff; border: 1px solid #d1e1f4; border-radius: 5px; box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1); display: none; max-height: 200px; overflow-y: auto; z-index: 1050; padding: 0; margin: 0; }
            .custom-dropdown .dropdown-list.active { display: block; }
            .custom-dropdown .dropdown-list li { padding: 8px 12px; cursor: pointer; transition: background-color 0.2s ease; list-style: none; font-size: 0.95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;}
            .custom-dropdown .dropdown-list li:hover { background-color: #e9ecef; }
            .custom-dropdown .dropdown-list li.selected{ background-color: var(--primary-color); color: white; }
            .alert { border-radius: 5px; font-size: 0.95rem; margin-top: 1.5rem;}
            .alert ul { padding-left: 1.5rem; margin-bottom: 0;}
    </style>
</head>
<body>
    <?php include 'navbar.php'; // Ensure correct path and passes $loggedInStaffID ?>

    <div class="hero-section">
        <h1>Patient Diagnosis Assignment</h1>
        <p>Assign Diagnosis and Manage Admission Status</p>
    </div>

    <div class="container mt-4 mb-5">

         <!-- Display Messages -->
         <div id="messageArea" style="min-height: 60px;">
             <?php if (!empty($message)) echo $message; // Display success/error message from POST/Redirect ?>
             <?php if ($fetch_error_patients || $fetch_error_diagnoses || $fetch_error_details): ?>
                 <div class="alert alert-warning alert-dismissible fade show" role="alert">
                     <i class="fas fa-exclamation-circle me-2"></i> Could not load all required data. Some lists might be incomplete.
                     <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                 </div>
             <?php endif; ?>
        </div>

        <!-- Assign Diagnosis Form Card -->
        <div class="card">
            <div class="card-header"><i class="fas fa-stethoscope me-2"></i>Assign Diagnosis to Patient</div>
            <div class="card-body form-container">
              <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?staffid=' . urlencode($loggedInStaffID ?? ''); ?>" method="POST">
                  <input type="hidden" name="loggedInStaffID" value="<?= htmlspecialchars($loggedInStaffID ?? '') ?>">

                 <div class="form-group mb-3">
                  <label for="patient_dropdown_input" class="form-label">Select Patient (Status: Booked) <span class="text-danger">*</span></label>
                    <div class="custom-dropdown">
                      <input type="text" class="form-control dropdown-input" placeholder="Select Patient..." readonly id="patient_dropdown_input" required>
                      <ul class="dropdown-list" id="patient_dropdown_list">
                           <?php if (!empty($patients)): ?>
                               <?php foreach ($patients as $patient) : ?>
                                   <li data-value="<?php echo htmlspecialchars($patient['PatientID']); ?>">
                                       <?= htmlspecialchars($patient['PatientName']) . " (ID: " . htmlspecialchars($patient['PatientID']) . ", IC: " . htmlspecialchars($patient['ic']) . ")" ?>
                                    </li>
                               <?php endforeach; ?>
                            <?php else: ?>
                                <li data-value="" class="text-muted p-2">No patients with 'Booked' status found.</li>
                           <?php endif; ?>
                      </ul>
                        <input type="hidden" name="patient_id" id="patient_id" value="">
                   </div>
                  </div>

                  <div class="form-group mb-3">
                    <label for="diagnosis_dropdown_input" class="form-label">Select Diagnosis <span class="text-danger">*</span></label>
                      <div class="custom-dropdown">
                         <input type="text" class="form-control dropdown-input" placeholder="Select Diagnosis..." readonly id="diagnosis_dropdown_input" required>
                            <ul class="dropdown-list" id="diagnosis_dropdown_list">
                                 <?php if (!empty($diagnoses)): ?>
                                     <?php foreach ($diagnoses as $diagnosis) : ?>
                                        <li data-value="<?php echo htmlspecialchars($diagnosis['DiagnosisID']); ?>">
                                            <?= htmlspecialchars($diagnosis['DiagnosisName']) . " (ID: " . htmlspecialchars($diagnosis['DiagnosisID']) . ")" ?>
                                        </li>
                                     <?php endforeach; ?>
                                 <?php else: ?>
                                      <li data-value="" class="text-muted p-2">No diagnoses found.</li>
                                 <?php endif; ?>
                            </ul>
                            <input type="hidden" name="diagnosis_id" id="diagnosis_id" value="">
                       </div>
                  </div>

                  <div class="form-group mb-3">
                    <label for="doctor_notes" class="form-label">Doctor's Notes (Remark) <span class="text-danger">*</span></label>
                    <textarea name="doctor_notes" id="doctor_notes" class="form-control" placeholder="Enter notes about the diagnosis..." rows="3" required></textarea>
                  </div>

                  <button type="submit" class="btn-submit"><i class="fas fa-check-circle me-1"></i> Assign Diagnosis & Set Status to 'Wait'</button>
            </form>

        </div> <!-- End card-body -->
        </div> <!-- End card -->


        <!-- Patients Awaiting Admission Decision Table Card -->
        <div class="card patients-container mt-4">
            <div class="card-header"><i class="fas fa-user-clock me-2"></i>Patients Awaiting Admission Decision (Status: Wait)</div>
            <div class="card-body">
                <div class="table-responsive">
                   <table class="table table-striped table-hover table-sm">
                      <thead>
                         <tr>
                               <th>Patient Name</th>
                               <th>Assigned Diagnosis</th>
                               <th>Doctor's Notes (Remark)</th>
                               <th class="text-center">Admit to Ward?</th>
                            </tr>
                      </thead>
                        <tbody>
                         <?php if (!empty($diagnosisDetails)): ?>
                            <?php foreach ($diagnosisDetails as $diagnosis): ?>
                                  <tr>
                                     <td><?= htmlspecialchars($diagnosis['PatientName'] ?? 'N/A'); ?></td>
                                     <td><?= htmlspecialchars($diagnosis['DiagnosisName'] ?? 'N/A'); ?></td>
                                     <td><?= htmlspecialchars($diagnosis['DoctorNotes'] ?? 'N/A'); ?></td>
                                     <td class="text-center action-forms">
                                        <!-- YES Button/Form -->
                                        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?staffid=' . urlencode($loggedInStaffID ?? ''); ?>" style="display: inline;">
                                           <input type="hidden" name="patient_id" value="<?= htmlspecialchars($diagnosis['PatientID']); ?>">
                                           <input type="hidden" name="status" value="Diagnosed"> <!-- Set to Diagnosed -->
                                           <input type="hidden" name="action" value="update_status">
                                           <button type="submit" class="btn btn-admit btn-sm" title="Admit Patient (Set Status to Diagnosed)">Yes <i class="fas fa-check"></i></button>
                                        </form>
                                        <!-- NO Button/Form -->
                                        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?staffid=' . urlencode($loggedInStaffID ?? ''); ?>" style="display: inline;">
                                            <input type="hidden" name="patient_id" value="<?= htmlspecialchars($diagnosis['PatientID']); ?>">
                                            <input type="hidden" name="status" value="Completed"> <!-- Set to Completed -->
                                            <input type="hidden" name="action" value="update_status">
                                            <button type="submit" class="btn btn-complete btn-sm" title="Do Not Admit (Set Status to Completed)">No <i class="fas fa-times"></i></button>
                                         </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                         <?php else: ?>
                               <tr><td colspan="4" class="text-center text-muted">No patients currently awaiting admission decision.</td></tr>
                         <?php endif; ?>
                       </tbody>
                </table>
             </div> <!-- End table-responsive -->
            </div> <!-- End card-body -->
          </div> <!-- End card -->
    </div> <!-- End container -->



    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script> <!-- jQuery needed for dropdown script -->
     <script>
     $(document).ready(function() {
         // Function to handle dropdown interactions
         function setupDropdown(inputId, listId, hiddenId) {
             const $input = $('#' + inputId);
             const $list = $('#' + listId);
             const $hidden = $('#' + hiddenId);

             // Show/Hide list on input click
             $input.on('click', function(e) {
                 // Close other dropdowns first
                 $('.dropdown-list').not($list).removeClass('active');
                 // Toggle current dropdown
                 $list.toggleClass('active');
                 e.stopPropagation();
             });

             // Select item from list
             $list.on('click', 'li', function() {
                 if ($(this).hasClass('disabled')) return;

                 var value = $(this).data('value');
                 var text = $(this).text().trim();

                 $input.val(text);
                 $hidden.val(value);
                 $list.find('li').removeClass('selected');
                 $(this).addClass('selected');
                 $list.removeClass('active');
             });
         }

         // Setup both dropdowns
         setupDropdown('patient_dropdown_input', 'patient_dropdown_list', 'patient_id');
         setupDropdown('diagnosis_dropdown_input', 'diagnosis_dropdown_list', 'diagnosis_id');

         // Hide dropdown list if clicked outside
         $(document).on('click', function(e) {
             // Check if the click is outside the dropdown components
             if (!$(e.target).closest('.custom-dropdown').length) {
                 $('.dropdown-list').removeClass('active');
             }
         });


          // --- Auto-dismiss alerts ---
         const alerts = document.querySelectorAll('.alert-success, .alert-danger, .alert-warning');
         alerts.forEach(function(alert) {
             if (typeof bootstrap !== 'undefined' && bootstrap.Alert) {
                setTimeout(() => { bootstrap.Alert.getOrCreateInstance(alert)?.close(); }, 5000); // 5 seconds
             } else {
                 // Fallback if Bootstrap JS isn't loaded properly
                 setTimeout(() => { alert.style.display = 'none'; }, 5000);
             }
        });

     }); // End document ready

     // Simple client-side validation (Optional)
     // function validateFormClientSide() { ... return true/false ... }

   </script>
</body>
</html>
<?php
// Close the database connection at the very end
if (isset($conn) && $conn instanceof mysqli && $conn->thread_id) {
    $conn->close();
}
?>