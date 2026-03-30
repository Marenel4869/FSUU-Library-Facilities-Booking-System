<?php
/**
 * FSUU Library Booking System
 * Session bootstrap — include at the top of every page.
 */

ini_set('session.use_strict_mode', '1');

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = function_exists('appIsHttpsRequest')
        ? appIsHttpsRequest()
        : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_name('FSUUSESSID');
    session_start();

    $now = time();

    // Expire idle sessions.
    if (isset($_SESSION['last_activity']) && ($now - (int) $_SESSION['last_activity']) > SESSION_LIFETIME) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
        session_start();
    }

    // Regenerate ID every 5 minutes to reduce fixation risk.
    if (!isset($_SESSION['last_regenerated'])) {
        $_SESSION['last_regenerated'] = $now;
    } elseif (($now - (int) $_SESSION['last_regenerated']) > 300) {
        session_regenerate_id(true);
        $_SESSION['last_regenerated'] = $now;
    }

    $_SESSION['last_activity'] = $now;
}
