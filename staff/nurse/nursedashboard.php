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

// Query to get total staff count grouped by status
$sql = "SELECT status, COUNT(*) AS count FROM staff GROUP BY status";
$stmt = $pdo->prepare($sql);
$stmt->execute();

$data = [
    "Available" => 0,
    "Unavailable" => 0
];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $data[$row['status']] = (int)$row['count'];
}

// Query to get total staff count (active + inactive)
$sql_total_staff = "SELECT COUNT(*) AS total_staff FROM staff";
$stmt_total_staff = $pdo->prepare($sql_total_staff);
$stmt_total_staff->execute();
$total_staff = $stmt_total_staff->fetch(PDO::FETCH_ASSOC)['total_staff'];

// Query to get staff count by status (available and unavailable)
$sql_availability = "SELECT status, COUNT(*) AS count FROM staff GROUP BY status";
$stmt_availability = $pdo->prepare($sql_availability);
$stmt_availability->execute();

$availability_data = [
    "Available" => 0,
    "Unavailable" => 0
];

while ($row = $stmt_availability->fetch(PDO::FETCH_ASSOC)) {
    $availability_data[$row['status']] = (int)$row['count'];
}

// Query to get staff count by role (e.g., doctors, nurses, admin)
$sql_role_breakdown = "SELECT role, COUNT(*) AS count FROM staff GROUP BY role";
$stmt_role_breakdown = $pdo->prepare($sql_role_breakdown);
$stmt_role_breakdown->execute();

$role_data = [];
while ($row = $stmt_role_breakdown->fetch(PDO::FETCH_ASSOC)) {
    $role_data[$row['role']] = (int)$row['count'];

    // Query to count available and unavailable nurses
    $sql_nurse_counts = "
    SELECT 
        SUM(CASE WHEN Status = 'Available' THEN 1 ELSE 0 END) AS available_nurses,
        SUM(CASE WHEN Status = 'Unavailable' THEN 1 ELSE 0 END) AS unavailable_nurses
    FROM STAFF 
    WHERE Role = 'NURSE'";
    $stmt_nurse_counts = $pdo->prepare($sql_nurse_counts);
    $stmt_nurse_counts->execute();
    $nurse_counts = $stmt_nurse_counts->fetch(PDO::FETCH_ASSOC);

    // Query to count available and unavailable doctors
    $sql_doctor_counts = "
    SELECT 
        SUM(CASE WHEN Status = 'Available' THEN 1 ELSE 0 END) AS available_doctors,
        SUM(CASE WHEN Status = 'Unavailable' THEN 1 ELSE 0 END) AS unavailable_doctors
    FROM STAFF 
    WHERE Role = 'DOCTOR'";
    $stmt_doctor_counts = $pdo->prepare($sql_doctor_counts);
    $stmt_doctor_counts->execute();
    $doctor_counts = $stmt_doctor_counts->fetch(PDO::FETCH_ASSOC);

}

// $sql = "SELECT * FROM AUDIT_TRAIL ORDER BY Timestamp DESC";
// $stmt = $pdo->query($sql);

// $days = 3; // Number of days to keep logs

// try {
//     // Delete logs older than $days days
//     $sql = "DELETE FROM AUDIT_TRAIL WHERE Timestamp < NOW() - INTERVAL :days DAY";
//     $stmt = $pdo->prepare($sql);
//     $stmt->bindValue(':days', $days, PDO::PARAM_INT);
//     $stmt->execute();

//     echo "Old audit logs deleted successfully.";
// } catch (PDOException $e) {
//     echo "Error deleting audit logs: " . $e->getMessage();
// }

// Query to fetch available nurses
$sql_available_nurses = "
    SELECT s.StaffID, s.Name, s.Gender, s.PhoneNo, s.Role, s.Status, d.DepartmentName 
    FROM STAFF s
    JOIN DEPARTMENT d ON s.DepartmentID = d.DepartmentID
    WHERE s.Status = 'Available' AND s.Role = 'NURSE'
    ORDER BY s.Name";
$stmt_available_nurses = $pdo->prepare($sql_available_nurses);
$stmt_available_nurses->execute();
$available_nurses = $stmt_available_nurses->fetchAll(PDO::FETCH_ASSOC);

// Query to fetch available doctors
$sql_available_doctors = "
    SELECT s.StaffID, s.Name, s.Gender, s.PhoneNo, s.Role, s.Status, d.DepartmentName 
    FROM STAFF s
    JOIN DEPARTMENT d ON s.DepartmentID = d.DepartmentID
    WHERE s.Status = 'Available' AND s.Role = 'DOCTOR'
    ORDER BY s.Name";
