<?php
session_start();

// Include the database connection
include 'connection.php';
include 'azwarieConnect.php';

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

if (isset($_GET['delete'])) {
    $deletestaffID = $_GET['delete'];
    echo "<script>console.log('Delete Staff ID:', '$deletestaffID');</script>"; // Debugging line

    try {
        // Begin transaction for PDO (local database)
        $pdo->beginTransaction();

        // Start transaction for azwarie database
        mysqli_autocommit($connect_azwarie, false);

        // Check Azwarie database connection
        if (!$connect_azwarie) {
            throw new Exception("Failed to connect to the Azwarie database: " . mysqli_connect_error());
        }

        // Debugging: Check if StaffID exists in the local database
        $checkLocalQuery = "SELECT * FROM STAFF WHERE staffid = :staffID";
        $checkLocalStmt = $pdo->prepare($checkLocalQuery);
        $checkLocalStmt->bindParam(':staffID', $deletestaffID, PDO::PARAM_STR);
        $checkLocalStmt->execute();

        if ($checkLocalStmt->rowCount() === 0) {
            throw new Exception("StaffID $deletestaffID does not exist in the local database.");
        }

        // Delete from local database
        $deleteStaffQueryLocal = "DELETE FROM STAFF WHERE staffid = :staffID";
        $stmtLocal = $pdo->prepare($deleteStaffQueryLocal);

        if (!$stmtLocal) {
            throw new Exception("Failed to prepare the local database query: " . implode(" ", $pdo->errorInfo()));
        }

        $stmtLocal->bindParam(':staffID', $deletestaffID, PDO::PARAM_STR); // Use PDO::PARAM_STR for string
        $deleteSuccessLocal = $stmtLocal->execute();

        if (!$deleteSuccessLocal || $stmtLocal->rowCount() === 0) {
            throw new Exception("No rows affected in the local database. StaffID may not exist.");
        }

        // Debugging: Check if StaffID exists in the Azwarie database
        $checkAzwarieQuery = "SELECT * FROM STAFF WHERE StaffID = ?";
        $checkAzwarieStmt = mysqli_prepare($connect_azwarie, $checkAzwarieQuery);
        mysqli_stmt_bind_param($checkAzwarieStmt, 's', $deletestaffID);
        mysqli_stmt_execute($checkAzwarieStmt);
        mysqli_stmt_store_result($checkAzwarieStmt);

        if (mysqli_stmt_num_rows($checkAzwarieStmt) === 0) {
            throw new Exception("StaffID $deletestaffID does not exist in the Azwarie database.");
        }

        // Delete from Azwarie database
        $deleteStaffQueryAzwarie = "DELETE FROM STAFF WHERE StaffID = ?";
        $stmtAzwarie = mysqli_prepare($connect_azwarie, $deleteStaffQueryAzwarie);

        if (!$stmtAzwarie) {
            throw new Exception("Failed to prepare the Azwarie database query: " . mysqli_error($connect_azwarie));
        }

        mysqli_stmt_bind_param($stmtAzwarie, 's', $deletestaffID); // Use 's' for string binding
        $deleteSuccessAzwarie = mysqli_stmt_execute($stmtAzwarie);

        if (!$deleteSuccessAzwarie) {
            throw new Exception("Failed to execute the Azwarie database query: " . mysqli_stmt_error($stmtAzwarie));
        }

        if (mysqli_stmt_affected_rows($stmtAzwarie) === 0) {
            throw new Exception("No rows affected in the Azwarie database. StaffID may not exist.");
        }

        // Commit both transactions if successful
        $pdo->commit();
        mysqli_commit($connect_azwarie);

        // Success message
        echo "<script>alert('Staff with ID $deletestaffID deleted successfully!');</script>";
        echo "<script>window.location.href = 'viewStaff.php';</script>";
    } catch (Exception $e) {
        // Rollback transaction in case of an error
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        mysqli_rollback($connect_azwarie);

        // Display the error message
        echo "<script>alert('Error: " . $e->getMessage() . "');</script>";
    }
}

// Initialize filter variables with default empty values
$filters = [
    'StaffID' => isset($_GET['StaffID']) ? $_GET['StaffID'] : '',
    'Name' => isset($_GET['Name']) ? $_GET['Name'] : '',
    'IC' => isset($_GET['IC']) ? $_GET['IC'] : '',
    'Gender' =>  isset($_GET['Gender']) ? $_GET['Gender'] : '',
    'PhoneNo' =>  isset($_GET['PhoneNo']) ? $_GET['PhoneNo'] : '',
    'Role' =>  isset($_GET['Role']) ? $_GET['Role'] : '',
    'Status' =>  isset($_GET['Status']) ? $_GET['Status'] : '',
    'DepartmentName' =>  isset($_GET['DepartmentName']) ? $_GET['DepartmentName'] : '',
    'searchValue' => isset($_GET['searchValue']) ? trim($_GET['searchValue']) : ''
];

// Build the filter and search query dynamically
$whereClauses = [];
$params = [];

