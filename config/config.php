<?php
/**
 * FSUU Library Booking System
 * Application Configuration
 */

// Database credentials
define('DB_HOST',     'localhost');
define('DB_USER',     'root');
define('DB_PASS',     '');
define('DB_NAME',     'fsuu_library_booking');
define('DB_CHARSET',  'utf8mb4');

// Application settings
define('APP_NAME',    'FSUU Library Booking System');
define('APP_URL',     getenv('APP_URL') ?: 'http://localhost/FSUU-Library-Facilities-Booking-System');
define('APP_VERSION', '1.0.0');
define('APP_ENV',     getenv('APP_ENV') ?: 'development');

// HTTPS settings
define('ENFORCE_HTTPS', (getenv('ENFORCE_HTTPS') === '1'));
define('TRUST_PROXY_HTTPS_HEADER', true);

// Session settings
define('SESSION_LIFETIME', 3600); // 1 hour in seconds

// Timezone
date_default_timezone_set('Asia/Manila');

if (!function_exists('appIsHttpsRequest')) {
	function appIsHttpsRequest(): bool {
		if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
			return true;
		}

		if (TRUST_PROXY_HTTPS_HEADER) {
			$forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
			if ($forwardedProto === 'https') {
				return true;
			}
		}

		return false;
	}
}

if (php_sapi_name() !== 'cli' && ENFORCE_HTTPS && !appIsHttpsRequest() && !headers_sent()) {
	$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
	$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
	header('Location: https://' . $host . $uri, true, 301);
	exit;
}

if (php_sapi_name() !== 'cli' && appIsHttpsRequest() && !headers_sent()) {
	header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// Error reporting
if (APP_ENV === 'production') {
	ini_set('display_errors', '0');
	error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);
} else {
	ini_set('display_errors', '1');
	error_reporting(E_ALL);
}
