<?php
session_start(); // Start session if needed (for potential staff ID logging)

// Include the database connection (mysqli for azwarie_dss)
include 'connection.php'; // This should define $conn using mysqli

// Check connection
if (!$conn || $conn->connect_error) {
    die("Database connection failed: " . ($conn ? $conn->connect_error : 'Unknown error'));
}

// Set default timezone
date_default_timezone_set('Asia/Kuala_Lumpur'); // Replace with your timezone

// Get logged-in staff ID (from session, for actions like audit trail, form submission)
$loggedInStaffID = $_SESSION['staffid'] ?? null;

// Get staff ID from URL (primarily for maintaining navigation state in links)
$staffIDFromURL = null;
if(isset($_GET['staffid'])) {
    $staffIDFromURL = $_GET['staffid'];
}

// Define $current_page for navbar active state
$current_page = basename($_SERVER['PHP_SELF']);


// Function to add staffid to links
function addStaffIdToLink($url, $staffIdParam) {
    if ($staffIdParam !== null && $staffIdParam !== '') {
        $separator = (strpos($url, '?') === false) ? '?' : '&';
        return $url . $separator . 'staffid=' . urlencode($staffIdParam);
    }
    return $url;
}

// --- Reusable Audit Trail Function (Optional but Recommended) ---
function logAuditTrail($conn_audit, $staffID_audit_param, $action, $description) {
    if (!$conn_audit) return;
    try {
        $sql = "INSERT INTO AUDIT_TRAIL (StaffID, Action, Description, Timestamp) VALUES (?, ?, ?, NOW())";
        $stmt = $conn_audit->prepare($sql);
        if ($stmt === false) { throw new Exception("Prepare failed (Audit Trail): " . $conn_audit->error); }
        $actorStaffID = !empty($staffID_audit_param) ? $staffID_audit_param : 'SYSTEM';
        $stmt->bind_param('sss', $actorStaffID, $action, $description);
        if (!$stmt->execute()) { throw new Exception("Execute failed (Audit Trail): " . $stmt->error); }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Audit trail error: " . $e->getMessage());
    }
}


// Initialize variables for form data and errors
$PatientID = "";
$PatientName = ""; $gender = "Male"; $race = ""; $other_race_text_val = ""; $phone_number = ""; $address = ""; $email = "";
$errors = [];
$successMessage = "";

// Define common race options (customize as needed for your context)
$raceOptions = [
    'Malay', 'Chinese', 'Indian', 'Indigenous/Bumiputera (Non-Malay)', 'Eurasian', 'Other Asian', 'Caucasian', 'African', 'Hispanic/Latino', 'Middle Eastern', 'Other'
];


