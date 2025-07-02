<?php
session_start();
include 'connection.php';

if (!$conn || $conn->connect_error) {
    die("Database connection failed: " . ($conn ? $conn->connect_error : 'Unknown error'));
}
date_default_timezone_set('Asia/Kuala_Lumpur');

$loggedInStaffID_Session = $_SESSION['staffid'] ?? null;
$staffIDFromURL = $_GET['staffid'] ?? null;
$current_page = basename($_SERVER['PHP_SELF']);

// --- Helper Functions (addStaffIdToLink, logAuditTrail - same as before) ---
function addStaffIdToLink($url, $staffIdParam) {
    if ($staffIdParam !== null && $staffIdParam !== '') {
        $separator = (strpos($url, '?') === false) ? '?' : '&';
        return $url . $separator . 'staffid=' . urlencode($staffIdParam);
    }
    return $url;
}
function logAuditTrail($conn_audit, $staffID_audit_param, $action, $description) {
    if (!$conn_audit) return;
    try {
        $sql_audit = "INSERT INTO AUDIT_TRAIL (StaffID, Action, Description, Timestamp) VALUES (?, ?, ?, NOW())";
        $stmt_audit = $conn_audit->prepare($sql_audit);
        if ($stmt_audit === false) { throw new Exception("Prepare failed (Audit Trail): " . $conn_audit->error); }
        $actorStaffID = !empty($staffID_audit_param) ? $staffID_audit_param : 'SYSTEM';
        $stmt_audit->bind_param('sss', $actorStaffID, $action, $description);
        if (!$stmt_audit->execute()) { throw new Exception("Execute failed (Audit Trail): " . $stmt_audit->error); }
        $stmt_audit->close();
    } catch (Exception $e) {
        error_log("Audit trail error: " . $e->getMessage());
    }
}
// --- End Helper Functions ---

$StayID_form = "";
$Intime_form_display = ""; // For display only during check-in
$Disposition_form = "";
$PatientID_FK_form = "";
$StaffID_FK_form = "";

$errors = [];
$successMessage = "";

// Fetch Patients for dropdown
$patientsList = [];
$sql_patients = "SELECT PatientID, PatientName FROM patients ORDER BY PatientName ASC";
$result_patients = $conn->query($sql_patients);
if ($result_patients) {
    while ($row = $result_patients->fetch_assoc()) {
        $patientsList[] = $row;
    }
    $result_patients->free();
} else {
    $errors[] = "Error fetching patients list: " . $conn->error;
}

