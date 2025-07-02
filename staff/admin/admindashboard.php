<?php
session_start();

// Include the database connection (mysqli for azwarie_dss)
include 'connection.php'; // This should define $conn using mysqli

// Check connection
if (!$conn || $conn->connect_error) {
    die("Database connection failed: " . ($conn ? $conn->connect_error : 'Unknown error'));
}

// Set default timezone
date_default_timezone_set('Asia/Kuala_Lumpur'); // Replace with your timezone

// Get current page
$current_page = basename($_SERVER['PHP_SELF']);

// Check login status
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

// Get logged-in staff ID
$loggedInStaffID = $_SESSION['staffid'] ?? $_GET['staffid'] ?? null;
if (empty($loggedInStaffID)) {
     die("Admin Staff ID is missing or invalid.");
}

// Initialize data arrays and error variable
$availability_data = ["Available" => 0, "Unavailable" => 0];
$role_data = [];
$nurse_counts = ['available_nurses' => 0, 'unavailable_nurses' => 0];
$doctor_counts = ['available_doctors' => 0, 'unavailable_doctors' => 0];
$total_staff = 'Error';
$available_nurses = [];
$available_doctors = [];
$query_error = null;

try {
    // --- Use prepared statements or escape inputs if needed, though these queries are static ---

    // Query to get total staff count grouped by status (for Pie chart)
    $sql_status = "SELECT status, COUNT(*) AS count FROM STAFF GROUP BY status";
    $result_status = $conn->query($sql_status);
    if ($result_status) {
        while ($row = $result_status->fetch_assoc()) {
            // Ensure status key exists and is capitalized correctly for the array
             $statusKey = ucfirst(strtolower($row['status'])); // e.g., 'available' -> 'Available'
             if (array_key_exists($statusKey, $availability_data)) {
                  $availability_data[$statusKey] = (int)$row['count'];
             }
        }
        $result_status->free();
    } else { throw new Exception("Error fetching staff status counts: " . $conn->error); }

    // Query to get total staff count (for card)
    $sql_total_staff = "SELECT COUNT(*) AS total_staff FROM STAFF";
    $result_total_staff = $conn->query($sql_total_staff);
    if ($result_total_staff && $row = $result_total_staff->fetch_assoc()) {
        $total_staff = (int)$row['total_staff'];
    } else { throw new Exception("Error fetching total staff count: " . $conn->error); }
    if ($result_total_staff) $result_total_staff->free();


    // Query to get staff count by role (for Bar chart)
    $sql_role_breakdown = "SELECT role, COUNT(*) AS count FROM STAFF GROUP BY role";
    $result_role_breakdown = $conn->query($sql_role_breakdown);
    if ($result_role_breakdown) {
        while ($row = $result_role_breakdown->fetch_assoc()) {
             // Use the role directly as the key, ensure it's not empty
             $roleKey = !empty($row['role']) ? $row['role'] : 'Unknown Role';
             $role_data[$roleKey] = (int)$row['count'];
        }
        $result_role_breakdown->free();
    } else { throw new Exception("Error fetching staff role breakdown: " . $conn->error); }

    // Query to count available and unavailable nurses (for card)
    $sql_nurse_counts = "
        SELECT
            SUM(CASE WHEN Status = 'Available' THEN 1 ELSE 0 END) AS available_nurses,
            SUM(CASE WHEN Status = 'Unavailable' THEN 1 ELSE 0 END) AS unavailable_nurses
        FROM STAFF
        WHERE Role = 'NURSE'";
    $result_nurse_counts = $conn->query($sql_nurse_counts);
    if ($result_nurse_counts && $row = $result_nurse_counts->fetch_assoc()) {
        $nurse_counts['available_nurses'] = (int)$row['available_nurses'];
        $nurse_counts['unavailable_nurses'] = (int)$row['unavailable_nurses'];
    } else { throw new Exception("Error fetching nurse counts: " . $conn->error); }
    if ($result_nurse_counts) $result_nurse_counts->free();

    // Query to count available and unavailable doctors (for card)
    $sql_doctor_counts = "
        SELECT
            SUM(CASE WHEN Status = 'Available' THEN 1 ELSE 0 END) AS available_doctors,
            SUM(CASE WHEN Status = 'Unavailable' THEN 1 ELSE 0 END) AS unavailable_doctors
        FROM STAFF
        WHERE Role = 'DOCTOR'";
    $result_doctor_counts = $conn->query($sql_doctor_counts);
    if ($result_doctor_counts && $row = $result_doctor_counts->fetch_assoc()) {
        $doctor_counts['available_doctors'] = (int)$row['available_doctors'];
        $doctor_counts['unavailable_doctors'] = (int)$row['unavailable_doctors'];
    } else { throw new Exception("Error fetching doctor counts: " . $conn->error); }
    if ($result_doctor_counts) $result_doctor_counts->free();

    // --- Queries for Tables ---
    // Query to fetch available nurses (for table)
    $sql_available_nurses = "
        SELECT s.StaffID, s.Name, s.Gender, s.PhoneNo, s.Role, s.Status, d.DepartmentName
        FROM STAFF s
        LEFT JOIN DEPARTMENT d ON s.DepartmentID = d.DepartmentID
        WHERE s.Status = 'Available' AND s.Role = 'NURSE'
        ORDER BY s.Name"; // Order by Name initially
    $result_available_nurses = $conn->query($sql_available_nurses);
    if ($result_available_nurses) {
        $available_nurses = $result_available_nurses->fetch_all(MYSQLI_ASSOC);
        $result_available_nurses->free();
    } else { throw new Exception("Error fetching available nurses list: " . $conn->error); }

    // Query to fetch available doctors (for table)
    $sql_available_doctors = "
        SELECT s.StaffID, s.Name, s.Gender, s.PhoneNo, s.Role, s.Status, d.DepartmentName
        FROM STAFF s
        LEFT JOIN DEPARTMENT d ON s.DepartmentID = d.DepartmentID
        WHERE s.Status = 'Available' AND s.Role = 'DOCTOR'
        ORDER BY s.Name"; // Order by Name initially
    $result_available_doctors = $conn->query($sql_available_doctors);
    if ($result_available_doctors) {
        $available_doctors = $result_available_doctors->fetch_all(MYSQLI_ASSOC);
        $result_available_doctors->free();
    } else { throw new Exception("Error fetching available doctors list: " . $conn->error); }


} catch (Exception $e) {
    $query_error = "Database Error: " . $e->getMessage();
    error_log($query_error); // Log detailed error
}

