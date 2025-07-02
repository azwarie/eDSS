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


// Handle update action for department
if (isset($_POST['update'])) {
    // Sanitize the inputs before using them
    $departmentID = $_POST['departmentid'] ?? null;
    $departmentName = $_POST['departmentname'] ?? null;
    $location = $_POST['location'] ?? null;

    // Check that all required fields are provided
    if ($departmentID && $departmentName && $location) {
        try {
            // SQL query to update department details
            $updateQuery = "UPDATE DEPARTMENT SET DepartmentName = :departmentname, Location = :location WHERE DepartmentID = :departmentid";

            // Prepare and execute for your database (PostgreSQL)
            $stmtupdate = $pdo->prepare($updateQuery);
            $stmtupdate->bindParam(':departmentid', $departmentID, PDO::PARAM_INT);
            $stmtupdate->bindParam(':departmentname', $departmentName, PDO::PARAM_STR);
            $stmtupdate->bindParam(':location', $location, PDO::PARAM_STR);
            $stmtupdate->execute();

            // Prepare and execute for Azwarie's database (MySQL)
            $updateQueryAzwarie = "UPDATE DEPARTMENT SET DepartmentName = ?, Location = ? WHERE DepartmentID = ?";
            $stmtAzwarieDB = $mysqliAzwarieDB->prepare($updateQueryAzwarie);
            $stmtAzwarieDB->bind_param('sss', $departmentName, $location, $departmentID); // Bind parameters for MySQLi
            $stmtAzwarieDB->execute();

            // Check if the update was successful in both databases
            if ($stmtupdate->rowCount() > 0 && $stmtAzwarieDB->affected_rows > 0) {
                echo "<script>alert('Department details updated successfully in both databases!');</script>";
                echo "<script>window.location.href = 'manageDept.php';</script>";
            } else {
                echo "<script>alert('No changes were made or the department does not exist.');</script>";
            }
        } catch (PDOException $e) {
            // Handle any errors during the update process for PostgreSQL
            echo "<script>alert('PostgreSQL Error: " . $e->getMessage() . "');</script>";
        } catch (Exception $e) {
            // Handle any errors during the update process for MySQL
            echo "<script>alert('MySQL Error: " . $e->getMessage() . "');</script>";
        }
    } else {
        // Error if fields are empty
        $error = "All fields are required for updating the department.";
        echo "<script>alert('$error');</script>";
    }
}

// Initialize variables
$message = '';
$error = '';
// Initialize filter variables
$filters = [
    'DepartmentID' => '',
    'DepartmentName' => '',
    'Location' => ''
];

// Build the filter query dynamically for department management
$whereClauses = [];
foreach ($filters as $key => $value) 
{
    if (isset($_GET[$key]) && !empty($_GET[$key])) 
    {
        $filters[$key] = $_GET[$key]; // Update filter with user input
        if ($key === 'DepartmentID') {
            // Assuming DepartmentID is an exact match, no case conversion needed
            $whereClauses[] = "$key = :$key"; 
        } elseif (in_array($key, ['DepartmentName', 'Location'])) {
            // For text fields, use LIKE with case insensitivity
            $whereClauses[] = "LOWER($key) LIKE LOWER(:$key)";
        }
    }
}
// Initialize the search results array and error/message variables
$searchResults = [];

// Get search inputs from the form
$searchType = $_GET['searchType'] ?? ''; // The column to search (DepartmentID, DepartmentName, Location)
$searchValue = trim($_GET['searchValue'] ?? ''); // The search value entered by the user

// Define valid search types for security
$validSearchTypes = ['DepartmentID', 'DepartmentName', 'Location'];

