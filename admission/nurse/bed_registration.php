<?php
session_start();
include 'connection.php'; // Defines $conn

if (!$conn || $conn->connect_error) {
    die("Database connection failed: " . ($conn ? $conn->connect_error : 'Unknown error'));
}
date_default_timezone_set('Asia/Kuala_Lumpur');

$loggedInStaffID_Session = $_SESSION['staffid'] ?? null; // Staff performing the action
$staffIDFromURL = $_GET['staffid'] ?? null; // Staff whose context we are in (for links)
$current_page = basename($_SERVER['PHP_SELF']);

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

// Initialize EDStay variables
$StayID = "";
$Intime = "";
$Outime = "";
$Disposition = "";
$PatientID_FK = ""; // For the selected patient
$StaffID_FK = "";   // For the selected staff (attending staff for the stay)

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

// Fetch Staff for dropdown (Assuming you have a 'staff' table with StaffID and StaffName)
$staffList = [];
// IMPORTANT: Adjust this query based on your actual staff table and columns
$sql_staff = "SELECT StaffID, Name FROM staff ORDER BY Name ASC"; // Example query
$result_staff = $conn->query($sql_staff);
if ($result_staff) {
    while ($row = $result_staff->fetch_assoc()) {
        $staffList[] = $row;
    }
    $result_staff->free();
} else {
    // $errors[] = "Error fetching staff list: " . $conn->error; // Optional: show error if staff table is crucial
    error_log("Error fetching staff list: " . $conn->error);
}


// Handle POST request for EDStays
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $StayID = trim($_POST['stay_id'] ?? '');
    $Intime = trim($_POST['intime'] ?? '');
    $Outime = trim($_POST['outime'] ?? '');
    $Disposition = trim($_POST['disposition'] ?? '');
    $PatientID_FK = trim($_POST['patient_id_fk'] ?? '');
    $StaffID_FK = trim($_POST['staff_id_fk'] ?? '');

    // --- Server-Side Validation ---
    if (empty($PatientID_FK)) $errors[] = 'Patient is required.';
    if (empty($StaffID_FK)) $errors[] = 'Attending Staff is required.'; // Or make optional
    if (empty($Intime)) $errors[] = 'In-Time is required.';
    // Out-Time can be empty if patient is still in ED
    if (!empty($Outime) && !empty($Intime) && strtotime($Outime) < strtotime($Intime)) {
        $errors[] = 'Out-Time cannot be earlier than In-Time.';
    }
    if (empty($Disposition)) $errors[] = 'Disposition is required.';
    // Add more specific validation for datetime format if needed

    // --- Proceed if NO errors ---
    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            // Convert to MySQL DATETIME format if necessary
            $dbIntime = !empty($Intime) ? date('Y-m-d H:i:s', strtotime($Intime)) : null;
            $dbOutime = !empty($Outime) ? date('Y-m-d H:i:s', strtotime($Outime)) : null;

            if (!empty($StayID)) { // --- UPDATE ---
                $sql = "UPDATE edstays SET Intime = ?, Outime = ?, Disposition = ?, PatientID = ?, StaffID = ? WHERE StayID = ?";
                $stmt = $conn->prepare($sql);
                if ($stmt === false) throw new Exception("Prepare failed (Update EDStay): " . $conn->error);
                $stmt->bind_param("ssssss", $dbIntime, $dbOutime, $Disposition, $PatientID_FK, $StaffID_FK, $StayID);
                if (!$stmt->execute()) throw new Exception("Execute failed (Update EDStay): " . $stmt->error);

                $successMessage = "ED Stay record ($StayID) updated successfully!";
                logAuditTrail($conn, $loggedInStaffID_Session, 'EDStay Update', "Updated EDStay record {$StayID}.");

            } else { // --- INSERT ---
                // Generate StayID (e.g., S001, S002)
                $sql_stay_id = "SELECT MAX(CAST(SUBSTRING(StayID, 2) AS UNSIGNED)) AS max_id FROM edstays";
                $result_stay_id = $conn->query($sql_stay_id);
                if (!$result_stay_id) throw new Exception("Failed to query max stay ID: " . $conn->error);
                $row_stay_id = $result_stay_id->fetch_assoc();
                $new_stay_id = $row_stay_id['max_id'] ? 'S' . str_pad($row_stay_id['max_id'] + 1, 3, '0', STR_PAD_LEFT) : 'S001';
                $result_stay_id->free();

                $sql = "INSERT INTO edstays (StayID, Intime, Outime, Disposition, PatientID, StaffID) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                if ($stmt === false) throw new Exception("Prepare failed (Insert EDStay): " . $conn->error);
                $stmt->bind_param("ssssss", $new_stay_id, $dbIntime, $dbOutime, $Disposition, $PatientID_FK, $StaffID_FK);
                if (!$stmt->execute()) throw new Exception("Execute failed (Insert EDStay): " . $stmt->error);

                $successMessage = "ED Stay registered successfully with ID: $new_stay_id!";
                logAuditTrail($conn, $loggedInStaffID_Session, 'EDStay Registration', "Registered new EDStay {$new_stay_id} for Patient {$PatientID_FK}.");
                // Clear form
                $StayID = $Intime = $Outime = $Disposition = $PatientID_FK = $StaffID_FK = "";
            }
            $stmt->close();
            if (!$conn->commit()) {
                throw new Exception("Transaction commit failed: " . $conn->error);
            }
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Database operation failed (EDStay): " . $e->getMessage());
            $errors[] = "An error occurred while saving ED Stay data: " . $e->getMessage();
        }
    }
}

