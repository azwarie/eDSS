<?php
session_start();

// Include the database connections
include 'connection.php'; // Your PostgreSQL database connection
//include 'azwarieConnect.php'; // Azwarie's MySQL database connection

// Get the current page filename
$current_page = basename($_SERVER['PHP_SELF']);

// Check if the user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

// Retrieve the staff ID from the session
$staffID = $_SESSION['staffid'];

// Add a searchValue filter for the department name and location
$filters = [
    'DepartmentID' => isset($_GET['DepartmentID']) ? $_GET['DepartmentID'] : '',
    'DepartmentName' => isset($_GET['DepartmentName']) ? $_GET['DepartmentName'] : '',
    'Location' => isset($_GET['Location']) ? $_GET['Location'] : '',
    'searchValue' => isset($_GET['searchValue']) ? $_GET['searchValue'] : ''
];

// Initialize query components
$whereClauses = [];
$params = [];

// Loop through filters and apply them directly to the query
foreach ($filters as $key => $value) {
    if (!empty($value)) {
        if ($key === 'DepartmentID') {
            $whereClauses[] = "d.$key = :$key";
        } elseif ($key === 'searchValue') {   
            // General search across department fields
            $whereClauses[] = "(LOWER(DepartmentID) LIKE LOWER(:searchValue) OR
                                LOWER(DepartmentName) LIKE LOWER(:searchValue) OR
                                LOWER(Location) LIKE LOWER(:searchValue))";
            $params[":searchValue"] = "%" . strtolower($value) . "%";  // Use wildcards for partial matching
        } else {
            // Specific filters for DepartmentID, DepartmentName, Location
            $whereClauses[] = "LOWER($key) LIKE LOWER(:$key)";
        }
        $params[":$key"] = "%" . strtolower($value) . "%";  // Use wildcards for partial matching
    }            
}

// Base query to fetch the departments
$query = "SELECT DepartmentID, DepartmentName, Location FROM DEPARTMENT";

// Add where clauses if there are any filters
if (!empty($whereClauses)) {
    $query .= " WHERE " . implode(" AND ", $whereClauses);
}

$query .= " ORDER BY DepartmentID";  // Optionally sort by DepartmentID

