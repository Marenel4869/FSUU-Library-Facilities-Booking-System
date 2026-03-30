<?php
/**
 * FSUU Library Booking System
 * Logout Handler
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

// Destroy session data
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

session_destroy();

setFlash('success', 'You have been logged out successfully.');
redirect(APP_URL . '/modules/auth/login.php');


