<?php
/**
 * Role entry: student dashboard
 */

require_once __DIR__ . '/includes/bootstrap.php';

requireRole(ROLE_STUDENT);
redirect(APP_URL . '/modules/student/dashboard.php');


