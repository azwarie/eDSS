<?php
// get_reason_for_admission.php
header('Content-Type: application/json'); // Set correct header for JSON response

// Include the SINGLE database connection ($conn to azwarie_dss)
// Ensure connection.php defines $conn correctly
include 'connection.php';

// Initialize the response array
$response = ['success' => false, 'reason' => null, 'error' => null];

// Check if the required patient_id parameter is set in the GET request
if (!isset($_GET['patient_id'])) {
    $response['error'] = 'Patient ID not provided.';
    echo json_encode($response);
    // Close connection if it was opened
    if (isset($conn) && $conn instanceof mysqli) { $conn->close(); }
    exit; // Stop script execution
}

$patient_id = $_GET['patient_id']; // Get the patient ID from the request

// --- Prepare SQL Query ---
// Assumes 'patients' and 'DIAGNOSIS' tables are in the database $conn connects to (azwarie_dss)
// Removed the `123.` database prefixes
$sql = "SELECT d.DiagnosisName
        FROM patients p
        JOIN diagnosis d ON p.DiagnosisID = d.DiagnosisID
        WHERE p.PatientID = ?";

// Use the single connection variable $conn
$stmt = $conn->prepare($sql);

// --- Check if statement preparation was successful ---
if (!$stmt) {
    $response['error'] = 'Database query preparation failed.';
    // Log the actual database error for debugging, don't expose to client
    error_log("Prepare failed in get_reason_for_admission.php: " . $conn->error);
    echo json_encode($response);
    if (isset($conn) && $conn instanceof mysqli) { $conn->close(); }
    exit;
}

// --- Bind the patient_id parameter ---
$stmt->bind_param("s", $patient_id);

// --- Execute the prepared statement ---
$stmt->execute();

// --- Get the result ---
$result = $stmt->get_result();

// --- Process the result ---
if ($result && $row = $result->fetch_assoc()) {
    // Diagnosis found
    $response['success'] = true;
    $response['reason'] = $row['DiagnosisName']; // Assign the diagnosis name
} else {
    // No diagnosis found or an error occurred after prepare/execute
    $response['error'] = 'No diagnosis record found for this patient.';
    // You could check $stmt->error here for more specific execution errors if needed
}

// --- Clean up resources ---
if (isset($result) && $result instanceof mysqli_result) {
    $result->free(); // Free the result set memory
}
$stmt->close(); // Close the prepared statement
$conn->close(); // Close the database connection

// --- Send the JSON response back to the JavaScript ---
echo json_encode($response);
exit; // Ensure no further output
?>