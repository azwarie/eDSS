<?php
session_start();

// Include the database connection (mysqli for azwarie_dss)
include 'connection.php'; // This should define $conn using mysqli

// Check if the connection was successful
if (!$conn || $conn->connect_error) {
    die("Database connection failed: " . ($conn ? $conn->connect_error : 'Unknown error'));
}

// Set default timezone
date_default_timezone_set('Asia/Kuala_Lumpur'); // Replace with your timezone

// Get the current page filename
$current_page = basename($_SERVER['PHP_SELF']);

// --- Staff ID Handling (Logged-in Admin) ---
// Check if the staffID for the logged-in admin is set in the session or GET param
$loggedInStaffID = $_SESSION['staffid'] ?? $_GET['staffid'] ?? null;

// Validate loggedInStaffID
if (empty($loggedInStaffID)) {
     // Redirect to login or show error if staffID is mandatory
     // header("Location: login.php?error=session_expired"); // Example redirect
     die("Admin Staff ID is missing or invalid. Please log in again.");
}


// --- Reusable Audit Trail Function (using mysqli) ---
function logAuditTrail($conn_audit, $staffID_audit, $action, $description) {
     if (!$conn_audit) return;
    try {
        $sql = "INSERT INTO AUDIT_TRAIL (StaffID, Action, Description, Timestamp) VALUES (?, ?, ?, NOW())";
        $stmt = $conn_audit->prepare($sql);
        if ($stmt === false) { throw new Exception("Prepare failed (Audit Trail): " . $conn_audit->error); }
        // Use loggedInStaffID for who performed the action, or SYSTEM if not available
        $actorStaffID = !empty($staffID_audit) ? $staffID_audit : 'SYSTEM';
        $stmt->bind_param('sss', $actorStaffID, $action, $description);
        if (!$stmt->execute()) { throw new Exception("Execute failed (Audit Trail): " . $stmt->error); }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Audit trail error: " . $e->getMessage());
    }
}


// --- Handle Delete Action ---
$delete_error = null;
$delete_success_message = null;

if (isset($_GET['delete'])) {
    $staffIDToDelete = trim($_GET['delete']); // Staff ID to be deleted

    // --- Basic Validation ---
    if (empty($staffIDToDelete)) {
        $delete_error = "Staff ID is required for deletion.";
    } elseif ($staffIDToDelete === $loggedInStaffID) {
         $delete_error = "You cannot delete your own staff record.";
    } else {
        // --- Proceed with Deletion using Transaction ---
        $conn->begin_transaction(); // Start transaction

        try {
            // 1. Check if Staff Exists (Optional but good practice)
            $stmtCheck = $conn->prepare("SELECT StaffID FROM STAFF WHERE StaffID = ?");
            if(!$stmtCheck) throw new Exception("Prepare failed (Check staff): ".$conn->error);
            $stmtCheck->bind_param('s', $staffIDToDelete);
            if(!$stmtCheck->execute()) throw new Exception("Execute failed (Check staff): ".$stmtCheck->error);
            $resultCheck = $stmtCheck->get_result();
            $staffExists = ($resultCheck && $resultCheck->num_rows > 0);
            if($resultCheck) $resultCheck->free();
            $stmtCheck->close();

            if (!$staffExists) {
                throw new Exception("Staff with ID '$staffIDToDelete' not found.");
            }

            // 2. Delete from the STAFF table
            $stmtDelete = $conn->prepare("DELETE FROM STAFF WHERE StaffID = ?");
            if (!$stmtDelete) {
                throw new Exception("Prepare failed (Delete staff): " . $conn->error);
            }
            $stmtDelete->bind_param('s', $staffIDToDelete); // 's' for string

            if (!$stmtDelete->execute()) {
                throw new Exception("Execute failed (Delete staff): " . $stmtDelete->error);
            }

            // Check if any row was actually deleted
            if ($stmtDelete->affected_rows === 0) {
                // This might happen if the ID existed moments ago but was deleted by another process
                throw new Exception("No staff record was deleted (ID: '$staffIDToDelete'). It might have already been removed.");
            }

            // Close delete statement
            $stmtDelete->close();

            // 3. If deletion successful, commit the transaction
            if (!$conn->commit()) {
                 throw new Exception("Transaction commit failed: " . $conn->error);
            }

            // 4. Log the audit trail (using loggedInStaffID as the actor)
            logAuditTrail($conn, $loggedInStaffID, 'Staff Deletion', "Staff with ID '$staffIDToDelete' deleted successfully.");

            // 5. Set success message for display after redirect
            $_SESSION['delete_success'] = "Staff with ID '$staffIDToDelete' deleted successfully!"; // Store in session

            // Redirect back to the same page without the 'delete' parameter, but with loggedInStaffID
            header("Location: removeStaff.php?staffid=" . urlencode($loggedInStaffID));
            exit;

        } catch (Exception $e) {
            // An error occurred, rollback the transaction
            $conn->rollback();
            $delete_error = "Error deleting staff: " . $e->getMessage(); // Store error message
            error_log("Staff Deletion Error: " . $e->getMessage()); // Log detailed error
            logAuditTrail($conn, $loggedInStaffID, 'Staff Deletion Attempt', "Failed to delete staff '$staffIDToDelete'. Error: " . $e->getMessage());
        }
    } // End basic validation else
} // End handle delete action