// Fetch Staff for dropdown - CORRECTED TO USE 'Name' column
$staffList = [];
$sql_staff = "SELECT StaffID, Name FROM staff ORDER BY Name ASC"; // Use 'Name'
$result_staff = $conn->query($sql_staff);
if ($result_staff) {
    while ($row = $result_staff->fetch_assoc()) {
        $staffList[] = $row;
    }
    $result_staff->free();
} else {
    error_log("Error fetching staff list: " . $conn->error);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $StayID_form = trim($_POST['stay_id_form'] ?? '');
    $PatientID_FK_form = trim($_POST['patient_id_fk_form'] ?? '');
    $StaffID_FK_form = trim($_POST['staff_id_fk_form'] ?? '');
    $isCheckoutAction = isset($_POST['is_checkout_action']) && $_POST['is_checkout_action'] === '1';

    if ($isCheckoutAction) {
        $Disposition_form = trim($_POST['disposition_form'] ?? '');
        // Outime for checkout will be set to NOW() directly in SQL or just before
    }

    // --- Server-Side Validation ---
    if (empty($PatientID_FK_form)) $errors[] = 'Patient is required.';
    if (empty($StaffID_FK_form)) $errors[] = 'Attending Staff is required.';

    if ($isCheckoutAction) {
        if (empty($StayID_form)) $errors[] = 'Stay ID is missing for checkout.'; // Should not happen
        if (empty($Disposition_form)) $errors[] = 'Disposition is required for checkout.';
    }

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            if (!empty($StayID_form) && $isCheckoutAction) { // --- CHECK-OUT Process ---
                $dbOutime = date('Y-m-d H:i:s'); // Current time for checkout

                // Fetch Intime to prevent accidental updates if needed, though not strictly necessary here
                // $checkSql = "SELECT Intime FROM edstays WHERE StayID = ?"; ...

                $sql = "UPDATE edstays SET Outime = ?, Disposition = ? WHERE StayID = ? AND Outime IS NULL"; // Ensure we only checkout ongoing stays
                $stmt = $conn->prepare($sql);
                if ($stmt === false) throw new Exception("Prepare failed (Checkout EDStay): " . $conn->error);
                $stmt->bind_param("sss", $dbOutime, $Disposition_form, $StayID_form);
                if (!$stmt->execute()) throw new Exception("Execute failed (Checkout EDStay): " . $stmt->error);

                if ($stmt->affected_rows > 0) {
                    $successMessage = "Patient checked out successfully (Stay ID: $StayID_form)!";
                    logAuditTrail($conn, $loggedInStaffID_Session, 'EDStay Checkout', "Checked out EDStay {$StayID_form}. Disposition: {$Disposition_form}");
                } else {
                    // This could happen if the stay was already checked out by someone else, or StayID is wrong
                    $errors[] = "Checkout failed. The stay may have already been completed or the Stay ID is invalid.";
                    $conn->rollback(); // Rollback if checkout didn't affect rows as expected
                }
                // Clear form variables used for checkout
                $StayID_form = $Disposition_form = $PatientID_FK_form = $StaffID_FK_form = "";


            } elseif (empty($StayID_form) && !$isCheckoutAction) { // --- CHECK-IN Process ---
                $dbIntime = date('Y-m-d H:i:s'); // Current time for check-in

                // Generate StayID
                $sql_stay_id = "SELECT MAX(CAST(SUBSTRING(StayID, 2) AS UNSIGNED)) AS max_id FROM edstays";
                $result_stay_id = $conn->query($sql_stay_id);
                if (!$result_stay_id) throw new Exception("Failed to query max stay ID: " . $conn->error);
                $row_stay_id = $result_stay_id->fetch_assoc();
                $new_stay_id = $row_stay_id['max_id'] ? 'S' . str_pad($row_stay_id['max_id'] + 1, 3, '0', STR_PAD_LEFT) : 'S001';
                $result_stay_id->free();

                $sql = "INSERT INTO edstays (StayID, Intime, PatientID, StaffID) VALUES (?, ?, ?, ?)"; // Outime, Disposition are NULL
                $stmt = $conn->prepare($sql);
                if ($stmt === false) throw new Exception("Prepare failed (Check-In EDStay): " . $conn->error);
                $stmt->bind_param("ssss", $new_stay_id, $dbIntime, $PatientID_FK_form, $StaffID_FK_form);
                if (!$stmt->execute()) throw new Exception("Execute failed (Check-In EDStay): " . $stmt->error);

                $successMessage = "Patient checked-in successfully (Stay ID: $new_stay_id)!";
                logAuditTrail($conn, $loggedInStaffID_Session, 'EDStay Check-In', "Checked-in new EDStay {$new_stay_id} for Patient {$PatientID_FK_form}.");
                $PatientID_FK_form = $StaffID_FK_form = ""; // Clear selection for next check-in
            }
            // No general "Edit" functionality for past stays in this simplified version yet

            if (empty($errors)) { // Only commit if no errors occurred during the process
                 if (!$conn->commit()) {
                    throw new Exception("Transaction commit failed: " . $conn->error);
                }
            }
            if (isset($stmt)) $stmt->close();

        } catch (Exception $e) {
            $conn->rollback();
            error_log("Database operation failed (EDStay): " . $e->getMessage());
            $errors[] = "An error occurred: " . $e->getMessage();
        }
    }
}