$stmt_available_doctors = $pdo->prepare($sql_available_doctors);
$stmt_available_doctors->execute();
$available_doctors = $stmt_available_doctors->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nurse - Staff Analysis</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Include the card style -->
    <link rel="stylesheet" href="cardstyle.css">
    <link href="https://cdn.jsdelivr.net/npm/remixicon/fonts/remixicon.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700&family=Poppins:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css"> <!-- FontAwesome for icons -->
    <!-- Bootstrap JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
        }
        .clickable {
            cursor: pointer;
        }
        .clickable:hover {
            text-decoration: underline; /* Underline on hover */
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
        .page-title {
            text-align: center;
            font-size: 2rem;
            color: black;
            margin: 20px 0;
        }
           /* Flex container for charts */
        .charts-flex-container {
            display: flex;
            flex-wrap: wrap; /* Allow wrapping on smaller screens */
            justify-content: center; /* Center charts horizontally */
            align-items: center; /* Center charts vertically */
            gap: 20px; /* Space between charts */
            margin: 20px auto;
            max-width: 1100px; /* Adjust as needed */
            height: 400px; /* Fixed height for the container */
        }

        /* Chart container styling */
        .chart-container {
            flex: 1; /* Allow charts to grow and take equal space */
            min-width: 300px; /* Minimum width for each chart */
            max-width: 400px; /* Maximum width for each chart */
            border: 1px solid #ddd; /* Border for separation */
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            padding: 15px;
            display: flex;
            flex-direction: column;
            justify-content: center; /* Center content vertically */
            align-items: center; /* Center content horizontally */
            background: #fff; /* Background color for the container */
            height: 100%; /* Ensure the container takes full height */
        }

        /* Canvas styling */
        .chart-container canvas {
            max-width: 100%; /* Ensure canvas fits within the container */
            max-height: 100%; /* Ensure canvas fits within the container */
            width: 100% !important; /* Force canvas to take full width */
            height: auto !important; /* Maintain aspect ratio */
        }

        /* Responsive behavior */
        @media (max-width: 768px) {
            .charts-flex-container {
                flex-direction: column; /* Stack charts vertically on smaller screens */
                height: auto; /* Allow height to adjust */
            }
            .chart-container {
                min-width: 100%; /* Full width on smaller screens */
                max-width: 100%; /* Full width on smaller screens */
            }
        }
        /* Hero section styling */
        .hero-section {
            background-color: #007bff;
            color: white;
            padding: 80px 0;
            text-align: center;
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
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: center;
        }
        th {
            background-color: #f4f4f4;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        tr:hover {
            background-color: #f1f1f1;
        }
      
        /* Pagination Buttons */
        .btn-outline-primary {
            margin: 0 2px;
        }
        /* Disabled Ellipsis */
        .btn-outline-primary.disabled {
            pointer-events: none;
            opacity: 0.6;
        }
        /* Rows Per Page Dropdown */
        .form-inline {
            display: flex;
            align-items: center;
        }
        .form-inline label {
            margin-right: 10px;
        }
        .form-inline select {
            width: auto;
        }
        .card {
            margin-top: 20px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            border-radius: 10px;
        }
        .card-body {
            padding: 20px;
            font-family: 'Montserrat', sans-serif; /* Ensures font consistency */
        }

            .card-text {
                font-size: 1.5rem;
                font-weight: bold;
            }

            .card-title {
                font-size: 1.25rem;
                font-weight: 700;
            }

            .card.border-primary {
                border-left: 5px solid #007bff;
            }

            .card.border-success {
                border-left: 5px solid #28a745;
            }

            .card.border-secondary {
                border-left: 5px solid #6c757d;
            }

            .card.border-info {
                border-left: 5px solid #17a2b8;
            }

            .card.border-warning {
                border-left: 5px solid #ffc107;
            }

        .card.border-lightgrey {
            border-left: 5px solid #808080; /* Dark grey color */
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1); /* Consistent shadow */
            border-radius: 10px; /* Matching the other cards */
        }

        .card.border-green {
            border-left: 5px solid #28a745; /* Green color */
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1); /* Consistent shadow */
            border-radius: 10px; /* Matching the other cards */
        }

            @media (max-width: 768px) {
                .card {
                    margin-bottom: 20px;
                    color: black;
                } 
                
            }
    </style>
