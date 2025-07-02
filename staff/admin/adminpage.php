<?php
session_start();

// --- CONFIGURATION ---
define('BACKUP_DIR', __DIR__ . '/backups/');
// Set the desired timezone for the application
date_default_timezone_set('Asia/Kuala_Lumpur');

// Include the PDO database connection.
include 'connection2.php';


// --- HELPER FUNCTIONS FOR BACKUP AND RESTORE (EMBEDDED AND FINAL) ---

/**
 * Sets a message to be displayed to the user on the next page load.
 */
function set_flash_message($message, $type = 'success') {
    $_SESSION['flash_message'] = ['text' => $message, 'type' => $type];
}

/**
 * Creates a database backup using pure PHP and PDO.
 * Includes 'DROP TABLE IF EXISTS' for robust restores.
 */
function create_pdo_backup(PDO $pdo, $backup_dir, &$error_message) {
    try {
        set_time_limit(300);
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        if (empty($tables)) {
            $error_message = "No tables found in the database.";
            return false;
        }

        $db_name = $pdo->query("SELECT DATABASE()")->fetchColumn();
        $sql_content = "-- Pure PHP PDO Backup\n";
        $sql_content .= "-- Server Version: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n";
        $sql_content .= "-- Generation Time: " . date('Y-m-d H:i:s') . "\n";
        $sql_content .= "-- Database: `" . $db_name . "`\n";
        $sql_content .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\nSET FOREIGN_KEY_CHECKS = 0;\nSET time_zone = \"+00:00\";\n\n";

        foreach ($tables as $table) {
            $sql_content .= "\n\n-- --------------------------------------------------------\n\n";
            $sql_content .= "--\n-- Structure for table `$table`\n--\n\n";
            $sql_content .= "DROP TABLE IF EXISTS `$table`;\n\n";

            $create_table_stmt = $pdo->query("SHOW CREATE TABLE `$table`");
            $create_table_row = $create_table_stmt->fetch(PDO::FETCH_ASSOC);
            $sql_content .= $create_table_row['Create Table'] . ";\n\n";
            
            $data_stmt = $pdo->query("SELECT * FROM `$table`");
            if ($data_stmt->rowCount() > 0) {
                $sql_content .= "--\n-- Dumping data for table `$table`\n--\n\n";
                while($row = $data_stmt->fetch(PDO::FETCH_ASSOC)) {
                    $values = [];
                    foreach ($row as $value) {
                        $values[] = $value === null ? "NULL" : $pdo->quote($value);
                    }
                    $sql_content .= "INSERT INTO `$table` VALUES(" . implode(',', $values) . ");\n";
                }
            }
        }
        
        $sql_content .= "\nSET FOREIGN_KEY_CHECKS = 1;\n";

        $file_name = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
        if (file_put_contents($backup_dir . $file_name, $sql_content) === false) {
            $error_message = "Could not write to file. Check folder permissions for: " . $backup_dir;
            return false;
        }
        return true;
    } catch (PDOException $e) {
        $error_message = "A PDO exception occurred during backup: " . $e->getMessage();
        return false;
    }
}

/**
 * Restores a database from an SQL file.
 * NOTE: Transactions are removed because DDL statements (DROP/CREATE) cause implicit commits.
 */
function restore_pdo_backup(PDO $pdo, $file_path, &$error_message) {
    try {
        set_time_limit(300);
        $sql_content = file_get_contents($file_path);
        if ($sql_content === false) {
            $error_message = "Could not read the backup file.";
            return false;
        }
        
        // ★★★★★ THE FIX IS HERE ★★★★★
        // Execute the commands directly without a transaction wrapper.
        $pdo->exec($sql_content);
        
        return true;

    } catch (PDOException $e) {
        // If an error occurs, it will be caught here.
        $error_message = "Restore failed. Error: " . $e->getMessage();
        return false;
    }
}


