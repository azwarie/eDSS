<?php
session_start();
include 'connection.php'; // Defines $conn

// --- Database Connection Check ---
if (!$conn || $conn->connect_error) {
    die("Database connection failed: " . ($conn ? $conn->connect_error : 'Unknown error'));
}

// --- Timezone ---
date_default_timezone_set('Asia/Kuala_Lumpur');

// --- Staff ID Handling ---
$loggedInStaffID = $_SESSION['staffid'] ?? null;
$staffIDForLinks = $_GET['staffid'] ?? null; // staffid from URL for link consistency
$staffIdToUseInNavbarLinks = $staffIDForLinks ?? $loggedInStaffID; // Use URL if available, else session

if (empty($staffIdToUseInNavbarLinks)) { // Primarily for navbar links
    // If loggedInStaffID is also empty, then critical actions might be blocked later.
    // For now, ensure navbar can be built. A more robust check for $loggedInStaffID for actions is good.
    // die("Staff ID context is missing for navigation.");
}
if (empty($loggedInStaffID)) {
    // Redirect to login or show error if staff must be logged in to perform actions
    // For this page, assigning diagnosis requires a logged-in staff.
    die("You must be logged in to perform this action. Staff ID is missing from session.");
}
$current_page = basename($_SERVER['PHP_SELF']);

// --- Define Role Constants ---
// Ensure these are defined. If they are in a central 'constants.php' file, include that instead.
if (!defined('ROLE_DIAGNOSING_DOCTOR')) {
    define('ROLE_DIAGNOSING_DOCTOR', 'DiagnosingDoctor');
}
if (!defined('ROLE_ASSESSMENT_COMPLETER')) {
    define('ROLE_ASSESSMENT_COMPLETER', 'AssessmentCompleter');
}
// Add other roles if used on this page, e.g.,
// if (!defined('ROLE_DISPOSITION_STAFF')) define('ROLE_DISPOSITION_STAFF', 'DispositionStaff');


// --- Helper Functions ---
function addStaffIdToLink($url, $staffIdParam) {
    // Use staffIdParam directly as it's passed.
    if ($staffIdParam !== null && $staffIdParam !== '') {
        $separator = (strpos($url, '?') === false) ? '?' : '&';
        return $url . $separator . 'staffid=' . urlencode($staffIdParam);
    }
    return $url;
}

function logAuditTrail($conn_audit, $actorStaffID, $action, $description) {
    $conn_to_use = $conn_audit ?? $GLOBALS['conn'] ?? null;
    if (!$conn_to_use) return;
    try {
        $sql = "INSERT INTO AUDIT_TRAIL (StaffID, Action, Description, Timestamp) VALUES (?, ?, ?, NOW())";
        $stmt = $conn_to_use->prepare($sql);
        if ($stmt === false) {
            throw new Exception("Prepare audit: " . $conn_to_use->error);
        }
        $actingStaff = !empty($actorStaffID) ? $actorStaffID : 'SYSTEM';
        $stmt->bind_param('sss', $actingStaff, $action, $description);
        if (!$stmt->execute()) {
            throw new Exception("Exec audit: " . $stmt->error);
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Audit error: " . $e->getMessage());
    }
}

// --- Initialize Variables ---
$message = ""; // For general messages and errors
$form_errors = []; // For specific form validation errors
$currentEDStayDetails = null;
$selected_stay_id = $_GET['selected_stay_id'] ?? null; // From GET for initial load or selection change

// Update $selected_stay_id if it's coming from a POST action
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['selected_stay_id_from_action'])) { $selected_stay_id = $_POST['selected_stay_id_from_action']; }
    elseif (isset($_POST['stay_id_to_assign'])) { $selected_stay_id = $_POST['stay_id_to_assign']; }
    elseif (isset($_POST['stay_id_to_remove_from'])) { $selected_stay_id = $_POST['stay_id_to_remove_from']; }
    elseif (isset($_POST['stay_id_for_dx_process'])) { $selected_stay_id = $_POST['stay_id_for_dx_process']; }
    elseif (isset($_POST['stay_id_for_finish_dx'])) { $selected_stay_id = $_POST['stay_id_for_finish_dx']; }
    elseif (isset($_POST['stay_id_to_complete_stay'])) { $selected_stay_id = $_POST['stay_id_to_complete_stay']; }
}

// --- Fetch Active ED Stays (Eligible for diagnosis workflow) ---
$activeEDStays = [];
$fetch_error_stays = null;
try {
    $staysQuery = "SELECT es.StayID, p.PatientName, p.PatientID, es.Status AS EDStatus 
                   FROM edstays es 
                   JOIN patients p ON es.PatientID = p.PatientID 
                   WHERE es.Outime IS NULL AND 
                         (es.Status = 'Waiting for Doctor' OR es.Status = 'Doctor Assessment' OR es.Status = 'Awaiting Disposition') 
                   ORDER BY p.PatientName ASC, es.Intime DESC";
    $staysResult = $conn->query($staysQuery);
    if ($staysResult) {
        $activeEDStays = $staysResult->fetch_all(MYSQLI_ASSOC);
        $staysResult->free();
    } else {
        throw new Exception($conn->error);
    }
} catch (Exception $e) {
    $fetch_error_stays = "Error loading ED stays: " . $e->getMessage();
    error_log($fetch_error_stays);
}

