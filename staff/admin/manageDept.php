<?php
session_start();

// Include the database connection (using mysqli for azwarie_dss)
include 'connection.php'; // This should define $conn using mysqli

// Check if the connection was successful
if (!$conn || $conn->connect_error) {
    // Use die() or log error, but don't show details in production
    die("Database connection failed: " . ($conn ? $conn->connect_error : 'Unknown error'));
}

// Get the current page filename
$current_page = basename($_SERVER['PHP_SELF']);

// Check if the user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

// Retrieve the staff ID from the session or GET parameter (ensure consistency)
$staffID = $_SESSION['staffid'] ?? $_GET['staffid'] ?? null; // Get from session or URL

// Validate staffID (basic example)
if (empty($staffID)) {
     // Redirect to login or show error if staffID is mandatory for this page
     die("Staff ID is missing or invalid.");
}


// Add a searchValue filter for the department name and location
$filters = [
    // Keep these specific filters if you want separate input fields later
    // 'DepartmentID' => isset($_GET['DepartmentID']) ? $_GET['DepartmentID'] : '',
    // 'DepartmentName' => isset($_GET['DepartmentName']) ? $_GET['DepartmentName'] : '',
    // 'Location' => isset($_GET['Location']) ? $_GET['Location'] : '',
    // Combined search value
    'searchValue' => isset($_GET['searchValue']) ? trim($_GET['searchValue']) : '' // Trim whitespace
];

// Initialize query components for mysqli
$whereClauses = [];
$params = []; // Array for parameter values
$paramTypes = ''; // String for parameter types (e.g., 'sss')

// Build WHERE clause and parameters based on filters
if (!empty($filters['searchValue'])) {
    $searchTerm = "%" . strtolower($filters['searchValue']) . "%"; // Prepare search term with wildcards

    $whereClauses[] = "(LOWER(DepartmentID) LIKE ? OR LOWER(DepartmentName) LIKE ? OR LOWER(Location) LIKE ?)";

    // Add the search term 3 times for the 3 conditions
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $paramTypes .= 'sss'; // Three string parameters
}

// Base query to fetch the departments
$query = "SELECT DepartmentID, DepartmentName, Location FROM DEPARTMENT";

// Add WHERE clause if filters are active
if (!empty($whereClauses)) {
    $query .= " WHERE " . implode(" AND ", $whereClauses); // Though only one clause used here currently
}

$query .= " ORDER BY DepartmentID ASC";  // Sort by DepartmentID ascending

// Prepare and execute the query using mysqli
$searchResults = []; // Initialize results array
$query_error = null; // Initialize error variable

try {
    $stmt = $conn->prepare($query);

    if ($stmt === false) {
        throw new Exception("Prepare failed: (" . $conn->errno . ") " . $conn->error);
    }

    // Bind parameters dynamically if any exist
    if (!empty($params)) {
        // Use argument unpacking (...) for bind_param
        if (!$stmt->bind_param($paramTypes, ...$params)) {
             throw new Exception("Binding parameters failed: (" . $stmt->errno . ") " . $stmt->error);
        }
    }

    // Execute the statement
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: (" . $stmt->errno . ") " . $stmt->error);
    }

    // Get the result set
    $result = $stmt->get_result();

    // Fetch all results into an associative array
    if ($result) {
        $searchResults = $result->fetch_all(MYSQLI_ASSOC);
        $result->free(); // Free result memory
    } else {
         throw new Exception("Getting result set failed: (" . $stmt->errno . ") " . $stmt->error);
    }

    // Close the statement
    $stmt->close();

} catch (Exception $e) {
    // Store or log the error
    $query_error = "Database Error: " . $e->getMessage();
    error_log($query_error); // Log detailed error to server logs
    // You might want to set $searchResults to [] here explicitly
    $searchResults = [];
}

