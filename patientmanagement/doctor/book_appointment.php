<?php
session_start(); // Start session if needed (e.g., for staff ID)

// Include the SINGLE database connection ($conn to azwarie_dss)
include 'connection.php'; // This should define $conn using mysqli

// Check connection
if (!$conn || $conn->connect_error) {
    die("Database connection failed: " . ($conn ? $conn->connect_error : 'Unknown error'));
}

// Set default timezone
date_default_timezone_set('Asia/Kuala_Lumpur'); // Replace with your timezone

// Initialize variables
$message = ''; // For success/error messages
$searchKeyword = '';

// --- Handle form submission for booking an appointment ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and validate inputs
    $patient_id = trim($_POST['PatientID'] ?? '');
    $appointment_date = trim($_POST['AppointmentDate'] ?? '');
    $appointment_time = trim($_POST['AppointmentTime'] ?? '');
    $appointment_type = trim($_POST['AppointmentType'] ?? '');
    $appointment_status = 'Booked'; // Default status

    // --- Basic Server-Side Validation ---
    $errors = [];
    if (empty($patient_id)) $errors[] = "Please select a patient.";
    if (empty($appointment_date)) $errors[] = "Please select an appointment date.";
    // Add more validation for date format, time format, type if needed
    if (empty($appointment_time)) $errors[] = "Please select an appointment time.";
     if (empty($appointment_type) || !in_array($appointment_type, ['Walk-in', 'Booking'])) $errors[] = "Please select a valid appointment type.";


    if (empty($errors)) {
        // --- Generate the next AppointmentID ---
        $new_appointment_id = 'APP001'; // Default
        try {
            $sql_max = "SELECT AppointmentID FROM appointments ORDER BY AppointmentID DESC LIMIT 1";
            $result_max = $conn->query($sql_max);
            if ($result_max && $result_max->num_rows > 0) {
                $row = $result_max->fetch_assoc();
                $last_appointment_id = $row['AppointmentID'];
                // Extract numeric part more robustly
                 if (preg_match('/^APP(\d+)$/', $last_appointment_id, $matches)) {
                     $numeric_part = (int) $matches[1];
                     $new_numeric_part = str_pad($numeric_part + 1, 3, '0', STR_PAD_LEFT);
                     $new_appointment_id = 'APP' . $new_numeric_part;
                 }
            }
             if ($result_max) $result_max->free(); // Free result
        } catch (Exception $e) {
             error_log("Error generating AppointmentID: " . $e->getMessage());
             $errors[] = "Could not generate appointment ID."; // Add error
        }


        // --- Insert if still no errors ---
        if (empty($errors)) {
            $conn->begin_transaction(); // Start transaction
            try {
                $sql_insert = "INSERT INTO appointments (AppointmentID, PatientID, AppointmentDate, AppointmentTime, AppointmentType, AppointmentStatus)
                               VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql_insert);
                if ($stmt === false) throw new Exception("Prepare failed (Insert Appointment): " . $conn->error);

                $stmt->bind_param("ssssss", $new_appointment_id, $patient_id, $appointment_date, $appointment_time, $appointment_type, $appointment_status);

                if (!$stmt->execute()) throw new Exception("Execute failed (Insert Appointment): " . $stmt->error);

                 if ($stmt->affected_rows > 0) {
                     // Commit transaction
                     if (!$conn->commit()) throw new Exception("Transaction commit failed: " . $conn->error);
                     $message = "<div class='alert alert-success alert-dismissible fade show' role='alert'>Appointment booked successfully (ID: $new_appointment_id)!<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
                     // Optionally log audit trail here if needed
                 } else {
                     throw new Exception("Insert operation affected 0 rows.");
                 }
                $stmt->close();

            } catch (Exception $e) {
                 $conn->rollback(); // Rollback on error
                 $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'>Failed to book appointment: " . htmlspecialchars($e->getMessage()) . "<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
                 error_log("Appointment Booking Error: " . $e->getMessage());
            }
        } // end insert block
    } // end validation check

    // If validation errors occurred before DB attempt
    if (!empty($errors)) {
         $message = "<div class='alert alert-danger alert-dismissible fade show' role='alert'><strong>Booking failed:</strong><ul class='mb-0'>";
         foreach ($errors as $error) {
             $message .= "<li>" . htmlspecialchars($error) . "</li>";
         }
         $message .= "</ul><button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button></div>";
    }

} // End POST check

