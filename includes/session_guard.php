<?php

if (!defined('SESSION_AWAY_LIMIT')) {
    define('SESSION_AWAY_LIMIT', 60);
}

function enforce_away_logout(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    if (empty($_SESSION['user_id']) && empty($_SESSION['clinic_logged_in'])) {
        return;
    }

    $now = time();
    $lastSeen = (int) ($_SESSION['_last_seen'] ?? $now);

    if (($now - $lastSeen) > SESSION_AWAY_LIMIT) {
        $_SESSION = [];
        session_destroy();

        if (!headers_sent()) {
            session_id(session_create_id());
            session_start();
        }

        return;
    }

    $_SESSION['_last_seen'] = $now;
}