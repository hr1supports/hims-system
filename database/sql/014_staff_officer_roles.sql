-- HIMS migration 009: allow the HR Staff and HR Officer roles.
-- Run once in phpMyAdmin: select the hr3_system database, open the SQL tab, paste this file, click Go.
-- Safe to run again: it only widens the role column so it accepts the new role names.

-- 1) Make users.role accept any role name (it may have been a fixed ENUM list).
ALTER TABLE users MODIFY role VARCHAR(20) NOT NULL;

-- 2) Check the result: every account keeps its old role.
SELECT role, COUNT(*) AS accounts FROM users GROUP BY role ORDER BY role;