// Close the connection (optional, PHP usually handles it, but good practice)
// $conn->close(); // Moved to end of script

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Department Directory</title>
    <!-- DataTables CSS (Remove if not using DataTables JS library) -->
    <!-- <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css"> -->
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700&family=Poppins:wght@400;700&display=swap" rel="stylesheet">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Font Awesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <!-- Link to searchstyle.css (ensure path is correct) -->
    <link rel="stylesheet" href="searchstyle.css">

    <style>
        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
            padding-top: 70px; /* Adjust for sticky navbar height */
            background-color: #f8f9fa; /* Light background for consistency */
        }
        /*** Navbar Adjustments ***/
        .navbar {
            position: sticky; /* Make navbar sticky */
            top: 0;
            z-index: 1030; /* Ensure it stays above */
            background-color: white !important; /* Explicit white background */
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05); /* Softer shadow */
            padding-top: 0.5rem; /* Adjust padding */
            padding-bottom: 0.5rem;
        }
        .navbar-brand {
            font-family: 'Montserrat', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: #007bff !important;
            /* Removed absolute positioning */
        }
         .navbar-toggler {
            border: none; /* Cleaner toggler */
         }
         .navbar-toggler:focus {
            box-shadow: none; /* Remove focus ring */
         }
        .navbar .nav-link {
            color: #495057 !important; /* Slightly darker default link color */
             font-weight: 500;
             transition: color 0.2s ease;
             padding: 0.5rem 1rem; /* Standard padding */
        }
        .navbar .nav-link:hover,
        .navbar .nav-item.active .nav-link {
            color: #007bff !important; /* Blue for hover/active */
        }
        .navbar .nav-item.active .nav-link {
            font-weight: 700 !important; /* Bolder active link */
        }
        /* Dropdown adjustments */
        .navbar .dropdown-menu {
            border-radius: 0.25rem;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
             border: none;
             margin-top: 0.125rem; /* Small gap */
        }
         .navbar .dropdown-item {
             padding: 0.5rem 1rem;
             font-size: 0.95rem;
         }
         .navbar .dropdown-item:active { /* Style for active item within dropdown */
             background-color: #e9ecef;
             color: #212529;
         }
         .navbar .dropdown-item.active { /* Style for current page within dropdown */
             font-weight: bold;
             color: #007bff;
             background-color: transparent; /* No background for active page link */
         }
        .navbar .dropdown-toggle::after {
            border: none;
            content: "\f107";
            font-family: "Font Awesome 6 Free"; /* Use correct FontAwesome 6 family */
            font-weight: 900;
            vertical-align: middle;
            margin-left: 5px;
        }
        /* Hide default dropdown behavior on larger screens for hover */
        @media (min-width: 992px) {
            .navbar .nav-item .dropdown-menu {
                display: block;
                margin-top: 0;
                top: 150%; /* Start hidden below */
                opacity: 0;
                visibility: hidden;
                transition: top 0.3s ease, opacity 0.3s ease, visibility 0.3s;
                 pointer-events: none; /* Prevent interaction when hidden */
            }
            .navbar .nav-item:hover .dropdown-menu {
                top: 100%; /* Move into view */
                visibility: visible;
                opacity: 1;
                 pointer-events: auto; /* Allow interaction when visible */
            }
        }

        /* Hero section styling */
        .hero-section {
            background-color: #007bff;
            color: white;
            padding: 60px 0; /* Reduced padding slightly */
            text-align: center;
            margin-bottom: 30px; /* Add space below hero */
        }
        .hero-section h1 {
            font-family: 'Montserrat', sans-serif;
            font-size: 2.8rem; /* Adjusted size */
            font-weight: 700;
            text-transform: uppercase;
        }
        .hero-section p {
            font-size: 1.1rem;
            opacity: 0.9;
        }
         /* Responsive Hero */
        @media (max-width: 768px) {
            .hero-section { padding: 40px 0; }
            .hero-section h1 { font-size: 2rem; }
            .hero-section p { font-size: 1rem; }
        }

        /* Table Styling */
        #deptTable {
             background-color: white; /* White background for table */
             border-radius: 5px; /* Rounded corners */
             overflow: hidden; /* Needed for rounded corners with striped tables */
             box-shadow: 0 2px 5px rgba(0,0,0,0.05); /* Subtle shadow */
        }
        #deptTable thead th {
            background-color: #e9ecef; /* Light header */
             border-bottom: 2px solid #dee2e6;
             font-weight: 600;
             color: #495057;
        }
         /* Add hover effect */
         #deptTable tbody tr:hover {
             background-color: #f1f7ff; /* Light blue hover */
         }

         /* Sortable header styles */
         th.clickable {
            cursor: pointer;
            position: relative;
            padding-right: 25px !important;
            user-select: none;
        }
        th.clickable:hover {
            background-color: #dde4ed; /* Slightly darker hover for header */
        }
        th.clickable::before,
        th.clickable::after {
            font-family: "Font Awesome 6 Free"; font-weight: 900;
            position: absolute; right: 8px; opacity: 0.25; color: #6c757d;
            transition: opacity 0.2s ease-in-out, color 0.2s ease-in-out;
        }
        th.clickable::before { content: "\f0de"; top: calc(50% - 0.6em); } /* Up arrow */
        th.clickable::after { content: "\f0dd"; top: calc(50% - 0.1em); } /* Down arrow */
        th.clickable.sort-asc::before { opacity: 1; color: #007bff; }
        th.clickable.sort-desc::after { opacity: 1; color: #007bff; }


        /* Style for Scroll to Top Button */
        #scrollUpBtn {
            position: fixed; bottom: 25px; right: 25px;
            display: none; background-color: #007bff; color: white;
            border: none; border-radius: 50%;
            width: 45px; height: 45px; /* Fixed size */
            font-size: 20px; line-height: 45px; /* Center icon */
            text-align: center; /* Center icon */
            cursor: pointer; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            z-index: 1000; transition: background-color 0.2s ease, opacity 0.3s ease;
        }
        #scrollUpBtn:hover { background-color: #0056b3; }

        /* Search Form Specific */
        #searchForm {
             max-width: 600px; /* Limit width of search bar */
             margin-left: auto;
             margin-right: auto;
             margin-bottom: 30px; /* Space below search */
        }
         /* Ensure searchstyle.css is correctly linked and styles .input-container */

        /* No results row styling */
        .no-results td {
             font-weight: bold;
             color: #dc3545; /* Danger color for emphasis */
             background-color: #f8d7da !important; /* Light red background */
        }

    </style>

