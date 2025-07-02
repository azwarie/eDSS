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
    $departmentID = $_GET['delete'];
    try {
        // Begin transaction
        $pdo->beginTransaction();  // Start transaction for PDO (local database)
        mysqli_autocommit($connect_azwarie, false); // Start transaction for azwarie database

        // Delete from DEPARTMENT table in the local PDO database
        $deleteDeptQueryLocal = "DELETE FROM DEPARTMENT WHERE DepartmentID = :departmentID";
        $stmtLocal = $pdo->prepare($deleteDeptQueryLocal);
        $stmtLocal->bindParam(':departmentID', $departmentID, PDO::PARAM_INT);
        $stmtLocal->execute();

        // Debugging: Check if the delete query affected any rows in the local database
        echo "Local DB affected rows: " . $stmtLocal->rowCount();

        // Delete from DEPARTMENT table in azwarie database
        $deleteDeptQueryAzwarie = "DELETE FROM DEPARTMENT WHERE DepartmentID = ?";
        $stmtAzwarie = mysqli_prepare($connect_azwarie, $deleteDeptQueryAzwarie);
        mysqli_stmt_bind_param($stmtAzwarie, 'i', $departmentID);  // 'i' for integer

        // Ensure that the delete operation targets only the given departmentID in azwarieConnect
        $deleteSuccessAzwarie = mysqli_stmt_execute($stmtAzwarie);

        // Debugging: Check if deletion was successful in azwarie database
        if ($deleteSuccessAzwarie) {
            echo "Azwarie DB deletion successful.";
        } else {
            echo "Error in deleting from azwarie DB.";
        }

        if ($stmtLocal->rowCount() > 0 && $deleteSuccessAzwarie) {
            // Commit both transactions if successful
            $pdo->commit(); // Commit transaction for PDO (local database)
            mysqli_commit($connect_azwarie); // Commit transaction for azwarie database
            echo "<script>alert('Department with ID $departmentID deleted successfully!');</script>";
            echo "<script>window.location.href = 'manageDept.php';</script>";
        } else {
            // Rollback if either delete operation fails
            $pdo->rollBack();
            mysqli_rollback($connect_azwarie);
            throw new Exception("Error deleting department from both databases.");
        }
    } catch (Exception $e) {
        // Rollback transaction in case of an error
        $pdo->rollBack();
        mysqli_rollback($connect_azwarie);
        echo "<script>alert('Error: " . $e->getMessage() . "');</script>";
    }
} else {
    echo "<script>alert('DepartmentID is required for deletion.');</script>";
}

// Initialize filter variables
$filters = [
    'DepartmentID' => '',
    'DepartmentName' => '',
    'Location' => ''
];

// Build the filter query dynamically for department management
$whereClauses = [];
$params = [];

foreach ($filters as $key => $value) {
    if (isset($_GET[$key]) && !empty($_GET[$key])) {
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

// Construct SQL query for filtering
$query = "SELECT * FROM DEPARTMENT";
if (!empty($whereClauses)) {
    $query .= " WHERE " . implode(" AND ", $whereClauses);
}
$query .= " ORDER BY DepartmentID"; // You can change the sorting column as needed

// Prepare and execute the query
$stmt = $pdo->prepare($query);
foreach ($filters as $key => $value) {
    if (!empty($value)) {
        $stmt->bindValue(":$key", "%$value%"); // For text search, use LIKE '%value%'
    }
}
$stmt->execute();
$searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Delete Department</title>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700&family=Poppins:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css"> <!-- FontAwesome for icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
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
        /* Hero section styling */
        .hero-section {
            background-color: #007bff;
            color: white;
            padding: 30px 0;
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
        .content {
            padding: 100px 0;
            text-align: center;
        }
        form {
            margin-bottom: 10px;
        }
        /* Icon button styles */
        .icon-btn {
            background-color: transparent; /* No background */
            border: none; /* Remove border */
            padding: 5px;
            cursor: pointer;
            transition: transform 0.3s ease;
            outline: none; /* Remove outline */
        }
        /* Remove default background and outline on focus */
        .icon-btn:focus {
            background-color: transparent; /* Ensure no background color */
            outline: none; /* Remove outline */
        }
        .icon-btn i {
            font-size: 21.5px; /* Icon size */
        }
        .icon-btn.delete-btn {
            background-color: transparent; /* No background */
            border: none; /* No border */
            cursor: pointer;
            transition: transform 0.3s ease, color 0.3s ease;
        }
        .icon-btn.delete-btn i {
            color: #FF0000; /* Red for delete icon */
        }
        .icon-btn.delete-btn:hover i {
            color: rgb(134, 23, 34); /* Darker red for hover */
            transform: scale(1.2); /* Increase icon size on hover */
        }
        .icon-btn.delete-btn:active i {
            color: rgb(134, 23, 34); /* Dark red for active state */
            transform: scale(1); /* Reset icon size on active */
        }
        /* Focus effect (if needed) */
        .icon-btn:focus i {
            box-shadow: none; /* Remove any focus effect on the icon */
        }
    </style>
    
</head>
<body>
<!-- Include Navbar -->
<?php include 'searchstyle.php'; ?>

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
                <li class="nav-item <?php echo ($current_page == 'manageDept.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="manageDept.php?staffid=<?php echo $staffID; ?>" style="color: black;">Department Directory</a>
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

    <!-- Department Management Table -->
    <table id="deptTable" class="table table-striped" data-sort="asc">
        <thead>
            <tr>
                <th class="clickable" onclick="sortDeptTable(0)">Department ID</th>
                <th class="clickable" onclick="sortDeptTable(1)">Department Name</th>
                <th class="clickable" onclick="sortDeptTable(2)">Location</th>
                <th class="clickable" onclick="sortDeptTable(3)">Actions</th>
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
                            <!-- Delete Action -->
                            <form method="GET" action="deleteDept.php" style="display: inline;">
                                <input type="hidden" name="delete" value="<?= htmlspecialchars($dept['departmentid']) ?>">
                                <button type="submit" class="icon-btn delete-btn" onclick="return confirm('Are you sure you want to delete this department?');">
                                    <i class="fas fa-trash"></i> <!-- Updated Trash Icon -->
                                </button>
                            </form>
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

<!-- Include JavaScript -->
<script src="main.js"></script>
<script>
     function confirmDelete(departmentID) {
        if (confirm('Are you sure you want to delete department with ID ' + departmentID + '?')) {
            window.location.href = 'deleteDept.php?delete=' + departmentID;
        }
    }
    function toggleEditForm(departmentID) {
        const form = document.getElementById('editForm-' + departmentID);
        if (form) {
            // Toggle visibility
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
        } else {
            console.error('Form not found for department ID:', departmentID);
        }
    }

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