// Check for success message from session (after redirect)
if (isset($_SESSION['delete_success'])) {
    $delete_success_message = $_SESSION['delete_success'];
    unset($_SESSION['delete_success']); // Clear message after displaying
}


// --- Fetch Staff List based on Filters ---
$filters = [
    'searchValue' => isset($_GET['searchValue']) ? trim($_GET['searchValue']) : ''
];

$whereClauses = [];
$params = [];
$paramTypes = '';

if (!empty($filters['searchValue'])) {
    $searchTermLower = "%" . strtolower($filters['searchValue']) . "%";
    $whereClauses[] = "(LOWER(s.StaffID) LIKE ? OR LOWER(s.Name) LIKE ? OR LOWER(s.IC) LIKE ? OR LOWER(s.Gender) LIKE ? OR LOWER(s.Role) LIKE ? OR LOWER(s.Status) LIKE ? OR LOWER(d.DepartmentName) LIKE ?)";
    for ($i = 0; $i < 7; $i++) {
        $params[] = $searchTermLower;
        $paramTypes .= 's';
    }
}

// Construct the SQL query
$query = "
    SELECT s.StaffID, s.Name, s.Gender, s.PhoneNo, s.Role, s.Status, d.DepartmentName
    FROM STAFF s
    LEFT JOIN DEPARTMENT d ON s.DepartmentID = d.DepartmentID
    ";
if (!empty($whereClauses)) {
    $query .= " WHERE " . implode(" AND ", $whereClauses);
}
$query .= " ORDER BY s.StaffID ASC";

// Prepare and execute the query using mysqli
$searchResults = [];
$query_error = null;

