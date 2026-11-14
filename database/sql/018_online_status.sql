-- HIMS migration 018: "Online now" on the Super Admin Accounts & Roles page.
-- Run once in MySQL Workbench: double-click hr3_system so it is bold, open this file, click the lightning button.
-- Adds the time each account last used HIMS. Safe: existing data is not changed.

ALTER TABLE users ADD COLUMN last_seen_at DATETIME NULL AFTER last_login_at;