// --- Fetch All ICD Diagnoses ---
$icdDiagnoses = [];
$fetch_error_icd = null;
try {
    $icdQuery = "SELECT ICD_Code, ICD_Title, ICD_version FROM diagnosis ORDER BY ICD_Title ASC";
    $icdResult = $conn->query($icdQuery);
    if ($icdResult) {
        $icdDiagnoses = $icdResult->fetch_all(MYSQLI_ASSOC);
        $icdResult->free();
    } else {
        throw new Exception($conn->error);
    }
} catch (Exception $e) {
    $fetch_error_icd = "Error loading ICD list: " . $e->getMessage();
    error_log($fetch_error_icd);
}

// --- Fetch Details AND Assigned Diagnoses for Selected Stay ---
$assignedDiagnosesForSelectedStay = [];
if ($selected_stay_id) {
    try {
        $stmt_current_stay = $conn->prepare("SELECT es.StayID, p.PatientName, es.Status AS EDStatus 
                                            FROM edstays es 
                                            JOIN patients p ON es.PatientID = p.PatientID 
                                            WHERE es.StayID = ? AND es.Outime IS NULL");
        if ($stmt_current_stay) {
            $stmt_current_stay->bind_param("s", $selected_stay_id);
            $stmt_current_stay->execute();
            $result_current_stay = $stmt_current_stay->get_result();
            $currentEDStayDetails = $result_current_stay->fetch_assoc();
            if($result_current_stay) $result_current_stay->free();
            $stmt_current_stay->close();
        } else {
            throw new Exception("Prepare statement for current stay details failed: " . $conn->error);
        }

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
            if($result_assigned) $result_assigned->free();
            $stmt_assigned->close();
        } else {
            throw new Exception("Prepare statement for assigned diagnoses failed: " . $conn->error);
        }
    } catch (Exception $e) {
        $message .= "<div class='alert alert-warning'>Error loading details for Stay ID " . htmlspecialchars($selected_stay_id) . ": " . htmlspecialchars($e->getMessage()) . "</div>";
        error_log("Error fetching details for StayID $selected_stay_id: " . $e->getMessage());
    }
}
$dispositionOptions = ["Admitted", "Home", "Transfer", "Other"]; // For disposition form

