<?php
session_start();

// Include the database connection (mysqli for azwarie_dss)
include 'connection.php'; // Should define $conn using mysqli

// Check connection
if (!$conn || $conn->connect_error) {
    die("Database connection failed: " . ($conn ? $conn->connect_error : 'Unknown error'));
}

// Set default timezone
date_default_timezone_set('Asia/Kuala_Lumpur'); // Replace with your timezone

// Get current page filename
$current_page = basename($_SERVER['PHP_SELF']);

// Check login status
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

// Retrieve the logged-in staff ID
$loggedInStaffID = $_SESSION['staffid'] ?? $_GET['staffid'] ?? null;
if (empty($loggedInStaffID)) {
    die("Admin Staff ID is missing or invalid.");
}

// --- Get Filters ---
$filter_staff = isset($_GET['filter_staff']) ? trim($_GET['filter_staff']) : '';
// Ensure date is in YYYY-MM-DD format for MySQL comparison, use null if empty/invalid
$from_date_input = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$from_date = (DateTime::createFromFormat('Y-m-d', $from_date_input)) ? $from_date_input : null;

// --- Pagination Variables ---
$page = isset($_GET['page']) && filter_var($_GET['page'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]) ? (int)$_GET['page'] : 1;
$records_per_page = isset($_GET['per_page']) && filter_var($_GET['per_page'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 5, "max_range" => 50]]) ? (int)$_GET['per_page'] : 10; // Default 10, allow 5-50
$offset = ($page - 1) * $records_per_page;

// --- Build SQL Query and Parameters ---
$base_sql = " FROM AUDIT_TRAIL WHERE 1=1 "; // Base query for count and data
$whereClauses = [];
$params = [];
$paramTypes = '';

// Add conditions based on filters
if (!empty($from_date)) {
    $whereClauses[] = "Timestamp >= ?"; // Use >= to include the selected date
    $params[] = $from_date . " 00:00:00"; // Start of the selected day
    $paramTypes .= 's';
}
if (!empty($filter_staff)) {
    $whereClauses[] = "StaffID = ?";
    $params[] = $filter_staff;
    $paramTypes .= 's';
}

// Append WHERE clauses if any
$sql_where = "";
if (!empty($whereClauses)) {
    $sql_where = " AND " . implode(" AND ", $whereClauses);
}

// --- Count Total Records ---
$sql_count = "SELECT COUNT(*) as total" . $base_sql . $sql_where;
$total_records = 0;
$query_error = null;

try {
    $stmt_count = $conn->prepare($sql_count);
    if ($stmt_count === false) throw new Exception("Count prepare failed: ".$conn->error);

    if (!empty($params)) {
        if (!$stmt_count->bind_param($paramTypes, ...$params)) throw new Exception("Count bind failed: ".$stmt_count->error);
    }
    if (!$stmt_count->execute()) throw new Exception("Count execute failed: ".$stmt_count->error);

    $result_count = $stmt_count->get_result();
    if ($result_count && $row_count = $result_count->fetch_assoc()) {
        $total_records = (int)$row_count['total'];
    } else { throw new Exception("Count fetch failed: ".$stmt_count->error); }

    if ($result_count) $result_count->free();
    $stmt_count->close();

} catch (Exception $e) {
    $query_error = "Error counting records: " . $e->getMessage();
    error_log($query_error);
    $total_records = 0; // Assume 0 on error
}

// Calculate total pages
$total_pages = ($records_per_page > 0) ? ceil($total_records / $records_per_page) : 0;
// Adjust page number if it's out of bounds
if ($page > $total_pages && $total_pages > 0) {
    $page = $total_pages;
    $offset = ($page - 1) * $records_per_page;
} elseif ($page < 1) {
    $page = 1;
    $offset = 0;
}


// --- Fetch Paginated Records ---
$sql_data = "SELECT AuditID, StaffID, Action, Description, Timestamp" . $base_sql . $sql_where . " ORDER BY Timestamp DESC LIMIT ? OFFSET ?";
$records = [];

