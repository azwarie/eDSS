<?php
// Database credentials
$servername_azwarie = "172.31.161.5";
$username_azwarie = "syiqin";
$password_azwarie = "abc123";
$db_name_azwarie = "123";

// Create connection
$connect_azwarie = mysqli_connect($servername_azwarie, $username_azwarie, $password_azwarie, $db_name_azwarie);

// Check connection
if ($connect_azwarie->connect_error) {
    die("Connection failed: " . $connect_azwarie->connect_error);
}


// echo "Connected successfully!<br>";

// //Test query to fetch data
// $sql = "SHOW TABLES";
// $result = $connect_azwarie->query($sql);

// if ($result->num_rows > 0) {
//     echo "Tables in the database:<br>";
//     while ($row = $result->fetch_array()) {
//         echo $row[0] . "<br>";
//     }
// } else {
//     echo "No tables found in the database.";
// }

?>