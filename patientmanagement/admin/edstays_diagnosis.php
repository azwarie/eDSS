<?php
session_start();
include 'connection.php'; // Defines $conn

if (!$conn || $conn->connect_error) {
    die("Database connection failed: " . ($conn ? $conn->connect_error : 'Unknown error'));
}
date_default_timezone_set('Asia/Kuala_Lumpur');

$loggedInStaffID = $_SESSION['staffid'] ?? $_GET['staffid'] ?? null;
if (empty($loggedInStaffID)) {
    die("Staff ID is missing. Please log in again or ensure staffid is in the URL.");
}
$current_page = basename($_SERVER['PHP_SELF']);

function addStaffIdToLink($url, $staffIdParam) { /* ... (same as before) ... */ }
function logAuditTrail($conn_audit, $staffID_audit_param, $action, $description) { /* ... (same as before) ... */ }


// --- Initialize Variables ---
$selected_stay_id = $_GET['selected_stay_id'] ?? $_POST['selected_stay_id'] ?? null; // To keep selected stay sticky
$message = "";
$form_errors = [];

// --- Fetch Active ED Stays for Dropdown ---
$activeEDStays = [];
$fetch_error_stays = null;
try {
    $staysQuery = "SELECT es.StayID, p.PatientName, p.PatientID
                   FROM edstays es
                   JOIN patients p ON es.PatientID = p.PatientID
                   WHERE es.Outime IS NULL
                   ORDER BY p.PatientName ASC, es.Intime DESC";
    $staysResult = $conn->query($staysQuery);
    if ($staysResult) {
        $activeEDStays = $staysResult->fetch_all(MYSQLI_ASSOC);
        $staysResult->free();
    } else { throw new Exception("Failed to fetch active ED stays: " . $conn->error); }
} catch (Exception $e) {
    $fetch_error_stays = "Error loading active ED stays: " . $e->getMessage();
    error_log($fetch_error_stays);
}

// --- Fetch All ICD Diagnoses for Dropdown ---
$icdDiagnoses = [];
$fetch_error_icd = null;
try {
    $icdQuery = "SELECT ICD_Code, ICD_Title, ICD_version FROM diagnosis ORDER BY ICD_Title ASC"; // Assuming 'diagnosis' is your table name
    $icdResult = $conn->query($icdQuery);
    if ($icdResult) {
        $icdDiagnoses = $icdResult->fetch_all(MYSQLI_ASSOC);
        $icdResult->free();
    } else { throw new Exception("Failed to fetch ICD diagnoses: " . $conn->error); }
} catch (Exception $e) {
    $fetch_error_icd = "Error loading ICD list: " . $e->getMessage();
    error_log($fetch_error_icd);
}

// --- Fetch Assigned Diagnoses for Selected Stay (if any) ---
$assignedDiagnosesForSelectedStay = [];
if ($selected_stay_id) {
    try {
        $assignedQuery = "SELECT sd.StayID, sd.ICD_Code, sd.SeqNum, d.ICD_Title, d.ICD_version
                          FROM edstays_diagnosis sd
                          JOIN diagnosis d ON sd.ICD_Code = d.ICD_Code
                          WHERE sd.StayID = ?
                          ORDER BY sd.SeqNum ASC";
        $stmt_assigned = $conn->prepare($assignedQuery);
        if ($stmt_assigned) {
            $stmt_assigned->bind_param("s", $selected_stay_id);
            $stmt_assigned->execute();
            $result_assigned = $stmt_assigned->get_result();
            $assignedDiagnosesForSelectedStay = $result_assigned->fetch_all(MYSQLI_ASSOC);
            $stmt_assigned->close();
        } else { throw new Exception("Prepare failed (fetch assigned): " . $conn->error); }
    } catch (Exception $e) {
        $message .= "<div class='alert alert-warning'>Error loading assigned diagnoses: " . htmlspecialchars($e->getMessage()) . "</div>";
        error_log("Error fetching assigned diagnoses for StayID $selected_stay_id: " . $e->getMessage());
    }
}