</head>
<body>
<!-- Navigation Bar Menu -->
<nav class="navbar navbar-expand-lg navbar-light">
    <div class="container">
        <!-- Ensure staffID is passed correctly -->
        <a class="navbar-brand" href="AdminLandingPage.php?staffid=<?php echo htmlspecialchars($staffID); ?>">eDSS</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <!-- Make sure staffID is appended to all links -->
                <li class="nav-item <?php echo ($current_page == 'adminpage.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="adminpage.php?staffid=<?php echo htmlspecialchars($staffID); ?>">Home</a>
                </li>
                <li class="nav-item dropdown <?php echo ($current_page == 'admindashboard.php') ? 'active' : ''; ?>">
                    <a class="nav-link dropdown-toggle" href="#" id="realTimeDashboardsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        Real-Time Dashboards
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="realTimeDashboardsDropdown">
                        <li><a class="dropdown-item <?php echo ($current_page == 'admindashboard.php') ? 'active' : ''; ?>" href="admindashboard.php?staffid=<?php echo htmlspecialchars($staffID); ?>">Dashboard Overview</a></li>
                    </ul>
                </li>
                <li class="nav-item dropdown <?php echo in_array($current_page, ['viewStaff.php', 'registerStaff.php', 'removeStaff.php', 'updateStaff.php']) ? 'active' : ''; ?>">
                    <a class="nav-link dropdown-toggle" href="#" id="manageStaffDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        Manage Staff
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="manageStaffDropdown">
                        <li><a class="dropdown-item <?php echo ($current_page == 'viewStaff.php') ? 'active' : ''; ?>" href="viewStaff.php?staffid=<?php echo htmlspecialchars($staffID); ?>">Staff Directory</a></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'registerStaff.php') ? 'active' : ''; ?>" href="registerStaff.php?staffid=<?php echo htmlspecialchars($staffID); ?>">Register Staff</a></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'updateStaff.php') ? 'active' : ''; ?>" href="updateStaff.php?staffid=<?php echo htmlspecialchars($staffID); ?>">Update Staff</a></li>
                    </ul>
                </li>
                <li class="nav-item <?php echo ($current_page == 'manageDept.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="manageDept.php?staffid=<?php echo htmlspecialchars($staffID); ?>">Department Directory</a>
                </li>
                 <!-- Basic Logout Link -->
                 <li class="nav-item">
                     <a class="nav-link" href="login.php?action=logout">Logout</a>
                 </li>
            </ul>
        </div>
    </div>
