<?php
/**
 * Role entry: faculty dashboard
 */

require_once __DIR__ . '/includes/bootstrap.php';

requireRole(ROLE_FACULTY, ROLE_ADVISER, ROLE_STAFF);
redirect(APP_URL . '/modules/faculty/dashboard.php');


