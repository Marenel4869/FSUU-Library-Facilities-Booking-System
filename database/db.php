<?php
/**
 * FSUU Library Booking System
 * Database Connection (PDO)
 */

require_once dirname(__DIR__) . '/config/config.php';

/**
 * Establish a PDO connection to MySQL server without selecting a database.
 */
function connectMysqlServer(array $options): PDO {
    $serverDsn = 'mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET;
    return new PDO($serverDsn, DB_USER, DB_PASS, $options);
}

/**
 * Build a database DSN for the configured application schema.
 */
function buildAppDsn(): string {
    return 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
}

/**
 * Create application database if it does not yet exist.
 */
function ensureDatabaseExists(PDO $serverPdo): void {
    $dbName = str_replace('`', '``', DB_NAME);
    $charset = preg_replace('/[^a-zA-Z0-9_]/', '', DB_CHARSET) ?: 'utf8mb4';

    $serverPdo->exec(sprintf(
        'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE utf8mb4_unicode_ci',
        $dbName,
        $charset
    ));
}

/**
 * Determine if connection failure is due to missing database.
 */
function isMissingDatabaseError(PDOException $e): bool {
    $message = strtolower($e->getMessage());
    return str_contains($message, 'unknown database') || str_contains($message, '1049');
}

/**
 * Check if a table exists in the selected database.
 */
