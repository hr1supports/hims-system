-- Batch 23: job boards (LinkedIn / JobStreet) and where each applicant came from.
-- Run once in MySQL Workbench (select all, then the lightning bolt).
-- First select your HIMS database (phpMyAdmin: click it on the left; MySQL Workbench: double-click it so it is bold).

-- Links to the same job posted on LinkedIn and JobStreet (shown on the careers page).
ALTER TABLE vacancies
  ADD COLUMN linkedin_url VARCHAR(500) NULL AFTER qualifications,
  ADD COLUMN jobstreet_url VARCHAR(500) NULL AFTER linkedin_url;

-- Where the applicant came from: LinkedIn, JobStreet, Facebook, Careers page or Walk-in.
ALTER TABLE applications ADD COLUMN channel VARCHAR(20) NOT NULL DEFAULT 'Careers page' AFTER note;

-- Applicants HR typed in by hand (no resume, no IP address) were walk-ins.
-- ("id > 0" keeps MySQL Workbench safe update mode happy.)
UPDATE applications SET channel = 'Walk-in' WHERE id > 0 AND resume_stored_name IS NULL AND ip IS NULL;
