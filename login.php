<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'connection.php';

$formToShow = $_GET['formToShow'] ?? 'loginForm';

// The logAuditTrail() function has been removed.

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // LOGIN logic
    if (isset($_POST['login'])) {
        if (empty($_POST['username']) || empty($_POST['password'])) {
             $_SESSION['login_error'] = 'Username or password is missing.';
        } else {
            $username = $_POST['username'];
            $password = $_POST['password'];
            $username_clean = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');

            $sql = "SELECT StaffID, Username, Password, Role FROM STAFF WHERE Username = ?";
            $stmt = $conn->prepare($sql);

            if ($stmt) {
                $stmt->bind_param('s', $username_clean);
                $stmt->execute();
                $result = $stmt->get_result();
                $user = $result->fetch_assoc();
                $stmt->close();

                 // Use password_verify for secure comparison
                 if ($user && password_verify($password, $user['Password'])) {
                    $_SESSION['loggedin'] = true;
                    $_SESSION['username'] = $user['Username'];
                    $_SESSION['staffid'] = $user['StaffID'];
                    // Removed audit trail call

                    $redirectUrl = '';
                    $userRole = isset($user['Role']) ? strtoupper($user['Role']) : 'UNKNOWN';

                    switch ($userRole) {
                        case 'ADMINISTRATOR':
                            $redirectUrl = "staff/admin/AdminLandingPage.php";
                            break;
                        case 'DOCTOR':
                            $redirectUrl = "staff/doctor/DoctorLandingPage.php";
                            break;
                        case 'NURSE':
                            $redirectUrl = "staff/nurse/NurseLandingPage.php";
                            break;
                        default:
                            $_SESSION['login_error'] = 'Invalid user role configured.';
                            // Removed audit trail call
                            break;
                    }
                    
                    if ($redirectUrl) {
                        $separator = (strpos($redirectUrl, '?') === false) ? '?' : '&';
                        header("Location: {$redirectUrl}{$separator}staffid=". urlencode($user['StaffID']));
                        exit;
                    }

                } else {
                    $_SESSION['login_error'] = 'Invalid username or password.';
                    // Removed audit trail call
                }
            } else {
                 $_SESSION['login_error'] = 'An error occurred during login preparation.';
                 // Removed audit trail call
            }
        } 

        if (isset($_SESSION['login_error'])) {
             $redirectQuery = http_build_query(['formToShow' => 'loginForm']);
             header("Location: " . htmlspecialchars($_SERVER["PHP_SELF"]) . "?" . $redirectQuery);
             exit();
         }
    }

    // Register logic
    if (isset($_POST['register'])) {
        $Name = $_POST['Name'] ?? '';
        $IC = $_POST['IC'] ?? '';
        $Gender = $_POST['Gender'] ?? '';
        $PhoneNo = $_POST['PhoneNo'] ?? '';
        $Address = $_POST['Address'] ?? '';
        $Email = $_POST['Email'] ?? '';
        $Role = $_POST['Role'] ?? '';
        $Username = $_POST['Username'] ?? '';
        $Password = $_POST['Password'] ?? '';
        $Department = $_POST['Department'] ?? '';

        $errors = [];
         if (!preg_match("/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[\W_]).{8,}$/", $Password)) $errors['passwordError'] = 'Password too weak (min 8 chars, upper, lower, digit, symbol).';
         if (!filter_var($Email, FILTER_VALIDATE_EMAIL)) $errors['emailError'] = 'Please enter a valid email address.';
         if (!preg_match("/^\d{6}-\d{2}-\d{4}$/", $IC)) $errors['icError'] = 'Invalid IC format (e.g., 020222-07-0222).';
         if (!preg_match("/^\d{3}-?\d{7,8}$/", $PhoneNo)) $errors['phoneError'] = 'Invalid phone format (e.g., 012-3456789 or 0123456789).';
         if (empty($Name)) $errors['nameError'] = 'Name is required.';
         if (empty($Gender)) $errors['genderError'] = 'Please select a gender.';
         if (empty($Address)) $errors['addressError'] = 'Address is required.';
         if (empty($Role)) $errors['roleError'] = 'Please select a role.';
         if (empty($Username)) $errors['usernameError'] = 'Username is required.';
         if (empty($Department)) $errors['departmentError'] = 'Please select a department.';

        if (empty($errors)) {
             $checkSql = "SELECT StaffID FROM STAFF WHERE Username = ? OR Email = ?";
             $checkStmt = $conn->prepare($checkSql);
             if ($checkStmt) {
                 $checkStmt->bind_param('ss', $Username, $Email);
                 $checkStmt->execute();
                 if ($checkStmt->get_result()->num_rows > 0) {
                     $errors['generalError'] = 'Username or Email is already registered.';
                 }
                 $checkStmt->close();
             } else {
                 $errors['dbError'] = 'Error checking existing user. Please try again.';
             }
        }

        if (!empty($errors)) {
            $_SESSION['register_errors'] = $errors;
            $_SESSION['register_data'] = $_POST;
            header("Location: " . htmlspecialchars($_SERVER["PHP_SELF"]) . "?formToShow=registerForm");
            exit();
        }

        $rolePrefix = ($Role === 'DOCTOR') ? 'D' : 'N';
        $StaffID = '';
        $rolePrefixPattern = $rolePrefix . '%';
        $sql_max_id = "SELECT MAX(StaffID) AS max_id FROM STAFF WHERE StaffID LIKE ?";
        $stmtMaxId = $conn->prepare($sql_max_id);
        if ($stmtMaxId) {
             $stmtMaxId->bind_param('s', $rolePrefixPattern); $stmtMaxId->execute(); $resultMaxId = $stmtMaxId->get_result(); $row = $resultMaxId->fetch_assoc(); $max_staff_id = $row['max_id']; $stmtMaxId->close();
             if ($max_staff_id) { $numeric_part = (int) substr($max_staff_id, 1); $new_numeric_part = $numeric_part + 1; $StaffID = $rolePrefix . str_pad($new_numeric_part, 5, '0', STR_PAD_LEFT); } else { $StaffID = $rolePrefix . '00001'; }
        } else {
             $_SESSION['register_errors'] = ['dbError' => 'Error generating staff ID. Please try again.']; $_SESSION['register_data'] = $_POST;
             header("Location: " . htmlspecialchars($_SERVER["PHP_SELF"]) . "?formToShow=registerForm"); exit();
        }

        $hashedPassword = password_hash($Password, PASSWORD_DEFAULT);

        $staffInsertQuery = "INSERT INTO STAFF (StaffID, Name, IC, Gender, PhoneNo, Address, Role, Email, Username, Password, DepartmentID) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmtInsert = $conn->prepare($staffInsertQuery);
        if ($stmtInsert) {
             $stmtInsert->bind_param('sssssssssss', $StaffID, $Name, $IC, $Gender, $PhoneNo, $Address, $Role, $Email, $Username, $hashedPassword, $Department);
            if ($stmtInsert->execute()) {
                $_SESSION['success_message'] = 'Registration successful! Please log in.';
                header("Location: " . htmlspecialchars($_SERVER["PHP_SELF"]) . "?formToShow=loginForm");
                exit();
            } else {
                 $_SESSION['register_errors'] = ['dbError' => 'Error saving registration. Please try again.']; $_SESSION['register_data'] = $_POST;
                 header("Location: " . htmlspecialchars($_SERVER["PHP_SELF"]) . "?formToShow=registerForm"); exit();
            }
            $stmtInsert->close();
        } else {
             $_SESSION['register_errors'] = ['dbError' => 'Error preparing registration. Please try again later.']; $_SESSION['register_data'] = $_POST;
             header("Location: " . htmlspecialchars($_SERVER["PHP_SELF"]) . "?formToShow=registerForm"); exit();
        }
    }

    // Forgot Password logic
    if (isset($_POST['forgotPassword'])) {
        $email = $_POST['Email'] ?? '';
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
             $_SESSION['forgot_error'] = 'Please enter a valid email address.';
        } else {
            $sql = "SELECT StaffID FROM STAFF WHERE Email = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                 $stmt->bind_param('s', $email); $stmt->execute(); $result = $stmt->get_result();
                 if ($result->num_rows > 0) {
                     header("Location: " . htmlspecialchars($_SERVER["PHP_SELF"]) . "?formToShow=resetPasswordForm&email=" . urlencode($email));
                     exit();
                 } else {
                     $_SESSION['forgot_error'] = 'Email address not found.';
                 }
                 $stmt->close();
            } else {
                 $_SESSION['forgot_error'] = 'An error occurred checking the email.';
            }
        }
         if(isset($_SESSION['forgot_error'])) {
             header("Location: " . htmlspecialchars($_SERVER["PHP_SELF"]) . "?formToShow=forgotPasswordForm");
             exit();
         }
    }

    // Reset Password logic
    if (isset($_POST['resetPassword'])) {
        $newPassword = $_POST['NewPassword'] ?? '';
        $confirmPassword = $_POST['ConfirmPassword'] ?? '';
        $email = $_POST['ResetEmail'] ?? '';

        $redirectParams = ['formToShow' => 'resetPasswordForm', 'email' => $email];

        if (empty($newPassword) || empty($confirmPassword) || empty($email)) {
             $_SESSION['reset_error'] = 'All password fields are required.';
        } elseif (!preg_match("/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[\W_]).{8,}$/", $newPassword)) {
             $_SESSION['reset_error'] = 'Password does not meet complexity requirements.';
        } elseif ($newPassword !== $confirmPassword) {
             $_SESSION['reset_error'] = 'Passwords do not match.';
        } else {
            $hashedNewPassword = password_hash($newPassword, PASSWORD_DEFAULT);

            $sql = "UPDATE STAFF SET Password = ? WHERE Email = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                 $stmt->bind_param('ss', $hashedNewPassword, $email);
                 if ($stmt->execute()) {
                     $_SESSION['success_message'] = 'Password successfully updated. Please log in.';
                     header("Location: " . htmlspecialchars($_SERVER["PHP_SELF"]) . "?formToShow=loginForm");
                     exit();
                 } else {
                     $_SESSION['reset_error'] = 'Error updating password. Please try again.';
                 }
                 $stmt->close();
            } else {
                 $_SESSION['reset_error'] = 'A database error occurred. Please try again later.';
            }
        }
        if(isset($_SESSION['reset_error'])) {
            header("Location: " . htmlspecialchars($_SERVER["PHP_SELF"]) . "?" . http_build_query($redirectParams));
            exit();
        }
    }
}

