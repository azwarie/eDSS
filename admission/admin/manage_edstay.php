<?php
session_start();
include 'connection.php'; // Defines $conn

// --- Database Connection Check ---
if (!$conn || $conn->connect_error) {
    die("Database connection failed: " . ($conn ? $conn->connect_error : 'Unknown error'));
}

// --- Define Role Constants ---
define('ROLE_CHECKIN_STAFF', 'CheckInStaff');
define('ROLE_TRIAGE_STAFF', 'TriageStaff');
define('ROLE_DISPOSITION_STAFF', 'DispositionStaff');
define('ROLE_CHECKOUT_STAFF', 'CheckoutStaff');
define('ROLE_ATTENDING_ED', 'AttendingED');

// --- Timezone & Session/URL Variables ---
date_default_timezone_set('Asia/Kuala_Lumpur');
$loggedInStaffID_Session = $_SESSION['staffid'] ?? null;
$loggedInStaffName = null;

if ($loggedInStaffID_Session) {
    $sql_current_staff = "SELECT Name FROM staff WHERE StaffID = ?";
    $stmt_current_staff = $conn->prepare($sql_current_staff);
    if ($stmt_current_staff) {
        $stmt_current_staff->bind_param("s", $loggedInStaffID_Session);
        if ($stmt_current_staff->execute()) {
            $result_current_staff = $stmt_current_staff->get_result();
            if ($row_current_staff = $result_current_staff->fetch_assoc()) {
                $loggedInStaffName = $row_current_staff['Name'];
            }
            if($result_current_staff) $result_current_staff->free();
        } else { error_log("Error executing staff name query: " . $stmt_current_staff->error); }
        $stmt_current_staff->close();
    } else { error_log("Error preparing staff name query: " . $conn->error); }
}
$loggedInStaffDisplayText = $loggedInStaffID_Session ? (($loggedInStaffName ?: "Unknown Staff") . " (ID: " . htmlspecialchars($loggedInStaffID_Session) . ")") : "N/A (Not Logged In)";

$staffIDFromURL = $_GET['staffid'] ?? null;
$staffIdForPageContext = $staffIDFromURL ?? $loggedInStaffID_Session;

if (empty($staffIdForPageContext) && empty($loggedInStaffID_Session) && basename($_SERVER['PHP_SELF']) !== 'login.php') { // Allow login page without session
    // Consider redirecting to login page if session is critical and not present
    // For now, die if critical context is missing for operational pages.
     if (basename($_SERVER['PHP_SELF']) !== 'some_public_page.php') { // Example of a public page
        die("Staff ID context is missing or session expired. Cannot operate page. Please <a href='login.php'>log in</a>.");
     }
}
$current_page = basename($_SERVER['PHP_SELF']);
$staffID = $_GET['staffid'] ?? $staffIdForPageContext;

// --- Helper Function: Add Staff ID to Links ---
function addStaffIdToLink($url, $staffIdParam) {
    if ($staffIdParam !== null && $staffIdParam !== '') {
        $separator = (strpos($url, '?') === false) ? '?' : '&';
        return $url . $separator . 'staffid=' . urlencode($staffIdParam);
    }
    return $url;
}
// --- Helper Function: Log Audit Trail ---
function logAuditTrail($conn_audit, $actorStaffID, $action, $description) {
    $conn_to_use = $conn_audit ?? $GLOBALS['conn'] ?? null;
    if (!$conn_to_use) return;
    try {
        $sql_audit = "INSERT INTO AUDIT_TRAIL (StaffID, Action, Description, Timestamp) VALUES (?, ?, ?, NOW())";
        $stmt_audit = $conn_to_use->prepare($sql_audit);
        if ($stmt_audit === false) { throw new Exception("Prepare audit: " . $conn_to_use->error); }
        $actingStaff = !empty($actorStaffID) ? $actorStaffID : 'SYSTEM';
        $stmt_audit->bind_param('sss', $actingStaff, $action, $description);
        if (!$stmt_audit->execute()) { throw new Exception("Exec audit: " . $stmt_audit->error); }
        $stmt_audit->close();
    } catch (Exception $e) { error_log("Audit error: " . $e->getMessage()); }
}

// --- Initialize Form Variables ---
// These will be populated from $_POST if available, otherwise default.
$edStay_StayID_form = $_POST['stay_id_form'] ?? "";
$PatientID_FK_form = $_POST['patient_id_fk_form'] ?? "";
$Arrival_transport_edstay_form = $_POST['arrival_transport_edstay'] ?? "";
$Disposition_form = $_POST['disposition_form'] ?? "";

// For "Attending Staff" (staff_id_fk_form) for check-in:
// If it's a GET request (fresh page load), set it to logged-in staff.
// On POST, it will be what was submitted (even if disabled, its value is sent from the form).
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $StaffID_FK_form = $loggedInStaffID_Session; // Pre-fill with logged-in staff for new check-ins
} else {
    // Use submitted value if available (e.g. form submitted with errors, value should persist)
    // Fallback to logged-in staff if not set in POST (shouldn't happen for check-in POST)
    $StaffID_FK_form = $_POST['staff_id_fk_form'] ?? $loggedInStaffID_Session;
}

// Triage form variables
$TriageStayID_form_hidden = $_POST['triage_stay_id_hidden'] ?? '';
$Existing_TriageID_form = $_POST['existing_triage_id'] ?? '';
$ChiefComplaint_form = $_POST['chief_complaint'] ?? '';
$Temperature_form = $_POST['temperature'] ?? '';
$Heartrate_form = $_POST['heartrate'] ?? '';
$Resprate_form = $_POST['resprate'] ?? '';
$O2sat_form = $_POST['o2sat'] ?? '';
$SBP_form = $_POST['sbp'] ?? '';
$DBP_form = $_POST['dbp'] ?? '';
$Pain_form = $_POST['pain'] ?? '';
$Acuity_form_val = $_POST['acuity'] ?? '';

$errors = []; $successMessage = "";

