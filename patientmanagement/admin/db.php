<?php
$host = "localhost";  // MariaDB server (localhost for local setup)
$user = "root";       // MariaDB username (default: root)
$password = "1234567";       // MariaDB password (leave empty for XAMPP default)
$database = "workshop2"; // Name of the database

// Create connection
$conn = new mysqli($host, $user, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
//echo "Connected successfully!";
?>

