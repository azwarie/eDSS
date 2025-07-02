<?php
session_start();

// Include the database connection (mysqli for azwarie_dss)
include 'connection.php'; // This should define $conn using mysqli

// Check if the connection was successful
if (!$conn || $conn->connect_error) {
    die("Database connection failed: " . ($conn ? $conn->connect_error : 'Unknown error'));
}

// Get the current page filename
$current_page = basename($_SERVER['PHP_SELF']);

// Check if the user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

// Retrieve the staff ID from the session or GET parameter
$staffID = $_SESSION['staffid'] ?? $_GET['staffid'] ?? null;

// Validate staffID
if (empty($staffID)) {
     die("Staff ID is missing or invalid.");
}


// Initialize filter variables with default empty values
$filters = [
    // Specific filters (if you had separate inputs)
    // 'StaffID' => isset($_GET['StaffID']) ? $_GET['StaffID'] : '',
    // 'Name' => isset($_GET['Name']) ? $_GET['Name'] : '',
    // ... other specific filters ...
    // Combined search value
    'searchValue' => isset($_GET['searchValue']) ? trim($_GET['searchValue']) : ''
];

// Initialize query components for mysqli
$whereClauses = [];
$params = [];       // Array for parameter values
$paramTypes = '';   // String for parameter types

// Build WHERE clause and parameters for combined search
if (!empty($filters['searchValue'])) {
    $searchTermLower = "%" . strtolower($filters['searchValue']) . "%"; // Prepare lowercased search term with wildcards

    $whereClauses[] = "(LOWER(s.StaffID) LIKE ? OR
                        LOWER(s.Name) LIKE ? OR
                        LOWER(s.IC) LIKE ? OR
                        LOWER(s.Gender) LIKE ? OR
                        LOWER(s.Role) LIKE ? OR
                        LOWER(s.Status) LIKE ? OR
                        LOWER(d.DepartmentName) LIKE ?)"; // Include DepartmentName in search

    // Add the search term 7 times (once for each condition)
    for ($i = 0; $i < 7; $i++) {
        $params[] = $searchTermLower;
        $paramTypes .= 's'; // String type for all LIKE comparisons
    }
}

// Construct the base SQL query with JOIN
$query = "
    SELECT s.StaffID, s.Name, s.Gender, s.PhoneNo, s.Role, s.Status, d.DepartmentName
    FROM STAFF s
    LEFT JOIN DEPARTMENT d ON s.DepartmentID = d.DepartmentID
    "; // Removed WHERE 1=1, WHERE clause added conditionally below

// Append dynamic WHERE clauses based on combined search
if (!empty($whereClauses)) {
    // Note: Currently only handles the 'searchValue' clause
    $query .= " WHERE " . implode(" AND ", $whereClauses);
}

$query .= " ORDER BY s.StaffID ASC"; // Sort results by StaffID Ascending

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
    $query_error = "Database Error: " . $e->getMessage();
    error_log($query_error); // Log detailed error
    $searchResults = []; // Ensure results array is empty on error
}