foreach ($filters as $key => $value) {
    if (!empty($value)) {
        // For exact match fields like StaffID
        if ($key === 'StaffID') {
            $whereClauses[] = "s.$key = :$key";
        } elseif ($key === 'searchValue') {
            // For search condition, we check if it applies
            $whereClauses[] = "(LOWER(s.StaffID) LIKE LOWER(:searchValue) OR
                                LOWER(s.Name) LIKE LOWER(:searchValue) OR
                                LOWER(s.IC) LIKE LOWER(:searchValue) OR
                                LOWER(s.Gender) LIKE LOWER(:searchValue) OR
                                LOWER(s.Role) LIKE LOWER(:searchValue) OR
                                LOWER(s.Status) LIKE LOWER(:searchValue) OR
                                LOWER(d.DepartmentName) LIKE LOWER(:searchValue))";
        } else {
            // For other fields, use LIKE for partial matching
            $whereClauses[] = "LOWER(TRIM(" . ($key === 'DepartmentName' ? "d.$key" : "s.$key") . ")) LIKE LOWER(:$key)";
        }
        $params[":$key"] = $key === 'searchValue' ? "%$value%" : $value;  // Add parameter for binding
    }
}

// Construct the SQL query
$query = "
    SELECT s.*, d.DepartmentName
    FROM STAFF s
    LEFT JOIN DEPARTMENT d ON s.DepartmentID = d.DepartmentID
    WHERE 1=1";  // Always true to simplify appending conditions

// Append dynamic WHERE clauses based on filters and search
if (!empty($whereClauses)) {
    $query .= " AND " . implode(" AND ", $whereClauses);
}

$query .= " ORDER BY s.StaffID"; // Sort results by StaffID

// Prepare and execute the query
try {
    $stmt = $pdo->prepare($query);

    // Bind the parameters dynamically
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->execute();
    $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Show alert if no results found
    if (empty($searchResults)) {
        echo "<script>alert('No staff found matching your filters.');</script>";
    }
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Remove Staff</title>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700&family=Poppins:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/2.2.1/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- Bootstrap JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
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
        .content {
            padding: 100px 0;
            text-align: center;
        }
        /* Style for h2 */
        h2 {
            font-size: 24px;
            color: black;
            margin-bottom: 10px;
        }
        form {
            margin-bottom: 10px;
        }
         /* Style icons */
        .btn i {
            font-size: 1.2rem;
        }
        /* Modal buttons styling */
        .modal-footer .btn {
            min-width: 120px;
        }
        .modal-footer .btn-secondary {
            margin-right: auto;
        }
        /* Style the Confirm and Cancel buttons in the Delete Modal */
        .modal-footer .btn-danger {
            background-color: #dc3545;
            border-color: #dc3545;
        }
        .modal-footer .btn-danger i {
            margin-right: 8px; /* Space between the icon and text */
            font-size: 1.2rem; /* Adjust icon size */
        }
        .modal-footer .btn-secondary {
            background-color: #6c757d;
            border-color: #6c757d;
        }
        /* Style for buttons and links on focus */
        .btn:focus, .btn:active, a:focus, a:active {
            outline: 3px solid #0056b3; /* Adding a focus style for better accessibility */
            box-shadow: none;
        }
    </style>

</head>
<body>
<!-- Include search styles -->
<?php include 'searchstyle.php'; ?>
<!-- Navigation Bar Menu -->
<nav class="navbar navbar-expand-lg navbar-light" style="background-color: white; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
    <div class="container">
        <a class="navbar-brand" href="https://172.20.238.6/staff2/admin/AdminLandingPage.php?staffid=<?php echo $staffID; ?>" style="font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 700; color: #007bff; position: absolute; top: 10px; left: 15px;">WeCare</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
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
                <li class="nav-item dropdown <?php echo ($current_page == 'viewStaff.php' || $current_page == 'registerStaff.php' || $current_page == 'deleteStaff.php' || $current_page == 'updateStaff.php') ? 'active' : ''; ?>" style="position: relative;">
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
                            <a class="dropdown-item <?php echo ($current_page == 'deleteStaff.php') ? 'active' : ''; ?>" 
                                href="deleteStaff.php?staffid=<?php echo $staffID; ?>">Remove Staff</a>
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
    <h1>Welcome To Staff Management</h1>
    <p>Remove Staff</p>
</div>

<form method="GET" id="searchForm">
    <div class="input-container">
        <span class="icon"><i class="fa fa-search"></i></span>
        <input type="text" id="searchValue" name="searchValue" value="<?= htmlspecialchars($_GET['searchValue'] ?? '') ?>" placeholder="Search staff">
        <span id="clearIcon" class="clear-icon" style="display: none;">&times;</span>
    </div>
</form>
<br><br>
<table id="staffTable" class="table table-striped">
    <thead>
        <tr>
            <th class="clickable" onclick="sortTable(0)">Staff ID</th>
            <th class="clickable" onclick="sortTable(1)">Name</th>
            <th class="clickable" onclick="sortTable(2)">Gender</th>
            <th class="clickable" onclick="sortTable(3)">Phone No</th>
            <th class="clickable" onclick="sortTable(4)">Role</th>
            <th class="clickable" onclick="sortTable(5)">Status</th>
            <th class="clickable" onclick="sortTable(6)">Department Name</th>
            <th class="clickable" onclick="sortTable(7)">Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!empty($searchResults)): ?>
            <?php foreach ($searchResults as $staff): ?>
                <tr>
                    <td><?= htmlspecialchars($staff['staffid']) ?></td>
                    <td><?= htmlspecialchars($staff['name']) ?></td>
                    <td><?= htmlspecialchars($staff['gender']) ?></td>
                    <td><?= htmlspecialchars($staff['phoneno']) ?></td>
                    <td><?= htmlspecialchars($staff['role']) ?></td>
                    <td><?= htmlspecialchars($staff['status']) ?></td>
                    <td><?= htmlspecialchars($staff['departmentname']) ?></td>
                    <td>
                    <button class='btn btn-danger btn-sm delete-btn' data-bs-toggle='modal' data-bs-target='#confirmDeleteModal' data-staffid='<?= htmlspecialchars($staff['staffid']) ?>'>
                        <i class='fas fa-trash-alt'></i>
                    </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="8" class="text-center">No staff records found for the search criteria.</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-labelledby="confirmDeleteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmDeleteModalLabel">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                Are you sure you want to delete this staff member?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a id="confirmDeleteBtn" href="#" class="btn btn-danger">Delete</a>
            </div>
        </div>
    </div>