// Add LIMIT and OFFSET params and types
$params[] = $records_per_page;
$paramTypes .= 'i'; // Integer for LIMIT
$params[] = $offset;
$paramTypes .= 'i'; // Integer for OFFSET

try {
    $stmt_data = $conn->prepare($sql_data);
    if ($stmt_data === false) throw new Exception("Data prepare failed: ".$conn->error);

    // Bind all parameters (filters + pagination)
    if (!$stmt_data->bind_param($paramTypes, ...$params)) throw new Exception("Data bind failed: ".$stmt_data->error);

    if (!$stmt_data->execute()) throw new Exception("Data execute failed: ".$stmt_data->error);

    $result_data = $stmt_data->get_result();
    if ($result_data) {
        $records = $result_data->fetch_all(MYSQLI_ASSOC);
        $result_data->free();
    } else { throw new Exception("Data fetch failed: ".$stmt_data->error); }

    $stmt_data->close();

} catch (Exception $e) {
    $query_error = ($query_error ? $query_error . "; " : "") . "Error fetching records: " . $e->getMessage(); // Append error
    error_log($query_error);
    $records = []; // Ensure empty on error
}

// --- Fetch Distinct Staff IDs for Filter Dropdown ---
$staff_list_filter = [];
$staff_query_error = null;
try {
    $staff_result = $conn->query("SELECT DISTINCT StaffID FROM AUDIT_TRAIL ORDER BY StaffID ASC");
    if ($staff_result) {
        while ($staff_row = $staff_result->fetch_assoc()) {
            $staff_list_filter[] = $staff_row['StaffID'];
        }
        $staff_result->free();
    } else { throw new Exception("Failed to fetch staff list for filter: " . $conn->error); }
} catch(Exception $e) {
     $staff_query_error = $e->getMessage();
     error_log("Error fetching staff list for filter: ".$staff_query_error);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Audit Trail Records</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700&family=Poppins:wght@400;700&display=swap" rel="stylesheet">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

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
        #scrollUpBtn { position: fixed; bottom: 25px; right: 25px; display: none; background-color: #007bff; color: white; border: none; border-radius: 50%; width: 45px; height: 45px; font-size: 20px; line-height: 45px; text-align: center; cursor: pointer; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2); z-index: 1000; transition: background-color 0.2s ease, opacity 0.3s ease; }
        #scrollUpBtn:hover { background-color: #0056b3; }
        .alert { margin-top: 1.25rem; border-radius: 5px; font-size: 0.95rem; }
        /* Audit Log Specific Styles */
        .card { margin-top: 0; box-shadow: 0 4px 12px rgba(0,0,0,0.08); border-radius: 8px; border: none; }
        .card-header { background-color: #e9ecef; border-bottom: 1px solid #dee2e6; font-weight: 600; font-size: 1.1rem; padding: 0.9rem 1.25rem; color: #495057; }
        .card-body { padding: 1.5rem; }
        .filter-section { margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid #eee; }
        .filter-section form { display: flex; flex-wrap: wrap; align-items: center; gap: 1rem; }
        .filter-section .form-label { margin-bottom: 0; white-space: nowrap; }
        .filter-section .form-control, .filter-section .form-select { min-width: 150px; max-width: 250px; flex-grow: 1; font-size: 0.9rem; padding: 0.4rem 0.75rem; }
        .audit-log-entry { padding: 1rem 0; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; }
        .audit-log-entry:last-child { border-bottom: none; }
        .audit-log-entry .action-details { flex-grow: 1; min-width: 200px; }
        .audit-log-entry .action { font-weight: 600; color: #333; }
        .audit-log-entry .description { color: #555; font-size: 0.9em; margin-top: 0.1rem; }
        .audit-log-entry .timestamp { color: #777; font-size: 0.85em; white-space: nowrap; }
        .pagination-section { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #eee; font-size: 0.9rem;}
        .pagination-controls nav { margin-bottom: 0; } /* Remove margin from nav inside flex */
        .records-per-page-form label { margin-bottom: 0; }
        .no-logs { text-align: center; padding: 3rem 1rem; color: #6c757d; }
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
                 <!-- Updated Links with loggedInStaffID -->
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
    <h1>Audit Trail Records</h1>
    <p>Track System Activities and Changes</p>
</div>

<div class="container mt-4 mb-5">
    <div class="card shadow-sm">
        <div class="card-header"><i class="fas fa-history me-2"></i>Audit Logs</div>
        <div class="card-body">

            <!-- Display Query/Staff List Error if any -->
            <?php if ($query_error || $staff_query_error): ?>
               <div class="alert alert-danger" role="alert">
                   <i class="fas fa-exclamation-triangle me-2"></i>
                   <?php echo htmlspecialchars($query_error ?: $staff_query_error); ?>
                   Please try again later or contact support.
               </div>
            <?php endif; ?>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="filterForm">
                    <!-- Hidden input for staffid (admin's ID) -->
                    <input type="hidden" name="staffid" value="<?= htmlspecialchars($loggedInStaffID) ?>">

                    <!-- Filter by Staff -->
                    <div>
                        <label for="filter_staff" class="form-label">Filter by Staff:</label>
                        <select name="filter_staff" id="filter_staff" class="form-select form-select-sm">
                            <option value="">All Staff</option>
                            <?php if (!empty($staff_list_filter)): ?>
                                <?php foreach ($staff_list_filter as $staff_id_filter): ?>
                                    <?php $selected = ($filter_staff == $staff_id_filter) ? 'selected' : ''; ?>
                                    <option value="<?= htmlspecialchars($staff_id_filter) ?>" <?= $selected ?>>
                                        <?= htmlspecialchars($staff_id_filter) ?>
                                    </option>
                                <?php endforeach; ?>
                             <?php elseif ($staff_query_error): ?>
                                 <option value="" disabled>Error loading staff</option>
                             <?php else: ?>
                                 <option value="" disabled>No staff found in logs</option>
                             <?php endif; ?>
                        </select>
                    </div>

                    <!-- Filter by From Date -->
                     <div>
                        <label for="from_date" class="form-label">From Date:</label>
                        <input type="date" name="from_date" id="from_date" value="<?= htmlspecialchars($from_date_input) ?>" class="form-control form-control-sm">
                     </div>

                      <!-- Optional: Rows Per Page -->
                      <div>
                          <label for="per_page" class="form-label">Show:</label>
                          <select name="per_page" id="per_page" class="form-select form-select-sm" onchange="this.form.submit()">
                              <option value="10" <?= $records_per_page == 10 ? 'selected' : '' ?>>10</option>
                              <option value="25" <?= $records_per_page == 25 ? 'selected' : '' ?>>25</option>
                              <option value="50" <?= $records_per_page == 50 ? 'selected' : '' ?>>50</option>
                          </select>
                          <span class="ms-1">records</span>
                      </div>

                    <!-- Submit button (optional, can rely on JS change event) -->
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter me-1"></i>Apply Filters</button>
                    <a href="adminaudittrail.php?staffid=<?= htmlspecialchars($loggedInStaffID) ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-undo me-1"></i>Reset</a>

                </form>
            </div>

            <!-- Audit Log Entries -->
            <div class="audit-log-entries mt-3">
                <?php if (empty($records)): ?>
                    <div class='no-logs text-center text-muted p-4'>
                         <i class="fas fa-file-alt fa-3x mb-3"></i>
                        <h5>No audit logs found matching your criteria.</h5>
                    </div>
                <?php else: ?>
                    <?php foreach ($records as $record): ?>
                        <div class='audit-log-entry'>
                            <div class="action-details">
                                <span class='action'>
                                    <i class="fas fa-user-circle me-1 text-secondary"></i><?= htmlspecialchars($record['StaffID'] ?? 'SYSTEM') ?>
                                    performed action: <strong><?= htmlspecialchars($record['Action'] ?? 'N/A') ?></strong>
                                </span>
                                <?php if (!empty($record['Description'])): ?>
                                     <div class='description'><?= htmlspecialchars($record['Description']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class='timestamp'>
                                <i class="fas fa-clock me-1"></i><?= htmlspecialchars(date('d M Y, H:i:s', strtotime($record['Timestamp'] ?? ''))) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Pagination Section -->
             <?php if ($total_pages > 1): ?>
            <div class="pagination-section mt-4">
                 <div class="text-muted">
                     Page <?= $page ?> of <?= $total_pages ?> (Total: <?= $total_records ?> records)
                 </div>
                <div class="pagination-controls">
                    <nav aria-label="Page navigation">
                        <ul class="pagination pagination-sm justify-content-end mb-0">
                            <?php // Previous Button ?>
                            <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                                <a class="page-link" href="?page=<?= $page - 1 ?>&per_page=<?= $records_per_page ?>&filter_staff=<?= urlencode($filter_staff) ?>&from_date=<?= urlencode($from_date_input) ?>&staffid=<?= urlencode($loggedInStaffID) ?>" aria-label="Previous">
                                    <span aria-hidden="true">«</span>
                                </a>
                            </li>

                            <?php // Page Number Links (simplified example) ?>
                            <?php // Display first page and ellipsis if needed
                                if ($page > 3) {
                                    echo '<li class="page-item"><a class="page-link" href="?page=1&per_page='.$records_per_page.'&filter_staff='.urlencode($filter_staff).'&from_date='.urlencode($from_date_input).'&staffid='.urlencode($loggedInStaffID).'">1</a></li>';
                                    if ($page > 4) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                }
                                // Display pages around current page
                                for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++) {
                                    echo '<li class="page-item '.($i == $page ? 'active' : '').'"><a class="page-link" href="?page='.$i.'&per_page='.$records_per_page.'&filter_staff='.urlencode($filter_staff).'&from_date='.urlencode($from_date_input).'&staffid='.urlencode($loggedInStaffID).'">'.$i.'</a></li>';
                                }
                                // Display last page and ellipsis if needed
                                if ($page < $total_pages - 2) {
                                    if ($page < $total_pages - 3) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                    echo '<li class="page-item"><a class="page-link" href="?page='.$total_pages.'&per_page='.$records_per_page.'&filter_staff='.urlencode($filter_staff).'&from_date='.urlencode($from_date_input).'&staffid='.urlencode($loggedInStaffID).'">'.$total_pages.'</a></li>';
                                }
                            ?>

                             <?php // Next Button ?>
                            <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                                <a class="page-link" href="?page=<?= $page + 1 ?>&per_page=<?= $records_per_page ?>&filter_staff=<?= urlencode($filter_staff) ?>&from_date=<?= urlencode($from_date_input) ?>&staffid=<?= urlencode($loggedInStaffID) ?>" aria-label="Next">
                                    <span aria-hidden="true">»</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>
             <?php endif; ?>

        </div> <!-- End card-body -->
    </div> <!-- End card -->
</div> <!-- End container -->

<!-- Scroll to Top Button -->
<button id="scrollUpBtn" onclick="scrollToTop()" title="Go to top"><i class="fas fa-arrow-up"></i></button>

<!-- Bootstrap JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<!-- Optional: main.js -->
<!-- <script src="main.js"></script> -->

<script>
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

    // --- Auto-submit form on filter change ---
    document.addEventListener('DOMContentLoaded', function () {
        const filterForm = document.getElementById('filterForm');
        const filterInputs = filterForm.querySelectorAll('select, input[type="date"]'); // Select date input too

        filterInputs.forEach(input => {
            input.addEventListener('change', function () {
                // When a filter changes, submit the form
                filterForm.submit();
            });
        });

         // --- Auto-dismiss alerts ---
         const alerts = document.querySelectorAll('.alert-danger, .alert-warning'); // Only dismiss errors/warnings automatically
         alerts.forEach(function(alert) {
             if (typeof bootstrap !== 'undefined' && bootstrap.Alert) {
                setTimeout(() => {
                    const alertInstance = bootstrap.Alert.getOrCreateInstance(alert);
                    if (alertInstance) alertInstance.close();
                }, 7000); // Hide after 7 seconds
             } else {
                 setTimeout(() => { alert.style.display = 'none'; }, 7000);
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