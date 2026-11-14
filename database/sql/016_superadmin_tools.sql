-- HIMS migration 016: Super Admin tools (account lockout unlock time, backup history).
-- Run once in phpMyAdmin: select hr3_system > SQL tab > paste this file > Go.
-- Safe to run again: it only adds what is missing and does not change existing data.

-- 1) When a Super Admin unlocks an account, the time is stored here.
SET @add_unlocked_at = IF(
  EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'unlocked_at'
  ),
  'SELECT 1',
  'ALTER TABLE users ADD COLUMN unlocked_at DATETIME NULL'
);
PREPARE add_column_statement FROM @add_unlocked_at;
EXECUTE add_column_statement;
DEALLOCATE PREPARE add_column_statement;

-- 2) A record of every database backup downloaded by a Super Admin.
CREATE TABLE IF NOT EXISTS backup_history (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_by INT UNSIGNED NULL,
  file_name VARCHAR(120) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
  table_count INT UNSIGNED NOT NULL DEFAULT 0,
  row_count INT UNSIGNED NOT NULL DEFAULT 0,
  database_mode VARCHAR(10) NOT NULL DEFAULT 'local',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_backup_history_created (created_at)
) ENGINE=InnoDB;

-- Check: unlocked_at should be listed, and backup_history should exist.
SHOW COLUMNS FROM users LIKE 'unlocked_at';
SHOW TABLES LIKE 'backup_history';
