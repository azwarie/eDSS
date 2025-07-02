<?php
session_start();

// Include the database connection
include 'connection.php'; 

// Check if the connection was successful
if (!$conn || $conn->connect_error) {
    die("Database connection failed: " . ($conn ? $conn->connect_error : 'Unknown error'));
}

// Set default timezone
date_default_timezone_set('Asia/Kuala_Lumpur');

// Get the current page filename
$current_page = basename($_SERVER['PHP_SELF']);

// Check if the user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

// Retrieve the staff ID from the session or GET parameter
$loggedInStaffID = $_SESSION['staffid'] ?? $_GET['staffid'] ?? null;

// Validate loggedInStaffID. The entire page depends on this.
if (empty($loggedInStaffID)) {
    die("Error: Logged-in staff ID is missing. Please log in again.");
}

// --- Initialize variables for form data and errors ---
$formData = [
    'Name' => '', 'IC' => '', 'Gender' => '', 'PhoneNo' => '', 'Address' => '',
    'Email' => '', 'Role' => '', 'Username' => '', 'Password' => '', 'Department' => ''
];
$errors = [];

// --- Fetch departments for the dropdown ---
$departments = [];
$dept_query_error = null;
try {
    $sql_dept = "SELECT DepartmentID, DepartmentName, Location FROM DEPARTMENT ORDER BY DepartmentName ASC";
    $result_dept = $conn->query($sql_dept);

    if ($result_dept) {
        $departments = $result_dept->fetch_all(MYSQLI_ASSOC);
        $result_dept->free();
    } else {
        throw new Exception("Failed to fetch departments: " . $conn->error);
    }
} catch (Exception $e) {
    $dept_query_error = "Error loading department list: " . $e->getMessage();
    error_log($dept_query_error);
}