// --- Staff ID Link Function ---
function addStaffIdToLink($url, $staffId) {
    if ($staffId !== null) {
        $separator = (strpos($url, '?') === false) ? '?' : '&';
        return $url . $separator . 'staffid=' . urlencode($staffId);
    }
    return $url;
}

// Prepare JSON data for charts
$availabilityDataJson = json_encode($availability_data);
$roleDataJson = json_encode($role_data);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Staff Analysis</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700&family=Poppins:wght@400;700&display=swap" rel="stylesheet">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Optional: Card Style CSS -->
    <!-- <link rel="stylesheet" href="cardstyle.css"> -->

    <style>
        /* Using styles from previous conversions for consistency */
        body { font-family: 'Poppins', sans-serif; margin: 0; padding: 0; padding-top: 70px; background-color: #f8f9fa; }
        .navbar { position: sticky; top: 0; z-index: 1030; background-color: white !important; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05); padding-top: 0.5rem; padding-bottom: 0.5rem; }
        .navbar-brand { font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 700; color: #007bff !important; }
        .navbar-toggler { border: none; } .navbar-toggler:focus { box-shadow: none; }
        .navbar .nav-link { color: #495057 !important; font-weight: 500; transition: color 0.2s ease; padding: 0.5rem 1rem; }
        .navbar .nav-link:hover, .navbar .nav-item.active .nav-link { color: #007bff !important; }
        .navbar .nav-item.active .nav-link { font-weight: 700 !important; }
        .navbar .dropdown-menu { border-radius: 0.25rem; box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15); border: none; margin-top: 0.125rem; }
        .navbar .dropdown-item { padding: 0.5rem 1rem; font-size: 0.95rem; }
        .navbar .dropdown-item:active { background-color: #e9ecef; color: #212529; }
        .navbar .dropdown-item.active { font-weight: bold; color: #007bff; background-color: transparent; }
        .navbar .dropdown-toggle::after { border: none; content: "\f107"; font-family: "Font Awesome 6 Free"; font-weight: 900; vertical-align: middle; margin-left: 5px; }
        @media (min-width: 992px) { .navbar .nav-item .dropdown-menu { display: block; margin-top: 0; top: 150%; opacity: 0; visibility: hidden; transition: top 0.3s ease, opacity 0.3s ease, visibility 0.3s; pointer-events: none; } .navbar .nav-item:hover .dropdown-menu { top: 100%; visibility: visible; opacity: 1; pointer-events: auto; } }
        .hero-section { background-color: #007bff; color: white; padding: 60px 0; text-align: center; margin-bottom: 30px; }
        .hero-section h1 { font-family: 'Montserrat', sans-serif; font-size: 2.8rem; font-weight: 700; text-transform: uppercase; }
        .hero-section p { font-size: 1.1rem; opacity: 0.9; }
        @media (max-width: 768px) { .hero-section { padding: 40px 0; } .hero-section h1 { font-size: 2rem; } .hero-section p { font-size: 1rem; } }

        /* Cards */
        .stat-card { margin-bottom: 20px; box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08); border-radius: 8px; border: none; border-left-width: 5px; border-left-style: solid; }
        .stat-card .card-body { padding: 1.25rem; display: flex; justify-content: space-between; align-items: center; }
        .stat-card .card-title { font-size: 0.95rem; font-weight: 600; color: #6c757d; text-transform: uppercase; margin-bottom: 0.25rem; }
        .stat-card .card-text { font-size: 2.2rem; font-weight: 700; color: #343a40; line-height: 1.2; }
        .stat-card i { font-size: 2.5rem; color: #dee2e6; /* Light grey icon */}
        .border-left-primary { border-left-color: #007bff !important; }
        .border-left-success { border-left-color: #198754 !important; }
        .border-left-secondary { border-left-color: #6c757d !important; }

        /* Charts */
        .chart-card { box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08); border-radius: 8px; border: none; margin-bottom: 20px; }
        .chart-card .card-header { background-color: #e9ecef; border-bottom: 1px solid #dee2e6; font-weight: 600; padding: 0.85rem 1.25rem; font-size: 1.05rem; }
        .chart-card .card-body { padding: 1.5rem; }
        .chart-container { position: relative; height: 300px; /* Adjust height as needed */ width: 100%; }
        @media (max-width: 992px) { .chart-container { height: 280px; } }
        @media (max-width: 768px) { .chart-container { height: 250px; } }

        /* Tables */
        .table-card { box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08); border-radius: 8px; border: none; margin-bottom: 20px; }
        .table-card .card-header { background-color: #007bff; color: white; border-bottom: none; font-weight: 600; padding: 0.85rem 1.25rem; font-size: 1.1rem; }
        .table-card .card-body { padding: 0; /* Remove padding to allow table to fill */ }
        #nurseTable, #doctorTable { margin-bottom: 0; /* Remove bottom margin inside card */ font-size: 0.9rem; }
        #nurseTable thead th, #doctorTable thead th { background-color: #f8f9fa; border-bottom: 2px solid #dee2e6; white-space: nowrap; }
        #nurseTable tbody tr:hover, #doctorTable tbody tr:hover { background-color: #f1f7ff; }
        th.clickable { cursor: pointer; position: relative; padding-right: 25px !important; user-select: none; }
        th.clickable:hover { background-color: #dde4ed; }
        th.clickable::before, th.clickable::after { font-family: "Font Awesome 6 Free"; font-weight: 900; position: absolute; right: 8px; opacity: 0.25; color: #6c757d; transition: opacity 0.2s ease, color 0.2s ease; }
        th.clickable::before { content: "\f0de"; top: calc(50% - 0.6em); }
        th.clickable::after { content: "\f0dd"; top: calc(50% - 0.1em); }
        th.clickable.sort-asc::before { opacity: 1; color: #007bff; }
        th.clickable.sort-desc::after { opacity: 1; color: #007bff; }

        /* Scroll Button */
        #scrollUpBtn { position: fixed; bottom: 25px; right: 25px; display: none; background-color: #007bff; color: white; border: none; border-radius: 50%; width: 45px; height: 45px; font-size: 20px; line-height: 45px; text-align: center; cursor: pointer; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2); z-index: 1000; transition: background-color 0.2s ease, opacity 0.3s ease; }
        #scrollUpBtn:hover { background-color: #0056b3; }
        .alert { margin-top: 1.25rem; border-radius: 5px; font-size: 0.95rem; }
    </style>
</head>
<body>
<!-- Navigation Bar Menu -->
<nav class="navbar navbar-expand-lg navbar-light">
    <div class="container">
        <a class="navbar-brand" href="AdminLandingPage.php?staffid=<?php echo htmlspecialchars($loggedInStaffID); ?>">eDSS</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <!-- Updated Navbar Links with staffid -->
                <li class="nav-item <?php echo ($current_page == 'adminpage.php') ? 'active' : ''; ?>"><a class="nav-link" href="adminpage.php?staffid=<?php echo htmlspecialchars($loggedInStaffID); ?>">Home</a></li>
                <li class="nav-item dropdown <?php echo in_array($current_page, ['admindashboard.php']) ? 'active' : ''; ?>"><a class="nav-link dropdown-toggle" href="#" id="dashboardsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">Real-Time Dashboards</a>
                    <ul class="dropdown-menu" aria-labelledby="dashboardsDropdown">
                        <li><a class="dropdown-item <?php echo ($current_page == 'admindashboard.php') ? 'active' : ''; ?>" href="admindashboard.php?staffid=<?php echo htmlspecialchars($loggedInStaffID); ?>">Dashboard Overview</a></li>
                    </ul>
                </li>
                <li class="nav-item dropdown <?php echo in_array($current_page, ['viewStaff.php', 'registerStaff.php', 'updateStaff.php']) ? 'active' : ''; ?>"><a class="nav-link dropdown-toggle" href="#" id="staffDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">Manage Staff</a>
                    <ul class="dropdown-menu" aria-labelledby="staffDropdown">
                        <li><a class="dropdown-item <?php echo ($current_page == 'viewStaff.php') ? 'active' : ''; ?>" href="viewStaff.php?staffid=<?php echo htmlspecialchars($loggedInStaffID); ?>">Staff Directory</a></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'registerStaff.php') ? 'active' : ''; ?>" href="registerStaff.php?staffid=<?php echo htmlspecialchars($loggedInStaffID); ?>">Register Staff</a></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'updateStaff.php') ? 'active' : ''; ?>" href="updateStaff.php?staffid=<?php echo htmlspecialchars($loggedInStaffID); ?>">Update Staff</a></li>
                    </ul>
                </li>
                <li class="nav-item <?php echo ($current_page == 'manageDept.php') ? 'active' : ''; ?>"><a class="nav-link" href="manageDept.php?staffid=<?php echo htmlspecialchars($loggedInStaffID); ?>">Department Directory</a></li>
                <li class="nav-item"><a class="nav-link" href="login.php?action=logout">Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<!-- Hero Section -->
<div class="hero-section">
    <h1>Staff Analysis</h1>
    <p>Overview of Staff Statistics and Availability</p>
</div>

<!-- Main Content Container -->
<div class="container mt-4 mb-5">

    <!-- Display Query Errors if any -->
     <?php if ($query_error): ?>
        <div class="alert alert-danger" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($query_error); ?> Some data might be unavailable.
        </div>
     <?php endif; ?>

    <!-- Statistics Cards Row -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="stat-card border-left-primary h-100">
                <div class="card-body">
                    <div>
                        <div class="card-title text-primary">Total Staff</div>
                        <div class="card-text"><?= $total_staff; ?></div>
                    </div>
                    <i class="fas fa-users fa-3x text-gray-300"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card border-left-success h-100">
                 <div class="card-body">
                    <div>
                        <div class="card-title text-success">Available Nurses</div>
                        <div class="card-text"><?= $nurse_counts['available_nurses'] ?? 0; ?></div>
                     </div>
                     <i class="fas fa-user-nurse fa-3x text-gray-300"></i>
                 </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card border-left-secondary h-100">
                <div class="card-body">
                    <div>
                        <div class="card-title text-secondary">Available Doctors</div>
                        <div class="card-text"><?= $doctor_counts['available_doctors'] ?? 0; ?></div>
                    </div>
                    <i class="fas fa-user-md fa-3x text-gray-300"></i>
                </div>
            </div>
        </div>
    </div> <!-- End Row -->


    <!-- Charts Row -->
    <div class="row mb-4">
        <!-- Pie Chart Card -->
        <div class="col-lg-6">
            <div class="chart-card h-100">
                <div class="card-header"><i class="fas fa-chart-pie me-2"></i>Staff Availability Status</div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="pieChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bar Chart Card -->
        <div class="col-lg-6">
            <div class="chart-card h-100">
                <div class="card-header"><i class="fas fa-chart-bar me-2"></i>Staff Breakdown by Role</div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="roleChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div> <!-- End Row -->


    <!-- Available Nurses Table -->
    <div class="table-card mb-4">
        <div class="card-header">
           <i class="fas fa-notes-medical me-2"></i>List Of Available Nurses
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="nurseTable" class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th class="clickable" onclick="sortTable('nurseTable', 0, this)">Staff ID</th>
                            <th class="clickable" onclick="sortTable('nurseTable', 1, this)">Name</th>
                            <th class="clickable" onclick="sortTable('nurseTable', 2, this)">Gender</th>
                            <th class="clickable" onclick="sortTable('nurseTable', 3, this)">Phone No</th>
                            <th class="clickable" onclick="sortTable('nurseTable', 4, this)">Status</th>
                            <th class="clickable" onclick="sortTable('nurseTable', 5, this)">Department</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($available_nurses)): ?>
                            <?php foreach ($available_nurses as $nurse): ?>
                                <tr>
                                    <td><?= htmlspecialchars($nurse['StaffID'] ?? 'N/A'); ?></td>
                                    <td><?= htmlspecialchars($nurse['Name'] ?? 'N/A'); ?></td>
                                    <td><?= htmlspecialchars($nurse['Gender'] ?? 'N/A'); ?></td>
                                    <td><?= htmlspecialchars($nurse['PhoneNo'] ?? 'N/A'); ?></td>
                                    <td><span class="badge bg-success"><?= htmlspecialchars($nurse['Status'] ?? 'N/A'); ?></span></td>
                                    <td><?= htmlspecialchars($nurse['DepartmentName'] ?? 'N/A'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center text-muted">No available nurses found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Available Doctors Table -->
    <div class="table-card mb-4">
        <div class="card-header">
            <i class="fas fa-user-md me-2"></i>List Of Available Doctors
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="doctorTable" class="table table-striped table-hover">
                    <thead>
                        <tr>
                             <th class="clickable" onclick="sortTable('doctorTable', 0, this)">Staff ID</th>
                             <th class="clickable" onclick="sortTable('doctorTable', 1, this)">Name</th>
                             <th class="clickable" onclick="sortTable('doctorTable', 2, this)">Gender</th>
                             <th class="clickable" onclick="sortTable('doctorTable', 3, this)">Phone No</th>
                             <th class="clickable" onclick="sortTable('doctorTable', 4, this)">Status</th>
                             <th class="clickable" onclick="sortTable('doctorTable', 5, this)">Department</th>
                        </tr>
                    </thead>
                    <tbody>
                         <?php if (!empty($available_doctors)): ?>
                            <?php foreach ($available_doctors as $doctor): ?>
                                <tr>
                                    <td><?= htmlspecialchars($doctor['StaffID'] ?? 'N/A'); ?></td>
                                    <td><?= htmlspecialchars($doctor['Name'] ?? 'N/A'); ?></td>
                                    <td><?= htmlspecialchars($doctor['Gender'] ?? 'N/A'); ?></td>
                                    <td><?= htmlspecialchars($doctor['PhoneNo'] ?? 'N/A'); ?></td>
                                    <td><span class="badge bg-success"><?= htmlspecialchars($doctor['Status'] ?? 'N/A'); ?></span></td>
                                    <td><?= htmlspecialchars($doctor['DepartmentName'] ?? 'N/A'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                         <?php else: ?>
                            <tr><td colspan="6" class="text-center text-muted">No available doctors found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div> <!-- End container -->


<!-- Scroll to Top Button -->
<button id="scrollUpBtn" onclick="scrollToTop()" title="Go to top"><i class="fas fa-arrow-up"></i></button>

<!-- Bootstrap JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<!-- Optional: main.js -->
<!-- <script src="main.js"></script> -->

<script>
    // --- Chart Generation ---
    document.addEventListener('DOMContentLoaded', function () {
        // --- UPDATED Staff Availability Doughnut Chart ---
        const availabilityData = <?php echo $availabilityDataJson; ?> || {}; // Default to empty object
        const availabilityCtx = document.getElementById('pieChart')?.getContext('2d'); // Get context for canvas with id="pieChart"
        let availabilityChartInstance = null; // To potentially destroy/update later if needed

        function renderAvailabilityChart(data) {
             if (!availabilityCtx) {
                console.error("Staff availability chart canvas ('pieChart') not found.");
                return;
             }
             if (availabilityChartInstance) {
                 availabilityChartInstance.destroy(); // Destroy previous instance if exists
             }

             const chartData = {
                 // Ensure labels match the keys in your PHP $availability_data array
                labels: ['Available', 'Unavailable'],
                datasets: [{
                    label: 'Staff Status', // Changed label slightly
                    // Ensure data order matches labels ['Available', 'Unavailable']
                    data: [ data.Available || 0, data.Unavailable || 0 ],
                    backgroundColor: ['#198754', '#dc3545'], // Green for Available, Red for Unavailable
                    borderColor: '#fff',
                    borderWidth: 2
                }]
             };

             // Check if all data points are zero
             const allZero = chartData.datasets[0].data.every(item => item === 0);

             if (allZero) {
                 // Display a message instead of an empty chart
                 availabilityCtx.clearRect(0, 0, availabilityCtx.canvas.width, availabilityCtx.canvas.height);
                 availabilityCtx.textAlign = 'center';
                 availabilityCtx.fillStyle = '#6c757d';
                 availabilityCtx.fillText('No staff availability data found.', availabilityCtx.canvas.width / 2, availabilityCtx.canvas.height / 2);
                 return; // Don't draw the chart
             }

             // Create the new Doughnut chart instance
             availabilityChartInstance = new Chart(availabilityCtx, {
                type: 'doughnut', // Changed type to doughnut
                data: chartData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '60%', // Doughnut hole size (adjust as desired)
                    plugins: {
                        legend: {
                            position: 'bottom', // Legend at the bottom
                            labels: { padding: 15 }
                        },
                        tooltip: {
                             callbacks: {
                                label: function(context) { // Add percentage to tooltip
                                    let label = context.label || '';
                                    if (label) label += ': ';
                                    if (context.parsed !== null) label += context.parsed;
                                     const total = context.dataset.data.reduce((acc, value) => acc + value, 0);
                                     const percentage = total > 0 ? ((context.parsed / total) * 100).toFixed(1) + '%' : '0%';
                                     label += ` (${percentage})`;
                                    return label;
                                }
                            }
                        },
                        title: { display: false } // No separate title needed above chart
                    }
                }
            });

        } // End renderAvailabilityChart function

        // Initial render of the availability chart
        renderAvailabilityChart(availabilityData);
        // --- END UPDATED Staff Availability Doughnut Chart ---

        // Staff Role Bar Chart
        const roleData = <?php echo $roleDataJson; ?>;
        const roleLabels = Object.keys(roleData);
        const roleCounts = Object.values(roleData);
        const roleCtx = document.getElementById('roleChart')?.getContext('2d');

        if (roleCtx && roleLabels.length > 0) { // Only draw if data exists
            new Chart(roleCtx, {
                type: 'bar',
                data: {
                    labels: roleLabels,
                    datasets: [{
                        label: 'Staff Count',
                        data: roleCounts,
                        backgroundColor: [ // Define distinct colors for roles
                            'rgba(0, 123, 255, 0.7)', // Blue (e.g., Doctor)
                            'rgba(25, 135, 84, 0.7)',  // Green (e.g., Nurse)
                            'rgba(108, 117, 125, 0.7)', // Grey (e.g., Admin)
                            'rgba(255, 193, 7, 0.7)'   // Yellow (Add more if needed)
                            // Add more colors if you have more roles
                        ],
                        borderColor: '#fff',
                        borderWidth: 1
                    }]
                },
                options: {
                    indexAxis: 'y', // Make it a horizontal bar chart
                    responsive: true, maintainAspectRatio: false,
                    scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } },
                    plugins: { legend: { display: false } } // Hide legend for single dataset
                }
            });
        } else if (roleCtx) {
             // Optional: Display message if no data for bar chart
             roleCtx.fillText('No role data available.', roleCtx.canvas.width / 2, roleCtx.canvas.height / 2);
        }
    }); // End DOMContentLoaded for charts

    // --- Table Sorting ---
    let sortDirectionsTables = {}; // Use one object to store directions for multiple tables

    function sortTable(tableId, columnIndex, thElement) {
        const table = document.getElementById(tableId);
        if (!table) return;
        const tbody = table.querySelector("tbody");
        const rows = Array.from(tbody.querySelectorAll("tr")); // Get all rows
        if (rows.length <= 1) return; // No need to sort if 0 or 1 data row

        // Use a unique key for the table and column
        const sortKey = `${tableId}-${columnIndex}`;
        const currentDirection = sortDirectionsTables[sortKey] || 0;
        const newDirection = (currentDirection === 1) ? -1 : 1;

        // Reset directions for all headers in *this* table
        table.querySelectorAll("th.clickable").forEach((th, index) => {
            const key = `${tableId}-${index}`;
            th.classList.remove("sort-asc", "sort-desc");
            sortDirectionsTables[key] = (index === columnIndex) ? newDirection : 0;
        });

        // Set class on the clicked header
        if(thElement) {
            thElement.classList.add(newDirection === 1 ? "sort-asc" : "sort-desc");
        }

        // Sort the rows
        rows.sort((rowA, rowB) => {
            const cellA = rowA.cells[columnIndex]?.textContent.trim().toLowerCase() || '';
            const cellB = rowB.cells[columnIndex]?.textContent.trim().toLowerCase() || '';
            const comparison = cellA.localeCompare(cellB, undefined, {numeric: true, sensitivity: 'base'});
            return comparison * newDirection;
        });

        // Re-append sorted rows
        rows.forEach(row => tbody.appendChild(row));
    }


    // --- Scroll to Top Button Logic ---
    const scrollUpBtn = document.getElementById("scrollUpBtn");
    if (scrollUpBtn) {
        window.onscroll = function() { scrollFunction(); };
        function scrollFunction() {
            if (document.body.scrollTop > 100 || document.documentElement.scrollTop > 100) {
                scrollUpBtn.style.display = "block";
            } else {
                scrollUpBtn.style.display = "none";
            }
        }
        function scrollToTop() { window.scrollTo({ top: 0, behavior: 'smooth' }); }
    }

</script>

</body>
</html>
<?php
// Close the database connection at the very end
if (isset($conn) && $conn instanceof mysqli && $conn->thread_id) {
    $conn->close();
}
?>