// Handle POST request (INSERT or UPDATE)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $PatientID = trim($_POST['patient_id'] ?? '');
    $PatientName = trim($_POST['name'] ?? '');
    $gender = $_POST['gender'] ?? 'Male';

    // Determine the final race value
    $selectedRaceFromDropdown = trim($_POST['race'] ?? '');
    $otherRaceTextValue = trim($_POST['other_race_text'] ?? '');
    $finalRaceToSave = $selectedRaceFromDropdown;

    if ($selectedRaceFromDropdown === 'Other') {
        if (!empty($otherRaceTextValue)) {
            $finalRaceToSave = $otherRaceTextValue;
        } else {
            $finalRaceToSave = 'Other';
        }
    }
    $race = $selectedRaceFromDropdown;
    $other_race_text_val = $otherRaceTextValue;


    $phone_number = trim($_POST['phone_number'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $email = trim($_POST['email'] ?? '');

    // --- Server-Side Validation ---
    if (empty($PatientName)) $errors[] = 'Patient Name is required.';
    if (empty($gender) || !in_array($gender, ['Male', 'Female'])) $errors[] = 'Valid Gender is required.';
    if (empty($finalRaceToSave)) $errors[] = 'Race is required.';

    $phonePattern = '/^\d{2,3}-?\d{7,8}$/';
    if (!preg_match($phonePattern, $phone_number)) $errors[] = 'Phone number must be in format XXX-XXXXXXX or XXXXXXXXXX.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid Email is required.';
    if (empty($address)) $errors[] = 'Address is required.';


    // --- Uniqueness Checks are now handled by the Stored Procedure ---
    // The old uniqueness check blocks are removed from here.

    // --- Proceed if NO basic validation errors ---
    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            // =====================================================================
            // MODIFICATION START: Call the Stored Procedure instead of direct SQL
            // =====================================================================

            // Prepare the CALL statement for the stored procedure
            $stmt = $conn->prepare("CALL ManagePatient(?, ?, ?, ?, ?, ?, ?, @new_id, @status_code, @status_message)");
            if ($stmt === false) {
                throw new Exception("Prepare failed (CALL ManagePatient): " . $conn->error);
            }

            // Bind the IN parameters
            $id_param = !empty($PatientID) ? $PatientID : null;
            $stmt->bind_param(
                "sssssss",
                $id_param,
                $PatientName,
                $gender,
                $finalRaceToSave,
                $phone_number,
                $address,
                $email
            );

            // Execute the stored procedure
            if (!$stmt->execute()) {
                throw new Exception("Execute failed (CALL ManagePatient): " . $stmt->error);
            }
            $stmt->close();

            // Fetch the OUT parameters from the procedure
            $select_out = $conn->query("SELECT @new_id AS new_id, @status_code AS status_code, @status_message AS status_message");
            if (!$select_out) {
                throw new Exception("Failed to retrieve OUT parameters: " . $conn->error);
            }
            $out_params = $select_out->fetch_assoc();
            $select_out->free();

            $newly_created_id = $out_params['new_id'];
            $status_code = (int)$out_params['status_code'];
            $status_message = $out_params['status_message'];

            // Check the status code returned by the procedure
            if ($status_code === 0) { // Success
                $successMessage = $status_message; // Use message from procedure
                
                // Determine action for audit log based on original PatientID
                if (!empty($PatientID)) {
                    logAuditTrail($conn, $loggedInStaffID, 'Patient Update', "Updated patient record {$PatientID}.");
                } else {
                    logAuditTrail($conn, $loggedInStaffID, 'Patient Registration', "Registered new patient {$newly_created_id} ({$PatientName}).");
                    // Clear form fields on successful registration
                    $PatientID = $PatientName = $gender = $race = $other_race_text_val = $phone_number = $address = $email = "";
                    $gender = "Male";
                    $race = "";
                }
                
                if (!$conn->commit()) {
                    throw new Exception("Transaction commit failed: " . $conn->error);
                }

            } else { // An error occurred (e.g., duplicate entry)
                $errors[] = $status_message; // Add procedure's error message to display
                $conn->rollback(); // Rollback any potential changes
            }
            

            
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Database operation failed: " . $e->getMessage());
            $errors[] = "An error occurred while saving patient data. Please try again.";
        }
    }
} else {
    // This part remains unchanged
    if (isset($_GET['edit_id'])) {
        $editPatientID = $_GET['edit_id'];
    }
}


