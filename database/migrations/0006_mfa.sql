-- Optional TOTP multi-factor authentication. Secrets are stored base32-encoded;
-- recovery codes are stored only as SHA-256 hashes.
CREATE TABLE IF NOT EXISTS user_mfa (
    user_id INT UNSIGNED NOT NULL,
    secret VARCHAR(64) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    recovery_codes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    enabled_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_user_mfa_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version) VALUES ('0006')
    ON DUPLICATE KEY UPDATE version = version;