// --- Fetch Data for Dropdowns ---
$patientsList = []; $sql_patients = "SELECT PatientID, PatientName FROM patients ORDER BY PatientName ASC"; $result_patients = $conn->query($sql_patients); if ($result_patients) { while ($row = $result_patients->fetch_assoc()) { $patientsList[] = $row; } $result_patients->free(); } else { $errors[] = "Error fetching patients list: " . $conn->error; }
$staffList = []; $sql_staff = "SELECT StaffID, Name FROM staff ORDER BY Name ASC"; $result_staff = $conn->query($sql_staff); if ($result_staff) { while ($row = $result_staff->fetch_assoc()) { $staffList[] = $row; } $result_staff->free(); } else { error_log("Error fetching staff list: " . $conn->error); }
$acuityOptions = ["1-Immediate" => 1, "2-Emergent" => 2, "3-Urgent" => 3, "4-Less Urgent" => 4, "5-Non-Urgent" => 5];
$arrivalTransportOptions = ["Ambulance", "Walk-in"];

// --- Handle POST Requests ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['submit_triage'])) {
        // --- TRIAGE SUBMISSION (ADD or EDIT) ---
        // Values are already initialized from $_POST above
        // Triage Validation
        if (empty($loggedInStaffID_Session)) $errors[] = "Cannot save triage: User not logged in or session expired.";
        if (empty($TriageStayID_form_hidden)) $errors[] = "Stay ID is missing for triage.";
        if (empty($ChiefComplaint_form)) $errors[] = "Chief Complaint is required for triage.";
        if (empty($Pain_form)) $errors[] = "Pain assessment is required.";
        if (empty($Acuity_form_val) || !array_key_exists($Acuity_form_val, array_flip($acuityOptions))) { $errors[] = "Acuity is required and must be a valid selection."; }

        if (empty($errors)) {
            $conn->begin_transaction();
            try {
                $temp_val = !empty($Temperature_form) ? (float)$Temperature_form : 0.0; $hr_val = !empty($Heartrate_form) ? (int)$Heartrate_form : 0; $rr_val = !empty($Resprate_form) ? (int)$Resprate_form : 0; $o2_val = !empty($O2sat_form) ? (float)$O2sat_form : 0.0; $sbp_val_db = !empty($SBP_form) ? (int)$SBP_form : 0; $dbp_val_db = !empty($DBP_form) ? (int)$DBP_form : 0;

                if (!empty($Existing_TriageID_form)) { // EDIT Triage
                    $sql_update_triage = "UPDATE triage SET Temperature=?, Heartrate=?, Resprate=?, O2sat=?, SBP=?, DBP=?, Pain=?, Acuity=? WHERE TriageID = ?";
                    $stmt_update_details = $conn->prepare($sql_update_triage); if($stmt_update_details === false) throw new Exception("Prep Update Triage: " . $conn->error);
                    $stmt_update_details->bind_param("diidiisis", $temp_val, $hr_val, $rr_val, $o2_val, $sbp_val_db, $dbp_val_db, $Pain_form, $Acuity_form_val, $Existing_TriageID_form);
                    if (!$stmt_update_details->execute()) throw new Exception("Exec Update Triage: " . $stmt_update_details->error); $stmt_update_details->close();
                    $sql_update_bridge = "UPDATE edstays_triage SET ChiefComplaint = ? WHERE TriageID = ? AND StayID = ?";
                    $stmt_update_bridge = $conn->prepare($sql_update_bridge); if($stmt_update_bridge === false) throw new Exception("Prep Update Bridge: " . $conn->error);
                    $stmt_update_bridge->bind_param("sss", $ChiefComplaint_form, $Existing_TriageID_form, $TriageStayID_form_hidden);
                    if (!$stmt_update_bridge->execute()) throw new Exception("Exec Update Bridge: " . $stmt_update_bridge->error); $stmt_update_bridge->close();
                    $role_triage_staff_var = ROLE_TRIAGE_STAFF;
                    $sql_log_role = "INSERT INTO edstay_staff_roles (StayID, StaffID, Role, ActivityTimestamp) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE ActivityTimestamp=NOW()";
                    $stmt_log_role = $conn->prepare($sql_log_role); if ($stmt_log_role) { $stmt_log_role->bind_param("sss", $TriageStayID_form_hidden, $loggedInStaffID_Session, $role_triage_staff_var); if(!$stmt_log_role->execute()){error_log("Fail TriageStaff (edit) log:".$stmt_log_role->error);} $stmt_log_role->close(); } else {error_log("Prep TriageStaff (edit) log fail:".$conn->error);}
                    $successMessage = "Triage data updated (Triage ID: $Existing_TriageID_form)";
                    logAuditTrail($conn, $loggedInStaffID_Session, 'Triage Update', "Updated Triage {$Existing_TriageID_form} for Stay {$TriageStayID_form_hidden}.");
                } else { // ADD Triage
                    $sql_check_bridge = "SELECT TriageID FROM edstays_triage WHERE StayID = ?"; $stmt_check_bridge = $conn->prepare($sql_check_bridge); $stmt_check_bridge->bind_param("s", $TriageStayID_form_hidden); $stmt_check_bridge->execute(); $result_check_bridge = $stmt_check_bridge->get_result(); if ($result_check_bridge->num_rows > 0) { throw new Exception("Triage data already linked to StayID: $TriageStayID_form_hidden."); } $stmt_check_bridge->close(); $result_check_bridge->free();
                    $sql_triage_id = "SELECT MAX(CAST(SUBSTRING(TriageID, 3) AS UNSIGNED)) AS max_id FROM triage"; $result_triage_id = $conn->query($sql_triage_id); if (!$result_triage_id) throw new Exception("Failed query max triage ID: " . $conn->error); $row_triage_id = $result_triage_id->fetch_assoc(); $new_triage_id = $row_triage_id['max_id'] ? 'TR' . str_pad($row_triage_id['max_id'] + 1, 3, '0', STR_PAD_LEFT) : 'TR001'; $result_triage_id->free();
                    $sql_insert_triage_details = "INSERT INTO triage (TriageID, Temperature, Heartrate, Resprate, O2sat, SBP, DBP, Pain, Acuity) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt_insert_details = $conn->prepare($sql_insert_triage_details); if($stmt_insert_details === false) throw new Exception("Prep Insert Triage: " . $conn->error);
                    $stmt_insert_details->bind_param("sdiidiisi", $new_triage_id, $temp_val, $hr_val, $rr_val, $o2_val, $sbp_val_db, $dbp_val_db, $Pain_form, $Acuity_form_val);
                    if (!$stmt_insert_details->execute()) throw new Exception("Exec Insert Triage: " . $stmt_insert_details->error); $stmt_insert_details->close();
                    $sql_insert_bridge = "INSERT INTO edstays_triage (StayID, TriageID, ChiefComplaint) VALUES (?, ?, ?)"; $stmt_insert_bridge = $conn->prepare($sql_insert_bridge); if($stmt_insert_bridge === false) throw new Exception("Prep Insert Bridge: " . $conn->error); $stmt_insert_bridge->bind_param("sss", $TriageStayID_form_hidden, $new_triage_id, $ChiefComplaint_form);
                    if (!$stmt_insert_bridge->execute()) throw new Exception("Exec Insert Bridge: " . $stmt_insert_bridge->error); $stmt_insert_bridge->close();
                    $updateStatusSql = "UPDATE edstays SET Status = 'Waiting for Doctor' WHERE StayID = ? AND Status = 'Waiting for Triage'";
                    $stmtUpdateStatus = $conn->prepare($updateStatusSql); if ($stmtUpdateStatus) { $stmtUpdateStatus->bind_param("s", $TriageStayID_form_hidden); if (!$stmtUpdateStatus->execute()) { error_log("Failed to update edstay status: " . $stmtUpdateStatus->error); } $stmtUpdateStatus->close(); } else { error_log("Prep fail status update: " . $conn->error); }
                    $role_triage_staff_var = ROLE_TRIAGE_STAFF;
                    $sql_insert_role = "INSERT INTO edstay_staff_roles (StayID, StaffID, Role, ActivityTimestamp) VALUES (?, ?, ?, NOW())"; $stmt_insert_role = $conn->prepare($sql_insert_role); if ($stmt_insert_role === false) { error_log("Prep fail TriageStaff role log: " . $conn->error); } else { $stmt_insert_role->bind_param("sss", $TriageStayID_form_hidden, $loggedInStaffID_Session, $role_triage_staff_var); if (!$stmt_insert_role->execute()) { error_log("Exec fail TriageStaff role log: " . $stmt_insert_role->error); } $stmt_insert_role->close(); }
                    $successMessage = "Triage data (ID: $new_triage_id) saved. Status updated.";
                    logAuditTrail($conn, $loggedInStaffID_Session, 'Triage Entry', "Added Triage {$new_triage_id} & CC for Stay {$TriageStayID_form_hidden}. Role '" . ROLE_TRIAGE_STAFF . "' assigned.");
                }
                $conn->commit();
                // Clear triage form variables after successful submission
                $TriageStayID_form_hidden = $Existing_TriageID_form = $ChiefComplaint_form = $Temperature_form = $Heartrate_form = $Resprate_form = $O2sat_form = $SBP_form = $DBP_form = $Pain_form = $Acuity_form_val = "";
            } catch (Exception $e) {
                $conn->rollback();
                error_log("Triage Save/Update Error: " . $e->getMessage()); $errors[] = "Error processing triage data: " . $e->getMessage();
            }
        }
    } else { // --- EDStay Check-In OR Check-Out Logic ---
        // Use previously initialized form variables: $edStay_StayID_form, $PatientID_FK_form, $StaffID_FK_form, $Arrival_transport_edstay_form, $Disposition_form
        $attendingStaffIDFromForm = $StaffID_FK_form; // For check-in, $StaffID_FK_form is the logged-in user (now auto-set)
        $isCheckoutAction = isset($_POST['is_checkout_action']) && $_POST['is_checkout_action'] === '1';

        // Validation
        if (empty($PatientID_FK_form)) $errors[] = 'Patient is required.';
        if (!$isCheckoutAction) { // CHECK-IN specific validation
            if (empty($attendingStaffIDFromForm)) $errors[] = 'Attending Staff is missing. Please ensure you are logged in.'; // Should be auto-filled
            if (empty($Arrival_transport_edstay_form) || !in_array($Arrival_transport_edstay_form, $arrivalTransportOptions)) $errors[] = "Arrival Transport is required.";
            if (empty($loggedInStaffID_Session)) $errors[] = "Check-in cannot proceed: Staff performing action not identified.";
        }
        if ($isCheckoutAction) { // CHECK-OUT specific validation
            if (empty($edStay_StayID_form)) $errors[] = 'Stay ID is missing for checkout.'; // $edStay_StayID_form should be from stay_id_form
            if (empty($Disposition_form)) $errors[] = 'Disposition is required for checkout.';
            if (empty($loggedInStaffID_Session)) $errors[] = "Checkout cannot proceed: Staff performing action not identified.";
        }

        if (empty($errors)) {
            $conn->begin_transaction();
            try {
                if (!empty($edStay_StayID_form) && $isCheckoutAction) { // CHECK-OUT
                    $dbOutime = date('Y-m-d H:i:s'); $finalStatusOnCheckout = 'Completed';
                    $sql = "UPDATE edstays SET Outime = ?, Disposition = ?, Status = ? WHERE StayID = ? AND Outime IS NULL";
                    $stmt = $conn->prepare($sql); if ($stmt === false) throw new Exception("Prep Checkout: " . $conn->error);
                    $stmt->bind_param("ssss", $dbOutime, $Disposition_form, $finalStatusOnCheckout, $edStay_StayID_form); // Use $edStay_StayID_form
                    if (!$stmt->execute()) throw new Exception("Exec Checkout: " . $stmt->error);
                    
                    if ($stmt->affected_rows > 0) {
                        $role_to_log = ROLE_CHECKOUT_STAFF;
                        $sql_log_staff_role = "INSERT INTO edstay_staff_roles (StayID, StaffID, Role, ActivityTimestamp) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE ActivityTimestamp=NOW()";
                        $stmt_log_role = $conn->prepare($sql_log_staff_role);
                        if($stmt_log_role){
                            $stmt_log_role->bind_param("sss", $edStay_StayID_form, $loggedInStaffID_Session, $role_to_log); // Use $edStay_StayID_form
                            if(!$stmt_log_role->execute()){ error_log("Failed to log role '" . $role_to_log . "' for StayID " . $edStay_StayID_form . ": " . $stmt_log_role->error); }
                            $stmt_log_role->close();
                        } else { error_log("Prepare statement failed for logging role '" . $role_to_log . "': " . $conn->error); }
                        $successMessage = "Patient (Stay ID: $edStay_StayID_form) checked out. Status: Completed.";
                        logAuditTrail($conn, $loggedInStaffID_Session, 'EDStay Checkout', "Checked out Stay {$edStay_StayID_form}. Disp: '{$Disposition_form}'. Role '" . $role_to_log . "' assigned.");
                    } else {
                        $checkStatusSql = "SELECT Status FROM edstays WHERE StayID = ?";
                        $stmtCheck = $conn->prepare($checkStatusSql);
                        if ($stmtCheck) {
                            $stmtCheck->bind_param("s", $edStay_StayID_form); // Use $edStay_StayID_form
                            $stmtCheck->execute(); $statusResult = $stmtCheck->get_result();
                            if ($statusRow = $statusResult->fetch_assoc()) {
                                if ($statusRow['Status'] === 'Completed') { $errors[] = "Checkout failed: Patient (Stay ID: $edStay_StayID_form) has already been completed/checked out."; }
                                else { $errors[] = "Checkout failed for Stay ID: $edStay_StayID_form. Record not updated (current status: ".htmlspecialchars($statusRow['Status']).")."; }
                            } else { $errors[] = "Checkout failed: Invalid Stay ID ($edStay_StayID_form) or patient record not found."; }
                            if(isset($statusResult)) $statusResult->free(); $stmtCheck->close();
                        } else { $errors[] = "Checkout failed. Could not verify stay status."; error_log("Prep fail chk status: " . $conn->error); }
                    }
                    $stmt->close();
                    // Clear main form variables after successful checkout
                    $edStay_StayID_form = $Disposition_form = $PatientID_FK_form = $Arrival_transport_edstay_form = $StaffID_FK_form = "";
                    if ($_SERVER['REQUEST_METHOD'] === 'GET') {$StaffID_FK_form = $loggedInStaffID_Session;} // Re-set for next check-in display
                } elseif (empty($edStay_StayID_form) && !$isCheckoutAction) { // CHECK-IN
                    $dbIntime = date('Y-m-d H:i:s');
                    $sql_stay_id_gen = "SELECT MAX(CAST(SUBSTRING(StayID, 2) AS UNSIGNED)) AS max_id FROM edstays"; $result_stay_id = $conn->query($sql_stay_id_gen); if (!$result_stay_id) throw new Exception("Query max_id failed: " . $conn->error); $row_stay_id = $result_stay_id->fetch_assoc(); $new_stay_id = $row_stay_id['max_id'] ? 'S' . str_pad($row_stay_id['max_id'] + 1, 3, '0', STR_PAD_LEFT) : 'S001'; $result_stay_id->free();
                    $initialStatus = 'Waiting for Triage';
                    $sql_insert_edstay = "INSERT INTO edstays (StayID, Intime, PatientID, StaffID, Arrival_transport, Status) VALUES (?, ?, ?, ?, ?, ?)";
                    $stmt_insert_edstay = $conn->prepare($sql_insert_edstay); if ($stmt_insert_edstay === false) throw new Exception("Prep Check-In: " . $conn->error);
                    // $attendingStaffIDFromForm is $StaffID_FK_form which is auto-set to logged-in user
                    $stmt_insert_edstay->bind_param("ssssss", $new_stay_id, $dbIntime, $PatientID_FK_form, $attendingStaffIDFromForm, $Arrival_transport_edstay_form, $initialStatus);
                    if (!$stmt_insert_edstay->execute()) throw new Exception("Exec Check-In: " . $stmt_insert_edstay->error);
                    $stmt_insert_edstay->close();

                    if ($attendingStaffIDFromForm) {
                        $role_attending_ed = ROLE_ATTENDING_ED;
                        $sql_log_attending = "INSERT INTO edstay_staff_roles (StayID, StaffID, Role, ActivityTimestamp) VALUES (?, ?, ?, NOW())";
                        $stmt_log_attending = $conn->prepare($sql_log_attending);
                        if ($stmt_log_attending) { $stmt_log_attending->bind_param("sss", $new_stay_id, $attendingStaffIDFromForm, $role_attending_ed); if (!$stmt_log_attending->execute()) { error_log("Failed log AttendingED for Stay {$new_stay_id}: " . $stmt_log_attending->error); } $stmt_log_attending->close(); } else { error_log("Prep fail AttendingED log: " . $conn->error); }
                    }
                    $successMessage = "Patient checked-in (Stay ID: $new_stay_id)! Status: $initialStatus. Add Triage next.";
                    logAuditTrail($conn, $loggedInStaffID_Session, 'EDStay Check-In', "Checked-in Stay {$new_stay_id} by {$loggedInStaffID_Session} for Pt {$PatientID_FK_form}. Attending: {$attendingStaffIDFromForm}. Transport: {$Arrival_transport_edstay_form}.");
                    // Clear main form variables after successful check-in
                    $PatientID_FK_form = $Arrival_transport_edstay_form = "";
                    // $StaffID_FK_form should be reset to loggedInStaffID_Session for the next check-in form display
                    if ($_SERVER['REQUEST_METHOD'] === 'GET') {$StaffID_FK_form = $loggedInStaffID_Session;} // Re-set for next check-in display
                    else { $StaffID_FK_form = $loggedInStaffID_Session; } // Also reset after POST for next new check-in
                }

                if (empty($errors)) {
                     if (!$conn->commit()) { throw new Exception("Transaction commit failed."); }
                } else {
                    $conn->rollback();
                }
            } catch (Exception $e) {
                $conn->rollback();
                error_log("EDStay Operation Error: " . $e->getMessage());
                $errors[] = "An error occurred: " . $e->getMessage();
            }
        }
    }
}

