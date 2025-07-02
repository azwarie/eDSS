<?php

// Enable detailed error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database credentials
$servername = "172.24.214.126"; // MariaDB server address
$username = "root";             // Database username
$password = "1234567";          // Database password
$database = "workshop2";        // Database name

// Connect to MariaDB
$conn_thanesh = new mysqli($servername, $username, $password, $database);

// Check the connection
if ($conn_thanesh->connect_error) {
    die("Connection failed: " . $conn_thanesh->connect_error . " (Error Code: " . $conn_thanesh->connect_errno . ")");
}

echo "Connected successfully!<br>";

// Test query to fetch tables in the database
$sql = "SHOW TABLES";
$result = $conn_thanesh->query($sql);

if ($result->num_rows > 0) {
    echo "Tables in the database:<br>";
    while ($row = $result->fetch_array()) {
        echo $row[0] . "<br>"; // Output each table name
    }
} else {
    echo "No tables found in the database.";
}

?>



