<?php
session_start();
// Include the database connection
include 'connection.php';

// Check if the staffID is set in session
if (isset($_SESSION['staffid'])) {
    $staffID = $_SESSION['staffid']; // Retrieve the staff ID from the session
} else {
    die("Staff ID not found in session.");
}

// This is code for current page to navbar to be darker to show the user is in that page
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Include Remix Icons -->
    <link href="https://cdn.jsdelivr.net/npm/remixicon@2.5.0/fonts/remixicon.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700&family=Poppins:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>Admin - Department Management</title>
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
        /* Hero section styling */
        .hero-section {
            background-color: #007bff;
            color: white;
            padding: 80px 0;
            text-align: center;
        }
        .hero-section h1 {
            font-family: 'Montserrat', sans-serif;
            font-size: 3.5rem;
            font-weight: 700;
            color: white;
            text-transform: uppercase;
        }
        .hero-section p {
            font-size: 1.2rem;
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
        /* Style for Scroll to Top Button */
        #scrollUpBtn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            display: none; /* Initially hidden */
            background-color: #007bff; /* Blue background */
            color: white; /* White arrow */
            border: none; /* Remove border */
            border-radius: 50%; /* Circular shape */
            padding: 10px 15px; /* Size of the button */
            font-size: 20px; /* Font size for the arrow */
            cursor: pointer; /* Pointer cursor on hover */
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2); /* Slight shadow */
            z-index: 1000; /* Ensure it appears above other elements */
        }
        #scrollUpBtn:hover {
            background-color: #0056b3; /* Darker blue on hover */
        }
    </style>

</head>
<body>
<!-- Navigation Bar Menu -->
<nav class="navbar navbar-expand-lg navbar-light" style="background-color: white; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
    <div class="container">
        <a class="navbar-brand" href="AdminLandingPage.php?staffid=<?php echo $staffID; ?>" style="font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 700; color: #007bff; position: absolute; top: 10px; left: 15px;">WeCare</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation"   style="margin-left: 65px;">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item <?php echo ($current_page == 'adminpage.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="adminpage.php?staffid=<?php echo $staffID; ?>" style="color: black;">Home</a>
                </li>
                <li class="nav-item <?php echo ($current_page == 'admindashboard.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="admindashboard.php?staffid=<?php echo $staffID; ?>" style="color: black;">Real-Time Dashboard</a>
                </li>
                <li class="nav-item dropdown <?php echo ($current_page == 'viewStaff.php' || $current_page == 'registerStaff.php' || $current_page == 'removeStaff.php' || $current_page == 'updateStaff.php') ? 'active' : ''; ?>" style="position: relative;">
                    <a 
                        class="nav-link dropdown-toggle" 
                        href="#" 
                        id="manageStaffDropdown" 
                        role="button" 
                        data-bs-toggle="dropdown" 
                        aria-expanded="false" 
                        style="color: black; cursor: pointer;"
                    >
                        Manage Staff
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="manageStaffDropdown">
                        <li>
                            <a class="dropdown-item <?php echo ($current_page == 'viewStaff.php') ? 'active' : ''; ?>" 
                                href="viewStaff.php?staffid=<?php echo $staffID; ?>">Staff Directory</a>
                        </li>
                        <li>
                            <a class="dropdown-item <?php echo ($current_page == 'registerStaff.php') ? 'active' : ''; ?>" 
                                href="registerStaff.php?staffid=<?php echo $staffID; ?>">Register Staff</a>
                        </li>
                        <li>
                            <a class="dropdown-item <?php echo ($current_page == 'removeStaff.php') ? 'active' : ''; ?>" 
                                href="removeStaff.php?staffid=<?php echo $staffID; ?>">Remove Staff</a>
                        </li>
                        <li>
                            <a class="dropdown-item <?php echo ($current_page == 'updateStaff.php') ? 'active' : ''; ?>" 
                                href="updateStaff.php?staffid=<?php echo $staffID; ?>">Update Staff</a>
                        </li>
                    </ul>
                </li>
                <li class="nav-item dropdown <?php echo ($current_page == 'manageDept.php' || $current_page == 'updateDept.php') ? 'active' : ''; ?>" style="position: relative;">
                    <a 
                        class="nav-link dropdown-toggle" 
                        href="#" 
                        id="manageDeptDropdown" 
                        role="button" 
                        data-bs-toggle="dropdown" 
                        aria-expanded="false" 
                        style="color: black; cursor: pointer;"
                    >
                        Manage Department
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="manageDeptDropdown">
                        <li>
                            <a class="dropdown-item <?php echo ($current_page == 'manageDept.php') ? 'active' : ''; ?>" 
                                href="manageDept.php?staffid=<?= $staffID ?>">Department Directory</a>
                        </li>
                        <li>
                            <a class="dropdown-item <?php echo ($current_page == 'updateDept.php') ? 'active' : ''; ?>" 
                                href="updateDept.php?staffid=<?= $staffID ?>">Update Department</a>
                        </li>
                    </ul>
                </li>
                <li class="nav-item <?php echo ($current_page == 'adminprofile.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="adminprofile.php?staffid=<?php echo $staffID; ?>" style="color: black;">Profile</a>
                </li>
            </ul>
        </div>
    </div>
</nav>
<!-- Hero Section -->
<div class="hero-section">
        <h1>Department Management</h1>
        <p>Select an option below to proceed:</p>
    </div>

        <div class="content">
            <div class="options">
                <a href="manageDept.php">Department Directory</a>
                <a href="addDept.php">Register Department</a>
                <a href="updateDept.php">Update Department</a>
            </div>
        </div>
<script>
// Get the button
const scrollUpBtn = document.getElementById("scrollUpBtn");
// When the user scrolls down 100px from the top of the document, show the button
window.onscroll = function() {
    if (document.body.scrollTop > 5 || document.documentElement.scrollTop >5) {
        scrollUpBtn.style.display = "block";
    } else {
        scrollUpBtn.style.display = "none";
    }
};
// When the button is clicked, scroll to the top of the page
function scrollToTop() {
    window.scrollTo({
        top: 0,
        behavior: "smooth"  // Smooth scroll
    });
}
</script>
</body>
</html>