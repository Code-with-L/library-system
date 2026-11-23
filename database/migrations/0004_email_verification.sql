-- Email verification for student accounts.
-- Token is stored as a SHA-256 hash only; the raw token appears in the email link.
CREATE TABLE IF NOT EXISTS email_verifications (
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    verified_at DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    KEY idx_email_verifications_token (token_hash),
    KEY idx_email_verifications_expiry (expires_at),
    CONSTRAINT fk_email_verifications_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version) VALUES ('0004')
    ON DUPLICATE KEY UPDATE version = version;
