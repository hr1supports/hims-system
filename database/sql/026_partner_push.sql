-- Batch 26: HIMS sends data to the other systems (HR2, HR3, HR4 and Administrators).
-- Run once in phpMyAdmin / MySQL Workbench on the SAME database HIMS uses (select it first).
-- Safe to run again. Needs a user that may ALTER/CREATE tables (for example root).

-- Administrators becomes a fourth partner system next to HR2, HR3 and HR4.
ALTER TABLE integration_keys     MODIFY partner ENUM('HR2', 'HR3', 'HR4', 'ADMIN') NOT NULL;
ALTER TABLE integration_requests MODIFY partner ENUM('HR2', 'HR3', 'HR4', 'ADMIN') NOT NULL;
ALTER TABLE file_exchange        MODIFY partner ENUM('HR2', 'HR3', 'HR4', 'ADMIN') NOT NULL;

-- Where HIMS sends each system's data: their API address and the key THEY gave us (stored encrypted).
CREATE TABLE IF NOT EXISTS integration_endpoints (
  partner VARCHAR(10) NOT NULL,
  url VARCHAR(500) NOT NULL,
  token_encrypted TEXT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (partner)
) ENGINE=InnoDB;

-- Every piece of data HIMS sends (or will send), so nothing is lost when a partner is offline.
CREATE TABLE IF NOT EXISTS integration_outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  partner VARCHAR(10) NOT NULL,
  event VARCHAR(60) NOT NULL,
  ref VARCHAR(80) NULL,
  payload MEDIUMTEXT NOT NULL,
  status ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  response_code SMALLINT NULL,
  last_error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_outbox_status (status, partner),
  KEY idx_outbox_created (created_at)
) ENGINE=InnoDB;