</div>

<!-- Scroll to Top Button -->
<button id="scrollUpBtn" onclick="scrollToTop()" class="fa fa-long-arrow-up"></button>

<!-- Include JavaScript -->
<script src="main.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const deleteButtons = document.querySelectorAll('.delete-btn');
    const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');

    deleteButtons.forEach(button => {
        button.addEventListener('click', function () {
            const staffID = button.getAttribute('data-staffid');
            console.log("Staff ID to delete:", staffID); // Debugging line
            confirmDeleteBtn.href = `deleteStaff.php?delete=${staffID}`; // Fixed: Added backticks
            console.log("Delete URL:", confirmDeleteBtn.href); // Debugging line
        });
    });
});

    function sortTable(columnIndex) {
        const table = document.getElementById("staffTable");
        const rows = Array.from(table.rows).slice(1); // Exclude the header row
        const ascending = table.getAttribute("data-sort") === "asc";

        rows.sort((a, b) => {
            const aText = a.cells[columnIndex].textContent.trim();
            const bText = b.cells[columnIndex].textContent.trim();
            return ascending ? aText.localeCompare(bText) : bText.localeCompare(aText);
        });

        rows.forEach(row => table.tBodies[0].appendChild(row)); // Reorder rows in DOM
        table.setAttribute("data-sort", ascending ? "desc" : "asc");
    }

    // Get the button
    const scrollUpBtn = document.getElementById("scrollUpBtn");
    // When the user scrolls down 100px from the top of the document, show the button
    window.onscroll = function() 
    {
        if (document.body.scrollTop > 5 || document.documentElement.scrollTop >5) 
        {
            scrollUpBtn.style.display = "block";
        } else 
        {
            scrollUpBtn.style.display = "none";
        }
    };
    // When the button is clicked, scroll to the top of the page
    function scrollToTop() 
    {
        window.scrollTo(
        {
            top: 0,
            behavior: "smooth"  // Smooth scroll
        });
    }
    // Auto-search as user types
    document.getElementById("searchValue").addEventListener("input", function () {
    const searchValue = document.getElementById("searchValue").value;

    // If there's a search value, show the 'X' icon to clear the input
    const clearIcon = document.getElementById("clearIcon");
    if (searchValue) {
        clearIcon.style.display = "inline"; // Show the X icon
    } else {
        clearIcon.style.display = "none"; // Hide the X icon if input is empty
    }
});

    // Clear the input when the 'X' icon is clicked
    document.getElementById("clearIcon").addEventListener("click", function () {
    const searchValueInput = document.getElementById("searchValue");
    searchValueInput.value = ""; // Clear the input
    this.style.display = "none"; // Hide the X icon
});

    // Show 'No records found' message if no rows match
    const noResultsRow = document.querySelector("#staffTable tbody .no-results");
    if (!Array.from(tableRows).some(row => row.style.display !== "none")) {
        if (!noResultsRow) {
            // Create a new 'No records found' row if it doesn't already exist
            const newRow = document.createElement("tr");
            newRow.classList.add("no-results");
            newRow.innerHTML = <td colspan="8" class="text-center">No staff records found for the search criteria.</td>;
            document.querySelector("#staffTable tbody").appendChild(newRow);
        }
    } else if (noResultsRow) {
        noResultsRow.remove(); // Remove the 'No records found' row if there are matching results
    }

        // Clear input when X icon is clicked
        document.getElementById("clearIcon").addEventListener("click", function () {
        document.getElementById("searchValue").value = ""; // Clear the input
        document.getElementById("searchForm").submit();   // Submit form to reset state
    });
</script>
</body>
</html>