// --- Handle Form Submission: Assign New Diagnosis ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['assign_diagnosis_action'])) {
    $stay_id_to_assign = trim($_POST['stay_id_to_assign'] ?? '');
    $icd_code_to_assign = trim($_POST['icd_code_to_assign'] ?? '');
    $selected_stay_id = $stay_id_to_assign; // Keep the dropdown selected

    if (empty($stay_id_to_assign)) $form_errors[] = "Please select an ED Stay.";
    if (empty($icd_code_to_assign)) $form_errors[] = "Please select an ICD Diagnosis.";

    if (empty($form_errors)) {
        $conn->begin_transaction();
        try {
            // 1. Determine the next SeqNum for this StayID
            $seqNum = 1;
            $stmt_seq = $conn->prepare("SELECT MAX(SeqNum) AS max_seq FROM edstays_diagnosis WHERE StayID = ?");
            if (!$stmt_seq) throw new Exception("Prepare failed (SeqNum): " . $conn->error);
            $stmt_seq->bind_param("s", $stay_id_to_assign);
            if (!$stmt_seq->execute()) throw new Exception("Execute failed (SeqNum): " . $stmt_seq->error);
            $res_seq = $stmt_seq->get_result();
            if ($row_seq = $res_seq->fetch_assoc()) {
                if ($row_seq['max_seq'] !== null) {
                    $seqNum = $row_seq['max_seq'] + 1;
                }
            }
            $stmt_seq->close();

            // 2. Check for duplicate (StayID, ICD_Code, SeqNum) - though SeqNum should make it unique per addition
            // More importantly, maybe you don't want the *same* ICD_Code assigned multiple times without different SeqNum
            // For now, we rely on SeqNum for uniqueness of this specific entry.
            // A check could be: SELECT COUNT(*) FROM edstays_diagnosis WHERE StayID = ? AND ICD_Code = ?
            // If count > 0, maybe prevent adding the *exact same code again unless it's an explicit update/different SeqNum.
            // For simplicity, we'll allow it as SeqNum will differ.

            // 3. Insert into `edstays_diagnosis`
            $insertQuery = "INSERT INTO edstays_diagnosis (StayID, ICD_Code, SeqNum) VALUES (?, ?, ?)";
            $stmt_insert = $conn->prepare($insertQuery);
            if (!$stmt_insert) throw new Exception("Prepare failed (Insert edstays_diagnosis): " . $conn->error);
            $stmt_insert->bind_param("ssi", $stay_id_to_assign, $icd_code_to_assign, $seqNum);
            if (!$stmt_insert->execute()) throw new Exception("Execute failed (Insert edstays_diagnosis): " . $stmt_insert->error);
            $stmt_insert->close();

            if (!$conn->commit()) throw new Exception("Transaction commit failed: " . $conn->error);

            logAuditTrail($conn, $loggedInStaffID, 'ED Diagnosis Assignment', "Assigned ICD '{$icd_code_to_assign}' (Seq: {$seqNum}) to StayID '{$stay_id_to_assign}'.");
            $successMessage = "Diagnosis (Seq: {$seqNum}) assigned successfully to Stay ID {$stay_id_to_assign}!";
            $_SESSION['form_message'] = "<div class='alert alert-success alert-dismissible fade show'>".htmlspecialchars($successMessage)."<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            // Refresh assigned diagnoses for the selected stay
            header("Location: assign_ed_diagnosis.php?staffid=" . urlencode($loggedInStaffID) . "&selected_stay_id=" . urlencode($stay_id_to_assign));
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $message = "<div class='alert alert-danger alert-dismissible fade show'>Error assigning diagnosis: " . htmlspecialchars($e->getMessage()) . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            error_log("ED Diagnosis Assignment Error: " . $e->getMessage());
        }
    } else {
        $message = "<div class='alert alert-danger alert-dismissible fade show'><strong>Assignment failed:</strong><ul class='mb-0'>";
        foreach ($form_errors as $error) { $message .= "<li>" . htmlspecialchars($error) . "</li>"; }
        $message .= "</ul><button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    }
}

