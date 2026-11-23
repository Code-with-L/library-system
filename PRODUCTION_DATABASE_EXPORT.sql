-- ============================================================
-- Campus Library Management System - PRODUCTION FRESH-INSTALL EXPORT
--
-- Source: database/database.sql (authoritative fresh-install schema),
-- with final migration state from database/migrations/0001..0003 already
-- incorporated. Generated for Phase "production database export".
--
-- Import into an ALREADY-CREATED EMPTY database via phpMyAdmin.
-- No CREATE DATABASE / USE / credentials / host / password included.
-- No DROP TABLE, no FOREIGN_KEY_CHECKS toggles, no demo users, no demo
-- catalogue data, no stored procedures and no DELIMITER statements.
--
-- Target: MySQL 8.0+ / MariaDB 10.6+ (verified locally on MariaDB 10.11).
-- Apply only to a fresh, empty production database.
-- ============================================================

-- Role and settings rows are required baseline application data.
-- categories/books/book_copies and users are intentionally NOT seeded;
-- add them through the application after the first administrator exists.


-- Roles
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
    id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(20) NOT NULL,
    description VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Users
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_id TINYINT UNSIGNED NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    session_version INT UNSIGNED NOT NULL DEFAULT 1,
    student_number VARCHAR(40) NULL,
    phone VARCHAR(30) NULL,
    department VARCHAR(100) NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    last_login_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_student_number (student_number),
    KEY idx_users_role (role_id),
    KEY idx_users_status (status),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id)
        REFERENCES roles (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_users_created_by FOREIGN KEY (created_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Categories
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Books (bibliographic titles, not physical copies)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS books (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id INT UNSIGNED NOT NULL,
    isbn VARCHAR(20) NOT NULL,
    title VARCHAR(255) NOT NULL,
    author VARCHAR(200) NOT NULL,
    publisher VARCHAR(150) NULL,
    publication_year SMALLINT UNSIGNED NULL,
    description TEXT NULL,
    cover_image VARCHAR(255) NULL,
    status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_books_isbn (isbn),
    KEY idx_books_category (category_id),
    KEY idx_books_status (status),
    KEY idx_books_title (title),
    KEY idx_books_author (author),
    CONSTRAINT fk_books_category FOREIGN KEY (category_id)
        REFERENCES categories (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_books_created_by FOREIGN KEY (created_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_books_publication_year CHECK (
        publication_year IS NULL OR publication_year BETWEEN 1000 AND 2100
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Physical book copies
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS book_copies (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    book_id INT UNSIGNED NOT NULL,
    copy_code VARCHAR(40) NOT NULL,
    status ENUM('available', 'reserved', 'issued', 'maintenance', 'archived')
        NOT NULL DEFAULT 'available',
    shelf_location VARCHAR(40) NULL,
    condition_note VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_book_copies_code (copy_code),
    KEY idx_book_copies_book (book_id),
    KEY idx_book_copies_status (status),
    CONSTRAINT fk_book_copies_book FOREIGN KEY (book_id)
        REFERENCES books (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Borrow requests
-- A student requests a title; staff assign a copy on approval.
-- Generated columns enforce active-request and reservation rules.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS borrow_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    book_id INT UNSIGNED NOT NULL,
    assigned_copy_id INT UNSIGNED NULL,
    reserved_copy_id INT UNSIGNED NULL,
    status ENUM('pending', 'approved', 'rejected', 'cancelled', 'fulfilled')
        NOT NULL DEFAULT 'pending',
    decision_note VARCHAR(255) NULL,
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    active_request_key TINYINT GENERATED ALWAYS AS (
        CASE WHEN status IN ('pending', 'approved') THEN 1 ELSE NULL END
    ) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_active_request_per_title (user_id, book_id, active_request_key),
    UNIQUE KEY uq_reserved_copy (reserved_copy_id),
    KEY idx_requests_user_status (user_id, status),
    KEY idx_requests_book_status (book_id, status),
    KEY idx_requests_status (status),
    KEY idx_requests_assigned_copy (assigned_copy_id),
    CONSTRAINT fk_requests_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_requests_book FOREIGN KEY (book_id)
        REFERENCES books (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_requests_copy FOREIGN KEY (assigned_copy_id)
        REFERENCES book_copies (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_requests_reserved_copy FOREIGN KEY (reserved_copy_id)
        REFERENCES book_copies (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_requests_reviewer FOREIGN KEY (reviewed_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Borrowings
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS borrowings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    book_copy_id INT UNSIGNED NOT NULL,
    issued_by INT UNSIGNED NULL,
    returned_by INT UNSIGNED NULL,
    issue_date DATE NOT NULL,
    due_date DATE NOT NULL,
    return_date DATE NULL,
    status ENUM('active', 'returned') NOT NULL DEFAULT 'active',
    active_copy_id INT UNSIGNED NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_borrowings_request (request_id),
    UNIQUE KEY uq_active_borrowing_copy (active_copy_id),
    KEY idx_borrowings_user_status (user_id, status),
    KEY idx_borrowings_copy (book_copy_id),
    KEY idx_borrowings_status_due (status, due_date),
    KEY idx_borrowings_return_date (return_date),
    CONSTRAINT fk_borrowings_request FOREIGN KEY (request_id)
        REFERENCES borrow_requests (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_borrowings_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_borrowings_copy FOREIGN KEY (book_copy_id)
        REFERENCES book_copies (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_borrowings_active_copy FOREIGN KEY (active_copy_id)
        REFERENCES book_copies (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_borrowings_issuer FOREIGN KEY (issued_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_borrowings_returner FOREIGN KEY (returned_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_borrowing_dates CHECK (due_date >= issue_date),
    CONSTRAINT chk_returned_has_date CHECK (
        status <> 'returned' OR return_date IS NOT NULL
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Fines
-- Recorded when an overdue borrowing is returned. No payments.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fines (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    borrowing_id INT UNSIGNED NOT NULL,
    days_overdue SMALLINT UNSIGNED NOT NULL,
    rate_per_day DECIMAL(8,2) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status ENUM('outstanding','settled') NOT NULL DEFAULT 'outstanding',
    assessed_at DATETIME NULL,
    settled_by_id INT UNSIGNED NULL,
    settled_at DATETIME NULL,
    calculated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fines_borrowing (borrowing_id),
    KEY idx_fines_status (status),
    KEY idx_fines_settled_by (settled_by_id),
    KEY idx_fines_calculated (calculated_at),
    CONSTRAINT fk_fines_borrowing FOREIGN KEY (borrowing_id)
        REFERENCES borrowings (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_fines_settled_by FOREIGN KEY (settled_by_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_fines_days CHECK (days_overdue > 0),
    CONSTRAINT chk_fines_rate CHECK (rate_per_day >= 0),
    CONSTRAINT chk_fines_amount CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Library settings (single row)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS library_settings (
    id TINYINT UNSIGNED NOT NULL DEFAULT 1,
    library_name VARCHAR(150) NOT NULL DEFAULT 'Campus Library',
    loan_period_days TINYINT UNSIGNED NOT NULL DEFAULT 14,
    max_active_borrowings TINYINT UNSIGNED NOT NULL DEFAULT 3,
    fine_per_day DECIMAL(8,2) NOT NULL DEFAULT 10.00,
    currency_code CHAR(3) NOT NULL DEFAULT 'KES',
    setup_completed_at DATETIME NULL,
    updated_by INT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_settings_updated_by FOREIGN KEY (updated_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_settings_single_row CHECK (id = 1),
    CONSTRAINT chk_settings_loan_period CHECK (loan_period_days BETWEEN 1 AND 90),
    CONSTRAINT chk_settings_borrow_limit CHECK (max_active_borrowings BETWEEN 1 AND 20),
    CONSTRAINT chk_settings_fine CHECK (fine_per_day >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Activity logs
-- Append-only audit trail.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    action VARCHAR(50) NOT NULL,
    entity_type VARCHAR(40) NULL,
    entity_id INT UNSIGNED NULL,
    description VARCHAR(500) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_logs_user (user_id),
    KEY idx_logs_created (created_at),
    KEY idx_logs_action (action),
    KEY idx_logs_entity (entity_type, entity_id),
    CONSTRAINT fk_logs_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Durable login throttling (Phase 6 / C-10)
-- Key is a SHA-256 hash of normalized email + IP; no raw credentials
-- or identifiers are stored here.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_throttles (
    throttle_key CHAR(64) NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL,
    first_attempt_at DATETIME NOT NULL,
    last_attempt_at DATETIME NOT NULL,
    PRIMARY KEY (throttle_key),
    KEY idx_login_throttles_last (last_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Explicit migration version tracking
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(40) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    description VARCHAR(255) NULL,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version, description) VALUES
    ('0001', 'users.session_version column'),
    ('0002', 'fines settlement fields'),
    ('0003', 'login_throttles durable throttle table');

INSERT INTO roles (id, name, description) VALUES
    (1, 'admin', 'Full system administration and oversight'),
    (2, 'librarian', 'Daily catalogue and circulation operations'),
    (3, 'student', 'Catalogue access and personal borrowing');

INSERT INTO library_settings
    (id, library_name, loan_period_days, max_active_borrowings,
     fine_per_day, currency_code, setup_completed_at, updated_by)
VALUES
    (1, 'Campus Library', 14, 3, 10.00, 'KES', NULL, NULL);

-- ============================================================
-- End of production import file.
-- Post-import verification queries are in PRODUCTION_DATABASE_EXPORT_REPORT.md.
-- ============================================================