// --- Retrieve session messages and data for display ---
$success_message = $_SESSION['success_message'] ?? null;
$login_error = $_SESSION['login_error'] ?? null;
$register_errors = $_SESSION['register_errors'] ?? [];
$register_data = $_SESSION['register_data'] ?? [];
$forgot_error = $_SESSION['forgot_error'] ?? null;
$reset_error = $_SESSION['reset_error'] ?? null;
unset($_SESSION['success_message'], $_SESSION['login_error'], $_SESSION['register_errors'], $_SESSION['register_data'], $_SESSION['forgot_error'], $_SESSION['reset_error']);
$reset_email_display = $_GET['email'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Login - eDSS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bs-primary-rgb: 13, 110, 253; --bs-link-color-rgb: var(--bs-primary-rgb); }
        body { font-family: 'Poppins', sans-serif; background-color: #f0f2f5; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px 15px; }
        .auth-container { max-width: 500px; width: 100%; }
        .card { border: none; border-radius: 10px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1); overflow: hidden; }
        .card.active { display: block; }
        .card-header { background-color: rgb(var(--bs-primary-rgb)); color: white; border-bottom: none; padding: 1.5rem; font-size: 1.5rem; font-weight: 600; text-align: center; }
        .card-body { padding: 2rem; }
        .form-label { font-weight: 600; color: #495057; margin-bottom: 0.5rem; }
        .form-control, .form-select { border-radius: 5px; padding: 0.75rem 1rem; border: 1px solid #ced4da; }
        .form-control:focus, .form-select:focus { border-color: #86b7fe; outline: 0; box-shadow: 0 0 0 0.25rem rgba(var(--bs-primary-rgb), 0.25); }
        .btn-primary { background-color: rgb(var(--bs-primary-rgb)); border-color: rgb(var(--bs-primary-rgb)); border-radius: 5px; padding: 0.75rem; font-weight: 600; }
        .toggle-link { text-align: center; margin-top: 1.5rem; font-size: 0.95rem; }
        .toggle-link a { color: rgb(var(--bs-link-color-rgb)); text-decoration: none; font-weight: 600; }
        .invalid-feedback { display: block; width: 100%; margin-top: 0.25rem; font-size: .875em; color: var(--bs-danger); }
        .input-group { position: relative; }
        .toggle-password { position: absolute; top: 70%; right: 1rem; transform: translateY(-50%); cursor: pointer; color: #6c757d; z-index: 5; padding: 0.375rem 0.75rem; }
        .input-group input[type="password"], .input-group input[type="text"] { padding-right: 3.5rem; }
        .gender-options .form-check { display: inline-block; margin-right: 1.5rem; }
        .gender-group.is-invalid { border: 1px solid var(--bs-danger); border-radius: 5px; padding: 0.5rem; }
    </style>
</head>
<body>

<div class="auth-container">

     <div id="globalAlertArea" class="mb-3" style="min-height: 58px;">
         <?php
         if ($success_message) { echo "<div class='alert alert-success alert-dismissible fade show' role='alert'><i class='fas fa-check-circle me-2'></i>" . htmlspecialchars($success_message) . "<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>"; }
         if (!empty($register_errors['dbError'])) { echo "<div class='alert alert-danger alert-dismissible fade show' role='alert'><i class='fas fa-exclamation-triangle me-2'></i>Error: " . htmlspecialchars($register_errors['dbError']) . "<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>"; }
         if (!empty($register_errors['generalError'])) { echo "<div class='alert alert-warning alert-dismissible fade show' role='alert'><i class='fas fa-exclamation-circle me-2'></i>" . htmlspecialchars($register_errors['generalError']) . "<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>"; }
         ?>
     </div>

    <!-- Login Form Card -->
    <div class="card <?php echo ($formToShow === 'loginForm') ? 'active' : ''; ?>" id="loginForm">
        <div class="card-header">Welcome to eDSS</div>
        <div class="card-body">
             <?php if ($login_error): ?>
                 <div class="alert alert-danger d-flex align-items-center" role="alert"><i class="fas fa-times-circle me-2"></i><div><?php echo htmlspecialchars($login_error); ?></div></div>
             <?php endif; ?>
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" novalidate>
                <div class="mb-3">
                    <label for="loginUsername" class="form-label">Username</label>
                    <input type="text" class="form-control <?php echo $login_error ? 'is-invalid' : ''; ?>" id="loginUsername" name="username" required>
                </div>
                <div class="mb-3 input-group">
                    <label for="loginPassword" class="form-label w-100">Password</label>
                    <input type="password" class="form-control <?php echo $login_error ? 'is-invalid' : ''; ?>" id="loginPassword" name="password" required>
                    <span class="toggle-password" onclick="togglePasswordVisibility('loginPassword')"><i class="fa fa-eye"></i></span>
                </div>
                 <div class="mb-3 text-end"><a href="javascript:void(0);" onclick="toggleForm('forgotPasswordForm')" class="form-text fw-bold">Forgot Password?</a></div>
                <button type="submit" name="login" class="btn btn-primary w-100">Login</button>
                <div class="toggle-link">Don't have an account? <a href="javascript:void(0);" onclick="toggleForm('registerForm')">Sign Up</a></div>
            </form>
        </div>
    </div>

    <!-- Register Form Card -->
    <div class="card <?php echo ($formToShow === 'registerForm') ? 'active' : ''; ?>" id="registerForm" style="<?php echo ($formToShow !== 'registerForm') ? 'display: none;' : ''; ?>">
        <div class="card-header">Create Staff Account</div>
        <div class="card-body">
             <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?formToShow=registerForm" method="POST" novalidate>
                <div class="row">
                     <div class="col-md-6 mb-3">
                        <label for="Name" class="form-label">Full Name</label>
                        <input type="text" class="form-control <?php echo isset($register_errors['nameError']) ? 'is-invalid' : ''; ?>" id="Name" name="Name" value="<?php echo htmlspecialchars($register_data['Name'] ?? ''); ?>" required>
                         <?php if (isset($register_errors['nameError'])): ?><div class="invalid-feedback"><?php echo $register_errors['nameError']; ?></div><?php endif; ?>
                    </div>
                     <div class="col-md-6 mb-3">
                        <label for="IC" class="form-label">IC Number</label>
                        <input type="text" class="form-control <?php echo isset($register_errors['icError']) ? 'is-invalid' : ''; ?>" id="IC" name="IC" placeholder="e.g., 020222-07-0222" value="<?php echo htmlspecialchars($register_data['IC'] ?? ''); ?>" required>
                        <?php if (isset($register_errors['icError'])): ?><div class="invalid-feedback"><?php echo $register_errors['icError']; ?></div><?php endif; ?>
                    </div>
                </div>
                <div class="row">
                     <div class="col-md-6 mb-3">
                         <div class="gender-group <?php echo isset($register_errors['genderError']) ? 'is-invalid' : ''; ?>">
                            <label class="form-label d-block">Gender</label>
                             <div class="gender-options">
                                 <div class="form-check"><input class="form-check-input <?php echo isset($register_errors['genderError']) ? 'is-invalid' : ''; ?>" type="radio" name="Gender" id="GenderMale" value="MALE" <?php echo (isset($register_data['Gender']) && $register_data['Gender'] == 'MALE') ? 'checked' : ''; ?> required><label class="form-check-label" for="GenderMale">Male</label></div>
                                 <div class="form-check"><input class="form-check-input <?php echo isset($register_errors['genderError']) ? 'is-invalid' : ''; ?>" type="radio" name="Gender" id="GenderFemale" value="FEMALE" <?php echo (isset($register_data['Gender']) && $register_data['Gender'] == 'FEMALE') ? 'checked' : ''; ?> required><label class="form-check-label" for="GenderFemale">Female</label></div>
                             </div>
                          </div>
                         <?php if (isset($register_errors['genderError'])): ?><div class="text-danger invalid-feedback d-block"><?php echo $register_errors['genderError']; ?></div><?php endif; ?>
                    </div>
                     <div class="col-md-6 mb-3">
                        <label for="PhoneNo" class="form-label">Phone Number</label>
                        <input type="tel" class="form-control <?php echo isset($register_errors['phoneError']) ? 'is-invalid' : ''; ?>" id="PhoneNo" name="PhoneNo" placeholder="e.g., 012-3456789" value="<?php echo htmlspecialchars($register_data['PhoneNo'] ?? ''); ?>" required>
                         <?php if (isset($register_errors['phoneError'])): ?><div class="invalid-feedback"><?php echo $register_errors['phoneError']; ?></div><?php endif; ?>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="Address" class="form-label">Address</label>
                    <input type="text" class="form-control <?php echo isset($register_errors['addressError']) ? 'is-invalid' : ''; ?>" id="Address" name="Address" value="<?php echo htmlspecialchars($register_data['Address'] ?? ''); ?>" required>
                    <?php if (isset($register_errors['addressError'])): ?><div class="invalid-feedback"><?php echo $register_errors['addressError']; ?></div><?php endif; ?>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="Email" class="form-label">Email Address</label>
                        <input type="email" class="form-control <?php echo isset($register_errors['emailError']) ? 'is-invalid' : ''; ?>" id="Email" name="Email" placeholder="e.g., staff@example.com" value="<?php echo htmlspecialchars($register_data['Email'] ?? ''); ?>" required>
                         <?php if (isset($register_errors['emailError'])): ?><div class="invalid-feedback"><?php echo $register_errors['emailError']; ?></div><?php endif; ?>
                    </div>
                     <div class="col-md-6 mb-3">
                        <label for="Role" class="form-label">Role</label>
                        <select class="form-select <?php echo isset($register_errors['roleError']) ? 'is-invalid' : ''; ?>" id="Role" name="Role" required>
                            <option value="" disabled <?php echo empty($register_data['Role']) ? 'selected' : ''; ?>>Select role...</option>
                            <option value="NURSE" <?php echo (isset($register_data['Role']) && $register_data['Role'] == 'NURSE') ? 'selected' : ''; ?>>Nurse</option>
                            <option value="DOCTOR" <?php echo (isset($register_data['Role']) && $register_data['Role'] == 'DOCTOR') ? 'selected' : ''; ?>>Doctor</option>
                        </select>
                         <?php if (isset($register_errors['roleError'])): ?><div class="invalid-feedback"><?php echo $register_errors['roleError']; ?></div><?php endif; ?>
                    </div>
                </div>
                 <div class="row">
                     <div class="col-md-6 mb-3">
                        <label for="Username" class="form-label">Username</label>
                        <input type="text" class="form-control <?php echo isset($register_errors['usernameError']) ? 'is-invalid' : ''; ?>" id="Username" name="Username" value="<?php echo htmlspecialchars($register_data['Username'] ?? ''); ?>" required>
                        <?php if (isset($register_errors['usernameError'])): ?><div class="invalid-feedback"><?php echo $register_errors['usernameError']; ?></div><?php endif; ?>
                    </div>
                     <div class="col-md-6 mb-3 input-group flex-column">
                         <label for="Password" class="form-label w-100">Password</label>
                         <div class="input-group">
                             <input type="password" class="form-control <?php echo isset($register_errors['passwordError']) ? 'is-invalid' : ''; ?>" id="Password" name="Password" required>
                             <span class="toggle-password" onclick="togglePasswordVisibility('Password')"><i class="fa fa-eye"></i></span>
                              <?php if (isset($register_errors['passwordError'])): ?><div class="invalid-feedback w-100"><?php echo $register_errors['passwordError']; ?></div><?php endif; ?>
                         </div>
                    </div>
                </div>
                 <div class="mb-4">
                    <label for="Department" class="form-label">Department</label>
                    <select class="form-select <?php echo isset($register_errors['departmentError']) ? 'is-invalid' : ''; ?>" id="Department" name="Department" required>
                        <option value="" disabled <?php echo empty($register_data['Department']) ? 'selected' : ''; ?>>Select department...</option>
                        <option value="DP001" <?php echo (isset($register_data['Department']) && $register_data['Department'] == 'DP001') ? 'selected' : ''; ?>>Emergency</option>
                        <option value="DP002" <?php echo (isset($register_data['Department']) && $register_data['Department'] == 'DP002') ? 'selected' : ''; ?>>Cardiology</option>
                        <option value="DP003" <?php echo (isset($register_data['Department']) && $register_data['Department'] == 'DP003') ? 'selected' : ''; ?>>Neurology</option>
                    </select>
                     <?php if (isset($register_errors['departmentError'])): ?><div class="invalid-feedback"><?php echo $register_errors['departmentError']; ?></div><?php endif; ?>
                </div>
                <button type="submit" name="register" class="btn btn-primary w-100">Register Account</button>
                <div class="toggle-link">Already have an account? <a href="javascript:void(0);" onclick="toggleForm('loginForm')">Login</a></div>
            </form>
        </div>
    </div>

    <!-- Forgot Password Form Card -->
     <div class="card <?php echo ($formToShow === 'forgotPasswordForm') ? 'active' : ''; ?>" id="forgotPasswordForm" style="<?php echo ($formToShow !== 'forgotPasswordForm') ? 'display: none;' : ''; ?>">
        <div class="card-header">Forgot Password</div>
        <div class="card-body">
             <?php if ($forgot_error): ?><div class="alert alert-danger d-flex align-items-center" role="alert"><i class="fas fa-times-circle me-2"></i><div><?php echo htmlspecialchars($forgot_error); ?></div></div><?php endif; ?>
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?formToShow=forgotPasswordForm" method="POST" novalidate>
                 <p class="text-muted mb-3">Enter your registered email address below.</p>
                <div class="mb-3"><label for="forgotEmail" class="form-label">Email Address</label><input type="email" class="form-control <?php echo $forgot_error ? 'is-invalid' : ''; ?>" id="forgotEmail" name="Email" required></div>
                <button type="submit" name="forgotPassword" class="btn btn-primary w-100">Send Reset Instructions</button>
                <div class="toggle-link"><a href="javascript:void(0);" onclick="toggleForm('loginForm')">Back to Login</a></div>
            </form>
        </div>
    </div>

    <!-- Reset Password Form Card -->
    <div class="card <?php echo ($formToShow === 'resetPasswordForm') ? 'active' : ''; ?>" id="resetPasswordForm" style="<?php echo ($formToShow !== 'resetPasswordForm') ? 'display: none;' : ''; ?>">
        <div class="card-header">Reset Password</div>
        <div class="card-body">
             <?php if ($reset_error): ?><div class="alert alert-danger d-flex align-items-center" role="alert"><i class="fas fa-times-circle me-2"></i><div><?php echo htmlspecialchars($reset_error); ?></div></div><?php endif; ?>
             <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?formToShow=resetPasswordForm&email=<?php echo urlencode($reset_email_display);?>" method="POST" novalidate>
                 <p class="text-muted mb-3">Create a new password for <strong><?php echo htmlspecialchars($reset_email_display); ?></strong></p>
                <input type="hidden" name="ResetEmail" value="<?php echo htmlspecialchars($reset_email_display); ?>">
                <div class="mb-3 input-group flex-column">
                    <label for="newPassword" class="form-label w-100">New Password</label>
                    <div class="input-group"><input type="password" class="form-control <?php echo $reset_error ? 'is-invalid' : ''; ?>" id="newPassword" name="NewPassword" required><span class="toggle-password" onclick="togglePasswordVisibility('newPassword')"><i class="fa fa-eye"></i></span></div>
                </div>
                <div class="mb-3 input-group flex-column">
                     <label for="confirmPassword" class="form-label w-100">Confirm New Password</label>
                      <div class="input-group"><input type="password" class="form-control <?php echo $reset_error ? 'is-invalid' : ''; ?>" id="confirmPassword" name="ConfirmPassword" required><span class="toggle-password" onclick="togglePasswordVisibility('confirmPassword')"><i class="fa fa-eye"></i></span></div>
                </div>
                <button type="submit" name="resetPassword" class="btn btn-primary w-100 mt-2">Set New Password</button>
                 <div class="toggle-link"><a href="javascript:void(0);" onclick="toggleForm('loginForm')">Back to Login</a></div>
            </form>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function toggleForm(targetFormId) {
        document.querySelectorAll('.auth-container .card').forEach(card => card.style.display = 'none');
        const targetForm = document.getElementById(targetFormId);
        if (targetForm) targetForm.style.display = 'block';
    }
    function togglePasswordVisibility(fieldId) {
        const field = document.getElementById(fieldId);
        const icon = field.closest('.input-group').querySelector('.toggle-password i');
        if (field.type === "password") {
            field.type = "text";
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            field.type = "password";
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    }
    document.addEventListener('DOMContentLoaded', function() {
        toggleForm('<?php echo $formToShow; ?>');
    });
</script>

</body>
</html>
<?php
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>