</nav>


<!-- Hero Section -->
<div class="hero-section">
    <h1>Department Directory</h1>
    <p>View and Search Department List</p>
</div>

<div class="container mt-4"> <!-- Added container for padding -->

    <!-- Display Query Error if any -->
     <?php if ($query_error): ?>
        <div class="alert alert-danger" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($query_error); ?>
             Please try again later or contact support.
        </div>
     <?php endif; ?>

    <!-- Search Form for Departments -->
    <form method="GET" id="searchForm" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
        <div class="input-container"> <!-- Assuming searchstyle.css styles this -->
            <span class="icon"><i class="fa fa-search"></i></span>
            <input type="text" id="searchValue" name="searchValue" value="<?= htmlspecialchars($filters['searchValue']) ?>" placeholder="Search by ID, Name, or Location...">
            <span id="clearIcon" class="clear-icon" style="<?= !empty($filters['searchValue']) ? 'display: inline;' : 'display: none;' ?>">×</span>
        </div>
        <!-- Keep staffid in the form submission -->
        <input type="hidden" name="staffid" value="<?= htmlspecialchars($staffID) ?>">
         <!-- Optional: Add a submit button if you don't want auto-submit -->
         <!-- <button type="submit" class="btn btn-primary btn-sm ms-2">Search</button> -->
    </form>


    <!-- Department Management Table -->
    <div class="table-responsive"> <!-- Wrap table for horizontal scroll on small screens -->
        <table id="deptTable" class="table table-striped table-hover">
            <thead>
                <tr>
                    <!-- Added sortable classes and onclick -->
                    <th class="clickable" onclick="sortTable(0, this)">Department ID</th>
                    <th class="clickable" onclick="sortTable(1, this)">Department Name</th>
                    <th class="clickable" onclick="sortTable(2, this)">Location</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($searchResults)): ?>
                    <?php foreach ($searchResults as $dept): ?>
                        <tr>
                            <!-- Corrected array keys to match query (lowercase recommended) -->
                            <td><?= htmlspecialchars($dept['DepartmentID'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($dept['DepartmentName'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($dept['Location'] ?? 'N/A') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <!-- Display no results row -->
                    <tr class="no-results">
                        <td colspan="3" class="text-center">
                            No department records found<?php echo !empty($filters['searchValue']) ? ' matching your search criteria.' : '.'; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div> <!-- End table-responsive -->

</div> <!-- End container -->

<!-- Scroll to Top Button -->
<button id="scrollUpBtn" onclick="scrollToTop()" title="Go to top"><i class="fas fa-arrow-up"></i></button>

<!-- Bootstrap JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<!-- Optional: DataTables JS (if you decide to use it for enhanced features) -->
<!-- <script src="https://code.jquery.com/jquery-3.7.0.js"></script> -->
<!-- <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script> -->
<!-- Include main.js if it contains other necessary scripts -->
<!-- <script src="main.js"></script> -->

<script>
    // --- Client-Side Table Sorting ---
    let tableSortDirections = {}; // Store sort directions

    function sortTable(columnIndex, thElement) {
        const table = document.getElementById("deptTable");
        if (!table) return;
        const tbody = table.querySelector("tbody");
        const rows = Array.from(tbody.querySelectorAll("tr:not(.no-results)")); // Exclude 'no results' row
        if (rows.length === 0) return; // No data to sort

        const currentDirection = tableSortDirections[columnIndex] || 0;
        const newDirection = (currentDirection === 1) ? -1 : 1; // Toggle direction

        // Reset directions/classes for other columns
        table.querySelectorAll("th.clickable").forEach((th, index) => {
            th.classList.remove("sort-asc", "sort-desc");
            tableSortDirections[index] = (index === columnIndex) ? newDirection : 0;
        });
        thElement.classList.add(newDirection === 1 ? "sort-asc" : "sort-desc");

        // Sort rows
        rows.sort((rowA, rowB) => {
            const cellA = rowA.cells[columnIndex]?.textContent.trim().toLowerCase() || '';
            const cellB = rowB.cells[columnIndex]?.textContent.trim().toLowerCase() || '';
            // Use localeCompare for potentially better string/numeric sorting
            const comparison = cellA.localeCompare(cellB, undefined, {numeric: true, sensitivity: 'base'});
            return comparison * newDirection;
        });

        // Re-append sorted rows
        rows.forEach(row => tbody.appendChild(row));
    }

    // --- Scroll to Top Button Logic ---
    const scrollUpBtn = document.getElementById("scrollUpBtn");
    if (scrollUpBtn) {
        window.onscroll = function() {
            scrollFunction();
        };

        function scrollFunction() {
            if (document.body.scrollTop > 100 || document.documentElement.scrollTop > 100) {
                scrollUpBtn.style.display = "block";
            } else {
                scrollUpBtn.style.display = "none";
            }
        }

        function scrollToTop() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        // Attach function to button (already done in HTML, but good practice)
        // scrollUpBtn.addEventListener('click', scrollToTop);
    }


   // --- Search Input Clear Icon Logic ---
    const searchInput = document.getElementById("searchValue");
    const clearIcon = document.getElementById("clearIcon");
    const searchForm = document.getElementById("searchForm");

    if (searchInput && clearIcon && searchForm) {
        // Show/hide clear icon based on input
        searchInput.addEventListener("input", function () {
            if (this.value) {
                clearIcon.style.display = "inline";
            } else {
                clearIcon.style.display = "none";
            }
            // Optional: Auto-submit form on input change (can be noisy)
            // clearTimeout(this.searchTimeout);
            // this.searchTimeout = setTimeout(() => { searchForm.submit(); }, 500); // Debounce
        });

        // Clear input and submit form when 'X' is clicked
        clearIcon.addEventListener("click", function () {
            searchInput.value = "";       // Clear the input field
            clearIcon.style.display = "none"; // Hide the icon
            searchInput.focus();          // Optional: refocus the input
            searchForm.submit();        // Submit the form to refresh results
        });
    }

    // --- Modal Logic (If you uncomment the Add Department feature) ---
    /*
    const addDeptForm = document.getElementById('addDepartmentForm');
    const addDeptModalElement = document.getElementById('addDepartmentModal');
    if (addDeptForm && addDeptModalElement) {
        addDeptForm.addEventListener('submit', function(event) {
            event.preventDefault(); // Stop default submission

            const formData = new FormData(this);
            const addDeptModal = bootstrap.Modal.getInstance(addDeptModalElement); // Get modal instance

            // You would typically send this via fetch to a separate PHP handler
            // For now, just logging and closing
            console.log("Submitting:", Object.fromEntries(formData));
            alert('Department added (simulated). Page will reload.'); // Placeholder alert

            addDeptModal.hide(); // Hide modal

            // Reload after a short delay to allow modal to close smoothly
            setTimeout(() => { window.location.reload(); }, 500);

            // Example using fetch (replace 'add_department_handler.php' with your actual handler URL)
            /*
            fetch('add_department_handler.php', { // Assumes a separate PHP file handles the INSERT
                method: 'POST',
                body: formData
            })
            .then(response => response.json()) // Assuming handler returns JSON {success: true/false, message: ...}
            .then(data => {
                if (data.success) {
                    alert('Department added successfully!');
                    addDeptModal.hide();
                    setTimeout(() => { window.location.reload(); }, 500);
                } else {
                    alert('Error adding department: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error submitting form:', error);
                alert('An error occurred while submitting the form.');
            });
            */
    /*
        });
    }
    */

</script>

</body>
</html>
<?php
// Close the database connection at the very end
if (isset($conn) && $conn instanceof mysqli && $conn->thread_id) {
    $conn->close();
}
?>