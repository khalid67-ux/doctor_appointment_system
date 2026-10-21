<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

unset(
    $_SESSION['clinic_logged_in'],
    $_SESSION['clinic_id'],
    $_SESSION['clinic_email']
);

session_regenerate_id(true);

header('Location: clinic-login.php');
exit;