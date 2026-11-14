-- HIMS migration 010: onboarding checklist for hired applicants and trainees.
-- Run once in phpMyAdmin: select hr3_system > SQL tab > paste this file > Go.
-- Safe to run again: tables are only created if they do not exist yet.

CREATE TABLE IF NOT EXISTS onboarding_records (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NULL,
  email VARCHAR(190) NOT NULL,
  name VARCHAR(150) NOT NULL,
  position VARCHAR(150) NULL,
  department VARCHAR(100) NULL,
  start_date DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'In progress',
  completed_by INT UNSIGNED NULL,
  completed_at DATETIME NULL,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_onboarding_email (email),
  UNIQUE KEY uq_onboarding_application (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS onboarding_checklist (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  record_id INT UNSIGNED NOT NULL,
  item_key VARCHAR(40) NOT NULL,
  done TINYINT(1) NOT NULL DEFAULT 0,
  done_by INT UNSIGNED NULL,
  done_at DATETIME NULL,
  note VARCHAR(255) NULL,
  UNIQUE KEY uq_onboarding_item (record_id, item_key),
  CONSTRAINT fk_onboarding_item_record FOREIGN KEY (record_id) REFERENCES onboarding_records (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Check: both tables should be listed.
SHOW TABLES LIKE 'onboarding%';