// --- PAGE SETUP AND SECURITY ---
$current_page = basename($_SERVER['PHP_SELF']);

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}
$staffID = $_SESSION['staffid'];

// Ensure the backup directory exists
if (!is_dir(BACKUP_DIR)) {
    mkdir(BACKUP_DIR, 0775, true);
}


// --- HANDLE POST REQUESTS (Backup & Restore) using PURE PDO METHOD ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error_msg = '';

    // Handle Create Backup
    if (isset($_POST['backup'])) {
        if (create_pdo_backup($pdo, BACKUP_DIR, $error_msg)) {
            set_flash_message('Backup created successfully!', 'success');
        } else {
            set_flash_message("Backup Failed: " . htmlspecialchars($error_msg), 'danger');
        }
    }

    // Handle Restore
    if (isset($_POST['restore'])) {
        $file_to_restore = basename($_POST['selected_backup']);
        $full_path = BACKUP_DIR . $file_to_restore;

        if (file_exists($full_path)) {
            if (restore_pdo_backup($pdo, $full_path, $error_msg)) {
                set_flash_message("Database restored successfully from '{$file_to_restore}'.", 'success');
            } else {
                set_flash_message("Restore Failed: " . htmlspecialchars($error_msg), 'danger');
            }
        } else {
            set_flash_message('Selected backup file not found.', 'warning');
        }
    }
    
    // Redirect after processing to prevent form resubmission
    header("Location: " . $_SERVER['PHP_SELF'] . "?staffid=" . $staffID);
    exit;
}

// --- HANDLE DOWNLOAD REQUEST ---
if (isset($_GET['download_file'])) {
    $file_name = basename($_GET['download_file']);
    $file_path = BACKUP_DIR . $file_name;
    if (is_readable($file_path)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file_name . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        exit;
    }
}


// --- DATA FOR THE VIEW ---
$staffURL = "https://172.20.238.6/staff2/admin/adminpage.php?staffid=" .$staffID;
$admissionURL = "http://localhost/dss/admission/admin/index.php?staffid=" .$staffID;
$inventoryURL = "https://172.30.41.2/resource_allocation/admin/index.php?staffid=" .$staffID;
$patientURL = "https://172.24.214.126/dss/admin/index.php?staffid=" .$staffID;

$flash_message = $_SESSION['flash_message'] ?? null;
unset($_SESSION['flash_message']);