// --- Handle Form Actions ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Action: Start Diagnosis Process
    if (isset($_POST['start_diagnosis_process_action'])) {
        $stay_id_to_start_dx = trim($_POST['stay_id_for_dx_process'] ?? '');
        $selected_stay_id = $stay_id_to_start_dx; // Update selected_stay_id context

        if (empty($stay_id_to_start_dx)) {
            $form_errors[] = "No ED Stay selected to start diagnosis.";
        } else {
            $conn->begin_transaction();
            try {
                // Check current status
                $stmtCheck = $conn->prepare("SELECT Status FROM edstays WHERE StayID = ? AND Outime IS NULL");
                if (!$stmtCheck) throw new Exception("Prepare status check: " . $conn->error);
                $stmtCheck->bind_param("s", $stay_id_to_start_dx);
                $stmtCheck->execute();
                $resultCheck = $stmtCheck->get_result();
                $currentRow = $resultCheck->fetch_assoc();
                if($resultCheck) $resultCheck->free();
                $stmtCheck->close();

                if ($currentRow && $currentRow['Status'] === 'Waiting for Doctor') {
                    $updateStatusSql = "UPDATE edstays SET Status = 'Doctor Assessment' WHERE StayID = ?";
                    $stmtUpdate = $conn->prepare($updateStatusSql);
                    if (!$stmtUpdate) throw new Exception("Prepare update status (Start Dx): " . $conn->error);
                    $stmtUpdate->bind_param("s", $stay_id_to_start_dx);
                    if (!$stmtUpdate->execute()) throw new Exception("Execute update status (Start Dx): " . $stmtUpdate->error);

                    if ($stmtUpdate->affected_rows > 0) {
                        if (!$conn->commit()) throw new Exception("Commit Start Dx failed.");
                        logAuditTrail($conn, $loggedInStaffID, 'ED Status Update', "Diagnosis process started for StayID '{$stay_id_to_start_dx}'. Status changed to 'Doctor Assessment'.");
                        $_SESSION['form_message'] = "<div class='alert alert-info alert-dismissible fade show'>Diagnosis process started for Stay ID " . htmlspecialchars($stay_id_to_start_dx) . ".<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                    } else {
                        $conn->rollback(); // Rollback if no rows affected (e.g., status wasn't 'Waiting for Doctor')
                        $_SESSION['form_message'] = "<div class='alert alert-warning alert-dismissible fade show'>Could not start diagnosis. Status may have changed or was not 'Waiting for Doctor'.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                    }
                    $stmtUpdate->close();
                } elseif ($currentRow && $currentRow['Status'] === 'Doctor Assessment') {
                    $_SESSION['form_message'] = "<div class='alert alert-info alert-dismissible fade show'>Diagnosis process is already active for this stay.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                    if ($conn->inTransaction()) $conn->rollback(); // Should not be in transaction if just checking
                } else {
                    $_SESSION['form_message'] = "<div class='alert alert-warning alert-dismissible fade show'>Patient not in 'Waiting for Doctor' status. Current status: " . htmlspecialchars($currentRow['Status'] ?? 'Unknown') . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                    if ($conn->inTransaction()) $conn->rollback();
                }
                // Redirect to maintain clean URL and state
                header("Location: " . addStaffIdToLink(htmlspecialchars($_SERVER['PHP_SELF']), $staffIdToUseInNavbarLinks) . "&selected_stay_id=" . urlencode($stay_id_to_start_dx));
                exit();
            } catch (Exception $e) {
                if ($conn->inTransaction()) $conn->rollback();
                $message = "<div class='alert alert-danger'>Error starting diagnosis: " . htmlspecialchars($e->getMessage()) . "</div>"; // Display error on current page
                error_log("Start Diagnosis Process Error: " . $e->getMessage());
            }
        }
    }
    // Action: Assign ICD Diagnosis (USING STORED PROCEDURE)
    elseif (isset($_POST['assign_diagnosis_action'])) {
        $stay_id_to_assign = trim($_POST['stay_id_to_assign'] ?? '');
        $icd_code_to_assign = trim($_POST['icd_code_to_assign'] ?? '');
        $selected_stay_id = $stay_id_to_assign;

        // Basic validation in PHP before calling the database
        if (empty($stay_id_to_assign)) $form_errors[] = "ED Stay not specified for diagnosis assignment.";
        if (empty($icd_code_to_assign)) $form_errors[] = "Please select an ICD Diagnosis to assign.";
        
        if (empty($form_errors)) {
            try {
                // Prepare the CALL statement for the stored procedure
                $stmt = $conn->prepare("CALL AssignDiagnosisToStay(?, ?, ?, ?, @statusCode, @statusMessage, @newSeqNum)");
                if ($stmt === false) {
                    throw new Exception("Prepare failed (CALL AssignDiagnosisToStay): " . $conn->error);
                }

                // The role logic is still defined here in PHP, as you requested.
                $role_to_log = ROLE_DIAGNOSING_DOCTOR;

                // Bind the IN parameters
                $stmt->bind_param("ssss", $stay_id_to_assign, $icd_code_to_assign, $loggedInStaffID, $role_to_log);
                
                // Execute the procedure
                if (!$stmt->execute()) {
                    throw new Exception("Execute failed (CALL AssignDiagnosisToStay): " . $stmt->error);
                }
                $stmt->close();

                // Fetch the OUT parameters from the procedure
                $select_out = $conn->query("SELECT @statusCode AS code, @statusMessage AS msg, @newSeqNum AS seq");
                if (!$select_out) {
                    throw new Exception("Failed to retrieve OUT parameters: " . $conn->error);
                }
                $out_params = $select_out->fetch_assoc();
                $select_out->free();

                // Check the status code returned by the procedure
                if ($out_params['code'] == 0) { // Success
                    $new_seq_num = $out_params['seq'];
                    
                    // The audit trail log remains here in PHP
                    logAuditTrail($conn, $loggedInStaffID, 'ED Diagnosis Assign', "Assigned ICD '{$icd_code_to_assign}' (Seq: {$new_seq_num}) to StayID '{$stay_id_to_assign}'. Role '{$role_to_log}' noted.");
                    
                    // Use the success message from the procedure
                    $_SESSION['form_message'] = "<div class='alert alert-success alert-dismissible fade show'>".htmlspecialchars($out_params['msg'])."<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                    header("Location: " . addStaffIdToLink(htmlspecialchars($_SERVER['PHP_SELF']), $staffIdToUseInNavbarLinks) . "&selected_stay_id=" . urlencode($stay_id_to_assign));
                    exit();
                } else { // The procedure handled an error (e.g., wrong status)
                    $form_errors[] = $out_params['msg'];
                }

            } catch (Exception $e) { 
                // This catches fundamental errors like the procedure call failing
                $message = "<div class='alert alert-danger'>Error assigning diagnosis: " . htmlspecialchars($e->getMessage()) . "</div>"; 
                error_log("ED Diagnosis Assign Procedure Call Error: " . $e->getMessage());
            }
        }
    }
     // Action: Remove Assigned Diagnosis
    elseif (isset($_POST['remove_diagnosis_action'])) {
        $stay_id_to_remove_from = trim($_POST['stay_id_to_remove_from'] ?? '');
        $icd_code_to_remove = trim($_POST['icd_code_to_remove'] ?? '');
        $seq_num_to_remove = trim($_POST['seq_num_to_remove'] ?? '');
        $selected_stay_id = $stay_id_to_remove_from;

        if (empty($stay_id_to_remove_from) || empty($icd_code_to_remove) || !is_numeric($seq_num_to_remove)) {
            $message = "<div class='alert alert-danger'>Missing data for diagnosis removal.</div>";
        } else {
            $conn->begin_transaction();
            try {
                $deleteQuery = "DELETE FROM edstays_diagnosis WHERE StayID = ? AND ICD_Code = ? AND SeqNum = ?";
                $stmt_delete = $conn->prepare($deleteQuery);
                if (!$stmt_delete) throw new Exception("Prepare Delete Diagnosis failed: " . $conn->error);
                $stmt_delete->bind_param("ssi", $stay_id_to_remove_from, $icd_code_to_remove, $seq_num_to_remove);
                if (!$stmt_delete->execute()) throw new Exception("Execute Delete Diagnosis failed: " . $stmt_delete->error);

                if ($stmt_delete->affected_rows > 0) {
                    // Also remove the corresponding staff role entry if it was specific to this DiagnosisSeqNum
                    $sql_delete_role = "DELETE FROM edstay_staff_roles WHERE StayID = ? AND DiagnosisSeqNum = ? AND Role = ?";
                    $stmt_delete_role = $conn->prepare($sql_delete_role);
                    if ($stmt_delete_role) {
                        $diagnosingRole = ROLE_DIAGNOSING_DOCTOR; // Assuming this was the role logged
                        $stmt_delete_role->bind_param("sis", $stay_id_to_remove_from, $seq_num_to_remove, $diagnosingRole);
                        if (!$stmt_delete_role->execute()) {
                            error_log("Could not delete corresponding staff role for StayID $stay_id_to_remove_from, SeqNum $seq_num_to_remove: " . $stmt_delete_role->error);
                            // Non-critical, so don't throw exception that rolls back main delete
                        }
                        $stmt_delete_role->close();
                    } else {
                         error_log("Prepare statement failed for deleting staff role: " . $conn->error);
                    }


                    if (!$conn->commit()) throw new Exception("Commit failed (Remove Diagnosis).");
                    logAuditTrail($conn, $loggedInStaffID, 'ED Diagnosis Remove', "Removed ICD '{$icd_code_to_remove}' (Seq: {$seq_num_to_remove}) from StayID '{$stay_id_to_remove_from}'.");
                    $_SESSION['form_message'] = "<div class='alert alert-success alert-dismissible fade show'>Diagnosis removed successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                } else {
                    $conn->rollback();
                    $_SESSION['form_message'] = "<div class='alert alert-warning alert-dismissible fade show'>Diagnosis not found or already removed.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                }
                $stmt_delete->close();
                header("Location: " . addStaffIdToLink(htmlspecialchars($_SERVER['PHP_SELF']), $staffIdToUseInNavbarLinks) . "&selected_stay_id=" . urlencode($stay_id_to_remove_from));
                exit();
            } catch (Exception $e) {
                if($conn->inTransaction()) $conn->rollback();
                $message = "<div class='alert alert-danger'>Error removing diagnosis: " . htmlspecialchars($e->getMessage()) . "</div>";
                error_log("ED Diagnosis Remove Error: " . $e->getMessage());
            }
        }
    }
    // Action: Finish Diagnosis and Move to Awaiting Disposition
    elseif (isset($_POST['finish_diagnosis_action'])) {
        $stay_id_to_finish_dx_phase = trim($_POST['stay_id_for_finish_dx'] ?? '');
        $selected_stay_id = $stay_id_to_finish_dx_phase;

        if (empty($stay_id_to_finish_dx_phase)) {
            $form_errors[] = "No ED Stay selected to finish diagnosis phase.";
        } else {
            // Check if at least one diagnosis is assigned
            $checkDxCountStmt = $conn->prepare("SELECT COUNT(*) as dx_count FROM edstays_diagnosis WHERE StayID = ?"); 
            if (!$checkDxCountStmt) { $form_errors[]="DB error preparing diagnosis count."; error_log("Prepare Dx Count: " . $conn->error); }
            else {
                $checkDxCountStmt->bind_param("s", $stay_id_to_finish_dx_phase);
                $checkDxCountStmt->execute();
                $dxCountResult = $checkDxCountStmt->get_result();
                $dxCountRow = $dxCountResult->fetch_assoc(); 
                if($dxCountResult) $dxCountResult->free();
                $checkDxCountStmt->close();
                if (!$dxCountRow || $dxCountRow['dx_count'] == 0) {
                    $form_errors[] = "Please assign at least one diagnosis before finishing this phase.";
                }
            }
        }
        
        if (empty($form_errors)) {
            $conn->begin_transaction(); 
            try {
                $updateStatusSql = "UPDATE edstays SET Status = 'Awaiting Disposition' WHERE StayID = ? AND Outime IS NULL AND Status = 'Doctor Assessment'"; 
                $stmtUpdate = $conn->prepare($updateStatusSql); 
                if (!$stmtUpdate) throw new Exception("Prepare Finish Dx Phase failed: " . $conn->error); 
                $stmtUpdate->bind_param("s", $stay_id_to_finish_dx_phase); 
                if (!$stmtUpdate->execute()) throw new Exception("Execute Finish Dx Phase failed: " . $stmtUpdate->error);
                
                if ($stmtUpdate->affected_rows > 0) {
                    if (!empty($loggedInStaffID)) { 
                        $role_assessment_completer = ROLE_ASSESSMENT_COMPLETER;
                        $sql_log_assessment_role = "INSERT INTO edstay_staff_roles (StayID, StaffID, Role, ActivityTimestamp) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE ActivityTimestamp=NOW()";
                        // No DiagnosisSeqNum here as this role is for the phase completion, not a specific diagnosis line item
                        $stmt_log_assessment_role = $conn->prepare($sql_log_assessment_role);
                        if ($stmt_log_assessment_role) {
                            $stmt_log_assessment_role->bind_param("sss", $stay_id_to_finish_dx_phase, $loggedInStaffID, $role_assessment_completer);
                            if (!$stmt_log_assessment_role->execute()) { error_log("Failed to log role '" . $role_assessment_completer . "' for StayID " . $stay_id_to_finish_dx_phase . " by Staff " . $loggedInStaffID . ": " . $stmt_log_assessment_role->error); }
                            $stmt_log_assessment_role->close();
                        } else { error_log("Prepare statement failed for logging role '" . $role_assessment_completer . "': " . $conn->error); }
                    }

                    if (!$conn->commit()) throw new Exception("Commit Finish Dx Phase failed."); 
                    logAuditTrail($conn, $loggedInStaffID, 'ED Status Update', "Diagnosis phase finished for StayID '{$stay_id_to_finish_dx_phase}'. Status changed to 'Awaiting Disposition'. Role '".ROLE_ASSESSMENT_COMPLETER."' noted for Staff {$loggedInStaffID}."); 
                    $_SESSION['form_message'] = "<div class='alert alert-info alert-dismissible fade show'>Diagnosis phase finished for Stay ID " . htmlspecialchars($stay_id_to_finish_dx_phase) . ". Patient is now Awaiting Disposition.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>"; 
                } else { 
                    $conn->rollback(); 
                    $_SESSION['form_message'] = "<div class='alert alert-warning alert-dismissible fade show'>Could not finish diagnosis phase. Stay may not be in 'Doctor Assessment' status, already processed, or another issue occurred.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>"; 
                } 
                $stmtUpdate->close();
                header("Location: " . addStaffIdToLink(htmlspecialchars($_SERVER['PHP_SELF']), $staffIdToUseInNavbarLinks) . "&selected_stay_id=" . urlencode($stay_id_to_finish_dx_phase));
                exit();
            } catch (Exception $e) { 
                if ($conn->inTransaction()) $conn->rollback(); 
                $message = "<div class='alert alert-danger'>Error finishing diagnosis phase: " . htmlspecialchars($e->getMessage()) . "</div>"; 
                error_log("Finish Diagnosis Phase Error: " . $e->getMessage()); 
            }
        }
    }
    // Action: Confirm Disposition & Complete ED Stay
    elseif (isset($_POST['confirm_disposition_and_complete_action'])) {
        $stay_id_to_complete = trim($_POST['stay_id_to_complete_stay'] ?? '');
        $disposition_choice = trim($_POST['disposition_choice'] ?? '');
        $other_disposition_text = trim($_POST['other_disposition_text'] ?? '');
        $selected_stay_id = $stay_id_to_complete;

        $final_disposition_value = $disposition_choice;
        if ($disposition_choice === 'Other') {
            $final_disposition_value = !empty($other_disposition_text) ? "Other: " . $other_disposition_text : "Other (Not Specified)";
        }

        if (empty($stay_id_to_complete)) $form_errors[] = "ED Stay ID missing for finalization.";
        if (empty($disposition_choice)) $form_errors[] = "Please select a disposition option.";
        if ($disposition_choice === 'Other' && empty($other_disposition_text)) $form_errors[] = "Please specify details for 'Other' disposition.";
        
        // Check current status
        $statusCheckStmt = $conn->prepare("SELECT Status FROM edstays WHERE StayID = ? AND Outime IS NULL");
        if($statusCheckStmt){
            $statusCheckStmt->bind_param("s", $stay_id_to_complete);
            $statusCheckStmt->execute();
            $statusResult = $statusCheckStmt->get_result();
            $currentStayStatusRow = $statusResult->fetch_assoc();
            if($statusResult) $statusResult->free();
            $statusCheckStmt->close();
            if (!$currentStayStatusRow || $currentStayStatusRow['Status'] !== 'Awaiting Disposition') {
                $form_errors[] = "Cannot complete stay. Patient not in 'Awaiting Disposition' status. Current status: ".htmlspecialchars($currentStayStatusRow['Status'] ?? 'Unknown');
            }
        } else {
            $form_errors[] = "Database error checking stay status for finalization.";
            error_log("Prepare statement failed for status check (Finalize Stay): " . $conn->error);
        }
        
        if (empty($form_errors)) {
            $conn->begin_transaction();
            try {
                $dbOutime = date('Y-m-d H:i:s');
                $finalStatus = 'Completed';
                $updateSql = "UPDATE edstays SET Outime = ?, Disposition = ?, Status = ? WHERE StayID = ? AND Outime IS NULL AND Status = 'Awaiting Disposition'";
                $stmtUpdate = $conn->prepare($updateSql);
                if (!$stmtUpdate) throw new Exception("Prepare Finalize Stay failed: " . $conn->error);
                $stmtUpdate->bind_param("ssss", $dbOutime, $final_disposition_value, $finalStatus, $stay_id_to_complete);
                if (!$stmtUpdate->execute()) throw new Exception("Execute Finalize Stay failed: " . $stmtUpdate->error);

                if ($stmtUpdate->affected_rows > 0) {
                    // You might log a 'DispositionStaff' or 'FinalizingStaff' role here if distinct from CheckoutStaff
                    // Example: $role_finalizer = 'DispositionStaff';
                    // $sql_log_final_role = "INSERT INTO edstay_staff_roles (StayID, StaffID, Role, ActivityTimestamp) VALUES (?, ?, ?, NOW()) ...";
                    // ...

                    if (!$conn->commit()) throw new Exception("Commit Finalize Stay failed."); 
                    logAuditTrail($conn, $loggedInStaffID, 'ED Stay Finalized', "Finalized StayID '{$stay_id_to_complete}'. Disposition: '{$final_disposition_value}'. Status changed to '{$finalStatus}'."); 
                    $_SESSION['form_message'] = "<div class='alert alert-success alert-dismissible fade show'>ED Stay for " . htmlspecialchars($stay_id_to_complete) . " finalized successfully. Disposition: " . htmlspecialchars($final_disposition_value) . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>"; 
                    header("Location: " . addStaffIdToLink(htmlspecialchars($_SERVER['PHP_SELF']), $staffIdToUseInNavbarLinks)); // Redirect to page without selected_stay_id
                    exit(); 
                } else { 
                    $conn->rollback(); 
                    $_SESSION['form_message'] = "<div class='alert alert-warning alert-dismissible fade show'>Could not finalize stay. It may have already been processed or was not in 'Awaiting Disposition' status.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>"; 
                } 
                $stmtUpdate->close();
                 header("Location: " . addStaffIdToLink(htmlspecialchars($_SERVER['PHP_SELF']), $staffIdToUseInNavbarLinks) . "&selected_stay_id=" . urlencode($stay_id_to_complete)); // Redirect back to selected stay on failure to update
                exit();
            } catch (Exception $e) {
                if ($conn->inTransaction()) $conn->rollback();
                $message = "<div class='alert alert-danger'>Error finalizing ED stay: " . htmlspecialchars($e->getMessage()) . "</div>";
                error_log("Finalize ED Stay Error: " . $e->getMessage());
            }
        }
    }

    // If there were form errors specific to an action, display them
    if (!empty($form_errors) && (empty($message) || strpos($message, 'alert-danger') === false) ) {
        $message = "<div class='alert alert-danger alert-dismissible fade show'><strong>Action failed:</strong><ul class='mb-0'>";
        foreach ($form_errors as $error) { $message .= "<li>" . htmlspecialchars($error) . "</li>"; }
        $message .= "</ul><button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    }
}

// Retrieve session message if set by a redirect
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
    <title>ED Diagnosis & Disposition - WeCare</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        :root { --primary-color: #007bff; --background-color: #f8f9fa;}
        body { font-family: 'Poppins', sans-serif; padding-top: 70px; background-color: var(--background-color); }
        .navbar { position: sticky; top: 0; z-index: 1030; background-color: white !important; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05); padding: 0.5rem 1rem; }
        .navbar-brand { font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 700; color: var(--primary-color) !important; }
        .navbar .nav-link { color: #495057 !important; font-weight: 500; transition: color 0.2s ease; padding: 0.5rem 1rem; }
        .navbar .nav-link:hover, .navbar .nav-item.active .nav-link { color: var(--primary-color) !important; }
        .navbar .nav-item.active .nav-link { font-weight: 700 !important; }
        .hero-section { background-color: var(--primary-color); color: white; padding: 40px 0; text-align: center; margin-bottom: 30px; }
        .hero-section h1 {font-family: 'Montserrat', sans-serif; font-size: 2.5rem;}
        .card { border: none; border-radius: .5rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,.075); margin-bottom: 1.5rem; }
        .card-header { background-color: #e9ecef; font-weight: 600; font-size: 1.1rem; }
        .form-label { font-weight: 600; font-size: 0.9rem; }
        .btn-submit { background: linear-gradient(to right, #005c99, #007bff); color: white; border: none; font-weight: 500; }
        .btn-submit:hover { background: linear-gradient(to right, #004080, #0056b3); }
        .btn-danger-soft { background-color: #ffe1e6; color: #dc3545; border: 1px solid #f8d7da; font-size: 0.8rem; padding: 0.2rem 0.4rem;}
        .btn-danger-soft:hover { background-color: #f5c6cb; }
        .table th, .table td { vertical-align: middle; font-size: 0.9rem; }
        .sticky-selection { position: sticky; top: 60px; /* Adjust based on actual navbar height if navbar is fixed */ z-index: 100; background-color: var(--background-color); padding-top:15px; padding-bottom: 1px; margin-bottom: 0px; border-bottom: 1px solid #dee2e6;}
        .action-section { border: 1px solid #dee2e6; border-radius: .5rem; padding: 1.5rem; margin-top: 1.5rem; background-color: #fff;}
        .alert ul { padding-left: 1rem; margin-bottom: 0; }
         /* Styles for absolute positioned navbar brand */
        .navbar-brand[style*="position: absolute"] { z-index: 1031; /* Ensure brand is above other navbar content */ }
        @media (max-width: 991.98px) {
            .navbar-brand[style*="position: absolute"] {
                position: relative !important; top: auto !important; left: auto !important;
                margin-right: auto; /* Help align toggler */
            }
             .navbar-toggler { margin-left: 0 !important; /* Reset margin for toggler */ }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light fixed-top" style="background-color: white; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
    <div class="container">
        <a class="navbar-brand" href="<?php echo addStaffIdToLink('https://localhost/dss/staff/admin/AdminLandingPage.php', $staffIdToUseInNavbarLinks); ?>" style="font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 700; color: #007bff; position: absolute; top: 10px; left: 15px;">eDSS</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item <?php echo ($current_page == 'index.php' || $current_page == 'AdminLandingPage.php') ? 'active' : ''; ?>">
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
    <div class="hero-section"><h1>ED Diagnosis & Disposition</h1></div>

    <div class="container mt-4 mb-5">
        <div id="messageArea" style="min-height: 60px;">
            <?php if (!empty($message)) echo $message; ?>
            <?php if ($fetch_error_stays || $fetch_error_icd): ?>
                 <div class="alert alert-warning alert-dismissible fade show"><i class="fas fa-exclamation-circle me-2"></i> Could not load all required data for page. Please refresh or check connections.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>
            <?php endif; ?>
        </div>

        <div class="sticky-selection">
            <div class="card">
                <div class="card-header"><i class="fas fa-user-injured me-2"></i>Select Patient / ED Stay</div>
                <div class="card-body">
                    <form method="GET" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="selectStayForm">
                        <input type="hidden" name="staffid" value="<?php echo htmlspecialchars($staffIdToUseInNavbarLinks); ?>">
                        <div class="col-md-12">
                            <label for="selected_stay_id_dropdown" class="form-label">Active ED Stay (Eligible for Diagnosis Actions) <span class="text-danger">*</span></label>
                            <select name="selected_stay_id" id="selected_stay_id_dropdown" class="form-select" required onchange="this.form.submit()">
                                <option value="">-- Select ED Stay --</option>
                                <?php foreach ($activeEDStays as $stay): ?>
                                    <option value="<?php echo htmlspecialchars($stay['StayID']); ?>" <?php if ($selected_stay_id == $stay['StayID']) echo "selected"; ?>>
                                        <?php echo htmlspecialchars($stay['PatientName']) . " (Stay ID: " . htmlspecialchars($stay['StayID']) . " - Status: " . htmlspecialchars($stay['EDStatus'] ?? 'N/A') . ")"; ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if(empty($activeEDStays) && !$fetch_error_stays): ?> <option value="" disabled>No patients currently eligible for diagnosis actions.</option> <?php endif; ?>
                                <?php if($fetch_error_stays): ?> <option value="" disabled>Error loading patient stays.</option> <?php endif; ?>
                            </select>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php if ($selected_stay_id && $currentEDStayDetails): ?>
            <?php
                $patientNameForDisplay = htmlspecialchars($currentEDStayDetails['PatientName'] ?? 'Selected Patient');
                $currentStatusForDisplay = htmlspecialchars($currentEDStayDetails['EDStatus'] ?? 'N/A');
            ?>
            <div class="action-section" id="diagnosisActionSection">
                <h5>Actions for: <?php echo $patientNameForDisplay; ?> (Stay: <?php echo htmlspecialchars($selected_stay_id); ?>) <br>Current Status: <span class="badge bg-primary fs-6"><?php echo $currentStatusForDisplay; ?></span></h5>
                <hr>

                <?php if ($currentStatusForDisplay == 'Waiting for Doctor'): ?>
                    <form method="POST" class="mb-3" action="<?php echo addStaffIdToLink(htmlspecialchars($_SERVER['PHP_SELF']), $staffIdToUseInNavbarLinks) . '&selected_stay_id=' . urlencode($selected_stay_id); ?>">
                        <input type="hidden" name="start_diagnosis_process_action" value="1">
                        <input type="hidden" name="stay_id_for_dx_process" value="<?php echo htmlspecialchars($selected_stay_id); ?>">
                        <input type="hidden" name="selected_stay_id_from_action" value="<?php echo htmlspecialchars($selected_stay_id); ?>"> 
                        <button type="submit" class="btn btn-info w-100"><i class="fas fa-play-circle me-1"></i> Start Diagnosis Process</button>
                    </form>
                <?php endif; ?>

                <?php if ($currentStatusForDisplay == 'Doctor Assessment'): ?>
                    <div class="card mb-3">
                        <div class="card-header bg-light"><i class="fas fa-plus-circle me-2"></i>Add New Diagnosis Code</div>
                        <div class="card-body">
                            <form method="POST" action="<?php echo addStaffIdToLink(htmlspecialchars($_SERVER['PHP_SELF']), $staffIdToUseInNavbarLinks) . '&selected_stay_id=' . urlencode($selected_stay_id); ?>" id="assignDiagnosisFormActual">
                                <input type="hidden" name="assign_diagnosis_action" value="1">
                                <input type="hidden" name="stay_id_to_assign" value="<?php echo htmlspecialchars($selected_stay_id); ?>">
                                <input type="hidden" name="selected_stay_id_from_action" value="<?php echo htmlspecialchars($selected_stay_id); ?>">
                                <div class="mb-3">
                                    <label for="icd_code_to_assign" class="form-label">Select ICD Diagnosis <span class="text-danger">*</span></label>
                                    <select name="icd_code_to_assign" id="icd_code_to_assign" class="form-select" required>
                                        <option value="">-- Select Diagnosis --</option>
                                        <?php if (!empty($icdDiagnoses)): ?>
                                            <?php foreach ($icdDiagnoses as $diag): ?> <option value="<?php echo htmlspecialchars($diag['ICD_Code']); ?>"><?php echo htmlspecialchars($diag['ICD_Title']) . " (" . htmlspecialchars($diag['ICD_Code']) . " - v" . htmlspecialchars($diag['ICD_version']) .")"; ?></option> <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="" disabled>Error loading diagnoses.</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <button type="submit" class="btn-submit btn btn-primary w-100"><i class="fas fa-check-circle me-1"></i> Add This Diagnosis</button>
                            </form>
                        </div>
                    </div>

                    <div class="card mb-3">
                        <div class="card-header bg-light"><i class="fas fa-list-ul me-2"></i>Currently Assigned Diagnoses</div>
                        <div class="card-body p-0">
                            <?php if (!empty($assignedDiagnosesForSelectedStay)): ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0">
                                    <thead><tr><th>Seq#</th><th>ICD Code</th><th>Title</th><th>Ver.</th><th class="text-center">Action</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($assignedDiagnosesForSelectedStay as $ad): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($ad['SeqNum']); ?></td><td><?php echo htmlspecialchars($ad['ICD_Code']); ?></td>
                                            <td><?php echo htmlspecialchars($ad['ICD_Title']); ?></td><td><?php echo htmlspecialchars($ad['ICD_version']); ?></td>
                                            <td class="text-center">
                                                <form method="POST" action="<?php echo addStaffIdToLink(htmlspecialchars($_SERVER['PHP_SELF']), $staffIdToUseInNavbarLinks) . '&selected_stay_id=' . urlencode($selected_stay_id); ?>" onsubmit="return confirm('Are you sure you want to remove this diagnosis?');">
                                                    <input type="hidden" name="remove_diagnosis_action" value="1">
                                                    <input type="hidden" name="stay_id_to_remove_from" value="<?php echo htmlspecialchars($ad['StayID']); ?>">
                                                    <input type="hidden" name="icd_code_to_remove" value="<?php echo htmlspecialchars($ad['ICD_Code']); ?>">
                                                    <input type="hidden" name="seq_num_to_remove" value="<?php echo htmlspecialchars($ad['SeqNum']); ?>">
                                                    <input type="hidden" name="selected_stay_id_from_action" value="<?php echo htmlspecialchars($selected_stay_id); ?>">
                                                    <button type="submit" class="btn btn-danger-soft btn-sm" title="Remove Diagnosis"><i class="fas fa-trash-alt"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?> <p class="text-muted p-3 text-center">No diagnoses assigned yet for this ED stay.</p> <?php endif; ?>
                        </div>
                    </div>
                    <form method="POST" class="mt-3" action="<?php echo addStaffIdToLink(htmlspecialchars($_SERVER['PHP_SELF']), $staffIdToUseInNavbarLinks) . '&selected_stay_id=' . urlencode($selected_stay_id); ?>">
                        <input type="hidden" name="finish_diagnosis_action" value="1">
                        <input type="hidden" name="stay_id_for_finish_dx" value="<?php echo htmlspecialchars($selected_stay_id); ?>">
                        <input type="hidden" name="selected_stay_id_from_action" value="<?php echo htmlspecialchars($selected_stay_id); ?>">
                        <button type="submit" class="btn btn-success w-100" <?php if(empty($assignedDiagnosesForSelectedStay)) echo "disabled title='Assign at least one diagnosis first to enable this step.'"; ?>>
                            <i class="fas fa-check-circle me-1"></i> Finalize Diagnoses & Proceed to Disposition
                        </button>
                         <?php if(empty($assignedDiagnosesForSelectedStay)): ?> <small class="form-text text-muted d-block text-center mt-1">Assign at least one diagnosis to enable this step.</small> <?php endif; ?>
                    </form>
                <?php endif; ?>

                <?php if ($currentStatusForDisplay == 'Awaiting Disposition'): ?>
                    <hr class="my-4">
                    <h5>Finalize ED Stay & Disposition</h5>
                    <form method="POST" action="<?php echo addStaffIdToLink(htmlspecialchars($_SERVER['PHP_SELF']), $staffIdToUseInNavbarLinks) . '&selected_stay_id=' . urlencode($selected_stay_id); ?>" id="finalizeStayForm">
                        <input type="hidden" name="confirm_disposition_and_complete_action" value="1">
                        <input type="hidden" name="stay_id_to_complete_stay" value="<?php echo htmlspecialchars($selected_stay_id); ?>">
                        <input type="hidden" name="selected_stay_id_from_action" value="<?php echo htmlspecialchars($selected_stay_id); ?>">
                        <div class="mb-3">
                            <label for="disposition_choice" class="form-label">Select Disposition <span class="text-danger">*</span></label>
                            <select name="disposition_choice" id="disposition_choice" class="form-select" required onchange="toggleOtherDispositionInput(this.value)">
                                <option value="">-- Select Disposition --</option>
                                <?php foreach ($dispositionOptions as $option): ?> <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option> <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3" id="other_disposition_container" style="display: none;">
                            <label for="other_disposition_text" class="form-label">Specify 'Other' Disposition</label>
                            <input type="text" name="other_disposition_text" id="other_disposition_text" class="form-control">
                        </div>
                        <button type="submit" class="btn btn-success w-100"><i class="fas fa-check-double me-1"></i> Confirm Disposition & Complete ED Stay</button>
                    </form>
                <?php endif; ?>

                <?php if(!in_array($currentStatusForDisplay, ['Waiting for Doctor', 'Doctor Assessment', 'Awaiting Disposition']) && $selected_stay_id && $currentStatusForDisplay !== 'Completed'): ?>
                     <p class="text-info mt-3">This patient is currently in status '<?php echo $currentStatusForDisplay; ?>'. Eligible statuses for this page are 'Waiting for Doctor', 'Doctor Assessment', or 'Awaiting Disposition'.</p>
                <?php elseif($currentStatusForDisplay === 'Completed'): ?>
                    <p class="text-success mt-3 fw-bold"><i class="fas fa-check-circle"></i> This ED stay has been completed.</p>
                <?php endif; ?>

            </div>
        <?php elseif($selected_stay_id && !$currentEDStayDetails): ?>
            <p class="text-danger mt-3">Could not load details for selected Stay ID: <?php echo htmlspecialchars($selected_stay_id); ?>. It might no longer be active or an error occurred.</p>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(function(alert) { if (bootstrap && bootstrap.Alert) { setTimeout(() => { bootstrap.Alert.getOrCreateInstance(alert)?.close(); }, 7000); } else { setTimeout(() => { alert.style.display = 'none'; }, 7000); } });
            
            // Initialize 'Other' disposition input based on current selection (if any, e.g. after form error)
            const initialDispositionSelect = document.getElementById('disposition_choice');
            if (initialDispositionSelect) {
                toggleOtherDispositionInput(initialDispositionSelect.value);
            }
        });

        function toggleOtherDispositionInput(selectedValue) {
            const otherContainer = document.getElementById('other_disposition_container');
            const otherText = document.getElementById('other_disposition_text');
            if (!otherContainer || !otherText) return;

            if (selectedValue === 'Other') {
                otherContainer.style.display = 'block';
                otherText.required = true;
            } else {
                otherContainer.style.display = 'none';
                otherText.value = ''; // Clear value if not 'Other'
                otherText.required = false;
            }
        }
    </script>
</body>
</html>
<?php
if (isset($conn) && $conn instanceof mysqli && $conn->thread_id) {
    $conn->close();
}
?>