-- HIMS migration 020: attendance verification for Timekeeping Approvals.
-- Run once in MySQL Workbench: double-click hr3_system so it is bold, click the lightning button.
-- Adds who verified each attendance record and when. Existing records are not changed.

-- First select your HIMS database (phpMyAdmin: click it on the left; MySQL Workbench: double-click it so it is bold).

ALTER TABLE attendance_records
  ADD COLUMN verified_by INT UNSIGNED NULL AFTER notes,
  ADD COLUMN verified_at DATETIME NULL AFTER verified_by;