// --- Handle Diagnosis Removal ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['remove_diagnosis_action'])) {
    $stay_id_to_remove_from = trim($_POST['stay_id_to_remove_from'] ?? '');
    $icd_code_to_remove = trim($_POST['icd_code_to_remove'] ?? '');
    $seq_num_to_remove = trim($_POST['seq_num_to_remove'] ?? '');
    $selected_stay_id = $stay_id_to_remove_from; // Keep dropdown selected

    if (empty($stay_id_to_remove_from) || empty($icd_code_to_remove) || empty($seq_num_to_remove)) {
        $message = "<div class='alert alert-danger'>Missing data for diagnosis removal.</div>";
    } else {
        $conn->begin_transaction();
        try {
            $deleteQuery = "DELETE FROM edstays_diagnosis WHERE StayID = ? AND ICD_Code = ? AND SeqNum = ?";
            $stmt_delete = $conn->prepare($deleteQuery);
            if (!$stmt_delete) throw new Exception("Prepare failed (Delete Diagnosis): " . $conn->error);
            $stmt_delete->bind_param("ssi", $stay_id_to_remove_from, $icd_code_to_remove, $seq_num_to_remove);
            if (!$stmt_delete->execute()) throw new Exception("Execute failed (Delete Diagnosis): " . $stmt_delete->error);

            if ($stmt_delete->affected_rows > 0) {
                 if (!$conn->commit()) throw new Exception("Commit failed (Delete Diagnosis): " . $conn->error);
                logAuditTrail($conn, $loggedInStaffID, 'ED Diagnosis Removal', "Removed ICD '{$icd_code_to_remove}' (Seq: {$seq_num_to_remove}) from StayID '{$stay_id_to_remove_from}'.");
                $successMessage = "Diagnosis (Seq: {$seq_num_to_remove}) removed successfully from Stay ID {$stay_id_to_remove_from}.";
                $_SESSION['form_message'] = "<div class='alert alert-success alert-dismissible fade show'>".htmlspecialchars($successMessage)."<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            } else {
                $conn->rollback(); // No rows affected, maybe already deleted
                $message = "<div class='alert alert-warning'>Diagnosis not found or already removed.</div>";
            }
            $stmt_delete->close();
            header("Location: assign_ed_diagnosis.php?staffid=" . urlencode($loggedInStaffID) . "&selected_stay_id=" . urlencode($stay_id_to_remove_from));
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $message = "<div class='alert alert-danger'>Error removing diagnosis: " . htmlspecialchars($e->getMessage()) . "</div>";
            error_log("ED Diagnosis Removal Error: " . $e->getMessage());
        }
    }
}


