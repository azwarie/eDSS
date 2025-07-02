<?php
session_start(); // Start the session

// Include the database connection
include 'connection.php';
include 'azwarieConnect.php';

// This is code for current page to navbar to be darker to show the user is in that page
$current_page = basename($_SERVER['PHP_SELF']);

// Check if the user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    // Redirect to the login page if not logged in
    header("Location: login.php");
    exit;
}

// Retrieve the staff ID from the session
$staffID = $_SESSION['staffid']; // Assign staff ID from session

if (isset($_SESSION['errors'])) {
    $passwordError = $_SESSION['errors']['passwordError'] ?? '';
    $emailError = $_SESSION['errors']['emailError'] ?? '';
    $icError = $_SESSION['errors']['icError'] ?? '';
    unset($_SESSION['errors']); // Clear errors after displaying
}

try {
    // Handle "Edit Profile" button click
    if (isset($_POST['edit'])) {
        // If the user clicks "Edit Profile", the form will be in edit mode.
        $editing = true; 
    } else {
        $editing = false;
    }
    if (isset($_POST['update'])) {
        // Gather form inputs
        $name = $_POST['name'];
        $ic = $_POST['ic'];
        $phoneno = $_POST['phoneno'];
        $address = $_POST['address'];
        $email = $_POST['email'];
        $username = $_POST['username'];
        $password = trim($_POST['password']); // Trim spaces
    
        // Hash the password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
        // Debug: Log the password being updated
        echo "<script>console.log('Password being updated: " . $password . "');</script>";
        // Initialize error flags
        $hasError = false;
        $passwordError = $emailError = $icError = $phoneError = $usernameError = '';
    
        // Function to check if the email already exists in the database
        function checkIfEmailExists($pdo, $email, $staffID) {
            $sql = "SELECT COUNT(*) FROM STAFF WHERE Email = :email AND StaffID != :staffid";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':email', $email, PDO::PARAM_STR);
            $stmt->bindParam(':staffid', $staffID, PDO::PARAM_STR);
            $stmt->execute();
            return $stmt->fetchColumn() > 0;
        }
    
        // Function to check if the IC already exists in the database
        function checkIfICExists($pdo, $ic, $staffID) {
            $sql = "SELECT COUNT(*) FROM STAFF WHERE IC = :ic AND StaffID != :staffid";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':ic', $ic, PDO::PARAM_STR);
            $stmt->bindParam(':staffid', $staffID, PDO::PARAM_STR);
            $stmt->execute();
            return $stmt->fetchColumn() > 0;
        }
    
        // Function to check if the phone number already exists in the database
        function checkIfPhoneExists($pdo, $phoneno, $staffID) {
            $sql = "SELECT COUNT(*) FROM STAFF WHERE PhoneNo = :phoneno AND StaffID != :staffid";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':phoneno', $phoneno, PDO::PARAM_STR);
            $stmt->bindParam(':staffid', $staffID, PDO::PARAM_STR);
            $stmt->execute();
            return $stmt->fetchColumn() > 0;
        }
    
        // Function to check if the username already exists in the database
        function checkIfUsernameExists($pdo, $username, $staffID) {
            $sql = "SELECT COUNT(*) FROM STAFF WHERE Username = :username AND StaffID != :staffid";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':username', $username, PDO::PARAM_STR);
            $stmt->bindParam(':staffid', $staffID, PDO::PARAM_STR);
            $stmt->execute();
            return $stmt->fetchColumn() > 0;
        }
    
        // Password validation function
        function validatePassword($password) {
            $pattern = "/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[\W_]).{8,}$/";
            return preg_match($pattern, $password);
        }
    
        // Validate password
        if (!validatePassword($password)) {
            $passwordError = 'Password must be at least 6 characters long, contain one uppercase, one lowercase letter, a digit, and a special character.';
            $hasError = true;
        }
    
        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emailError = "Please enter a valid email address.";
            $hasError = true;
        } elseif (checkIfEmailExists($pdo, $email, $staffID)) {
            $emailError = "Email already exists in the system.";
            $hasError = true;
        }
    
        // Validate username
        if (checkIfUsernameExists($pdo, $username, $staffID)) {
            $usernameError = "Username already exists in the system.";
            $hasError = true;
        }
    
        // Validate IC
        if (!preg_match("/^\d{6}-\d{2}-\d{4}$/", $ic)) {
            $icError = 'Please enter a valid IC number (E.g: 020222-07-0222).';
            $hasError = true;
        } elseif (checkIfICExists($pdo, $ic, $staffID)) {
            $icError = "IC number already exists in the system.";
            $hasError = true;
        }
    
        // Validate phone number
        if (!preg_match("/^\d{3}-\d{7,8}$/", $phoneno)) {
            $phoneError = "Please enter a valid phone number (E.g: 012-4195477 or 012-41954777).";
            $hasError = true;
        } elseif (checkIfPhoneExists($pdo, $phoneno, $staffID)) {
            $phoneError = "Phone number already exists in the system.";
            $hasError = true;
        }
    
        // If there are errors, pass them back to the form
        if ($hasError) {
            $_SESSION['errors'] = [
                'passwordError' => $passwordError,
                'emailError' => $emailError,
                'icError' => $icError,
                'usernameError' => $usernameError,
                'phoneError' => $phoneError
            ];
            header("Location: nurseprofile.php?staffid=$staffID");
            exit();
        }
    
        function logAuditTrail($pdo, $staffID, $action, $description) {
            $sql = "INSERT INTO AUDIT_TRAIL (StaffID, Action, Description) VALUES (:staffID, :action, :description)";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':staffID', $staffID, PDO::PARAM_STR);
            $stmt->bindParam(':action', $action, PDO::PARAM_STR);
            $stmt->bindParam(':description', $description, PDO::PARAM_STR);
            $stmt->execute();
        }
        
        // If no errors, proceed with the update
        try {
            // Start a transaction for PostgreSQL (your main database)
            $pdo->beginTransaction();
    
            // Update staff details in the PostgreSQL database
            $query = "UPDATE STAFF SET name = :name, phoneno = :phoneno, address = :address, email = :email, username = :username, password = :password WHERE StaffID = :staffid";
            $stmt = $pdo->prepare($query);
            $stmt->execute([
                'name' => $name,
                'phoneno' => $phoneno,
                'address' => $address,
                'email' => $email,
                'username' => $username,
                'password' => $hashedPassword, // Use hashed password
                'staffid' => $staffID
            ]);
    
            // Commit PostgreSQL transaction
            $pdo->commit();
    
            // Start MySQL transaction for Azwarie database
            mysqli_autocommit($connect_azwarie, false);  // Disable autocommit for Azwarie database
    
            // Prepare the update statement for Azwarie's database
            $updateStmtAzwarie = $connect_azwarie->prepare("UPDATE STAFF SET Name = ?, PhoneNo = ?, Address = ?, Email = ?, Username = ?, Password = ? WHERE StaffID = ?");
    
            if ($updateStmtAzwarie) {
                // Bind parameters and execute the query for Azwarie database
                $updateStmtAzwarie->bind_param("sssssss", $name, $phoneno, $address, $email, $username, $hashedPassword, $staffID);
    
                if ($updateStmtAzwarie->execute()) {
                    // Commit MySQL transaction for Azwarie
                    mysqli_commit($connect_azwarie);
    
                    // Store success message in session
                    $_SESSION['success_message'] = 'Profile updated successfully!';
    
                    // Log the update action in the AUDIT_TRAIL table
                    logAuditTrail($pdo, $staffID, 'Profile Update', 'Admin updated their profile details.');
    
                    header("Location: nurseprofile.php?staffid=$staffID");
                    exit();
                } else {
                    // Rollback MySQL transaction if the update fails
                    mysqli_rollback($connect_azwarie);
                    echo "<script>alert('Failed to update staff in Azwarie\'s database.');</script>";
                }
            } else {
                // Rollback MySQL transaction if the query couldn't be prepared
                mysqli_rollback($connect_azwarie);
                echo "<script>alert('Failed to prepare the update statement for Azwarie\'s database.');</script>";
            }
        } catch (PDOException $e) {
            // Rollback PostgreSQL transaction if an error occurs
            $pdo->rollBack();
            echo "<script>alert('Error updating profile in PostgreSQL: " . $e->getMessage() . "');</script>";
    
            // Rollback MySQL transaction for Azwarie as well
            mysqli_rollback($connect_azwarie);
            echo "<script>alert('Error updating staff in Azwarie\'s database.');</script>";
        } catch (Exception $e) {
            // Catch any other exception
            echo "<script>alert('An error occurred: " . $e->getMessage() . "');</script>";
    
            // Rollback both PostgreSQL and MySQL transactions
            $pdo->rollBack();
            mysqli_rollback($connect_azwarie);
        }
    }

        
    // Query to fetch staff details
    $query = "SELECT * FROM STAFF WHERE StaffID = :staffid";
    $stmt = $pdo->prepare($query);
    $stmt->execute(['staffid' => $staffID]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$staff) {
        die("Staff not found.");
    }
} catch (PDOException $e) {
    die("Error retrieving data: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nurse - Profile Details</title>
    <!-- Include Remix Icons -->
    <link href="https://cdn.jsdelivr.net/npm/remixicon/fonts/remixicon.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700&family=Poppins:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
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
        .profile-container {
            background-color: white;
            width: 100%;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            padding: 20px;
        }
        .profile-header {
            display: flex;
            flex-direction: column;
            margin-bottom: 10px;
        }
        .profile-name h2 {
            font-size: 24px;
            font-weight: bold;
            margin: 0;
        }
        /* Profile info section */
        .profile-info-wrapper {
            display: flex;
            flex-wrap: wrap;
            gap: 22px;
        }
        /* Each profile info section takes up 48% width */
        .profile-info {
            flex: 1;
            min-width: 250px;
        }

        /* Section Titles - Display them inline */
        .section-titles {
            display: flex;
            justify-content: flex-start;
            margin-bottom: 10px;
        }
        .section-title {
            font-size: 16px;
            font-weight: bold;
            color: black;
            cursor: pointer;
            margin-right: 20px;  /* Spacing between titles */
            padding: 5px 10px;
            transition: background-color 0.3s;
        }
        .section-title:hover {
            background-color:rgba(224, 224, 224, 0.78);
            border-radius: 5px;
        }
        /* Section Content (hidden by default) */
        .section-content {
            display: none;  /* Hide sections initially */
            margin-top: 10px;
            padding: 10px;
            background-color: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        /* When the section is shown */
        .section-content.active {
            display: block;
        }
        /* Universal Button Styling (same size, padding, etc.) */
        button {
            font-size: 16px; /* Same font size for all buttons */
            color: white; /* White text */
            padding: 8px; /* Same padding for all buttons */
            border: none; /* Remove borders */
            border-radius: 5px; /* Rounded corners */
            cursor: pointer; /* Pointer cursor */
            transition: background-color 0.3s ease, transform 0.2s ease; /* Smooth transitions */
            margin: 5px; /* Space between buttons */
        }
        /* Edit Profile Button */
        .edit-btn {
            background-color: #007bff; /* Blue for edit */
        }
        .edit-btn:hover {
            background-color: #0056b3; /* Darker blue on hover */
        }
        .edit-btn:active {
            background-color: #003f7d; /* Even darker blue on active */
        }
        .edit-btn:focus {
            outline: none;
            box-shadow: 0 0 5px rgba(0, 123, 255, 0.5); /* Focus shadow */
        }
        /* Save Changes Button */
        .save-btn {
            background-color: #4CAF50; /* Green for save changes */
        }
        .save-btn:hover {
            background-color: #45a049; /* Darker green on hover */
        }
        .save-btn:active {
            background-color: #388e3c; /* Even darker green on active */
        }
        .save-btn:focus {
            outline: none;
            box-shadow: 0 0 5px rgba(0, 123, 255, 0.5); /* Focus shadow */
        }
              /* Cancel Profile Button */
              .cancel-btn {
            background-color: #dc3545; /* Red for cancel */
        }
        .cancel-btn:hover {
            background-color: #c82333; /* Darker red on hover */
        }
        .cancel-btn:active {
            background-color: #9e1b32; /* Even darker red on active */
        }
        .cancel-btn:focus {
            outline: none;
            box-shadow: 0 0 5px rgba(220, 53, 69, 0.5); /* Focus shadow */
        }
        /* Container Styling for Buttons */
        .edit-profile-button, .save-profile-button, .cancel-profile-button {
            display: inline-block;
            margin-top: 10px;
        }

        /* form for profile details */
        .form-group {
            margin-bottom: 15px;
        }
        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
        }
        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .content {
            padding: 100px 0;
            text-align: center;
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
<nav class="navbar navbar-expand-lg navbar-light" style="background-color: white; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
    <div class="container">
        <a class="navbar-brand" href="https://172.20.238.6/staff2/nurse/NurseLandingPage.php?staffid=<?php echo $staffID; ?>"  style="font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 700; color: #007bff; position: absolute; top: 10px; left: 15px;">WeCare</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation"   style="margin-left: 65px;">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
            <li class="nav-item <?php echo ($current_page == 'https://172.20.238.6/staff2/nurse/nursepage.php') ? 'active' : ''; ?>">
                    <a class="nav-link" href="https://172.20.238.6/staff2/nurse/nursepage.php?staffid=<?php echo $staffID; ?>"  style="color: black;">Home</a>
                </li>
            <li class="nav-item <?php echo ($current_page == 'https://172.20.238.6/staff2/nurse/nursedashboard.php') ? 'active' : ''; ?>">
                <a class="nav-link" href="https://172.20.238.6/staff2/nurse/nursedashboard.php?staffid=<?php echo $staffID; ?>" style="color: black;">Real-Time Dashboards</a>
            </li>
            <li class="nav-item <?php echo ($current_page == 'https://172.20.238.6/staff2/nurse/nurseliststaff.php') ? 'active' : ''; ?>">
                <a class="nav-link" href="https://172.20.238.6/staff2/nurse/nurseliststaff.php?staffid=<?php echo $staffID; ?>" style="color: black;">Staff Directory</a>
            </li>
            <li class="nav-item <?php echo ($current_page == 'https://172.20.238.6/staff2/nurse/nurselistdept.php') ? 'active' : ''; ?>">
                <a class="nav-link" href="https://172.20.238.6/staff2/nurse/nurselistdept.php?staffid=<?php echo $staffID; ?>" style="color: black;">Department Directory</a>
            </li>
            <li class="nav-item <?php echo ($current_page == 'nurseprofile.php') ? 'active' : ''; ?>">
                <a class="nav-link" href="https://172.20.238.6/staff2/nurse/nurseprofile.php?staffid=<?php echo $staffID; ?>" style="color: black;">Profile</a>
            </li>
            </ul>
        </div>
    </div>
</nav>


<!-- Hero Section -->
<div class="hero-section">
        <h1>Staff Profile Management </h1>
        <p>Profile Details</p>
    </div>
    
<div class="profile-container">

<!-- Success message here -->
<?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success" role="alert" style="margin: 20px; padding: 15px; border-radius: 5px; background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb;">
        <?php echo $_SESSION['success_message']; ?>
    </div>
    <?php unset($_SESSION['success_message']); // Clear the message after displaying ?>
<?php endif; ?>

    <form method="POST">
        <div class="section-titles">
            <div class="section-title" onclick="toggleSection('personalDetails')">
                <i class="ri-user-line"></i> Personal Details
            </div>
            <div class="section-title" onclick="toggleSection('jobDetails')">
                <i class="ri-briefcase-line"></i> Job Details
            </div>
            <div class="section-title" onclick="toggleSection('contactDetails')">
                <i class="ri-phone-line"></i> Contact Details
            </div>
            <div class="section-title" onclick="toggleSection('accountDetails')">
                <i class="ri-settings-2-line"></i> Account Details
            </div>
        </div>

        <!-- Personal Details Section -->
        <div class="section" id="personalDetails">
            <div class="section-content">
                <div class="form-group">
                    <label for="name">Name:</label>
                    <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($staff['name']); ?>" <?php echo $editing ? '' : 'readonly'; ?>>
                </div>
                <div class="form-group">
                    <label for="ic">NRIC:</label>
                    <input type="text" id="ic" name="ic" value="<?php echo htmlspecialchars($staff['ic']); ?>" <?php echo $editing ? '' : 'readonly'; ?> oninput="validateIC()">
                    <span id="icError" style="color:red;"></span>
                </div>
                <div class="form-group">
                    <label for="gender">Gender:</label>
                    <input type="text" id="gender" name="gender" value="<?php echo htmlspecialchars($staff['gender']); ?>" <?php echo $editing ? '' : 'readonly'; ?>>
                </div>
                <div class="form-group">
                    <label for="address">Address:</label>
                    <input type="text" id="address" name="address" value="<?php echo htmlspecialchars($staff['address']); ?>" <?php echo $editing ? '' : 'readonly'; ?>>
                </div>
            </div>
        </div>

        <!-- Job Details Section -->
        <div class="section" id="jobDetails">
            <div class="section-content">
                <div class="form-group">
                    <label for="staffid">Staff ID:</label>
                    <input type="text" name="staffid" value="<?php echo htmlspecialchars($staff['staffid']); ?>" readonly>
                </div>
                <div class="form-group">
                    <label for="role">Role:</label>
                    <input type="text" name="role" value="<?php echo htmlspecialchars($staff['role']); ?>" readonly>
                </div>
                <div class="form-group">
                    <label for="status">Status:</label>
                    <input type="text" name="status" value="<?php echo htmlspecialchars($staff['status']); ?>" readonly>
                </div>
                <div class="form-group">
                    <label for="departmentid">Department ID:</label>
                    <input type="text" name="departmentid" value="<?php echo htmlspecialchars($staff['departmentid']); ?>" readonly>
                </div>
            </div>
        </div>

        <!-- Contact Details Section -->
        <div class="section" id="contactDetails">
            <div class="section-content">
                <div class="form-group">
                    <label for="phoneno">Phone Number:</label>
                    <input type="text" id="phoneno" name="phoneno" value="<?php echo htmlspecialchars($staff['phoneno']); ?>" <?php echo $editing ? '' : 'readonly'; ?> oninput="validatePhone()">
                    <span id="phoneError" style="color:red;"></span>
                </div>
                <div class="form-group">
                    <label for="email">Email:</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($staff['email']); ?>" <?php echo $editing ? '' : 'readonly'; ?> oninput="validateEmail()">
                    <span id="emailError" style="color:red;"></span>
                </div>
            </div>
        </div>

        <!-- Account Details Section -->
        <div class="section" id="accountDetails">
            <div class="section-content">
                <div class="form-group">
                    <label for="username">Username:</label>
                    <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($staff['username']); ?>" <?php echo $editing ? '' : 'readonly'; ?>>
                </div>
                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="text" id="password" name="password" value="<?php echo htmlspecialchars($staff['password']); ?>" <?php echo $editing ? '' : 'readonly'; ?> oninput="validatePassword()">
                    <span id="passwordError" style="color:red;"></span>
                </div>
            </div>
        </div>

        <!-- Buttons: Edit Profile, Cancel and Save Changes -->
        <?php if ($editing): ?>
            <div class="save-profile-button">
                <button type="submit" name="update" class="save-btn">Save</button>            
            </div>
            <div class="cancel-profile-button">
                <button type="submit" id="cancelButton" class="cancel-btn">Cancel</button>
            </div>
                <?php else: ?>
                    <div class="edit-profile-button">
                        <button type="submit" name="edit" class="edit-btn">Edit</button>
                    </div>
                <?php endif; ?>
            </form>
        </div>


<script>
    // Toggle visibility of sections
    function toggleSection(sectionId) {
        // Get all sections
        var sections = document.querySelectorAll('.section');
        var sectionTitles = document.querySelectorAll('.section-title');

        // Loop through all sections and hide them
        sections.forEach(function (section) {
            section.querySelector('.section-content').style.display = 'none';
        });

        // Loop through all section titles and remove the active class
        sectionTitles.forEach(function (title) {
            title.classList.remove('active');
        });

        // Show the clicked section
        var section = document.getElementById(sectionId);
        section.querySelector('.section-content').style.display = 'block';

        // Add active class to the clicked section title
        var sectionTitle = document.querySelector(`[onclick="toggleSection('${sectionId}')"]`);
        sectionTitle.classList.add('active');
    }

    // Initially highlight the personal details section
    document.addEventListener('DOMContentLoaded', function() {
        toggleSection('personalDetails'); // Show the personal details section by default
    });
    // Validate Passwords Match
    function validatePasswords() 
    {
        const newPassword = document.getElementById('newPassword').value;
        const confirmPassword = document.getElementById('confirmPassword').value;

        if (newPassword !== confirmPassword) 
        {
            alert('Passwords do not match. Please try again.');
            return false;
        }
            return true;
        }
    function togglePasswordVisibility(fieldId, toggleIcon) {
        const passwordField = document.getElementById(fieldId);
        const isPassword = passwordField.type === "password";
        passwordField.type = isPassword ? "text" : "password";
        toggleIcon.classList.toggle('fa-eye', !isPassword);
        toggleIcon.classList.toggle('fa-eye-slash', isPassword);
    }
    // Validate IC (NRIC)
    function validateIC() {
        const ic = document.getElementById("ic").value;
        const icError = document.getElementById("icError");
        const icPattern = /^\d{6}-\d{2}-\d{4}$/;

        if (!ic.match(icPattern)) {
            icError.innerText = "Please enter a valid IC number (E.g: 020222-07-0222).";
        } else {
            icError.innerText = "";
        }
    }
    // Validate Phone Number
    function validatePhone() {
        const phone = document.getElementById("phoneno").value;
        const phoneError = document.getElementById("phoneError");
        const phonePattern = /^\d{3}-\d{7,8}$/;

        if (!phone.match(phonePattern)) {
            phoneError.innerText = "Please enter a valid phone number (E.g: 012-4195477 or 012-41954777).";
        } else {
            phoneError.innerText = "";
        }
    }
    // Validate Email
    function validateEmail() {
        const email = document.getElementById("email").value;
        const emailError = document.getElementById("emailError");
        const emailPattern = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;

        if (!email.match(emailPattern)) {
            emailError.innerText = "Please enter a valid email address.";
        } else {
            emailError.innerText = "";
        }
    }
    // Validate Password
    function validatePassword() {
        const password = document.getElementById("password").value;
        const passwordError = document.getElementById("passwordError");
        const passwordPattern = /^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[\W_]).{8,}$/;

        if (!password.match(passwordPattern)) {
            passwordError.innerText = "Password must be at least 6 characters long, contain one uppercase, one lowercase letter, a digit, and a special character.";
        } else {
            passwordError.innerText = "";
        }
    }
</script>
<script>
    // Fade out the success message after 5 seconds
    setTimeout(function() {
        var successMessage = document.querySelector('.alert-success');
        if (successMessage) {
            successMessage.style.transition = 'opacity 1s';
            successMessage.style.opacity = '0';
            setTimeout(function() {
                successMessage.remove();
            }, 1000);
        }
    }, 5000); // 5000 milliseconds = 5 seconds
</script>
</body>
</html>