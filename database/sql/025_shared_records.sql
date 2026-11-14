-- Batch 25: claims and ESS requests saved in the database (instead of only in one browser).
-- Run once in phpMyAdmin (or MySQL Workbench) on the SAME database HIMS uses.
-- Select that database first; this file does not pick one for you.
-- Safe to run again: it only creates the table if it is missing.

-- One row per claim or ESS request. "data" holds the record exactly as the HIMS pages use it;
-- owner_code (employee ID) and status are copied into their own columns for filtering and reports.
CREATE TABLE IF NOT EXISTS shared_records (
  seq INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind ENUM('claims', 'essRequests') NOT NULL,
  uid VARCHAR(40) NOT NULL,
  owner_code VARCHAR(60) NULL,
  status VARCHAR(40) NULL,
  data MEDIUMTEXT NOT NULL,
  receipt_stored VARCHAR(80) NULL,       -- claim receipt file, stored outside the project folder
  receipt_mime VARCHAR(60) NULL,
  created_by INT UNSIGNED NULL,          -- users.id
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (seq),
  UNIQUE KEY uq_shared_records_uid (kind, uid),
  KEY idx_shared_records_owner (kind, owner_code),
  KEY idx_shared_records_status (kind, status)
) ENGINE=InnoDB;
