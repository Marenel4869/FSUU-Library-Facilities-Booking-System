<?php
/**
 * FSUU Library Booking System
 * Application Constants
 */

// User roles
define('ROLE_ADMIN',    'admin');
define('ROLE_FACULTY',  'faculty');
define('ROLE_STUDENT',  'student');
define('ROLE_ADVISER',  'adviser');
define('ROLE_STAFF',    'staff');
define('ROLE_LIBRARY_STAFF', 'library_staff');
define('ROLE_SUPER_ADMIN',   'super_admin');

define('ROLE_GROUP_ACADEMIC', [ROLE_FACULTY, ROLE_ADVISER, ROLE_STAFF, ROLE_ADMIN]);
define('ROLE_GROUP_MANAGEMENT', [ROLE_ADMIN, ROLE_LIBRARY_STAFF, ROLE_SUPER_ADMIN]);

// Booking statuses
define('STATUS_PENDING',   'pending');
define('STATUS_APPROVED',  'approved');
define('STATUS_REJECTED',  'rejected');
define('STATUS_CANCELLED', 'cancelled');
define('STATUS_COMPLETED', 'completed');

// Facility types
define('FACILITY_DISCUSSION_ROOM', 'discussion_room');
define('FACILITY_COMPUTER_LAB',    'computer_lab');
define('FACILITY_READING_ROOM',    'reading_room');
define('FACILITY_MULTIMEDIA_ROOM', 'multimedia_room');

// Paths
define('ROOT_PATH',    dirname(__DIR__));
define('UPLOAD_PATH',  ROOT_PATH . '/uploads');
define('ASSET_PATH',   APP_URL  . '/assets');

// Pagination
define('RECORDS_PER_PAGE', 10);

// Date/Time formats
define('DATE_FORMAT',     'd M Y');
define('TIME_FORMAT',     'h:i A');
define('DATETIME_FORMAT', 'd M Y, h:i A');