// Connection closed at the end of the script
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Staff Directory</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700&family=Poppins:wght@400;700&display=swap" rel="stylesheet">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Font Awesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <!-- Link to searchstyle.css -->
    <link rel="stylesheet" href="searchstyle.css">

    <style>
        /* Copied relevant styles from manageDept.php for consistency */
        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
            padding-top: 70px;
            background-color: #f8f9fa;
        }
        .navbar {
            position: sticky; top: 0; z-index: 1030;
            background-color: white !important;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            padding-top: 0.5rem; padding-bottom: 0.5rem;
        }
        .navbar-brand {
            font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 700;
            color: #007bff !important;
        }
        .navbar-toggler { border: none; }
        .navbar-toggler:focus { box-shadow: none; }
        .navbar .nav-link {
            color: #495057 !important; font-weight: 500;
            transition: color 0.2s ease; padding: 0.5rem 1rem;
        }
        .navbar .nav-link:hover, .navbar .nav-item.active .nav-link { color: #007bff !important; }
        .navbar .nav-item.active .nav-link { font-weight: 700 !important; }
        .navbar .dropdown-menu {
            border-radius: 0.25rem; box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            border: none; margin-top: 0.125rem;
        }
        .navbar .dropdown-item { padding: 0.5rem 1rem; font-size: 0.95rem; }
        .navbar .dropdown-item:active { background-color: #e9ecef; color: #212529; }
        .navbar .dropdown-item.active { font-weight: bold; color: #007bff; background-color: transparent; }
        .navbar .dropdown-toggle::after {
            border: none; content: "\f107"; font-family: "Font Awesome 6 Free";
            font-weight: 900; vertical-align: middle; margin-left: 5px;
        }
        @media (min-width: 992px) { /* Dropdown hover on desktop */
            .navbar .nav-item .dropdown-menu {
                display: block; margin-top: 0; top: 150%; opacity: 0; visibility: hidden;
                transition: top 0.3s ease, opacity 0.3s ease, visibility 0.3s; pointer-events: none;
            }
            .navbar .nav-item:hover .dropdown-menu {
                top: 100%; visibility: visible; opacity: 1; pointer-events: auto;
            }
        }
        .hero-section {
            background-color: #007bff; color: white; padding: 60px 0;
            text-align: center; margin-bottom: 30px;
        }
        .hero-section h1 {
            font-family: 'Montserrat', sans-serif; font-size: 2.8rem;
            font-weight: 700; text-transform: uppercase;
        }
        .hero-section p { font-size: 1.1rem; opacity: 0.9; }
        @media (max-width: 768px) { /* Responsive Hero */
            .hero-section { padding: 40px 0; }
            .hero-section h1 { font-size: 2rem; }
            .hero-section p { font-size: 1rem; }
        }
        #staffTable { /* Table Styles */
             background-color: white; border-radius: 5px; overflow: hidden;
             box-shadow: 0 2px 5px rgba(0,0,0,0.05); font-size: 0.95rem; /* Slightly smaller font */
        }
        #staffTable thead th {
            background-color: #e9ecef; border-bottom: 2px solid #dee2e6;
            font-weight: 600; color: #495057; white-space: nowrap; /* Prevent header wrapping */
        }
        #staffTable tbody tr:hover { background-color: #f1f7ff; }
        th.clickable { /* Sortable header styles */
            cursor: pointer; position: relative; padding-right: 25px !important; user-select: none;
        }
        th.clickable:hover { background-color: #dde4ed; }
        th.clickable::before, th.clickable::after {
            font-family: "Font Awesome 6 Free"; font-weight: 900; position: absolute; right: 8px;
            opacity: 0.25; color: #6c757d; transition: opacity 0.2s ease, color 0.2s ease;
        }
        th.clickable::before { content: "\f0de"; top: calc(50% - 0.6em); } /* Up arrow */
        th.clickable::after { content: "\f0dd"; top: calc(50% - 0.1em); } /* Down arrow */
        th.clickable.sort-asc::before { opacity: 1; color: #007bff; }
        th.clickable.sort-desc::after { opacity: 1; color: #007bff; }
        #scrollUpBtn { /* Scroll Button */
            position: fixed; bottom: 25px; right: 25px; display: none;
            background-color: #007bff; color: white; border: none; border-radius: 50%;
            width: 45px; height: 45px; font-size: 20px; line-height: 45px;
            text-align: center; cursor: pointer; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            z-index: 1000; transition: background-color 0.2s ease, opacity 0.3s ease;
        }
        #scrollUpBtn:hover { background-color: #0056b3; }
        #searchForm { /* Search Form */
             max-width: 600px; margin: 0 auto 30px auto;
        }
         /* Assuming searchstyle.css provides .input-container and related styles */
        .no-results td { /* No results row */
             font-weight: bold; color: #dc3545; background-color: #f8d7da !important;
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
                <!-- Updated Navbar Links with staffid -->
                 <li class="nav-item <?php echo ($current_page == 'adminpage.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="adminpage.php?staffid=<?php echo htmlspecialchars($staffID); ?>">Home</a>
                </li>
                <li class="nav-item dropdown <?php echo in_array($current_page, ['admindashboard.php']) ? 'active' : ''; ?>">
                    <a class="nav-link dropdown-toggle" href="#" id="dashboardsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        Real-Time Dashboards
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="dashboardsDropdown">
                        <li><a class="dropdown-item <?php echo ($current_page == 'admindashboard.php') ? 'active' : ''; ?>" href="admindashboard.php?staffid=<?php echo htmlspecialchars($staffID); ?>">Dashboard Overview</a></li>
                    </ul>
                </li>
                 <li class="nav-item dropdown <?php echo in_array($current_page, ['viewStaff.php', 'registerStaff.php', 'removeStaff.php', 'updateStaff.php']) ? 'active' : ''; ?>">
                    <a class="nav-link dropdown-toggle" href="#" id="staffDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        Manage Staff
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="staffDropdown">
                        <li><a class="dropdown-item <?php echo ($current_page == 'viewStaff.php') ? 'active' : ''; ?>" href="viewStaff.php?staffid=<?php echo htmlspecialchars($staffID); ?>">Staff Directory</a></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'registerStaff.php') ? 'active' : ''; ?>" href="registerStaff.php?staffid=<?php echo htmlspecialchars($staffID); ?>">Register Staff</a></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'updateStaff.php') ? 'active' : ''; ?>" href="updateStaff.php?staffid=<?php echo htmlspecialchars($staffID); ?>">Update Staff</a></li>
                    </ul>
                </li>
                 <li class="nav-item <?php echo ($current_page == 'manageDept.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="manageDept.php?staffid=<?php echo htmlspecialchars($staffID); ?>">Department Directory</a>
                </li>
                 <li class="nav-item">
                     <a class="nav-link" href="login.php?action=logout">Logout</a>
                 </li>
            </ul>
        </div>
    </div>
</nav>

<!-- Hero Section -->
<div class="hero-section">
    <h1>Staff Directory</h1>
    <p>View and Search Staff Information</p>
</div>

<div class="container mt-4"> <!-- Added container -->

     <!-- Display Query Error if any -->
     <?php if ($query_error): ?>
        <div class="alert alert-danger" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($query_error); ?>
             Please try again later or contact support.
        </div>
     <?php endif; ?>

    <!-- Search Form for Staff -->
    <form method="GET" id="searchForm" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
        <div class="input-container"> <!-- Styled by searchstyle.css -->
            <span class="icon"><i class="fa fa-search"></i></span>
            <input type="text" id="searchValue" name="searchValue" value="<?= htmlspecialchars($filters['searchValue']) ?>" placeholder="Search by ID, Name, IC, Gender, Role, Status, Dept...">
            <span id="clearIcon" class="clear-icon" style="<?= !empty($filters['searchValue']) ? 'display: inline;' : 'display: none;' ?>">×</span>
        </div>
        <!-- Keep staffid in the form submission -->
        <input type="hidden" name="staffid" value="<?= htmlspecialchars($staffID) ?>">
    </form>


    <!-- Staff Table -->
    <div class="table-responsive">
        <table id="staffTable" class="table table-striped table-hover">
            <thead>
                <tr>
                    <!-- Added sortable classes and onclick -->
                    <th class="clickable" onclick="sortTable(0, this)">Staff ID</th>
                    <th class="clickable" onclick="sortTable(1, this)">Name</th>
                    <th class="clickable" onclick="sortTable(2, this)">Gender</th>
                    <th class="clickable" onclick="sortTable(3, this)">Phone No</th>
                    <th class="clickable" onclick="sortTable(4, this)">Role</th>
                    <th class="clickable" onclick="sortTable(5, this)">Status</th>
                    <th class="clickable" onclick="sortTable(6, this)">Department</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($searchResults)): ?>
                    <?php foreach ($searchResults as $staff): ?>
                        <tr>
                            <!-- Use null coalescing for safety -->
                            <td><?= htmlspecialchars($staff['StaffID'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($staff['Name'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($staff['Gender'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($staff['PhoneNo'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($staff['Role'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($staff['Status'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($staff['DepartmentName'] ?? 'N/A') ?></td>
                            <!-- Removed empty extra <td> -->
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <!-- Display no results row -->
                    <tr class="no-results">
                        <td colspan="7" class="text-center">
                            No staff records found<?php echo !empty($filters['searchValue']) ? ' matching your search criteria.' : '.'; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div> <!-- end table-responsive -->

</div> <!-- end container -->

<!-- Scroll to Top Button -->
<button id="scrollUpBtn" onclick="scrollToTop()" title="Go to top"><i class="fas fa-arrow-up"></i></button>

<!-- Bootstrap JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<!-- Optional: DataTables JS -->
<!-- <script src="https://code.jquery.com/jquery-3.7.0.js"></script> -->
<!-- <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script> -->
<!-- Optional: main.js -->
<!-- <script src="main.js"></script> -->

<script>
    // --- Client-Side Table Sorting ---
    let tableSortDirectionsStaff = {}; // Use a unique name

    function sortTable(columnIndex, thElement) {
        const table = document.getElementById("staffTable");
        if (!table) return;
        const tbody = table.querySelector("tbody");
        const rows = Array.from(tbody.querySelectorAll("tr:not(.no-results)"));
        if (rows.length === 0) return;

        const currentDirection = tableSortDirectionsStaff[columnIndex] || 0;
        const newDirection = (currentDirection === 1) ? -1 : 1;

        table.querySelectorAll("th.clickable").forEach((th, index) => {
            th.classList.remove("sort-asc", "sort-desc");
            tableSortDirectionsStaff[index] = (index === columnIndex) ? newDirection : 0;
        });
        thElement.classList.add(newDirection === 1 ? "sort-asc" : "sort-desc");

        rows.sort((rowA, rowB) => {
            const cellA = rowA.cells[columnIndex]?.textContent.trim().toLowerCase() || '';
            const cellB = rowB.cells[columnIndex]?.textContent.trim().toLowerCase() || '';
            // Use localeCompare for potentially better string/numeric sorting (e.g., for StaffID)
            const comparison = cellA.localeCompare(cellB, undefined, {numeric: true, sensitivity: 'base'});
            return comparison * newDirection;
        });

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
        // Button in HTML already has onclick, so no need to add listener here unless preferred
    }


   // --- Search Input Clear Icon Logic ---
    const searchInputStaff = document.getElementById("searchValue"); // Assuming same ID as dept page
    const clearIconStaff = document.getElementById("clearIcon");
    const searchFormStaff = document.getElementById("searchForm");

    if (searchInputStaff && clearIconStaff && searchFormStaff) {
        searchInputStaff.addEventListener("input", function () {
            clearIconStaff.style.display = this.value ? "inline" : "none";
            // Optional: Auto-submit logic (debounced)
            // clearTimeout(this.searchTimeout);
            // this.searchTimeout = setTimeout(() => { searchFormStaff.submit(); }, 500);
        });

        clearIconStaff.addEventListener("click", function () {
            searchInputStaff.value = "";
            clearIconStaff.style.display = "none";
            searchInputStaff.focus();
            searchFormStaff.submit(); // Submit to show all results
        });
    }

    // --- Handling "No results" row display (if needed dynamically after JS sort/filter) ---
    // This basic example relies on PHP rendering the row correctly.
    // More advanced JS filtering would require updating this logic.

</script>

</body>
</html>
<?php
// Close the database connection at the very end
if (isset($conn) && $conn instanceof mysqli && $conn->thread_id) {
    $conn->close();
}
?>