function tableExists(PDO $pdo, string $tableName): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
    $stmt->execute([DB_NAME, $tableName]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Check if a column exists in a table.
 */
function columnExists(PDO $pdo, string $tableName, string $columnName): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?');
    $stmt->execute([DB_NAME, $tableName, $columnName]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Get the raw column type definition from information_schema.
 */
function getColumnType(PDO $pdo, string $tableName, string $columnName): string {
    $stmt = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ? LIMIT 1');
    $stmt->execute([DB_NAME, $tableName, $columnName]);
    return (string) ($stmt->fetchColumn() ?: '');
}

/**
 * Normalize facility names for exact rule matching.
 */
function normalizeFacilityName(string $name): string {
    return strtoupper(preg_replace('/\s+/', '', trim($name)) ?? '');
}

/**
 * Seed required built-in facilities without duplicating existing records.
 */
function ensureDefaultFacilitiesExist(PDO $pdo): void {
    $defaults = [
        ['name' => 'CL1', 'type' => 'cl_room', 'capacity_min' => 1, 'capacity_max' => 7, 'location' => 'Main Campus'],
        ['name' => 'CL2', 'type' => 'cl_room', 'capacity_min' => 1, 'capacity_max' => 8, 'location' => 'Main Campus'],
        ['name' => 'CL3', 'type' => 'cl_room', 'capacity_min' => 1, 'capacity_max' => 2, 'location' => 'Main Campus'],
        ['name' => 'Museum', 'type' => 'museum', 'capacity_min' => 1, 'capacity_max' => 100, 'location' => 'Main Campus'],
        ['name' => 'EIRC', 'type' => 'eirc', 'capacity_min' => 1, 'capacity_max' => 60, 'location' => 'Main Campus'],
        ['name' => 'Reading Area', 'type' => 'reading_area', 'capacity_min' => 40, 'capacity_max' => 200, 'location' => 'Morelos'],
        ['name' => 'Faculty Area', 'type' => 'faculty_area', 'capacity_min' => 1, 'capacity_max' => 30, 'location' => 'Morelos'],
    ];

    $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM facilities WHERE UPPER(REPLACE(name, " ", "")) = ?');
    $insertStmt = $pdo->prepare('
        INSERT INTO facilities (name, type, capacity_min, capacity_max, availability, location)
        VALUES (?, ?, ?, ?, 1, ?)
    ');

    foreach ($defaults as $facility) {
        $existsStmt->execute([normalizeFacilityName($facility['name'])]);
        if ((int) $existsStmt->fetchColumn() === 0) {
            $insertStmt->execute([
                $facility['name'],
                $facility['type'],
                $facility['capacity_min'],
                $facility['capacity_max'],
                $facility['location'],
            ]);
        }
    }
}

/**
 * Create essential tables if schema import has not been run yet.
 */
function ensureCoreSchemaExists(PDO $pdo): void {
    if (!tableExists($pdo, 'users')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(150) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role ENUM('student', 'faculty', 'admin', 'adviser', 'staff', 'library_staff', 'super_admin') NOT NULL DEFAULT 'student',
            status ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active',
            contact_number VARCHAR(20) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
    }

    if (!columnExists($pdo, 'users', 'contact_number')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN contact_number VARCHAR(20) DEFAULT NULL');
    }

    if (!columnExists($pdo, 'users', 'created_at')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }

    $roleColumnType = strtolower(getColumnType($pdo, 'users', 'role'));
    $requiredRoleLiterals = [
        "'student'",
        "'faculty'",
        "'admin'",
        "'adviser'",
        "'staff'",
        "'library_staff'",
        "'super_admin'",
    ];

    $hasAllRoleLiterals = true;
    foreach ($requiredRoleLiterals as $literal) {
        if (!str_contains($roleColumnType, $literal)) {
            $hasAllRoleLiterals = false;
            break;
        }
    }

    if (!$hasAllRoleLiterals) {
        $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('student','faculty','admin','adviser','staff','library_staff','super_admin') NOT NULL DEFAULT 'student'");
    }

    if (!tableExists($pdo, 'facilities')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS facilities (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL UNIQUE,
            type VARCHAR(50) NOT NULL,
            location VARCHAR(120) DEFAULT NULL,
            capacity_min INT UNSIGNED DEFAULT 1,
            capacity_max INT UNSIGNED NOT NULL,
            availability TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_facilities_type (type),
            INDEX idx_facilities_availability (availability)
        ) ENGINE=InnoDB");
    }

    if (!columnExists($pdo, 'facilities', 'location')) {
        $pdo->exec('ALTER TABLE facilities ADD COLUMN location VARCHAR(120) DEFAULT NULL AFTER type');
    }

    $pdo->exec("UPDATE facilities SET location = 'Morelos' WHERE (location IS NULL OR location = '') AND UPPER(REPLACE(name, ' ', '')) IN ('READINGAREA', 'FACULTYAREA')");
    $pdo->exec("UPDATE facilities SET location = 'Main Campus' WHERE location IS NULL OR location = ''");

    ensureDefaultFacilitiesExist($pdo);

    if (!tableExists($pdo, 'bookings')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS bookings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            facility_id BIGINT UNSIGNED NOT NULL,
            date DATE NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            status ENUM('pending', 'approved', 'rejected', 'cancelled', 'completed') NOT NULL DEFAULT 'pending',
            purpose TEXT DEFAULT NULL,
            attendees INT UNSIGNED DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_bookings_user_id (user_id),
            INDEX idx_bookings_facility_id (facility_id),
            INDEX idx_bookings_date (date),
            INDEX idx_bookings_status (status),
            CONSTRAINT fk_bookings_user FOREIGN KEY (user_id)
                REFERENCES users(id)
                ON UPDATE CASCADE
                ON DELETE CASCADE,
            CONSTRAINT fk_bookings_facility FOREIGN KEY (facility_id)
                REFERENCES facilities(id)
                ON UPDATE CASCADE
                ON DELETE CASCADE
        ) ENGINE=InnoDB");
    }

    if (!tableExists($pdo, 'requests')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS requests (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            facility_id BIGINT UNSIGNED NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            status ENUM('pending', 'approved', 'rejected', 'cancelled', 'completed') NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_requests_user_id (user_id),
            INDEX idx_requests_facility_id (facility_id),
            INDEX idx_requests_status (status),
            CONSTRAINT fk_requests_user FOREIGN KEY (user_id)
                REFERENCES users(id)
                ON UPDATE CASCADE
                ON DELETE CASCADE,
            CONSTRAINT fk_requests_facility FOREIGN KEY (facility_id)
                REFERENCES facilities(id)
                ON UPDATE CASCADE
                ON DELETE CASCADE
        ) ENGINE=InnoDB");
    }

    if (!tableExists($pdo, 'audit_logs')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(255) NOT NULL,
            details JSON DEFAULT NULL,
            signature_applied TINYINT(1) NOT NULL DEFAULT 0,
            timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_logs_user_id (user_id),
            INDEX idx_audit_logs_action (action),
            INDEX idx_audit_logs_timestamp (timestamp),
            CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id)
                REFERENCES users(id)
                ON UPDATE CASCADE
                ON DELETE CASCADE
        ) ENGINE=InnoDB");
    }

    if (!tableExists($pdo, 'password_resets')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            requested_ip VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_password_resets_token_hash (token_hash),
            INDEX idx_password_resets_user_id (user_id),
            INDEX idx_password_resets_expires_at (expires_at),
            INDEX idx_password_resets_used_at (used_at),
            CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id)
                REFERENCES users(id)
                ON UPDATE CASCADE
                ON DELETE CASCADE
        ) ENGINE=InnoDB");
    }

    if (!tableExists($pdo, 'notifications')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            message TEXT NOT NULL,
            status ENUM('unread', 'read') NOT NULL DEFAULT 'unread',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notifications_user_id (user_id),
            INDEX idx_notifications_created_at (created_at),
            CONSTRAINT fk_notifications_user FOREIGN KEY (user_id)
                REFERENCES users(id)
                ON UPDATE CASCADE
                ON DELETE CASCADE
        ) ENGINE=InnoDB");
    }
}

/**
 * Stop execution with an actionable database setup message.
 */
function failDatabaseConnection(PDOException $e): void {
    error_log('Database connection failed: ' . $e->getMessage());

    if (defined('APP_ENV') && APP_ENV !== 'production') {
        die('Database connection failed. Check that MySQL is running and credentials in config/config.php are correct. Details: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }

    die('Database connection error. Please try again later.');
}

function getDBConnection(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO(buildAppDsn(), DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            if (isMissingDatabaseError($e)) {
                try {
                    $serverPdo = connectMysqlServer($options);
                    ensureDatabaseExists($serverPdo);
                    $pdo = new PDO(buildAppDsn(), DB_USER, DB_PASS, $options);
                } catch (PDOException $inner) {
                    failDatabaseConnection($inner);
                }
            } else {
                failDatabaseConnection($e);
            }
        }

        try {
            ensureCoreSchemaExists($pdo);
        } catch (PDOException $e) {
            failDatabaseConnection($e);
        }
    }

    return $pdo;
}
