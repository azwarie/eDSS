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
        <a class="navbar-brand" href="https://localhost/dss/staff/nurse/NurseLandingPage.php" style="font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 700; color: #007bff; position: absolute; top: 10px; left: 15px;">eDSS</a>
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


        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    </body>
 </html>