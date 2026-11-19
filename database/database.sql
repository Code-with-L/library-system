-- ============================================================
-- Campus Library Management System
-- Database schema and base demonstration data
--
-- Target: MySQL 8.x or MariaDB 10.6+
-- Import into an already-created database.
-- Do NOT place CREATE DATABASE or USE statements here because
-- shared hosting providers such as InfinityFree usually provide
-- the database through their control panel.
--
-- DEMONSTRATION ACCOUNTS (local development only)
--   Librarian: librarian@campus.test / Librarian123!
--   Student:   student@campus.test   / Student123!
-- These passwords MUST be changed or the accounts removed before
-- deployment to a public production environment.
--
-- No administrator account is seeded. Use setup-admin.php once.
--
-- MariaDB compatibility note:
-- MariaDB 10.11 does not allow generated columns or CHECK constraints
-- to reference foreign-key columns. The active reservation and active
-- borrowing guards therefore use nullable keys with UNIQUE indexes,
-- maintained inside application transactions. This preserves the
-- "one reservation per copy" and "one active loan per copy" rules.
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+03:00';

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS activity_logs;
DROP TABLE IF EXISTS fines;
DROP TABLE IF EXISTS borrowings;
DROP TABLE IF EXISTS borrow_requests;
DROP TABLE IF EXISTS book_copies;
DROP TABLE IF EXISTS books;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS library_settings;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS roles;
SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- Roles
-- ------------------------------------------------------------
CREATE TABLE roles (
    id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(20) NOT NULL,
    description VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Users
-- ------------------------------------------------------------
CREATE TABLE users (
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
CREATE TABLE categories (
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
CREATE TABLE books (
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
CREATE TABLE book_copies (
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
CREATE TABLE borrow_requests (
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
CREATE TABLE borrowings (
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
CREATE TABLE fines (
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
CREATE TABLE library_settings (
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
CREATE TABLE activity_logs (
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

-- ============================================================
-- Seed data
-- ============================================================

INSERT INTO roles (id, name, description) VALUES
    (1, 'admin', 'Full system administration and oversight'),
    (2, 'librarian', 'Daily catalogue and circulation operations'),
    (3, 'student', 'Catalogue access and personal borrowing');

-- Password hashes were generated with PHP password_hash(PASSWORD_DEFAULT).
-- Plain-text demo passwords are intentionally documented only in this
-- local demonstration SQL and must be changed before public deployment.
INSERT INTO users
    (id, role_id, full_name, email, password_hash, student_number, phone, department, status)
VALUES
    (1, 2, 'Amina Librarian', 'librarian@campus.test',
     '$2y$10$DNA1avunvuE9wq7DDk6kvOZkG6NFF2R/LylQiBjdw0P7RYaOElNtC',
     NULL, '+254700000001', 'Library Services', 'active'),
    (2, 3, 'Brian Student', 'student@campus.test',
     '$2y$10$E4fnvnCvMP4nwd9ZIX9xo.0rzk9aVQEsdtuiu43sP02JwpQAufsuK',
     'STU-2026-001', '+254700000002', 'Computer Science', 'active');

INSERT INTO library_settings
    (id, library_name, loan_period_days, max_active_borrowings,
     fine_per_day, currency_code, setup_completed_at, updated_by)
VALUES
    (1, 'Campus Library', 14, 3, 10.00, 'KES', NULL, NULL);

INSERT INTO categories (id, name, description) VALUES
    (1, 'Computer Science', 'Programming, algorithms and information systems'),
    (2, 'Literature', 'Fiction, drama and literary studies'),
    (3, 'Business', 'Management, marketing and entrepreneurship'),
    (4, 'Engineering', 'Applied engineering and technical design'),
    (5, 'Mathematics', 'Pure and applied mathematics'),
    (6, 'History', 'Historical studies and social sciences');

INSERT INTO books
    (id, category_id, isbn, title, author, publisher, publication_year, description, created_by, status)
VALUES
    (1, 1, '9780262046305', 'Introduction to Algorithms',
     'Thomas H. Cormen', 'MIT Press', 2022,
     'A comprehensive introduction to algorithm design and analysis.', 1, 'active'),
    (2, 1, '9780132350884', 'Clean Code',
     'Robert C. Martin', 'Prentice Hall', 2008,
     'Practical principles for writing readable and maintainable software.', 1, 'active'),
    (3, 1, '9780078022159', 'Database System Concepts',
     'Abraham Silberschatz', 'McGraw-Hill', 2019,
     'Relational modelling, SQL, transactions and database architecture.', 1, 'active'),
    (4, 2, '9780385474542', 'Things Fall Apart',
     'Chinua Achebe', 'Anchor Books', 1994,
     'A landmark novel about tradition, change and colonial encounter.', 1, 'active'),
    (5, 2, '9780141439518', 'Pride and Prejudice',
     'Jane Austen', 'Penguin Classics', 2003,
     'A classic novel of manners, family and marriage.', 1, 'active'),
    (6, 2, '9780743273565', 'The Great Gatsby',
     'F. Scott Fitzgerald', 'Scribner', 2004,
     'A study of ambition and illusion in the Jazz Age.', 1, 'active'),
    (7, 3, '9780273754091', 'Principles of Marketing',
     'Philip Kotler', 'Pearson', 2017,
     'Core marketing concepts, strategy and customer behaviour.', 1, 'active'),
    (8, 3, '9781292208623', 'Research Methods for Business Students',
     'Mark Saunders', 'Pearson', 2019,
     'A practical guide to business research design and analysis.', 1, 'active'),
    (9, 4, '9781118885840', 'Engineering Mechanics: Dynamics',
     'James L. Meriam', 'Wiley', 2015,
     'Classical dynamics for engineering students.', 1, 'active'),
    (10, 5, '9780321982384', 'Linear Algebra and Its Applications',
     'David C. Lay', 'Pearson', 2015,
     'Matrix theory, vector spaces and applications.', 1, 'active'),
    (11, 5, '9780134685991', 'Calculus: Early Transcendentals',
     'James Stewart', 'Cengage', 2020,
     'Differential and integral calculus with applications.', 1, 'active'),
    (12, 6, '9780553380163', 'A Brief History of Time',
     'Stephen Hawking', 'Bantam', 1998,
     'An accessible exploration of cosmology and modern physics.', 1, 'active');

INSERT INTO book_copies
    (book_id, copy_code, status, shelf_location)
VALUES
    (1, 'CL-0001-01', 'available', 'CS-A1'),
    (1, 'CL-0001-02', 'available', 'CS-A1'),
    (1, 'CL-0001-03', 'available', 'CS-A1'),
    (2, 'CL-0002-01', 'available', 'CS-A2'),
    (2, 'CL-0002-02', 'available', 'CS-A2'),
    (3, 'CL-0003-01', 'available', 'CS-B1'),
    (3, 'CL-0003-02', 'available', 'CS-B1'),
    (3, 'CL-0003-03', 'available', 'CS-B1'),
    (4, 'CL-0004-01', 'available', 'LIT-A1'),
    (4, 'CL-0004-02', 'available', 'LIT-A1'),
    (5, 'CL-0005-01', 'available', 'LIT-A2'),
    (5, 'CL-0005-02', 'available', 'LIT-A2'),
    (6, 'CL-0006-01', 'available', 'LIT-B1'),
    (7, 'CL-0007-01', 'available', 'BUS-A1'),
    (7, 'CL-0007-02', 'available', 'BUS-A1'),
    (8, 'CL-0008-01', 'available', 'BUS-A2'),
    (9, 'CL-0009-01', 'available', 'ENG-A1'),
    (9, 'CL-0009-02', 'available', 'ENG-A1'),
    (10, 'CL-0010-01', 'available', 'MTH-A1'),
    (10, 'CL-0010-02', 'available', 'MTH-A1'),
    (11, 'CL-0011-01', 'available', 'MTH-A2'),
    (12, 'CL-0012-01', 'available', 'HIS-A1');

-- ============================================================
-- Integrity smoke checks (results are informational)
-- ============================================================
SELECT 'roles' AS table_name, COUNT(*) AS row_count FROM roles
UNION ALL SELECT 'users', COUNT(*) FROM users
UNION ALL SELECT 'categories', COUNT(*) FROM categories
UNION ALL SELECT 'books', COUNT(*) FROM books
UNION ALL SELECT 'book_copies', COUNT(*) FROM book_copies
UNION ALL SELECT 'borrow_requests', COUNT(*) FROM borrow_requests
UNION ALL SELECT 'borrowings', COUNT(*) FROM borrowings
UNION ALL SELECT 'fines', COUNT(*) FROM fines
UNION ALL SELECT 'library_settings', COUNT(*) FROM library_settings
UNION ALL SELECT 'activity_logs', COUNT(*) FROM activity_logs;

