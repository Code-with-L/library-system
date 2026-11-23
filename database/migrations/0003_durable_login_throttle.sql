-- Phase 6 (C-10): durable, database-backed login throttling.
-- Idempotent migration for existing installations.
-- Compatible with MySQL 8.0 and MariaDB 10.6+.

CREATE TABLE IF NOT EXISTS login_throttles (
    throttle_key CHAR(64) NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL,
    first_attempt_at DATETIME NOT NULL,
    last_attempt_at DATETIME NOT NULL,
    PRIMARY KEY (throttle_key),
    KEY idx_login_throttles_last (last_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(40) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    description VARCHAR(255) NULL,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version, description) VALUES
    ('0001', 'users.session_version column'),
    ('0002', 'fines settlement fields'),
    ('0003', 'login_throttles durable throttle table');
