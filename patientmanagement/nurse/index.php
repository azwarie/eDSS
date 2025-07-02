<?php
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

?>
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
                <li class="nav-item <?php echo ($current_page == 'register_patient.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="<?php echo addStaffIdToLink('register_patient.php', $staffID); ?>" style="color: black;">Patient Registration</a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<style>
    .navbar .nav-link:hover {
        color: #007bff; /* Blue color on hover */
    }

    /* Highlight the active link with the specified blue color and bold text */
    .navbar .nav-item.active .nav-link {
        color: #007bff !important; /* Blue color for active page */
        font-weight: bold !important; /* Bold for active link */
    }
</style>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient</title>
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

        
    </style>
</head>
<body>


    <div class="content">
        <h1>Welcome to the Patient Management</h1>
        <p>Select an option below to proceed:</p>
        <div class="options">
            <a href="register_patient.php<?php if ($staffID !== null) echo '?staffid=' . htmlspecialchars($staffID); ?>">Patient Registration</a>
            <a href="assign_ed_diagnosis.php<?php if ($staffID !== null) echo '?staffid=' . htmlspecialchars($staffID); ?>">Patient Diagnosis</a>
            <a href="dashboard.php<?php if ($staffID !== null) echo '?staffid=' . htmlspecialchars($staffID); ?>">Real-Time Dashboards</a>
        
        </div>
    </div>



    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>