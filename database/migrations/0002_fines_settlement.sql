-- Phase 4: add manual library-desk settlement to fines.
-- Existing development database is upgraded idempotently.

DELIMITER $$

CREATE PROCEDURE campus_library_fines_add_settlement_fields()
BEGIN
    DECLARE v_count INT DEFAULT 0;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'fines'
       AND COLUMN_NAME = 'status';
    IF v_count = 0 THEN
        ALTER TABLE `fines`
            ADD COLUMN `status` ENUM('outstanding','settled') NOT NULL DEFAULT 'outstanding';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'fines'
       AND COLUMN_NAME = 'assessed_at';
    IF v_count = 0 THEN
        ALTER TABLE `fines` ADD COLUMN `assessed_at` DATETIME NULL;
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'fines'
       AND COLUMN_NAME = 'settled_by_id';
    IF v_count = 0 THEN
        ALTER TABLE `fines` ADD COLUMN `settled_by_id` INT UNSIGNED NULL;
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'fines'
       AND COLUMN_NAME = 'settled_at';
    IF v_count = 0 THEN
        ALTER TABLE `fines` ADD COLUMN `settled_at` DATETIME NULL;
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'fines'
       AND INDEX_NAME = 'idx_fines_status';
    IF v_count = 0 THEN
        ALTER TABLE `fines` ADD INDEX idx_fines_status (status);
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'fines'
       AND INDEX_NAME = 'idx_fines_settled_by';
    IF v_count = 0 THEN
        ALTER TABLE `fines` ADD INDEX idx_fines_settled_by (settled_by_id);
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.TABLE_CONSTRAINTS tc
      WHERE tc.CONSTRAINT_SCHEMA = DATABASE()
        AND tc.TABLE_NAME = 'fines'
        AND tc.CONSTRAINT_NAME = 'fk_fines_settled_by';
    IF v_count = 0 THEN
        ALTER TABLE `fines`
            ADD CONSTRAINT fk_fines_settled_by FOREIGN KEY (settled_by_id)
                REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL;
    END IF;

END$$

DELIMITER ;

CALL campus_library_fines_add_settlement_fields();
DROP PROCEDURE IF EXISTS campus_library_fines_add_settlement_fields;