// Prepare and execute the query with bound parameters
try {
    $stmt = $pdo->prepare($query);

    // Bind parameters dynamically
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->execute();
    $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor - Department Directory</title>
    <!-- DataTables CSS for styling tables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <!-- Montserrat and Poppins fonts from Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700&family=Poppins:wght@400;700&display=swap" rel="stylesheet">
    <!-- Bootstrap CSS for general layout and components -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome CSS for icons -->
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
        /* Make the navbar sticky */
        .navbar {
            position: sticky;
            top: 0;
            z-index: 1000; /* Ensure it stays above other content */
            background-color: white; /* Add a background color */
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); /* Optional: Add a shadow for better visibility */
        }
        .navbar .nav-link:hover {
            color: #007bff; /* Blue color on hover */
        }
        /* Highlight the active link with the specified blue color and bold text */
        .navbar .nav-item.active .nav-link {
            color: #007bff !important; /* Blue color for active page */
            font-weight: bold !important; /* Bold for active link */
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
        
    </style>
    
</head>
<body>
    <!-- Include Navbar -->
    <?php include 'searchstyle.php'; ?>
    <!-- Navigation Bar Menu -->
    <nav class="navbar navbar-expand-lg navbar-light">
    <div class="container">
        <a class="navbar-brand" href="https://172.20.238.6/staff2/doctor/DoctorLandingPage.php?staffid=<?php echo $staffID; ?>" style="font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 700; color: #007bff; position: absolute; top: 10px; left: 15px;">WeCare</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation"   style="margin-left: 65px;">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item <?php echo ($current_page == 'https://172.20.238.6/staff2/doctor/doctorpage.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="https://172.20.238.6/staff2/doctor/doctorpage.php?staffid=<?php echo $staffID; ?>"  style="color: black;">Home</a>
                </li>
                <li class="nav-item <?php echo ($current_page == 'https://172.20.238.6/staff2/doctor/doctordashboard.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="https://172.20.238.6/staff2/doctor/doctordashboard.php?staffid=<?php echo $staffID; ?>"  style="color: black;">Real-Time Dashboard</a>
                </li>
                <li class="nav-item <?php echo ($current_page == 'https://172.20.238.6/staff2/doctor/docliststaff.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="https://172.20.238.6/staff2/doctor/docliststaff.php?staffid=<?php echo $staffID; ?>"  style="color: black;">Staff Directory</a>
                </li>
                <li class="nav-item <?php echo ($current_page == 'doclistdept.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="https://172.20.238.6/staff2/doctor/doclistdept.php?staffid=<?php echo $staffID; ?>"  style="color: black;">Department Directory</a>
                </li>
                <li class="nav-item <?php echo ($current_page == 'https://172.20.238.6/staff2/doctor/doctorprofile.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="https://172.20.238.6/staff2/doctor/doctorprofile.php?staffid=<?php echo $staffID; ?>" style="color: black;">Profile</a>
                </li>
            </ul>
        </div>
    </div>
</nav>
    <!-- Hero Section -->
    <div class="hero-section">
        <h1>Department Directory</h1>
        <p>View Department List</p>
    </div>

    <!-- Search Form for Departments -->
    <form method="GET" id="searchForm">
        <div class="input-container">
            <span class="icon"><i class="fa fa-search"></i></span>
            <input type="text" id="searchValue" name="searchValue" value="<?= htmlspecialchars($_GET['searchValue'] ?? '') ?>" placeholder="Search staff">
            <span id="clearIcon" class="clear-icon" style="display: none;">&times;</span> <!-- X icon for clearing -->
        </div>
        <!-- Add a hidden input for staffid -->
        <input type="hidden" name="staffid" value="<?= htmlspecialchars($staffID) ?>">
    </form>
    <br><br>

    <!-- Button to trigger modal (at the bottom) -->
    <!-- <div class="add-department-button">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDepartmentModal">
                Add Department
            </button>
        </div> -->

    <!-- Modal for Adding Department -->
    <!-- <div class="modal fade" id="addDepartmentModal" tabindex="-1" aria-labelledby="addDepartmentModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" id="addDepartmentForm">
                        <div class="modal-header">
                            <h5 class="modal-title" id="addDepartmentModalLabel">Add New Department</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
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
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-primary">Add Department</button>
                        </div>
                    </form>
                </div>
            </div>
        </div> -->

        <!-- Department Management Table -->
        <table id="deptTable" class="table table-striped">
            <thead>
                <tr>
                    <th class="clickable" onclick="sortTable(0)">Department ID</th>
                    <th class="clickable" onclick="sortTable(1)">Department Name</th>
                    <th class="clickable" onclick="sortTable(2)">Location</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($searchResults)): ?>
                    <?php foreach ($searchResults as $dept): ?>
                        <tr>
                            <td><?= htmlspecialchars($dept['departmentid']) ?></td>
                            <td><?= htmlspecialchars($dept['departmentname']) ?></td>
                            <td><?= htmlspecialchars($dept['location']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                <tr>
                        <td colspan="3" class="text-center" style="font-weight: bold; color: red;">
                            No department records found for the search criteria.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

    <!-- Scroll to Top Button -->
    <button id="scrollUpBtn" onclick="scrollToTop()" class="fa fa-long-arrow-up"></button>                
    </div>
</section> 

<!-- Include JavaScript -->
<script src="main.js"></script>
<script>
document.getElementById('addDepartmentForm').addEventListener('submit', function(event) {
            event.preventDefault(); // Prevent the default form submission

            const departmentName = document.getElementById('departmentname').value.trim();
            const location = document.getElementById('location').value.trim();

            if (departmentName && location) {
                // Send data to the server using AJAX
                const formData = new FormData();
                formData.append('departmentname', departmentName);
                formData.append('location', location);

                fetch('manageDept.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(data => {
                    console.log('Server Response:', data);
                    alert('Department added successfully!');
                    // Close the modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('addDepartmentModal'));
                    modal.hide();
                    // Reload the page to reflect the changes
                    window.location.reload();
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to add department. Please try again.');
                });
            } else {
                alert('Please fill in all fields.');
            }
        });

    // Sort the table based on the clicked column
    function sortTable(columnIndex) {
        const table = document.getElementById("deptTable");
        const rows = Array.from(table.rows).slice(1); // Exclude the header row
        const ascending = table.getAttribute("data-sort") === "asc";

        rows.sort((a, b) => {
            const aText = a.cells[columnIndex].textContent.trim().toLowerCase();
            const bText = b.cells[columnIndex].textContent.trim().toLowerCase();
            return ascending ? aText.localeCompare(bText) : bText.localeCompare(aText);
        });

        rows.forEach(row => table.tBodies[0].appendChild(row)); // Reorder rows in DOM
        table.setAttribute("data-sort", ascending ? "desc" : "asc"); // Toggle sort order
    }

    // Get the button
    const scrollUpBtn = document.getElementById("scrollUpBtn");
    // When the user scrolls down 100px from the top of the document, show the button
    window.onscroll = function() 
    {
        if (document.body.scrollTop > 2 || document.documentElement.scrollTop >2) 
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
    const noResultsRow = document.querySelector("#deptTable tbody .no-results");
    if (!Array.from(tableRows).some(row => row.style.display !== "none")) {
        if (!noResultsRow) {
            // Create a new 'No records found' row if it doesn't already exist
            const newRow = document.createElement("tr");
            newRow.classList.add("no-results");
            newRow.innerHTML = `<td colspan="3" class="text-center">No department records found for the search criteria.</td>`;
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