if (isset($_GET['search']) && in_array($searchType, $validSearchTypes) && !empty($searchValue)) {
    try {
        // Prepare the query dynamically based on search type
        $searchQuery = "SELECT * FROM DEPARTMENT WHERE LOWER(TRIM($searchType)) LIKE LOWER(:searchValue) ORDER BY DepartmentID";
        $stmt = $pdo->prepare($searchQuery);

        // Add wildcards for partial matching and bind the parameter
        $searchValue = '%' . $searchValue . '%';
        $stmt->bindParam(':searchValue', $searchValue, PDO::PARAM_STR);

        // Execute the query
        $stmt->execute();
        $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Check if results are empty
        if (empty($searchResults)) {
            $message = "No departments found for the given $searchType.";
        }
    } catch (PDOException $e) {
        $error = "Error searching for departments: " . $e->getMessage();
    }
} else {

    try {
        $stmt = $pdo->query("SELECT * FROM DEPARTMENT ORDER BY DepartmentID");
        $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Error fetching departments: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Update Department</title>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700&family=Poppins:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css"> <!-- FontAwesome for icons -->
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
    </style>
    
</head>
<body>
<!-- Include Navbar -->
<?php include 'searchstyle.php'; ?>
<!-- Navigation Bar Menu -->
<nav class="navbar navbar-expand-lg navbar-light" style="background-color: white; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
    <div class="container">
        <a class="navbar-brand" href="AdminLandingPage.php?staffid=<?php echo $staffID; ?>" style="font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 700; color: #007bff; position: absolute; top: 10px; left: 15px;">eDSS</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation"   style="margin-left: 65px;">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item <?php echo ($current_page == 'adminpage.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="adminpage.php?staffid=<?php echo $staffID; ?>" style="color: black;">Home</a>
                </li>
                <li class="nav-item <?php echo ($current_page == 'admindashboard.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="admindashboard.php?staffid=<?php echo $staffID; ?>" style="color: black;">Real-Time Dashboards</a>
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
    <p>Efficiently Manage and Organize Departments</p>
</div>

<!-- Search Form for Departments -->
<form method="GET" id="searchForm">
    <div class="input-container">
        <span class="icon"><i class="fa fa-search"></i></span>
        <input type="text" id="searchValue" name="searchValue" value="<?= htmlspecialchars($_GET['searchValue'] ?? '') ?>" placeholder="Search department">
        <span id="clearIcon" class="clear-icon" style="display: none;">&times;</span> <!-- X icon for clearing -->
    </div>
</form>
<br><br>
<!-- Department Management Table -->
<table id="deptTable" class="table table-striped" data-sort="asc">
    <thead>
        <tr>
            <th class="clickable" onclick="sortDeptTable(0)">Department ID</th>
            <th class="clickable" onclick="sortDeptTable(1)">Department Name</th>
            <th class="clickable" onclick="sortDeptTable(2)">Location</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!empty($searchResults)): ?>
            <?php foreach ($searchResults as $dept): ?>
                <tr>
                    <td><?= htmlspecialchars($dept['departmentid']) ?></td>
                    <td><?= htmlspecialchars($dept['departmentname']) ?></td>
                    <td><?= htmlspecialchars($dept['location']) ?></td>
                    <td>
                        <!-- Edit Button -->
                        <button class="btn btn-warning btn-sm edit-dept-btn" 
                                data-bs-toggle="modal" 
                                data-bs-target="#editDeptModal" 
                                data-dept-id="<?= htmlspecialchars($dept['departmentid']) ?>" 
                                data-dept-name="<?= htmlspecialchars($dept['departmentname']) ?>" 
                                data-location="<?= htmlspecialchars($dept['location']) ?>">
                            <i class="fas fa-edit"></i>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="4" class="text-center">No department records found for the search criteria.</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<!-- Edit Department Modal -->
<div class="modal fade" id="editDeptModal" tabindex="-1" aria-labelledby="editDeptModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editDeptModalLabel">Update Department Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="updateDept.php">
    <div class="modal-body">
        <div class="mb-3">
            <label for="departmentid" class="form-label">Department ID</label>
            <input type="text" class="form-control" id="departmentid" name="departmentid" readonly>
        </div>
        <div class="mb-3">
            <label for="departmentname" class="form-label">Department Name</label>
            <input type="text" class="form-control" id="departmentname" name="departmentname" required>
        </div>
        <div class="mb-3">
            <label for="location" class="form-label">Location</label>
            <input type="text" class="form-control" id="location" name="location" required>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" name="updateDept" class="btn btn-primary">Save Changes</button>
    </div>
</form>
        </div>
    </div>
</div>

<!-- Bootstrap CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- Bootstrap JS (with Popper.js) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Include JavaScript -->
<script src="main.js"></script>
<script>

document.addEventListener('DOMContentLoaded', function () {
    // Handle edit department button clicks
    const editDeptButtons = document.querySelectorAll('.edit-dept-btn');
    editDeptButtons.forEach(button => {
        button.addEventListener('click', function () {
            console.log("Edit button clicked!"); // Debugging statement

            // Get department details from data attributes
            const departmentID = button.getAttribute('data-dept-id');
            const departmentName = button.getAttribute('data-dept-name'); // Corrected variable name
            const location = button.getAttribute('data-location');

            // Populate the modal form fields
            document.getElementById('departmentid').value = departmentID;
            document.getElementById('departmentname').value = departmentName;
            document.getElementById('location').value = location;
        });
    });
});

function sortDeptTable(columnIndex) {
    const table = document.getElementById("deptTable");
    const rows = Array.from(table.rows).slice(1); // Exclude the header row
    const ascending = table.getAttribute("data-sort") === "asc";

    rows.sort((a, b) => {
        const aText = a.cells[columnIndex].textContent.trim().toLowerCase();
        const bText = b.cells[columnIndex].textContent.trim().toLowerCase();
        return ascending ? aText.localeCompare(bText) : bText.localeCompare(aText);
    });

    rows.forEach(row => table.tBodies[0].appendChild(row)); // Reorder rows in DOM
    table.setAttribute("data-sort", ascending ? "desc" : "asc");
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
    const noResultsRow = document.querySelector("#deptTable tbody .no-results");
    if (!Array.from(tableRows).some(row => row.style.display !== "none")) {
        if (!noResultsRow) {
            // Create a new 'No records found' row if it doesn't already exist
            const newRow = document.createElement("tr");
            newRow.classList.add("no-results");
            newRow.innerHTML = `<td colspan="4" class="text-center">No department records found for the search criteria.</td>`;
            document.querySelector("#deptTable tbody").appendChild(newRow);
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