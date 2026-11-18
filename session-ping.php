<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/includes/session_guard.php';

enforce_away_logout();

$loggedIn = !empty($_SESSION['user_id'])
    || !empty($_SESSION['clinic_logged_in']);

session_write_close();

header('Cache-Control: no-store');
http_response_code($loggedIn ? 204 : 401);