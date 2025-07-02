<?php
session_start();

// Include the database connection
include 'connection.php';
include_once('C:/xampp/htdocs/dss/components/chatbot.php');

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if the user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    // Redirect to the login page if not logged in
    header("Location: login.php");
    exit;
}

// Retrieve the staff ID from the session
$staffID = $_SESSION['staffid']; // Assign staff ID from session

$staffURL = "http://localhost/dss/staff/admin/adminpage.php?staffid=" .$staffID;
$admissionURL = "http://localhost/dss/admission/admin/index.php?staffid=" .$staffID;
$inventoryURL = "https://172.30.41.2/resource_allocation/admin/index.php?staffid=" .$staffID;
$patientURL = "http://localhost/dss/patientmanagement/admin/index.php?staffid=" .$staffID;

// Get the current page filename
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Landing Page</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700&family=Poppins:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <!-- Bootstrap JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
        }
        /*** Navbar ***/
        .navbar .dropdown-toggle::after {
            border: none;
            content: "\f107";
            font-family: "Font Awesome 5 Free";
            font-weight: 900;
            vertical-align: middle;
            margin-left: 8px;
        }
        .navbar .nav-link:hover {
            color: #007bff; /* Blue color on hover */
        }
        /* Highlight the active link with the specified blue color and bold text */
        .navbar .nav-item.active .nav-link {
            color: #007bff !important; /* Blue color for active page */
            font-weight: bold !important; /* Bold for active link */
        }
        /* Make the navbar sticky */
        .navbar {
            position: sticky;
            top: 0;
            z-index: 1000; /* Ensure it stays above other content */
            background-color: white; /* Add a background color */
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); /* Optional: Add a shadow for better visibility */
        }
        @media (min-width: 992px) {
            .navbar .nav-item .dropdown-menu {
                display: block;
                border: none;
                margin-top: 0;
                top: 150%;
                opacity: 0;
                visibility: hidden;
                transition: .5s;
            }
            .navbar .nav-item:hover .dropdown-menu {
                top: 100%;
                visibility: visible;
                transition: .5s;
                opacity: 1;
            }
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
        /* Flex container */
        .flex-container {
            display: flex;
            flex-direction: column;
            min-height: 100vh; /* Ensure the container takes at least the full viewport height */
        }
        .content {
            padding: 100px 0;
            text-align: center;
            flex: 1; /* Allow the main content to grow and take up remaining space */

        }
        /* Footer styling */
        footer {
            background-color: #007bff;
            color: white;
            padding: 20px 0;
            text-align: center;
        }
    </style>

</head>
<body>
<!-- Navigation Bar Menu -->
<nav class="navbar navbar-expand-lg navbar-light" style="background-color: white; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
    <div class="container">
        <a class="navbar-brand" href="http://localhost/dss/staff/admin/AdminLandingPage.php?staffid=<?php echo $staffID; ?>"style="font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 700; color: #007bff; position: absolute; top: 10px; left: 15px;">eDSS</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation"   style="margin-left: 65px;">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
            <li class="nav-item <?php echo ($current_page == 'AdminLandingPage.php') ? 'active' : ''; ?>">
                <a class="nav-link" href="http://localhost/dss/staff/admin/AdminLandingPage.php?staffid=<?php echo $staffID; ?>" style="color: black;">Home</a>
            </li>
            <li class="nav-item <?php echo ($current_page == 'http://localhost/dss/staff/admin/adminpage.php') ? 'active' : ''; ?>">
                <a class="nav-link" href="http://localhost/dss/staff/admin/adminpage.php?staffid=<?php echo $staffID; ?>" style="color: black;">Staff Management</a>
            </li>
            <li class="nav-item <?php echo ($current_page == 'http://localhost/dss/patientmanagement/admin/index.php') ? 'active' : ''; ?>">
                <a class="nav-link" href="http://localhost/dss/patientmanagement/admin/index.php?staffid=<?php echo $staffID; ?>" style="color: black;">Patient Management</a>
            </li>
            <li class="nav-item <?php echo ($current_page == 'http://localhost/dss/admission/admin/index.php') ? 'active' : ''; ?>">
                <a class="nav-link" href="http://localhost/dss/admission/admin/index.php?staffid=<?php echo $staffID; ?>" style="color: black;">Admission</a>
            </li>
                <li class="nav-item <?php echo ($current_page == 'http://localhost/dss/staff/admin/logout.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="http://localhost/dss/staff/admin/logout.php" style="color: black;">Logout</a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="flex-container">
<!-- Content Of The Page -->
    <div class="content">
        <h1>Welcome to the eDSS (Emergency Decision Support System)</h1>
        <p>Select an option below to proceed:</p>
        <div class="options">
            <!-- <a href="http://localhost/dss/staff/admin/adminpage.php">Staff Management</a>
            <a href="http://localhost/dss/patientmanagement/admin/index.php">Patient Management</a>
            <a href="http://localhost/dss/admission/admin/index.php">Admission</a>
            <a href="https://172.30.41.2/resource_allocation/admin/index.php">Inventory Management</a> -->

            <a href="<?php echo $staffURL; ?>">Staff Management</a>
            <a href="<?php echo $patientURL; ?>">Patient Management</a>
            <a href="<?php echo $admissionURL; ?>">Admission</a>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
    // Optional: Add logout confirmation
    document.addEventListener('DOMContentLoaded', function () {
        const logoutLink = document.querySelector('a[href="logout.php"]');
        if (logoutLink) {
            logoutLink.addEventListener('click', function (event) {
                if (!confirm('Are you sure you want to log out?')) {
                    event.preventDefault();
                }
            });
        }
    });
</script>

</div>
</body>
</html>