// --- Fetch ACTIVE EDStays for Display Table ---
$itemsPerPage_ed = 10;
$page_ed = isset($_GET['page_ed']) && (int)$_GET['page_ed'] > 0 ? (int)$_GET['page_ed'] : 1;
$offset_ed = ($page_ed - 1) * $itemsPerPage_ed;
$totalActiveEDStays = 0;
$totalPages_ed = 0;
$activeEDStaysData = [];
$fetch_error_ed = null;

try {
    // Count only active stays for pagination
    $totalActiveEDStaysQuery = "SELECT COUNT(*) AS total FROM edstays WHERE Outime IS NULL";
    $totalResult_ed = $conn->query($totalActiveEDStaysQuery);
    if ($totalResult_ed) {
        $totalActiveEDStays = $totalResult_ed->fetch_assoc()['total'];
        $totalPages_ed = ceil($totalActiveEDStays / $itemsPerPage_ed);
        $totalResult_ed->free();
    } else { throw new Exception("Failed to get total active EDStay count: " . $conn->error); }

    // Fetch only active stays (Outime IS NULL)
    $edStaysQuery = "SELECT es.StayID, es.Intime, es.PatientID, p.PatientName, es.StaffID, s.Name AS StaffNameProcessed
                     FROM edstays es
                     LEFT JOIN patients p ON es.PatientID = p.PatientID
                     LEFT JOIN staff s ON es.StaffID = s.StaffID
                     WHERE es.Outime IS NULL
                     ORDER BY es.Intime DESC
                     LIMIT ? OFFSET ?"; // Corrected StaffName alias
    $stmt_edstays = $conn->prepare($edStaysQuery);
    if ($stmt_edstays === false) throw new Exception("Prepare failed (Fetch Active EDStays): " . $conn->error);
    $stmt_edstays->bind_param("ii", $itemsPerPage_ed, $offset_ed);
    if (!$stmt_edstays->execute()) throw new Exception("Execute failed (Fetch Active EDStays): " . $stmt_edstays->error);
    $result_edstays = $stmt_edstays->get_result();
    if ($result_edstays) {
        $activeEDStaysData = $result_edstays->fetch_all(MYSQLI_ASSOC);
        $result_edstays->free();
    } else { throw new Exception("Getting result set failed (Fetch Active EDStays): " . $stmt_edstays->error); }
    $stmt_edstays->close();

} catch (Exception $e) {
    $fetch_error_ed = "Error fetching ED Stay list: " . $e->getMessage();
    error_log($fetch_error_ed);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ED Check-In/Check-Out - WeCare</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        :root { --primary-color: #007bff; /* ... other color vars */ --checkout-btn-color: #28a745; }
        body { font-family: 'Poppins', sans-serif; padding-top: 70px; background-color: #f8f9fa; }
        .navbar { position: sticky; top: 0; z-index: 1030; /* ... */ }
        .hero-section { background-color: var(--primary-color); color: white; padding: 40px 0; text-align: center; margin-bottom: 30px; }
        .card { border: none; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.07); margin-bottom: 20px; }
        .card-header { background-color: #e9ecef; font-weight: 600; }
        .form-label { font-weight: 600; }
        .btn-submit { background: linear-gradient(to right, #005c99, #007bff); color: white; border: none; padding: 0.6rem 1.5rem; width: 100%; }
        .checkout-btn { background-color: var(--checkout-btn-color); color:white; }
        .checkout-btn i { margin-right: 5px; }
        .pagination { justify-content: center; }
        .invalid-feedback { display: block; width: 100%; margin-top: -0.75rem; margin-bottom: 0.5rem; font-size: .875em; color: var(--danger-color); }
        .form-field-hidden { display: none; } /* For initially hiding Outime/Disposition */
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-light" style="background-color: white; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
    <div class="container">
        <a class="navbar-brand" href="<?php echo addStaffIdToLink('https://localhost/dss/AdminLandingPage.php', $staffIDFromURL); ?>" style="font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 700; color: #007bff; position: absolute; top: 10px; left: 15px;">WeCare</a>
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
                <li class="nav-item <?php echo ($current_page == 'book_appointment.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="<?php echo addStaffIdToLink('book_appointment.php', $staffIDFromURL); ?>">Appointments</a>
                </li>
                <li class="nav-item <?php echo ($current_page == 'assign_diagnosis.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="<?php echo addStaffIdToLink('assign_diagnosis.php', $staffIDFromURL); ?>" style="color: black;">Patient Diagnosis</a>
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

    <!-- Hero Section -->
    <div class="hero-section">
        <h1>ED Check-In / Check-Out</h1>
        <p>Manage patients EDStays.</p>
    </div>
<div class="container mt-4 mb-5">
    <div id="messageArea" style="min-height: 60px;">
        <?php if (!empty($errors)): ?>
            <div class='alert alert-danger alert-dismissible fade show' role='alert'>
                <h5 class="alert-heading">Errors:</h5><ul class="mb-0"><?php foreach ($errors as $error): echo "<li>" . htmlspecialchars($error) . "</li>"; endforeach; ?></ul>
                <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
            </div>
        <?php endif; ?>
        <?php if (!empty($successMessage)): ?>
            <div class='alert alert-success alert-dismissible fade show' role='alert'>
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($successMessage); ?>
                <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
            </div>
        <?php endif; ?>
        <?php if ($fetch_error_ed): ?>
             <div class="alert alert-warning" role="alert"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($fetch_error_ed); ?></div>
        <?php endif; ?>
    </div>

    <div class="card" id="edStayFormCard">
        <div class="card-header"><i class="fas fa-notes-medical me-2"></i><span id="formTitle">Patient Check-In</span></div>
        <div class="card-body">
            <form method="POST" action="<?php echo addStaffIdToLink(htmlspecialchars($_SERVER['PHP_SELF']), $staffIDFromURL); ?>" id="edStayForm">
                <input type="hidden" name="stay_id_form" id="stay_id_form" value="<?php echo htmlspecialchars($StayID_form); ?>">
                <input type="hidden" name="is_checkout_action" id="is_checkout_action" value="">

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="patient_id_fk_form" class="form-label">Patient <span class="text-danger">*</span></label>
                        <select name="patient_id_fk_form" id="patient_id_fk_form" class="form-select" required>
                            <option value="">Select Patient</option>
                            <?php foreach ($patientsList as $patient): ?>
                                <option value="<?php echo htmlspecialchars($patient['PatientID']); ?>" <?php if ($PatientID_FK_form == $patient['PatientID']) echo "selected"; ?>>
                                    <?php echo htmlspecialchars($patient['PatientName']) . " (ID: " . htmlspecialchars($patient['PatientID']) . ")"; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="staff_id_fk_form" class="form-label">Attending Staff <span class="text-danger">*</span></label>
                        <select name="staff_id_fk_form" id="staff_id_fk_form" class="form-select" required>
                            <option value="">Select Staff</option>
                            <?php foreach ($staffList as $staff): ?>
                                <option value="<?php echo htmlspecialchars($staff['StaffID']); ?>" <?php if ($StaffID_FK_form == $staff['StaffID']) echo "selected"; ?>>
                                    <?php echo htmlspecialchars($staff['Name']) . " (ID: " . htmlspecialchars($staff['StaffID']) . ")"; // Corrected to $staff['Name'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- InTime is auto, not shown in form for check-in, but needed if we re-purpose form for edit -->
                <div class="mb-3 form-field-hidden" id="intime_form_container">
                     <label for="intime_form_display" class="form-label">In-Time</label>
                     <input type="text" class="form-control" id="intime_form_display" value="" readonly>
                </div>


                <div class="mb-3 form-field-hidden" id="disposition_container"> <!-- Hidden by default -->
                    <label for="disposition_form" class="form-label">Disposition <span class="text-danger">*</span></label>
                    <textarea name="disposition_form" id="disposition_form" class="form-control" rows="3"><?php echo htmlspecialchars($Disposition_form); ?></textarea>
                </div>

                <button type="submit" class="btn-submit" id="submitBtnEDStay">
                    <i class="fas fa-sign-in-alt me-1"></i> <span id="submitBtnTextEDStay">Check-In Patient</span>
                </button>
                <button type="button" class="btn btn-secondary mt-2 w-100" onclick="resetToCheckInMode()" id="resetBtnEDStay">
                     <i class="fas fa-times me-1"></i> Clear / Cancel
                </button>
            </form>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header"><i class="fas fa-user-clock me-2"></i>Active ED Patients</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-sm">
                    <thead>
                        <tr>
                            <th>Stay ID</th>
                            <th>Patient</th>
                            <th>Attending Staff</th>
                            <th>In-Time</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($activeEDStaysData)): ?>
                            <?php foreach ($activeEDStaysData as $stay): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($stay['StayID']); ?></td>
                                <td><?php echo htmlspecialchars($stay['PatientName'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($stay['StaffNameProcessed'] ?? 'N/A'); // Use alias from query ?></td>
                                <td><?php echo !empty($stay['Intime']) ? htmlspecialchars(date('d M Y, h:i A', strtotime($stay['Intime']))) : 'N/A'; ?></td>
                                <td class="text-center">
                                    <button class='checkout-btn btn btn-sm'
                                        data-stay='<?php echo htmlspecialchars(json_encode($stay), ENT_QUOTES, 'UTF-8'); ?>'
                                        onclick='prepareCheckout(JSON.parse(this.getAttribute("data-stay"))); return false;'
                                        title='Check-Out Patient'>
                                        <i class='fas fa-sign-out-alt'></i> Check-Out
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center text-muted">No active ED patients at the moment.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <!-- Pagination for Active ED Stays -->
            <?php if ($totalPages_ed > 1): ?>
            <nav aria-label="Active ED Stays Pagination" class="mt-3">
                 <ul class="pagination">
                     <li class="page-item <?php echo ($page_ed <= 1) ? 'disabled' : ''; ?>">
                         <a class="page-link" href="<?php echo addStaffIdToLink('?page_ed='.($page_ed - 1), $staffIDFromURL); ?>">«</a></li>
                      <?php
                       $range_ed = 2; $start_ed = max(1, $page_ed - $range_ed); $end_ed = min($totalPages_ed, $page_ed + $range_ed);
                       if ($start_ed > 1) { echo '<li class="page-item"><a class="page-link" href="'.addStaffIdToLink('?page_ed=1', $staffIDFromURL).'">1</a></li>'; if ($start_ed > 2) { echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; } }
                       for ($i = $start_ed; $i <= $end_ed; $i++): ?>
                         <li class="page-item <?php echo ($i == $page_ed) ? 'active' : ''; ?>"><a class="page-link" href="<?php echo addStaffIdToLink('?page_ed='.$i, $staffIDFromURL); ?>"><?php echo $i; ?></a></li>
                       <?php endfor;
                       if ($end_ed < $totalPages_ed) { if ($end_ed < $totalPages_ed - 1) { echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; } echo '<li class="page-item"><a class="page-link" href="'.addStaffIdToLink('?page_ed='.$totalPages_ed, $staffIDFromURL).'">'.$totalPages_ed.'</a></li>'; }
                      ?>
                     <li class="page-item <?php echo ($page_ed >= $totalPages_ed) ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo addStaffIdToLink('?page_ed='.($page_ed + 1), $staffIDFromURL); ?>">»</a></li>
                  </ul>
             </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Alert dismissal
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(function(alert) {
            if (bootstrap && bootstrap.Alert) {
               setTimeout(() => { bootstrap.Alert.getOrCreateInstance(alert)?.close(); }, 7000);
            } else { setTimeout(() => { alert.style.display = 'none'; }, 7000); }
       });
       resetToCheckInMode(); // Initialize form to check-in mode
    });

    function formatDateTimeForDisplay(dateTimeStr) {
        if (!dateTimeStr) return '';
        const options = { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true };
        return new Date(dateTimeStr).toLocaleString('en-US', options);
    }

    function resetToCheckInMode() {
        const form = document.getElementById('edStayForm');
        form.reset();
        document.getElementById('formTitle').textContent = 'Patient Check-In';
        document.getElementById('stay_id_form').value = '';
        document.getElementById('is_checkout_action').value = '';

        document.getElementById('patient_id_fk_form').disabled = false; // Ensure enabled
        document.getElementById('staff_id_fk_form').disabled = false;   // Ensure enabled
        // document.getElementById('patient_id_fk_form').classList.remove('field-locked-for-checkout'); // If using CSS class
        // document.getElementById('staff_id_fk_form').classList.remove('field-locked-for-checkout'); // If using CSS class


        document.getElementById('intime_form_container').classList.add('form-field-hidden');
        document.getElementById('intime_form_display').value = '';

        document.getElementById('disposition_container').classList.add('form-field-hidden');
        document.getElementById('disposition_form').value = '';
        document.getElementById('disposition_form').required = false;

        document.getElementById('submitBtnTextEDStay').innerHTML = "<i class='fas fa-sign-in-alt me-1'></i> Check-In Patient";
        document.getElementById('submitBtnTextEDStay').classList.remove('btn-warning', 'btn-success');
        document.getElementById('submitBtnTextEDStay').classList.add('btn-primary');

        form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
        form.querySelectorAll('.invalid-feedback').forEach(el => el.textContent = '');
    }

    function prepareCheckout(stay) {
        console.log("Preparing Checkout for Stay:", stay);
        document.getElementById('edStayFormCard').scrollIntoView({ behavior: 'smooth', block: 'start' });

        document.getElementById('formTitle').textContent = 'Patient Check-Out';
        document.getElementById('stay_id_form').value = stay.StayID;
        document.getElementById('is_checkout_action').value = '1'; // Mark as checkout action

        // Pre-fill Patient and Staff - DO NOT DISABLE THEM, just set their values
        document.getElementById('patient_id_fk_form').value = stay.PatientID;
        // document.getElementById('patient_id_fk_form').disabled = true; // REMOVE THIS
        document.getElementById('staff_id_fk_form').value = stay.StaffID;
        // document.getElementById('staff_id_fk_form').disabled = true; // REMOVE THIS

        // If you want them to *look* non-editable during checkout, you could add a CSS class
        // e.g., document.getElementById('patient_id_fk_form').classList.add('field-locked-for-checkout');
        //       document.getElementById('staff_id_fk_form').classList.add('field-locked-for-checkout');
        // And then add CSS: .field-locked-for-checkout { pointer-events: none; background-color: #e9ecef; }

        document.getElementById('intime_form_container').classList.remove('form-field-hidden');
        document.getElementById('intime_form_display').value = formatDateTimeForDisplay(stay.Intime);

        document.getElementById('disposition_container').classList.remove('form-field-hidden');
        document.getElementById('disposition_form').value = '';
        document.getElementById('disposition_form').required = true;
        document.getElementById('disposition_form').focus();

        document.getElementById('submitBtnTextEDStay').innerHTML = "<i class='fas fa-sign-out-alt me-1'></i> Complete Check-Out";
        document.getElementById('submitBtnTextEDStay').classList.remove('btn-primary', 'btn-warning');
        document.getElementById('submitBtnTextEDStay').classList.add('btn-success');
    }

    // Note: A general 'editEDStay' function for completed stays is removed for this simplified workflow.
    // If you need to edit completed stays, that would be a separate feature.
</script>
</body>
</html>
<?php
if (isset($conn) && $conn instanceof mysqli && $conn->thread_id) {
    $conn->close();
}
?>