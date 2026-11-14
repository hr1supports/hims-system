-- HIMS migration 019: login sessions and devices on the Super Admin Accounts & Roles page.
-- Run once in MySQL Workbench: open this file and click the lightning button.
-- Creates a new table only. Existing data is not changed.

-- First select your HIMS database (phpMyAdmin: click it on the left; MySQL Workbench: double-click it so it is bold).

CREATE TABLE IF NOT EXISTS login_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  ip VARCHAR(45) NOT NULL,
  user_agent VARCHAR(255) NULL,
  device VARCHAR(120) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at DATETIME NULL,
  end_reason VARCHAR(40) NULL,
  PRIMARY KEY (id),
  KEY idx_login_sessions_user (user_id, created_at),
  CONSTRAINT fk_login_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;