try {
    $stmt = $conn->prepare($query);
    if ($stmt === false) throw new Exception("Prepare failed (Fetch list): " . $conn->error);
    if (!empty($params)) {
        if (!$stmt->bind_param($paramTypes, ...$params)) throw new Exception("Binding parameters failed (Fetch list): " . $stmt->error);
    }
    if (!$stmt->execute()) throw new Exception("Execute failed (Fetch list): " . $stmt->error);
    $result = $stmt->get_result();
    if ($result) {
        $searchResults = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    } else { throw new Exception("Getting result set failed (Fetch list): " . $stmt->error); }
    $stmt->close();
} catch (Exception $e) {
    $query_error = "Database Error fetching staff list: " . $e->getMessage();
    error_log($query_error);
    $searchResults = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Remove Staff</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700&family=Poppins:wght@400;700&display=swap" rel="stylesheet">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Font Awesome & Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Link to searchstyle.css -->
    <link rel="stylesheet" href="searchstyle.css">

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
        @media (min-width: 992px) { /* Dropdown hover */ .navbar .nav-item .dropdown-menu { display: block; margin-top: 0; top: 150%; opacity: 0; visibility: hidden; transition: top 0.3s ease, opacity 0.3s ease, visibility 0.3s; pointer-events: none; } .navbar .nav-item:hover .dropdown-menu { top: 100%; visibility: visible; opacity: 1; pointer-events: auto; } }
        .hero-section { background-color: #007bff; color: white; padding: 60px 0; text-align: center; margin-bottom: 30px; }
        .hero-section h1 { font-family: 'Montserrat', sans-serif; font-size: 2.8rem; font-weight: 700; text-transform: uppercase; }
        .hero-section p { font-size: 1.1rem; opacity: 0.9; }
        @media (max-width: 768px) { /* Responsive Hero */ .hero-section { padding: 40px 0; } .hero-section h1 { font-size: 2rem; } .hero-section p { font-size: 1rem; } }
        #staffTable { background-color: white; border-radius: 5px; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,0.05); font-size: 0.95rem; }
        #staffTable thead th { background-color: #e9ecef; border-bottom: 2px solid #dee2e6; font-weight: 600; color: #495057; white-space: nowrap; }
        #staffTable tbody tr:hover { background-color: #f1f7ff; }
        th.clickable { cursor: pointer; position: relative; padding-right: 25px !important; user-select: none; }
        th.clickable:hover { background-color: #dde4ed; }
        th.clickable::before, th.clickable::after { font-family: "Font Awesome 6 Free"; font-weight: 900; position: absolute; right: 8px; opacity: 0.25; color: #6c757d; transition: opacity 0.2s ease, color 0.2s ease; }
        th.clickable::before { content: "\f0de"; top: calc(50% - 0.6em); }
        th.clickable::after { content: "\f0dd"; top: calc(50% - 0.1em); }
        th.clickable.sort-asc::before { opacity: 1; color: #007bff; }
        th.clickable.sort-desc::after { opacity: 1; color: #007bff; }
        #scrollUpBtn { position: fixed; bottom: 25px; right: 25px; display: none; background-color: #007bff; color: white; border: none; border-radius: 50%; width: 45px; height: 45px; font-size: 20px; line-height: 45px; text-align: center; cursor: pointer; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2); z-index: 1000; transition: background-color 0.2s ease, opacity 0.3s ease; }
        #scrollUpBtn:hover { background-color: #0056b3; }
        #searchForm { max-width: 600px; margin: 0 auto 30px auto; }
        .no-results td { font-weight: bold; color: #dc3545; background-color: #f8d7da !important; }
        /* Delete button specific styling */
        .delete-btn {
            background-color: #dc3545; /* Bootstrap danger red */
            color: white;
            border: none;
            border-radius: 0.25rem; /* Small rounding */
            padding: 0.25rem 0.5rem; /* Smaller padding */
            font-size: 0.8rem; /* Smaller font */
            line-height: 1.5;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        .delete-btn:hover { background-color: #c82333; /* Darker red */ }
        .delete-btn i { font-size: 0.9em; /* Adjust icon size relative to button text */ }
        /* Alert styling */
        .alert { margin-top: 1.25rem; border-radius: 5px; font-size: 0.95rem; }
    </style>

</head>
<body>
<!-- Navigation Bar Menu -->
<nav class="navbar navbar-expand-lg navbar-light">
    <div class="container">
        <a class="navbar-brand" href="AdminLandingPage.php?staffid=<?php echo htmlspecialchars($loggedInStaffID); ?>">WeCare</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <!-- Navbar links with loggedInStaffID -->
                <li class="nav-item <?php echo ($current_page == 'adminpage.php') ? 'active' : ''; ?>"><a class="nav-link" href="adminpage.php?staffid=<?php echo htmlspecialchars($loggedInStaffID); ?>">Home</a></li>
                <li class="nav-item dropdown <?php echo in_array($current_page, ['admindashboard.php', 'adminaudittrail.php']) ? 'active' : ''; ?>"><a class="nav-link dropdown-toggle" href="#" id="dashboardsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">Real-Time Dashboards</a><ul class="dropdown-menu" aria-labelledby="dashboardsDropdown"><li><a class="dropdown-item <?php echo ($current_page == 'admindashboard.php') ? 'active' : ''; ?>" href="admindashboard.php?staffid=<?php echo htmlspecialchars($loggedInStaffID); ?>">Dashboard Overview</a></li><li><a class="dropdown-item <?php echo ($current_page == 'adminaudittrail.php') ? 'active' : ''; ?>" href="adminaudittrail.php?staffid=<?php echo htmlspecialchars($loggedInStaffID); ?>">Audit Trail Records</a></li></ul></li>
                <li class="nav-item dropdown <?php echo in_array($current_page, ['viewStaff.php', 'registerStaff.php', 'removeStaff.php', 'updateStaff.php']) ? 'active' : ''; ?>"><a class="nav-link dropdown-toggle" href="#" id="staffDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">Manage Staff</a><ul class="dropdown-menu" aria-labelledby="staffDropdown"><li><a class="dropdown-item <?php echo ($current_page == 'viewStaff.php') ? 'active' : ''; ?>" href="viewStaff.php?staffid=<?php echo htmlspecialchars($loggedInStaffID); ?>">Staff Directory</a></li><li><a class="dropdown-item <?php echo ($current_page == 'registerStaff.php') ? 'active' : ''; ?>" href="registerStaff.php?staffid=<?php echo htmlspecialchars($loggedInStaffID); ?>">Register Staff</a></li><li><a class="dropdown-item <?php echo ($current_page == 'removeStaff.php') ? 'active' : ''; ?>" href="removeStaff.php?staffid=<?php echo htmlspecialchars($loggedInStaffID); ?>">Remove Staff</a></li><li><a class="dropdown-item <?php echo ($current_page == 'updateStaff.php') ? 'active' : ''; ?>" href="updateStaff.php?staffid=<?php echo htmlspecialchars($loggedInStaffID); ?>">Update Staff</a></li></ul></li>
                <li class="nav-item <?php echo ($current_page == 'manageDept.php') ? 'active' : ''; ?>"><a class="nav-link" href="manageDept.php?staffid=<?php echo htmlspecialchars($loggedInStaffID); ?>">Department Directory</a></li>
                <li class="nav-item <?php echo ($current_page == 'adminprofile.php') ? 'active' : ''; ?>"><a class="nav-link" href="adminprofile.php?staffid=<?php echo htmlspecialchars($loggedInStaffID); ?>">Profile</a></li>
                <li class="nav-item"><a class="nav-link" href="login.php?action=logout">Logout</a></li>
            </ul>
        </div>
    </div>
</nav>
<!-- Hero Section -->
<div class="hero-section">
    <h1>Remove Staff</h1>
    <p>Select staff members to remove from the system.</p>
</div>

<div class="container mt-4">

    <!-- Display Success/Error Messages for Deletion -->
     <div id="messageAreaDelete" style="min-height: 60px;"> <!-- Reserve space -->
        <?php if ($delete_success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($delete_success_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($delete_error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($delete_error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
    </div>

     <!-- Display Query Error for fetching list -->
     <?php if ($query_error && !$delete_error && !$delete_success_message): // Show list fetch error only if no delete message ?>
        <div class="alert alert-warning" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($query_error); ?> Staff list may be incomplete.
        </div>
     <?php endif; ?>

    <!-- Search Form for Staff -->
    <form method="GET" id="searchForm" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
        <div class="input-container">
            <span class="icon"><i class="fa fa-search"></i></span>
            <input type="text" id="searchValue" name="searchValue" value="<?= htmlspecialchars($filters['searchValue']) ?>" placeholder="Search by ID, Name, IC, Gender, Role, Status, Dept...">
            <span id="clearIcon" class="clear-icon" style="<?= !empty($filters['searchValue']) ? 'display: inline;' : 'display: none;' ?>">×</span>
        </div>
        <!-- Keep loggedInStaffID in the form submission -->
        <input type="hidden" name="staffid" value="<?= htmlspecialchars($loggedInStaffID) ?>">
    </form>


    <!-- Staff Table -->
    <div class="table-responsive">
        <table id="staffTable" class="table table-striped table-hover">
            <thead>
                <tr>
                    <th class="clickable" onclick="sortTable(0, this)">Staff ID</th>
                    <th class="clickable" onclick="sortTable(1, this)">Name</th>
                    <th class="clickable" onclick="sortTable(2, this)">Gender</th>
                    <th class="clickable" onclick="sortTable(3, this)">Phone No</th>
                    <th class="clickable" onclick="sortTable(4, this)">Role</th>
                    <th class="clickable" onclick="sortTable(5, this)">Status</th>
                    <th class="clickable" onclick="sortTable(6, this)">Department</th>
                    <th>Action</th> <!-- Action column -->
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($searchResults)): ?>
                    <?php foreach ($searchResults as $staff): ?>
                        <?php // Prevent deleting the currently logged-in admin ?>
                        <?php $canDelete = ($staff['StaffID'] !== $loggedInStaffID); ?>
                        <tr>
                            <td><?= htmlspecialchars($staff['StaffID'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($staff['Name'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($staff['Gender'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($staff['PhoneNo'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($staff['Role'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($staff['Status'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($staff['DepartmentName'] ?? 'N/A') ?></td>
                            <td class="text-center">
                                <?php if ($canDelete): ?>
                                    <button class="delete-btn"
                                            onclick="confirmDelete('<?= htmlspecialchars(addslashes($staff['StaffID'])) ?>')"
                                            title="Delete Staff <?= htmlspecialchars($staff['StaffID']) ?>">
                                        <i class="bi bi-trash-fill"></i> <!-- Bootstrap Trash Icon -->
                                    </button>
                                <?php else: ?>
                                     <button class="delete-btn" disabled title="Cannot delete self">
                                         <i class="bi bi-trash-fill"></i>
                                     </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr class="no-results">
                        <td colspan="8" class="text-center">
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
<!-- Optional: main.js -->
<!-- <script src="main.js"></script> -->

<script>
    // --- Confirm Deletion ---
    function confirmDelete(staffIdToDelete) {
        // Use Bootstrap Modal for confirmation instead of basic confirm() for better UX
        // If you don't have a modal defined, use confirm() as a fallback:
        if (confirm('Are you sure you want to permanently delete staff member with ID: ' + staffIdToDelete + '? This action cannot be undone.')) {
            // Construct URL carefully, ensuring loggedInStaffID is included for the page context
            const adminStaffId = '<?= htmlspecialchars($loggedInStaffID, ENT_QUOTES, 'UTF-8') ?>'; // Get admin ID from PHP
            window.location.href = `removeStaff.php?delete=${encodeURIComponent(staffIdToDelete)}&staffid=${encodeURIComponent(adminStaffId)}`;
        }
        // If using a Bootstrap modal, you'd trigger the modal here and handle the confirmation
        // button inside the modal to redirect.
    }


    // --- Client-Side Table Sorting ---
    let tableSortDirectionsStaff = {}; // Unique name

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
        // Ensure thElement exists before adding class
         if(thElement) {
            thElement.classList.add(newDirection === 1 ? "sort-asc" : "sort-desc");
         }


        rows.sort((rowA, rowB) => {
            const cellA = rowA.cells[columnIndex]?.textContent.trim().toLowerCase() || '';
            const cellB = rowB.cells[columnIndex]?.textContent.trim().toLowerCase() || '';
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
    }


   // --- Search Input Clear Icon Logic ---
    const searchInputStaff = document.getElementById("searchValue");
    const clearIconStaff = document.getElementById("clearIcon");
    const searchFormStaff = document.getElementById("searchForm");

    if (searchInputStaff && clearIconStaff && searchFormStaff) {
        searchInputStaff.addEventListener("input", function () {
            clearIconStaff.style.display = this.value ? "inline" : "none";
            // Optional: Auto-submit logic (debounced) - uncomment if desired
            /*
            clearTimeout(this.searchTimeout);
            this.searchTimeout = setTimeout(() => {
                // Check if form exists before submitting
                 if (document.getElementById("searchForm")) {
                     document.getElementById("searchForm").submit();
                 }
             }, 500); // Wait 500ms after typing stops
            */
        });

        clearIconStaff.addEventListener("click", function () {
            searchInputStaff.value = "";
            clearIconStaff.style.display = "none";
            searchInputStaff.focus();
            searchFormStaff.submit(); // Submit to show all results
        });
    }

     // --- Remove success/error messages after delay ---
     document.addEventListener('DOMContentLoaded', function() {
        const alerts = document.querySelectorAll('.alert-success, .alert-danger');
        alerts.forEach(function(alert) {
            // Ensure Bootstrap's Alert component is available
             if (typeof bootstrap !== 'undefined' && bootstrap.Alert) {
                setTimeout(() => {
                    const alertInstance = bootstrap.Alert.getOrCreateInstance(alert);
                    if (alertInstance) {
                        alertInstance.close();
                    }
                }, 5000); // Hide after 5 seconds
             } else {
                 // Fallback if Bootstrap JS isn't loaded properly
                 setTimeout(() => { alert.style.display = 'none'; }, 5000);
             }
        });
    });

</script>

</body>
</html>
<?php
// Close the database connection at the very end
if (isset($conn) && $conn instanceof mysqli && $conn->thread_id) {
    $conn->close();
}
?>