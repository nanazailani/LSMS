<?php
// Log out: end the PHP session, clear the prototype copy in sessionStorage, back to the login page.
require __DIR__ . '/includes/auth.php';

logout_user();
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>IPTB-LSMS · Logged out</title>
    <script>
        try {
            ['lsms_user', 'lsms_role', 'lsms_loc'].forEach(function(k) {
                sessionStorage.removeItem(k);
            });
        } catch (e) {}
        location.replace('login.php');
    </script>
</head>

<body>
    <p>You are logged out. <a href="login.php">Log in again</a></p>
</body>

</html>