// --- Fetch EDStays for Display Table (Pagination) ---
$itemsPerPage_ed = 10;
$page_ed = isset($_GET['page_ed']) && (int)$_GET['page_ed'] > 0 ? (int)$_GET['page_ed'] : 1;
$offset_ed = ($page_ed - 1) * $itemsPerPage_ed;
$totalEDStays = 0;
$totalPages_ed = 0;
$edStaysData = [];
$fetch_error_ed = null;

try {
    $totalEDStaysQuery = "SELECT COUNT(*) AS total FROM edstays";
    $totalResult_ed = $conn->query($totalEDStaysQuery);
    if ($totalResult_ed) {
        $totalEDStays = $totalResult_ed->fetch_assoc()['total'];
        $totalPages_ed = ceil($totalEDStays / $itemsPerPage_ed);
        $totalResult_ed->free();
    } else { throw new Exception("Failed to get total EDStay count: " . $conn->error); }

    // Join with patients and staff to get names
    $edStaysQuery = "SELECT es.StayID, es.Intime, es.Outime, es.Disposition, es.PatientID, p.PatientName, es.StaffID, s.Name
                     FROM edstays es
                     LEFT JOIN patients p ON es.PatientID = p.PatientID
                     LEFT JOIN staff s ON es.StaffID = s.StaffID
                     ORDER BY es.Intime DESC LIMIT ? OFFSET ?";
    $stmt_edstays = $conn->prepare($edStaysQuery);
    if ($stmt_edstays === false) throw new Exception("Prepare failed (Fetch EDStays): " . $conn->error);
    $stmt_edstays->bind_param("ii", $itemsPerPage_ed, $offset_ed);
    if (!$stmt_edstays->execute()) throw new Exception("Execute failed (Fetch EDStays): " . $stmt_edstays->error);
    $result_edstays = $stmt_edstays->get_result();
    if ($result_edstays) {
        $edStaysData = $result_edstays->fetch_all(MYSQLI_ASSOC);
        $result_edstays->free();
    } else { throw new Exception("Getting result set failed (Fetch EDStays): " . $stmt_edstays->error); }
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
    <title>Manage ED Stays - WeCare</title>
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
        .navbar .nav-link { color: #495057 !important; font-weight: 500; padding: 0.5rem 1rem; }
        .navbar .nav-link:hover, .navbar .nav-item.active .nav-link { color: var(--primary-color) !important; }
        .navbar .nav-item.active .nav-link { font-weight: 700 !important; }
        .hero-section { background-color: var(--primary-color); color: white; padding: 40px 0; text-align: center; margin-bottom: 30px; }
        .card { border: none; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.07); margin-bottom: 20px; }
        .card-header { background-color: #e9ecef; font-weight: 600; }
        .form-label { font-weight: 600; }
        .form-control, .form-select { margin-bottom: 1rem; }
        .btn-submit { background: linear-gradient(to right, #005c99, #007bff); color: white; border: none; padding: 0.6rem 1.5rem; width: 100%; }
        .table-responsive { margin-top: 20px; }
        .edit-btn { background-color: #ffc107; border: none; color: #212529; padding: 3px 8px; border-radius: 4px;}
        .pagination { justify-content: center; }
        .invalid-feedback { display: block; width: 100%; margin-top: -0.75rem; margin-bottom: 0.5rem; font-size: .875em; color: var(--danger-color); }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-light" style="background-color: white; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
    <div class="container">
         <a class="navbar-brand" href="<?php echo addStaffIdToLink('AdminLandingPage.php', $staffIDFromURL); ?>">WeCare</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item <?php echo ($current_page == 'AdminLandingPage.php' || $current_page == 'index.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="<?php echo addStaffIdToLink('AdminLandingPage.php', $staffIDFromURL); ?>">Home</a></li>
                <li class="nav-item <?php echo ($current_page == 'register_patient.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="<?php echo addStaffIdToLink('register_patient.php', $staffIDFromURL); ?>">Patient Registration</a></li>
                <li class="nav-item <?php echo ($current_page == 'manage_edstays.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="<?php echo addStaffIdToLink('manage_edstays.php', $staffIDFromURL); ?>">ED Stays</a></li>
                <li class="nav-item <?php echo ($current_page == 'book_appointment.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="<?php echo addStaffIdToLink('book_appointment.php', $staffIDFromURL); ?>">Appointments</a></li>
                <li class="nav-item <?php echo ($current_page == 'assign_diagnosis.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="<?php echo addStaffIdToLink('assign_diagnosis.php', $staffIDFromURL); ?>">Patient Diagnosis</a></li>
                <li class="nav-item <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="<?php echo addStaffIdToLink('dashboard.php', $staffIDFromURL); ?>">Dashboards</a></li>
            </ul>
        </div>
    </div>
</nav>
<style>
    .navbar .nav-link:hover { color: #007bff !important; }
    .navbar .nav-item.active .nav-link { color: #007bff !important; font-weight: bold !important; }
</style>

<div class="hero-section">
    <h1>Manage Emergency Department Stays</h1>
</div>

<div class="container mt-4 mb-5">
    <div id="messageArea" style="min-height: 60px;">
        <?php if (!empty($errors)): ?>
            <div class='alert alert-danger alert-dismissible fade show' role='alert'>
                <h5 class="alert-heading">Errors:</h5>
                <ul class="mb-0">
                <?php foreach ($errors as $error): echo "<li>" . htmlspecialchars($error) . "</li>"; endforeach; ?>
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
        <?php if ($fetch_error_ed): ?>
             <div class="alert alert-warning" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($fetch_error_ed); ?> ED Stay list may be incomplete.
             </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header"><i class="fas fa-procedures me-2"></i>Register / Update ED Stay</div>
        <div class="card-body">
            <form method="POST" action="<?php echo addStaffIdToLink(htmlspecialchars($_SERVER['PHP_SELF']), $staffIDFromURL); ?>" id="edStayForm">
                <input type="hidden" name="stay_id" id="stay_id" value="<?php echo htmlspecialchars($StayID); ?>">

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="patient_id_fk" class="form-label">Patient <span class="text-danger">*</span></label>
                        <select name="patient_id_fk" id="patient_id_fk" class="form-select" required>
                            <option value="">Select Patient</option>
                            <?php foreach ($patientsList as $patient): ?>
                                <option value="<?php echo htmlspecialchars($patient['PatientID']); ?>" <?php if ($PatientID_FK == $patient['PatientID']) echo "selected"; ?>>
                                    <?php echo htmlspecialchars($patient['PatientName']) . " (ID: " . htmlspecialchars($patient['PatientID']) . ")"; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="staff_id_fk" class="form-label">Attending Staff <span class="text-danger">*</span></label>
                        <select name="staff_id_fk" id="staff_id_fk" class="form-select" required>
                            <option value="">Select Staff</option>
                            <?php foreach ($staffList as $staff): ?>
                                <option value="<?php echo htmlspecialchars($staff['StaffID']); ?>" <?php if ($StaffID_FK == $staff['StaffID']) echo "selected"; ?>>
                                    <?php echo htmlspecialchars($staff['Name']) . " (ID: " . htmlspecialchars($staff['StaffID']) . ")"; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="intime" class="form-label">In-Time <span class="text-danger">*</span></label>
                        <input type="datetime-local" class="form-control" name="intime" id="intime" value="<?php echo !empty($Intime) ? htmlspecialchars(date('Y-m-d\TH:i', strtotime($Intime))) : ''; ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="outime" class="form-label">Out-Time</label>
                        <input type="datetime-local" class="form-control" name="outime" id="outime" value="<?php echo !empty($Outime) ? htmlspecialchars(date('Y-m-d\TH:i', strtotime($Outime))) : ''; ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label for="disposition" class="form-label">Disposition <span class="text-danger">*</span></label>
                    <textarea name="disposition" id="disposition" class="form-control" rows="3" required><?php echo htmlspecialchars($Disposition); ?></textarea>
                </div>

                <button type="submit" class="btn-submit" id="submitBtnEDStay">
                    <i class="fas fa-save me-1"></i> <span id="submitBtnTextEDStay">Register ED Stay</span>
                </button>
                <button type="button" class="btn btn-secondary mt-2 w-100" onclick="resetEDStayForm()" id="resetBtnEDStay">
                     <i class="fas fa-times me-1"></i> Clear Form / Cancel Edit
                </button>
            </form>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header"><i class="fas fa-clipboard-list me-2"></i>Current ED Stays</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-sm">
                    <thead>
                        <tr>
                            <th>Stay ID</th>
                            <th>Patient Name</th>
                            <th>Attending Staff</th>
                            <th>In-Time</th>
                            <th>Out-Time</th>
                            <th>Disposition</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($edStaysData)): ?>
                            <?php foreach ($edStaysData as $stay): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($stay['StayID']); ?></td>
                                <td><?php echo htmlspecialchars($stay['PatientName'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($stay['Name'] ?? 'N/A'); ?></td>
                                <td><?php echo !empty($stay['Intime']) ? htmlspecialchars(date('d M Y, h:i A', strtotime($stay['Intime']))) : 'N/A'; ?></td>
                                <td><?php echo !empty($stay['Outime']) ? htmlspecialchars(date('d M Y, h:i A', strtotime($stay['Outime']))) : 'N/A'; ?></td>
                                <td><?php echo nl2br(htmlspecialchars($stay['Disposition'])); ?></td>
                                <td class="text-center">
                                    <button class='edit-btn'
                                        data-stay='<?php echo htmlspecialchars(json_encode($stay), ENT_QUOTES, 'UTF-8'); ?>'
                                        onclick='editEDStay(JSON.parse(this.getAttribute("data-stay"))); return false;'
                                        title='Edit ED Stay'>
                                        <i class='fas fa-edit'></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center text-muted">No ED Stays recorded yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <!-- Pagination for ED Stays -->
            <?php if ($totalPages_ed > 1): ?>
            <nav aria-label="ED Stays Pagination" class="mt-3">
                 <ul class="pagination">
                     <li class="page-item <?php echo ($page_ed <= 1) ? 'disabled' : ''; ?>">
                         <a class="page-link" href="<?php echo addStaffIdToLink('?page_ed='.($page_ed - 1), $staffIDFromURL); ?>" aria-label="Previous">«</a>
                     </li>
                      <?php
                       $range_ed = 2; $start_ed = max(1, $page_ed - $range_ed); $end_ed = min($totalPages_ed, $page_ed + $range_ed);
                       if ($start_ed > 1) { echo '<li class="page-item"><a class="page-link" href="'.addStaffIdToLink('?page_ed=1', $staffIDFromURL).'">1</a></li>'; if ($start_ed > 2) { echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; } }
                       for ($i = $start_ed; $i <= $end_ed; $i++): ?>
                         <li class="page-item <?php echo ($i == $page_ed) ? 'active' : ''; ?>">
                               <a class="page-link" href="<?php echo addStaffIdToLink('?page_ed='.$i, $staffIDFromURL); ?>"><?php echo $i; ?></a>
                         </li>
                       <?php endfor;
                       if ($end_ed < $totalPages_ed) { if ($end_ed < $totalPages_ed - 1) { echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; } echo '<li class="page-item"><a class="page-link" href="'.addStaffIdToLink('?page_ed='.$totalPages_ed, $staffIDFromURL).'">'.$totalPages_ed.'</a></li>'; }
                      ?>
                     <li class="page-item <?php echo ($page_ed >= $totalPages_ed) ? 'disabled' : ''; ?>">
                         <a class="page-link" href="<?php echo addStaffIdToLink('?page_ed='.($page_ed + 1), $staffIDFromURL); ?>" aria-label="Next">»</a>
                     </li>
                  </ul>
             </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(function(alert) {
            if (bootstrap && bootstrap.Alert) {
               setTimeout(() => { bootstrap.Alert.getOrCreateInstance(alert)?.close(); }, 7000);
            } else {
                setTimeout(() => { alert.style.display = 'none'; }, 7000);
            }
       });
    });

    function formatDateTimeForInput(dateTimeStr) {
        if (!dateTimeStr) return '';
        const date = new Date(dateTimeStr);
        // Adjust for timezone offset if the dateTimeStr is UTC and input expects local
        // For simplicity, assuming dateTimeStr from DB is already in local or compatible format
        const year = date.getFullYear();
        const month = ('0' + (date.getMonth() + 1)).slice(-2);
        const day = ('0' + date.getDate()).slice(-2);
        const hours = ('0' + date.getHours()).slice(-2);
        const minutes = ('0' + date.getMinutes()).slice(-2);
        return `${year}-${month}-${day}T${hours}:${minutes}`;
    }

    function editEDStay(stay) {
        console.log("Editing ED Stay:", stay);
        document.getElementById('edStayForm').scrollIntoView({ behavior: 'smooth', block: 'start' });

        document.getElementById('stay_id').value = stay.StayID || '';
        document.getElementById('patient_id_fk').value = stay.PatientID || '';
        document.getElementById('staff_id_fk').value = stay.StaffID || '';
        document.getElementById('intime').value = formatDateTimeForInput(stay.Intime);
        document.getElementById('outime').value = formatDateTimeForInput(stay.Outime);
        document.getElementById('disposition').value = stay.Disposition || '';

        document.getElementById('submitBtnTextEDStay').textContent = 'Update ED Stay';
        document.getElementById('submitBtnEDStay').classList.remove('btn-primary'); // Or whatever your default 'register' class is
        document.getElementById('submitBtnEDStay').classList.add('btn-warning');
    }

    function resetEDStayForm() {
        const form = document.getElementById('edStayForm');
        form.reset();
        document.getElementById('stay_id').value = '';
        document.getElementById('submitBtnTextEDStay').textContent = 'Register ED Stay';
        document.getElementById('submitBtnEDStay').classList.remove('btn-warning');
        // document.getElementById('submitBtnEDStay').classList.add('btn-primary'); // Reset to default if needed

        form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
        form.querySelectorAll('.invalid-feedback').forEach(el => el.textContent = '');
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
</script>
</body>
</html>
<?php
if (isset($conn) && $conn instanceof mysqli && $conn->thread_id) {
    $conn->close();
}
?>