</head>
<body>  
<!-- Navigation Bar Menu -->
<nav class="navbar navbar-expand-lg navbar-light" style="background-color: white; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
    <div class="container">
        <a class="navbar-brand" href="https://172.20.238.6/staff2/nurse/NurseLandingPage.php?staffid=<?php echo $staffID; ?>"  style="font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 700; color: #007bff; position: absolute; top: 10px; left: 15px;">WeCare</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation"   style="margin-left: 65px;">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
            <li class="nav-item <?php echo ($current_page == 'https://172.20.238.6/staff2/nurse/nursepage.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="https://172.20.238.6/staff2/nurse/nursepage.php?staffid=<?php echo $staffID; ?>"  style="color: black;">Home</a>
                </li>
            <li class="nav-item <?php echo ($current_page == 'nursedashboard.php') ? 'active' : ''; ?>">
                <a class="nav-link" href="https://172.20.238.6/staff2/nurse/nursedashboard.php?staffid=<?php echo $staffID; ?>" style="color: black;">Real-Time Dashboards</a>
            </li>
            <li class="nav-item <?php echo ($current_page == 'https://172.20.238.6/staff2/nurse/nurseliststaff.php') ? 'active' : ''; ?>">
                <a class="nav-link" href="https://172.20.238.6/staff2/nurse/nurseliststaff.php?staffid=<?php echo $staffID; ?>" style="color: black;">Staff Directory</a>
            </li>
            <li class="nav-item <?php echo ($current_page == 'https://172.20.238.6/staff2/nurse/nurselistdept.php') ? 'active' : ''; ?>">
                <a class="nav-link" href="https://172.20.238.6/staff2/nurse/nurselistdept.php?staffid=<?php echo $staffID; ?>" style="color: black;">Department Directory</a>
            </li>
            <li class="nav-item <?php echo ($current_page == 'https://172.20.238.6/staff2/nurse/nurseprofile.php') ? 'active' : ''; ?>">
                <a class="nav-link" href="https://172.20.238.6/staff2/nurse/nurseprofile.php?staffid=<?php echo $staffID; ?>" style="color: black;">Profile</a>
            </li>
            </ul>
        </div>
    </div>
</nav>
<!-- Hero Section -->
<div class="hero-section">
    <h1>Staff Analysis</h1>
    <p>View Statistics</p>
</div>

