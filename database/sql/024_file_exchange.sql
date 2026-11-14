-- Batch 24: Integration with the HR2, HR3 and HR4 systems: File Exchange and HR Requests.
-- Run once in phpMyAdmin (or MySQL Workbench) on the SAME database HIMS uses.
-- Select that database first; this file does not pick one for you.
-- Safe to run again: it only creates the tables if they are missing.

-- Every file HIMS receives from (incoming) or sends to (outgoing) HR2 / HR3 / HR4.
-- The file itself is stored on the server outside the project folder; this row describes it.
CREATE TABLE IF NOT EXISTS file_exchange (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  direction ENUM('incoming', 'outgoing') NOT NULL,
  partner ENUM('HR2', 'HR3', 'HR4') NOT NULL,
  subject VARCHAR(150) NOT NULL,
  note TEXT NULL,
  original_name VARCHAR(200) NOT NULL,
  stored_name VARCHAR(80) NOT NULL,
  mime_type VARCHAR(120) NOT NULL,
  file_size INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  partner_sender VARCHAR(150) NULL,      -- incoming: who sent it on the partner's side
  partner_ref VARCHAR(60) NULL,          -- incoming: the partner's own reference number
  sent_by INT UNSIGNED NULL,             -- outgoing: HIMS account that sent it (users.id)
  status ENUM('New', 'Opened', 'Waiting', 'Picked up') NOT NULL,
  opened_by INT UNSIGNED NULL,
  opened_at DATETIME NULL,
  picked_up_at DATETIME NULL,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_file_exchange_box (direction, partner, archived),
  KEY idx_file_exchange_created (created_at)
) ENGINE=InnoDB;

-- Connection keys the Super Admin creates for each partner system (only a hash is stored).
CREATE TABLE IF NOT EXISTS integration_keys (
  partner ENUM('HR2', 'HR3', 'HR4') NOT NULL,
  key_hash CHAR(64) NOT NULL,
  key_hint VARCHAR(12) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  PRIMARY KEY (partner)
) ENGINE=InnoDB;

-- Requests the HR2 / HR3 / HR4 systems send to HIMS (shown on the HR Requests page).
-- An HR Officer or Admin approves or rejects them; the sending system reads the decision back.
CREATE TABLE IF NOT EXISTS integration_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  partner ENUM('HR2', 'HR3', 'HR4') NOT NULL,
  reference VARCHAR(60) NOT NULL,          -- the partner's own request number
  request_type VARCHAR(60) NOT NULL,       -- e.g. Overtime, Employee record, Training, Payroll adjustment
  title VARCHAR(150) NOT NULL,
  employee_code VARCHAR(40) NULL,
  employee_name VARCHAR(150) NULL,
  details TEXT NULL,
  hours DECIMAL(6,2) NULL,
  request_date DATE NULL,
  requested_by VARCHAR(150) NULL,
  status ENUM('Pending', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending',
  remark VARCHAR(500) NULL,
  decided_by INT UNSIGNED NULL,            -- users.id of the HR Officer / Admin
  decided_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_integration_request_ref (partner, reference),
  KEY idx_integration_request_status (status, partner)
) ENGINE=InnoDB;