// --- Fetch ACTIVE EDStays (with triage data) ---
$itemsPerPage_ed = 10; $page_ed = isset($_GET['page_ed']) && (int)$_GET['page_ed'] > 0 ? (int)$_GET['page_ed'] : 1; $offset_ed = ($page_ed - 1) * $itemsPerPage_ed;
$activeEDStaysData = []; $totalPages_ed = 0; $fetch_error_ed = null;
try {
    $totalActiveEDStaysQuery = "SELECT COUNT(*) AS total FROM edstays WHERE Outime IS NULL";
    $totalResult_ed = $conn->query($totalActiveEDStaysQuery); if ($totalResult_ed) { $totalActiveEDStays = $totalResult_ed->fetch_assoc()['total']; $totalPages_ed = ceil($totalActiveEDStays / $itemsPerPage_ed); $totalResult_ed->free(); } else { throw new Exception("Count active stays: " . $conn->error); }
    $edStaysQuery = "SELECT es.StayID, es.Intime, es.PatientID, p.PatientName, es.StaffID AS AttendingStaffID_edstays, s_attending.Name AS AttendingStaffName, es.Status AS EDStatus, es.Arrival_transport AS StayArrivalTransport, et.TriageID, et.ChiefComplaint, t.Temperature, t.Heartrate, t.Resprate, t.O2sat, t.SBP, t.DBP, t.Pain, t.Acuity FROM edstays es JOIN patients p ON es.PatientID = p.PatientID LEFT JOIN staff s_attending ON es.StaffID = s_attending.StaffID LEFT JOIN edstays_triage et ON es.StayID = et.StayID LEFT JOIN triage t ON et.TriageID = t.TriageID WHERE es.Outime IS NULL ORDER BY es.Intime DESC LIMIT ? OFFSET ?";
    $stmt_edstays = $conn->prepare($edStaysQuery); if ($stmt_edstays === false) throw new Exception("Prep Fetch Stays: " . $conn->error);
    $stmt_edstays->bind_param("ii", $itemsPerPage_ed, $offset_ed); 
    if (!$stmt_edstays->execute()) throw new Exception("Exec Fetch Stays: " . $stmt_edstays->error);
    $result_edstays = $stmt_edstays->get_result(); if ($result_edstays) { $activeEDStaysData = $result_edstays->fetch_all(MYSQLI_ASSOC); $result_edstays->free(); } else { throw new Exception("Get result Fetch Stays: " . $stmt_edstays->error); }
    $stmt_edstays->close();
} catch (Exception $e) { $fetch_error_ed = "Error fetching ED Stay list: " . $e->getMessage(); error_log($fetch_error_ed); }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"> <meta name="viewport" content="width=device-width, initial-scale=1.0"> <title>ED Check-In & Triage - WeCare</title> <link rel="preconnect" href="https://fonts.googleapis.com"> <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin> <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet"> <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;700&display=swap" rel="stylesheet"> <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"> <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        :root { --primary-color: #007bff; --background-color: #f8f9fa;} body { font-family: 'Poppins', sans-serif; padding-top: 70px; background-color: var(--background-color); } .navbar { position: sticky; top: 0; z-index: 1030; background-color: white !important; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05); } .navbar-brand { font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 700; color: var(--primary-color) !important; } .nav-link { color: #495057 !important; font-weight: 500; } .nav-link:hover { color: var(--primary-color) !important; } .nav-item.active .nav-link { color: var(--primary-color) !important; font-weight: 700 !important; } .hero-section { background-color: var(--primary-color); color: white; padding: 40px 0; text-align: center; margin-bottom: 30px; } .card { border: none; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.07); margin-bottom: 20px; } .card-header { background-color: #e9ecef; font-weight: 600; } .form-label { font-weight: 600; } .form-control, .form-select { margin-bottom: 1rem; } .btn-submit { color: white; border: none; padding: 0.6rem 1.5rem; width: 100%; } .checkout-btn, .triage-btn { color:white; border: none; font-size: 0.85rem; padding: 0.25rem 0.5rem; } .checkout-btn { background-color: #28a745; } .triage-btn { background-color: #17a2b8; } .checkout-btn i, .triage-btn i { margin-right: 4px; } .pagination { justify-content: center; } .invalid-feedback { display: block; width: 100%; margin-top: -0.75rem; margin-bottom: 0.5rem; font-size: .875em; color: #dc3545; } .form-field-hidden { display: none !important; } .modal-header { background-color: var(--primary-color); color: white; } .modal-header .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
        .table th, .table td { white-space: nowrap; }
        select:disabled { background-color: #e9ecef; } /* Style for disabled select */
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-light">
        <div class="container">
            <!-- Using href from function -->
       <a class="navbar-brand" href="<?php echo addStaffIdToLink('http://localhost/dss/staff/admin/AdminLandingPage.php', $staffIdForPageContext); // Link to AdminLandingPage ?>">eDSS</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item <?php echo ($current_page == 'index.php') ? 'active' : ''; ?>">
                        <a class="nav-link" href="<?php echo addStaffIdToLink('index.php', $staffIdForPageContext); ?>">Home</a>
                    </li>
                    <li class="nav-item <?php echo ($current_page == 'manage_edstay.php') ? 'active' : ''; ?>">
                        <a class="nav-link" href="<?php echo addStaffIdToLink('manage_edstay.php', $staffIdForPageContext); ?>">ED</a>
                    </li>
                    <li class="nav-item <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
                        <a class="nav-link" href="<?php echo addStaffIdToLink('dashboard.php', $staffIdForPageContext); ?>">Real-Time Dashboards</a>
                    </li>
                    <!-- Add Logout or other links as needed -->
                </ul>
            </div>
        </div>
    </nav>
    <style>.navbar .nav-item.active .nav-link { color: var(--primary-color) !important; font-weight: bold !important; }</style>


    <div class="hero-section"><h1>ED Check-In & Triage</h1></div>

    <div class="container mt-4 mb-5">
        <div id="messageArea" style="min-height: 60px;">
            <?php if (!empty($errors)): ?><div class='alert alert-danger alert-dismissible fade show' role='alert'><h5 class="alert-heading">Errors:</h5><ul class="mb-0"><?php foreach ($errors as $error): echo "<li>" . htmlspecialchars($error) . "</li>"; endforeach; ?></ul><button type='button' class='btn-close' data-bs-dismiss='alert'></button></div><?php endif; ?>
            <?php if (!empty($successMessage)): ?><div class='alert alert-success alert-dismissible fade show' role='alert'><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($successMessage); ?><button type='button' class='btn-close' data-bs-dismiss='alert'></button></div><?php endif; ?>
            <?php if ($fetch_error_ed): ?><div class="alert alert-warning" role="alert"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($fetch_error_ed); ?></div><?php endif; ?>
        </div>

        <div class="card" id="edStayFormCard">
            <div class="card-header"><i class="fas fa-notes-medical me-2"></i><span id="formTitle">Patient Check-In</span></div>
            <div class="card-body">
                <form method="POST" action="<?php echo addStaffIdToLink(htmlspecialchars($_SERVER['PHP_SELF']), $staffIdForPageContext); ?>" id="edStayForm">
                    <input type="hidden" name="stay_id_form" id="stay_id_form_main" value="<?php echo htmlspecialchars($edStay_StayID_form); ?>">
                    <input type="hidden" name="is_checkout_action" id="is_checkout_action" value="">

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="patient_id_fk_form" class="form-label">Patient <span class="text-danger">*</span></label>
                            <select name="patient_id_fk_form" id="patient_id_fk_form" class="form-select" required>
                                <option value="">Select Patient</option>
                                <?php foreach ($patientsList as $patient): ?> <option value="<?php echo htmlspecialchars($patient['PatientID']); ?>" <?php if ($PatientID_FK_form == $patient['PatientID']) echo "selected"; ?>><?php echo htmlspecialchars($patient['PatientName']) . " (ID: " . htmlspecialchars($patient['PatientID']) . ")"; ?></option> <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div id="attending_staff_container_checkin">
                                <label for="staff_id_fk_form" class="form-label">Attending Staff for ED Stay</label>
                                <select name="staff_id_fk_form" id="staff_id_fk_form" class="form-select" required disabled> <!-- Made disabled by default for check-in mode -->
                                    <option value="">Loading...</option> <!-- Placeholder, JS will populate -->
                                    <?php foreach ($staffList as $staff): ?> <option value="<?php echo htmlspecialchars($staff['StaffID']); ?>" <?php if ($StaffID_FK_form == $staff['StaffID']) echo "selected"; ?>><?php echo htmlspecialchars($staff['Name']) . " (ID: " . htmlspecialchars($staff['StaffID']) . ")"; ?></option> <?php endforeach; ?>
                                </select>
                            </div>
                            <div id="attending_staff_container_checkout" class="form-field-hidden">
                                <label for="staff_id_fk_form_display_checkout" class="form-label">Original Attending Staff</label>
                                <input type="text" id="staff_id_fk_form_display_checkout" class="form-control" readonly>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3" id="arrival_transport_container">
                       <label for="arrival_transport_edstay" class="form-label">Mode of Arrival <span class="text-danger">*</span></label>
                        <select class="form-select" name="arrival_transport_edstay" id="arrival_transport_edstay" required>
                            <option value="">Select Transport</option>
                            <?php foreach($arrivalTransportOptions as $option): ?> <option value="<?php echo htmlspecialchars($option); ?>" <?php if($Arrival_transport_edstay_form == $option) echo "selected"; ?>><?php echo htmlspecialchars($option); ?></option> <?php endforeach; ?>
                        </select>
                   </div>
                    <div class="mb-3 form-field-hidden" id="intime_form_container"><label for="intime_form_display" class="form-label">In-Time</label><input type="text" class="form-control" id="intime_form_display" readonly></div>
                    <div class="mb-3 form-field-hidden" id="disposition_container"><label for="disposition_form" class="form-label">Disposition <span class="text-danger">*</span></label><textarea name="disposition_form" id="disposition_form" class="form-control" rows="3"><?php echo htmlspecialchars($Disposition_form); ?></textarea></div>
                    <button type="submit" class="btn-submit btn btn-primary w-100" id="submitBtnEDStay"><i class="fas fa-sign-in-alt me-1"></i> <span id="submitBtnTextEDStay">Check-In Patient</span></button>
                    <button type="button" class="btn btn-secondary mt-2 w-100" onclick="resetToCheckInMode()"><i class="fas fa-times me-1"></i> Clear Form</button>
                </form>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header"><i class="fas fa-user-clock me-2"></i>Active ED Patients (<span id="activePatientCount"><?php echo $totalActiveEDStays ?? 0; ?></span>)</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover table-sm">
                        <thead><tr><th>Stay ID</th><th>Patient</th><th>Attending Staff</th><th>Current Status</th><th>In-Time</th><th class="text-center">Actions</th></tr></thead>
                        <tbody>
                            <?php if (!empty($activeEDStaysData)): ?>
                                <?php foreach ($activeEDStaysData as $stay):
                                    $hasTriage = !empty($stay['TriageID']);
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($stay['StayID']); ?></td>
                                    <td><?php echo htmlspecialchars($stay['PatientName'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($stay['AttendingStaffName'] ?? 'N/A'); ?></td>
                                    <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($stay['EDStatus'] ?? 'N/A'); ?></span></td>
                                    <td><?php echo !empty($stay['Intime']) ? htmlspecialchars(date('d M Y, h:i A', strtotime($stay['Intime']))) : 'N/A'; ?></td>
                                    <td class="text-center">
                                        <?php
                                            $button_class = $hasTriage ? 'btn-secondary' : 'btn-info';
                                            $button_icon = $hasTriage ? 'fa-eye' : 'fa-file-medical-alt';
                                            $button_text = $hasTriage ? 'View/Edit Triage' : 'Add Triage';
                                            $button_title = $hasTriage ? 'View or Edit Existing Triage Data' : 'Add New Triage Data';
                                            if (in_array($stay['EDStatus'], ['Waiting for Triage', 'Waiting for Doctor', 'Doctor Assessment', 'Awaiting Disposition'])) :
                                        ?>
                                            <button class='triage-btn btn btn-sm <?php echo $button_class; ?> me-1'
                                                data-bs-toggle="modal" data-bs-target="#triageModal"
                                                data-stay-data='<?php echo htmlspecialchars(json_encode($stay), ENT_QUOTES, 'UTF-8'); ?>'
                                                onclick="prepareTriageModal(this)"
                                                title="<?php echo $button_title; ?>">
                                                <i class="fas <?php echo $button_icon; ?>"></i> <?php echo $button_text; ?>
                                            </button>
                                        <?php endif; ?>
                                        <?php if (!in_array($stay['EDStatus'], ['Completed'])): ?>
                                            <button class='checkout-btn btn btn-sm'
                                                data-stay='<?php echo htmlspecialchars(json_encode($stay), ENT_QUOTES, 'UTF-8'); ?>'
                                                onclick='prepareCheckout(JSON.parse(this.getAttribute("data-stay"))); return false;'
                                                title="Set Disposition & Checkout">
                                                <i class='fas fa-sign-out-alt'></i> Checkout
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center text-muted">No active ED patients.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php /* Pagination placeholder */ ?>
            </div>
        </div>
    </div> <!-- End Container -->

    <!-- Triage Modal -->
    <div class="modal fade" id="triageModal" tabindex="-1" aria-labelledby="triageModalLabel" aria-hidden="true"> <div class="modal-dialog modal-xl"> <div class="modal-content"> <form method="POST" action="<?php echo addStaffIdToLink(htmlspecialchars($_SERVER['PHP_SELF']), $staffIdForPageContext); ?>" id="triageForm"> <input type="hidden" name="triage_stay_id_hidden" id="triage_stay_id_hidden"> <input type="hidden" name="existing_triage_id" id="existing_triage_id"> <div class="modal-header"><h5 class="modal-title" id="triageModalLabel">Triage Data: <span id="triagePatientName"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div> <div class="modal-body"> <div class="mb-3"><label for="chief_complaint" class="form-label">Chief Complaint <span class="text-danger">*</span></label><textarea class="form-control" name="chief_complaint" id="chief_complaint" rows="2" required><?php echo htmlspecialchars($ChiefComplaint_form); ?></textarea></div><hr><h6>Vitals & Assessment</h6> <div class="row"> <div class="col-md-3 col-sm-6 mb-3"><label for="temperature" class="form-label">Temp (°C)</label><input type="number" step="0.1" min="0" class="form-control" name="temperature" id="temperature" value="<?php echo htmlspecialchars($Temperature_form); ?>"></div> <div class="col-md-3 col-sm-6 mb-3"><label for="heartrate" class="form-label">HR (bpm)</label><input type="number" min="0" class="form-control" name="heartrate" id="heartrate" value="<?php echo htmlspecialchars($Heartrate_form); ?>"></div> <div class="col-md-3 col-sm-6 mb-3"><label for="resprate" class="form-label">RR (rpm)</label><input type="number" min="0" class="form-control" name="resprate" id="resprate" value="<?php echo htmlspecialchars($Resprate_form); ?>"></div> <div class="col-md-3 col-sm-6 mb-3"><label for="o2sat" class="form-label">O2 Sat (%)</label><input type="number" step="0.1" min="0" max="100" class="form-control" name="o2sat" id="o2sat" value="<?php echo htmlspecialchars($O2sat_form); ?>"></div> </div> <div class="row"> <div class="col-md-3 col-sm-6 mb-3"><label for="sbp" class="form-label">SBP (mmHg)</label><input type="number" min="0" class="form-control" name="sbp" id="sbp" value="<?php echo htmlspecialchars($SBP_form); ?>"></div> <div class="col-md-3 col-sm-6 mb-3"><label for="dbp" class="form-label">DBP (mmHg)</label><input type="number" min="0" class="form-control" name="dbp" id="dbp" value="<?php echo htmlspecialchars($DBP_form); ?>"></div> <div class="col-md-6 mb-3"><label for="pain" class="form-label">Pain <span class="text-danger">*</span></label><input type="text" class="form-control" name="pain" id="pain" value="<?php echo htmlspecialchars($Pain_form); ?>" placeholder="e.g. 5/10, Mild Ache" required></div> </div> <div class="row"> <div class="col-md-6 mb-3"><label for="acuity" class="form-label">Acuity <span class="text-danger">*</span></label><select class="form-select" name="acuity" id="acuity" required><option value="">Select Acuity</option><?php foreach($acuityOptions as $displayText => $storedValue): ?><option value="<?php echo $storedValue; ?>" <?php if($Acuity_form_val == $storedValue) echo "selected"; ?>><?php echo htmlspecialchars($displayText); ?></option><?php endforeach; ?></select></div> </div> </div> <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="submit" name="submit_triage" class="btn btn-primary" id="triageSubmitBtn"><i class="fas fa-save"></i> <span id="triageSubmitBtnText">Save Triage</span></button></div> </form> </div> </div> </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const loggedInStaffID_JS = <?php echo json_encode($loggedInStaffID_Session); ?>;

        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(function(alert) { if (bootstrap && bootstrap.Alert) { setTimeout(() => { bootstrap.Alert.getOrCreateInstance(alert)?.close(); }, 7000); } else { setTimeout(() => { alert.style.display = 'none'; }, 7000); } });
            resetToCheckInMode(); // Initialize form correctly on page load
        });

        function formatDateTimeForDisplay(dateTimeStr) { if (!dateTimeStr) return 'N/A'; const options = { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true }; try { return new Date(dateTimeStr).toLocaleString('en-US', options); } catch (e) { return dateTimeStr; } }

        function resetToCheckInMode() {
            const form = document.getElementById('edStayForm');
            form.reset();

            document.getElementById('formTitle').textContent = 'Patient Check-In';
            document.getElementById('stay_id_form_main').value = '';
            document.getElementById('is_checkout_action').value = '';

            document.getElementById('patient_id_fk_form').disabled = false;
            document.getElementById('patient_id_fk_form').value = "";


            const attendingStaffSelect = document.getElementById('staff_id_fk_form');
            const attendingStaffContainerCheckin = document.getElementById('attending_staff_container_checkin');
            const attendingStaffContainerCheckout = document.getElementById('attending_staff_container_checkout');

            attendingStaffContainerCheckin.classList.remove('form-field-hidden');
            attendingStaffContainerCheckout.classList.add('form-field-hidden');

            if (loggedInStaffID_JS) {
                attendingStaffSelect.value = loggedInStaffID_JS;
            } else {
                attendingStaffSelect.value = ""; // Fallback if somehow loggedInStaffID_JS is null
                console.warn("Logged-in Staff ID not available in JS for Attending Staff.");
            }
            attendingStaffSelect.disabled = true; // Auto-assigned and read-only for check-in
            attendingStaffSelect.required = true;


            document.getElementById('arrival_transport_container').classList.remove('form-field-hidden');
            const arrivalTransportSelect = document.getElementById('arrival_transport_edstay');
            arrivalTransportSelect.required = true;
            arrivalTransportSelect.value = "";

            if(document.getElementById('intime_form_container')) document.getElementById('intime_form_container').classList.add('form-field-hidden');
            
            const dispositionContainer = document.getElementById('disposition_container');
            const dispositionForm = document.getElementById('disposition_form');
            if(dispositionContainer) dispositionContainer.classList.add('form-field-hidden');
            if(dispositionForm) {
                dispositionForm.required = false;
                dispositionForm.value = "";
            }

            const submitBtn = document.getElementById('submitBtnEDStay');
            submitBtn.innerHTML = "<i class='fas fa-sign-in-alt me-1'></i> Check-In Patient";
            submitBtn.className = 'btn-submit btn btn-primary w-100';
            submitBtn.disabled = !loggedInStaffID_JS; // Disable if not logged in
            if (submitBtn.disabled) { submitBtn.title = "Cannot check-in: Staff not identified."; } else { submitBtn.title = ""; }

            form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
            form.querySelectorAll('.invalid-feedback').forEach(el => el.textContent = '');
        }

        function prepareCheckout(stay) {
            document.getElementById('edStayFormCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
            document.getElementById('formTitle').textContent = 'Patient Check-Out / Disposition';
            document.getElementById('stay_id_form_main').value = stay.StayID;
            document.getElementById('is_checkout_action').value = '1';

            const patientSelect = document.getElementById('patient_id_fk_form');
            const patientIdFromStay = stay.PatientID;

            if (!patientIdFromStay || String(patientIdFromStay).trim() === "") {
                alert("Error: Patient ID is missing or empty for this stay record. Cannot prepare checkout form.");
                resetToCheckInMode(); 
                return; 
            }
            patientSelect.value = patientIdFromStay; 
            let patientOptionExists = false;
            for (let i = 0; i < patientSelect.options.length; i++) {
                if (patientSelect.options[i].value === String(patientIdFromStay)) {
                    patientOptionExists = true;
                    break;
                }
            }
            if (!patientOptionExists) {
                alert(`Error: Patient ID '${patientIdFromStay}' (from stay data) was not found in the patient dropdown list. Please ensure patient data is consistent. Checkout cannot proceed.`);
                resetToCheckInMode(); 
                return; 
            }
            patientSelect.disabled = false;

            const attendingStaffSelectCheckin = document.getElementById('staff_id_fk_form');
            const attendingStaffContainerCheckin = document.getElementById('attending_staff_container_checkin');
            const attendingStaffContainerCheckout = document.getElementById('attending_staff_container_checkout');

            attendingStaffContainerCheckin.classList.add('form-field-hidden');
            attendingStaffSelectCheckin.required = false; 
            attendingStaffSelectCheckin.disabled = true; 

            attendingStaffContainerCheckout.classList.remove('form-field-hidden');
            const originalAttendingStaffName = stay.AttendingStaffName || 'N/A';
            const originalAttendingStaffID = stay.AttendingStaffID_edstays || 'N/A';
            document.getElementById('staff_id_fk_form_display_checkout').value = `${originalAttendingStaffName} (ID: ${originalAttendingStaffID})`;

            document.getElementById('arrival_transport_container').classList.add('form-field-hidden');
            document.getElementById('arrival_transport_edstay').required = false;

            if(document.getElementById('intime_form_container')) document.getElementById('intime_form_container').classList.remove('form-field-hidden');
            if(document.getElementById('intime_form_display')) document.getElementById('intime_form_display').value = formatDateTimeForDisplay(stay.Intime);

            if(document.getElementById('disposition_container')) {
                 document.getElementById('disposition_container').classList.remove('form-field-hidden');
                 document.getElementById('disposition_form').value = stay.Disposition || '';
                 document.getElementById('disposition_form').required = true;
                 document.getElementById('disposition_form').focus();
            }
            const submitBtn = document.getElementById('submitBtnEDStay');
            submitBtn.innerHTML = "<i class='fas fa-check-double me-1'></i> Confirm Disposition & Checkout";
            submitBtn.className = 'btn-submit btn btn-success w-100';
            submitBtn.disabled = !loggedInStaffID_JS; 
            if (submitBtn.disabled) { submitBtn.title = "Cannot checkout: Staff not identified."; } else { submitBtn.title = ""; }
        }

        function prepareTriageModal(buttonElement) {
            const stayDataString = buttonElement.getAttribute('data-stay-data');
            if (!stayDataString) { console.error("Missing stay data for triage modal"); return; }
            const stayData = JSON.parse(stayDataString);
            const stayId = stayData.StayID;
            const patientName = stayData.PatientName || 'N/A';
            const existingTriageId = stayData.TriageID || '';
            const hasTriage = existingTriageId && existingTriageId !== '';
            document.getElementById('triage_stay_id_hidden').value = stayId;
            document.getElementById('existing_triage_id').value = existingTriageId;
            const modalLabelSpan = document.getElementById('triagePatientName');
            const submitBtnText = document.getElementById('triageSubmitBtnText');
            const form = document.getElementById('triageForm');
            form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
            form.querySelectorAll('.invalid-feedback').forEach(el => el.textContent = '');
            const modalTitleElement = document.getElementById('triageModalLabel').childNodes[0];

            if (hasTriage) {
                if (modalTitleElement) modalTitleElement.nodeValue = 'View/Edit Triage Data for Patient: ';
                submitBtnText.textContent = "Update Triage Data";
                form.chief_complaint.value = stayData.ChiefComplaint || ''; form.temperature.value = stayData.Temperature || ''; form.heartrate.value = stayData.Heartrate || ''; form.resprate.value = stayData.Resprate || ''; form.o2sat.value = stayData.O2sat || ''; form.sbp.value = stayData.SBP || ''; form.dbp.value = stayData.DBP || ''; form.pain.value = stayData.Pain || ''; form.acuity.value = stayData.Acuity || '';
            } else {
                if (modalTitleElement) modalTitleElement.nodeValue = 'Add Triage Data for Patient: ';
                submitBtnText.textContent = "Save Triage Data";
                form.reset(); document.getElementById('triage_stay_id_hidden').value = stayId; document.getElementById('existing_triage_id').value = '';
            }
            if(modalLabelSpan) modalLabelSpan.textContent = patientName + " (Stay ID: " + stayId + ")";
        }
    </script>
</body>
</html>
<?php
if (isset($conn) && $conn instanceof mysqli && $conn->thread_id) {
    $conn->close();
}
?>