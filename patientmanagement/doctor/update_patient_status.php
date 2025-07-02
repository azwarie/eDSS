<?php
include 'db.php'; // Include database connection
include 'connection_azwarie.php'; // Include azwarie database connection

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve data from POST request
    $patient_id = $_POST['patient_id'];
    $status = $_POST['status'];

    if (!empty($patient_id) && !empty($status)) {
        // Update AppointmentStatus in my database
        $updateStatusQuery = "UPDATE appointments SET AppointmentStatus = ? WHERE PatientID = ?";
        $stmt = $conn->prepare($updateStatusQuery);
        $stmt->bind_param("ss", $status, $patient_id);

        if ($stmt->execute()) {
            // Update AppointmentStatus in azwarie database
            $updateStatusQueryAzwarie = "UPDATE appointments SET AppointmentStatus = ? WHERE PatientID = ?";
            $stmt_azwarie = $connect_azwarie->prepare($updateStatusQueryAzwarie);
            $stmt_azwarie->bind_param("ss", $status, $patient_id);

            if ($stmt_azwarie->execute()) {
                echo "<script>alert('Appointment status updated to $status successfully in both databases!'); window.location.href='assign_diagnosis.php';</script>";
            } else {
                 echo "<script>alert('Failed to update appointment status in azwarie database. Error: " . $stmt_azwarie->error . "');</script>";
            }
            $stmt_azwarie->close();

        } else {
            echo "<script>alert('Failed to update appointment status in primary database. Error: " . $stmt->error . "');</script>";
        }

        $stmt->close();
    } else {
        echo "<script>alert('Invalid data received. Please try again.');</script>";
    }
}

$conn->close();
$connect_azwarie->close();
?>