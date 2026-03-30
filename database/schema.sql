-- ============================================================
-- FSUU Library Booking System - MySQL Database Schema
-- ============================================================

CREATE DATABASE IF NOT EXISTS fsuu_library_booking
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE fsuu_library_booking;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS requests;
DROP TABLE IF EXISTS bookings;
DROP TABLE IF EXISTS facilities;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- users
-- ------------------------------------------------------------
CREATE TABLE users (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    email       VARCHAR(150) NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,
    role        ENUM('student', 'faculty', 'admin', 'adviser', 'staff', 'library_staff', 'super_admin') NOT NULL DEFAULT 'student',
    status      ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active',
    contact_number VARCHAR(20) DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- facilities
-- ------------------------------------------------------------
CREATE TABLE facilities (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    type            VARCHAR(80) NOT NULL,
    location        VARCHAR(120) DEFAULT NULL,
    capacity_min    INT UNSIGNED NOT NULL,
    capacity_max    INT UNSIGNED NOT NULL,
    availability    TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT chk_facilities_capacity_range CHECK (capacity_min <= capacity_max)
) ENGINE=InnoDB;

INSERT INTO facilities (name, type, location, capacity_min, capacity_max, availability) VALUES
    ('CL1', 'cl_room', 'Main Campus', 1, 7, 1),
    ('CL2', 'cl_room', 'Main Campus', 1, 8, 1),
    ('CL3', 'cl_room', 'Main Campus', 1, 2, 1),
    ('Museum', 'museum', 'Main Campus', 1, 100, 1),
    ('EIRC', 'eirc', 'Main Campus', 1, 60, 1),
    ('Reading Area', 'reading_area', 'Morelos', 40, 200, 1),
    ('Faculty Area', 'faculty_area', 'Morelos', 1, 30, 1);

-- ------------------------------------------------------------
-- bookings
-- ------------------------------------------------------------
CREATE TABLE bookings (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         BIGINT UNSIGNED NOT NULL,
    facility_id     BIGINT UNSIGNED NOT NULL,
    date            DATE NOT NULL,
    start_time      TIME NOT NULL,
    end_time        TIME NOT NULL,
    status          ENUM('pending', 'approved', 'rejected', 'cancelled', 'completed') NOT NULL DEFAULT 'pending',
    purpose         TEXT,
    attendees       INT UNSIGNED NOT NULL DEFAULT 1,
    CONSTRAINT chk_bookings_time_range CHECK (start_time < end_time),
    CONSTRAINT chk_bookings_attendees CHECK (attendees >= 1),
    CONSTRAINT fk_bookings_user FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_bookings_facility FOREIGN KEY (facility_id)
        REFERENCES facilities(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    INDEX idx_bookings_user_id (user_id),
    INDEX idx_bookings_facility_id (facility_id),
    INDEX idx_bookings_date (date)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- requests
-- ------------------------------------------------------------
CREATE TABLE requests (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         BIGINT UNSIGNED NOT NULL,
    facility_id     BIGINT UNSIGNED NOT NULL,
    file_path       VARCHAR(255) NOT NULL,
    status          ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    CONSTRAINT fk_requests_user FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_requests_facility FOREIGN KEY (facility_id)
        REFERENCES facilities(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    INDEX idx_requests_user_id (user_id),
    INDEX idx_requests_facility_id (facility_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- notifications
-- ------------------------------------------------------------
CREATE TABLE notifications (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         BIGINT UNSIGNED NOT NULL,
    message         TEXT NOT NULL,
    status          ENUM('unread', 'read') NOT NULL DEFAULT 'unread',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    INDEX idx_notifications_user_id (user_id),
    INDEX idx_notifications_created_at (created_at)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- password_resets
-- ------------------------------------------------------------
CREATE TABLE password_resets (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         BIGINT UNSIGNED NOT NULL,
    token_hash      CHAR(64) NOT NULL,
    expires_at      DATETIME NOT NULL,
    used_at         DATETIME DEFAULT NULL,
    requested_ip    VARCHAR(45) DEFAULT NULL,
    user_agent      VARCHAR(255) DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    UNIQUE KEY uq_password_resets_token_hash (token_hash),
    INDEX idx_password_resets_user_id (user_id),
    INDEX idx_password_resets_expires_at (expires_at),
    INDEX idx_password_resets_used_at (used_at)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- audit_logs
-- ------------------------------------------------------------
CREATE TABLE audit_logs (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         BIGINT UNSIGNED NOT NULL,
    action          VARCHAR(255) NOT NULL,
    timestamp       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    INDEX idx_audit_logs_user_id (user_id),
    INDEX idx_audit_logs_timestamp (timestamp)
) ENGINE=InnoDB;