// --- Fetch Patients for Display Table (Pagination) ---
// THIS ENTIRE SECTION REMAINS UNCHANGED
$itemsPerPage = 20;
$page = isset($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $itemsPerPage;
$totalPatients = 0;
$totalPages = 0;
$patientsData = [];
$fetch_error = null;

try {
    $totalPatientsQuery = "SELECT COUNT(*) AS total FROM patients";
    $totalResult = $conn->query($totalPatientsQuery);
    if ($totalResult) {
        $totalPatients = $totalResult->fetch_assoc()['total'];
        $totalPages = ceil($totalPatients / $itemsPerPage);
        $totalResult->free();
    } else { throw new Exception("Failed to get total patient count: " . $conn->error); }

    $patientsQuery = "SELECT PatientID, PatientName, Gender, Race, Phone_Number, Address, Email FROM patients ORDER BY PatientID ASC LIMIT ? OFFSET ?";
    $stmt_patients = $conn->prepare($patientsQuery);
    if ($stmt_patients === false) throw new Exception("Prepare failed (Fetch Patients): " . $conn->error);
    $stmt_patients->bind_param("ii", $itemsPerPage, $offset);
    if (!$stmt_patients->execute()) throw new Exception("Execute failed (Fetch Patients): " . $stmt_patients->error);
    $patientsResult = $stmt_patients->get_result();
    if ($patientsResult) {
        $patientsData = $patientsResult->fetch_all(MYSQLI_ASSOC);
        $patientsResult->free();
    } else { throw new Exception("Getting result set failed (Fetch Patients): " . $stmt_patients->error); }
    $stmt_patients->close();
} catch (Exception $e) {
    $fetch_error = "Error fetching patient list: " . $e->getMessage();
    error_log($fetch_error);
}
?>

<!DOCTYPE html>
<!-- THE REST OF YOUR HTML, CSS, and JAVASCRIPT REMAINS EXACTLY THE SAME -->
<!-- ... (pasting the full HTML/CSS/JS block) ... -->
<html lang="en">
<head>
    <meta charset="UTF-T">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Registration - WeCare</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        :root { --primary-color: #007bff; --secondary-color: #f0f0f0; --background-color: #f8f9fa; --text-color: #343a40; --accent-color: #ffc107; --form-bg-color: #ffffff; --form-border-color: #dee2e6; --input-focus-color: #86b7fe; --danger-color: #dc3545; --success-color: #198754; }
        body { font-family: 'Poppins', sans-serif; margin: 0; padding: 0; background-color: var(--background-color); padding-top: 70px; }
        .navbar { position: sticky; top: 0; z-index: 1030; background-color: white !important; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05); padding: 0.5rem 1rem; }
        .navbar-brand { font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 700; color: var(--primary-color) !important; }
        .navbar .nav-link { color: #495057 !important; font-weight: 500; transition: color 0.2s ease; padding: 0.5rem 1rem; }
        .navbar .nav-link:hover, .navbar .nav-item.active .nav-link { color: var(--primary-color) !important; }
        .navbar .nav-item.active .nav-link { font-weight: 700 !important; }
        .hero-section { background-color: var(--primary-color); color: white; padding: 60px 0; text-align: center; margin-bottom: 30px; }
        .card { border: none; border-radius: 8px; box-shadow: 0 2px 5px rgba(0, 0, 0, 0.07); margin-bottom: 20px; }
        .card-header { background-color: #e9ecef; border-bottom: 1px solid #dee2e6; font-weight: 600; padding: 0.9rem 1.25rem; font-size: 1.1rem; color: #495057; }
        .form-label { display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--text-color); font-size: 0.95rem; }
        .form-control, .form-select { width: 100%; padding: 0.5rem 0.85rem; margin-bottom: 1rem; border: 1px solid var(--form-border-color); border-radius: 5px; font-size: 1rem; }
        .form-control:focus, .form-select:focus { border-color: var(--input-focus-color); outline: none; box-shadow: 0 0 4px rgba(0, 123, 255, 0.2); }
        textarea.form-control { min-height: 80px; resize: vertical; }
        .btn-submit { background: linear-gradient(to right, #005c99, #007bff); color: white; border: none; padding: 0.6rem 1.5rem; font-size: 1rem; font-weight: 600; border-radius: 5px; display: block; width: 100%; margin-top: 1rem; }
        .table-responsive { display: block; width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 1rem; background-color: white; font-size: 0.9rem; }
        table th, table td { padding: 0.75rem; text-align: left; vertical-align: middle; border: 1px solid #dee2e6; }
        table thead th { background-color: #f8f9fa; font-weight: 600; color: #495057; white-space: nowrap; }
        .edit-btn { background-color: #ffc107; border: none; padding: 3px 8px; border-radius: 4px; color: #212529;}
        .pagination { display: flex; justify-content: center; list-style: none; padding: 0; margin-top: 1.5rem; }
        .pagination li { margin: 0 3px; }
        .pagination a, .pagination span { padding: 0.5rem 0.85rem; text-decoration: none; color: var(--primary-color); background-color: #ffffff; border: 1px solid #dee2e6; border-radius: 4px; }
        .pagination .active a, .pagination .active span { background-color: var(--primary-color); color: #ffffff; border-color: var(--primary-color); }
        .alert { border-radius: 5px; font-size: 0.95rem; margin-top: 1.5rem;}
        .invalid-feedback { display: block; width: 100%; margin-top: -0.75rem; margin-bottom: 0.5rem; font-size: .875em; color: var(--danger-color); }
        .is-invalid { border-color: var(--danger-color) !important; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-light" style="background-color: white; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
    <div class="container">
        <a class="navbar-brand" href="<?php echo addStaffIdToLink('https://localhost/dss/staff/admin/AdminLandingPage.php', $staffIDFromURL); ?>" style="font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 700; color: #007bff; position: absolute; top: 10px; left: 15px;">eDSS</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item <?php echo ($current_page == 'index.php' || $current_page == 'AdminLandingPage.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="<?php echo addStaffIdToLink('index.php', $staffIDFromURL); ?>" style="color: black;">Home</a>
                </li>
                <li class="nav-item <?php echo ($current_page == 'register_patient.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="<?php echo addStaffIdToLink('register_patient.php', $staffIDFromURL); ?>" style="color: black;">Patient Registration</a>
                </li>
                <li class="nav-item <?php echo ($current_page == 'assign_ed_diagnosis.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="<?php echo addStaffIdToLink('assign_ed_diagnosis.php', $staffIDFromURL); ?>" style="color: black;">Patient Diagnosis</a>
                </li>
                <li class="nav-item <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="<?php echo addStaffIdToLink('dashboard.php', $staffIDFromURL); ?>" style="color: black;">Real-Time Dashboards</a>
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
        <h1>Patient Registration</h1>
        <p>Register New Patients or Update Existing Records</p>
    </div>

    <div class="container mt-4 mb-5">
        <div id="messageArea" style="min-height: 60px;">
             <?php if (!empty($errors)): ?>
                <div class='alert alert-danger alert-dismissible fade show' role='alert'>
                     <h5 class="alert-heading">Validation Errors:</h5>
                     <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                     </ul>
                    <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
                </div>
             <?php endif; ?>
             <?php if (!empty($successMessage)): ?>
               <div class='alert alert-success alert-dismissible fade show' role='alert'>
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($successMessage); ?>
                    <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
               </div>
             <?php endif; ?>
              <?php if ($fetch_error): ?>
                 <div class="alert alert-warning" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($fetch_error); ?> Patient list may be incomplete.
                 </div>
             <?php endif; ?>
        </div>

       <div class="card">
           <div class="card-header"><i class="fas fa-user-plus me-2"></i>Register / Update Patient</div>
           <div class="card-body form-container">
              <form method="POST" action="<?php echo addStaffIdToLink(htmlspecialchars($_SERVER['PHP_SELF']), $loggedInStaffID); ?>" id="patientForm" onsubmit="return validateFormClientSide()">
                   <input type="hidden" name="patient_id" id="patient_id" value="<?php echo htmlspecialchars($PatientID); ?>">

                    <div class="mb-3">
                        <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" id="name" value="<?php echo htmlspecialchars($PatientName); ?>" required>
                        <div id="nameError" class="invalid-feedback"></div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                           <label for="gender" class="form-label">Gender <span class="text-danger">*</span></label>
                           <select name="gender" id="gender" class="form-select" required>
                               <option value="Male" <?php if($gender == "Male") echo "selected"; ?>>Male</option>
                               <option value="Female" <?php if($gender == "Female") echo "selected"; ?>>Female</option>
                           </select>
                            <div id="genderError" class="invalid-feedback"></div>
                       </div>
                       <div class="col-md-6 mb-3">
                           <label for="race" class="form-label">Race <span class="text-danger">*</span></label>
                           <select name="race" id="race_dropdown" class="form-select" required onchange="toggleOtherRaceInput()">
                               <option value="">Select Race</option>
                               <?php
                                foreach ($raceOptions as $option):
                                    $isSelected = false;
                                    if ($option === 'Other' && $race === 'Other') {
                                        $isSelected = true;
                                    } elseif ($option !== 'Other' && $race === $option) {
                                        $isSelected = true;
                                    }
                               ?>
                               <option value="<?php echo htmlspecialchars($option); ?>" <?php if($isSelected) echo "selected"; ?>>
                                   <?php echo htmlspecialchars($option); ?>
                               </option>
                               <?php endforeach; ?>
                           </select>
                           <div id="raceError" class="invalid-feedback"></div>
                       </div>
                    </div>
                    <div class="mb-3" id="otherRaceContainer" style="display: none;">
                        <label for="other_race_text" class="form-label">Please specify Race </label>
                        <input type="text" class="form-control" name="other_race_text" id="other_race_text" value="<?php echo htmlspecialchars($other_race_text_val); ?>">
                        <div id="otherRaceError" class="invalid-feedback"></div>
                    </div>


                   <div class="mb-3">
                        <label for="phone_number" class="form-label">Phone Number <span class="text-danger">*</span></label>
                       <input type="tel" class="form-control" name="phone_number" id="phone_number" value="<?php echo htmlspecialchars($phone_number); ?>" placeholder="e.g., 012-3456789" required>
                        <div id="phoneError" class="invalid-feedback"></div>
                   </div>

                   <div class="mb-3">
                        <label for="address" class="form-label">Address <span class="text-danger">*</span></label>
                       <textarea name="address" id="address" class="form-control" required><?php echo htmlspecialchars($address); ?></textarea>
                        <div id="addressError" class="invalid-feedback"></div>
                   </div>

                  <div class="mb-3">
                      <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                     <input type="email" class="form-control" name="email" id="email" value="<?php echo htmlspecialchars($email); ?>" placeholder="patient@example.com" required>
                      <div id="emailError" class="invalid-feedback"></div>
                  </div>

                    <button type="submit" class="btn-submit" id="submitBtn">
                        <i class="fas fa-save me-1"></i> <span id="submitBtnText">Register Patient</span>
                    </button>
                     <button type="button" class="btn btn-secondary mt-2" onclick="resetForm()" id="resetBtn" style="width: 100%;">
                         <i class="fas fa-times me-1"></i> Clear Form / Cancel Edit
                     </button>
                </form>
           </div>
       </div>

        <div class="patients-container card">
           <div class="card-header"><i class="fas fa-users me-2"></i>Registered Patients List</div>
           <div class="card-body">
                <div class="table-responsive">
                     <table class="table table-striped table-hover table-sm">
                        <thead>
                          <tr>
                                <th>Patient ID</th>
                                <th>Name</th>
                                <th>Gender</th>
                                <th>Race</th>
                                <th>Phone</th>
                                <th>Address</th>
                                <th>Email</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                          <?php if (!empty($patientsData)) : ?>
                               <?php foreach ($patientsData as $row): ?>
                                 <tr>
                                    <td><?php echo htmlspecialchars($row['PatientID']); ?></td>
                                    <td><?php echo htmlspecialchars($row['PatientName']); ?></td>
                                    <td><?php echo htmlspecialchars($row['Gender']); ?></td>
                                    <td><?php echo htmlspecialchars($row['Race']); ?></td>
                                    <td><?php echo htmlspecialchars($row['Phone_Number']); ?></td>
                                    <td><?php echo htmlspecialchars($row['Address']); ?></td>
                                    <td><?php echo htmlspecialchars($row['Email']); ?></td>
                                    <td class="text-center">
                                         <button class='edit-btn'
                                            data-patient='<?php echo htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8'); ?>'
                                            onclick='editPatient(JSON.parse(this.getAttribute("data-patient"))); return false;'
                                            title='Edit Patient'>
                                             <i class='fas fa-edit'></i>
                                         </button>
                                     </td>
                                </tr>
                               <?php endforeach; ?>
                           <?php else: ?>
                               <tr><td colspan='8' class="text-center text-muted">No patients registered yet or none on this page.</td></tr>
                           <?php endif; ?>
                        </tbody>
                   </table>
                </div>

                <?php if ($totalPages > 1): ?>
                <nav aria-label="Patients Pagination" class="mt-3">
                     <ul class="pagination justify-content-center">
                         <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                             <a class="page-link" href="<?php echo addStaffIdToLink('?page='.($page - 1), $staffIDFromURL); ?>" aria-label="Previous">«</a>
                         </li>
                          <?php
                           $range = 2; $start = max(1, $page - $range); $end = min($totalPages, $page + $range);
                           if ($start > 1) { echo '<li class="page-item"><a class="page-link" href="'.addStaffIdToLink('?page=1', $staffIDFromURL).'">1</a></li>'; if ($start > 2) { echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; } }
                           for ($i = $start; $i <= $end; $i++): ?>
                             <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                                   <a class="page-link" href="<?php echo addStaffIdToLink('?page='.$i, $staffIDFromURL); ?>"><?php echo $i; ?></a>
                             </li>
                           <?php endfor;
                           if ($end < $totalPages) { if ($end < $totalPages - 1) { echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; } echo '<li class="page-item"><a class="page-link" href="'.addStaffIdToLink('?page='.$totalPages, $staffIDFromURL).'">'.$totalPages.'</a></li>'; }
                          ?>
                         <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                             <a class="page-link" href="<?php echo addStaffIdToLink('?page='.($page + 1), $staffIDFromURL); ?>" aria-label="Next">»</a>
                         </li>
                      </ul>
                 </nav>
                <?php endif; ?>
             </div>
         </div>
     </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const raceOptionsJS = <?php echo json_encode($raceOptions); ?>;

        function toggleOtherRaceInput() {
            const raceSelect = document.getElementById('race_dropdown');
            const otherRaceContainer = document.getElementById('otherRaceContainer');
            const otherRaceText = document.getElementById('other_race_text');

            if (raceSelect.value === 'Other') {
                otherRaceContainer.style.display = 'block';
            } else {
                otherRaceContainer.style.display = 'none';
                otherRaceText.value = '';
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            toggleOtherRaceInput();

            const alerts = document.querySelectorAll('.alert-success, .alert-danger, .alert-warning');
            alerts.forEach(function(alert) {
                if (typeof bootstrap !== 'undefined' && bootstrap.Alert) {
                   setTimeout(() => { bootstrap.Alert.getOrCreateInstance(alert)?.close(); }, 7000);
                } else {
                    setTimeout(() => { alert.style.display = 'none'; }, 7000);
                }
           });
        });

        function editPatient(patient) {
            console.log("Patient data received by editPatient:", patient);
            document.getElementById('patientForm').scrollIntoView({ behavior: 'smooth', block: 'start' });

            document.getElementById('patient_id').value = patient.PatientID || '';
            document.getElementById('name').value = patient.PatientName || '';

            const raceValueFromDB = patient.Race || '';
            const raceSelect = document.getElementById('race_dropdown');
            const otherRaceTextInput = document.getElementById('other_race_text');

            let isStandardRace = raceOptionsJS.includes(raceValueFromDB);

            if (raceValueFromDB === 'Other' || !isStandardRace) {
                raceSelect.value = 'Other';
                if (raceValueFromDB !== 'Other' && raceValueFromDB !== '') {
                     otherRaceTextInput.value = raceValueFromDB;
                } else {
                    otherRaceTextInput.value = '';
                }
            } else {
                raceSelect.value = raceValueFromDB;
                otherRaceTextInput.value = '';
            }
            toggleOtherRaceInput();

            document.getElementById('gender').value = patient.Gender || 'Male';
            document.getElementById('phone_number').value = patient.Phone_Number || '';
            document.getElementById('address').value = patient.Address || '';
            document.getElementById('email').value = patient.Email || '';

            document.getElementById('submitBtnText').textContent = 'Update Patient';
            document.getElementById('submitBtn').classList.remove('btn-success');
            document.getElementById('submitBtn').classList.add('btn-warning');
        }

        function resetForm() {
            const form = document.getElementById('patientForm');
            form.reset();
            document.getElementById('patient_id').value = '';
            document.getElementById('submitBtnText').textContent = 'Register Patient';
            document.getElementById('submitBtn').classList.remove('btn-warning');
            document.getElementById('other_race_text').value = '';
            toggleOtherRaceInput();

            form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
            form.querySelectorAll('.invalid-feedback').forEach(el => el.textContent = '');
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

       function validateFormClientSide() {
            let isValid = true;
            document.querySelectorAll('.invalid-feedback').forEach(el => el.textContent = '');
            document.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));

            const name = document.getElementById('name');
            const gender = document.getElementById('gender');
            const raceSelect = document.getElementById('race_dropdown');
            const otherRaceText = document.getElementById('other_race_text');
            const phone_number = document.getElementById('phone_number');
            const email = document.getElementById('email');
            const address = document.getElementById('address');

             if (name.value.trim() === '') {
                 isValid = false; name.classList.add('is-invalid');
                 document.getElementById('nameError').textContent = 'Name is required.';
             }
             if (gender.value === '') {
                 isValid = false; gender.classList.add('is-invalid');
                 document.getElementById('genderError').textContent = 'Gender is required.';
             }
             if (raceSelect.value === '') {
                 isValid = false; raceSelect.classList.add('is-invalid');
                 document.getElementById('raceError').textContent = 'Race is required.';
             }

            const phonePattern = /^\d{2,3}-?\d{7,8}$/;
             if (!phonePattern.test(phone_number.value)) {
                 isValid = false; phone_number.classList.add('is-invalid');
                 document.getElementById('phoneError').textContent = 'Phone must be in XXX-XXXXXXX or XXXXXXXXXX format.';
             }
             if (!email.value.includes('@') || !email.value.includes('.')) {
                isValid = false; email.classList.add('is-invalid');
                 document.getElementById('emailError').textContent = 'Please enter a valid email address.';
             }
             if (address.value.trim() === '') {
                 isValid = false; address.classList.add('is-invalid');
                 document.getElementById('addressError').textContent = 'Address is required.';
             }
            if (!isValid) {
                return false;
            }
            return true;
         }
    </script>
</body>
</html>
<?php
if (isset($conn) && $conn instanceof mysqli && $conn->thread_id) {
    $conn->close();
}
?>