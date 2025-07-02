<?php
session_start();

// 1. Unset all of the session variables.
// Using $_SESSION = array(); is a more robust way to clear all session data
// compared to session_unset() in some older PHP versions or specific configurations.
$_SESSION = array();

// 2. If it's desired to kill the session, also delete the session cookie.
// Note: This will destroy the session, and not just the session data!
// This step is important for ensuring the client's browser forgets the session.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, // Set cookie expiration to the past
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Finally, destroy the session on the server.
session_destroy();

// 4. Redirect to the login page
// Adjust the path to your login page as necessary.
// The original code used:
header("Location: ../../login.php");
// If your login.php is in the same directory, you might use:
// header("Location: login.php");
// If your login.php is one directory up, you might use:
// header("Location: ../login.php");

// Ensure no further code is executed after the redirect.
exit();
?>