// Retrieve message from session after redirect
if(isset($_SESSION['form_message'])){
    $message = $_SESSION['form_message'];
    unset($_SESSION['form_message']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign ED Diagnosis - WeCare</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        :root { --primary-color: #007bff; /* ... other vars */ }
        body { font-family: 'Poppins', sans-serif; padding-top: 70px; background-color: #f8f9fa; }
        .navbar { position: sticky; top: 0; z-index: 1030; /* ... */ }
        .hero-section { background-color: var(--primary-color); color: white; padding: 40px 0; text-align: center; margin-bottom: 30px; }
        .card { border: none; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.07); margin-bottom: 20px; }
        .card-header { background-color: #e9ecef; font-weight: 600; }
        .form-label { font-weight: 600; }
        .btn-submit { background: linear-gradient(to right, #005c99, #007bff); color: white; border: none; }
        .btn-danger-soft { background-color: #ffebe6; color: #dc3545; border: 1px solid #f5c6cb; }
        .btn-danger-soft:hover { background-color: #f8d7da; color: #b02a37; }
        .table th, .table td { vertical-align: middle; }
        .sticky-form { position: sticky; top: 80px; /* Adjust based on navbar height */ z-index: 100; background-color: #f8f9fa; padding-bottom: 15px; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
         <div class="container">
            <a class="navbar-brand" style="font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 700; color: var(--primary-color);" href="<?php echo addStaffIdToLink('AdminLandingPage.php', $loggedInStaffID); ?>">WeCare</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"><span class="navbar-toggler-icon"></span></button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                     <li class="nav-item <?php echo ($current_page == 'AdminLandingPage.php' || $current_page == 'index.php') ? 'active' : ''; ?>"><a class="nav-link" href="<?php echo addStaffIdToLink('AdminLandingPage.php', $loggedInStaffID); ?>">Home</a></li>
                    <li class="nav-item <?php echo ($current_page == 'register_patient.php') ? 'active' : ''; ?>"><a class="nav-link" href="<?php echo addStaffIdToLink('register_patient.php', $loggedInStaffID); ?>">Patients</a></li>
                    <li class="nav-item <?php echo ($current_page == 'manage_edstays.php') ? 'active' : ''; ?>"><a class="nav-link" href="<?php echo addStaffIdToLink('manage_edstays.php', $loggedInStaffID); ?>">ED</a></li>
                    <li class="nav-item <?php echo ($current_page == 'assign_ed_diagnosis.php') ? 'active' : ''; ?>"><a class="nav-link" href="<?php echo addStaffIdToLink('assign_ed_diagnosis.php', $loggedInStaffID); ?>">ED Diagnosis</a></li>
                    <li class="nav-item <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>"><a class="nav-link" href="<?php echo addStaffIdToLink('dashboard.php', $loggedInStaffID); ?>">Dashboards</a></li>
                </ul>
            </div>
        </div>
    </nav>
    <style>.navbar .nav-item.active .nav-link { color: var(--primary-color) !important; font-weight: bold !important; }</style>

    <div class="hero-section">
        <h1>Assign Diagnosis to ED Stay</h1>
    </div>

    <div class="container mt-4 mb-5">
        <div id="messageArea" style="min-height: 60px;">
            <?php if (!empty($message)) echo $message; ?>
            <?php if ($fetch_error_stays || $fetch_error_icd): ?>
                 <div class="alert alert-warning alert-dismissible fade show"><i class="fas fa-exclamation-circle me-2"></i> Could not load all required data for assignment. Some lists might be incomplete.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>
            <?php endif; ?>
        </div>

        <div class="sticky-form"> <!-- Make the assignment form sticky -->
            <div class="card">
                <div class="card-header"><i class="fas fa-file-medical-alt me-2"></i>Assign New Diagnosis</div>
                <div class="card-body">
                    <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?staffid=' . urlencode($loggedInStaffID); ?>" id="assignDiagnosisForm">
                        <input type="hidden" name="assign_diagnosis_action" value="1">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="stay_id_to_assign" class="form-label">Select Active ED Stay <span class="text-danger">*</span></label>
                                <select name="stay_id_to_assign" id="stay_id_to_assign" class="form-select" required onchange="this.form.submit()"> <!-- Submit form on change to load assigned diagnoses -->
                                    <option value="">-- Select ED Stay --</option>
                                    <?php foreach ($activeEDStays as $stay): ?>
                                        <option value="<?php echo htmlspecialchars($stay['StayID']); ?>" <?php if ($selected_stay_id == $stay['StayID']) echo "selected"; ?>>
                                            <?php echo htmlspecialchars($stay['PatientName']) . " (Stay ID: " . htmlspecialchars($stay['StayID']) . ")"; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="icd_code_to_assign" class="form-label">Select ICD Diagnosis <span class="text-danger">*</span></label>
                                <select name="icd_code_to_assign" id="icd_code_to_assign" class="form-select" required <?php if(empty($selected_stay_id)) echo "disabled";?>>
                                    <option value="">-- Select Diagnosis --</option>
                                    <?php foreach ($icdDiagnoses as $diag): ?>
                                        <option value="<?php echo htmlspecialchars($diag['ICD_Code']); ?>">
                                            <?php echo htmlspecialchars($diag['ICD_Title']) . " (" . htmlspecialchars($diag['ICD_Code']) . " - v" . htmlspecialchars($diag['ICD_version']) .")"; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if(empty($selected_stay_id)): ?>
                                    <small class="form-text text-muted">Select an ED Stay first to enable diagnosis selection.</small>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button type="submit" class="btn-submit btn btn-primary w-100" <?php if(empty($selected_stay_id)) echo "disabled";?>>
                            <i class="fas fa-plus-circle me-1"></i> Assign Diagnosis
                        </button>
                    </form>
                </div>
            </div>
        </div> <!-- End sticky-form -->

        <?php if ($selected_stay_id && !empty($activeEDStays)): // Show assigned diagnoses only if a stay is selected ?>
            <?php
                // Find the name of the selected patient
                $currentPatientName = "Unknown Patient";
                foreach($activeEDStays as $s) { if ($s['StayID'] == $selected_stay_id) { $currentPatientName = $s['PatientName']; break; } }
            ?>
            <div class="card mt-4">
                <div class="card-header"><i class="fas fa-list-ul me-2"></i>Assigned Diagnoses for <?php echo htmlspecialchars($currentPatientName); ?> (Stay ID: <?php echo htmlspecialchars($selected_stay_id); ?>)</div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>Seq #</th>
                                    <th>ICD Code</th>
                                    <th>ICD Title</th>
                                    <th>Version</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($assignedDiagnosesForSelectedStay)): ?>
                                    <?php foreach ($assignedDiagnosesForSelectedStay as $ad): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($ad['SeqNum']); ?></td>
                                            <td><?php echo htmlspecialchars($ad['ICD_Code']); ?></td>
                                            <td><?php echo htmlspecialchars($ad['ICD_Title']); ?></td>
                                            <td><?php echo htmlspecialchars($ad['ICD_version']); ?></td>
                                            <td class="text-center">
                                                <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?staffid=' . urlencode($loggedInStaffID) . '&selected_stay_id=' . urlencode($selected_stay_id); ?>" onsubmit="return confirm('Are you sure you want to remove this diagnosis?');">
                                                    <input type="hidden" name="remove_diagnosis_action" value="1">
                                                    <input type="hidden" name="stay_id_to_remove_from" value="<?php echo htmlspecialchars($ad['StayID']); ?>">
                                                    <input type="hidden" name="icd_code_to_remove" value="<?php echo htmlspecialchars($ad['ICD_Code']); ?>">
                                                    <input type="hidden" name="seq_num_to_remove" value="<?php echo htmlspecialchars($ad['SeqNum']); ?>">
                                                    <button type="submit" class="btn btn-danger-soft btn-sm" title="Remove Diagnosis">
                                                        <i class="fas fa-trash-alt"></i> Remove
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" class="text-center text-muted">No diagnoses assigned to this ED stay yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div> <!-- End Container -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-dismiss alerts
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(function(alert) {
                if (bootstrap && bootstrap.Alert) {
                   setTimeout(() => { bootstrap.Alert.getOrCreateInstance(alert)?.close(); }, 7000);
                } else { setTimeout(() => { alert.style.display = 'none'; }, 7000); }
           });

            // When ED Stay dropdown changes, submit the form to reload assigned diagnoses
            const stayDropdown = document.getElementById('stay_id_to_assign');
            const assignDiagnosisForm = document.getElementById('assignDiagnosisForm');
            const icdDropdown = document.getElementById('icd_code_to_assign');
            const submitButton = assignDiagnosisForm.querySelector('button[type="submit"]');

            // Function to handle form submission for selecting stay
            function handleStaySelectionChange() {
                // Create a temporary input to indicate this is a selection change, not an assignment
                let tempInput = document.createElement('input');
                tempInput.type = 'hidden';
                tempInput.name = 'stay_selection_changed'; // Or some other flag
                tempInput.value = '1';
                assignDiagnosisForm.appendChild(tempInput);
                assignDiagnosisForm.submit();
            }

            // Attach if not already attached (to prevent multiple event listeners on POST refresh)
            if (stayDropdown && !stayDropdown.hasAttribute('data-listener-attached')) {
                stayDropdown.addEventListener('change', handleStaySelectionChange);
                stayDropdown.setAttribute('data-listener-attached', 'true');
            }

            // Enable/disable ICD dropdown and submit button based on stay selection
            function toggleIcdAndSubmit() {
                if (stayDropdown && icdDropdown && submitButton) {
                    if (stayDropdown.value === "") {
                        icdDropdown.disabled = true;
                        submitButton.disabled = true;
                    } else {
                        icdDropdown.disabled = false;
                        submitButton.disabled = false;
                    }
                }
            }
            toggleIcdAndSubmit(); // Call on page load
            if (stayDropdown) {
                 stayDropdown.addEventListener('change', toggleIcdAndSubmit); // Also on change
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