// --- Fetch patients for dropdown (Filtered) ---
$patients = []; // Initialize
$fetch_error = null;
try {
    // Select patients who DO NOT have an active appointment ('Booked', 'Diagnosed', 'Admitted')
    // This includes patients with no appointments or only 'Completed' appointments.
    $sql_patients = "
        SELECT p.PatientID, p.PatientName
        FROM patients p
        LEFT JOIN appointments a ON p.PatientID = a.PatientID
                                  AND a.AppointmentStatus IN ('Booked', 'Diagnosed', 'Admitted')
        WHERE a.AppointmentID IS NULL
        GROUP BY p.PatientID, p.PatientName 
        ORDER BY p.PatientName ASC
    ";

    $result_patients = $conn->query($sql_patients);

    if ($result_patients) {
        $patients = $result_patients->fetch_all(MYSQLI_ASSOC);
        $result_patients->free();
    } else {
        throw new Exception("Failed to fetch filtered patients: " . $conn->error);
    }
} catch (Exception $e) {
    $fetch_error = "Error loading patient list: " . $e->getMessage();
    error_log($fetch_error);
}


// --- Fetch appointments for display table (Pagination & Search) ---
$itemsPerPage = 20;
$page = isset($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $itemsPerPage;
$totalAppointments = 0;
$totalPages = 0;
$appointmentsData = []; // Initialize

// Sanitize search keyword
$searchKeyword = isset($_GET['search']) ? trim(htmlspecialchars($_GET['search'], ENT_QUOTES, 'UTF-8')) : '';
$searchCondition = '';
$params = [];
$paramTypes = '';

if (!empty($searchKeyword)) {
    // Use prepared statement for search condition
    $searchCondition = " AND p.PatientName LIKE ?";
    $params[] = "%" . $searchKeyword . "%"; // Add wildcard parameter
    $paramTypes .= 's';
}

try {
    // Get total count with search condition
    $totalAppointmentsQuery = "SELECT COUNT(a.AppointmentID) AS total
                               FROM appointments a
                               JOIN patients p ON a.PatientID = p.PatientID
                               WHERE a.AppointmentStatus IN ('Booked', 'Diagnosed', 'Completed', 'Admitted')" . $searchCondition;

    $stmt_total = $conn->prepare($totalAppointmentsQuery);
    if ($stmt_total === false) throw new Exception("Prepare failed (Total Count): " . $conn->error);
    if (!empty($params)) $stmt_total->bind_param($paramTypes, ...$params); // Bind search param if exists
    if (!$stmt_total->execute()) throw new Exception("Execute failed (Total Count): " . $stmt_total->error);
    $totalResult = $stmt_total->get_result();
    if ($totalResult) {
        $totalAppointments = $totalResult->fetch_assoc()['total'];
        $totalPages = ceil($totalAppointments / $itemsPerPage);
        $totalResult->free();
    } else { throw new Exception("Getting result failed (Total Count): " . $stmt_total->error); }
    $stmt_total->close();


    // Fetch limited set of appointments with search condition
    $appointmentsQuery = "SELECT a.AppointmentID, p.PatientName, a.AppointmentDate, a.AppointmentTime, a.AppointmentType, a.AppointmentStatus
                          FROM appointments a
                          JOIN patients p ON a.PatientID = p.PatientID
                          WHERE a.AppointmentStatus IN ('Booked', 'Diagnosed', 'Completed', 'Admitted')" . $searchCondition .
                         " ORDER BY a.AppointmentDate DESC, a.AppointmentTime DESC LIMIT ? OFFSET ?"; // Order by date/time desc

    // Add LIMIT and OFFSET parameters
    $currentParams = $params; // Copy existing search params
    $currentParams[] = $itemsPerPage;
    $currentParams[] = $offset;
    $currentParamTypes = $paramTypes . 'ii'; // Add types for LIMIT, OFFSET

    $stmt_appointments = $conn->prepare($appointmentsQuery);
    if ($stmt_appointments === false) throw new Exception("Prepare failed (Fetch Appointments): " . $conn->error);
    if (!empty($currentParams)) $stmt_appointments->bind_param($currentParamTypes, ...$currentParams); // Bind all params
    if (!$stmt_appointments->execute()) throw new Exception("Execute failed (Fetch Appointments): " . $stmt_appointments->error);
    $appointmentsResult = $stmt_appointments->get_result();
    if ($appointmentsResult) {
        $appointmentsData = $appointmentsResult->fetch_all(MYSQLI_ASSOC);
        $appointmentsResult->free();
    } else { throw new Exception("Getting result set failed (Fetch Appointments): " . $stmt_appointments->error); }
    $stmt_appointments->close();

} catch (Exception $e) {
    $fetch_error = "Error fetching appointments: " . $e->getMessage();
    error_log($fetch_error);
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Appointment - WeCare</title>
    <!-- Font Links -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;700&display=swap" rel="stylesheet">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        /* Using styles from previous conversions for consistency */
        :root { --primary-color: #007bff; --background-color: #f8f9fa; --text-color: #343a40; --form-bg-color: #ffffff; --form-border-color: #dee2e6; --input-focus-color: #86b7fe; }
        body { font-family: 'Poppins', sans-serif; margin: 0; padding: 0; background-color: var(--background-color); padding-top: 70px; }
        /* Navbar Styling (assuming navbar.php is included and styled) */
        /* Hero Section */
        .hero-section { background-color: var(--primary-color); color: white; padding: 60px 0; text-align: center; margin-bottom: 30px; }
        .hero-section h1 { font-family: 'Montserrat', sans-serif; font-size: 2.8rem; font-weight: 700; text-transform: uppercase; }
        .hero-section p { font-size: 1.1rem; opacity: 0.9; }
        @media (max-width: 768px) { .hero-section { padding: 40px 0; } .hero-section h1 { font-size: 2rem; } .hero-section p { font-size: 1rem; } }
        /* Card Styling */
        .card { border: none; border-radius: 8px; box-shadow: 0 2px 5px rgba(0, 0, 0, 0.07); margin-bottom: 20px; }
        .card-header { background-color: #e9ecef; border-bottom: 1px solid #dee2e6; font-weight: 600; padding: 0.9rem 1.25rem; font-size: 1.1rem; color: #495057; }
        .card-body { padding: 1.5rem; }
        /* Form Specific Styling */
        .form-container { background-color: var(--form-bg-color); padding: 2rem; border-radius: 8px; }
        .form-container h2 { text-align: center; margin-bottom: 1.5rem; color: var(--primary-color); }
        .form-label { display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--text-color); font-size: 0.95rem; }
        .form-control, .form-select { width: 100%; padding: 0.5rem 0.85rem; margin-bottom: 1rem; border: 1px solid var(--form-border-color); border-radius: 5px; font-size: 1rem; transition: border-color 0.3s ease, box-shadow 0.2s ease; }
        .form-control:focus, .form-select:focus { border-color: var(--input-focus-color); outline: none; box-shadow: 0 0 4px rgba(0, 123, 255, 0.2); }
        .btn-submit { background: linear-gradient(to right, #005c99, #007bff); color: white; border: none; cursor: pointer; transition: background 0.4s ease, transform 0.2s ease; padding: 0.6rem 1.5rem; font-size: 1rem; font-weight: 600; border-radius: 5px; display: block; width: 100%; margin-top: 1rem; }
        .btn-submit:hover { background: linear-gradient(to right, #004080, #0056b3); transform: translateY(-1px); }
        /* Table Styling */
        .table-responsive { display: block; width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 1rem; background-color: white; font-size: 0.9rem; }
        table th, table td { padding: 0.75rem; text-align: left; vertical-align: middle; border: 1px solid #dee2e6; }
        table thead th { vertical-align: bottom; border-bottom: 2px solid #dee2e6; background-color: #f8f9fa; font-weight: 600; color: #495057; white-space: nowrap; }
        table tbody tr:hover { background-color: #f1f7ff !important; }
        /* Status Badges */
        .status-booked { color: #0d6efd; font-weight: bold; background-color: #cfe2ff; padding: 0.25em 0.6em; border-radius: 0.25rem; }
        .status-diagnosed { color: #664d03; font-weight: bold; background-color: #fff3cd; padding: 0.25em 0.6em; border-radius: 0.25rem; }
        .status-admitted { color: #0f5132; font-weight: bold; background-color: #d1e7dd; padding: 0.25em 0.6em; border-radius: 0.25rem; }
        .status-completed { color: #842029; font-weight: bold; background-color: #f8d7da; padding: 0.25em 0.6em; border-radius: 0.25rem; }
        /* Pagination */
        .pagination { display: flex; justify-content: center; list-style: none; padding: 0; margin-top: 1.5rem; }
        .pagination li { margin: 0 3px; }
        .pagination a, .pagination span { padding: 0.5rem 0.85rem; text-decoration: none; color: var(--primary-color); background-color: #ffffff; border: 1px solid #dee2e6; border-radius: 4px; transition: background-color 0.3s ease; font-size: 0.9rem; }
        .pagination a:hover, .pagination a:focus { background-color: #e9ecef; }
        .pagination .active a, .pagination .active span { background-color: var(--primary-color); color: #ffffff; border-color: var(--primary-color); }
        .pagination .disabled span, .pagination .disabled a { color: #6c757d; pointer-events: none; background-color: #fff; border-color: #dee2e6; }
        /* Search */
        .search-container { margin-bottom: 1rem; text-align: right; }
        .search-container input[type="text"] { padding: 0.4rem 0.8rem; border: 1px solid #ced4da; border-radius: 5px; font-size: 0.9rem; width: auto; min-width: 250px; }
        .search-container button { margin-left: 5px; }
        /* Alert Styling */
        .alert { border-radius: 5px; font-size: 0.95rem; margin-top: 1.5rem;}
        .alert ul { padding-left: 1.5rem; margin-bottom: 0;}
    </style>
</head>
<body>

    <!-- Include Navbar -->
    <?php include 'navbar.php'; // Ensure this includes links with staffid if needed ?>

    <!-- Hero Section -->
    <div class="hero-section">
       <h1>Patient Appointment System</h1>
        <p>Book and Manage Appointments</p>
    </div>

    <div class="container mt-4 mb-5">

        <!-- Display Messages -->
        <div id="messageArea" style="min-height: 60px;">
             <?php if (!empty($message)) echo $message; // Display success/error message from POST ?>
              <?php if ($fetch_error): ?>
                 <div class="alert alert-warning" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($fetch_error); ?> Data may be incomplete.
                 </div>
             <?php endif; ?>
        </div>


        <!-- Appointment Form Card-->
        <div class="card">
            <div class="card-header"><i class="fas fa-calendar-plus me-2"></i>Book New Appointment</div>
            <div class="card-body form-container">
                <!-- Form submits back to this page -->
               <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); // Submit to self ?>" id="appointmentForm" onsubmit="return validateAppointmentForm()">
                    <!-- Pass loggedInStaffID if needed for audit or other purposes -->
                    <input type="hidden" name="loggedInStaffID" value="<?= htmlspecialchars($loggedInStaffID ?? '') ?>">

                   <div class="mb-3">
                       <label for="PatientID" class="form-label">Select Patient <span class="text-danger">*</span></label>
                       <select name="PatientID" id="PatientID" class="form-select" required>
                           <option value="" selected disabled>-- Select Patient --</option>
                           <?php foreach ($patients as $patient): // Use fetched patient data ?>
                               <option value="<?= htmlspecialchars($patient['PatientID']); ?>">
                                   <?= htmlspecialchars($patient['PatientName']) . " (ID: " . htmlspecialchars($patient['PatientID']) . ")" ?>
                               </option>
                           <?php endforeach; ?>
                           <?php if (empty($patients) && !$fetch_error): ?>
                               <option value="" disabled>No patients found</option>
                           <?php endif; ?>
                       </select>
                       <div id="patientError" class="invalid-feedback"></div>
                   </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                           <label for="appointment_date" class="form-label">Appointment Date <span class="text-danger">*</span></label>
                           <input type="date" name="AppointmentDate" id="appointment_date" class="form-control" required min="<?= date('Y-m-d'); // Set min date to today ?>">
                            <div id="dateError" class="invalid-feedback"></div>
                       </div>
                        <div class="col-md-6 mb-3">
                           <label for="appointment_time" class="form-label">Appointment Time <span class="text-danger">*</span></label>
                           <input type="time" name="AppointmentTime" id="appointment_time" class="form-control" required>
                            <div id="timeError" class="invalid-feedback"></div>
                        </div>
                   </div>

                   <div class="mb-3">
                       <label for="appointment_type" class="form-label">Appointment Type <span class="text-danger">*</span></label>
                       <select name="AppointmentType" id="appointment_type" class="form-select" required>
                           <option value="" selected disabled>-- Select Type --</option>
                           <option value="Walk-in">Walk-in</option>
                           <option value="Booking">Booking</option>
                       </select>
                        <div id="typeError" class="invalid-feedback"></div>
                   </div>

                 <button type="submit" class="btn-submit"><i class="fas fa-calendar-check me-1"></i> Book Appointment</button>
               </form>

           </div> <!-- End card-body -->
       </div> <!-- End card -->


        <!-- View Appointments Section -->
        <div class="card mt-4">
           <div class="card-header"><i class="fas fa-list-alt me-2"></i>View Appointments</div>
           <div class="card-body">
               <!-- Search Form -->
                <div class="search-container mb-3">
                     <form method="GET" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="d-flex">
                         <input type="hidden" name="staffid" value="<?= htmlspecialchars($loggedInStaffID ?? '') ?>">
                         <input type="text" name="search" class="form-control form-control-sm me-2" placeholder="Search by Patient Name..." value="<?php echo htmlspecialchars($searchKeyword); ?>" aria-label="Search Appointments">
                         <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
                         <?php if (!empty($searchKeyword)): ?>
                             <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?staffid=' . urlencode($loggedInStaffID ?? ''); ?>" class="btn btn-secondary btn-sm ms-2" title="Clear Search"><i class="fas fa-times"></i></a>
                         <?php endif; ?>
                     </form>
                 </div>

                <!-- Appointments Table -->
                <div class="table-responsive">
                   <table class="table table-striped table-hover table-sm">
                       <thead>
                          <tr>
                               <!-- Add sortable headers if needed -->
                               <th>Appt. ID</th>
                               <th>Patient Name</th>
                               <th>Date</th>
                               <th>Time</th>
                               <th>Type</th>
                               <th>Status</th>
                            </tr>
                       </thead>
                         <tbody>
                           <?php if (!empty($appointmentsData)): ?>
                               <?php foreach ($appointmentsData as $row): ?>
                                  <?php
                                      $status = htmlspecialchars($row['AppointmentStatus']);
                                      $statusClass = 'status-' . strtolower($status); // Generate class like status-booked
                                  ?>
                                 <tr>
                                     <td><?= htmlspecialchars($row['AppointmentID']); ?></td>
                                     <td><?= htmlspecialchars($row['PatientName']); ?></td>
                                     <td><?= htmlspecialchars(date('d M Y', strtotime($row['AppointmentDate']))); // Format date ?></td>
                                     <td><?= htmlspecialchars(date('h:i A', strtotime($row['AppointmentTime']))); // Format time ?></td>
                                     <td><?= htmlspecialchars($row['AppointmentType']); ?></td>
                                     <td><span class='<?= $statusClass; ?>'><?= $status; ?></span></td>
                                 </tr>
                               <?php endforeach; ?>
                           <?php else: ?>
                               <tr><td colspan='6' class="text-center text-muted">
                                   <?php echo !empty($searchKeyword) ? 'No appointments found matching search.' : 'No appointments booked yet.'; ?>
                               </td></tr>
                           <?php endif; ?>
                       </tbody>
                     </table>
                 </div> <!-- End table-responsive -->

                <!-- Pagination links -->
                <?php if ($totalPages > 1): ?>
                   <nav aria-label="Appointments Pagination" class="mt-3">
                        <ul class="pagination justify-content-center">
                           <!-- Previous Button -->
                           <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                               <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo !empty($searchKeyword) ? '&search='.urlencode($searchKeyword) : ''; ?>&staffid=<?php echo urlencode($loggedInStaffID ?? ''); ?>" aria-label="Previous">«</a>
                           </li>
                           <!-- Page Numbers -->
                           <?php
                           $range = 2; $start = max(1, $page - $range); $end = min($totalPages, $page + $range);
                           if ($start > 1) { echo '<li class="page-item"><a class="page-link" href="?page=1'.(!empty($searchKeyword) ? '&search='.urlencode($searchKeyword) : '').'&staffid='.urlencode($loggedInStaffID ?? '').'">1</a></li>'; if ($start > 2) { echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; } }
                           for ($i = $start; $i <= $end; $i++): ?>
                             <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>"><a class="page-link" href="?page=<?php echo $i; ?><?php echo !empty($searchKeyword) ? '&search='.urlencode($searchKeyword) : ''; ?>&staffid=<?php echo urlencode($loggedInStaffID ?? ''); ?>"><?php echo $i; ?></a></li>
                           <?php endfor;
                           if ($end < $totalPages) { if ($end < $totalPages - 1) { echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; } echo '<li class="page-item"><a class="page-link" href="?page='.$totalPages.(!empty($searchKeyword) ? '&search='.urlencode($searchKeyword) : '').'&staffid='.urlencode($loggedInStaffID ?? '').'">'.$totalPages.'</a></li>'; }
                           ?>
                           <!-- Next Button -->
                           <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                               <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo !empty($searchKeyword) ? '&search='.urlencode($searchKeyword) : ''; ?>&staffid=<?php echo urlencode($loggedInStaffID ?? ''); ?>" aria-label="Next">»</a>
                           </li>
                        </ul>
                   </nav>
                <?php endif; ?>
           </div> <!-- End card-body -->
       </div> <!-- End card -->
    </div> <!-- End container -->



    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script>
        // --- Client-Side Validation (Optional) ---
        function validateAppointmentForm() {
            let isValid = true;
             // Clear previous errors
             document.querySelectorAll('.invalid-feedback').forEach(el => el.textContent = '');
             document.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));

            const patientSelect = document.getElementById('PatientID');
            const dateInput = document.getElementById('appointment_date');
            const timeInput = document.getElementById('appointment_time');
            const typeSelect = document.getElementById('appointment_type');

            if (!patientSelect.value) {
                 isValid = false;
                 patientSelect.classList.add('is-invalid');
                 document.getElementById('patientError').textContent = 'Please select a patient.';
            }
            if (!dateInput.value) {
                 isValid = false;
                 dateInput.classList.add('is-invalid');
                 document.getElementById('dateError').textContent = 'Please select a date.';
            } else {
                 // Optional: Check if date is not in the past (already handled by min attribute)
                 const selectedDate = new Date(dateInput.value + 'T00:00:00'); // Compare date part only
                 const today = new Date();
                 today.setHours(0,0,0,0);
                 if (selectedDate < today) {
                      isValid = false;
                      dateInput.classList.add('is-invalid');
                      document.getElementById('dateError').textContent = 'Appointment date cannot be in the past.';
                 }
            }
            if (!timeInput.value) {
                 isValid = false;
                 timeInput.classList.add('is-invalid');
                 document.getElementById('timeError').textContent = 'Please select a time.';
            }
            if (!typeSelect.value) {
                 isValid = false;
                 typeSelect.classList.add('is-invalid');
                 document.getElementById('typeError').textContent = 'Please select an appointment type.';
            }

            // if (!isValid) {
            //     alert('Please correct the errors in the form.');
            // }
            return isValid; // Return true to allow server validation, false to stop here if errors
        }

        // Auto-dismiss alerts
         document.addEventListener('DOMContentLoaded', function() {
             const alerts = document.querySelectorAll('.alert-success, .alert-danger');
             alerts.forEach(function(alert) {
                 if (typeof bootstrap !== 'undefined' && bootstrap.Alert) {
                    setTimeout(() => { bootstrap.Alert.getOrCreateInstance(alert)?.close(); }, 5000);
                 } else {
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