<!-- Your dashboard content goes here -->
<div class="container mt-4">
    <div class="charts-flex-container">
        <!-- Total Staff Card -->
        <div class="row">
            <div class="col-md-4">
                <div class="card border-primary">
                    <div class="card-body">
                        <h5 class="card-title">Total Staff</h5>
                        <p class="card-text"><?= $total_staff; ?></p>
                    </div>
                </div>
            </div>

            <!-- Available Nurses Card -->
            <div class="col-md-4">
                <div class="card border-success">
                    <div class="card-body">
                        <h5 class="card-title">Available Nurses</h5>
                        <p class="card-text"><?= $nurse_counts['available_nurses']; ?></p>
                    </div>
                </div>
            </div>

            <!-- Available Doctors Card -->
            <div class="col-md-4">
                <div class="card border-secondary">
                    <div class="card-body">
                        <h5 class="card-title">Available Doctors</h5>
                        <p class="card-text"><?= $doctor_counts['available_doctors']; ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="row">
            <!-- Pie Chart Container -->
            <div class="col-md-6">
                <div class="chart-container">
                    <h1 class="page-title">Staff Availability</h1>
                    <canvas id="pieChart" width="600" height="200"></canvas>
                </div>
            </div>

            <!-- Bar Chart Container -->
            <div class="col-md-6">
                <div class="chart-container">
                    <h1 class="page-title">Total Staff By Role</h1>
                    <canvas id="roleChart" width="500" height="400"></canvas>
                </div>
            </div>
        </div>

        <!-- Available Nurses Table -->
        <div class="container mt-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-primary text-white">
                    <h1 class="card-title">List Of Available Nurses</h1>
                </div>
                <div class="card-body">
                    <table id="nurseTable" class="table table-striped">
                        <thead>
                            <tr>
                                <th class="clickable" onclick="sortTable('nurseTable', 0)">Staff ID</th>
                                <th class="clickable" onclick="sortTable('nurseTable', 1)">Name</th>
                                <th class="clickable" onclick="sortTable('nurseTable', 2)">Gender</th>
                                <th class="clickable" onclick="sortTable('nurseTable', 3)">Phone No</th>
                                <th class="clickable" onclick="sortTable('nurseTable', 4)">Status</th>
                                <th class="clickable" onclick="sortTable('nurseTable', 5)">Department Name</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($available_nurses as $nurse): ?>
                                <tr>
                                    <td><?= htmlspecialchars($nurse['staffid']); ?></td>
                                    <td><?= htmlspecialchars($nurse['name']); ?></td>
                                    <td><?= htmlspecialchars($nurse['gender']); ?></td>
                                    <td><?= htmlspecialchars($nurse['phoneno']); ?></td>
                                    <td><?= htmlspecialchars($nurse['status']); ?></td>
                                    <td><?= htmlspecialchars($nurse['departmentname']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Available Doctors Table -->
        <div class="container mt-4">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h1 class="card-title">List Of Available Doctors</h1>
                </div>
                <div class="card-body">
                    <table id="doctorTable" class="table table-striped">
                        <thead>
                            <tr>
                                <th class="clickable" onclick="sortTable('doctorTable', 0)">Staff ID</th>
                                <th class="clickable" onclick="sortTable('doctorTable', 1)">Name</th>
                                <th class="clickable" onclick="sortTable('doctorTable', 2)">Gender</th>
                                <th class="clickable" onclick="sortTable('doctorTable', 3)">Phone No</th>
                                <th class="clickable" onclick="sortTable('doctorTable', 4)">Status</th>
                                <th class="clickable" onclick="sortTable('doctorTable', 5)">Department Name</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($available_doctors as $doctor): ?>
                                <tr>
                                    <td><?= htmlspecialchars($doctor['staffid']); ?></td>
                                    <td><?= htmlspecialchars($doctor['name']); ?></td>
                                    <td><?= htmlspecialchars($doctor['gender']); ?></td>
                                    <td><?= htmlspecialchars($doctor['phoneno']); ?></td>
                                    <td><?= htmlspecialchars($doctor['status']); ?></td>
                                    <td><?= htmlspecialchars($doctor['departmentname']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- Scroll to Top Button -->
<button id="scrollUpBtn" onclick="scrollToTop()" class="fa fa-long-arrow-up"></button>                
</div>
</section> 

<script>

function sortTable(columnIndex) {
        const table = document.getElementById("doctorTable", "nurseTable");
        const rows = Array.from(table.rows).slice(1); // Exclude the header row
        const ascending = table.getAttribute("data-sort") === "asc";

        // Sort rows based on the column content
        rows.sort((a, b) => {
            const aText = a.cells[columnIndex].textContent.trim().toLowerCase();
            const bText = b.cells[columnIndex].textContent.trim().toLowerCase();
            return ascending ? aText.localeCompare(bText) : bText.localeCompare(aText);
        });

        rows.forEach(row => table.tBodies[0].appendChild(row)); // Reorder rows in DOM
        table.setAttribute("data-sort", ascending ? "desc" : "asc"); // Toggle sort order
    }

    // Staff Availability Data from PHP
    const availabilityData = <?php echo json_encode($availability_data); ?>;
    const availabilityLabels = Object.keys(availabilityData);
    const availabilityCounts = Object.values(availabilityData);

    // Pie Chart for Staff Availability
    const pieCtx = document.getElementById('pieChart').getContext('2d');
    new Chart(pieCtx, {
        type: 'pie',
        data: {
            labels: availabilityLabels,
            datasets: [{
                label: 'Staff Availability',
                data: availabilityCounts,
                backgroundColor: ['#B78700', '#78706E'],
                borderColor: ['#ffffff', '#ffffff'],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'top'
                }
            }
        }
    });

    // Staff Breakdown by Role Data from PHP
    const roleData = <?php echo json_encode($role_data); ?>;
    const roleLabels = Object.keys(roleData);
    const roleCounts = Object.values(roleData);

    // Bar Chart for Staff Breakdown by Role
    const roleCtx = document.getElementById('roleChart').getContext('2d');
    new Chart(roleCtx, {
    type: 'bar',
    data: {
        labels: roleLabels,
        datasets: [{
            label: 'Staff Breakdown by Role',
            data: roleCounts,
            backgroundColor: [
                '#2A0134',  // Purple
                '#800000', // Blue
                '#000435', // Pink
          
            ],
            borderColor: '#ffffff', // White border
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: {
                beginAtZero: true
            }
        },
        plugins: {
            legend: {
                display: false
            }
        }
    }
});
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

    function sortTable(tableId, columnIndex) {
        const table = document.getElementById(tableId);
        const rows = Array.from(table.querySelectorAll("tbody tr"));
        const isAscending = table.querySelector(`th.clickable.asc[data-column="${columnIndex}"]`);

        // Remove existing sorting indicators
        table.querySelectorAll("th.clickable").forEach(th => th.classList.remove("asc", "desc"));

        // Sort rows based on the column content
        rows.sort((a, b) => {
            const aValue = a.cells[columnIndex].textContent.trim();
            const bValue = b.cells[columnIndex].textContent.trim();

            if (!isNaN(aValue) && !isNaN(bValue)) {
                return isAscending ? bValue - aValue : aValue - bValue; // Numeric comparison
            } else {
                return isAscending ? bValue.localeCompare(aValue) : aValue.localeCompare(bValue); // String comparison
            }
        });

        // Toggle sorting order
        const clickedHeader = table.querySelectorAll("th.clickable")[columnIndex];
        clickedHeader.classList.toggle("asc", !isAscending);
        clickedHeader.classList.toggle("desc", isAscending);

        // Rebuild the table with sorted rows
        const tbody = table.querySelector("tbody");
        tbody.innerHTML = "";
        rows.forEach(row => tbody.appendChild(row));
    }
    </script>
</body>
</html>