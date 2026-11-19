-- Phase 2.1: add a per-account session version.
--
-- Existing deployments must run this migration once. A fresh import of
-- database/database.sql includes the column; this migration is idempotent.
--
-- The version is compared with the value stored in the session on each
-- request so password changes, administrator resets and deactivations can
-- invalidate existing sessions server-side.

DELIMITER $$

CREATE PROCEDURE campus_library_add_session_version()
BEGIN
    DECLARE column_exists INT DEFAULT 0;

    SELECT COUNT(*)
      INTO column_exists
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'users'
       AND COLUMN_NAME = 'session_version';

    IF column_exists = 0 THEN
        ALTER TABLE `users`
          ADD COLUMN `session_version` INT UNSIGNED NOT NULL DEFAULT 1
              AFTER `password_hash`;
    END IF;
END$$

DELIMITER ;

CALL campus_library_add_session_version();
DROP PROCEDURE IF EXISTS campus_library_add_session_version;
