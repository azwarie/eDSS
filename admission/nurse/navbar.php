<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Get the staff ID from the URL
$staffID = null;
if (isset($_GET['staffid'])) {
    $staffID = $_GET['staffid'];
}

// Get the current page filename
$current_page = basename($_SERVER['PHP_SELF']);

// Function to add staffid to links
function addStaffIdToLink($url, $staffId) {
    if ($staffId !== null) {
        $separator = (strpos($url, '?') === false) ? '?' : '&';
        return $url . $separator . 'staffid=' . htmlspecialchars($staffId);
    }
    return $url;
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
        
           .navbar .nav-link:hover {
            color: #007bff; /* Blue color on hover */
        }

        /* Highlight the active link with the specified blue color and bold text */
        .navbar .nav-item.active .nav-link {
            color: #007bff !important; /* Blue color for active page */
            font-weight: bold !important; /* Bold for active link */
        }
      </style>
   </head>
   <body>

    <nav class="navbar navbar-expand-lg navbar-light" style="background-color: white; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
    <div class="container">
        <a class="navbar-brand" href="https://172.20.238.6/staff2/admin/AdminLandingPage.php" style="font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 700; color: #007bff; position: absolute; top: 10px; left: 15px;">WeCare</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item <?php echo ($current_page == 'index.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="<?php echo addStaffIdToLink('index.php', $staffID); ?>" style="color: black;">Home</a>
                </li>
                <li class="nav-item <?php echo ($current_page == 'bed_registration.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="<?php echo addStaffIdToLink('bed_registration.php', $staffID); ?>" style="color: black;">Bed Registration</a>
                </li>
                <li class="nav-item <?php echo ($current_page == 'patient_coordination.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="<?php echo addStaffIdToLink('patient_coordination.php', $staffID); ?>" style="color: black;">Patient Coordination</a>
                </li>
                <li class="nav-item ms-auto <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="<?php echo addStaffIdToLink('dashboard.php', $staffID); ?>" style="color: black;">Real-Time Dashboards</a>
                </li>
            </ul>
        </div>
    </div>
</nav>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    </body>
 </html>