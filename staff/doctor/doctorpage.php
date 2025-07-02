<?php
session_start();
// Include the database connection
include 'connection.php';

// Get the current page filename
$current_page = basename($_SERVER['PHP_SELF']);

// Check if the user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    // Redirect to the login page if not logged in
    header("Location: login.php");
    exit;
}

// Retrieve the staff ID from the session
$staffID = $_SESSION['staffid']; // Assign staff ID from session

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Home Page</title>
    <!-- Include Remix Icons -->
    <link href="https://cdn.jsdelivr.net/npm/remixicon@2.5.0/fonts/remixicon.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700&family=Poppins:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
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
            /* Ensure responsiveness on smaller screens */
                @media (max-width: 768px) {
                .card-responsive {
                    margin: 10px;
                    padding: 10px;
                }
                .hero-section h1 {
                    font-size: 2.5rem;
                }
                .hero-section p {
                    font-size: 1rem;
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
        /*doctor header styles*/
        .doctor--overview h2 {
            font-size: 28px;
            color: black;  /* Dark color for better contrast */
            margin-bottom: 15px;
            text-transform: uppercase; /* Optional: makes the text uppercase */
            letter-spacing: 1px; /* Optional: adds space between letters */
            border-bottom: 2px solid #007bff; /* Adds a blue border at the bottom */
            padding-bottom: 10px; /* Gives some space between the text and border */
        }
    </style>

</head>
<body>
<!-- Navigation Bar Menu -->
<nav class="navbar navbar-expand-lg navbar-light" style="background-color: white; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
    <div class="container">
        <a class="navbar-brand" href="https://172.20.238.6/staff2/doctor/DoctorLandingPage.php?staffid=<?php echo $staffID; ?>"style="font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 700; color: #007bff; position: absolute; top: 10px; left: 15px;">WeCare</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation"   style="margin-left: 65px;">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item <?php echo ($current_page == 'doctorpage.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="https://172.20.238.6/staff2/doctor/doctorpage.php?staffid=<?php echo $staffID; ?>"  style="color: black;">Home</a>
                </li>
                <li class="nav-item <?php echo ($current_page == 'https://172.20.238.6/staff2/doctor/doctordashboard.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="https://172.20.238.6/staff2/doctor/doctordashboard.php?staffid=<?php echo $staffID; ?>"  style="color: black;">Real-Time Dashboard</a>
                </li>
                <li class="nav-item <?php echo ($current_page == 'https://172.20.238.6/staff2/doctor/docliststaff.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="https://172.20.238.6/staff2/doctor/docliststaff.php?staffid=<?php echo $staffID; ?>"  style="color: black;">Staff Directory</a>
                </li>
                <li class="nav-item <?php echo ($current_page == 'https://172.20.238.6/staff2/doctor/doclistdept.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="https://172.20.238.6/staff2/doctor/doclistdept.php?staffid=<?php echo $staffID; ?>"  style="color: black;">Department Directory</a>
                </li>
                <li class="nav-item <?php echo ($current_page == 'https://172.20.238.6/staff2/doctor/doctorprofile.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="https://172.20.238.6/staff2/doctor/doctorprofile.php?staffid=<?php echo $staffID; ?>" style="color: black;">Profile</a>
                </li>
            </ul>
        </div>
    </div>
</nav>
<!-- Content Of The Page -->
    <div class="content">
        <h1>Welcome to the Doctor Page</h1>
        <p>Select an option below to proceed:</p>
            <div class="options">
                <a href="https://172.20.238.6/staff2/doctor/doctordashboard.php?staffid=<?php echo $staffID; ?>">Real-Time Dashboard</a>
                <a href="https://172.20.238.6/staff2/doctor/docliststaff.php?staffid=<?php echo $staffID; ?>">Staff Directory</a>
                <a href="https://172.20.238.6/staff2/doctor/doclistdept.php?staffid=<?php echo $staffID; ?>">Department Directory</a>
                <a href="https://172.20.238.6/staff2/doctor/doctorprofile.php?staffid=<?php echo $staffID; ?>">Profile</a>
            </div>
        </div>
</body>
</html>
