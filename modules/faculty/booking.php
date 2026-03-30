<?php
/**
 * FSUU Library Booking System
 * Faculty — Book a Facility
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

requireRole(ROLE_FACULTY, ROLE_ADVISER, ROLE_STAFF);
redirect(APP_URL . '/modules/faculty/dashboard.php#booking-form');



