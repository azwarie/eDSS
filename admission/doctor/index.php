<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);


$staffID = null;
if(isset($_GET['staffid'])) {
    $staffID = $_GET['staffid'];
    // IMPORTANT: You MUST validate this value.
    // Use with caution
}

  if( $staffID !== null)
        {
         echo htmlspecialchars( $staffID );
        }
        else{
            echo 'Not Provided';
           }

// Function to add staffid to links
function addStaffIdToLink($url, $staffId) {
    if ($staffId !== null) {
        $separator = (strpos($url, '?') === false) ? '?' : '&';
        return $url . $separator . 'staffid=' . htmlspecialchars($staffId);
    }
    return $url;
}

// Define the directory where you will store the backup files (make it relative to the admission folder)
define('DIR', dirname(__DIR__));

// Set the desired timezone for the application
date_default_timezone_set('Asia/Kuala_Lumpur');



function backupDatabaseUsingPDO($host, $username, $password, $database, $backupFile) {
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$database", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
      $query = "SHOW TABLES";
      $stmt = $pdo->query($query);
       $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

         $sql = "SET FOREIGN_KEY_CHECKS = 0;\n"; // Disable foreign key checks
        foreach ($tables as $table) {
            // Drop Table query
           $sql .= "DROP TABLE IF EXISTS `$table`;\n";
            // Show Create table query
           $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
           $row = $stmt->fetch(PDO::FETCH_ASSOC);
           $sql .= $row['Create Table'] . ";\n";
             // Get all Data
          $stmt = $pdo->query("SELECT * FROM `$table`");
           $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
             // Insert data
            if ($rows) {
              foreach ($rows as $row) {
                  $keys = array_keys($row);
               $values = array_map(function($value) use ($pdo) {
                 if ($value === null) {
                          return 'NULL';
                      }
                       return $pdo->quote($value);
                  }, array_values($row));
                $sql .= "INSERT INTO `$table` (`".implode("`,`", $keys)."`) VALUES (".implode(",", $values).");\n";
               }
             }
          }
        $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n"; // Enable foreign key checks
       if (file_put_contents($backupFile, $sql) !== false) {
          return "Backup was successful";
      } else {
           return "Error saving backup to file.";
      }
      } catch (PDOException $e) {
           return "Error during database backup: " . $e->getMessage();
     }
 }

   if (isset($_POST['backup'])) {
      // Database credentials
        $host = 'localhost';
        $username = 'root'; // Replace with your MySQL username
      $password = ''; // Replace with your MySQL password
        $database = 'azwarie_dss'; //Replace with the database

        // Get the timestamp from local time
        $timestamp = date('Y-m-d_H-i-s');

        // Define the output file name and path
        $backupFile = DIR . '/backup/azwarie_dss_backup_' . $timestamp . '.sql';
        $message = backupDatabaseUsingPDO($host, $username, $password, $database, $backupFile);

       echo "<script>alert('{$message} Backup saved as: $backupFile');</script>";
  }

 if (isset($_POST['recover'])) {
       // Database credentials
      $host = 'localhost';
      $username = 'root'; // Replace with your MySQL username
       $password = ''; // Replace with your MySQL password
      $database = 'azwarie_dss'; //Replace with the database

     // Get the latest backup file
      $backupDir = DIR . '/backup';
      $files = glob($backupDir . '/azwarie_dss_backup_*.sql');
      rsort($files); // Sort files in descending order by name (latest first)

     if (!empty($files)) {
          $latestBackup = $files[0];

         // Command to restore the database
         $command = "/opt/lampp/bin/mysql --user=$username --password=$password --host=$host $database < $latestBackup";

         exec($command, $output, $return_var);

         // Check if the recovery was successful
           if ($return_var === 0) {
               echo "<script>alert('Database recovery successful! Restored from: $latestBackup');</script>";
          } else {
              $error_message = implode("\n", $output);
               echo "<script>alert('Database recovery failed with error: " .  addslashes($error_message) .  " Please check your permissions or command syntax.');</script>";
          }
    } else {
           echo "<script>alert('No backup files found for recovery.');</script>";
    }
 }
 ?>
 <!DOCTYPE html>
 <html lang="en">
   <head>
      <meta charset="UTF-8">
     <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admission</title>
      <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700&family=Poppins:wght@400;700&display=swap" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
      <style>
          body {
               font-family: 'Poppins', sans-serif;
              margin: 0;
               padding: 0;
          }
          .content {
               padding: 100px 0;
             text-align: center;
          }
          .options a {
               display: inline-block;
                margin: 10px;
              padding: 15px 30px;
               border: 2px solid #007bff;
                 color: #007bff;
               text-decoration: none;
              font-weight: 700;
                border-radius: 5px;
          }
           .options a:hover {
               background-color: #007bff;
                color: white;
           }
          footer {
                background-color: #007bff;
                color: white;
               padding: 20px 0;
                text-align: center;
          }
          .backup-form {
              margin: 20px auto;
               width: fit-content;
          }
          .backup-form button {
               padding: 10px 20px;
                background-color: #4CAF50;
                color: white;
               border: none;
                border-radius: 5px;
                cursor: pointer;
           }
           .backup-form button:hover {
                background-color: #45a049;
           }
               .content {
            padding: 100px 0;
            text-align: center;
        }

           .navbar .nav-link:hover {
            color: #007bff; /* Blue color on hover */
        }

        /* Highlight the active link with the specified blue color and bold text */
        .navbar .nav-item.active .nav-link {
            color: #007bff !important; /* Blue color for active page */
            font-weight: bold !important; /* Bold for active link */
        }

        .navbar .nav-item:first-child .nav-link{
            color: #007bff !important;
      font-weight: bold !important;
      }

 .navbar .nav-item:first-child.active .nav-link {
         color: #007bff !important;
         font-weight: bold !important;
        }
      </style>
   </head>
   <body>

    <nav class="navbar navbar-expand-lg navbar-light" style="background-color: white; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
    <div class="container">
        <a class="navbar-brand" href="https://localhost/dss/staff/admin/AdminLandingPage.php" style="font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 700; color: #007bff; position: absolute; top: 10px; left: 15px;">WeCare</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item <?php echo ($current_page == 'index.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="<?php echo addStaffIdToLink('index.php', $staffID); ?>" style="color: black;">Home</a>
                </li>
                <li class="nav-item <?php echo ($current_page == 'manage_edstay.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="<?php echo addStaffIdToLink('manage_edstay.php', $staffID); ?>" style="color: black;">ED</a>
                </li>
                <li class="nav-item ms-auto <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="<?php echo addStaffIdToLink('dashboard.php', $staffID); ?>" style="color: black;">Real-Time Dashboards</a>
                </li>
            </ul>
        </div>
    </div>
</nav>
       <div class="content">
        <h1>Welcome to the Admission Module</h1>
        <p>Select an option below to proceed:</p>
        <div class="options">
          <a href="bed_registration.php<?php if ($staffID !== null) echo '?staffid=' . htmlspecialchars($staffID); ?>">Bed Registration</a>
            <a href="patient_coordination.php<?php if ($staffID !== null) echo '?staffid=' . htmlspecialchars($staffID); ?>">Patient Coordination</a>
            <a href="dashboard.php<?php if ($staffID !== null) echo '?staffid=' . htmlspecialchars($staffID); ?>">Real-Time Dashboards</a>
        </div>
    </div>

            <div class="backup-form">
                <form method="post">
                    <button type="submit" name="backup">Backup Database</button>
                    <button type="submit" name="recover">Recover Database</button>
                </form>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    </body>
 </html>