<?php
/**
 * Role entry: admin dashboard
 */

require_once __DIR__ . '/includes/bootstrap.php';

requireRole(ROLE_ADMIN, ROLE_LIBRARY_STAFF, ROLE_SUPER_ADMIN);
redirect(APP_URL . '/modules/admin/dashboard.php');


