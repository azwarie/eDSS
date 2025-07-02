<?php
// Include your database connection
include 'connection.php';

// Check for a successful connection
if (!$conn || $conn->connect_error) {
    die("Database connection failed: " . ($conn ? $conn->connect_error : 'Unknown error'));
}

echo "<h1>Updating Passwords to Secure Hashes</h1>";

// Array of all your users with their plain-text passwords
$users = [
    ['id' => 'A00001', 'pass' => 'azwarie123'],
    ['id' => 'A00002', 'pass' => 'ali123'],
    ['id' => 'D00001', 'pass' => '@nemo123'],
    ['id' => 'D00002', 'pass' => 'nora123'],
    ['id' => 'D00003', 'pass' => 'pass123'],
    ['id' => 'D00004', 'pass' => 'pharma123'],
    ['id' => 'D00005', 'pass' => 'doc123'],
    ['id' => 'D00006', 'pass' => 'nurse456'],
    ['id' => 'D00007', 'pass' => 'medassistant123'],
    ['id' => 'D00008', 'pass' => 'badrul123'],
    ['id' => 'D00009', 'pass' => 'assist123'],
    ['id' => 'D00010', 'pass' => 'azman123'],
    ['id' => 'D00011', 'pass' => 'pharm765'],
    ['id' => 'D00012', 'pass' => 'assist098'],
    ['id' => 'D00013', 'pass' => 'pharm234'],
    ['id' => 'D00014', 'pass' => 'doc098'],
    ['id' => 'D00015', 'pass' => 'assist678'],
    ['id' => 'D00016', 'pass' => 'meddoc123'],
    ['id' => 'D00017', 'pass' => 'nursedoc456'],
    ['id' => 'N00001', 'pass' => '@Aimi123'],
    ['id' => 'N00002', 'pass' => 'ain123'],
    ['id' => 'N00003', 'pass' => 'aina123'],
    ['id' => 'N00004', 'pass' => 'nurse123'],
    ['id' => 'N00005', 'pass' => 'med123'],
    ['id' => 'N00006', 'pass' => 'doc456'],
    ['id' => 'N00007', 'pass' => 'nurse789'],
    ['id' => 'N00008', 'pass' => 'pharmassist123'],
    ['id' => 'N00009', 'pass' => 'nurse765'],
    ['id' => 'N00010', 'pass' => 'pharmassist456'],
    ['id' => 'N00011', 'pass' => 'maya123'],
    ['id' => 'N00012', 'pass' => 'doctor098'],
    ['id' => 'N00013', 'pass' => 'meiyi123'],
    ['id' => 'N00014', 'pass' => 'nurse567'],
    ['id' => 'N00015', 'pass' => 'assist567'],
];

// Prepare the UPDATE statement once for efficiency
$sql = "UPDATE staff SET Password = ? WHERE StaffID = ?";
$stmt = $conn->prepare($sql);

if ($stmt === false) {
    die("Failed to prepare statement: " . $conn->error);
}

// Bind parameters
$stmt->bind_param('ss', $hashedPassword, $staffId);

$successCount = 0;
$errorCount = 0;

// Loop through each user and update their password
foreach ($users as $user) {
    $staffId = $user['id'];
    $plainPassword = $user['pass'];
    
    // Hash the password using the best available algorithm
    $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);
    
    echo "Processing StaffID: <strong>{$staffId}</strong>... ";
    
    if ($stmt->execute()) {
        echo "<span style='color: green;'>SUCCESS!</span><br>";
        $successCount++;
    } else {
        echo "<span style='color: red;'>ERROR: " . $stmt->error . "</span><br>";
        $errorCount++;
    }
}

$stmt->close();
$conn->close();

echo "<h2>Update Complete!</h2>";
echo "<p style='color: green;'>Successfully updated {$successCount} records.</p>";
if ($errorCount > 0) {
    echo "<p style='color: red;'>Failed to update {$errorCount} records.</p>";
}
echo "<p><strong>IMPORTANT: Please delete this file (`hash_passwords.php`) from your server now.</strong></p>";

?>