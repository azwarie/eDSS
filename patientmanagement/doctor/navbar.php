<?php
// Get the current page filename
$current_page = basename($_SERVER['PHP_SELF']);

?>


<nav class="navbar navbar-expand-lg navbar-light" style="background-color: white; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
    <div class="container">
        <a class="navbar-brand" href="https://localhost/dss/AdminLandingPage.php" style="font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 700; color: #007bff; position: absolute; top: 10px; left: 15px;">WeCare</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">

                <li class="nav-item <?php echo ($current_page == 'index.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="https://localhost/dss/AdminLandingPage.php" style="color: black;">Home</a>
                </li>
                <li class="nav-item <?php echo ($current_page == 'register_patient.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="register_patient.php" style="color: black;">Patient Registration</a>
                </li>
                <li class="nav-item <?php echo ($current_page == 'book_appointment.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="book_appointment.php" style="color: black;">Appointments</a>
                </li>
                <li class="nav-item ms-auto <?php echo ($current_page == 'assign_diagnosis.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="assign_diagnosis.php" style="color: black;">Patient Diagnosis</a>
                </li>
                <li class="nav-item ms-auto <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="dashboard.php" style="color: black;">Real-Time Dashboards</a>
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