// --- Handle Form Submission ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Gather and trim form inputs
    $formData['Name'] = trim($_POST['Name'] ?? '');
    $formData['IC'] = trim($_POST['IC'] ?? '');
    $formData['Gender'] = $_POST['Gender'] ?? '';
    $formData['PhoneNo'] = trim($_POST['PhoneNo'] ?? '');
    $formData['Address'] = trim($_POST['Address'] ?? '');
    $formData['Email'] = trim($_POST['Email'] ?? '');
    $formData['Role'] = $_POST['Role'] ?? '';
    $formData['Username'] = trim($_POST['Username'] ?? '');
    $formData['Password'] = $_POST['Password'] ?? '';
    $formData['Department'] = $_POST['Department'] ?? '';

    // --- Start Validation (No changes to validation logic) ---
    if (empty($formData['Name'])) $errors['nameError'] = "Name is required.";
    
    if (!preg_match("/^\d{6}-\d{2}-\d{4}$/", $formData['IC'])) {
        $errors['icError'] = "Invalid IC format (e.g., 020222-07-0222).";
    } else {
        $stmtCheck = $conn->prepare("SELECT StaffID FROM STAFF WHERE IC = ?");
        $stmtCheck->bind_param('s', $formData['IC']);
        $stmtCheck->execute();
        if ($stmtCheck->get_result()->num_rows > 0) $errors['icError'] = "IC number already exists.";
        $stmtCheck->close();
    }

    if (empty($formData['Gender'])) $errors['genderError'] = "Gender is required.";
    
    if (!preg_match("/^01\d-?\d{7,8}$/", $formData['PhoneNo'])) {
        $errors['phoneError'] = "Invalid Malaysian phone format (e.g., 012-3456789).";
    } else {
         $phoneToCheck = preg_replace('/-/', '', $formData['PhoneNo']);
         $stmtCheck = $conn->prepare("SELECT StaffID FROM STAFF WHERE REPLACE(PhoneNo, '-', '') = ?");
         $stmtCheck->bind_param('s', $phoneToCheck);
         $stmtCheck->execute();
         if ($stmtCheck->get_result()->num_rows > 0) $errors['phoneError'] = "Phone number already exists.";
         $stmtCheck->close();
    }

    if (empty($formData['Address'])) $errors['addressError'] = "Address is required.";

    if (!filter_var($formData['Email'], FILTER_VALIDATE_EMAIL)) {
        $errors['emailError'] = "Please enter a valid email address.";
    } else {
        $stmtCheck = $conn->prepare("SELECT StaffID FROM STAFF WHERE Email = ?");
        $stmtCheck->bind_param('s', $formData['Email']);
        $stmtCheck->execute();
        if ($stmtCheck->get_result()->num_rows > 0) $errors['emailError'] = "Email already exists.";
        $stmtCheck->close();
    }
    
    if (empty($formData['Role']) || !in_array($formData['Role'], ['DOCTOR', 'NURSE'])) {
        $errors['roleError'] = "A valid role (Doctor or Nurse) is required.";
    }
    
    if (empty($formData['Username'])) {
        $errors['usernameError'] = "Username is required.";
    } else {
        $stmtCheck = $conn->prepare("SELECT StaffID FROM STAFF WHERE Username = ?");
        $stmtCheck->bind_param('s', $formData['Username']);
        $stmtCheck->execute();
        if ($stmtCheck->get_result()->num_rows > 0) $errors['usernameError'] = "Username already exists.";
        $stmtCheck->close();
    }

    if (!preg_match("/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[\W_]).{8,}$/", $formData['Password'])) {
        $errors['passwordError'] = "Password must be at least 8 characters and include uppercase, lowercase, digit, and special character.";
    }

    if (empty($formData['Department'])) $errors['departmentError'] = "Department is required.";

    // --- If NO validation errors, proceed to insert ---
    if (empty($errors)) {
        try {
            // ** CRITICAL SECURITY: HASH THE PASSWORD **
            $hashedPassword = password_hash($formData['Password'], PASSWORD_DEFAULT);
            
            // The MySQL trigger now handles StaffID generation.
            $stmtInsert = $conn->prepare(
                // StaffID is NOT provided; the trigger will set it.
                "INSERT INTO STAFF (Name, IC, Gender, PhoneNo, Address, Role, Email, Username, Password, DepartmentID, Status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Available')"
            );
            
            if (!$stmtInsert) throw new Exception("Database prepare statement failed: " . $conn->error);

            // =================================================================
            // THE FIX IS HERE: The final 'i' was changed to 's'
            // This treats the DepartmentID as a string, resolving the foreign key error.
            // =================================================================
            $stmtInsert->bind_param('ssssssssss',
                $formData['Name'],
                $formData['IC'],
                $formData['Gender'],
                $formData['PhoneNo'],
                $formData['Address'],
                $formData['Role'],
                $formData['Email'],
                $formData['Username'],
                $hashedPassword,
                $formData['Department']
            );

            if ($stmtInsert->execute()) {
                $stmtInsert->close();
                header("Location: registerStaff.php?staffid=" . urlencode($loggedInStaffID) . "&success=1");
                exit();
            } else {
                 throw new Exception("Database execute statement failed: " . $stmtInsert->error);
            }

        } catch (Exception $e) {
             $errors['dbError'] = "Error saving new staff. Please contact support.";
             error_log("Staff registration failed: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Register Staff</title>
    <!-- CSS and Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700&family=Poppins:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
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
        .card { margin-top: 0; box-shadow: 0 4px 12px rgba(0,0,0,0.08); border-radius: 8px; border: none; }
        .card-header { background-color: #e9ecef; border-bottom: 1px solid #dee2e6; font-weight: 600; font-size: 1.2rem; padding: 1rem 1.5rem; }
        .card-body { padding: 2rem; }
        .invalid-feedback { display: block; }
        .input-group .form-control { padding-right: 3rem; }
        .input-group .toggle-password { position: absolute; top: 50%; right: 1rem; transform: translateY(-50%); cursor: pointer; z-index: 5; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-light">
    <div class="container">
        <a class="navbar-brand" href="AdminLandingPage.php?staffid=<?php echo htmlspecialchars($loggedInStaffID); ?>">eDSS</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item <?php echo ($current_page == 'adminpage.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="adminpage.php?staffid=<?php echo htmlspecialchars($loggedInStaffID); ?>">Home</a>
                </li>
                <li class="nav-item dropdown <?php echo ($current_page == 'admindashboard.php') ? 'active' : ''; ?>">
                    <a class="nav-link dropdown-toggle" href="#" id="dashboardsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        Real-Time Dashboards
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="dashboardsDropdown">
                        <li><a class="dropdown-item <?php echo ($current_page == 'admindashboard.php') ? 'active' : ''; ?>" href="admindashboard.php?staffid=<?php echo htmlspecialchars($loggedInStaffID); ?>">Dashboard Overview</a></li>
                    </ul>
                </li>
                <li class="nav-item dropdown <?php echo in_array($current_page, ['viewStaff.php', 'registerStaff.php', 'removeStaff.php', 'updateStaff.php']) ? 'active' : ''; ?>">
                    <a class="nav-link dropdown-toggle" href="#" id="staffDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        Manage Staff
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="staffDropdown">
                        <li><a class="dropdown-item <?php echo ($current_page == 'viewStaff.php') ? 'active' : ''; ?>" href="viewStaff.php?staffid=<?php echo htmlspecialchars($loggedInStaffID); ?>">Staff Directory</a></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'registerStaff.php') ? 'active' : ''; ?>" href="registerStaff.php?staffid=<?php echo htmlspecialchars($loggedInStaffID); ?>">Register Staff</a></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'updateStaff.php') ? 'active' : ''; ?>" href="updateStaff.php?staffid=<?php echo htmlspecialchars($loggedInStaffID); ?>">Update Staff</a></li>
                    </ul>
                </li>
                <li class="nav-item <?php echo ($current_page == 'manageDept.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="manageDept.php?staffid=<?php echo htmlspecialchars($loggedInStaffID); ?>">Department Directory</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="login.php?action=logout">Logout</a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="hero-section">
    <h1>Register New Staff</h1>
    <p>Enter details for the new staff member</p>
</div>

<div class="container mt-4 mb-5">
    <div class="card">
        <div class="card-header"><i class="fas fa-user-plus me-2"></i>Register New Staff Form</div>
        <div class="card-body">

             <?php if (!empty($errors['dbError'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($errors['dbError']); ?></div>
             <?php endif; ?>
             <?php if ($dept_query_error): ?>
                <div class="alert alert-warning"><i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($dept_query_error); ?></div>
             <?php endif; ?>
             <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i>Staff registered successfully! The database has automatically assigned a new Staff ID.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
             <?php endif; ?>

            <form method="POST" action="registerStaff.php?staffid=<?= htmlspecialchars($loggedInStaffID) ?>" novalidate>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="Name" class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control <?= isset($errors['nameError']) ? 'is-invalid' : '' ?>" id="Name" name="Name" value="<?= htmlspecialchars($formData['Name']) ?>" required>
                        <?php if (isset($errors['nameError'])): ?><div class="invalid-feedback"><?= $errors['nameError'] ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label for="IC" class="form-label">IC Number <span class="text-danger">*</span></label>
                        <input type="text" class="form-control <?= isset($errors['icError']) ? 'is-invalid' : '' ?>" id="IC" name="IC" placeholder="e.g., 020222-07-0222" value="<?= htmlspecialchars($formData['IC']) ?>" required>
                        <?php if (isset($errors['icError'])): ?><div class="invalid-feedback"><?= $errors['icError'] ?></div><?php endif; ?>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                         <label class="form-label d-block">Gender <span class="text-danger">*</span></label>
                         <div class="form-check form-check-inline">
                            <input class="form-check-input <?= isset($errors['genderError']) ? 'is-invalid' : '' ?>" type="radio" name="Gender" id="GenderMale" value="MALE" <?= ($formData['Gender'] == 'MALE') ? 'checked' : '' ?> required>
                            <label class="form-check-label" for="GenderMale">Male</label>
                         </div>
                         <div class="form-check form-check-inline">
                            <input class="form-check-input <?= isset($errors['genderError']) ? 'is-invalid' : '' ?>" type="radio" name="Gender" id="GenderFemale" value="FEMALE" <?= ($formData['Gender'] == 'FEMALE') ? 'checked' : '' ?> required>
                            <label class="form-check-label" for="GenderFemale">Female</label>
                         </div>
                         <?php if (isset($errors['genderError'])): ?><div class="invalid-feedback"><?= $errors['genderError'] ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label for="PhoneNo" class="form-label">Phone No <span class="text-danger">*</span></label>
                        <input type="tel" class="form-control <?= isset($errors['phoneError']) ? 'is-invalid' : '' ?>" id="PhoneNo" name="PhoneNo" placeholder="e.g., 012-3456789" value="<?= htmlspecialchars($formData['PhoneNo']) ?>" required>
                        <?php if (isset($errors['phoneError'])): ?><div class="invalid-feedback"><?= $errors['phoneError'] ?></div><?php endif; ?>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="Address" class="form-label">Address <span class="text-danger">*</span></label>
                    <textarea class="form-control <?= isset($errors['addressError']) ? 'is-invalid' : '' ?>" id="Address" name="Address" rows="3" required><?= htmlspecialchars($formData['Address']) ?></textarea>
                    <?php if (isset($errors['addressError'])): ?><div class="invalid-feedback"><?= $errors['addressError'] ?></div><?php endif; ?>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="Email" class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control <?= isset($errors['emailError']) ? 'is-invalid' : '' ?>" id="Email" name="Email" placeholder="staff@eDSS.com" value="<?= htmlspecialchars($formData['Email']) ?>" required>
                        <?php if (isset($errors['emailError'])): ?><div class="invalid-feedback"><?= $errors['emailError'] ?></div><?php endif; ?>
                    </div>
                     <div class="col-md-6">
                        <label for="Role" class="form-label">Role <span class="text-danger">*</span></label>
                        <select class="form-select <?= isset($errors['roleError']) ? 'is-invalid' : '' ?>" id="Role" name="Role" required>
                            <option value="" disabled <?= empty($formData['Role']) ? 'selected' : '' ?>>Select a role...</option>
                            <option value="DOCTOR" <?= ($formData['Role'] == 'DOCTOR') ? 'selected' : '' ?>>Doctor</option>
                            <option value="NURSE" <?= ($formData['Role'] == 'NURSE') ? 'selected' : '' ?>>Nurse</option>
                        </select>
                        <?php if (isset($errors['roleError'])): ?><div class="invalid-feedback"><?= $errors['roleError'] ?></div><?php endif; ?>
                    </div>
                </div>
                <div class="row mb-3">
                     <div class="col-md-6">
                        <label for="Username" class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" class="form-control <?= isset($errors['usernameError']) ? 'is-invalid' : '' ?>" id="Username" name="Username" value="<?= htmlspecialchars($formData['Username']) ?>" required>
                        <?php if (isset($errors['usernameError'])): ?><div class="invalid-feedback"><?= $errors['usernameError'] ?></div><?php endif; ?>
                    </div>
                     <div class="col-md-6">
                        <label for="Password" class="form-label">Password <span class="text-danger">*</span></label>
                         <div class="input-group">
                            <input type="password" class="form-control <?= isset($errors['passwordError']) ? 'is-invalid' : '' ?>" id="Password" name="Password" value="<?= htmlspecialchars($formData['Password']) ?>" required>
                            <span class="toggle-password" onclick="togglePasswordVisibility('Password')"><i class="fa fa-eye"></i></span>
                         </div>
                        <?php if (isset($errors['passwordError'])): ?><div class="invalid-feedback"><?= $errors['passwordError'] ?></div><?php endif; ?>
                    </div>
                </div>
                <div class="mb-4">
                    <label for="Department" class="form-label">Department <span class="text-danger">*</span></label>
                    <select class="form-select <?= isset($errors['departmentError']) ? 'is-invalid' : '' ?>" id="Department" name="Department" required>
                        <option value="" disabled <?= empty($formData['Department']) ? 'selected' : '' ?>>Select a department...</option>
                        <?php if (!empty($departments)): ?>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= htmlspecialchars($dept['DepartmentID']) ?>" <?= ($formData['Department'] == $dept['DepartmentID']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($dept['DepartmentName']) ?> (<?= htmlspecialchars($dept['Location']) ?>)
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <?php if (isset($errors['departmentError'])): ?><div class="invalid-feedback"><?= $errors['departmentError'] ?></div><?php endif; ?>
                </div>
                <button type="submit" name="register" class="btn btn-primary btn-lg"><i class="fas fa-user-plus me-2"></i>Register Staff</button>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function togglePasswordVisibility(fieldId) {
        const passwordField = document.getElementById(fieldId);
        const icon = passwordField.nextElementSibling.querySelector('i');
        if (passwordField.type === "password") {
            passwordField.type = "text";
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            passwordField.type = "password";
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }
    document.addEventListener('DOMContentLoaded', function() {
        const successAlert = document.querySelector('.alert-success');
        if (successAlert) {
            setTimeout(() => {
                 const alertInstance = bootstrap.Alert.getOrCreateInstance(successAlert);
                 if(alertInstance) alertInstance.close();
            }, 5000);
        }
    });
</script>
</body>
</html>
<?php
// Close the database connection cleanly
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>