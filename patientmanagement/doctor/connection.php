<?php
// connection.php

$servername = "localhost"; // e.g., "localhost" or your DB server IP
$username = "root";
$password = "";
$dbname = "azwarie_dss"; // The single database you want to use

// Create connection and assign it to $conn
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    // Log error instead of dying in production?
    die("Connection failed: " . $conn->connect_error);
}

// Optional: Set character set (recommended)
$conn->set_charset("utf8mb4");

?>