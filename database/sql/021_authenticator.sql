-- HIMS migration 021: phone OTP with an authenticator app (Google Authenticator, Microsoft Authenticator...).
-- Run once in MySQL Workbench: open this file and click the lightning button.
-- Adds three empty columns to users. Existing data is not changed.

-- First select your HIMS database (phpMyAdmin: click it on the left; MySQL Workbench: double-click it so it is bold).

ALTER TABLE users
  ADD COLUMN totp_secret VARCHAR(64) NULL,
  ADD COLUMN totp_enabled_at DATETIME NULL,
  ADD COLUMN totp_last_step BIGINT NULL;