$backup_files = array_filter(scandir(BACKUP_DIR), fn($file) => pathinfo($file, PATHINFO_EXTENSION) == 'sql');
rsort($backup_files);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Maintenance - Backup & Recovery</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700&family=Poppins:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Your original CSS remains here */
        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f8f9fa; /* A light background for the whole page */
        }
        /*** Navbar ***/
        .navbar .dropdown-toggle::after { border: none; content: "\f107"; font-family: "Font Awesome 5 Free"; font-weight: 900; vertical-align: middle; margin-left: 8px; }
        .navbar .nav-link:hover { color: #007bff; }
        .navbar .nav-item.active .nav-link { color: #007bff !important; font-weight: bold !important; }
        .navbar { position: sticky; top: 0; z-index: 1000; background-color: white; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); }
        @media (min-width: 992px) {
            .navbar .nav-item:hover .dropdown-menu { top: 100%; visibility: visible; transition: .5s; opacity: 1; }
            .navbar .nav-item .dropdown-menu { display: block; border: none; margin-top: 0; top: 150%; opacity: 0; visibility: hidden; transition: .5s; }
        }
        
        /* Scroll to Top Button */
        #scrollUpBtn { position: fixed; bottom: 20px; right: 20px; display: none; background-color: #007bff; color: white; border: none; border-radius: 50%; padding: 10px 15px; font-size: 20px; cursor: pointer; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2); z-index: 1000; }
        #scrollUpBtn:hover { background-color: #0056b3; }

        /* --- NEW & IMPROVED STYLES FOR BACKUP/RECOVERY --- */
        .maintenance-card {
            background-color: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            border: none;
            margin-top: 2rem;
        }
        .card-header-custom {
            background: linear-gradient(135deg, #0d6efd, #0558d3);
            color: white;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
            padding: 1.5rem;
            border-bottom: 0;
        }
        .card-header-custom h2 { margin-bottom: 0.25rem; }
        .btn-gradient-success {
            background-image: linear-gradient(to right, #198754, #146c43);
            border: none;
            color: white;
            transition: all 0.3s ease;
        }
        .btn-gradient-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(25, 135, 84, 0.4);
        }
        .backup-list-table th { font-weight: 600; }
        .alert { border-left-width: 5px; }
        .vr { opacity: 0.15; }
    </style>
</head>
<body>

<!-- Your Original Navigation Bar Menu (Unchanged) -->
<nav class="navbar navbar-expand-lg navbar-light" style="background-color: white; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
    <div class="container">
        <a class="navbar-brand" href="https://localhost/dss/staff/admin/AdminLandingPage.php?staffid=<?php echo $staffID; ?>" style="font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 700; color: #007bff; position: absolute; top: 10px; left: 15px;">eDSS</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation" style="margin-left: 65px;">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item <?php echo ($current_page == 'adminpage.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="adminpage.php?staffid=<?php echo $staffID; ?>" style="color: black;">Home</a>
                </li>
                <li class="nav-item dropdown <?php echo ($current_page == 'admindashboard.php') ? 'active' : ''; ?>" style="position: relative;">
                    <a class="nav-link dropdown-toggle" href="#" id="realTimeDashboardsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="color: black; cursor: pointer;">
                        Real-Time Dashboards
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="realTimeDashboardsDropdown">
                        <li><a class="dropdown-item <?php echo ($current_page == 'admindashboard.php') ? 'active' : ''; ?>" href="admindashboard.php?staffid=<?php echo $staffID; ?>">Dashboard Overview</a></li>
                    </ul>
                </li>
                <li class="nav-item dropdown <?php echo ($current_page == 'viewStaff.php' || $current_page == 'registerStaff.php' || $current_page == 'removeStaff.php' || $current_page == 'updateStaff.php') ? 'active' : ''; ?>" style="position: relative;">
                    <a class="nav-link dropdown-toggle" href="#" id="manageStaffDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="color: black; cursor: pointer;">
                        Manage Staff
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="manageStaffDropdown">
                        <li><a class="dropdown-item <?php echo ($current_page == 'viewStaff.php') ? 'active' : ''; ?>" href="viewStaff.php?staffid=<?php echo $staffID; ?>">Staff Directory</a></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'registerStaff.php') ? 'active' : ''; ?>" href="registerStaff.php?staffid=<?php echo $staffID; ?>">Register Staff</a></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'updateStaff.php') ? 'active' : ''; ?>" href="updateStaff.php?staffid=<?php echo $staffID; ?>">Update Staff</a></li>
                    </ul>
                </li>
                <li class="nav-item <?php echo ($current_page == 'manageDept.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="manageDept.php?staffid=<?php echo $staffID; ?>" style="color: black;">Department Directory</a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- Main Content Area -->
<div class="container my-4">

    <!-- Flash Message Display -->
    <?php if ($flash_message): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flash_message['type']); ?> alert-dismissible fade show" role="alert">
        <i class="fas <?php echo ($flash_message['type'] == 'success') ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?> me-2"></i>
        <?php echo htmlspecialchars($flash_message['text']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <!-- NEW REDESIGNED Backup and Recovery Section -->
    <div class="card maintenance-card">
        <div class="card-header-custom text-center">
            <h2><i class="fas fa-cogs me-2"></i>System Backup & Recovery</h2>
            <p class="mb-0">Manage database backup and recovery processes.</p>
        </div>
        <div class="card-body p-4 p-md-5">

            <div class="row g-5">
                <!-- Create Backup Section -->
                <div class="col-md-5">
                    <h4 class="mb-3"><i class="fas fa-database me-2 text-primary"></i>Create a Backup</h4>
                    <p class="text-muted small">Create a complete snapshot of the database. This file can be used to restore the system to the current state.</p>
                    <form method="POST" onsubmit="return confirm('Are you sure you want to create a new database backup?');">
                        <button type="submit" name="backup" class="btn btn-primary w-100 py-2">
                            <i class="fas fa-plus-circle me-2"></i>Create New Backup
                        </button>
                    </form>
                </div>

                <!-- Divider -->
                <div class="col-md-1 d-none d-md-flex align-items-center justify-content-center">
                    <div class="vr"></div>
                </div>

                <!-- Restore Backup Section -->
                <div class="col-md-6">
                    <h4 class="mb-3"><i class="fas fa-undo me-2 text-success"></i>Restore from Backup</h4>
                    <p class="text-muted small">Select a backup file from the list to restore the database. <strong class="text-danger">Warning:</strong> This will overwrite all current data.</p>
                    
                    <?php if (empty($backup_files)): ?>
                        <div class="alert alert-info mt-3">No backup files found. Create a backup first.</div>
                    <?php else: ?>
                    <form method="POST" onsubmit="return confirm('WARNING: This will overwrite the current database with the selected backup. This action cannot be undone. Are you absolutely sure?');">
                        <div class="input-group">
                            <label class="input-group-text" for="selected_backup"><i class="fas fa-file-alt"></i></label>
                            <select class="form-select" id="selected_backup" name="selected_backup" required>
                                <option value="" disabled selected>Choose a backup file to restore...</option>
                                <?php foreach ($backup_files as $file): ?>
                                    <option value="<?php echo htmlspecialchars($file); ?>"><?php echo htmlspecialchars($file); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" name="restore" class="btn btn-gradient-success w-100 mt-3 py-2">
                            <i class="fas fa-history me-2"></i>Restore Selected Backup
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <hr class="my-5">

            <!-- Available Backups List -->
            <h4 class="mb-3"><i class="fas fa-list-ul me-2 text-secondary"></i>Available Backups</h4>
            <div class="table-responsive">
                <table class="table table-hover table-striped backup-list-table">
                    <thead class="table-light">
                        <tr>
                            <th>Backup File</th>
                            <th>Date Created</th>
                            <th>File Size</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($backup_files)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">No backups available.</td></tr>
                        <?php else: ?>
                            <?php foreach ($backup_files as $file): 
                                $file_path = BACKUP_DIR . $file;
                                $file_size = is_readable($file_path) ? round(filesize($file_path) / 1024, 2) . ' KB' : 'N/A';
                                $file_date = is_readable($file_path) ? date("Y-m-d H:i:s", filemtime($file_path)) : 'N/A';
                            ?>
                            <tr>
                                <td><i class="fas fa-file-archive text-primary me-2"></i><?php echo htmlspecialchars($file); ?></td>
                                <td><?php echo $file_date; ?></td>
                                <td><?php echo $file_size; ?></td>
                                <td class="text-end">
                                    <a href="?staffid=<?php echo $staffID; ?>&download_file=<?php echo urlencode($file); ?>" class="btn btn-sm btn-info" title="Download">
                                        <i class="fas fa-download"></i> Download
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Scroll to Top Button (Unchanged) -->
<button id="scrollUpBtn" onclick="scrollToTop()" class="fa fa-long-arrow-up"></button>

<!-- Include JavaScript (Unchanged) -->
<script>
// Get the button
const scrollUpBtn = document.getElementById("scrollUpBtn");
// When the user scrolls down, show the button
window.onscroll = function() {
    if (document.body.scrollTop > 20 || document.documentElement.scrollTop > 20) {
        scrollUpBtn.style.display = "block";
    } else {
        scrollUpBtn.style.display = "none";
    }
};
// When the button is clicked, scroll to the top
function scrollToTop() {
    window.scrollTo({ top: 0, behavior: "smooth" });
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>